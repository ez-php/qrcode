<?php

declare(strict_types=1);

namespace EzPhp\QrCode\Internal;

/**
 * Class ReedSolomon
 *
 * Reed-Solomon error-correction codewords over GF(256) with the QR code
 * polynomial x^8 + x^4 + x^3 + x^2 + 1 (0x11D).
 *
 * @internal
 *
 * @package EzPhp\QrCode\Internal
 */
final class ReedSolomon
{
    /**
     * The `$degree` error-correction codewords for `$data`.
     *
     * @param list<int> $data Codewords (0–255).
     *
     * @return list<int>
     */
    public static function remainder(array $data, int $degree): array
    {
        $divisor = self::divisor($degree);
        $result = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;

            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= self::multiply($coefficient, $factor);
            }
        }

        return array_values($result);
    }

    /**
     * Generator polynomial coefficients (highest degree first, leading 1 dropped).
     *
     * @return list<int>
     */
    private static function divisor(int $degree): array
    {
        $result = array_fill(0, $degree - 1, 0);
        $result[] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::multiply($result[$j], $root);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = self::multiply($root, 0x02);
        }

        return array_values($result);
    }

    /**
     * Multiplication in GF(256) modulo 0x11D (Russian peasant).
     */
    private static function multiply(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }
}
