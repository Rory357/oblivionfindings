<?php

namespace App\Domain\Monitoring\Protocols\Flow;

final readonly class FlowSequenceHealth
{
    public function __construct(
        public string $status,
        public ?int $expectedSequence,
        public int $actualSequence,
        public int $gapCount = 0,
    ) {}
}
