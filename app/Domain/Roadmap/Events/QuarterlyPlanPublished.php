<?php

namespace App\Domain\Roadmap\Events;

use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuarterlyPlanPublished
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public QuarterlyRoadmapPlan $plan,
    ) {}
}
