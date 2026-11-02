<?php

namespace App\Services\Chat\Voice;

/**
 * Reads WebM / Matroska voice recordings: EBML as RFC 8794 defines it and the Matroska elements of RFC 9559.
 *
 * It accepts one audio track with the Opus codec and works out the length from the blocks themselves,
 * because browser recordings often carry no Duration (or a placeholder of 0). Element sizes may be
 * unknown only for the Segment and its Clusters, as the format allows for live recording.
 */
final class WebmVoiceParser
{
    private const EBML = 0x1A45DFA3;

    private const SEGMENT = 0x18538067;

    private const SEEK_HEAD = 0x114D9B74;

    private const INFO = 0x1549A966;

    private const TRACKS = 0x1654AE6B;

    private const CLUSTER = 0x1F43B675;

    private const CUES = 0x1C53BB6B;

    private const CHAPTERS = 0x1043A770;

    private const TAGS = 0x1254C367;

    private const ATTACHMENTS = 0x1941A469;

    private const VOID = 0xEC;

    private const CRC32 = 0xBF;

    private const TIMESTAMP_SCALE = 0x2AD7B1;

    private const DURATION = 0x4489;

    private const TRACK_ENTRY = 0xAE;

    private const TRACK_NUMBER = 0xD7;

    private const TRACK_TYPE = 0x83;

    private const CODEC_ID = 0x86;

    private const CODEC_PRIVATE = 0x63A2;

    private const VIDEO = 0xE0;

    private const AUDIO = 0xE1;

    private const SAMPLING_FREQUENCY = 0xB5;

    private const CHANNELS = 0x9F;

    private const CONTENT_ENCODINGS = 0x6D80;

    private const TRACK_TIMESTAMP_SCALE = 0x23314F;

    private const TIMESTAMP = 0xE7;

    private const SIMPLE_BLOCK = 0xA3;

    private const BLOCK_GROUP = 0xA0;

    private const BLOCK = 0xA1;

    private const BLOCK_DURATION = 0x9B;

    private const POSITION = 0xA7;

    private const PREV_SIZE = 0xAB;

    private const SILENT_TRACKS = 0x5854;

    private const ENCRYPTED_BLOCK = 0xAF;

    /** Elements that end an unknown-size Cluster: the Segment's other children, a new Segment or a new EBML header. */
    private const ENDS_CLUSTER = [self::SEEK_HEAD, self::INFO, self::TRACKS, self::CLUSTER, self::CUES, self::CHAPTERS,
        self::TAGS, self::ATTACHMENTS, self::SEGMENT, self::EBML];

    private const AUDIO_TRACK = 2;

    private int $scale = 1_000_000;

    private int $declaredNs = 0;

    private bool $info = false;

    private bool $tracks = false;

    private ?int $track = null;

    private bool $clusters = false;

    private int $blocks = 0;

    private int $samples = 0;

    private int $firstNs = PHP_INT_MAX;

    private int $endNs = 0;

    private int $lastNs = 0;

    public function __construct(private readonly VoiceBytes $b) {}

    public function parse(): VoiceFacts
    {
        $length = $this->b->length;
        [$id, $size, $start] = $this->header(0, $length);
        if ($id !== self::EBML || $size === null) {
            throw new VoiceFormatException('webm_header');
        }
        $this->ebmlHeader($start, $start + $size);
        [$id, $size, $start] = $this->header($start + $size, $length);
        if ($id !== self::SEGMENT) {
            throw new VoiceFormatException('webm_segment');
        }
        if ($size !== null && $start + $size !== $length) {
            throw new VoiceFormatException('webm_data_after_segment');
        }
        $this->segment($start, $length);
        if ($this->blocks === 0) {
            throw new VoiceFormatException('webm_no_audio');
        }
        $span = $this->endNs - $this->firstNs;
        $total = VoiceFacts::nanoseconds($this->samples, 48_000);

        return new VoiceFacts('audio/webm', max($span, $total, $this->declaredNs));
    }

    /**
     * Reads one element header at $p: [ID, data size or null when unknown, data start].
     * The ID must use its shortest form and the data must end inside $end (RFC 8794 sections 4–6).
     */
    private function header(int $p, int $end): array
    {
        $this->b->count();
        [$id, $idLength] = $this->id($p, $end);
        $q = $p + $idLength;
        $sizeLength = $this->vintLength($this->b->byte($q, $end));
        $raw = $this->b->uintBe($q, $sizeLength, $end);
        $all = (1 << (7 * $sizeLength)) - 1;
        $value = $raw & $all;
        $start = $q + $sizeLength;
        if ($value === $all) {
            return [$id, null, $start];
        }
        if ($value > $end - $start) {
            throw new VoiceFormatException('webm_element_past_end');
        }

        return [$id, $value, $start];
    }

    /** [ID including its length marker, octet length]. IDs are at most four octets here (EBMLMaxIDLength 4). */
    private function id(int $p, int $end): array
    {
        $first = $this->b->byte($p, $end);
        $length = $this->vintLength($first);
        if ($length > 4) {
            throw new VoiceFormatException('webm_id');
        }
        $id = $this->b->uintBe($p, $length, $end);
        $bits = $id & ((1 << (7 * $length)) - 1);
        $shortest = $length === 1 ? 1 : (1 << (7 * ($length - 1))) - 1;
        if ($bits === (1 << (7 * $length)) - 1 || $bits < $shortest) {
            throw new VoiceFormatException('webm_id');
        }

        return [$id, $length];
    }

    private function vintLength(int $first): int
    {
        if ($first === 0) {
            throw new VoiceFormatException('webm_vint');
        }
        $length = 1;
        for ($mask = 0x80; ($first & $mask) === 0; $mask >>= 1) {
            $length++;
        }

        return $length;
    }

    /** An unsigned integer element: 0 to 8 bytes, empty meaning zero (RFC 8794 section 7.2). */
    private function uint(int $start, int $size): int
    {
        return $this->b->uintBe($start, $size, $start + $size);
    }

    private function known(?int $size): int
    {
        if ($size === null) {
            throw new VoiceFormatException('webm_unknown_size');
        }

        return $size;
    }

    private function ebmlHeader(int $start, int $end): void
    {
        $seen = [];
        $docType = null;
        $version = 1;
        $readVersion = 1;
        for ($p = $start; $p < $end; $p = $s + $size) {
            [$id, $size, $s] = $this->header($p, $end);
            $size = $this->known($size);
            if (isset($seen[$id]) && $id !== self::VOID && $id !== self::CRC32) {
                throw new VoiceFormatException('webm_duplicate');
            }
            $seen[$id] = true;
            match ($id) {
                0x4286 => ($size === 0 ? 1 : $this->uint($s, $size)) >= 1 || throw new VoiceFormatException('webm_ebml_version'),
                0x42F7 => in_array($size === 0 ? 1 : $this->uint($s, $size), [1], true) || throw new VoiceFormatException('webm_ebml_version'),
                0x42F2 => ($size === 0 ? 4 : $this->uint($s, $size)) === 4 || throw new VoiceFormatException('webm_ebml_limits'),
                0x42F3 => in_array($size === 0 ? 8 : $this->uint($s, $size), range(1, 8), true) || throw new VoiceFormatException('webm_ebml_limits'),
                0x4282 => $docType = rtrim($this->b->bytes($s, $size, $end), "\0"),
                0x4287 => $version = $size === 0 ? 1 : $this->uint($s, $size),
                0x4285 => $readVersion = $size === 0 ? 1 : $this->uint($s, $size),
                0x4281, self::VOID, self::CRC32 => null, // DocTypeExtension and global elements carry nothing needed here
                default => throw new VoiceFormatException('webm_header_element'),
            };
        }
        if (! in_array($docType, ['webm', 'matroska'], true)) {
            throw new VoiceFormatException('webm_doctype');
        }
        if ($version < 1 || $readVersion < 1 || $readVersion > $version || $readVersion > 4) {
            throw new VoiceFormatException('webm_doctype_version');
        }
    }

    private function segment(int $start, int $end): void
    {
        $p = $start;
        while ($p < $end) {
            [$id, $size, $s] = $this->header($p, $end);
            if ($id === self::CLUSTER) {
                if (! $this->info || ! $this->tracks) {
                    throw new VoiceFormatException('webm_cluster_before_tracks');
                }
                $this->clusters = true;
                $p = $this->cluster($s, $size === null ? null : $s + $size, $end);

                continue;
            }
            $size = $this->known($size);
            match ($id) {
                self::INFO => $this->info($s, $s + $size),
                self::TRACKS => $this->tracks($s, $s + $size),
                self::SEEK_HEAD, self::CUES, self::CHAPTERS, self::TAGS, self::VOID, self::CRC32 => null,
                self::ATTACHMENTS => throw new VoiceFormatException('webm_attachments'),
                default => throw new VoiceFormatException('webm_segment_element'),
            };
            $p = $s + $size;
        }
    }

    private function info(int $start, int $end): void
    {
        if ($this->info || $this->clusters) {
            throw new VoiceFormatException('webm_info');
        }
        $this->info = true;
        $seen = [];
        $duration = 0.0;
        for ($p = $start; $p < $end; $p = $s + $size) {
            [$id, $size, $s] = $this->header($p, $end);
            $size = $this->known($size);
            if ($id === self::TIMESTAMP_SCALE || $id === self::DURATION) {
                if (isset($seen[$id])) {
                    throw new VoiceFormatException('webm_duplicate');
                }
                $seen[$id] = true;
            }
            if ($id === self::TIMESTAMP_SCALE) {
                $this->scale = $size === 0 ? 1_000_000 : $this->uint($s, $size);
                if ($this->scale < 1 || $this->scale > 1_000_000_000) {
                    throw new VoiceFormatException('webm_timestamp_scale');
                }
            } elseif ($id === self::DURATION) {
                $duration = $this->b->floatBe($s, $size, $end);
                if (! is_finite($duration) || $duration < 0) {
                    throw new VoiceFormatException('webm_duration');
                }
            }
        }
        // Duration counts Segment ticks of TimestampScale nanoseconds. Firefox writes 0 as a placeholder
        // while recording, which carries no length, so 0 is treated as absent.
        $ns = $duration * $this->scale;
        $this->declaredNs = $ns >= VoiceFacts::LONGEST_NS ? VoiceFacts::LONGEST_NS : (int) ceil($ns);
    }

    private function tracks(int $start, int $end): void
    {
        if ($this->tracks || $this->clusters) {
            throw new VoiceFormatException('webm_tracks');
        }
        $this->tracks = true;
        $entries = 0;
        for ($p = $start; $p < $end; $p = $s + $size) {
            [$id, $size, $s] = $this->header($p, $end);
            $size = $this->known($size);
            if ($id === self::TRACK_ENTRY) {
                if (++$entries > 1) {
                    throw new VoiceFormatException('webm_two_tracks');
                }
                $this->entry($s, $s + $size);
            }
        }
        if ($entries !== 1) {
            throw new VoiceFormatException('webm_no_track');
        }
    }

    private function entry(int $start, int $end): void
    {
        $seen = [];
        $number = $type = $codec = $private = null;
        for ($p = $start; $p < $end; $p = $s + $size) {
            [$id, $size, $s] = $this->header($p, $end);
            $size = $this->known($size);
            if (isset($seen[$id]) && $id !== self::VOID && $id !== self::CRC32) {
                throw new VoiceFormatException('webm_duplicate');
            }
            $seen[$id] = true;
            match ($id) {
                self::TRACK_NUMBER => $number = $this->uint($s, $size),
                self::TRACK_TYPE => $type = $this->uint($s, $size),
                self::CODEC_ID => $codec = rtrim($this->b->bytes($s, $size, $end), "\0"),
                self::CODEC_PRIVATE => $private = $this->b->bytes($s, $size, $end),
                self::AUDIO => $this->audio($s, $s + $size),
                self::TRACK_TIMESTAMP_SCALE => $this->b->floatBe($s, $size, $end) === 1.0 || throw new VoiceFormatException('webm_track_timestamp_scale'),
                self::VIDEO => throw new VoiceFormatException('webm_video'),
                self::CONTENT_ENCODINGS => throw new VoiceFormatException('webm_content_encoding'),
                default => null,
            };
        }
        if ($number === null || $number < 1) {
            throw new VoiceFormatException('webm_track_number');
        }
        if ($type !== self::AUDIO_TRACK) {
            throw new VoiceFormatException('webm_not_audio');
        }
        if ($codec !== 'A_OPUS') {
            throw new VoiceFormatException('webm_codec');
        }
        if ($private === null) {
            throw new VoiceFormatException('opus_head');
        }
        OpusPacket::head($private);
        $this->track = $number;
    }

    private function audio(int $start, int $end): void
    {
        for ($p = $start; $p < $end; $p = $s + $size) {
            [$id, $size, $s] = $this->header($p, $end);
            $size = $this->known($size);
            if ($id === self::SAMPLING_FREQUENCY) {
                $rate = $this->b->floatBe($s, $size, $end);
                if (! is_finite($rate) || $rate <= 0) {
                    throw new VoiceFormatException('webm_sampling_frequency');
                }
            } elseif ($id === self::CHANNELS && $this->uint($s, $size) < 1) {
                throw new VoiceFormatException('webm_channels');
            }
        }
    }

    /** Walks one Cluster and returns where it ends. An unknown-size Cluster ends at the next Segment-level element. */
    private function cluster(int $start, ?int $knownEnd, int $parentEnd): int
    {
        $end = $knownEnd ?? $parentEnd;
        $timestamp = null;
        $p = $start;
        while ($p < $end) {
            if ($knownEnd === null && in_array($this->id($p, $end)[0], self::ENDS_CLUSTER, true)) {
                break;
            }
            [$id, $size, $s] = $this->header($p, $end);
            $size = $this->known($size);
            switch ($id) {
                case self::TIMESTAMP:
                    if ($timestamp !== null) {
                        throw new VoiceFormatException('webm_duplicate');
                    }
                    $timestamp = $this->uint($s, $size);
                    break;
                case self::SIMPLE_BLOCK:
                    $this->block($s, $s + $size, $timestamp, true);
                    break;
                case self::BLOCK_GROUP:
                    $this->group($s, $s + $size, $timestamp);
                    break;
                case self::POSITION:
                case self::PREV_SIZE:
                    $this->uint($s, $size);
                    break;
                case self::SILENT_TRACKS:
                case self::VOID:
                case self::CRC32:
                    break;
                case self::ENCRYPTED_BLOCK:
                    throw new VoiceFormatException('webm_encrypted');
                default:
                    throw new VoiceFormatException('webm_cluster_element');
            }
            $p = $s + $size;
        }
        if ($timestamp === null) {
            throw new VoiceFormatException('webm_cluster_timestamp');
        }

        return $p;
    }

    private function group(int $start, int $end, ?int $timestamp): void
    {
        $blocks = 0;
        for ($p = $start; $p < $end; $p = $s + $size) {
            [$id, $size, $s] = $this->header($p, $end);
            $size = $this->known($size);
            if ($id === self::BLOCK) {
                $blocks++;
                $this->block($s, $s + $size, $timestamp, false);
            } elseif ($id === self::BLOCK_DURATION) {
                $this->uint($s, $size);
            }
        }
        if ($blocks !== 1) {
            throw new VoiceFormatException('webm_block_group');
        }
    }

    /** A SimpleBlock or Block (RFC 9559 section 10): track, relative timestamp, flags, then one or more Opus packets. */
    private function block(int $start, int $end, ?int $timestamp, bool $simple): void
    {
        if ($timestamp === null) {
            throw new VoiceFormatException('webm_cluster_timestamp');
        }
        $numberLength = $this->vintLength($this->b->byte($start, $end));
        $all = (1 << (7 * $numberLength)) - 1;
        $number = $this->b->uintBe($start, $numberLength, $end) & $all;
        if ($number !== $this->track) {
            throw new VoiceFormatException('webm_block_track');
        }
        $q = $start + $numberLength;
        $relative = $this->b->u16be($q, $end);
        if ($relative >= 0x8000) {
            $relative -= 0x10000;
        }
        $flags = $this->b->byte($q + 2, $end);
        if ($flags & ($simple ? 0x70 : 0xF0)) {
            throw new VoiceFormatException('webm_block_flags');
        }
        $q += 3;
        $sizes = $this->frames($q, $end, ($flags >> 1) & 0x03);
        $samples = 0;
        foreach ($sizes as $size) {
            $this->b->count();
            $samples += OpusPacket::samples($this->b->data, $q, $size);
            $q += $size;
        }
        $time = $timestamp + $relative;
        if ($time < 0) {
            throw new VoiceFormatException('webm_negative_timestamp');
        }
        if ($time > intdiv(VoiceFacts::LONGEST_NS, $this->scale)) {
            throw new VoiceFormatException('webm_timestamp_range');
        }
        $startNs = $time * $this->scale;
        // Audio blocks follow each other in time; a block that goes back is a broken recording.
        if ($this->blocks > 0 && $startNs < $this->lastNs) {
            throw new VoiceFormatException('webm_block_order');
        }
        $this->lastNs = $startNs;
        $this->firstNs = min($this->firstNs, $startNs);
        $this->endNs = max($this->endNs, $startNs + VoiceFacts::nanoseconds($samples, 48_000));
        $this->samples += $samples;
        $this->blocks++;
    }

    /**
     * The sizes of the frames stored in one block, from its lacing (RFC 9559 section 10.3).
     * $q is moved past the lacing header so the frames start there.
     */
    private function frames(int &$q, int $end, int $lacing): array
    {
        if ($lacing === 0) {
            return [$end - $q];
        }
        $count = $this->b->byte($q++, $end) + 1;
        if ($count < 2) {
            throw new VoiceFormatException('webm_lacing'); // lacing must not hold a single frame
        }
        $sizes = [];
        if ($lacing === 1) { // Xiph lacing: runs of 255 plus a final byte below 255
            for ($i = 1; $i < $count; $i++) {
                $size = 0;
                do {
                    $this->b->count();
                    $value = $this->b->byte($q++, $end);
                    $size += $value;
                } while ($value === 255);
                $sizes[] = $size;
            }
        } elseif ($lacing === 3) { // EBML lacing: first size, then signed differences
            $length = $this->vintLength($this->b->byte($q, $end));
            $mask = (1 << (7 * $length)) - 1;
            $size = $this->b->uintBe($q, $length, $end) & $mask;
            if ($size === $mask) {
                throw new VoiceFormatException('webm_lacing');
            }
            $q += $length;
            $sizes[] = $size;
            for ($i = 2; $i < $count; $i++) {
                $this->b->count();
                $length = $this->vintLength($this->b->byte($q, $end));
                $mask = (1 << (7 * $length)) - 1;
                $raw = $this->b->uintBe($q, $length, $end) & $mask;
                if ($raw === $mask) {
                    throw new VoiceFormatException('webm_lacing');
                }
                $q += $length;
                $size += $raw - ((1 << (7 * $length - 1)) - 1);
                if ($size < 0) {
                    throw new VoiceFormatException('webm_lacing');
                }
                $sizes[] = $size;
            }
        } else { // fixed-size lacing: equal frames fill the block
            $rest = $end - $q;
            if ($rest < 0 || $rest % $count !== 0) {
                throw new VoiceFormatException('webm_lacing');
            }

            return array_fill(0, $count, intdiv($rest, $count));
        }
        $last = $end - $q - array_sum($sizes);
        if ($last < 0) {
            throw new VoiceFormatException('webm_lacing');
        }
        $sizes[] = $last;

        return $sizes;
    }
}
