<?php

namespace App\Services\ControlRoom;

use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\MedicationError;
use App\Models\SafeguardingConcern;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;

class ComprehensiveAlertBridgeService
{
    // ─── Medication Alerts ──────────────────────────────────────

    /**
     * Bridge a medication dashboard alert into the Control Room.
     *
     * @param  string  $alertType  e.g. overdue, prn_over_limit, controlled_discrepancy, expired, stock_low
     * @param  string  $severity   'warning' or 'critical'
     * @param  int     $clientId
     * @param  array   $context    Additional context data
     */
    public function bridgeMedicationAlert(string $alertType, string $severity, int $clientId, array $context = []): ?ControlRoomAlert
    {
        $severityMap = [
            'warning'  => 'medium',
            'critical' => 'critical',
        ];

        return $this->createBridgedAlert([
            'source'     => 'medication',
            'alert_type' => "medication.{$alertType}",
            'severity'   => $severityMap[$severity] ?? 'medium',
            'client_id'  => $clientId,
            'context'    => array_merge($context, [
                'original_severity' => $severity,
            ]),
        ]);
    }

    /**
     * Bridge a medication error into the Control Room.
     * Only errors with severity 'major' or 'critical' are bridged.
     */
    public function bridgeMedicationError(MedicationError $error): ?ControlRoomAlert
    {
        if (! in_array($error->severity, ['major', 'critical'], true)) {
            return null;
        }

        $severityMap = [
            'major'    => 'high',
            'critical' => 'critical',
        ];

        return $this->createBridgedAlert([
            'source'     => 'medication',
            'alert_type' => "medication.error.{$error->error_type}",
            'severity'   => $severityMap[$error->severity],
            'client_id'  => $error->client_id,
            'context'    => [
                'medication_error_id'    => $error->id,
                'error_type'             => $error->error_type,
                'original_severity'      => $error->severity,
                'client_medication_id'   => $error->client_medication_id,
                'description'            => $error->description,
            ],
        ]);
    }

    // ─── Client Incidents ───────────────────────────────────────

    /**
     * Bridge a client incident into the Control Room.
     * Only incidents with severity 'high' or 'critical' are bridged.
     */
    public function bridgeClientIncident(ClientIncident $incident): ?ControlRoomAlert
    {
        if (! in_array($incident->severity, ['high', 'critical'], true)) {
            return null;
        }

        return $this->createBridgedAlert([
            'source'     => 'incident',
            'alert_type' => "incident.{$incident->type}",
            'severity'   => $incident->severity,
            'client_id'  => $incident->client_id,
            'context'    => [
                'incident_id'   => $incident->id,
                'incident_type' => $incident->type,
                'occurred_at'   => $incident->occurred_at?->toIso8601String(),
                'description'   => $incident->description,
                'reported_by'   => $incident->reported_by,
            ],
        ]);
    }

    // ─── Safeguarding Concerns ──────────────────────────────────

    /**
     * Bridge a safeguarding concern into the Control Room.
     * All safeguarding concerns are bridged; severity is at minimum 'high'.
     */
    public function bridgeSafeguardingConcern(SafeguardingConcern $concern): ?ControlRoomAlert
    {
        $severity = $concern->severity === 'critical' ? 'critical' : 'high';

        return $this->createBridgedAlert([
            'source'     => 'safeguarding',
            'alert_type' => "safeguarding.{$concern->concern_type}",
            'severity'   => $severity,
            'client_id'  => $concern->subject_type === 'App\\Models\\Client' ? $concern->subject_id : null,
            'site_id'    => $concern->site_id,
            'context'    => [
                'concern_id'        => $concern->id,
                'reference_number'  => $concern->reference_number,
                'concern_type'      => $concern->concern_type,
                'abuse_category'    => $concern->abuse_category,
                'original_severity' => $concern->severity,
                'subject_name'      => $concern->subject_name,
                'reported_at'       => $concern->reported_at?->toIso8601String(),
                'current_risk_level' => $concern->current_risk_level,
            ],
        ]);
    }

    // ─── Compliance Expiry ──────────────────────────────────────

    /**
     * Bridge a compliance expiry event into the Control Room.
     *
     * @param  string   $type      e.g. training_expired, dbs_expired, consent_expired, care_plan_overdue
     * @param  string   $severity  low|medium|high|critical
     * @param  int|null $clientId
     * @param  int|null $userId    Staff member whose compliance item is expiring
     * @param  array    $context   Additional context data
     */
    public function bridgeComplianceExpiry(string $type, string $severity, ?int $clientId, ?int $userId, array $context = []): ?ControlRoomAlert
    {
        return $this->createBridgedAlert([
            'source'     => 'compliance',
            'alert_type' => "compliance.{$type}",
            'severity'   => $severity,
            'client_id'  => $clientId,
            'context'    => array_merge($context, [
                'user_id' => $userId,
            ]),
        ]);
    }

    // ─── Break Glass Access ─────────────────────────────────────

    /**
     * Bridge a break-glass access event into the Control Room.
     */
    public function bridgeBreakGlassAccess(ClientBreakGlassAccess $access): ?ControlRoomAlert
    {
        $userName   = $access->relationLoaded('user') ? $access->user?->name : null;
        $clientName = $access->relationLoaded('client') ? $access->client?->name : null;

        return $this->createBridgedAlert([
            'source'     => 'compliance',
            'alert_type' => 'compliance.break_glass',
            'severity'   => 'high',
            'client_id'  => $access->client_id,
            'context'    => [
                'break_glass_id' => $access->id,
                'user_id'        => $access->user_id,
                'user_name'      => $userName,
                'client_name'    => $clientName,
                'reason'         => $access->reason,
                'expires_at'     => $access->expires_at?->toIso8601String(),
            ],
        ]);
    }

    // ─── Operational Alerts ─────────────────────────────────────

    /**
     * Bridge a generic operational alert into the Control Room.
     *
     * @param  string  $type      e.g. unassigned_shift, evv_violation, overdue_timesheet
     * @param  string  $severity  low|medium|high|critical
     * @param  array   $context   Additional context data (should include client_id/site_id if applicable)
     */
    public function bridgeOperationalAlert(string $type, string $severity, array $context = []): ?ControlRoomAlert
    {
        return $this->createBridgedAlert([
            'source'     => 'operations',
            'alert_type' => "operations.{$type}",
            'severity'   => $severity,
            'client_id'  => $context['client_id'] ?? null,
            'site_id'    => $context['site_id'] ?? null,
            'context'    => $context,
        ]);
    }

    // ─── Governance Risk Escalation ─────────────────────────────

    /**
     * Bridge a governance risk into the Control Room.
     * Only risks with residual_score >= 15 (high) or >= 20 (critical) are bridged.
     */
    public function bridgeGovernanceRisk(RiskRegisterEntry $risk): ?ControlRoomAlert
    {
        $severity = $this->mapRiskScoreToSeverity($risk->residual_score);

        if (! in_array($severity, ['high', 'critical'], true)) {
            return null;
        }

        return $this->createBridgedAlert([
            'source'     => 'governance',
            'alert_type' => 'governance.risk_escalation',
            'severity'   => $severity,
            'context'    => [
                'risk_id'              => $risk->id,
                'risk_reference'       => $risk->risk_reference,
                'title'                => $risk->title,
                'category'             => $risk->category,
                'residual_score'       => $risk->residual_score,
                'inherent_score'       => $risk->inherent_score,
                'within_appetite'      => $risk->within_appetite,
                'control_effectiveness' => $risk->control_effectiveness,
                'risk_owner_id'        => $risk->risk_owner_id,
            ],
        ]);
    }

    // ─── Core Helper ────────────────────────────────────────────

    /**
     * Create a bridged alert with deduplication, queue assignment, and SLA tracking.
     *
     * @param  array  $data  Must include: source, alert_type, severity. Optional: client_id, site_id, asset_id, context.
     * @return ControlRoomAlert|null  Null if a duplicate was found within the dedup window.
     */
    private function createBridgedAlert(array $data): ?ControlRoomAlert
    {
        $source    = $data['source'];
        $alertType = $data['alert_type'];
        $severity  = $data['severity'];
        $clientId  = $data['client_id'] ?? null;
        $siteId    = $data['site_id'] ?? null;
        $assetId   = $data['asset_id'] ?? null;
        $context   = $data['context'] ?? [];

        // ── Deduplication: same source + alert_type + entity key within 30 minutes ──
        // Escalation bypass: if the caller signals a severity escalation,
        // only suppress if an alert of equal-or-higher severity already exists.
        $isEscalation = ! empty($context['severity_escalation']);

        $dedupQuery = ControlRoomAlert::query()
            ->where('source', $source)
            ->where('alert_type', $alertType)
            ->where('triggered_at', '>=', now()->subMinutes(30));

        if ($clientId) {
            $dedupQuery->where('client_id', $clientId);
        } elseif ($assetId) {
            $dedupQuery->where('asset_id', $assetId);
        } else {
            $dedupQuery->whereNull('client_id')->whereNull('asset_id');
        }

        if ($isEscalation) {
            // Only suppress if an alert at this severity or higher already exists
            $severityOrder = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];
            $currentRank = $severityOrder[$severity] ?? 0;
            $atOrAbove = array_keys(array_filter($severityOrder, fn ($r) => $r >= $currentRank));
            $dedupQuery->whereIn('severity', $atOrAbove);
        }

        if ($dedupQuery->exists()) {
            Log::debug('ComprehensiveAlertBridge: duplicate suppressed', [
                'source'     => $source,
                'alert_type' => $alertType,
                'client_id'  => $clientId,
                'asset_id'   => $assetId,
                'is_escalation' => $isEscalation,
            ]);

            return null;
        }

        // ── Create the Control Room alert ──
        $alert = ControlRoomAlert::create([
            'source'       => $source,
            'alert_type'   => $alertType,
            'severity'     => $severity,
            'status'       => 'open',
            'client_id'    => $clientId,
            'site_id'      => $siteId,
            'asset_id'     => $assetId,
            'triggered_at' => now(),
            'context'      => $context,
        ]);

        // ── Assign to triage queue ──
        $queue = TriageQueue::findForAlert($severity, $source, $alertType);

        if ($queue) {
            $alert->update(['queue_id' => $queue->id]);

            AlertQueue::create([
                'alert_id'   => $alert->id,
                'queue_id'   => $queue->id,
                'entered_at' => now(),
            ]);
        }

        // ── Attach SLA ──
        $slaDefinition = SlaDefinition::findForAlert($alertType, $severity, $source);

        if ($slaDefinition) {
            AlertSla::createFromDefinition($alert, $slaDefinition);
        }

        // ── Audit & log ──
        AuditLogger::log('control_room.alert_bridged', $alert, [
            'source'     => $source,
            'alert_type' => $alertType,
            'severity'   => $severity,
            'client_id'  => $clientId,
        ]);

        Log::info('ComprehensiveAlertBridge: alert created', [
            'alert_id'   => $alert->id,
            'source'     => $source,
            'alert_type' => $alertType,
            'severity'   => $severity,
            'queue_id'   => $queue?->id,
            'has_sla'    => $slaDefinition !== null,
        ]);

        return $alert;
    }

    /**
     * Map a governance risk residual score to a control-room severity.
     *
     * Score thresholds (likelihood x impact, 1-5 each, max 25):
     *   >= 20 => critical
     *   >= 15 => high
     *   >= 8  => medium
     *   <  8  => low
     */
    private function mapRiskScoreToSeverity(int $score): string
    {
        return match (true) {
            $score >= 20 => 'critical',
            $score >= 15 => 'high',
            $score >= 8  => 'medium',
            default      => 'low',
        };
    }
}
