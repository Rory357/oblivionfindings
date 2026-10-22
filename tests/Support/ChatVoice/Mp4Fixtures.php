<?php

namespace Tests\Support\ChatVoice;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Writes MP4 voice files from the ISO base media file format box structure, with one AAC sound track.
 * 'plain' files keep their sample tables in 'moov' (either before or after 'mdat'); 'fragmented' files
 * announce fragments in 'mvex' and describe them in 'moof' boxes, as Chrome's MediaRecorder writes.
 */
final class Mp4Fixtures
{
    private const TIMESCALE = 48_000;

    /** Boxes that must be present, so changing their type breaks the file. */
    private const REQUIRED = ['ftyp', 'moov', 'mvhd', 'trak', 'tkhd', 'mdia', 'mdhd', 'hdlr', 'minf', 'stbl', 'stsd',
        'mp4a', 'esds', 'stts', 'stsc', 'stsz', 'stco', 'mdat', 'mvex', 'trex', 'mfhd', 'tfhd'];

    /**
     * Options: seconds, seed, fragmented, movieFirst, tracks, handler, entry (sample entry type), objectType,
     * audioObjectType, mediaDuration, chunkShift (moves the first chunk offset), countShift (stsz count),
     * noMovie, boxes (callback over the top-level box list), trak (callback over the trak children).
     */
    public static function file(array $o = []): VoiceFile
    {
        $random = new Randomizer(new Mt19937($o['seed'] ?? 1));
        $fragmented = $o['fragmented'] ?? false;
        $count = (int) ceil(($o['seconds'] ?? 5.0) * self::TIMESCALE / 1024);
        $sizes = [];
        $durations = [];
        $data = '';
        for ($i = 0; $i < $count; $i++) {
            $sizes[] = $random->getInt(80, 320);
            $data .= $random->getBytes(end($sizes));
            // Chrome's fragments stamp now and then a sample one tick short.
            $durations[] = $fragmented && $i % 7 === 6 ? 1023 : 1024;
        }
        $ticks = array_sum($durations);
        $ftyp = self::box('ftyp', 'M4A '.pack('N', 0).'M4A isomiso2');

        if ($fragmented) {
            $movie = self::movie($o, 0, [], [], 0, true);
            $boxes = $o['noMovie'] ?? false ? [$ftyp] : [$ftyp, $movie];
            $decode = 0;
            $sequence = 1;
            foreach (array_chunk(array_keys($sizes), 47) as $chunk) {
                $chunkSizes = array_map(fn ($i) => $sizes[$i], $chunk);
                $chunkDurations = array_map(fn ($i) => $durations[$i], $chunk);
                $offset = array_sum(array_slice($sizes, 0, $chunk[0]));
                $payload = substr($data, $offset, array_sum($chunkSizes));
                // The data offset counts from the start of 'moof' to the first sample inside the following 'mdat'.
                $moof = self::fragment($sequence++, $decode, $chunkSizes, $chunkDurations, 0);
                $moofLength = strlen(self::nodes([$moof])[0]);
                $boxes[] = self::fragment($sequence - 1, $decode, $chunkSizes, $chunkDurations, $moofLength + 8);
                $boxes[] = self::box('mdat', $payload);
                $decode += array_sum($chunkDurations);
            }
        } else {
            $movieFirst = $o['movieFirst'] ?? true;
            $perChunk = 20;
            $chunks = array_chunk($sizes, $perChunk);
            $draft = self::movie($o, $ticks, $sizes, array_fill(0, count($chunks), 0), $perChunk, false);
            $movieLength = strlen(self::nodes([$draft])[0]);
            $dataStart = strlen(self::nodes([$ftyp])[0]) + ($movieFirst ? $movieLength : 0) + 8;
            $offsets = [];
            $position = $dataStart;
            foreach ($chunks as $chunk) {
                $offsets[] = $position;
                $position += array_sum($chunk);
            }
            $offsets[0] += $o['chunkShift'] ?? 0;
            $movie = self::movie($o, $ticks, $sizes, $offsets, $perChunk, false);
            $mdat = self::box('mdat', $data);
            $boxes = $o['noMovie'] ?? false ? [$ftyp, $mdat] : ($movieFirst ? [$ftyp, $movie, $mdat] : [$ftyp, $mdat, $movie]);
        }
        if (isset($o['boxes'])) {
            $boxes = ($o['boxes'])($boxes);
        }
        [$bytes, $map] = self::nodes($boxes);
        $declared = $o['mediaDuration'] ?? ($fragmented ? 0 : $ticks);
        $ns = intdiv(max($ticks, $declared) * 1_000_000_000 + self::TIMESCALE - 1, self::TIMESCALE);

        return new VoiceFile($o['name'] ?? ($fragmented ? 'mp4-fragmented' : 'mp4-plain'), $bytes, 'audio/mp4', intdiv($ns + 999_999, 1_000_000), $map->extents, $map->critical, false, $map->sizes);
    }

    private static function movie(array $o, int $ticks, array $sizes, array $offsets, int $perChunk, bool $fragmented): array
    {
        $tracks = [];
        for ($t = 1; $t <= ($o['tracks'] ?? 1); $t++) {
            $tracks[] = self::box('trak', self::track($o, $t, $ticks, $sizes, $offsets, $perChunk, $fragmented));
        }
        $children = [self::box('mvhd', self::full(0).pack('N3', 0, 0, 1000).pack('N', intdiv($ticks, 48))
            .pack('N', 0x00010000).pack('n', 0x0100).str_repeat("\0", 10).self::matrix().str_repeat("\0", 24).pack('N', 2)), ...$tracks];
        if ($fragmented) {
            $children[] = self::box('mvex', [self::box('trex', self::full(0).pack('N5', 1, 1, 1024, 0, 0), ['ranges' => [[4, 8]]])]);
        }

        return self::box('moov', $children);
    }

    private static function track(array $o, int $id, int $ticks, array $sizes, array $offsets, int $perChunk, bool $fragmented): array
    {
        $sampleEntry = self::box($o['entry'] ?? 'mp4a', [
            // 6 reserved bytes, data reference 1, sound description version 0, then channels, size, rate.
            str_repeat("\0", 6).pack('n', 1).str_repeat("\0", 8).pack('n', 1).pack('n', 16).pack('n2', 0, 0).pack('N', self::TIMESCALE << 16),
            // Byte 17 is the object type: 4 (version, flags) + 5 (ES tag, length) + 3 (ES ID, flags) + 5 (config tag, length).
            self::box('esds', self::full(0).self::elementaryStream($o), ['ranges' => [[17, 18]]]),
        ]);
        $chunkCount = count($offsets);
        $last = $chunkCount === 0 ? 0 : count($sizes) - ($chunkCount - 1) * $perChunk;
        $toChunk = $chunkCount === 0 ? [] : ($last === $perChunk || $chunkCount === 1 ? [[1, $last === $perChunk ? $perChunk : $last]] : [[1, $perChunk], [$chunkCount, $last]]);
        $sampleCount = count($sizes);
        $tables = [
            self::box('stsd', [self::full(0).pack('N', 1), $sampleEntry], ['ranges' => [[4, 8]]]),
            // countShift makes the time table count a different number of samples from the size table.
            self::box('stts', self::full(0).pack('N', count($sizes) ? 1 : 0).(count($sizes) ? pack('N2', count($sizes) + ($o['countShift'] ?? 0), 1024) : ''), ['ranges' => [[4, 8]]]),
            self::box('stsc', self::full(0).pack('N', count($toChunk)).implode('', array_map(fn ($e) => pack('N3', $e[0], $e[1], 1), $toChunk)), ['ranges' => [[4, 8]]]),
            self::box('stsz', self::full(0).pack('N2', 0, $sampleCount).($sizes ? pack('N*', ...$sizes) : ''), ['ranges' => [[8, 12]]]),
            self::box('stco', self::full(0).pack('N', $chunkCount).($offsets ? pack('N*', ...$offsets) : ''), ['ranges' => [[4, 8]]]),
        ];
        $children = [
            self::box('tkhd', self::full(0, 3).pack('N5', 0, 0, $id, 0, intdiv($ticks, 48)).str_repeat("\0", 8).pack('n4', 0, 0, 0x0100, 0).self::matrix().pack('N2', 0, 0), $fragmented ? ['ranges' => [[12, 16]]] : []),
            self::box('mdia', [
                self::box('mdhd', self::full(0).pack('N4', 0, 0, self::TIMESCALE, $o['mediaDuration'] ?? ($fragmented ? 0 : $ticks)).pack('n2', 0x55C4, 0)),
                self::box('hdlr', self::full(0).pack('N', 0).($o['handler'] ?? 'soun').str_repeat("\0", 12)."SoundHandler\0", ['ranges' => [[8, 12]]]),
                self::box('minf', [
                    self::box('smhd', self::full(0).pack('n2', 0, 0)),
                    self::box('dinf', [self::box('dref', [self::full(0).pack('N', 1), self::box('url ', self::full(0, 1))])]),
                    self::box('stbl', $tables),
                ]),
            ]),
        ];
        if (isset($o['trak'])) {
            $children = ($o['trak'])($children);
        }

        return $children;
    }

    /** ES descriptor → decoder config (object type 0x40, audio stream) → AAC LC, 48 kHz, mono. */
    private static function elementaryStream(array $o): string
    {
        $objectType = $o['audioObjectType'] ?? 2;
        $config = pack('n', ($objectType << 11) | (3 << 7) | (1 << 3));
        $decoderSpecific = self::descriptor(0x05, $config);
        $decoderConfig = self::descriptor(0x04, chr($o['objectType'] ?? 0x40).chr(0x15).str_repeat("\0", 3).pack('N2', 96_000, 96_000).$decoderSpecific);

        return self::descriptor(0x03, pack('n', 1).chr(0).$decoderConfig.self::descriptor(0x06, chr(0x02)));
    }

    private static function descriptor(int $tag, string $content): string
    {
        $length = strlen($content);

        // Four-byte expandable length, as many muxers write it.
        return chr($tag).chr(0x80 | (($length >> 21) & 0x7F)).chr(0x80 | (($length >> 14) & 0x7F)).chr(0x80 | (($length >> 7) & 0x7F)).chr($length & 0x7F).$content;
    }

    /** One movie fragment: default-base-is-moof, a decode time and one run with per-sample durations and sizes. */
    private static function fragment(int $sequence, int $decode, array $sizes, array $durations, int $dataOffset): array
    {
        $samples = '';
        foreach ($sizes as $i => $size) {
            $samples .= pack('N2', $durations[$i], $size);
        }

        return self::box('moof', [
            self::box('mfhd', self::full(0).pack('N', $sequence)),
            self::box('traf', [
                self::box('tfhd', self::full(0, 0x020000).pack('N', 1), ['ranges' => [[4, 8]]]),
                self::box('tfdt', self::full(1).pack('J', $decode)),
                self::box('trun', self::full(0, 0x000301).pack('N', count($sizes)).pack('N', $dataOffset).$samples, ['ranges' => [[4, 8]]]),
            ]),
        ]);
    }

    private static function full(int $version, int $flags = 0): string
    {
        return pack('N', ($version << 24) | $flags);
    }

    private static function matrix(): string
    {
        return pack('N9', 0x00010000, 0, 0, 0, 0x00010000, 0, 0, 0, 0x40000000);
    }

    /** A box: content is bytes or a list of bytes and child boxes. 'ranges' marks critical bytes in the content. */
    public static function box(string $type, string|array $content, array $o = []): array
    {
        return ['type' => $type, 'content' => is_array($content) ? $content : [$content], 'ranges' => $o['ranges'] ?? [], 'size' => $o['size'] ?? null];
    }

    /** @return array{string, StructureMap} */
    public static function nodes(array $boxes): array
    {
        $bytes = '';
        $map = new StructureMap;
        foreach ($boxes as $box) {
            if (is_string($box)) {
                $bytes .= $box;

                continue;
            }
            [$content, $inner] = self::nodes($box['content']);
            $length = 8 + strlen($content);
            $start = strlen($bytes);
            $bytes .= pack('N', $box['size'] ?? $length).$box['type'].$content;
            $map->critical($start, $start + 4);
            $map->size($start, $start + 4, 'box');
            if (in_array($box['type'], self::REQUIRED, true)) {
                $map->critical($start + 4, $start + 8);
            }
            foreach ($box['ranges'] as [$from, $to]) {
                $map->critical($start + 8 + $from, $start + 8 + $to);
            }
            $map->extent($start, $start + $length);
            $map->merge($inner, $start + 8);
        }

        return [$bytes, $map];
    }

    public static function random(int $seed): Randomizer
    {
        return new Randomizer(new Mt19937($seed));
    }
}
