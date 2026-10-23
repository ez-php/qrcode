<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\QrCode\Encoder;
use EzPhp\QrCode\ErrorCorrectionLevel;
use EzPhp\QrCode\Internal\ReedSolomon;
use EzPhp\QrCode\Internal\Tables;
use EzPhp\QrCode\Mode;
use EzPhp\QrCode\QrCode;
use EzPhp\QrCode\QrCodeException;
use EzPhp\QrCode\Renderer\PngRenderer;
use EzPhp\QrCode\Renderer\SvgRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class QrCodeRendererTest
 *
 * @package Tests
 */
#[CoversClass(SvgRenderer::class)]
#[CoversClass(PngRenderer::class)]
#[UsesClass(Encoder::class)]
#[UsesClass(QrCode::class)]
#[UsesClass(Mode::class)]
#[UsesClass(ErrorCorrectionLevel::class)]
#[UsesClass(Tables::class)]
#[UsesClass(ReedSolomon::class)]
#[UsesClass(QrCodeException::class)]
final class QrCodeRendererTest extends TestCase
{
    private function qr(): QrCode
    {
        return (new Encoder())->encode('HELLO', ErrorCorrectionLevel::L, 1);
    }

    public function testSvgDrawsOneSquarePerDarkModuleInsideTheQuietZone(): void
    {
        $qr = $this->qr();
        $svg = (new SvgRenderer())->render($qr);

        $dark = array_sum(array_map(static fn (array $row): int => count(array_filter($row)), $qr->modules));

        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 29 29" shape-rendering="crispEdges">', $svg);
        $this->assertStringContainsString('<rect width="29" height="29" fill="#ffffff"/>', $svg);
        $this->assertSame($dark, substr_count($svg, 'h1v1h-1z'));
        $this->assertStringContainsString('M4 4h1v1h-1z', $svg); // top-left finder corner, offset by the quiet zone

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($svg));
    }

    public function testSvgOptions(): void
    {
        $svg = (new SvgRenderer(size: 200, quietZone: 0, foreground: '"red"', background: ''))->render($this->qr());

        $this->assertStringContainsString('viewBox="0 0 21 21" width="200" height="200"', $svg);
        $this->assertStringNotContainsString('<rect', $svg);
        $this->assertStringContainsString('fill="&quot;red&quot;"', $svg);
        $this->assertStringContainsString('M0 0h1v1h-1z', $svg);
    }

    public function testSvgRejectsInvalidOptions(): void
    {
        $this->expectException(QrCodeException::class);

        new SvgRenderer(size: 0);
    }

    public function testSvgRejectsNegativeQuietZone(): void
    {
        $this->expectException(QrCodeException::class);

        new SvgRenderer(quietZone: -1);
    }

    #[RequiresPhpExtension('gd')]
    public function testPngPixelsMatchTheModules(): void
    {
        $qr = $this->qr();
        $png = (new PngRenderer(scale: 3, quietZone: 2, foreground: '#112233', background: '#fafafa'))->render($qr);

        $this->assertStringStartsWith("\x89PNG", $png);
        $image = imagecreatefromstring($png);
        $this->assertNotFalse($image);
        $this->assertSame((21 + 4) * 3, imagesx($image));

        foreach ($qr->modules as $y => $row) {
            foreach ($row as $x => $dark) {
                $rgb = imagecolorat($image, ($x + 2) * 3 + 1, ($y + 2) * 3 + 1);
                $this->assertSame($dark ? 0x112233 : 0xFAFAFA, $rgb, "module {$x},{$y}");
            }
        }

        $this->assertSame(0xFAFAFA, imagecolorat($image, 0, 0)); // quiet zone
    }

    #[RequiresPhpExtension('gd')]
    public function testPngRejectsInvalidColour(): void
    {
        $this->expectException(QrCodeException::class);
        $this->expectExceptionMessage('#rrggbb');

        new PngRenderer(foreground: 'black');
    }

    #[RequiresPhpExtension('gd')]
    public function testPngRejectsInvalidScale(): void
    {
        $this->expectException(QrCodeException::class);

        new PngRenderer(scale: 0);
    }

    #[RequiresPhpExtension('gd')]
    public function testPngRejectsNegativeQuietZone(): void
    {
        $this->expectException(QrCodeException::class);

        new PngRenderer(quietZone: -1);
    }
}
