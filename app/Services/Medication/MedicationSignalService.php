<?php

namespace App\Services\Medication;

use App\Enums\AlertSeverity;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\MedicationError;
use App\Services\AuditLogger;
use App\Services\ControlRoom\SignalProcessingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Canonical medication signal emission service.
 *
 * This is the SINGLE place where medication operational events enter
 * the Control Room signal pipeline. All medication safety alerts
 * must flow through this service.
 *
 * Flow: medication event → MedicationSignalService::emit() → SignalProcessingService → ControlRoomAlert
 *
 * Non-operational items (stock warnings, expiry reminders, near-limits)
 * should NOT use this service — they remain as dashboard/notification items.
 */
class MedicationSignalService
{
    // --- Signal type codes ---
    public const TYPE_OVERDUE = 'medication_overdue';

    public const TYPE_MISSED_DOSE = 'medication_missed_dose';

    public const TYPE_LATE_DOSE = 'medication_late_dose';

    public const TYPE_PRN_OVER_LIMIT = 'medication_prn_over_limit';

    public const TYPE_CONTROLLED_DISCREPANCY = 'medication_controlled_discrepancy';

    public const TYPE_REFUSED_DOSE = 'medication_refused_dose';

    public const TYPE_REFUSAL_ESCALATION = 'medication_refusal_escalation';

    public const TYPE_UNSAFE_CORRECTION = 'medication_unsafe_correction';

    public const TYPE_CONTROLLED_LOSS = 'medication_controlled_loss';

    public const TYPE_TRANSIT_EXCEPTION = 'medication_transit_exception';

    public const TYPE_EXPIRED = 'medication_expired';

    public const TYPE_STOCK_OUT = 'medication_stock_out';

    public const TYPE_ERROR = 'medication_error';

    // --- Canonical severity mapping ---
    // Only operational alerts use these — routine items stay out of CR
    public const SEVERITY_MAP = [
        self::TYPE_CONTROLLED_DISCREPANCY => AlertSeverity::CRITICAL,
        self::TYPE_CONTROLLED_LOSS => AlertSeverity::CRITICAL,
        self::TYPE_PRN_OVER_LIMIT => AlertSeverity::CRITICAL,
        self::TYPE_TRANSIT_EXCEPTION => AlertSeverity::HIGH,
        self::TYPE_OVERDUE => AlertSeverity::HIGH,
        self::TYPE_REFUSAL_ESCALATION => AlertSeverity::HIGH,
        self::TYPE_EXPIRED => AlertSeverity::HIGH,
        self::TYPE_STOCK_OUT => AlertSeverity::HIGH,
        self::TYPE_ERROR => AlertSeverity::HIGH,       // overridden by error severity
        self::TYPE_UNSAFE_CORRECTION => AlertSeverity::MEDIUM,
        self::TYPE_MISSED_DOSE => AlertSeverity::MEDIUM,
        self::TYPE_LATE_DOSE => AlertSeverity::MEDIUM,  // overridden by lateness
        self::TYPE_REFUSED_DOSE => AlertSeverity::MEDIUM,
    ];

    protected ?SignalSource $signalSource = null;

    public function __construct(
        protected SignalProcessingService $signalProcessor,
    ) {}

    /**
     * Emit a medication signal into the Control Room pipeline.
     *
     * @param  string  $signalType  One of the TYPE_* constants
     * @param  int  $clientId  Client affected
     * @param  string  $severity  Canonical severity (low/medium/high/critical)
     * @param  string  $message  Operator-facing summary
     * @param  array  $context  Additional medication context for traceability
     */
    public function emit(
        string $signalType,
        int $clientId,
        string $severity,
        string $message,
        array $context = [],
    ): void {
        $signal = null;
        $hasIncidentClaim = $this->hasIncidentClaim($context);
        $incidentIdentity = $context['incident_id'] ?? null;

        $idempotencyKey = $this->buildIdempotencyKey(
            $signalType,
            $clientId,
            $context,
        );

        try {
            $operation = function () use (
                $signalType,
                $clientId,
                $severity,
                $message,
                $context,
                $idempotencyKey,
                $hasIncidentClaim,
                $incidentIdentity,
                &$signal,
            ): void {
                $source = $this->getSignalSource();

                if ($hasIncidentClaim && $source === null) {
                    throw new \RuntimeException('Medication signal source is unavailable for an incident journey.');
                }

                $signalData = [
                    'signal_source_id' => $source?->id,
                    'signal_type_code' => $signalType,
                    'idempotency_key' => $idempotencyKey,
                    'site_id' => $context['site_id'] ?? null,
                    'client_id' => $clientId,
                    'severity_hint' => $severity,
                    'occurred_at' => $context['occurred_at'] ?? now(),
                    'payload' => [],
                    'normalized_data' => array_merge([
                        'title' => $message,
                        'description' => $message,
                        'source_module' => 'medication',
                        'signal_type' => $signalType,
                        'client_id' => $clientId,
                    ], $context),
                ];

                $signal = $this->signalProcessor->ingest($signalData);
                $alert = $this->signalProcessor->process($signal);

                if ($alert !== null && $hasIncidentClaim) {
                    $this->attachSignalToIncidentAlert($signal, $alert, (int) $incidentIdentity);
                }

                if ($alert) {
                    Log::info('MedicationSignalService: alert created', [
                        'signal_type' => $signalType,
                        'alert_id' => $alert->id,
                        'severity' => $severity,
                        'client_id' => $clientId,
                    ]);
                }
            };

            if ($hasIncidentClaim) {
                DB::transaction($operation);
            } else {
                $operation();
            }
        } catch (\Throwable $exception) {
            if ($hasIncidentClaim) {
                $this->signalSource = null;
                Log::error('incident_journey_repair_required', [
                    'incident_id' => $this->incidentIdentityForLog($incidentIdentity),
                    'incident_id_type' => get_debug_type($incidentIdentity),
                    'signal_id' => $signal?->id,
                    'signal_type' => $signalType,
                    'signal_client_id' => $clientId,
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            Log::error('MedicationSignalService: signal emission failed', [
                'signal_type' => $signalType,
                'client_id' => $clientId,
                'severity' => $severity,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function hasIncidentClaim(array $context): bool
    {
        return array_key_exists('incident_id', $context)
            && $context['incident_id'] !== null
            && $context['incident_id'] !== '';
    }

    private function incidentIdentityForLog(mixed $identity): int|string|float|bool|null
    {
        return is_scalar($identity) || $identity === null
            ? $identity
            : '['.get_debug_type($identity).']';
    }

    /**
     * Emit a medication error signal into the Control Room pipeline.
     *
     * This is the CANONICAL path for medication errors reaching Control Room.
     * Do NOT use ComprehensiveAlertBridgeService::bridgeMedicationError() — it is deprecated.
     *
     * Only errors with severity 'major' or 'critical' create operational alerts.
     * Near-miss, minor, and moderate errors are tracked in the MedicationError
     * record and investigation workflow but do NOT enter Control Room.
     *
     * @param  MedicationError  $error  The medication error record
     */
    public function emitError(MedicationError $error): void
    {
        // Only major/critical medication errors are operational alerts
        if (! in_array($error->severity, ['major', 'critical'], true)) {
            return;
        }

        $severityMap = [
            'major' => 'high',
            'critical' => 'critical',
        ];

        $client = $error->client;
        $medication = $error->medication;

        $this->emit(
            self::TYPE_ERROR,
            $error->client_id,
            $severityMap[$error->severity],
            'Medication error: '.str_replace('_', ' ', $error->error_type)
                .($medication ? " — {$medication->name}" : ''),
            [
                'incident_id' => $error->client_incident_id,
                'medication_error_id' => $error->id,
                'client_medication_id' => $error->client_medication_id,
                'medication_name' => $medication?->name,
                'error_type' => $error->error_type,
                'original_severity' => $error->severity,
                'description' => $error->description,
                'immediate_action' => $error->immediate_action,
                'reported_by' => $error->reported_by,
                'reported_at' => $error->reported_at?->toIso8601String(),
                'site_id' => $client?->site_id,
                'controlled_drug' => $medication?->controlled_drug ?? false,
                'high_risk' => $medication?->high_risk ?? false,
            ],
        );
    }

    /**
     * Build a deterministic idempotency key for medication signals.
     *
     * Uses 30-minute precision so the same condition detected multiple times
     * within a 30-minute window produces the same key (dedup at signal level).
     */
    protected function buildIdempotencyKey(
        string $signalType,
        int $clientId,
        array $context = [],
    ): string {
        $occurredAt = isset($context['occurred_at'])
            ? Carbon::parse($context['occurred_at'])
            : now();
        $window = $occurredAt->format('Y-m-d H:').(intdiv((int) $occurredAt->format('i'), 30) * 30);
        $medicationId = $context['client_medication_id'] ?? null;
        [$entityType, $entityId] = $this->relatedEntityIdentity($context);

        $parts = [
            'medication',
            $signalType,
            $clientId,
            $medicationId ?? 'all',
            $entityType ?? 'check',
            $entityId ?? 'check',
            $window,
        ];

        return hash('sha256', implode('|', $parts));
    }

    protected function relatedEntityIdentity(array $context): array
    {
        foreach ([
            'medication_error_id' => 'medication_error',
            'discrepancy_id' => 'discrepancy',
            'loss_report_id' => 'loss_report',
            'followup_id' => 'followup',
            'correction_id' => 'correction',
            'administration_id' => 'administration',
            'transport_log_id' => 'transport_log',
            'incident_id' => 'client_incident',
            'client_medication_id' => 'medication',
        ] as $key => $type) {
            if (filled($context[$key] ?? null)) {
                return [$type, $context[$key]];
            }
        }

        return [null, null];
    }

    private function attachSignalToIncidentAlert(
        Signal $signal,
        ControlRoomAlert $alert,
        int $incidentId,
    ): void {
        $context = $alert->context ?? [];
        $normalizedData = array_replace_recursive(
            (array) ($context['normalized_data'] ?? []),
            (array) ($signal->normalized_data ?? []),
            ['incident_id' => $incidentId],
        );

        $alert->updateQuietly([
            'context' => array_replace($context, [
                'incident_id' => $incidentId,
                'signal_id' => $signal->id,
                'signal_type_code' => $signal->signal_type_code,
                'signal_payload' => $signal->payload,
                'normalized_data' => $normalizedData,
            ]),
        ]);
        $signal->markProcessed($alert, 'Attached to exact incident journey alert');
    }

    /**
     * Resolve the medication signal source (cached per request).
     */
    protected function getSignalSource(): ?SignalSource
    {
        if ($this->signalSource) {
            return $this->signalSource;
        }

        try {
            $this->signalSource = SignalSource::firstOrCreate(
                ['slug' => 'medication'],
                [
                    'name' => 'Medication / eMAR',
                    'vendor' => 'internal',
                    'status' => 'active',
                    'config' => [],
                    'capabilities' => ['scheduled_checks', 'event_driven'],
                ]
            );
        } catch (\Throwable $e) {
            Log::error('MedicationSignalService: failed to resolve signal source', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->signalSource;
    }

    public function resolveAlerts(
        string $signalType,
        array $matchContext,
        string $reason,
        string $resolutionSource = 'medication_workflow',
        array $metadata = [],
    ): int {
        $query = ControlRoomAlert::query()
            ->with('sla')
            ->unresolved()
            ->whereIn('source', ['medication', 'incident'])
            ->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(context, '$.signal_type_code')) = ?",
                [$signalType]
            );

        foreach ($matchContext as $key => $value) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', (string) $key) || $value === null || $value === '') {
                continue;
            }

            $query->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(context, '$.normalized_data.{$key}')) = ?",
                [(string) $value]
            );
        }

        $alerts = $query->get();
        $resolvedAt = now();
        $resolvedBy = $metadata['resolved_by_user_id'] ?? null;
        $resolutionMetadata = $metadata;
        unset($resolutionMetadata['resolved_by_user_id']);

        foreach ($alerts as $alert) {
            $context = $alert->context ?? [];
            $resolution = array_merge([
                'resolved_at' => $resolvedAt->toISOString(),
                'reason' => $reason,
                'source' => $resolutionSource,
            ], $resolutionMetadata);
            $history = $context['resolution_history'] ?? [];
            $history[] = $resolution;

            $alert->update([
                'status' => ControlRoomAlert::STATUS_RESOLVED,
                'resolved_at' => $resolvedAt,
                'resolved_by_user_id' => $resolvedBy,
                'notes' => $reason,
                'context' => array_merge($context, [
                    'resolution' => $resolution,
                    'resolution_history' => $history,
                ]),
            ]);

            $alert->sla?->recordResolution();

            AuditLogger::log('controlRoom.alert.resolve', $alert, [
                'source' => 'medication_signal_pipeline',
                'resolution_source' => $resolutionSource,
            ]);
        }

        return $alerts->count();
    }
}
