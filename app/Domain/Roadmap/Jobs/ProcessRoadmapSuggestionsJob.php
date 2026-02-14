<?php

namespace App\Domain\Roadmap\Jobs;

use App\Domain\Roadmap\Services\RoadmapSuggestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessRoadmapSuggestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public ?int $tenantId = null
    ) {}

    public function handle(RoadmapSuggestionService $suggestionService): void
    {
        $result = $suggestionService->ingestAll($this->tenantId);

        \Log::info('Roadmap suggestions ingested', [
            'tenant_id' => $this->tenantId,
            'result' => $result,
        ]);
    }
}
