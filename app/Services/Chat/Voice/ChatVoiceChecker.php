<?php

namespace App\Services\Chat\Voice;

use App\Services\Chat\ChatVoiceInspection;
use App\Services\Chat\ChatVoiceInspector;
use Throwable;

/**
 * Oblivion's own voice-note checker (Chat plan item C2.5). It replaces ffprobe for the formats browsers
 * record: WebM with Opus, Ogg with Opus, and MP4 with AAC. It decides from the file's own structure that
 * there is exactly one audio stream, and works out its true length. Ogg Vorbis is refused (no browser
 * records it, and its length cannot be read without decoding its setup header).
 *
 * Anything it cannot read with certainty is blocked; it never guesses and never throws.
 */
final class ChatVoiceChecker
{
    public const SHORTEST_NS = 50_000_000;

    /** Two minutes plus room for codec padding, as the ffprobe-based check allowed. */
    public const LONGEST_NS = (ChatVoiceInspector::MAX_SECONDS * 1000 + 500) * 1_000_000;

    /**
     * @param  float  $seconds  work budget per file; kept under the 2-second limit so reading the file fits too
     * @param  int  $maxParts  elements, boxes, pages, table entries and packets allowed per file
     */
    public function __construct(private readonly float $seconds = 1.5, private readonly int $maxParts = VoiceBytes::MAX_PARTS) {}

    public function check(string $bytes): ChatVoiceInspection
    {
        $size = strlen($bytes);
        if ($size < 1 || $size > ChatVoiceInspector::MAX_BYTES) {
            return new ChatVoiceInspection('blocked', errorCode: 'voice_file_invalid');
        }
        try {
            $facts = $this->facts($bytes);
        } catch (Throwable) {
            // A broken file can make any rule fail; every failure means the file is not accepted.
            return new ChatVoiceInspection('blocked', errorCode: 'voice_file_invalid');
        }
        if ($facts->durationNs > self::LONGEST_NS) {
            return new ChatVoiceInspection('blocked', errorCode: 'voice_too_long');
        }
        if ($facts->durationNs < self::SHORTEST_NS) {
            return new ChatVoiceInspection('blocked', errorCode: 'voice_file_invalid');
        }

        return new ChatVoiceInspection('ready', $facts->mime, intdiv($facts->durationNs + 999_999, 1_000_000));
    }

    /**
     * The parsed facts, or a VoiceFormatException naming the rule that failed. The format is chosen
     * by the file's first bytes, never by its name or the type the browser reported.
     */
    public function facts(string $bytes): VoiceFacts
    {
        $file = new VoiceBytes($bytes, $this->seconds, $this->maxParts);

        return match (true) {
            str_starts_with($bytes, "\x1A\x45\xDF\xA3") => (new WebmVoiceParser($file))->parse(),
            str_starts_with($bytes, 'OggS') => (new OggVoiceParser($file))->parse(),
            substr($bytes, 4, 4) === 'ftyp' => (new Mp4VoiceParser($file))->parse(),
            default => throw new VoiceFormatException('unknown_format'),
        };
    }
}
