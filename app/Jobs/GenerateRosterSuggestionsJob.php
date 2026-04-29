<?php

namespace App\Jobs;

use App\Domain\Rostering\AutoSchedule\RosterSuggestionService;
use App\Models\RosterSuggestionRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateRosterSuggestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $runId,
    ) {
    }

    public function handle(RosterSuggestionService $suggestions): void
    {
        $run = RosterSuggestionRun::query()->find($this->runId);

        if (! $run || $run->status !== RosterSuggestionRun::STATUS_PENDING) {
            return;
        }

        $suggestions->completePendingRun($run);
    }

    public function failed(Throwable $exception): void
    {
        $run = RosterSuggestionRun::query()->find($this->runId);

        if (! $run || ! in_array($run->status, [RosterSuggestionRun::STATUS_PENDING, RosterSuggestionRun::STATUS_RUNNING], true)) {
            return;
        }

        $run->forceFill([
            'status' => RosterSuggestionRun::STATUS_FAILED,
            'completed_at' => now(),
            'failure_message' => $exception->getMessage(),
        ])->save();
    }
}
