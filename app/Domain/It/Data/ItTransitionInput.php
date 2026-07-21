<?php

namespace App\Domain\It\Data;

use App\Domain\It\Enums\ItWorkflowState;
use App\Models\User;

final readonly class ItTransitionInput
{
    public function __construct(
        public User $actor,
        public ItWorkflowState $to,
        public ?string $reason = null,
        public ?string $waitingParty = null,
        public ?string $nextAction = null,
        public ?string $resolutionCode = null,
        public ?string $resolutionSummary = null,
        public string $source = 'manual',
    ) {}
}
