<?php

namespace App\Observers;

use App\Domain\Governance\Services\IncidentEscalationService;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class ClientIncidentObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly ComprehensiveAlertBridgeService $bridge,
        private readonly HsEventService $hsEventService,
        private readonly IncidentEscalationService $governanceEscalation,
    ) {}

    /**
     * On create: record HsEvent and bridge high/critical incidents that are not drafts.
     */
    public function created(ClientIncident $incident): void
    {
        // Always create the HsEvent record (even for low severity and drafts)
        $this->recordHsEvent($incident);

        if (! $this->shouldBridge($incident)) {
            return;
        }

        $this->dispatchBridge($incident);
        $this->maybeEscalateToGovernance($incident);
    }

    /**
     * On update: sync HsEvent severity, bridge on material escalation or draft→submitted.
     */
    public function updated(ClientIncident $incident): void
    {
        // Sync severity changes to HsEvent
        if ($incident->wasChanged('severity')) {
            $this->syncHsEventSeverity($incident);
        }

        if (! $this->shouldBridgeOnUpdate($incident)) {
            return;
        }

        $this->dispatchBridge($incident, $this->isEscalation($incident));
        $this->maybeEscalateToGovernance($incident);
    }

    /**
     * Bridge a high/critical incident into the governance escalation track.
     * Idempotent — the service deduplicates by (client_incident_id, reason).
     * Failures are logged but never break the operational flow.
     */
    private function maybeEscalateToGovernance(ClientIncident $incident): void
    {
        try {
            $this->governanceEscalation->escalateClientIncident($incident);
        } catch (\Throwable $e) {
            Log::warning('ClientIncidentObserver: governance escalation failed', [
                'incident_id' => $incident->id,
                'severity' => $incident->severity,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  HsEvent wiring                                                     */
    /* ------------------------------------------------------------------ */

    private function recordHsEvent(ClientIncident $incident): void
    {
        try {
            $category = $incident->type === 'near_miss'
                ? HsEvent::CATEGORY_NEAR_MISS
                : HsEvent::CATEGORY_INCIDENT;

            $this->hsEventService->recordEvent([
                'source' => $incident,
                'event_category' => $category,
                'severity' => $incident->severity ?? 'low',
                'occurred_at' => $incident->occurred_at,
                'reported_at' => $incident->submitted_at ?? $incident->created_at,
                'site_id' => $incident->client?->site_id ?? null,
                'client_id' => $incident->client_id,
                'staff_id' => $incident->reported_by,
                'shift_id' => $incident->shift_id,
                'worksafe_notifiable' => (bool) $incident->is_notifiable,
                'created_by' => $incident->reported_by,
            ]);
        } catch (\Throwable $e) {
            Log::error('ClientIncidentObserver: HsEvent creation failed', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncHsEventSeverity(ClientIncident $incident): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(
                get_class($incident),
                $incident->getKey(),
                $incident->type === 'near_miss' ? HsEvent::CATEGORY_NEAR_MISS : HsEvent::CATEGORY_INCIDENT,
            );

            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                $this->hsEventService->syncSeverity($hsEvent, $incident->severity);
            }
        } catch (\Throwable $e) {
            Log::error('ClientIncidentObserver: HsEvent severity sync failed', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Bridge logic (unchanged from PR0, with escalation flag added)      */
    /* ------------------------------------------------------------------ */

    private function shouldBridge(ClientIncident $incident): bool
    {
        if (! in_array($incident->severity, ['high', 'critical'], true)) {
            return false;
        }

        if ($incident->status === 'draft') {
            return false;
        }

        return true;
    }

    private function shouldBridgeOnUpdate(ClientIncident $incident): bool
    {
        // Case 1: severity escalated TO high/critical on a non-draft
        if ($incident->wasChanged('severity')
            && in_array($incident->severity, ['high', 'critical'], true)
            && ! in_array($incident->getOriginal('severity'), ['high', 'critical'], true)
            && $incident->status !== 'draft'
        ) {
            return true;
        }

        // Case 2: high/critical draft just submitted
        if ($incident->wasChanged('status')
            && $incident->getOriginal('status') === 'draft'
            && $incident->status !== 'draft'
            && in_array($incident->severity, ['high', 'critical'], true)
        ) {
            return true;
        }

        return false;
    }

    private function isEscalation(ClientIncident $incident): bool
    {
        return $incident->wasChanged('severity')
            && in_array($incident->severity, ['high', 'critical'], true)
            && ! in_array($incident->getOriginal('severity'), ['high', 'critical'], true);
    }

    private function dispatchBridge(ClientIncident $incident, bool $escalation = false): void
    {
        try {
            if ($escalation) {
                // Use operational alert with escalation flag for dedup bypass
                $this->bridge->bridgeOperationalAlert(
                    "client_incident_escalation",
                    $incident->severity,
                    [
                        'severity_escalation' => true,
                        'incident_id' => $incident->id,
                        'client_id' => $incident->client_id,
                        'previous_severity' => $incident->getOriginal('severity'),
                        'new_severity' => $incident->severity,
                    ],
                );
            } else {
                $alert = $this->bridge->bridgeClientIncident($incident);

                // Link Control Room alert back to HsEvent
                if ($alert) {
                    $this->linkAlertToHsEvent($incident, $alert->id);
                }
            }
        } catch (\Throwable $e) {
            Log::error('ClientIncidentObserver: bridge dispatch failed', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function linkAlertToHsEvent(ClientIncident $incident, int $alertId): void
    {
        try {
            $category = $incident->type === 'near_miss'
                ? HsEvent::CATEGORY_NEAR_MISS
                : HsEvent::CATEGORY_INCIDENT;

            $key = HsEvent::buildIdempotencyKey(get_class($incident), $incident->getKey(), $category);
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                $this->hsEventService->linkControlRoomAlert($hsEvent, $alertId);
            }
        } catch (\Throwable $e) {
            // Non-critical — don't break the flow for a back-reference
            Log::warning('ClientIncidentObserver: failed to link alert to HsEvent', [
                'incident_id' => $incident->id,
                'alert_id' => $alertId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
