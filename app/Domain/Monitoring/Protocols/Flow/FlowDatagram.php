<?php

namespace App\Domain\Monitoring\Protocols\Flow;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class FlowDatagram
{
    /** @param list<FlowRecord> $records */
    public function __construct(
        public string $family,
        public string $exporterAddress,
        public int $sourceId,
        public int $sequence,
        public ?int $uptimeMillis,
        public CarbonImmutable $exportedAt,
        public array $records,
    ) {
        if (! in_array($family, ['netflow-v5', 'netflow-v9', 'ipfix', 'sflow-v5'], true)
            || filter_var($exporterAddress, FILTER_VALIDATE_IP) === false
            || $sourceId < 0 || $sourceId > 0xFFFFFFFF
            || $sequence < 0 || $sequence > 0xFFFFFFFF
            || ($uptimeMillis !== null && ($uptimeMillis < 0 || $uptimeMillis > 0xFFFFFFFF))
            || count($records) > 1000) {
            throw new InvalidArgumentException('Flow datagram is invalid.');
        }
        foreach ($records as $record) {
            if (! $record instanceof FlowRecord) {
                throw new InvalidArgumentException('Flow datagram record is invalid.');
            }
        }
    }

    public function sequenceIncrement(): int
    {
        return match ($this->family) {
            'netflow-v5', 'ipfix' => count($this->records),
            'netflow-v9', 'sflow-v5' => 1,
        };
    }
}
