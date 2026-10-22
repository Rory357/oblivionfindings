<?php

namespace Tests\Support\ChatVoice;

/**
 * A voice file made by the test suite, with what it should check as and a map of its structure.
 *
 * The map tells the mutation test which changes must break the file: a cut strictly inside an extent
 * (an element, box or page whose size is declared), or any change to a critical byte (sizes, required
 * IDs and box types, and values the format fixes). Ogg files are fully covered by their page CRCs and
 * end-of-stream page, so every change to them breaks.
 */
final readonly class VoiceFile
{
    /**
     * @param  list<array{int, int}>  $extents  [start, end) of every element, box or page with a declared size
     * @param  list<array{int, int}>  $critical  [start, end) ranges where any change breaks the file
     * @param  list<array{int, int, string}>  $sizes  size fields among the critical ranges: [start, end, 'ebml' or 'box']
     */
    public function __construct(
        public string $name,
        public string $bytes,
        public ?string $mime,
        public ?int $durationMs,
        public array $extents = [],
        public array $critical = [],
        public bool $everyChangeBreaks = false,
        public array $sizes = [],
    ) {}

    /**
     * Whether a changed size field at $at could still describe a valid file. A new size can only
     * keep the structure whole if the element now ends exactly on an existing boundary (it swallowed
     * whole neighbours); every other new size leaves bytes that cannot line up. Those few changes are
     * left undecided instead of being counted as broken.
     */
    public function realigns(string $mutated, int $at): bool
    {
        foreach ($this->sizes as [$start, $end, $kind]) {
            if ($at < $start || $at >= $end) {
                continue;
            }
            if ($kind === 'box') {
                $size = unpack('N', $mutated, $start)[1];
                if ($size <= 1) {
                    return true; // 0 runs to the end of the file and 1 reads a 64-bit size
                }
                $newEnd = $start + $size;
            } else {
                $first = ord($mutated[$start]);
                if ($first === 0) {
                    return false;
                }
                $length = 9 - strlen(decbin($first));
                if ($start + $length > strlen($mutated)) {
                    return false;
                }
                $value = $first & ((1 << (8 - $length)) - 1);
                for ($i = 1; $i < $length; $i++) {
                    $value = ($value << 8) | ord($mutated[$start + $i]);
                }
                if ($value === (1 << (7 * $length)) - 1) {
                    return true; // now of unknown size
                }
                $newEnd = $start + $length + $value;
            }
            if ($newEnd === strlen($mutated)) {
                return true;
            }
            foreach ($this->extents as [$from, $to]) {
                if ($newEnd === $from || $newEnd === $to) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    public function cutBreaks(int $at): bool
    {
        if ($at >= strlen($this->bytes)) {
            return false;
        }
        if ($this->everyChangeBreaks) {
            return true;
        }
        foreach ($this->extents as [$start, $end]) {
            if ($start < $at && $at < $end) {
                return true;
            }
        }

        return false;
    }

    public function changeBreaks(int $at): bool
    {
        if ($this->everyChangeBreaks) {
            return true;
        }
        foreach ($this->critical as [$start, $end]) {
            if ($start <= $at && $at < $end) {
                return true;
            }
        }

        return false;
    }

    /** Offsets of every critical byte, for picking mutations. */
    public function criticalOffsets(): array
    {
        $offsets = [];
        foreach ($this->critical as [$start, $end]) {
            for ($i = $start; $i < $end; $i++) {
                $offsets[$i] = true;
            }
        }

        return array_keys($offsets);
    }
}
