<?php

namespace App\Observers;

use App\Models\HsEvent;
use App\Models\RestraintEvent;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class RestraintEventObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly ComprehensiveAlertBridgeService $bridge,
        private readonly HsEventService $hsEventService,
    ) {}

    public function created(RestraintEvent $event): void
    {
        $this->recordHsEvent($event);

        if ($this->qualifiesForBridge($event)) {
            $this->dispatchBridge($event);
        }
    }

    public function updated(RestraintEvent $event): void
    {
        if ($event->wasChanged('injury_occurred')
            && $event->injury_occurred
            && ! $event->getOriginal('injury_occurred')
        ) {
            $this->syncHsEventForInjuryDiscovery($event);
            $this->dispatchBridge($event, escalation: true);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  HsEvent wiring                                                     */
    /* ------------------------------------------------------------------ */

    private function recordHsEvent(RestraintEvent $event): void
    {
        try {
            $severity = $event->injury_occurred ? 'high' : 'medium';

            $this->hsEventService->recordEvent([
                'source' => $event,
                'event_category' => HsEvent::CATEGORY_RESTRAINT,
                'severity' => $severity,
                'occurred_at' => $event->started_at,
                'reported_at' => $event->created_at,
                'site_id' => $event->site_id,
                'client_id' => $event->client_id,
                'staff_id' => $event->created_by,
                'created_by' => $event->created_by,
            ]);
        } catch (\Throwable $e) {
            Log::error('RestraintEventObserver: HsEvent creation failed', [
                'restraint_event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncHsEventForInjuryDiscovery(RestraintEvent $event): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(get_class($event), $event->getKey(), HsEvent::CATEGORY_RESTRAINT);
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                $this->hsEventService->syncSeverity($hsEvent, 'high');
            }
        } catch (\Throwable $e) {
            Log::error('RestraintEventObserver: HsEvent injury sync failed', [
                'restraint_event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Bridge dispatch                                                    */
    /* ------------------------------------------------------------------ */

    private function qualifiesForBridge(RestraintEvent $event): bool
    {
        if ($event->injury_occurred) {
            return true;
        }

        if ($event->within_support_plan === false) {
            return true;
        }

        return false;
    }

    private function dispatchBridge(RestraintEvent $event, bool $escalation = false): void
    {
        try {
            $severity = $event->injury_occurred ? 'high' : 'medium';

            $alert = $this->bridge->bridgeOperationalAlert('restraint_event', $severity, [
                'restraint_event_id' => $event->id,
                'client_id' => $event->client_id,
                'site_id' => $event->site_id,
                'restraint_type' => $event->restraint_type,
                'injury_occurred' => $event->injury_occurred,
                'within_support_plan' => $event->within_support_plan,
                'duration_minutes' => $event->duration_minutes,
                'started_at' => $event->started_at?->toIso8601String(),
                'description' => $event->restraint_description,
                'severity_escalation' => $escalation,
            ]);

            if ($alert) {
                $this->linkAlertToHsEvent($event, $alert->id);
            }
        } catch (\Throwable $e) {
            Log::error('RestraintEventObserver: bridge dispatch failed', [
                'restraint_event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function linkAlertToHsEvent(RestraintEvent $event, int $alertId): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(get_class($event), $event->getKey(), HsEvent::CATEGORY_RESTRAINT);
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                $this->hsEventService->linkControlRoomAlert($hsEvent, $alertId);
            }
        } catch (\Throwable $e) {
            Log::warning('RestraintEventObserver: failed to link alert to HsEvent', [
                'restraint_event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
