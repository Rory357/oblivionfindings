<?php

namespace Tests\Support\ChatVoice;

use Random\Randomizer;

/**
 * Builds Opus packets with valid framing (RFC 6716 section 3). The frame bytes are random: the
 * checker never decodes audio, it only reads the TOC byte and the frame lengths.
 */
final class OpusFrames
{
    /** Frame length in microseconds for each TOC configuration, from RFC 6716 table 2. */
    public static function frameMicroseconds(int $config): int
    {
        return match (true) {
            $config < 12 => [10_000, 20_000, 40_000, 60_000][$config % 4],
            $config < 16 => [10_000, 20_000][$config % 2],
            default => [2_500, 5_000, 10_000, 20_000][$config % 4],
        };
    }

    /** 48 kHz samples in a packet made of $frames frames of configuration $config. */
    public static function samples(int $config, int $frames): int
    {
        return intdiv(self::frameMicroseconds($config) * 48, 1000) * $frames;
    }

    /** One frame, 20 ms fullband CELT by default (what browsers send). */
    public static function single(Randomizer $random, int $config = 31, bool $stereo = true, ?int $bytes = null): string
    {
        return chr(($config << 3) | ($stereo ? 0x04 : 0)).$random->getBytes($bytes ?? $random->getInt(40, 240));
    }

    /** Code 3 with $frames equal frames, constant bitrate, as Chrome records three 20 ms frames per block. */
    public static function constant(Randomizer $random, int $frames, int $config = 31, bool $stereo = true, ?int $frameBytes = null): string
    {
        $frameBytes ??= $random->getInt(30, 120);

        return chr(($config << 3) | ($stereo ? 0x04 : 0) | 0x03).chr($frames).$random->getBytes($frames * $frameBytes);
    }

    /** Code 3 with variable-size frames and $padding bytes of padding. */
    public static function variable(Randomizer $random, int $frames, int $config = 31, int $padding = 0): string
    {
        $sizes = [];
        for ($i = 0; $i < $frames; $i++) {
            $sizes[] = $random->getInt(1, 300);
        }
        $header = chr(($config << 3) | 0x03).chr(0x80 | ($padding > 0 ? 0x40 : 0) | $frames);
        if ($padding > 0) {
            $left = $padding;
            while ($left >= 254) {
                $header .= chr(255);
                $left -= 254;
            }
            $header .= chr($left);
        }
        foreach (array_slice($sizes, 0, -1) as $size) {
            // Lengths from 252 are two bytes: the first is 252 to 255, and length = second * 4 + first.
            $first = 252 + $size % 4;
            $header .= $size < 252 ? chr($size) : chr($first).chr(intdiv($size - $first, 4));
        }
        $body = '';
        foreach ($sizes as $size) {
            $body .= $random->getBytes($size);
        }

        return $header.$body.str_repeat("\0", $padding);
    }

    /** The 19-byte OpusHead identification header (RFC 7845 section 5.1), channel mapping family 0. */
    public static function head(int $channels = 2, int $preSkip = 312, int $version = 1, int $family = 0): string
    {
        return 'OpusHead'.chr($version).chr($channels).pack('v', $preSkip).pack('V', 48000).pack('v', 0).chr($family);
    }
}
