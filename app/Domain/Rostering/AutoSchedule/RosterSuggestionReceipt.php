<?php

namespace App\Domain\Rostering\AutoSchedule;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RosterSuggestionReceipt
{
    public function begin(Request $request): bool
    {
        $request->session()->forget('roster_suggestion_result');
        try {
            return $this->isPhysicalRoot();
        } catch (Throwable) {
            return false;
        }
    }

    protected function isPhysicalRoot(): bool
    {
        return DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction();
    }

    public function committed(bool $rootEntry, RosterSuggestionCommandResult $result): ?array
    {
        if (! $rootEntry) {
            return null;
        }
        try {
            if (! $this->isPhysicalRoot() || $result->actorId <= 0) {
                return null;
            }

            return ['action' => $result->action, 'actor_id' => $result->actorId, 'request_id' => $result->requestId,
                'scope' => $result->suggestionId === null ? 'accepted_run' : 'single',
                'run_id' => $result->runId, 'site_id' => $result->siteId, 'suggestion_id' => $result->suggestionId,
                'expected_source' => $result->expectedSource,
                'values_hash' => RosterSuggestionSource::hash(['action' => $result->action, 'run_id' => $result->runId,
                    'suggestion_id' => $result->suggestionId, 'expected_source' => $result->expectedSource]),
                'outcome' => $result->outcome, 'changed' => $result->changed, 'disposition' => $result->disposition,
                'counts' => $result->counts, 'suggestion' => $result->suggestion, 'assignments' => $result->assignments];
        } catch (Throwable $exception) {
            try {
                Log::warning('Committed roster suggestion result could not be presented', ['action' => $result->action, 'exception_class' => $exception::class]);
            } catch (Throwable) {
            }

            return null;
        }
    }
}
