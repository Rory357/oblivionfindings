<?php

namespace App\Services\Chat\Voice;

/**
 * Reads MP4 voice recordings from the ISO base media file format box structure: one sound track whose
 * single sample entry is AAC ('mp4a' with an MPEG-4 audio decoder configuration).
 *
 * Both layouts browsers write are read: sample tables in 'moov', and fragmented files where 'moov'
 * announces fragments ('mvex') that 'moof' boxes then describe. Every sample's bytes must lie inside
 * an 'mdat' box, so a file whose audio data is missing or cut short is refused.
 */
final class Mp4VoiceParser
{
    /** AAC LC, HE-AAC (SBR) and HE-AAC v2 (PS): the audio object types browsers record. */
    private const AAC_OBJECT_TYPES = [2, 5, 29];

    private bool $movie = false;

    private int $movieTimescale = 0;

    private int $tracks = 0;

    private ?int $trackId = null;

    private int $timescale = 0;

    private int $mediaDuration = 0;

    private int $fragmentDuration = 0;

    private bool $fragmented = false;

    private ?array $trex = null;

    /** @var array<string, array{int, int}> sample tables: name => [table start, entry count] */
    private array $tables = [];

    private int $uniformSize = 0;

    private bool $wideOffsets = false;

    /**
     * Where each 'mdat' box's contents start and end, in file order, packed as two 64-bit numbers
     * per box (16 bytes), so even a million boxes take 16 MiB.
     */
    private string $mdatIndex = '';

    /** Fragment sample ranges waiting to be checked once every 'mdat' is known, packed the same way. */
    private string $pendingRanges = '';

    private int $tableSamples = 0;

    private int $tableTicks = 0;

    private int $fragmentSamples = 0;

    private int $fragmentTicks = 0;

    private ?int $firstDecode = null;

    private int $lastDecodeEnd = 0;

    private int $runningDecode = 0;

    private int $sequence = 0;

    public function __construct(private readonly VoiceBytes $b) {}

    public function parse(): VoiceFacts
    {
        $length = $this->b->length;
        $first = true;
        for ($p = 0; $p < $length; $p = $end) {
            [$type, $start, $end] = $this->box($p, $length, true);
            if ($first !== ($type === 'ftyp')) {
                throw new VoiceFormatException('mp4_ftyp');
            }
            $first = false;
            match ($type) {
                'ftyp' => $this->b->need($start, 8, $end),
                'moov' => $this->movie($start, $end),
                'mdat' => $this->mdatIndex .= pack('J2', $start, $end),
                'moof' => $this->fragment($p, $start, $end),
                default => null, // free space, metadata and other boxes carry no audio
            };
        }
        if (! $this->movie) {
            throw new VoiceFormatException('mp4_no_movie');
        }
        $this->sampleTables();
        if ($this->tableSamples + $this->fragmentSamples === 0) {
            throw new VoiceFormatException('mp4_no_audio');
        }
        for ($offset = 0, $length = strlen($this->pendingRanges); $offset < $length; $offset += 16) {
            $this->b->count();
            [, $from, $to] = unpack('J2', $this->pendingRanges, $offset);
            if (! $this->insideMdat($from, $to)) {
                throw new VoiceFormatException('mp4_sample_data');
            }
        }
        $span = $this->firstDecode === null ? 0 : $this->lastDecodeEnd - $this->firstDecode;
        $ticks = max($this->mediaDuration, self::add($this->tableTicks, $this->fragmentTicks), $span);

        return new VoiceFacts('audio/mp4', max(
            VoiceFacts::nanoseconds($ticks, $this->timescale),
            VoiceFacts::nanoseconds($this->fragmentDuration, max(1, $this->movieTimescale)),
        ));
    }

    /** One box header: [type, content start, end]. 'size 0' (to the end of the file) is allowed only at the top level. */
    private function box(int $p, int $end, bool $top = false): array
    {
        $this->b->count();
        $size = $this->b->u32be($p, $end);
        $type = $this->b->bytes($p + 4, 4, $end);
        $header = 8;
        if ($size === 1) {
            $size = $this->b->uintBe($p + 8, 8, $end);
            $header = 16;
        } elseif ($size === 0) {
            if (! $top) {
                throw new VoiceFormatException('mp4_box_size');
            }
            $size = $end - $p;
        }
        if ($type === 'uuid') {
            $header += 16;
        }
        if ($size < $header || $size > $end - $p) {
            throw new VoiceFormatException('mp4_box_past_end');
        }

        return [$type, $p + $header, $p + $size];
    }

    /**
     * Whether [$from, $to) lies inside one 'mdat'. Top-level boxes follow each other without overlapping,
     * so the starts are in rising order and the candidate is found by halving the list.
     */
    private function insideMdat(int $from, int $to): bool
    {
        $low = 0;
        $high = intdiv(strlen($this->mdatIndex), 16) - 1;
        $found = -1;
        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            if (unpack('J', $this->mdatIndex, 16 * $middle)[1] <= $from) {
                $found = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $found >= 0 && $to <= unpack('J', $this->mdatIndex, 16 * $found + 8)[1];
    }

    /**
     * Child boxes one at a time as [type, content start, end], so a container holding very many
     * children never builds a list of them.
     *
     * @return \Generator<int, array{string, int, int}>
     */
    private function children(int $start, int $end): \Generator
    {
        for ($p = $start; $p < $end; $p = $boxEnd) {
            [$type, $boxStart, $boxEnd] = $this->box($p, $end);
            yield [$type, $boxStart, $boxEnd];
        }
    }

    /** Returns [version, flags] of a full box and checks the content after them is present. */
    private function full(int $start, int $end, int $minimum = 0): array
    {
        $this->b->need($start, 4 + $minimum, $end);
        $word = $this->b->u32be($start, $end);

        return [$word >> 24, $word & 0xFFFFFF];
    }

    /** Requires $length bytes at $offset that end exactly where the box ends. */
    private function fills(int $offset, int $length, int $end): void
    {
        $this->b->need($offset, $length, $end);
        if ($offset + $length !== $end) {
            throw new VoiceFormatException('mp4_box_contents');
        }
    }

    private function once(array &$seen, string $type): void
    {
        if (isset($seen[$type])) {
            throw new VoiceFormatException('mp4_duplicate_'.$type);
        }
        $seen[$type] = true;
    }

    private function movie(int $start, int $end): void
    {
        if ($this->movie) {
            throw new VoiceFormatException('mp4_duplicate_moov');
        }
        $this->movie = true;
        $seen = [];
        foreach ($this->children($start, $end) as [$type, $s, $e]) {
            match ($type) {
                'mvhd' => $this->movieHeader($s, $e, $seen),
                'trak' => $this->track($s, $e),
                'mvex' => $this->extends($s, $e, $seen),
                default => null,
            };
        }
        if (! isset($seen['mvhd']) || $this->tracks !== 1) {
            throw new VoiceFormatException($this->tracks > 1 ? 'mp4_two_tracks' : 'mp4_no_track');
        }
        if ($this->fragmented && ($this->trex === null || $this->trex['track'] !== $this->trackId)) {
            throw new VoiceFormatException('mp4_fragment_defaults');
        }
    }

    private function movieHeader(int $s, int $e, array &$seen): void
    {
        $this->once($seen, 'mvhd');
        [$version] = $this->full($s, $e, 16);
        $this->movieTimescale = $this->b->u32be($s + ($version === 1 ? 20 : 12), $e);
        if ($version > 1 || $this->movieTimescale === 0) {
            throw new VoiceFormatException('mp4_movie_header');
        }
    }

    private function track(int $start, int $end): void
    {
        if (++$this->tracks > 1) {
            throw new VoiceFormatException('mp4_two_tracks');
        }
        $seen = [];
        foreach ($this->children($start, $end) as [$type, $s, $e]) {
            if ($type === 'tkhd') {
                $this->once($seen, 'tkhd');
                [$version] = $this->full($s, $e, 16);
                $this->trackId = $this->b->u32be($s + ($version === 1 ? 20 : 12), $e);
                if ($version > 1 || $this->trackId === 0) {
                    throw new VoiceFormatException('mp4_track_header');
                }
            } elseif ($type === 'mdia') {
                $this->once($seen, 'mdia');
                $this->media($s, $e);
            }
        }
        if (! isset($seen['tkhd'], $seen['mdia'])) {
            throw new VoiceFormatException('mp4_track');
        }
    }

    private function media(int $start, int $end): void
    {
        $seen = [];
        foreach ($this->children($start, $end) as [$type, $s, $e]) {
            if ($type === 'mdhd') {
                $this->once($seen, 'mdhd');
                [$version] = $this->full($s, $e, 20);
                if ($version > 1) {
                    throw new VoiceFormatException('mp4_media_header');
                }
                $this->timescale = $this->b->u32be($s + ($version === 1 ? 20 : 12), $e);
                $raw = $this->b->bytes($s + ($version === 1 ? 24 : 16), $version === 1 ? 8 : 4, $e);
                // All ones means the length is not known.
                $this->mediaDuration = $raw === str_repeat("\xFF", strlen($raw)) ? 0 : $this->b->uintBe($s + ($version === 1 ? 24 : 16), strlen($raw), $e);
                if ($this->timescale === 0) {
                    throw new VoiceFormatException('mp4_media_header');
                }
            } elseif ($type === 'hdlr') {
                $this->once($seen, 'hdlr');
                $this->full($s, $e, 8);
                if ($this->b->bytes($s + 8, 4, $e) !== 'soun') {
                    throw new VoiceFormatException('mp4_not_audio');
                }
            } elseif ($type === 'minf') {
                $this->once($seen, 'minf');
                $this->mediaInformation($s, $e, $seen);
            }
        }
        if (! isset($seen['mdhd'], $seen['hdlr'], $seen['minf'], $seen['stbl'])) {
            throw new VoiceFormatException('mp4_media');
        }
    }

    /**
     * 'minf' of a sound track holds a sound media header, data information and the sample table, all
     * required by the box structure. The first two carry nothing the checker needs, but their sizes
     * must nest exactly, so a damaged box tree is not mistaken for a good one.
     */
    private function mediaInformation(int $start, int $end, array &$seen): void
    {
        foreach ($this->children($start, $end) as [$type, $s, $e]) {
            if ($type === 'stbl') {
                $this->once($seen, 'stbl');
                $this->sampleTable($s, $e);
            } elseif ($type === 'smhd') {
                $this->once($seen, 'smhd');
                $this->full($s, $e, 4);
                $this->fills($s + 4, 4, $e);
            } elseif ($type === 'dinf') {
                $this->once($seen, 'dinf');
                $references = 0;
                foreach ($this->children($s, $e) as [$childType, $cs, $ce]) {
                    if ($childType === 'dref') {
                        $references++;
                        $this->full($cs, $ce, 4);
                        $entries = $this->b->u32be($cs + 4, $ce);
                        if ($entries < 1 || iterator_count($this->children($cs + 8, $ce)) !== $entries) {
                            throw new VoiceFormatException('mp4_data_reference');
                        }
                    }
                }
                if ($references !== 1) {
                    throw new VoiceFormatException('mp4_data_reference');
                }
            }
        }
        if (! isset($seen['smhd'], $seen['dinf'])) {
            throw new VoiceFormatException('mp4_media');
        }
    }

    private function sampleTable(int $start, int $end): void
    {
        $seen = [];
        foreach ($this->children($start, $end) as [$type, $s, $e]) {
            switch ($type) {
                case 'stsd':
                    $this->once($seen, 'stsd');
                    $this->sampleDescription($s, $e);
                    break;
                case 'stts':
                case 'stsc':
                    $this->once($seen, $type);
                    $this->full($s, $e, 4);
                    $count = $this->b->u32be($s + 4, $e);
                    $this->fills($s + 8, $count * ($type === 'stts' ? 8 : 12), $e);
                    $this->tables[$type] = [$s + 8, $count];
                    break;
                case 'stsz':
                    $this->once($seen, 'stsz');
                    $this->full($s, $e, 8);
                    $this->uniformSize = $this->b->u32be($s + 4, $e);
                    $count = $this->b->u32be($s + 8, $e);
                    $this->fills($s + 12, $this->uniformSize === 0 ? $count * 4 : 0, $e);
                    $this->tables['stsz'] = [$s + 12, $count];
                    break;
                case 'stco':
                case 'co64':
                    $this->once($seen, 'chunk offsets');
                    $this->full($s, $e, 4);
                    $count = $this->b->u32be($s + 4, $e);
                    $this->wideOffsets = $type === 'co64';
                    $this->fills($s + 8, $count * ($this->wideOffsets ? 8 : 4), $e);
                    $this->tables['stco'] = [$s + 8, $count];
                    break;
                case 'stz2':
                    throw new VoiceFormatException('mp4_compact_sizes');
            }
        }
        if (! isset($seen['stsd'], $seen['stts'], $seen['stsc'], $seen['stsz'], $seen['chunk offsets'])) {
            throw new VoiceFormatException('mp4_sample_table');
        }
    }

    private function sampleDescription(int $s, int $e): void
    {
        $this->full($s, $e, 4);
        if ($this->b->u32be($s + 4, $e) !== 1) {
            throw new VoiceFormatException('mp4_sample_entries');
        }
        [$type, $start, $end] = $this->box($s + 8, $e);
        if ($type !== 'mp4a') {
            throw new VoiceFormatException('mp4_codec');
        }
        if ($end !== $e) {
            throw new VoiceFormatException('mp4_sample_entries');
        }
        // Audio sample entry: 6 reserved bytes, data reference index, then the sound description,
        // which is longer in QuickTime's versions 1 and 2.
        $this->b->need($start, 28, $end);
        $version = $this->b->u16be($start + 8, $end);
        $channels = $this->b->u16be($start + 16, $end);
        $children = $start + 28 + match ($version) {
            0 => 0,
            1 => 16,
            2 => 36,
            default => throw new VoiceFormatException('mp4_sound_description'),
        };
        if ($version < 2 && $channels === 0) {
            throw new VoiceFormatException('mp4_sound_description');
        }
        $found = 0;
        foreach ($this->children($children, $end) as [$childType, $cs, $ce]) {
            if ($childType === 'esds') {
                $found++;
                $this->elementaryStream($cs, $ce);
            } elseif ($childType === 'wave') {
                foreach ($this->children($cs, $ce) as [$waveType, $ws, $we]) {
                    if ($waveType === 'esds') {
                        $found++;
                        $this->elementaryStream($ws, $we);
                    }
                }
            } elseif ($childType === 'sinf') {
                throw new VoiceFormatException('mp4_protected');
            }
        }
        if ($found !== 1) {
            throw new VoiceFormatException('mp4_codec');
        }
    }

    /** The 'esds' box: an ES descriptor holding the decoder configuration (MPEG-4 descriptor syntax). */
    private function elementaryStream(int $s, int $e): void
    {
        [$version] = $this->full($s, $e, 2);
        [$tag, $ds, $de] = $this->descriptor($s + 4, $e);
        if ($version !== 0 || $tag !== 0x03) {
            throw new VoiceFormatException('mp4_codec');
        }
        $flags = $this->b->byte($ds + 2, $de);
        $q = $ds + 3;
        if ($flags & 0x80) {
            $q += 2;
        }
        if ($flags & 0x40) {
            $q += 1 + $this->b->byte($q, $de);
        }
        if ($flags & 0x20) {
            $q += 2;
        }
        $this->b->need($q, 0, $de);
        $configs = 0;
        while ($q < $de) {
            [$tag, $cs, $ce] = $this->descriptor($q, $de);
            if ($tag === 0x04) {
                $configs++;
                $this->decoderConfig($cs, $ce);
            }
            $q = $ce;
        }
        if ($configs !== 1) {
            throw new VoiceFormatException('mp4_codec');
        }
    }

    /** [tag, content start, content end]; the length uses 7 bits per byte over at most four bytes. */
    private function descriptor(int $p, int $end): array
    {
        $this->b->count();
        $tag = $this->b->byte($p, $end);
        $size = 0;
        $q = $p + 1;
        for ($i = 0; ; $i++) {
            if ($i === 4) {
                throw new VoiceFormatException('mp4_descriptor');
            }
            $byte = $this->b->byte($q++, $end);
            $size = ($size << 7) | ($byte & 0x7F);
            if (($byte & 0x80) === 0) {
                break;
            }
        }
        if ($size > $end - $q) {
            throw new VoiceFormatException('mp4_descriptor');
        }

        return [$tag, $q, $q + $size];
    }

    /** Decoder configuration: object type 0x40 (MPEG-4 audio), stream type 5 (audio), then the audio specific config. */
    private function decoderConfig(int $s, int $e): void
    {
        $this->b->need($s, 13, $e);
        if ($this->b->byte($s, $e) !== 0x40 || ($this->b->byte($s + 1, $e) >> 2) !== 0x05) {
            throw new VoiceFormatException('mp4_codec');
        }
        $found = 0;
        for ($q = $s + 13; $q < $e; $q = $ce) {
            [$tag, $cs, $ce] = $this->descriptor($q, $e);
            if ($tag === 0x05) {
                $found++;
                $this->audioSpecificConfig($cs, $ce);
            }
        }
        if ($found !== 1) {
            throw new VoiceFormatException('mp4_codec');
        }
    }

    /** The start of the MPEG-4 audio specific config: object type, sampling frequency index, channel configuration. */
    private function audioSpecificConfig(int $s, int $e): void
    {
        $this->b->need($s, 2, $e);
        $bits = $this->b->u16be($s, $e);
        $objectType = $bits >> 11;
        $frequency = ($bits >> 7) & 0x0F;
        if (! in_array($objectType, self::AAC_OBJECT_TYPES, true)) {
            throw new VoiceFormatException('mp4_codec');
        }
        if ($frequency === 0x0F) {
            // An explicit 24-bit rate follows; the channel configuration comes after it.
            // Of these 40 bits: object type 39–35, index 34–31, rate 30–7, channels 6–3.
            $wide = $this->b->uintBe($s, 5, $e);
            $rate = ($wide >> 7) & 0xFFFFFF;
            $channels = ($wide >> 3) & 0x0F;
            if ($rate === 0) {
                throw new VoiceFormatException('mp4_codec');
            }
        } else {
            $channels = ($bits >> 3) & 0x0F;
            if ($frequency > 12) {
                throw new VoiceFormatException('mp4_codec');
            }
        }
        if ($channels < 1 || $channels > 7) {
            throw new VoiceFormatException('mp4_codec');
        }
    }

    /** Sample tables in 'moov': counts must agree and every chunk must sit inside 'mdat'. */
    private function sampleTables(): void
    {
        [$sttsStart, $sttsCount] = $this->tables['stts'];
        [$stscStart, $stscCount] = $this->tables['stsc'];
        [$stszStart, $sampleCount] = $this->tables['stsz'];
        [$chunkStart, $chunkCount] = $this->tables['stco'];
        $data = $this->b->data;
        for ($i = 0; $i < $sttsCount; $i++) {
            $this->b->count();
            ['count' => $count, 'delta' => $delta] = unpack('Ncount/Ndelta', $data, $sttsStart + 8 * $i);
            $this->tableSamples += $count;
            $this->tableTicks = $delta > 0 && $count > intdiv(PHP_INT_MAX - $this->tableTicks, $delta)
                ? PHP_INT_MAX : self::add($this->tableTicks, $count * $delta);
        }
        if ($this->tableSamples !== $sampleCount) {
            throw new VoiceFormatException('mp4_sample_count');
        }
        if ($sampleCount === 0) {
            if ($stscCount !== 0 || $chunkCount !== 0) {
                throw new VoiceFormatException('mp4_sample_count');
            }

            return;
        }
        if ($stscCount === 0 || $chunkCount === 0) {
            throw new VoiceFormatException('mp4_sample_count');
        }
        $sample = 0;
        $previousFirst = 0;
        for ($i = 0; $i < $stscCount; $i++) {
            $this->b->count();
            ['first' => $first, 'samples' => $perChunk, 'description' => $description] = unpack('Nfirst/Nsamples/Ndescription', $data, $stscStart + 12 * $i);
            $next = $i + 1 < $stscCount ? unpack('N', $data, $stscStart + 12 * ($i + 1))[1] : $chunkCount + 1;
            if (($i === 0 && $first !== 1) || $first <= $previousFirst || $next <= $first || $next > $chunkCount + 1 || $perChunk < 1 || $description !== 1) {
                throw new VoiceFormatException('mp4_sample_to_chunk');
            }
            $previousFirst = $first;
            for ($chunk = $first; $chunk < $next; $chunk++) {
                $this->b->count();
                if ($sample + $perChunk > $sampleCount) {
                    throw new VoiceFormatException('mp4_sample_count');
                }
                $offset = $this->wideOffsets
                    ? $this->b->uintBe($chunkStart + 8 * ($chunk - 1), 8, $this->b->length)
                    : unpack('N', $data, $chunkStart + 4 * ($chunk - 1))[1];
                $bytes = $this->sizes($stszStart, $sample, $perChunk);
                // Every 'mdat' is known by now (the tables are read after the whole file), so check at once.
                if (! $this->insideMdat($offset, $offset + $bytes)) {
                    throw new VoiceFormatException('mp4_sample_data');
                }
                $sample += $perChunk;
            }
        }
        if ($sample !== $sampleCount) {
            throw new VoiceFormatException('mp4_sample_count');
        }
    }

    /** Total bytes of $count samples from $first, from the uniform size or the size table; no sample may be empty. */
    private function sizes(int $tableStart, int $first, int $count): int
    {
        if ($this->uniformSize > 0) {
            return $this->uniformSize * $count;
        }
        $total = 0;
        for ($done = 0; $done < $count; $done += $batch) {
            $batch = min(4096, $count - $done);
            $this->b->count($batch);
            $sizes = unpack('N'.$batch, $this->b->data, $tableStart + 4 * ($first + $done));
            if (min($sizes) < 1) {
                throw new VoiceFormatException('mp4_sample_size');
            }
            $total += array_sum($sizes);
        }

        return $total;
    }

    private function extends(int $start, int $end, array &$seen): void
    {
        $this->once($seen, 'mvex');
        $this->fragmented = true;
        foreach ($this->children($start, $end) as [$type, $s, $e]) {
            if ($type === 'trex') {
                $this->once($seen, 'trex');
                $this->full($s, $e, 20);
                ['track' => $track, 'description' => $description, 'duration' => $duration, 'size' => $size] = unpack('Ntrack/Ndescription/Nduration/Nsize', $this->b->data, $s + 4);
                // 'stsd' holds exactly one sample entry, so fragments can only describe entry 1.
                if ($description !== 1) {
                    throw new VoiceFormatException('mp4_sample_entries');
                }
                $this->trex = ['track' => $track, 'duration' => $duration, 'size' => $size];
            } elseif ($type === 'mehd') {
                $this->once($seen, 'mehd');
                [$version] = $this->full($s, $e, 4);
                $this->fragmentDuration = $version === 1 ? $this->b->uintBe($s + 4, 8, $e) : $this->b->u32be($s + 4, $e);
            }
        }
    }

    /** One movie fragment: its sequence number must rise, and each track fragment describes our one track. */
    private function fragment(int $moofStart, int $start, int $end): void
    {
        if (! $this->movie || ! $this->fragmented) {
            throw new VoiceFormatException('mp4_fragment_without_movie');
        }
        $headers = 0;
        $base = $moofStart;
        foreach ($this->children($start, $end) as [$type, $s, $e]) {
            if ($type === 'mfhd') {
                $headers++;
                $this->full($s, $e, 4);
                $sequence = $this->b->u32be($s + 4, $e);
                if ($sequence <= $this->sequence) {
                    throw new VoiceFormatException('mp4_fragment_order');
                }
                $this->sequence = $sequence;
            } elseif ($type === 'traf') {
                $base = $this->trackFragment($moofStart, $base, $s, $e);
            }
        }
        if ($headers !== 1) {
            throw new VoiceFormatException('mp4_fragment_header');
        }
    }

    /** Returns where this track fragment's data ends, which is where the next one's starts by default. */
    private function trackFragment(int $moofStart, int $base, int $start, int $end): int
    {
        $header = null;
        $decode = null;
        $position = $base;
        $ticks = 0;
        foreach ($this->children($start, $end) as [$type, $bs, $be]) {
            if ($header === null) {
                // 'tfhd' comes first: it names the track and the defaults the runs after it use.
                if ($type !== 'tfhd') {
                    throw new VoiceFormatException('mp4_fragment_header');
                }
                $header = $this->fragmentHeader($moofStart, $base, $bs, $be);
                $position = $header['base'];

                continue;
            }
            if ($type === 'tfhd') {
                throw new VoiceFormatException('mp4_duplicate_tfhd');
            }
            if ($type === 'senc') {
                throw new VoiceFormatException('mp4_protected');
            }
            if ($type === 'tfdt') {
                if ($decode !== null) {
                    throw new VoiceFormatException('mp4_duplicate_tfdt');
                }
                [$version] = $this->full($bs, $be, 4);
                $decode = $version === 1 ? $this->b->uintBe($bs + 4, 8, $be) : $this->b->u32be($bs + 4, $be);
            } elseif ($type === 'trun') {
                [$position, $runTicks] = $this->run($bs, $be, $header['base'], $position, $header['duration'], $header['size']);
                $ticks = self::add($ticks, $runTicks);
            }
        }
        if ($header === null) {
            throw new VoiceFormatException('mp4_fragment_header');
        }
        $decode ??= $this->runningDecode;
        $decodeEnd = self::add($decode, $ticks);
        $this->firstDecode = min($this->firstDecode ?? $decode, $decode);
        $this->lastDecodeEnd = max($this->lastDecodeEnd, $decodeEnd);
        $this->runningDecode = $decodeEnd;
        $this->fragmentTicks = self::add($this->fragmentTicks, $ticks);

        return $position;
    }

    /** The 'tfhd' box: our track, then optional base offset, sample entry and sample defaults. */
    private function fragmentHeader(int $moofStart, int $base, int $s, int $e): array
    {
        [, $flags] = $this->full($s, $e, 4);
        if ($this->b->u32be($s + 4, $e) !== $this->trackId) {
            throw new VoiceFormatException('mp4_fragment_track');
        }
        $q = $s + 8;
        if ($flags & 0x000001) {
            $base = $this->b->uintBe($q, 8, $e);
            $q += 8;
        } elseif ($flags & 0x020000) {
            $base = $moofStart;
        }
        if ($flags & 0x000002) {
            if ($this->b->u32be($q, $e) !== 1) {
                throw new VoiceFormatException('mp4_sample_entries');
            }
            $q += 4;
        }
        $duration = $this->trex['duration'];
        $size = $this->trex['size'];
        if ($flags & 0x000008) {
            $duration = $this->b->u32be($q, $e);
            $q += 4;
        }
        if ($flags & 0x000010) {
            $size = $this->b->u32be($q, $e);
            $q += 4;
        }
        if ($flags & 0x000020) {
            $q += 4;
        }
        $this->fills($q, 0, $e);

        return ['base' => $base, 'duration' => $duration, 'size' => $size];
    }

    /** Adds two non-negative tick counts, holding at PHP_INT_MAX instead of overflowing. */
    private static function add(int $a, int $b): int
    {
        return $a > PHP_INT_MAX - $b ? PHP_INT_MAX : $a + $b;
    }

    /** One track run: returns [end of its data, its total duration in media ticks]. */
    private function run(int $s, int $e, int $base, int $position, int $defaultDuration, int $defaultSize): array
    {
        [, $flags] = $this->full($s, $e, 4);
        $count = $this->b->u32be($s + 4, $e);
        $q = $s + 8;
        if ($flags & 0x000001) {
            $offset = $this->b->u32be($q, $e);
            $position = $base + ($offset >= 0x80000000 ? $offset - 0x100000000 : $offset);
            $q += 4;
        }
        if ($flags & 0x000004) {
            $q += 4;
        }
        $fields = [];
        foreach ([0x100 => 'duration', 0x200 => 'size', 0x400 => 'flags', 0x800 => 'offset'] as $bit => $name) {
            if ($flags & $bit) {
                $fields[] = $name;
            }
        }
        $width = count($fields);
        $this->fills($q, $count * 4 * $width, $e);
        $this->b->count($count);
        $durationColumn = array_search('duration', $fields, true);
        $sizeColumn = array_search('size', $fields, true);
        if (($durationColumn === false && $defaultDuration === 0) || ($sizeColumn === false && $defaultSize === 0)) {
            throw new VoiceFormatException('mp4_sample_size');
        }
        $ticks = $durationColumn === false ? $count * $defaultDuration : 0;
        $bytes = $sizeColumn === false ? $count * $defaultSize : 0;
        if ($width > 0 && ($durationColumn !== false || $sizeColumn !== false)) {
            for ($done = 0; $done < $count; $done += $batch) {
                $batch = min(4096, $count - $done);
                $values = array_values(unpack('N'.($batch * $width), $this->b->data, $q + 4 * $width * $done));
                for ($i = 0; $i < $batch; $i++) {
                    if ($durationColumn !== false) {
                        $ticks += $values[$i * $width + $durationColumn];
                    }
                    if ($sizeColumn !== false) {
                        $sampleSize = $values[$i * $width + $sizeColumn];
                        if ($sampleSize < 1) {
                            throw new VoiceFormatException('mp4_sample_size');
                        }
                        $bytes += $sampleSize;
                    }
                }
            }
        }
        $this->fragmentSamples += $count;
        if ($count > 0) {
            // A fragment's 'mdat' usually follows it, so the range is checked once the whole file is read.
            $this->pendingRanges .= pack('J2', $position, $position + $bytes);
        }

        return [$position + $bytes, $ticks];
    }
}
