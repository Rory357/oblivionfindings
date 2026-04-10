<?php

namespace App\Services\HealthSafety;

use App\Enums\AlertSeverity;
use App\Models\ControlRoom\SignalSource;
use App\Services\ControlRoom\SignalProcessingService;
use Illuminate\Support\Facades\Log;

/**
 * Canonical H&S signal emission service.
 *
 * Emits signals for H&S compliance monitoring events:
 * - Overdue investigations
 * - Overdue corrective actions
 * - Risk assessment reviews due
 * - Emergency drill failures
 *
 * NOTE: The 6 existing H&S observers (incident, safeguarding, injury, restraint,
 * hazard, fleet incident) continue to use ComprehensiveAlertBridgeService for now.
 * This service covers the MONITORING layer — scheduled detection of stale/overdue items.
 */
class HsSignalService
{
    public const TYPE_INVESTIGATION_OVERDUE = 'hs_investigation_overdue';
    public const TYPE_CORRECTIVE_ACTION_OVERDUE = 'hs_corrective_action_overdue';
    public const TYPE_RISK_REVIEW_OVERDUE = 'hs_risk_review_overdue';
    public const TYPE_DRILL_FAILURE = 'hs_drill_failure';

    protected ?SignalSource $signalSource = null;

    public function __construct(
        protected SignalProcessingService $signalProcessor,
    ) {}

    /**
     * Emit signal for an overdue investigation.
     */
    public function emitInvestigationOverdue(
        int $investigationId,
        string $referenceNumber,
        int $daysOverdue,
        ?int $siteId = null,
        array $extraContext = [],
    ): void {
        $severity = $daysOverdue >= 7 ? AlertSeverity::HIGH : AlertSeverity::MEDIUM;

        $this->emit(
            self::TYPE_INVESTIGATION_OVERDUE,
            "Investigation overdue: {$referenceNumber} ({$daysOverdue} days)",
            $severity,
            array_merge([
                'hs_investigation_id' => $investigationId,
                'reference_number' => $referenceNumber,
                'days_overdue' => $daysOverdue,
                'site_id' => $siteId,
            ], $extraContext),
            $siteId,
            "investigation_{$investigationId}",
        );
    }

    /**
     * Emit signal for an overdue corrective action.
     */
    public function emitCorrectiveActionOverdue(
        int $actionId,
        string $referenceNumber,
        int $daysOverdue,
        string $priority,
        ?int $siteId = null,
        array $extraContext = [],
    ): void {
        // High-priority overdue actions escalate faster
        $severity = match (true) {
            $priority === 'critical' => AlertSeverity::HIGH,
            $daysOverdue >= 7 => AlertSeverity::HIGH,
            default => AlertSeverity::MEDIUM,
        };

        $this->emit(
            self::TYPE_CORRECTIVE_ACTION_OVERDUE,
            "Corrective action overdue: {$referenceNumber} ({$daysOverdue} days, {$priority} priority)",
            $severity,
            array_merge([
                'hs_corrective_action_id' => $actionId,
                'reference_number' => $referenceNumber,
                'days_overdue' => $daysOverdue,
                'priority' => $priority,
                'site_id' => $siteId,
            ], $extraContext),
            $siteId,
            "corrective_action_{$actionId}",
        );
    }

    /**
     * Emit signal for a risk assessment past its review date.
     */
    public function emitRiskReviewOverdue(
        int $assessmentId,
        string $referenceNumber,
        int $daysOverdue,
        string $riskLevel,
        ?int $siteId = null,
        array $extraContext = [],
    ): void {
        // High/extreme risk assessments overdue for review are more urgent
        $severity = in_array($riskLevel, ['high', 'extreme'], true)
            ? AlertSeverity::HIGH
            : AlertSeverity::MEDIUM;

        $this->emit(
            self::TYPE_RISK_REVIEW_OVERDUE,
            "Risk assessment review overdue: {$referenceNumber} ({$daysOverdue} days, {$riskLevel} risk)",
            $severity,
            array_merge([
                'hs_risk_assessment_id' => $assessmentId,
                'reference_number' => $referenceNumber,
                'days_overdue' => $daysOverdue,
                'risk_level' => $riskLevel,
                'site_id' => $siteId,
            ], $extraContext),
            $siteId,
            "risk_assessment_{$assessmentId}",
        );
    }

    /**
     * Emit signal for a failed emergency drill.
     */
    public function emitDrillFailure(
        int $drillId,
        string $drillType,
        string $title,
        ?int $siteId = null,
        array $extraContext = [],
    ): void {
        $this->emit(
            self::TYPE_DRILL_FAILURE,
            "Emergency drill failed: {$title} ({$drillType})",
            AlertSeverity::MEDIUM,
            array_merge([
                'emergency_drill_id' => $drillId,
                'drill_type' => $drillType,
                'title' => $title,
                'site_id' => $siteId,
            ], $extraContext),
            $siteId,
            "drill_{$drillId}",
        );
    }

    /**
     * Core signal emission method.
     */
    protected function emit(
        string $signalType,
        string $message,
        string $severity,
        array $context,
        ?int $siteId = null,
        ?string $entityKey = null,
    ): void {
        $source = $this->getSignalSource();

        // Daily dedup window — one signal per entity per day
        $window = now()->format('Y-m-d');
        $idempotencyKey = hash('sha256', implode('|', [
            'hs_monitoring',
            $signalType,
            $entityKey ?? 'unknown',
            $window,
        ]));

        $signalData = [
            'signal_source_id' => $source?->id,
            'signal_type_code' => $signalType,
            'idempotency_key' => $idempotencyKey,
            'site_id' => $siteId,
            'severity_hint' => $severity,
            'occurred_at' => now(),
            'payload' => [],
            'normalized_data' => array_merge([
                'title' => $message,
                'description' => $message,
                'source_module' => 'health_safety',
                'signal_type' => $signalType,
            ], $context),
        ];

        try {
            $signal = $this->signalProcessor->ingest($signalData);
            $alert = $this->signalProcessor->process($signal);

            if ($alert) {
                Log::info('HsSignalService: alert created', [
                    'signal_type' => $signalType,
                    'alert_id' => $alert->id,
                    'severity' => $severity,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('HsSignalService: signal emission failed', [
                'signal_type' => $signalType,
                'severity' => $severity,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function getSignalSource(): ?SignalSource
    {
        if ($this->signalSource) {
            return $this->signalSource;
        }

        try {
            $this->signalSource = SignalSource::firstOrCreate(
                ['slug' => 'health_safety'],
                [
                    'name' => 'Health & Safety Monitoring',
                    'vendor' => 'internal',
                    'status' => 'active',
                    'config' => [],
                    'capabilities' => ['scheduled_checks', 'event_driven'],
                ]
            );
        } catch (\Throwable $e) {
            Log::error('HsSignalService: failed to resolve signal source', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->signalSource;
    }
}
