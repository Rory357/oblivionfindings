<?php

namespace App\Domain\Roadmap\Jobs;

use App\Domain\Roadmap\Models\Initiative;
use App\Domain\Roadmap\Services\RoadmapScoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScoreRoadmapInitiativesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public string $preset = 'board_ceo'
    ) {}

    public function handle(RoadmapScoringService $scoringService): void
    {
        $query = Initiative::query()->active();

        $initiatives = $query->get();

        foreach ($initiatives as $initiative) {
            $scoringService->score($initiative, $this->preset, true);
        }

        \Log::info('Roadmap initiative scoring completed', [
            'preset' => $this->preset,
            'count' => $initiatives->count(),
        ]);
    }
}
