<?php

namespace App\Observers;

use App\Models\HsEvent;
use App\Models\WorkplaceInjury;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class WorkplaceInjuryObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly ComprehensiveAlertBridgeService $bridge,
        private readonly HsEventService $hsEventService,
    ) {}

    public function created(WorkplaceInjury $injury): void
    {
        $this->recordHsEvent($injury);

        if ($this->qualifiesForBridge($injury)) {
            $this->dispatchBridge($injury);
        }
    }

    public function updated(WorkplaceInjury $injury): void
    {
        if ($injury->wasChanged('severity')) {
            $this->syncHsEventSeverity($injury);
        }

        // worksafe_notifiable flipped to true
        if ($injury->wasChanged('worksafe_notifiable')
            && $injury->worksafe_notifiable
            && ! $injury->getOriginal('worksafe_notifiable')
        ) {
            $this->updateHsEventWorksafe($injury);
            $this->dispatchBridge($injury, escalation: true);
            return;
        }

        // severity escalated to serious/critical
        if ($injury->wasChanged('severity')
            && in_array($injury->severity, ['serious', 'critical'], true)
            && ! in_array($injury->getOriginal('severity'), ['serious', 'critical'], true)
        ) {
            $this->dispatchBridge($injury, escalation: true);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  HsEvent wiring                                                     */
    /* ------------------------------------------------------------------ */

    private function recordHsEvent(WorkplaceInjury $injury): void
    {
        try {
            $this->hsEventService->recordEvent([
                'source' => $injury,
                'event_category' => HsEvent::CATEGORY_INJURY,
                'severity' => $injury->severity ?? 'medium',
                'occurred_at' => $injury->injury_date,
                'reported_at' => $injury->created_at,
                'site_id' => $injury->site_id,
                'staff_id' => $injury->user_id,
                'worksafe_notifiable' => (bool) $injury->worksafe_notifiable,
                'created_by' => $injury->created_by,
            ]);
        } catch (\Throwable $e) {
            Log::error('WorkplaceInjuryObserver: HsEvent creation failed', [
                'workplace_injury_id' => $injury->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncHsEventSeverity(WorkplaceInjury $injury): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(get_class($injury), $injury->getKey(), HsEvent::CATEGORY_INJURY);
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                $this->hsEventService->syncSeverity($hsEvent, $injury->severity);
            }
        } catch (\Throwable $e) {
            Log::error('WorkplaceInjuryObserver: HsEvent severity sync failed', [
                'workplace_injury_id' => $injury->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function updateHsEventWorksafe(WorkplaceInjury $injury): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(get_class($injury), $injury->getKey(), HsEvent::CATEGORY_INJURY);
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent && ! $hsEvent->worksafe_notifiable) {
                $hsEvent->update([
                    'worksafe_notifiable' => true,
                    'worksafe_status' => HsEvent::WORKSAFE_PENDING,
                    'investigation_required' => true,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('WorkplaceInjuryObserver: HsEvent WorkSafe update failed', [
                'workplace_injury_id' => $injury->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Bridge dispatch                                                    */
    /* ------------------------------------------------------------------ */

    private function qualifiesForBridge(WorkplaceInjury $injury): bool
    {
        if ($injury->worksafe_notifiable) {
            return true;
        }

        return in_array($injury->severity, ['serious', 'critical'], true);
    }

    private function dispatchBridge(WorkplaceInjury $injury, bool $escalation = false): void
    {
        try {
            $severity = $injury->worksafe_notifiable ? 'critical' : 'high';

            $alert = $this->bridge->bridgeOperationalAlert('workplace_injury', $severity, [
                'workplace_injury_id' => $injury->id,
                'user_id' => $injury->user_id,
                'site_id' => $injury->site_id,
                'injury_type' => $injury->injury_type,
                'injury_severity' => $injury->severity,
                'body_part_affected' => $injury->body_part_affected,
                'worksafe_notifiable' => $injury->worksafe_notifiable,
                'injury_date' => $injury->injury_date?->toIso8601String(),
                'description' => $injury->description,
                'severity_escalation' => $escalation,
            ]);

            if ($alert) {
                $this->linkAlertToHsEvent($injury, $alert->id);
            }
        } catch (\Throwable $e) {
            Log::error('WorkplaceInjuryObserver: bridge dispatch failed', [
                'workplace_injury_id' => $injury->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function linkAlertToHsEvent(WorkplaceInjury $injury, int $alertId): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(get_class($injury), $injury->getKey(), HsEvent::CATEGORY_INJURY);
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                $this->hsEventService->linkControlRoomAlert($hsEvent, $alertId);
            }
        } catch (\Throwable $e) {
            Log::warning('WorkplaceInjuryObserver: failed to link alert to HsEvent', [
                'workplace_injury_id' => $injury->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
