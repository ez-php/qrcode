<?php

declare(strict_types=1);

namespace EzPhp\QrCode;

use EzPhp\QrCode\Internal\ReedSolomon;
use EzPhp\QrCode\Internal\Tables;

/**
 * Class Encoder
 *
 * Encodes a string into a QR code symbol (ISO/IEC 18004, model 2, versions
 * 1–40): single-segment numeric, alphanumeric or byte mode, Reed-Solomon
 * error correction, and the lowest-penalty of the eight data masks unless one
 * is forced.
 *
 *   $qr = (new Encoder())->encode('otpauth://totp/Example:alice?secret=JBSWY3DPEHPK3PXP');
 *   echo (new SvgRenderer())->render($qr);
 *
 * Byte mode writes the string's bytes as-is (UTF-8 for ordinary PHP strings)
 * without an ECI header, which is what every common scanner expects.
 *
 * @package EzPhp\QrCode
 */
final class Encoder
{
    /**
     * Encode `$data`.
     *
     * @param string               $data
     * @param ErrorCorrectionLevel $level   Error-correction level (kept as requested, never boosted).
     * @param int|null             $version Force a version (1–40); null picks the smallest that fits.
     * @param int|null             $mask    Force a mask (0–7); null picks the lowest-penalty one.
     * @param Mode|null            $mode    Force a mode; null picks the densest one the data fits.
     *
     * @throws QrCodeException When the data does not fit, the mode cannot encode it, or an option is out of range.
     *
     * @return QrCode
     */
    public function encode(
        string $data,
        ErrorCorrectionLevel $level = ErrorCorrectionLevel::M,
        ?int $version = null,
        ?int $mask = null,
        ?Mode $mode = null,
    ): QrCode {
        $mode ??= Mode::detect($data);

        if (!$mode->supports($data)) {
            throw new QrCodeException(sprintf('The data cannot be encoded in %s mode.', $mode->name));
        }

        if ($version !== null && ($version < 1 || $version > 40)) {
            throw new QrCodeException('The version must be between 1 and 40.');
        }

        if ($mask !== null && ($mask < 0 || $mask > 7)) {
            throw new QrCodeException('The mask must be between 0 and 7.');
        }

        $version ??= $this->smallestVersion($data, $mode, $level);
        $bits = $this->dataBits($data, $mode, $version);
        $capacity = Tables::dataCodewords($version, $level) * 8;

        if (count($bits) > $capacity) {
            throw new QrCodeException(sprintf('The data does not fit into version %d at level %s.', $version, $level->value));
        }

        $codewords = $this->interleave($this->pad($bits, $capacity), $version, $level);

        [$modules, $isFunction] = $this->functionPatterns($version);
        $modules = $this->placeData($modules, $isFunction, $codewords);

        if ($mask === null) {
            // Evaluate before format/version information is written: those areas (and
            // the dark module) count as light (ISO/IEC 18004 7.8; same as segno).
            $size = count($modules);
            $evaluated = $modules;
            $evaluated[$size - 8][8] = false;
            $best = PHP_INT_MAX;

            for ($candidate = 0; $candidate < 8; $candidate++) {
                $penalty = $this->penalty($this->applyMask($evaluated, $isFunction, $candidate));

                if ($penalty < $best) {
                    $best = $penalty;
                    $mask = $candidate;
                }
            }
        }

        /** @var int<0, 7> $mask */
        $modules = $this->writeInformation($this->applyMask($modules, $isFunction, $mask), $version, $level, $mask);

        return new QrCode(array_values(array_map(array_values(...), $modules)), $version, $level, $mode, $mask);
    }

    /**
     * @throws QrCodeException When the data exceeds version 40.
     */
    private function smallestVersion(string $data, Mode $mode, ErrorCorrectionLevel $level): int
    {
        for ($version = 1; $version <= 40; $version++) {
            if (count($this->dataBits($data, $mode, $version)) <= Tables::dataCodewords($version, $level) * 8) {
                return $version;
            }
        }

        throw new QrCodeException(sprintf('The data is too long for a QR code at level %s.', $level->value));
    }

    /**
     * Mode indicator, character count and encoded data.
     *
     * @return list<int>
     */
    private function dataBits(string $data, Mode $mode, int $version): array
    {
        $bits = [];
        $this->append($bits, $mode->indicator(), 4);

        switch ($mode) {
            case Mode::Numeric:
                $this->append($bits, strlen($data), $mode->countBits($version));

                foreach (str_split($data, 3) as $group) {
                    $this->append($bits, (int) $group, [1 => 4, 2 => 7, 3 => 10][strlen($group)]);
                }

                break;

            case Mode::Alphanumeric:
                $this->append($bits, strlen($data), $mode->countBits($version));

                foreach (str_split($data, 2) as $pair) {
                    $first = strpos(Mode::ALPHANUMERIC_CHARSET, $pair[0]);

                    if (strlen($pair) === 2) {
                        $this->append($bits, (int) $first * 45 + (int) strpos(Mode::ALPHANUMERIC_CHARSET, $pair[1]), 11);
                    } else {
                        $this->append($bits, (int) $first, 6);
                    }
                }

                break;

            case Mode::Byte:
                $this->append($bits, strlen($data), $mode->countBits($version));

                foreach (str_split($data) as $byte) {
                    $this->append($bits, ord($byte), 8);
                }

                break;
        }

        return $bits;
    }

    /**
     * Terminator, byte alignment and the 0xEC/0x11 pad codewords.
     *
     * @param list<int> $bits
     *
     * @return list<int> Data codewords.
     */
    private function pad(array $bits, int $capacity): array
    {
        $this->append($bits, 0, min(4, $capacity - count($bits)));
        $this->append($bits, 0, (8 - count($bits) % 8) % 8);

        $codewords = [];

        foreach (array_chunk($bits, 8) as $byte) {
            $codewords[] = (int) bindec(implode('', $byte));
        }

        for ($pad = 0xEC; count($codewords) < $capacity / 8; $pad ^= 0xEC ^ 0x11) {
            $codewords[] = $pad;
        }

        return $codewords;
    }

    /**
     * Split into blocks, add Reed-Solomon codewords, interleave.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    private function interleave(array $data, int $version, ErrorCorrectionLevel $level): array
    {
        $numBlocks = Tables::numBlocks($version, $level);
        $eccLength = Tables::eccPerBlock($version, $level);
        $rawCodewords = intdiv(Tables::rawDataModules($version), 8);
        $numShortBlocks = $numBlocks - $rawCodewords % $numBlocks;
        $shortBlockLength = intdiv($rawCodewords, $numBlocks);

        $dataBlocks = [];
        $eccBlocks = [];

        for ($i = 0, $offset = 0; $i < $numBlocks; $i++) {
            $length = $shortBlockLength - $eccLength + ($i < $numShortBlocks ? 0 : 1);
            $block = array_slice($data, $offset, $length);
            $offset += $length;
            $dataBlocks[] = $block;
            $eccBlocks[] = ReedSolomon::remainder($block, $eccLength);
        }

        $result = [];
        $longest = $shortBlockLength - $eccLength + 1;

        for ($i = 0; $i < $longest; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $eccLength; $i++) {
            foreach ($eccBlocks as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    /**
     * Finder, separator, timing and alignment patterns plus the dark module;
     * format and version areas are reserved (filled in finish()).
     *
     * @return array{array<int, array<int, bool>>, array<int, array<int, bool>>} modules, isFunction
     */
    private function functionPatterns(int $version): array
    {
        $size = 4 * $version + 17;
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $isFunction = $modules;

        $set = static function (int $x, int $y, bool $dark) use (&$modules, &$isFunction): void {
            $modules[$y][$x] = $dark;
            $isFunction[$y][$x] = true;
        };

        for ($i = 0; $i < $size; $i++) {
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }

        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;

                    if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                        $distance = max(abs($dx), abs($dy));
                        $set($x, $y, $distance !== 2 && $distance !== 4);
                    }
                }
            }
        }

        $positions = Tables::alignmentPositions($version);
        $last = count($positions) - 1;

        foreach ($positions as $i => $cx) {
            foreach ($positions as $j => $cy) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue; // overlaps a finder pattern
                }

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        // Reserve format information (both copies) and the dark module;
        // index 6 is the timing pattern and stays as drawn above.
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $set(8, $i, false);
                $set($i, 8, false);
            }
        }

        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, false);
            $set(8, $size - 1 - $i, false);
        }

        $set(8, $size - 8, true);

        if ($version >= 7) {
            for ($i = 0; $i < 18; $i++) {
                $set($size - 11 + $i % 3, intdiv($i, 3), false);
                $set(intdiv($i, 3), $size - 11 + $i % 3, false);
            }
        }

        return [$modules, $isFunction];
    }

    /**
     * Data bits in the two-column zigzag, bottom-right to top-left.
     *
     * @param array<int, array<int, bool>> $modules
     * @param array<int, array<int, bool>> $isFunction
     * @param list<int>        $codewords
     *
     * @return array<int, array<int, bool>>
     */
    private function placeData(array $modules, array $isFunction, array $codewords): array
    {
        $size = count($modules);
        $total = count($codewords) * 8;
        $i = 0;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5; // skip the vertical timing pattern
            }

            $upward = (($right + 1) & 2) === 0;

            for ($vertical = 0; $vertical < $size; $vertical++) {
                $y = $upward ? $size - 1 - $vertical : $vertical;

                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;

                    if (!$isFunction[$y][$x] && $i < $total) {
                        $modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }

        return $modules;
    }

    /**
     * Invert the data modules the mask pattern selects.
     *
     * @param array<int, array<int, bool>> $modules
     * @param array<int, array<int, bool>> $isFunction
     *
     * @return array<int, array<int, bool>>
     */
    private function applyMask(array $modules, array $isFunction, int $mask): array
    {
        $size = count($modules);

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (!$isFunction[$y][$x] && $this->masked($mask, $x, $y)) {
                    $modules[$y][$x] = !$modules[$y][$x];
                }
            }
        }

        return $modules;
    }

    /**
     * Write the format information (both copies) and, from version 7, the version information.
     *
     * @param array<int, array<int, bool>> $modules
     *
     * @return array<int, array<int, bool>>
     */
    private function writeInformation(array $modules, int $version, ErrorCorrectionLevel $level, int $mask): array
    {
        $size = count($modules);
        $data = $level->formatBits() << 3 | $mask;
        $remainder = $data;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }

        $format = ($data << 10 | $remainder) ^ 0x5412;
        $bit = static fn (int $value, int $i): bool => (($value >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $modules[$i][8] = $bit($format, $i);
        }

        $modules[7][8] = $bit($format, 6);
        $modules[8][8] = $bit($format, 7);
        $modules[8][7] = $bit($format, 8);

        for ($i = 9; $i < 15; $i++) {
            $modules[8][14 - $i] = $bit($format, $i);
        }

        for ($i = 0; $i < 8; $i++) {
            $modules[8][$size - 1 - $i] = $bit($format, $i);
        }

        for ($i = 8; $i < 15; $i++) {
            $modules[$size - 15 + $i][8] = $bit($format, $i);
        }

        if ($version >= 7) {
            $remainder = $version;

            for ($i = 0; $i < 12; $i++) {
                $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
            }

            $versionBits = $version << 12 | $remainder;

            for ($i = 0; $i < 18; $i++) {
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $modules[$b][$a] = $bit($versionBits, $i);
                $modules[$a][$b] = $bit($versionBits, $i);
            }
        }

        return $modules;
    }

    private function masked(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => $x * $y % 2 + $x * $y % 3 === 0,
            6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
            default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
        };
    }

    /**
     * Penalty score of a masked symbol (ISO/IEC 18004 7.8.3: N1 = 3, N2 = 3, N3 = 40, N4 = 10).
     *
     * @param array<int, array<int, bool>> $modules
     */
    private function penalty(array $modules): int
    {
        $size = count($modules);
        $score = 0;
        $dark = 0;

        for ($i = 0; $i < $size; $i++) {
            $row = $modules[$i];
            $column = array_column($modules, $i);
            $score += $this->runPenalty($row) + $this->runPenalty($column);
            $score += $this->finderLikePenalty($row) + $this->finderLikePenalty($column);

            foreach ($row as $j => $module) {
                $dark += (int) $module;

                if ($i > 0 && $j > 0 && $module === $row[$j - 1] && $module === $modules[$i - 1][$j] && $module === $modules[$i - 1][$j - 1]) {
                    $score += 3;
                }
            }
        }

        $total = $size * $size;

        return $score + 10 * intdiv(abs(20 * $dark - 10 * $total), $total);
    }

    /**
     * N1: 3 + (n − 5) for every run of n ≥ 5 same-coloured modules.
     *
     * @param array<int, bool> $line
     */
    private function runPenalty(array $line): int
    {
        $score = 0;
        $run = 0;
        $previous = null;

        foreach ($line as $module) {
            if ($module === $previous) {
                $run++;

                continue;
            }

            if ($run >= 5) {
                $score += $run - 2;
            }

            $run = 1;
            $previous = $module;
        }

        return $run >= 5 ? $score + $run - 2 : $score;
    }

    /**
     * N3: 40 for every 1:1:3:1:1 pattern with four light modules (or the symbol
     * edge) on at least one side.
     *
     * @param array<int, bool> $line
     */
    private function finderLikePenalty(array $line): int
    {
        $size = count($line);
        $text = implode('', array_map(static fn (bool $module): string => $module ? '1' : '0', $line));
        $score = 0;
        $index = strpos($text, '1011101');

        while ($index !== false) {
            $after = $index + 7;
            $before = substr($text, max($index - 4, 0), $index - max($index - 4, 0));
            $following = substr($text, $after, 4);

            if ($index === 0 || $index === $size - 7 || !str_contains($before, '1') || !str_contains($following, '1')) {
                $score += 40;
                $next = $after;
            } else {
                $next = $index + 4;
            }

            $index = strpos($text, '1011101', $next);
        }

        return $score;
    }

    /**
     * @param list<int> $bits
     */
    private function append(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }
}
