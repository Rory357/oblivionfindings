<?php

namespace App\Observers;

use App\Models\HsEvent;
use App\Models\SafeguardingConcern;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Carry-forward #3: Safeguarding concerns default to 'high' severity for
 * Control Room alerts. Only explicit escalation (concern severity = 'critical')
 * produces a critical alert. The bridge method itself floors at 'high'.
 */
class SafeguardingConcernObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly ComprehensiveAlertBridgeService $bridge,
        private readonly HsEventService $hsEventService,
    ) {}

    public function created(SafeguardingConcern $concern): void
    {
        $this->recordHsEvent($concern);
        $this->dispatchBridge($concern);
    }

    /**
     * Bridge on update only when severity escalates TO critical.
     * Carry-forward #3: severity stays 'high' unless explicitly 'critical'.
     */
    public function updated(SafeguardingConcern $concern): void
    {
        if ($concern->wasChanged('severity')) {
            $this->syncHsEventSeverity($concern);
        }

        // Only re-bridge if severity escalated to critical (was not critical before)
        if ($concern->wasChanged('severity')
            && $concern->severity === 'critical'
            && $concern->getOriginal('severity') !== 'critical'
        ) {
            $this->dispatchBridge($concern, escalation: true);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  HsEvent wiring                                                     */
    /* ------------------------------------------------------------------ */

    private function recordHsEvent(SafeguardingConcern $concern): void
    {
        try {
            // Carry-forward #3: HsEvent severity for safeguarding is floored at 'high'.
            // Only an explicitly 'critical' concern gets 'critical' on the HsEvent.
            $severity = $concern->severity === 'critical' ? 'critical' : 'high';

            $this->hsEventService->recordEvent([
                'source' => $concern,
                'event_category' => HsEvent::CATEGORY_SAFEGUARDING,
                'severity' => $severity,
                'occurred_at' => $concern->occurred_at,
                'reported_at' => $concern->reported_at,
                'site_id' => $concern->site_id,
                'client_id' => $concern->subject_type === 'App\\Models\\Client' ? $concern->subject_id : null,
                'staff_id' => $concern->reported_by_user_id,
                'organization_id' => $concern->organization_id,
                'created_by' => $concern->reported_by_user_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('SafeguardingConcernObserver: HsEvent creation failed', [
                'concern_id' => $concern->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncHsEventSeverity(SafeguardingConcern $concern): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(
                get_class($concern),
                $concern->getKey(),
                HsEvent::CATEGORY_SAFEGUARDING,
            );

            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                // Carry-forward #3: floor at high
                $severity = $concern->severity === 'critical' ? 'critical' : 'high';
                $this->hsEventService->syncSeverity($hsEvent, $severity);
            }
        } catch (\Throwable $e) {
            Log::error('SafeguardingConcernObserver: HsEvent severity sync failed', [
                'concern_id' => $concern->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Bridge dispatch                                                    */
    /* ------------------------------------------------------------------ */

    private function dispatchBridge(SafeguardingConcern $concern, bool $escalation = false): void
    {
        try {
            $alert = $this->bridge->bridgeSafeguardingConcern($concern);

            if ($alert) {
                $this->linkAlertToHsEvent($concern, $alert->id);
            }
        } catch (\Throwable $e) {
            Log::error('SafeguardingConcernObserver: bridge dispatch failed', [
                'concern_id' => $concern->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function linkAlertToHsEvent(SafeguardingConcern $concern, int $alertId): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(get_class($concern), $concern->getKey(), HsEvent::CATEGORY_SAFEGUARDING);
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                $this->hsEventService->linkControlRoomAlert($hsEvent, $alertId);
            }
        } catch (\Throwable $e) {
            Log::warning('SafeguardingConcernObserver: failed to link alert to HsEvent', [
                'concern_id' => $concern->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
