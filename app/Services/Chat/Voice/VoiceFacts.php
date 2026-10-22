<?php

namespace App\Services\Chat\Voice;

/** What a parser established about one voice file: its MIME type and its length in nanoseconds. */
final readonly class VoiceFacts
{
    /** Lengths are capped here (about 11.5 days); anything this long is far past every limit. */
    public const LONGEST_NS = 1_000_000_000_000_000;

    public function __construct(public string $mime, public int $durationNs) {}

    /** Converts a count of $perSecond ticks to nanoseconds, rounding up and capping at LONGEST_NS. */
    public static function nanoseconds(int $ticks, int $perSecond): int
    {
        if ($ticks <= 0) {
            return 0;
        }
        if ($perSecond <= 0) {
            throw new VoiceFormatException('timescale');
        }
        $whole = intdiv($ticks, $perSecond);
        if ($whole >= intdiv(self::LONGEST_NS, 1_000_000_000)) {
            return self::LONGEST_NS;
        }
        $rest = $ticks % $perSecond;

        return $whole * 1_000_000_000 + intdiv($rest * 1_000_000_000 + $perSecond - 1, $perSecond);
    }
}
