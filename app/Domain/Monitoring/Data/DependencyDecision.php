<?php

namespace App\Domain\Monitoring\Data;

use App\Domain\Monitoring\Enums\MonitorState;

final readonly class DependencyDecision
{
    public function __construct(
        public MonitorState $effectiveState,
        public MonitorState $underlyingState,
        public ?int $rootCauseMonitorId,
        public bool $symptomVisible,
        public ?string $reason = null,
    ) {}
}
