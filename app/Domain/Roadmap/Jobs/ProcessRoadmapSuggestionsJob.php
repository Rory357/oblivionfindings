<?php

namespace App\Domain\Roadmap\Jobs;

use App\Domain\Roadmap\Services\RoadmapSuggestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessRoadmapSuggestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(RoadmapSuggestionService $suggestionService): void
    {
        try {
            $result = $suggestionService->ingestAll();

            Log::info('Roadmap suggestions ingested', [
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to process roadmap suggestions', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
