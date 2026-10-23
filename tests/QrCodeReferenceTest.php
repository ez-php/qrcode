<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\QrCode\Encoder;
use EzPhp\QrCode\ErrorCorrectionLevel;
use EzPhp\QrCode\Internal\ReedSolomon;
use EzPhp\QrCode\Internal\Tables;
use EzPhp\QrCode\Mode;
use EzPhp\QrCode\QrCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class QrCodeReferenceTest
 *
 * Bit-for-bit comparison against symbols produced by the independent Python
 * encoder segno 1.6.6 (tests/Fixtures/segno-reference.json, generated once with
 * boost_error=False; each case stores the SHA-256 of the module matrix as rows
 * of "0"/"1" joined by "\n"). Covers every version 1–40, all levels, all masks,
 * all three modes, and automatic version/mode/mask selection.
 *
 * @package Tests
 */
#[CoversClass(Encoder::class)]
#[CoversClass(QrCode::class)]
#[CoversClass(Tables::class)]
#[CoversClass(ReedSolomon::class)]
#[CoversClass(Mode::class)]
#[CoversClass(ErrorCorrectionLevel::class)]
final class QrCodeReferenceTest extends TestCase
{
    /**
     * @return array<string, array{array{data: string, level: string, forcedVersion: int|null, forcedMask: int|null, version: int, mask: int, mode: string, sha256: string}}>
     */
    public static function referenceProvider(): array
    {
        $json = (string) file_get_contents(__DIR__ . '/Fixtures/segno-reference.json');

        /** @var list<array{data: string, level: string, forcedVersion: int|null, forcedMask: int|null, version: int, mask: int, mode: string, sha256: string}> $cases */
        $cases = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $provided = [];

        foreach ($cases as $i => $case) {
            $label = sprintf(
                '#%d v%s %s mask %s (%s)',
                $i,
                $case['forcedVersion'] ?? 'auto',
                $case['level'],
                $case['forcedMask'] ?? 'auto',
                $case['mode'],
            );
            $provided[$label] = [$case];
        }

        return $provided;
    }

    /**
     * @param array{data: string, level: string, forcedVersion: int|null, forcedMask: int|null, version: int, mask: int, mode: string, sha256: string} $case
     */
    #[DataProvider('referenceProvider')]
    public function testMatchesSegnoReference(array $case): void
    {
        $mode = match ($case['mode']) {
            'numeric' => Mode::Numeric,
            'alphanumeric' => Mode::Alphanumeric,
            default => Mode::Byte,
        };

        $qr = (new Encoder())->encode(
            $case['data'],
            ErrorCorrectionLevel::from($case['level']),
            $case['forcedVersion'],
            $case['forcedMask'],
            $case['forcedVersion'] !== null ? $mode : null,
        );

        $this->assertSame($case['version'], $qr->version, 'version');
        $this->assertSame($mode, $qr->mode, 'mode');
        $this->assertSame($case['mask'], $qr->mask, 'mask');
        $this->assertSame(4 * $case['version'] + 17, $qr->size());
        $this->assertSame($case['sha256'], hash('sha256', self::matrix($qr)), 'module matrix');
    }

    private static function matrix(QrCode $qr): string
    {
        return implode("\n", array_map(
            static fn (array $row): string => implode('', array_map(static fn (bool $dark): string => $dark ? '1' : '0', $row)),
            $qr->modules,
        ));
    }
}
