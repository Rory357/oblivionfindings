<?php

namespace App\Domain\SecurityDevices\Management\Data;

use Carbon\CarbonImmutable;

final readonly class BulkCommandRequestInput
{
    /**
     * @param  list<int>  $deviceIds
     * @param  array<string, mixed>  $parameters
     * @param  array<int, int>  $itChangeIds
     */
    public function __construct(
        public string $workspace,
        public array $deviceIds,
        public string $capability,
        public array $parameters,
        public string $reason,
        public string $idempotencyKey,
        public ?CarbonImmutable $stepUpConfirmedAt = null,
        public array $itChangeIds = [],
        public bool $impactAcknowledged = false,
        public ?string $confirmationText = null,
    ) {}
}
