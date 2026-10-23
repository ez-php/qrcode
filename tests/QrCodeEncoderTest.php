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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class QrCodeEncoderTest
 *
 * Mode selection, options and failure paths (bit-exact output is covered by
 * QrCodeReferenceTest).
 *
 * @package Tests
 */
#[CoversClass(Encoder::class)]
#[CoversClass(Mode::class)]
#[CoversClass(QrCode::class)]
#[CoversClass(ErrorCorrectionLevel::class)]
#[UsesClass(Tables::class)]
#[UsesClass(ReedSolomon::class)]
#[UsesClass(QrCodeException::class)]
final class QrCodeEncoderTest extends TestCase
{
    public function testModeDetection(): void
    {
        $this->assertSame(Mode::Numeric, Mode::detect('0123'));
        $this->assertSame(Mode::Alphanumeric, Mode::detect('HELLO WORLD $%*+-./:'));
        $this->assertSame(Mode::Byte, Mode::detect('hello'));
        $this->assertSame(Mode::Byte, Mode::detect(''));
        $this->assertTrue(Mode::Numeric->supports(''));
        $this->assertFalse(Mode::Numeric->supports('12a'));
        $this->assertFalse(Mode::Alphanumeric->supports('abc'));
        $this->assertTrue(Mode::Byte->supports("\xff\x00"));
    }

    public function testCountBitsByVersionRange(): void
    {
        $this->assertSame([10, 12, 14], [Mode::Numeric->countBits(9), Mode::Numeric->countBits(10), Mode::Numeric->countBits(27)]);
        $this->assertSame([9, 11, 13], [Mode::Alphanumeric->countBits(1), Mode::Alphanumeric->countBits(26), Mode::Alphanumeric->countBits(40)]);
        $this->assertSame([8, 16, 16], [Mode::Byte->countBits(9), Mode::Byte->countBits(10), Mode::Byte->countBits(40)]);
    }

    public function testDefaultsAndForcedOptions(): void
    {
        $encoder = new Encoder();
        $qr = $encoder->encode('https://example.com');

        $this->assertSame(ErrorCorrectionLevel::M, $qr->level);
        $this->assertSame(Mode::Byte, $qr->mode);
        $this->assertSame(2, $qr->version);
        $this->assertSame(25, $qr->size());

        $forced = $encoder->encode('123', ErrorCorrectionLevel::H, 10, 6, Mode::Byte);
        $this->assertSame([10, 6, Mode::Byte, ErrorCorrectionLevel::H], [$forced->version, $forced->mask, $forced->mode, $forced->level]);
    }

    public function testHigherLevelsNeedLargerSymbols(): void
    {
        $data = str_repeat('ez-php ', 20);
        $versions = array_map(
            static fn (ErrorCorrectionLevel $level): int => (new Encoder())->encode($data, $level)->version,
            ErrorCorrectionLevel::cases(),
        );

        $this->assertSame($versions, array_values(array_unique($versions)));
        $sorted = $versions;
        sort($sorted);
        $this->assertSame($sorted, $versions);
    }

    public function testFunctionPatternsArePresent(): void
    {
        $qr = (new Encoder())->encode('finder', ErrorCorrectionLevel::L, 7);
        $size = $qr->size();

        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$x, $y]) {
            $this->assertTrue($qr->isDark($x, $y));
            $this->assertTrue($qr->isDark($x + 6, $y + 6));
            $this->assertFalse($qr->isDark($x + 1, $y + 1));
            $this->assertTrue($qr->isDark($x + 3, $y + 3));
        }

        $this->assertTrue($qr->isDark(8, $size - 8)); // dark module
        $this->assertFalse($qr->isDark(-1, 0));
        $this->assertFalse($qr->isDark(0, $size));
    }

    public function testEmptyStringEncodes(): void
    {
        $qr = (new Encoder())->encode('');

        $this->assertSame(1, $qr->version);
        $this->assertSame(Mode::Byte, $qr->mode);
    }

    public function testMaximumCapacity(): void
    {
        // Version 40-L holds 7089 digits.
        $this->assertSame(40, (new Encoder())->encode(str_repeat('7', 7089), ErrorCorrectionLevel::L)->version);

        $this->expectException(QrCodeException::class);
        $this->expectExceptionMessage('too long');

        (new Encoder())->encode(str_repeat('7', 7090), ErrorCorrectionLevel::L);
    }

    public function testDataNotFittingTheForcedVersion(): void
    {
        $this->expectException(QrCodeException::class);
        $this->expectExceptionMessage('does not fit into version 1');

        (new Encoder())->encode(str_repeat('x', 50), ErrorCorrectionLevel::L, 1);
    }

    public function testModeThatCannotEncodeTheData(): void
    {
        $this->expectException(QrCodeException::class);
        $this->expectExceptionMessage('Numeric mode');

        (new Encoder())->encode('abc', mode: Mode::Numeric);
    }

    public function testVersionOutOfRange(): void
    {
        $this->expectException(QrCodeException::class);

        (new Encoder())->encode('x', version: 41);
    }

    public function testMaskOutOfRange(): void
    {
        $this->expectException(QrCodeException::class);

        (new Encoder())->encode('x', mask: 8);
    }
}
