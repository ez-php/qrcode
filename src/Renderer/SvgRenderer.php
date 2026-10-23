<?php

declare(strict_types=1);

namespace EzPhp\QrCode\Renderer;

use EzPhp\QrCode\QrCode;
use EzPhp\QrCode\QrCodeException;

/**
 * Class SvgRenderer
 *
 * Renders a QR code as a standalone SVG string. The viewBox is measured in
 * modules (quiet zone included), dark modules are one `<path>`, so the image
 * scales crisply; `$size` sets the width/height attributes.
 *
 *   $svg = (new SvgRenderer(size: 240))->render($qr);
 *
 * @package EzPhp\QrCode\Renderer
 */
final readonly class SvgRenderer
{
    /**
     * @param int|null $size       Width/height attribute in px; null omits them (the SVG fills its container).
     * @param int      $quietZone  Light border in modules (the spec requires 4).
     * @param string   $foreground Dark module colour (any SVG paint value).
     * @param string   $background Background colour; empty string for transparent.
     *
     * @throws QrCodeException When `$size` is not positive or `$quietZone` is negative.
     */
    public function __construct(
        private ?int $size = null,
        private int $quietZone = 4,
        private string $foreground = '#000000',
        private string $background = '#ffffff',
    ) {
        if ($size !== null && $size < 1) {
            throw new QrCodeException('The SVG size must be positive.');
        }

        if ($quietZone < 0) {
            throw new QrCodeException('The quiet zone cannot be negative.');
        }
    }

    /**
     * @param QrCode $qrCode
     *
     * @return string
     */
    public function render(QrCode $qrCode): string
    {
        $dimension = $qrCode->size() + 2 * $this->quietZone;
        $path = '';

        foreach ($qrCode->modules as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= sprintf('M%d %dh1v1h-1z', $x + $this->quietZone, $y + $this->quietZone);
                }
            }
        }

        $attributes = $this->size !== null ? sprintf(' width="%1$d" height="%1$d"', $this->size) : '';
        $background = $this->background !== ''
            ? sprintf('<rect width="%d" height="%d" fill="%s"/>', $dimension, $dimension, $this->escape($this->background))
            : '';

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d"%2$s shape-rendering="crispEdges">%3$s<path fill="%4$s" d="%5$s"/></svg>',
            $dimension,
            $attributes,
            $background,
            $this->escape($this->foreground),
            $path,
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
