<?php

namespace Tests\Support\ChatVoice;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Writes Ogg voice files: pages as RFC 3533 defines them, carrying Opus (RFC 7845) or Vorbis.
 * Every page has a correct CRC, so any change to a written file breaks it.
 */
final class OggFixtures
{
    private static array $table = [];

    /** The same CRC a byte at a time from a 256-entry table, fast enough for 20 MiB test files. */
    public static function crc(string $bytes): int
    {
        if (self::$table === []) {
            for ($byte = 0; $byte < 256; $byte++) {
                self::$table[$byte] = self::crcBits(chr($byte));
            }
        }
        $crc = 0;
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $crc = (($crc << 8) & 0xFFFFFFFF) ^ self::$table[(($crc >> 24) ^ ord($bytes[$i])) & 0xFF];
        }

        return $crc;
    }

    /**
     * The Ogg CRC written out bit by bit: polynomial 0x04C11DB7, register starting at zero, most
     * significant bit first, no final inversion, over the page with its CRC field set to zero.
     */
    public static function crcBits(string $bytes): int
    {
        $crc = 0;
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($bytes[$i]) << 24;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x80000000) ? (($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF : ($crc << 1) & 0xFFFFFFFF;
            }
        }

        return $crc;
    }

    /** One page. $lacing are the segment table values, $body the bytes they describe. */
    public static function page(int $flags, int $granule, int $serial, int $sequence, array $lacing, string $body): string
    {
        $header = 'OggS'."\0".chr($flags).pack('P', $granule).pack('V', $serial).pack('V', $sequence)."\0\0\0\0".chr(count($lacing)).implode('', array_map('chr', $lacing));
        $page = $header.$body;

        return substr_replace($page, pack('V', self::crc($page)), 22, 4);
    }

    /**
     * Options: codec ('opus', 'vorbis' or anything else for an unknown first packet), seconds, seed,
     * channels, preSkip, trim (end trimming in samples), perPage (packets per audio page), noEnd,
     * secondStream, tagsOnFirstPage, granuleSkew ([page index, amount]), pages (callback over the page list).
     */
    public static function file(array $o = []): VoiceFile
    {
        $random = new Randomizer(new Mt19937($o['seed'] ?? 1));
        $codec = $o['codec'] ?? 'opus';
        $serial = $random->getInt(1, 0x7FFFFFFF);
        $seconds = $o['seconds'] ?? 5.0;
        $packets = [];
        if ($codec === 'vorbis') {
            [$headers, $audio, $samplesPerPacket, $rate] = self::vorbis($random, $seconds);
        } else {
            $preSkip = $o['preSkip'] ?? 312;
            $id = $codec === 'opus' ? OpusFrames::head($o['channels'] ?? 1, $preSkip) : 'Speex   '.str_repeat("\0", 72);
            $vendor = 'Oblivion tests';
            $tags = 'OpusTags'.pack('V', strlen($vendor)).$vendor.pack('V', 0);
            $headers = [[$id], [$tags]];
            $audio = [];
            for ($i = 0, $filled = 0; $filled < $seconds * 48_000 + $preSkip; $i++) {
                // Mostly 20 ms single frames; now and then a long packet over 255 bytes, or 40 ms in two frames.
                $audio[] = match (true) {
                    $i % 97 === 50 => OpusFrames::single($random, 31, false, 600),
                    $i % 61 === 30 => OpusFrames::constant($random, 2, 31, false),
                    default => OpusFrames::single($random, 31, false),
                };
                $filled += $i % 61 === 30 && $i % 97 !== 50 ? 1920 : 960;
            }
            $samplesPerPacket = fn (string $packet) => OpusFrames::samples(ord($packet[0]) >> 3, (ord($packet[0]) & 3) === 3 ? ord($packet[1]) & 0x3F : 1);
            $rate = 48_000;
        }
        if ($o['tagsOnFirstPage'] ?? false) {
            $headers = [array_merge(...$headers)];
        }

        $pages = [];
        foreach ($headers as $packetsOnPage) {
            $pages[] = ['packets' => $packetsOnPage, 'granule' => 0];
        }
        $perPage = $o['perPage'] ?? 50;
        $running = 0;
        $chunks = array_chunk($audio, $perPage);
        foreach ($chunks as $index => $chunk) {
            foreach ($chunk as $packet) {
                $running += $samplesPerPacket($packet);
            }
            $pages[] = ['packets' => $chunk, 'granule' => $running];
        }
        $trim = $o['trim'] ?? min(300, $samplesPerPacket(end($audio)));
        if ($codec === 'vorbis') {
            $trim = $o['trim'] ?? 0;
        }
        $pages[array_key_last($pages)]['granule'] -= $trim;
        if (isset($o['granuleSkew'])) {
            [$page, $amount] = $o['granuleSkew'];
            $pages[$page]['granule'] += $amount;
        }
        if (isset($o['pages'])) {
            $pages = ($o['pages'])($pages);
        }

        $bytes = '';
        $extents = [];
        $sequence = 0;
        $pending = '';
        foreach ($pages as $index => $page) {
            $last = $index === array_key_last($pages);
            $flags = ($index === 0 ? 0x02 : 0) | ($last && ! ($o['noEnd'] ?? false) ? 0x04 : 0) | ($o['pageFlags'][$index] ?? 0);
            [$lacing, $body] = self::lace($page['packets']);
            if (($o['sequenceGap'] ?? null) === $index) {
                $sequence++;
            }
            $written = self::page($flags, $page['granule'], $serial, $sequence++, $lacing, $body);
            $extents[] = [strlen($bytes), strlen($bytes) + strlen($written)];
            $bytes .= $written;
            if ($index === 0 && ($o['secondStream'] ?? false)) {
                $other = self::page(0x02, 0, $serial + 1, 0, [19], OpusFrames::head());
                $extents[] = [strlen($bytes), strlen($bytes) + strlen($other)];
                $bytes .= $other;
            }
        }
        $finalGranule = $pages[array_key_last($pages)]['granule'];
        $ns = $codec === 'vorbis'
            ? intdiv($finalGranule * 1_000_000_000 + $rate - 1, $rate)
            : intdiv(($finalGranule - ($preSkip ?? 0)) * 1_000_000_000 + 47_999, 48_000);

        return new VoiceFile($o['name'] ?? "ogg-$codec", $bytes, 'audio/ogg', intdiv($ns + 999_999, 1_000_000), $extents, [], true);
    }

    /** The segment table and body for a list of whole packets (RFC 3533 lacing). */
    public static function lace(array $packets): array
    {
        $lacing = [];
        foreach ($packets as $packet) {
            $length = strlen($packet);
            while ($length >= 255) {
                $lacing[] = 255;
                $length -= 255;
            }
            $lacing[] = $length;
        }

        return [$lacing, implode('', $packets)];
    }

    /**
     * Vorbis headers and audio. The setup header is opaque here (the checker does not read it), and every
     * audio packet is a long block, so after the first each adds half a long block: 1024 samples.
     */
    private static function vorbis(Randomizer $random, float $seconds): array
    {
        $rate = 44_100;
        $id = "\x01vorbis".pack('V', 0).chr(1).pack('V', $rate).pack('V', 0).pack('V', 96_000).pack('V', 0).chr(0xB8).chr(1);
        $vendor = 'Oblivion tests';
        $comment = "\x03vorbis".pack('V', strlen($vendor)).$vendor.pack('V', 0).chr(1);
        $setup = "\x05vorbis".$random->getBytes(300).chr(1);
        $audio = [];
        $count = (int) ceil($seconds * $rate / 1024) + 1;
        for ($i = 0; $i < $count; $i++) {
            $audio[] = chr($random->getInt(0, 127) << 1).$random->getBytes($random->getInt(20, 200));
        }
        $samples = fn (string $packet) => 1024;

        // The first audio packet only primes the decoder, so the page positions start one packet late.
        return [[[$id], [$comment, $setup]], $audio, (function () use ($samples) {
            $first = true;

            return function (string $packet) use (&$first, $samples) {
                if ($first) {
                    $first = false;

                    return 0;
                }

                return $samples($packet);
            };
        })(), $rate];
    }

    public static function random(int $seed): Randomizer
    {
        return new Randomizer(new Mt19937($seed));
    }
}
