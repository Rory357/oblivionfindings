<?php

namespace App\Observers;

use App\Models\HsEvent;
use App\Models\SiteHazard;
use App\Notifications\Sites\HazardAssignedNotification;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class SiteHazardObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly ComprehensiveAlertBridgeService $bridge,
        private readonly HsEventService $hsEventService,
    ) {}

    public function creating(SiteHazard $hazard): void
    {
        // Generate reference number if not set
        if (empty($hazard->reference_number)) {
            $hazard->reference_number = $this->generateReferenceNumber();
        }

        // Calculate risk rating
        if ($hazard->severity && $hazard->likelihood) {
            $calculator = new \App\Services\Sites\SiteHazardRiskCalculator();
            $hazard->risk_rating = $calculator->calculate($hazard->severity, $hazard->likelihood);
        }

        // Set due date based on risk rating
        if (empty($hazard->due_date) && $hazard->risk_rating) {
            $calculator = new \App\Services\Sites\SiteHazardRiskCalculator();
            $hazard->due_date = now()->addDays($calculator->suggestedDueDays($hazard->risk_rating));
        }
    }

    public function created(SiteHazard $hazard): void
    {
        // Auto-assign H&S officer if high/extreme risk
        if (in_array($hazard->risk_rating, ['high', 'extreme'])) {
            $this->autoAssignHealthSafetyOfficer($hazard);
        }

        AuditLogger::log('hazard.created', $hazard);

        // Record HsEvent for all hazards
        $this->recordHsEvent($hazard);

        // Bridge high/extreme hazards to Control Room
        if (in_array($hazard->risk_rating, ['high', 'extreme'])) {
            $this->dispatchBridge($hazard);
        }
    }

    public function updating(SiteHazard $hazard): void
    {
        // Recalculate risk if severity/likelihood changed
        if ($hazard->isDirty(['severity', 'likelihood'])) {
            $calculator = new \App\Services\Sites\SiteHazardRiskCalculator();
            $hazard->risk_rating = $calculator->calculate($hazard->severity, $hazard->likelihood);
        }
    }

    public function updated(SiteHazard $hazard): void
    {
        $updates = [];

        // Log status changes
        if ($hazard->wasChanged('status')) {
            AuditLogger::log('hazard.status_changed', $hazard, [
                'from' => $hazard->getOriginal('status'),
                'to' => $hazard->status,
            ]);

            // Update timestamps
            $updates['status_changed_at'] = now();
            $updates['status_changed_by_user_id'] = auth()->id();

            // Closure timestamps record terminal closure only. `mitigated` is an
            // active "awaiting closure" state, so it must NOT be stamped as
            // closed (doing so corrupts closed-by-month analytics).
            if ($hazard->status === 'closed') {
                $updates['closed_at'] = now();
                $updates['closed_by_user_id'] = auth()->id();
            }
        }

        // Notify on assignment
        if ($hazard->wasChanged('assigned_to_user_id') && $hazard->assigned_to_user_id) {
            if ($hazard->assignedTo) {
                $hazard->assignedTo->notify(new HazardAssignedNotification($hazard));
            }
            $updates['assigned_at'] = now();
        }

        // Log risk changes and bridge if escalated to high/extreme
        if ($hazard->wasChanged('risk_rating')) {
            AuditLogger::log('hazard.risk_changed', $hazard, [
                'from' => $hazard->getOriginal('risk_rating'),
                'to' => $hazard->risk_rating,
            ]);

            // Sync HsEvent severity
            $this->syncHsEventSeverity($hazard);

            if (in_array($hazard->risk_rating, ['high', 'extreme'])
                && ! in_array($hazard->getOriginal('risk_rating'), ['high', 'extreme'])
            ) {
                $this->dispatchBridge($hazard, escalation: true);
            }
        }

        if ($updates !== []) {
            $hazard->forceFill($updates)->saveQuietly();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  HsEvent wiring                                                     */
    /* ------------------------------------------------------------------ */

    private function recordHsEvent(SiteHazard $hazard): void
    {
        try {
            $severity = $hazard->risk_rating ?? 'low';

            $this->hsEventService->recordEvent([
                'source' => $hazard,
                'event_category' => HsEvent::CATEGORY_HAZARD,
                'severity' => $severity,
                'occurred_at' => $hazard->created_at,
                'reported_at' => $hazard->created_at,
                'site_id' => $hazard->site_id,
                'staff_id' => $hazard->reported_by_user_id,
                'created_by' => $hazard->reported_by_user_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('SiteHazardObserver: HsEvent creation failed', [
                'site_hazard_id' => $hazard->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncHsEventSeverity(SiteHazard $hazard): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(get_class($hazard), $hazard->getKey(), HsEvent::CATEGORY_HAZARD);
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                $this->hsEventService->syncSeverity($hsEvent, $hazard->risk_rating ?? 'low');
            }
        } catch (\Throwable $e) {
            Log::error('SiteHazardObserver: HsEvent severity sync failed', [
                'site_hazard_id' => $hazard->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Bridge dispatch                                                    */
    /* ------------------------------------------------------------------ */

    private function dispatchBridge(SiteHazard $hazard, bool $escalation = false): void
    {
        try {
            $severity = $hazard->risk_rating === 'extreme' ? 'critical' : 'high';

            $alert = $this->bridge->bridgeOperationalAlert('hazard_identified', $severity, [
                'site_hazard_id' => $hazard->id,
                'reference_number' => $hazard->reference_number,
                'site_id' => $hazard->site_id,
                'hazard_type' => $hazard->hazard_type,
                'risk_rating' => $hazard->risk_rating,
                'description' => $hazard->description,
                'reported_by_user_id' => $hazard->reported_by_user_id,
                'severity_escalation' => $escalation,
            ]);

            if ($alert) {
                $this->linkAlertToHsEvent($hazard, $alert->id);
            }
        } catch (\Throwable $e) {
            Log::error('SiteHazardObserver: bridge dispatch failed', [
                'site_hazard_id' => $hazard->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function linkAlertToHsEvent(SiteHazard $hazard, int $alertId): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(get_class($hazard), $hazard->getKey(), HsEvent::CATEGORY_HAZARD);
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                $this->hsEventService->linkControlRoomAlert($hsEvent, $alertId);
            }
        } catch (\Throwable $e) {
            Log::warning('SiteHazardObserver: failed to link alert to HsEvent', [
                'site_hazard_id' => $hazard->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Existing helpers (unchanged)                                        */
    /* ------------------------------------------------------------------ */

    private function autoAssignHealthSafetyOfficer(SiteHazard $hazard): void
    {
        $hsOfficer = \App\Models\User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'health_safety_officer'))
            ->first();

        if ($hsOfficer) {
            $hazard->forceFill([
                'assigned_to_user_id' => $hsOfficer->id,
                'assigned_at' => now(),
            ])->saveQuietly();
        }
    }

    private function generateReferenceNumber(): string
    {
        return app(\App\Services\References\ReferenceNumberGenerator::class)->next('HAZ');
    }
}
