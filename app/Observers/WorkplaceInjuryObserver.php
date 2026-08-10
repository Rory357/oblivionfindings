<?php

namespace App\Observers;

use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\WorkplaceInjuryJourneyService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Compatibility repair for injury writes that do not use the HTTP command path.
 *
 * ReturnToWorkController creates every required projection synchronously inside
 * the source transaction. This observer is intentionally idempotent and is not
 * the integrity boundary; it only reconciles imports, seeders and legacy writers.
 */
class WorkplaceInjuryObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly WorkplaceInjuryJourneyService $journey,
    ) {}

    public function created(WorkplaceInjury $injury): void
    {
        $this->repair($injury);
    }

    public function updated(WorkplaceInjury $injury): void
    {
        if ($injury->wasChanged([
            'user_id',
            'site_id',
            'injury_date',
            'injury_type',
            'body_part_affected',
            'severity',
            'description',
            'worksafe_notifiable',
        ])) {
            $this->repair($injury);
        }
    }

    private function repair(WorkplaceInjury $injury): void
    {
        try {
            $this->journey->synchronize($injury);
        } catch (\Throwable $exception) {
            Log::error('Workplace injury journey repair failed.', [
                'workplace_injury_id' => $injury->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
