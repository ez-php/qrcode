<?php

declare(strict_types=1);

namespace EzPhp\QrCode;

/**
 * Enum ErrorCorrectionLevel
 *
 * How much of the symbol can be damaged and still decode: L ≈ 7 %, M ≈ 15 %,
 * Q ≈ 25 %, H ≈ 30 % — at the cost of capacity.
 *
 * @package EzPhp\QrCode
 */
enum ErrorCorrectionLevel: string
{
    case L = 'L';
    case M = 'M';
    case Q = 'Q';
    case H = 'H';

    /**
     * The two format-information bits for this level (ISO/IEC 18004 table 12).
     */
    public function formatBits(): int
    {
        return match ($this) {
            self::L => 1,
            self::M => 0,
            self::Q => 3,
            self::H => 2,
        };
    }
}
