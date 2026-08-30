<?php

namespace App\Services\Queclink;

use RuntimeException;

/**
 * Allocates the 4-hex-digit serial numbers used in AT commands.
 *
 * The serial appears in the corresponding +ACK from the device and is the
 * only reliable way to correlate command → ack.
 *
 * Callers with device context must pass the serials that remain replay-relevant
 * for that device. The exhaustive ring scan guarantees a free slot is returned
 * when one exists instead of relying on a probabilistic retry limit.
 */
class SerialNumberAllocator
{
    private const SLOT_COUNT = 0x10000;

    public function next(): string
    {
        return $this->nextExcluding([]);
    }

    /**
     * @param  iterable<mixed>  $reserved
     */
    public function nextExcluding(iterable $reserved): string
    {
        $excluded = [];
        foreach ($reserved as $serial) {
            $normalized = strtoupper(trim((string) $serial));
            if (preg_match('/^[0-9A-F]{4}$/', $normalized) === 1) {
                $excluded[$normalized] = true;
            }
        }

        if (count($excluded) >= self::SLOT_COUNT) {
            throw new RuntimeException('No Queclink command serial number is currently available.');
        }

        $start = $this->startingValue();
        for ($offset = 0; $offset < self::SLOT_COUNT; $offset++) {
            $candidate = $this->format(($start + $offset) % self::SLOT_COUNT);
            if (! isset($excluded[$candidate])) {
                return $candidate;
            }
        }

        throw new RuntimeException('No Queclink command serial number is currently available.');
    }

    protected function startingValue(): int
    {
        return random_int(0, self::SLOT_COUNT - 1);
    }

    private function format(int $value): string
    {
        return strtoupper(str_pad(dechex($value), 4, '0', STR_PAD_LEFT));
    }
}
