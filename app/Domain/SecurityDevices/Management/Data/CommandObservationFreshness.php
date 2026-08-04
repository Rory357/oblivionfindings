<?php

namespace App\Domain\SecurityDevices\Management\Data;

use Carbon\CarbonImmutable;

final readonly class CommandObservationFreshness
{
    public function __construct(
        public string $state,
        public ?CarbonImmutable $observedAt,
        public int $staleAfterSeconds,
        public string $source,
    ) {}

    public function isFresh(): bool
    {
        return $this->state === 'fresh';
    }
}
