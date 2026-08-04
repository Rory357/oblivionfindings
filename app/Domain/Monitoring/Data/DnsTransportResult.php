<?php

namespace App\Domain\Monitoring\Data;

final readonly class DnsTransportResult
{
    /** @param list<string> $answers */
    public function __construct(
        public bool $answered,
        public array $answers,
        public ?int $latencyMs,
        public string $reasonCode,
    ) {}
}
