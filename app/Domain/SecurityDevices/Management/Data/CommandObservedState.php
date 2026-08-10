<?php

namespace App\Domain\SecurityDevices\Management\Data;

use Carbon\CarbonImmutable;

final readonly class CommandObservedState
{
    /** @param array<string, scalar|null> $state */
    public function __construct(
        public array $state,
        public CarbonImmutable $observedAt,
        public string $observationReference,
        public string $safeEvidenceSummary,
    ) {}
}
