<?php

namespace App\Domain\Roadmap\Jobs;

use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Domain\Roadmap\Services\QuarterlyRoadmapPlannerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishQuarterlyRoadmapSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public int $planId,
        public int $publishedBy
    ) {}

    public function handle(QuarterlyRoadmapPlannerService $plannerService): void
    {
        $plan = QuarterlyRoadmapPlan::findOrFail($this->planId);
        $plannerService->publish($plan, $this->publishedBy);

        \Log::info('Roadmap plan published', [
            'plan_id' => $this->planId,
            'published_by' => $this->publishedBy,
        ]);
    }
}
