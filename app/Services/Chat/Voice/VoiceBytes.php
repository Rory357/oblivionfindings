<?php

namespace App\Services\Chat\Voice;

/**
 * Bounded access to one voice file held in memory.
 *
 * Every read is checked against an end offset, so nothing is read past the end of the file or of
 * the element that contains it. Every element, box, page, table entry and packet counts against a
 * per-file budget, and the work stops once the time budget is spent.
 */
final class VoiceBytes
{
    public const MAX_PARTS = 1_000_000;

    public readonly int $length;

    private int $parts = 0;

    private int $sinceClock = 0;

    private readonly int $deadline;

    public function __construct(public readonly string $data, float $seconds = 1.5, private readonly int $maxParts = self::MAX_PARTS)
    {
        $this->length = strlen($data);
        $this->deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
    }

    public function count(int $parts = 1): void
    {
        $this->parts += $parts;
        if ($this->parts > $this->maxParts) {
            throw new VoiceFormatException('too_many_parts');
        }
        $this->sinceClock += $parts;
        if ($this->sinceClock >= 256) {
            $this->sinceClock = 0;
            $this->clock();
        }
    }

    public function clock(): void
    {
        if (hrtime(true) > $this->deadline) {
            throw new VoiceFormatException('too_slow');
        }
    }

    /** Requires $length bytes at $offset, all before $end and the end of the file. */
    public function need(int $offset, int $length, int $end): void
    {
        if ($offset < 0 || $length < 0 || $end > $this->length || $offset > $end - $length) {
            throw new VoiceFormatException('truncated');
        }
    }

    public function byte(int $offset, int $end): int
    {
        $this->need($offset, 1, $end);

        return ord($this->data[$offset]);
    }

    public function bytes(int $offset, int $length, int $end): string
    {
        $this->need($offset, $length, $end);

        return substr($this->data, $offset, $length);
    }

    /** A big-endian unsigned integer of 0 to 8 bytes. Values that do not fit a PHP integer are refused. */
    public function uintBe(int $offset, int $length, int $end): int
    {
        if ($length > 8) {
            throw new VoiceFormatException('integer_too_long');
        }
        $this->need($offset, $length, $end);
        $value = 0;
        for ($i = 0; $i < $length; $i++) {
            if ($value > (PHP_INT_MAX >> 8)) {
                throw new VoiceFormatException('integer_too_large');
            }
            $value = ($value << 8) | ord($this->data[$offset + $i]);
        }

        return $value;
    }

    public function u16be(int $offset, int $end): int
    {
        $this->need($offset, 2, $end);

        return unpack('n', $this->data, $offset)[1];
    }

    public function u32be(int $offset, int $end): int
    {
        $this->need($offset, 4, $end);

        return unpack('N', $this->data, $offset)[1];
    }

    public function u16le(int $offset, int $end): int
    {
        $this->need($offset, 2, $end);

        return unpack('v', $this->data, $offset)[1];
    }

    public function u32le(int $offset, int $end): int
    {
        $this->need($offset, 4, $end);

        return unpack('V', $this->data, $offset)[1];
    }

    /** A big-endian IEEE float of 0, 4 or 8 bytes (an empty one reads as zero). */
    public function floatBe(int $offset, int $length, int $end): float
    {
        $this->need($offset, $length, $end);

        return match ($length) {
            0 => 0.0,
            4 => unpack('G', $this->data, $offset)[1],
            8 => unpack('E', $this->data, $offset)[1],
            default => throw new VoiceFormatException('float_size'),
        };
    }
}
