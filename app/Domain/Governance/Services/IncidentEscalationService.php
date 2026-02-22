<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\IncidentGovernanceEscalation;
use App\Domain\Governance\Models\RiskRegisterEntry;

class IncidentEscalationService
{
    const SEVERITY_THRESHOLDS = [
        'critical' => ['auto_escalate' => true, 'escalate_to' => 'board_chair', 'timeframe_hours' => 1],
        'major' => ['auto_escalate' => true, 'escalate_to' => 'ceo', 'timeframe_hours' => 4],
        'moderate' => ['auto_escalate' => false, 'escalate_to' => 'manager', 'timeframe_hours' => 24],
        'minor' => ['auto_escalate' => false, 'escalate_to' => null, 'timeframe_hours' => null],
    ];

    public function shouldAutoEscalate(string $severity): bool
    {
        return self::SEVERITY_THRESHOLDS[$severity]['auto_escalate'] ?? false;
    }

    public function escalateIncident(
        string $incidentType,
        int $incidentId,
        string $severity,
        string $description,
        int $escalatedBy,
        ?int $riskId = null
    ): IncidentGovernanceEscalation {
        $threshold = self::SEVERITY_THRESHOLDS[$severity] ?? self::SEVERITY_THRESHOLDS['minor'];

        $escalation = IncidentGovernanceEscalation::create([
            'incident_type' => $incidentType,
            'incident_id' => $incidentId,
            'severity' => $severity,
            'description' => $description,
            'escalated_to' => $threshold['escalate_to'],
            'escalated_by' => $escalatedBy,
            'escalated_at' => now(),
            'risk_register_entry_id' => $riskId,
            'response_required_by' => $threshold['timeframe_hours']
                ? now()->addHours($threshold['timeframe_hours'])
                : null,
            'status' => 'open',
        ]);

        // Auto-link to risk register if risk_id provided
        if ($riskId) {
            $risk = RiskRegisterEntry::find($riskId);
            if ($risk) {
                $risk->events()->create([
                    'event_type' => 'incident',
                    'event_id' => $incidentId,
                    'event_reference' => "ESC-{$escalation->id}",
                    'event_severity' => $severity,
                    'event_occurred_at' => now(),
                    'link_rationale' => "Auto-linked from governance escalation",
                    'linked_by' => $escalatedBy,
                    'linked_at' => now(),
                ]);
            }
        }

        return $escalation;
    }

    public function acknowledgeEscalation(IncidentGovernanceEscalation $escalation, int $userId, ?string $notes = null): void
    {
        $escalation->update([
            'acknowledged_by' => $userId,
            'acknowledged_at' => now(),
            'status' => 'acknowledged',
            'response_notes' => $notes,
        ]);
    }

    public function resolveEscalation(IncidentGovernanceEscalation $escalation, int $userId, string $resolution, ?string $boardActionRequired = null): void
    {
        $escalation->update([
            'resolved_by' => $userId,
            'resolved_at' => now(),
            'status' => 'resolved',
            'resolution_summary' => $resolution,
            'board_action_required' => $boardActionRequired,
        ]);
    }

    public function getOpenEscalations(): array
    {
        return IncidentGovernanceEscalation::whereIn('status', ['open', 'acknowledged'])
            ->orderByDesc('escalated_at')
            ->get()
            ->toArray();
    }
}
