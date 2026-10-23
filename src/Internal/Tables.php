<?php

declare(strict_types=1);

namespace EzPhp\QrCode\Internal;

use EzPhp\QrCode\ErrorCorrectionLevel;

/**
 * Class Tables
 *
 * Error-correction block structure from ISO/IEC 18004 table 9, plus the
 * capacity and alignment-pattern formulas derived from the symbol layout.
 *
 * @internal
 *
 * @package EzPhp\QrCode\Internal
 */
final class Tables
{
    /**
     * Error-correction codewords per block, by level and version (index 0 unused).
     *
     * @var array<string, list<int>>
     */
    private const array ECC_PER_BLOCK = [
        'L' => [0, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'M' => [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        'Q' => [0, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'H' => [0, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    /**
     * Number of error-correction blocks, by level and version (index 0 unused).
     *
     * @var array<string, list<int>>
     */
    private const array NUM_BLOCKS = [
        'L' => [0, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        'M' => [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        'Q' => [0, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        'H' => [0, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    public static function eccPerBlock(int $version, ErrorCorrectionLevel $level): int
    {
        return self::ECC_PER_BLOCK[$level->value][$version];
    }

    public static function numBlocks(int $version, ErrorCorrectionLevel $level): int
    {
        return self::NUM_BLOCKS[$level->value][$version];
    }

    /**
     * Modules available for data + error correction (everything but function patterns).
     */
    public static function rawDataModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;

        if ($version >= 2) {
            $alignments = intdiv($version, 7) + 2;
            $result -= (25 * $alignments - 10) * $alignments - 55;

            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    /**
     * Data codewords (excluding error correction) a symbol holds.
     */
    public static function dataCodewords(int $version, ErrorCorrectionLevel $level): int
    {
        return intdiv(self::rawDataModules($version), 8) - self::eccPerBlock($version, $level) * self::numBlocks($version, $level);
    }

    /**
     * Row/column centre coordinates of the alignment patterns.
     *
     * @return list<int>
     */
    public static function alignmentPositions(int $version): array
    {
        if ($version === 1) {
            return [];
        }

        $count = intdiv($version, 7) + 2;
        $step = intdiv($version * 8 + $count * 3 + 5, $count * 4 - 4) * 2;
        $positions = [6];

        for ($i = 0, $position = 4 * $version + 10; $i < $count - 1; $i++, $position -= $step) {
            array_splice($positions, 1, 0, [$position]);
        }

        return $positions;
    }
}
