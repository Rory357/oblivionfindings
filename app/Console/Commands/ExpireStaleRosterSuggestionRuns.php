<?php

namespace App\Console\Commands;

use App\Domain\Rostering\AutoSchedule\RosterSuggestionService;
use Illuminate\Console\Command;

class ExpireStaleRosterSuggestionRuns extends Command
{
    protected $signature = 'rostering:expire-stale-suggestion-runs';

    protected $description = 'Expire roster suggestion runs whose review window has passed.';

    public function handle(RosterSuggestionService $suggestions): int
    {
        $count = $suggestions->expireStaleRuns();

        $this->info("Expired {$count} roster suggestion run(s).");

        return self::SUCCESS;
    }
}
