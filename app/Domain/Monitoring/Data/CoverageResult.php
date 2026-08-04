<?php

namespace App\Domain\Monitoring\Data;

final readonly class CoverageResult
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public string $capability,
        public string $status,
        public array $evidence,
    ) {}
}
