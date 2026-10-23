<?php

declare(strict_types=1);

namespace EzPhp\QrCode;

/**
 * Class QrCode
 *
 * An encoded QR code symbol: a square grid of dark/light modules (without
 * quiet zone) plus the parameters it was encoded with. Renderers add the
 * quiet zone.
 *
 * @package EzPhp\QrCode
 */
final readonly class QrCode
{
    /**
     * @param list<list<bool>>     $modules Rows of modules, true = dark; size × size.
     * @param int                  $version 1–40.
     * @param ErrorCorrectionLevel $level
     * @param Mode                 $mode
     * @param int                  $mask    0–7.
     */
    public function __construct(
        public array $modules,
        public int $version,
        public ErrorCorrectionLevel $level,
        public Mode $mode,
        public int $mask,
    ) {
    }

    /**
     * Modules per side: 17 + 4 × version.
     */
    public function size(): int
    {
        return count($this->modules);
    }

    /**
     * Whether the module at column `$x`, row `$y` is dark; outside the symbol is light.
     */
    public function isDark(int $x, int $y): bool
    {
        return $this->modules[$y][$x] ?? false;
    }
}
