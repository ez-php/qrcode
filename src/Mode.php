<?php

declare(strict_types=1);

namespace EzPhp\QrCode;

/**
 * Enum Mode
 *
 * Data encoding modes. The encoder picks the densest single mode the whole
 * input fits: numeric (digits) → alphanumeric (0-9, A-Z, space, $%*+-./:) →
 * byte (raw bytes, e.g. UTF-8 — no ECI header is emitted).
 *
 * @package EzPhp\QrCode
 */
enum Mode
{
    case Numeric;
    case Alphanumeric;
    case Byte;

    public const string ALPHANUMERIC_CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    /**
     * The densest mode that can encode all of `$data`.
     */
    public static function detect(string $data): self
    {
        if ($data !== '' && ctype_digit($data)) {
            return self::Numeric;
        }

        if ($data !== '' && strspn($data, self::ALPHANUMERIC_CHARSET) === strlen($data)) {
            return self::Alphanumeric;
        }

        return self::Byte;
    }

    /**
     * Whether `$data` can be encoded in this mode.
     */
    public function supports(string $data): bool
    {
        return match ($this) {
            self::Numeric => $data === '' || ctype_digit($data),
            self::Alphanumeric => strspn($data, self::ALPHANUMERIC_CHARSET) === strlen($data),
            self::Byte => true,
        };
    }

    /**
     * The 4-bit mode indicator.
     */
    public function indicator(): int
    {
        return match ($this) {
            self::Numeric => 0b0001,
            self::Alphanumeric => 0b0010,
            self::Byte => 0b0100,
        };
    }

    /**
     * Width of the character-count field for a version.
     */
    public function countBits(int $version): int
    {
        $range = $version <= 9 ? 0 : ($version <= 26 ? 1 : 2);

        return match ($this) {
            self::Numeric => [10, 12, 14][$range],
            self::Alphanumeric => [9, 11, 13][$range],
            self::Byte => [8, 16, 16][$range],
        };
    }
}
