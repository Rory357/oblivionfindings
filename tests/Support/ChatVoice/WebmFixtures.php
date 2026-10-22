<?php

namespace Tests\Support\ChatVoice;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Writes WebM voice files from EBML (RFC 8794) and Matroska (RFC 9559) element definitions.
 *
 * Styles copy what the browsers write: 'chrome' (unknown-size Segment and Clusters, three 20 ms frames
 * per block, no Duration), 'firefox' (8-byte sizes, an empty SeekHead, Duration 0, track number 2,
 * one frame per block) and 'known' (known sizes everywhere, a real Duration, BlockGroups and laced blocks).
 */
final class WebmFixtures
{
    public const EBML = 0x1A45DFA3;

    public const SEGMENT = 0x18538067;

    public const INFO = 0x1549A966;

    public const TRACKS = 0x1654AE6B;

    public const TRACK_ENTRY = 0xAE;

    public const CLUSTER = 0x1F43B675;

    public const SIMPLE_BLOCK = 0xA3;

    public const BLOCK_GROUP = 0xA0;

    /**
     * Options: style, seconds, seed, channels, trackNumber, docType, codec, trackType, entries (TrackEntry copies),
     * video (adds a Video element), duration (declared, in ms), segment / entry / blocks (callbacks that may change
     * the element lists or the block list before writing).
     */
    public static function file(array $o = []): VoiceFile
    {
        $style = $o['style'] ?? 'chrome';
        $random = new Randomizer(new Mt19937($o['seed'] ?? 1));
        $channels = $o['channels'] ?? 2;
        $track = $o['trackNumber'] ?? ($style === 'firefox' ? 2 : 1);
        $wide = $style === 'firefox' ? ['sizeLength' => 8] : [];
        $header = self::el(self::EBML, [
            self::uint(0x4286, 1),
            self::uint(0x42F7, 1, ['value' => true]),
            self::uint(0x42F2, 4, ['value' => true]),
            self::uint(0x42F3, 8, ['value' => true]),
            self::text(0x4282, $o['docType'] ?? 'webm', ['value' => true]),
            self::uint(0x4287, $style === 'firefox' ? 2 : 4),
            self::uint(0x4285, 2),
        ], $wide);
        if (isset($o['header'])) {
            $header['payload'] = ($o['header'])($header['payload']);
        }

        $blocks = self::blocks($style, $o['seconds'] ?? 5.0, $random, $track);
        if (isset($o['blocks'])) {
            $blocks = ($o['blocks'])($blocks, $random);
        }
        // Firefox writes a placeholder Duration of 0; 'known' declares the real length; Chrome writes none.
        $declared = array_key_exists('duration', $o) ? $o['duration'] : match ($style) {
            'firefox' => 0.0,
            'known' => self::expectedNs($blocks, null) / 1_000_000,
            default => null,
        };
        $info = [self::uint(0x2AD7B1, 1_000_000, ['strict' => false])];
        if ($declared !== null) {
            $info[] = self::float(0x4489, $declared, 8, ['strict' => false]);
        }
        $info[] = self::text(0x4D80, 'Oblivion tests', ['strict' => false]);
        $info[] = self::text(0x5741, 'Oblivion tests', ['strict' => false]);

        $entries = [];
        for ($i = 0; $i < ($o['entries'] ?? 1); $i++) {
            $entry = [
                self::uint(0xD7, $track + $i, ['value' => $i === 0 && ($o['entries'] ?? 1) === 1]),
                self::uint(0x73C5, $random->getInt(1, PHP_INT_MAX), ['strict' => false]),
                self::uint(0x83, $o['trackType'] ?? 2, ['value' => true]),
            ];
            if ($style === 'firefox') {
                $entry[] = self::uint(0x56AA, 6_500_000, ['strict' => false]);
                $entry[] = self::uint(0x56BB, 80_000_000, ['strict' => false]);
            }
            $entry[] = self::text(0x86, $o['codec'] ?? 'A_OPUS', ['value' => true]);
            $entry[] = self::el(0x63A2, $o['opusHead'] ?? OpusFrames::head($channels), ['valueRanges' => [[0, 8], [9, 10], [18, 19]]]);
            $entry[] = self::el(0xE1, [
                self::float(0xB5, 48000.0, $style === 'firefox' ? 8 : 4, ['strict' => false]),
                self::uint(0x9F, $channels, ['strict' => false]),
            ], ['strict' => false] + $wide);
            if ($o['video'] ?? false) {
                $entry[] = self::el(0xE0, [self::uint(0xB0, 320), self::uint(0xBA, 240)], ['strict' => false]);
            }
            if (isset($o['entry'])) {
                $entry = ($o['entry'])($entry);
            }
            $entries[] = self::el(self::TRACK_ENTRY, $entry, $wide);
        }

        $segment = [];
        if ($style === 'firefox') {
            $segment[] = self::el(0x114D9B74, '', ['sizeLength' => 8]);
        }
        $segment[] = self::el(self::INFO, $info, $wide);
        $segment[] = self::el(self::TRACKS, $entries, $wide);
        foreach (self::clusters($blocks, $style) as $cluster) {
            $segment[] = $cluster;
        }
        if (isset($o['segment'])) {
            $segment = ($o['segment'])($segment);
        }
        [$bytes, $map] = self::nodes([$header, self::el(self::SEGMENT, $segment, ['unknown' => $style !== 'known'])]);
        $ms = intdiv(self::expectedNs($blocks, $declared) + 999_999, 1_000_000);

        return new VoiceFile($o['name'] ?? "webm-$style", $bytes, 'audio/webm', $ms, $map->extents, $map->critical, false, $map->sizes);
    }

    /**
     * Blocks as ['time' => ms, 'frames' => list of Opus packets, 'samples' => 48 kHz samples,
     * 'lacing' => 0 none, 1 Xiph, 2 fixed, 3 EBML, 'group' => in a BlockGroup, 'track' => number].
     */
    private static function blocks(string $style, float $seconds, Randomizer $random, int $track): array
    {
        $blocks = [];
        $start = $style === 'firefox' ? 6 : 0;
        $step = $style === 'chrome' ? 60 : 20;
        $count = (int) ceil($seconds * 1000 / $step);
        $time = $start;
        for ($i = 0; $i < $count; $i++) {
            $nominal = $start + $i * $step;
            // Chrome stamps blocks from the wall clock, a millisecond or two either way.
            $time = $style === 'chrome' ? max($time, $nominal + $random->getInt(-1, 2)) : $nominal;
            $block = ['time' => $time, 'lacing' => 0, 'group' => false, 'track' => $track];
            if ($style === 'chrome') {
                $block['frames'] = [OpusFrames::constant($random, 3)];
                $block['samples'] = OpusFrames::samples(31, 3);
            } elseif ($style === 'known' && $i % 5 === 1) {
                // A laced block: two 10 ms SILK frames in one 20 ms block, by each lacing kind in turn.
                $lacing = [1, 2, 3][intdiv($i, 5) % 3];
                $size = $random->getInt(20, 300);
                $block['frames'] = [
                    OpusFrames::single($random, 8, false, $lacing === 2 ? $size : null),
                    OpusFrames::single($random, 8, false, $lacing === 2 ? $size : null),
                ];
                $block['samples'] = OpusFrames::samples(8, 2);
                $block['lacing'] = $lacing;
            } elseif ($style === 'known' && $i % 7 === 3) {
                // Two 10 ms CELT frames of different sizes with padding (code 3, variable bitrate).
                $block['frames'] = [OpusFrames::variable($random, 2, 30, 3)];
                $block['samples'] = OpusFrames::samples(30, 2);
            } else {
                $block['frames'] = [OpusFrames::single($random)];
                $block['samples'] = OpusFrames::samples(31, 1);
                $block['group'] = $style === 'known' && $i % 3 === 0;
            }
            $blocks[] = $block;
        }

        return $blocks;
    }

    /** The length the checker must find: the larger of block span, total audio and declared Duration. */
    public static function expectedNs(array $blocks, ?float $declaredMs): int
    {
        $first = PHP_INT_MAX;
        $end = 0;
        $samples = 0;
        foreach ($blocks as $block) {
            $first = min($first, $block['time'] * 1_000_000);
            $end = max($end, $block['time'] * 1_000_000 + intdiv($block['samples'] * 1_000_000_000 + 47_999, 48_000));
            $samples += $block['samples'];
        }
        $total = intdiv($samples * 1_000_000_000 + 47_999, 48_000);
        $declared = $declaredMs === null ? 0 : (int) ceil($declaredMs * 1_000_000);

        return max($blocks === [] ? 0 : $end - $first, $total, $declared);
    }

    /** Groups blocks into Clusters that each start a new second. */
    private static function clusters(array $blocks, string $style): array
    {
        $clusters = [];
        $current = null;
        foreach ($blocks as $block) {
            if ($current === null || $block['time'] - $current['time'] >= 1000) {
                if ($current !== null) {
                    $clusters[] = $current;
                }
                $current = ['time' => $block['time'], 'children' => [self::uint(0xE7, $block['time'])]];
            }
            $current['children'][] = self::block($block, $block['time'] - $current['time']);
        }
        if ($current !== null) {
            $clusters[] = $current;
        }

        return array_map(fn ($cluster) => self::el(self::CLUSTER, $cluster['children'], ['unknown' => $style !== 'known']), $clusters);
    }

    /** One SimpleBlock or BlockGroup (RFC 9559 section 10). The track number byte is critical. */
    public static function block(array $block, int $relative): array
    {
        $frames = $block['frames'];
        $lacing = $block['lacing'];
        $flags = ($block['group'] ? 0x00 : 0x80) | ($lacing << 1);
        $head = '';
        if ($lacing !== 0) {
            $head = chr(count($frames) - 1);
            $sizes = array_map('strlen', array_slice($frames, 0, -1));
            if ($lacing === 1) {
                foreach ($sizes as $size) {
                    $head .= str_repeat(chr(255), intdiv($size, 255)).chr($size % 255);
                }
            } elseif ($lacing === 3) {
                $head .= self::size($sizes[0]);
                for ($i = 1; $i < count($sizes); $i++) {
                    $head .= self::signedVint($sizes[$i] - $sizes[$i - 1]);
                }
            }
        }
        $payload = chr(0x80 | $block['track']).pack('n', $relative & 0xFFFF).chr($flags).$head.implode('', $frames);
        if (! $block['group']) {
            return self::el(self::SIMPLE_BLOCK, $payload, ['valueRanges' => [[0, 1]]]);
        }

        return self::el(self::BLOCK_GROUP, [
            self::el(0xA1, $payload, ['valueRanges' => [[0, 1]]]),
            self::uint(0x9B, intdiv($block['samples'], 48), ['strict' => false]),
        ]);
    }

    /** An element. $payload is bytes or a list of child elements. */
    public static function el(int $id, string|array $payload, array $o = []): array
    {
        return ['id' => $id, 'payload' => $payload, 'strict' => $o['strict'] ?? true, 'value' => $o['value'] ?? false,
            'valueRanges' => $o['valueRanges'] ?? [], 'sizeLength' => $o['sizeLength'] ?? null, 'unknown' => $o['unknown'] ?? false,
            'declaredSize' => $o['declaredSize'] ?? null];
    }

    public static function uint(int $id, int $value, array $o = []): array
    {
        $bytes = '';
        do {
            $bytes = chr($value & 0xFF).$bytes;
            $value >>= 8;
        } while ($value > 0);

        return self::el($id, $bytes, $o);
    }

    public static function float(int $id, float $value, int $length = 8, array $o = []): array
    {
        return self::el($id, $length === 4 ? pack('G', $value) : pack('E', $value), $o);
    }

    public static function text(int $id, string $value, array $o = []): array
    {
        return self::el($id, $value, $o);
    }

    /** @return array{string, StructureMap} */
    public static function nodes(array $nodes): array
    {
        $bytes = '';
        $map = new StructureMap;
        foreach ($nodes as $node) {
            // A string is written as it is, for hand-made bad elements.
            [$encoded, $inner] = is_string($node) ? [$node, new StructureMap] : self::node($node);
            $map->merge($inner, strlen($bytes));
            $bytes .= $encoded;
        }

        return [$bytes, $map];
    }

    /** @return array{string, StructureMap} */
    private static function node(array $node): array
    {
        $map = new StructureMap;
        $id = self::id($node['id']);
        [$payload, $inner] = is_array($node['payload']) ? self::nodes($node['payload']) : [$node['payload'], new StructureMap];
        if ($node['unknown']) {
            // An unknown size has every value bit set: 0x01 then seven 0xFF for eight bytes.
            $length = $node['sizeLength'] ?? 8;
            $marker = 1 << (8 - $length);
            $size = chr($marker | ($marker - 1)).str_repeat("\xFF", $length - 1);
        } else {
            $size = self::size($node['declaredSize'] ?? strlen($payload), $node['sizeLength']);
        }
        $head = strlen($id) + strlen($size);
        if ($node['strict']) {
            $map->critical(0, strlen($id));
        }
        $map->critical(strlen($id), $head);
        $map->size(strlen($id), $head, 'ebml');
        if ($node['value']) {
            $map->critical($head, $head + strlen($payload));
        }
        foreach ($node['valueRanges'] as [$from, $to]) {
            $map->critical($head + $from, $head + $to);
        }
        if (! $node['unknown']) {
            $map->extent(0, $head + strlen($payload));
        }
        $map->merge($inner, $head);

        return [$id.$size.$payload, $map];
    }

    /** An element ID's bytes; IDs are written with their length marker included. */
    public static function id(int $id): string
    {
        $bytes = '';
        do {
            $bytes = chr($id & 0xFF).$bytes;
            $id >>= 8;
        } while ($id > 0);

        return $bytes;
    }

    /** A data size in the shortest form, or in $length bytes. All-ones is reserved for "unknown". */
    public static function size(int $value, ?int $length = null): string
    {
        if ($length === null) {
            $length = 1;
            while ($value >= (1 << (7 * $length)) - 1) {
                $length++;
            }
        }
        $raw = (1 << (7 * $length)) | $value;
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes = chr($raw & 0xFF).$bytes;
            $raw >>= 8;
        }

        return $bytes;
    }

    /** A signed EBML lacing difference: stored as value + (2^(7n-1) - 1) in an n-byte VINT. */
    private static function signedVint(int $difference): string
    {
        for ($length = 1; ; $length++) {
            $bias = (1 << (7 * $length - 1)) - 1;
            $raw = $difference + $bias;
            if ($raw >= 0 && $raw < (1 << (7 * $length)) - 1) {
                return self::size($raw, $length);
            }
        }
    }

    /** A random but fixed set of files for loops over many shapes. */
    public static function random(int $seed): Randomizer
    {
        return new Randomizer(new Mt19937($seed));
    }
}
