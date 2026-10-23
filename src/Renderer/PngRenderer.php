<?php

declare(strict_types=1);

namespace EzPhp\QrCode\Renderer;

use EzPhp\QrCode\QrCode;
use EzPhp\QrCode\QrCodeException;

/**
 * Class PngRenderer
 *
 * Renders a QR code as PNG bytes via ext-gd (a suggested, not required,
 * extension — the encoder and SvgRenderer work without it).
 *
 *   header('Content-Type: image/png');
 *   echo (new PngRenderer(scale: 8))->render($qr);
 *
 * @package EzPhp\QrCode\Renderer
 */
final readonly class PngRenderer
{
    /**
     * @param int    $scale      Pixels per module.
     * @param int    $quietZone  Light border in modules (the spec requires 4).
     * @param string $foreground Dark module colour as #rrggbb.
     * @param string $background Background colour as #rrggbb.
     *
     * @throws QrCodeException When ext-gd is missing, `$scale` is not positive, `$quietZone` is negative or a colour is not #rrggbb.
     */
    public function __construct(
        private int $scale = 4,
        private int $quietZone = 4,
        private string $foreground = '#000000',
        private string $background = '#ffffff',
    ) {
        if (!extension_loaded('gd')) {
            throw new QrCodeException('PngRenderer requires the gd extension.');
        }

        if ($scale < 1) {
            throw new QrCodeException('The PNG scale must be positive.');
        }

        if ($quietZone < 0) {
            throw new QrCodeException('The quiet zone cannot be negative.');
        }

        self::rgb($foreground);
        self::rgb($background);
    }

    /**
     * @param QrCode $qrCode
     *
     * @return string PNG bytes.
     */
    public function render(QrCode $qrCode): string
    {
        $pixels = ($qrCode->size() + 2 * $this->quietZone) * $this->scale;
        $image = imagecreatetruecolor(max(1, $pixels), max(1, $pixels));

        [$r, $g, $b] = self::rgb($this->background);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $r, $g, $b));

        [$r, $g, $b] = self::rgb($this->foreground);
        $dark = (int) imagecolorallocate($image, $r, $g, $b);

        foreach ($qrCode->modules as $y => $row) {
            foreach ($row as $x => $isDark) {
                if ($isDark) {
                    $left = ($x + $this->quietZone) * $this->scale;
                    $top = ($y + $this->quietZone) * $this->scale;
                    imagefilledrectangle($image, $left, $top, $left + $this->scale - 1, $top + $this->scale - 1, $dark);
                }
            }
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @throws QrCodeException When `$hex` is not #rrggbb.
     *
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    private static function rgb(string $hex): array
    {
        if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m) !== 1) {
            throw new QrCodeException(sprintf('Colour "%s" must be #rrggbb.', $hex));
        }

        /** @var int<0, 255> $r */
        $r = (int) hexdec($m[1]);
        /** @var int<0, 255> $g */
        $g = (int) hexdec($m[2]);
        /** @var int<0, 255> $b */
        $b = (int) hexdec($m[3]);

        return [$r, $g, $b];
    }
}
