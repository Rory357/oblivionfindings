<?php

namespace App\Observers;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Domain\Governance\Services\GovernanceAuditService;
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
        if ($this->usesCanonicalIncidentJourney($concern)) {
            $this->maybeCreateNotifiableIncident($concern);

            return;
        }

        $this->recordHsEvent($concern);
        $this->dispatchBridge($concern);
        $this->maybeCreateNotifiableIncident($concern);
    }

    /**
     * Bridge on update only when severity escalates TO critical.
     * Carry-forward #3: severity stays 'high' unless explicitly 'critical'.
     */
    public function updated(SafeguardingConcern $concern): void
    {
        if ($this->usesCanonicalIncidentJourney($concern)) {
            $this->maybeCreateNotifiableIncident($concern);

            return;
        }

        if ($concern->wasChanged('severity')) {
            $this->syncHsEventSeverity($concern);
        }

        // Only re-bridge if severity escalated to critical (was not critical before)
        if ($concern->wasChanged('severity')
            && $concern->severity === 'critical'
            && $concern->getOriginal('severity') !== 'critical'
        ) {
            $this->dispatchBridge($concern, escalation: true);
            $this->maybeCreateNotifiableIncident($concern);
        }
    }

    private function usesCanonicalIncidentJourney(SafeguardingConcern $concern): bool
    {
        return $concern->concern_type === 'incident_escalation'
            && $concern->related_incident_id !== null;
    }

    /**
     * For critical safeguarding concerns requiring external reporting,
     * automatically create a `NotifiableIncident` so the regulator
     * notification clock starts. Idempotent — one notifiable per concern.
     */
    private function maybeCreateNotifiableIncident(SafeguardingConcern $concern): void
    {
        if ($concern->severity !== 'critical') {
            return;
        }

        // Tied to the concern via the `related_incident_id` column. Skip if
        // it's already been created for this concern.
        $existing = NotifiableIncident::query()
            ->where('related_incident_id', $concern->id)
            ->where('incident_type', 'safeguarding')
            ->first();

        if ($existing) {
            return;
        }

        try {
            $authority = $this->resolveAuthority($concern);

            $incident = NotifiableIncident::create([
                'incident_type' => 'safeguarding',
                'notification_authority' => $authority,
                'title' => 'Auto-generated from safeguarding concern #' . $concern->id,
                'description' => $concern->description ?? 'Critical safeguarding concern requiring authority notification.',
                'related_incident_id' => $concern->id,
                'severity' => 'critical',
                'status' => 'pending',
                'occurred_at' => $concern->occurred_at ?? $concern->created_at,
                'discovered_at' => $concern->reported_at ?? now(),
                'notification_deadline' => $this->resolveDeadline($authority),
                'submitted_by' => $concern->reported_by_user_id,
            ]);

            GovernanceAuditService::log(
                'notifiable_incident.auto_created',
                'NotifiableIncident',
                $incident->id,
                [
                    'source' => 'SafeguardingConcern',
                    'safeguarding_concern_id' => $concern->id,
                    'authority' => $authority,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('SafeguardingConcernObserver: NotifiableIncident creation failed', [
                'concern_id' => $concern->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map concern context to the most likely notification authority.
     * Conservative default is 'oranga_tamariki' for child-related concerns
     * and 'police' for adult abuse / assault categories — the user can edit
     * the authority on the NotifiableIncident before it's submitted.
     */
    private function resolveAuthority(SafeguardingConcern $concern): string
    {
        // SafeguardingConcern uses concern_type + abuse_category — combine for routing.
        $hint = strtolower(implode(' ', array_filter([
            (string) ($concern->concern_type ?? ''),
            (string) ($concern->abuse_category ?? ''),
        ])));

        if (str_contains($hint, 'financial') || str_contains($hint, 'fraud')) {
            return 'police';
        }
        if (str_contains($hint, 'abuse') || str_contains($hint, 'assault') || str_contains($hint, 'violence')) {
            return 'police';
        }
        if (str_contains($hint, 'child') || str_contains($hint, 'minor')) {
            return 'oranga_tamariki';
        }

        return 'health_nz';
    }

    private function resolveDeadline(string $authority): \Illuminate\Support\Carbon
    {
        // Statutory deadlines vary by authority. Use a conservative 48-hour
        // window as a placeholder; the user can adjust on review.
        return now()->addHours(48);
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
