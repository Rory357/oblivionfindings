<?php

namespace App\Domain\Roadmap\Jobs;

use App\Domain\Roadmap\Services\QuarterlyRoadmapPlannerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateQuarterlyRoadmapPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public int $fiscalYear,
        public int $quarter,
        public string $preset = 'board_ceo',
        public ?int $generatedBy = null,
    ) {}

    public function handle(QuarterlyRoadmapPlannerService $plannerService): void
    {
        $plan = $plannerService->generateDraft(
            $this->fiscalYear,
            $this->quarter,
            $this->preset,
            $this->generatedBy,
        );

        \Log::info('Roadmap quarterly plan generated', [
            'plan_id' => $plan->id,
            'fiscal_year' => $this->fiscalYear,
            'quarter' => $this->quarter,
        ]);
    }
}
