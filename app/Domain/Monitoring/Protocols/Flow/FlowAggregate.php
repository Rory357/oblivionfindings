<?php

namespace App\Domain\Monitoring\Protocols\Flow;

final readonly class FlowAggregate
{
    /** @param list<array<string, int|string|null>> $buckets */
    public function __construct(
        public int $siteId,
        public string $exporterAddress,
        public string $family,
        public int $sourceId,
        public int $sequence,
        public array $buckets,
    ) {}
}
