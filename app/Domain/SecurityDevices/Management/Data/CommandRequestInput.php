<?php

namespace App\Domain\SecurityDevices\Management\Data;

use Carbon\CarbonImmutable;

final readonly class CommandRequestInput
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public string $capability,
        public array $parameters,
        public string $reason,
        public string $idempotencyKey,
        public ?CarbonImmutable $stepUpConfirmedAt = null,
        public ?int $itChangeId = null,
        public bool $breakGlass = false,
        public ?string $breakGlassReason = null,
        public ?int $breakGlassReviewerUserId = null,
        public bool $impactAcknowledged = false,
        public ?string $confirmationText = null,
    ) {}
}
