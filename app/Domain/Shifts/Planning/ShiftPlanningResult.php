<?php

namespace App\Domain\Shifts\Planning;

use App\Models\Shift;

/** Actual persisted command evidence; no durable idempotency or delivery claim. */
final readonly class ShiftPlanningResult
{
    public function __construct(
        public Shift $shift,
        public int $actorId,
        public bool $changed,
        public array $intent,
        public ?array $source,
        public ?string $rejectionReason = null,
        public array $eligibility = [],
        /** Internal unchanged ValidationException after committed hold cleanup. */
        public array $validationErrors = [],
        public int $validationStatus = 422,
    ) {}
}
