<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CoverageSupplyAdded
{
    use Dispatchable;

    public function __construct(
        public readonly string $coverageWindowKey,
        public readonly int $siteId,
        public readonly ?int $coverageRequirementId,
        public readonly string $windowStartsAt,
        public readonly string $windowEndsAt,
        public readonly ?int $shiftId,
        public readonly ?int $seriesId,
        public readonly ?int $actorId,
        public readonly ?string $action,
    ) {
    }
}
