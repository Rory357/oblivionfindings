<?php

namespace App\Observers;

use App\Models\EmergencyDrill;
use App\Models\HsEvent;
use App\Services\HealthSafety\HsEventService;
use App\Services\HealthSafety\HsSignalService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Observer for EmergencyDrill — creates HsEvent and signals for failed drills.
 *
 * Triggers when a drill is completed with a non-pass outcome.
 * This is a safety compliance event: a failed drill means the site's
 * emergency response capability is unverified.
 */
class EmergencyDrillObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        protected HsEventService $hsEventService,
        protected HsSignalService $hsSignalService,
    ) {}

    /**
     * Handle drill updated — detect completion with failed outcome.
     */
    public function updated(EmergencyDrill $drill): void
    {
        // Only trigger when status transitions to 'completed'
        if (!$drill->wasChanged('status') || $drill->status !== 'completed') {
            return;
        }

        // Only trigger for failed/partial outcomes — not for pass
        if ($this->isPassing($drill->outcome)) {
            return;
        }

        $this->handleDrillFailure($drill);
    }

    /**
     * Record HsEvent and emit signal for a failed drill.
     */
    protected function handleDrillFailure(EmergencyDrill $drill): void
    {
        try {
            // Record HsEvent. recordEvent() derives source_type/source_id + the
            // idempotency key from the `source` MODEL — passing source_type/source_id
            // strings instead (the original bug) threw on the undefined `source` key
            // and was silently swallowed, so the drill_failure convergence never fired.
            $this->hsEventService->recordEvent([
                'source' => $drill,
                'event_category' => HsEvent::CATEGORY_DRILL_FAILURE,
                'severity' => 'medium',
                'site_id' => $drill->site_id,
                'occurred_at' => $drill->completed_at ?? now(),
                'reported_at' => now(),
            ]);

            // Emit signal → Control Room
            $this->hsSignalService->emitDrillFailure(
                $drill->id,
                $drill->drill_type ?? 'evacuation',
                $drill->title ?? 'Emergency Drill',
                $drill->site_id,
                [
                    'outcome' => $drill->outcome,
                    'completed_at' => $drill->completed_at?->toIso8601String(),
                    'site_name' => $drill->site?->name,
                    'total_participants' => $drill->total_participants,
                    'all_areas_checked' => $drill->all_areas_checked,
                    'assembly_point_reached' => $drill->assembly_point_reached,
                    'roll_call_completed' => $drill->roll_call_completed,
                    'improvements_identified' => $drill->improvements_identified,
                    'observer_notes' => $drill->observer_notes,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('EmergencyDrillObserver: failed to process drill failure', [
                'drill_id' => $drill->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Determine if a drill outcome is a passing result.
     */
    protected function isPassing(?string $outcome): bool
    {
        if ($outcome === null) {
            return false; // No outcome recorded = not passing
        }

        return in_array(strtolower($outcome), ['pass', 'passed', 'successful', 'satisfactory'], true);
    }
}
