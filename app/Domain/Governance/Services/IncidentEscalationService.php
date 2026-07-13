<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\IncidentGovernanceEscalation;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Models\ClientIncident;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bridges operational incidents into the governance escalation track.
 *
 * Writes a row to `incident_governance_escalations` and optionally links
 * the source incident to a `RiskRegisterEntry` via `risk_event_links`.
 *
 * Called from `ClientIncidentObserver` on critical-severity creation /
 * escalation. Failures are swallowed and logged — must never break the
 * operational incident workflow.
 */
class IncidentEscalationService
{
    public const SEVERITY_THRESHOLDS = [
        'critical' => ['auto_escalate' => true, 'notify_chair' => true, 'notify_ceo' => true],
        'high' => ['auto_escalate' => true, 'notify_chair' => false, 'notify_ceo' => true],
        'major' => ['auto_escalate' => true, 'notify_chair' => false, 'notify_ceo' => true],
        'moderate' => ['auto_escalate' => false, 'notify_chair' => false, 'notify_ceo' => false],
        'medium' => ['auto_escalate' => false, 'notify_chair' => false, 'notify_ceo' => false],
        'minor' => ['auto_escalate' => false, 'notify_chair' => false, 'notify_ceo' => false],
        'low' => ['auto_escalate' => false, 'notify_chair' => false, 'notify_ceo' => false],
    ];

    public function shouldAutoEscalate(?string $severity): bool
    {
        return self::SEVERITY_THRESHOLDS[$severity ?? '']['auto_escalate'] ?? false;
    }

    /**
     * Create or return existing escalation for a given ClientIncident.
     *
     * Idempotent — one escalation per (client_incident_id, escalation_reason).
     */
    public function escalateClientIncident(
        ClientIncident $incident,
        ?int $riskId = null,
        ?string $reasonOverride = null,
    ): ?IncidentGovernanceEscalation {
        try {
            return DB::transaction(function () use ($incident, $riskId, $reasonOverride): ?IncidentGovernanceEscalation {
                $lockedIncident = ClientIncident::query()
                    ->whereKey($incident->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $threshold = self::SEVERITY_THRESHOLDS[$lockedIncident->severity ?? 'low']
                    ?? self::SEVERITY_THRESHOLDS['low'];

                if (! $threshold['auto_escalate']) {
                    return null;
                }

                $reason = $reasonOverride ?? $this->inferReason($lockedIncident);
                $existing = IncidentGovernanceEscalation::query()
                    ->where('client_incident_id', $lockedIncident->id)
                    ->where('escalation_reason', $reason)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $escalation = IncidentGovernanceEscalation::create([
                    'client_incident_id' => $lockedIncident->id,
                    'escalation_reason' => $reason,
                    'status' => 'pending',
                    'notified_chair' => $threshold['notify_chair'] ? $this->resolveChairUserId() : null,
                    'chair_notified_at' => $threshold['notify_chair'] ? now() : null,
                    'notified_ceo' => $threshold['notify_ceo'] ? $this->resolveCeoUserId() : null,
                    'ceo_notified_at' => $threshold['notify_ceo'] ? now() : null,
                    'risk_register_entry_id' => $riskId,
                ]);

                if ($riskId) {
                    $this->linkToRiskRegister($lockedIncident, $escalation, $riskId);
                }

                GovernanceAuditService::log(
                    'incident.escalated',
                    'IncidentGovernanceEscalation',
                    $escalation->id,
                    [
                        'client_incident_id' => $lockedIncident->id,
                        'severity' => $lockedIncident->severity,
                        'escalation_reason' => $reason,
                    ],
                );

                return $escalation;
            }, 3);
        } catch (\Throwable $e) {
            Log::error('IncidentEscalationService: failed to escalate', [
                'client_incident_id' => $incident->id,
                'severity' => $incident->severity,
                'reason_override' => $reasonOverride,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function acknowledgeEscalation(IncidentGovernanceEscalation $escalation, int $userId, ?string $actionTaken = null): void
    {
        $escalation->update([
            'acknowledged_by' => $userId,
            'acknowledged_at' => now(),
            'status' => 'acknowledged',
            'action_taken' => $actionTaken,
        ]);
    }

    public function closeEscalation(IncidentGovernanceEscalation $escalation, int $userId, string $actionTaken): void
    {
        $escalation->update([
            'status' => 'closed',
            'acknowledged_by' => $escalation->acknowledged_by ?? $userId,
            'acknowledged_at' => $escalation->acknowledged_at ?? now(),
            'action_taken' => $actionTaken,
        ]);
    }

    public function getOpenEscalations(): array
    {
        return IncidentGovernanceEscalation::whereIn('status', ['pending', 'acknowledged', 'actioned'])
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    private function linkToRiskRegister(ClientIncident $incident, IncidentGovernanceEscalation $escalation, int $riskId): void
    {
        $risk = RiskRegisterEntry::find($riskId);
        if (! $risk) {
            return;
        }

        $alreadyLinked = $risk->events()
            ->where('event_type', 'incident')
            ->where('event_id', $incident->id)
            ->exists();

        if ($alreadyLinked) {
            return;
        }

        $risk->events()->create([
            'event_type' => 'incident',
            'event_id' => $incident->id,
            'event_reference' => "ESC-{$escalation->id}",
            'event_severity' => $incident->severity,
            'event_occurred_at' => $incident->occurred_at ?? now(),
            'link_rationale' => "Auto-linked from governance escalation #{$escalation->id}",
            'linked_by' => $incident->reported_by,
            'linked_at' => now(),
        ]);
    }

    private function inferReason(ClientIncident $incident): string
    {
        // Death and serious-harm types are explicit regulatory triggers.
        $type = strtolower((string) ($incident->type ?? ''));
        if (str_contains($type, 'death')) {
            return 'death';
        }
        if (str_contains($type, 'serious_harm') || $incident->severity === 'critical') {
            return 'serious_harm';
        }
        if (str_contains($type, 'breach') || str_contains($type, 'regulatory')) {
            return 'regulatory_breach';
        }

        return 'serious_harm'; // sensible default for auto-escalated incidents
    }

    private function resolveChairUserId(): ?int
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'board_chair'))
            ->orderBy('id')
            ->value('id');
    }

    private function resolveCeoUserId(): ?int
    {
        $roleNames = ['ceo', 'admin'];

        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roleNames))
            ->orderBy('id')
            ->value('id');
    }
}
