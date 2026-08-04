<?php

namespace App\Domain\Monitoring\Data;

use App\Domain\Monitoring\Enums\MonitorState;
use Carbon\CarbonImmutable;

final readonly class MonitorTransitionDecision
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public MonitorState $reportedState,
        public MonitorState $confirmedState,
        public string $reason,
        public bool $stateChanged,
        public ?MonitorState $pendingState,
        public int $pendingCount,
        public ?CarbonImmutable $pendingSinceAt,
        public array $evidence = [],
    ) {}
}
