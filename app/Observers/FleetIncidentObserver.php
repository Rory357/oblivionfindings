<?php

namespace App\Observers;

use App\Models\FleetIncident;
use App\Models\HsEvent;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class FleetIncidentObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly ComprehensiveAlertBridgeService $bridge,
        private readonly HsEventService $hsEventService,
    ) {}

    public function created(FleetIncident $incident): void
    {
        $this->recordHsEvent($incident);

        if ($this->qualifiesForBridge($incident)) {
            $this->dispatchBridge($incident);
        }
    }

    public function updated(FleetIncident $incident): void
    {
        if (! $incident->wasChanged('severity')) {
            return;
        }

        $this->syncHsEventSeverity($incident);

        // Re-bridge when severity escalates INTO the major/critical band (Gap F4 —
        // fleet vocab is minor/moderate/major/critical, not high/critical).
        $wasHigh = in_array($incident->getOriginal('severity'), ['major', 'critical'], true);

        if ($incident->isHighSeverity() && ! $wasHigh) {
            $this->dispatchBridge($incident, escalation: true);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  HsEvent wiring                                                     */
    /* ------------------------------------------------------------------ */

    private function recordHsEvent(FleetIncident $incident): void
    {
        try {
            $this->hsEventService->recordEvent([
                'source' => $incident,
                'event_category' => HsEvent::CATEGORY_VEHICLE_INCIDENT,
                'severity' => $incident->hsSeverity(), // map minor/moderate/major/critical → low/medium/high/critical
                'occurred_at' => $incident->occurred_at,
                'reported_at' => $incident->created_at,
                'site_id' => $incident->asset?->site_id,
                'staff_id' => $incident->driver_user_id,
                'asset_id' => $incident->asset_id,
                'created_by' => $incident->reported_by_user_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('FleetIncidentObserver: HsEvent creation failed', [
                'fleet_incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncHsEventSeverity(FleetIncident $incident): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(get_class($incident), $incident->getKey(), HsEvent::CATEGORY_VEHICLE_INCIDENT);
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                $this->hsEventService->syncSeverity($hsEvent, $incident->hsSeverity());
            }
        } catch (\Throwable $e) {
            Log::error('FleetIncidentObserver: HsEvent severity sync failed', [
                'fleet_incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Bridge dispatch                                                    */
    /* ------------------------------------------------------------------ */

    private function qualifiesForBridge(FleetIncident $incident): bool
    {
        return $incident->isHighSeverity(); // major | critical
    }

    private function dispatchBridge(FleetIncident $incident, bool $escalation = false): void
    {
        try {
            $severity = $incident->severity === 'critical' ? 'critical' : 'high';

            $alert = $this->bridge->bridgeOperationalAlert('fleet_incident', $severity, [
                'fleet_incident_id' => $incident->id,
                'incident_type' => $incident->incident_type,
                'asset_id' => $incident->asset_id,
                'site_id' => $incident->asset?->site_id,
                'driver_user_id' => $incident->driver_user_id,
                'occurred_at' => $incident->occurred_at?->toIso8601String(),
                'location' => $incident->location,
                'description' => $incident->description,
                'police_notified' => $incident->police_notified,
                'severity_escalation' => $escalation,
            ]);

            if ($alert) {
                $this->linkAlertToHsEvent($incident, $alert->id);
            }
        } catch (\Throwable $e) {
            Log::error('FleetIncidentObserver: bridge dispatch failed', [
                'fleet_incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function linkAlertToHsEvent(FleetIncident $incident, int $alertId): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(get_class($incident), $incident->getKey(), HsEvent::CATEGORY_VEHICLE_INCIDENT);
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                $this->hsEventService->linkControlRoomAlert($hsEvent, $alertId);
            }
        } catch (\Throwable $e) {
            Log::warning('FleetIncidentObserver: failed to link alert to HsEvent', [
                'fleet_incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
