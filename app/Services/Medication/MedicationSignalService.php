<?php

namespace App\Services\Medication;

use App\Enums\AlertSeverity;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Services\ControlRoom\SignalProcessingService;
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
    public const TYPE_EXPIRED = 'medication_expired';
    public const TYPE_STOCK_OUT = 'medication_stock_out';
    public const TYPE_ERROR = 'medication_error';

    // --- Canonical severity mapping ---
    // Only operational alerts use these — routine items stay out of CR
    public const SEVERITY_MAP = [
        self::TYPE_CONTROLLED_DISCREPANCY => AlertSeverity::CRITICAL,
        self::TYPE_PRN_OVER_LIMIT => AlertSeverity::CRITICAL,
        self::TYPE_OVERDUE => AlertSeverity::HIGH,
        self::TYPE_EXPIRED => AlertSeverity::HIGH,
        self::TYPE_STOCK_OUT => AlertSeverity::HIGH,
        self::TYPE_ERROR => AlertSeverity::HIGH,       // overridden by error severity
        self::TYPE_MISSED_DOSE => AlertSeverity::MEDIUM,
        self::TYPE_LATE_DOSE => AlertSeverity::MEDIUM,  // overridden by lateness
    ];

    protected ?SignalSource $signalSource = null;

    public function __construct(
        protected SignalProcessingService $signalProcessor,
    ) {}

    /**
     * Emit a medication signal into the Control Room pipeline.
     *
     * @param string $signalType One of the TYPE_* constants
     * @param int $clientId Client affected
     * @param string $severity Canonical severity (low/medium/high/critical)
     * @param string $message Operator-facing summary
     * @param array $context Additional medication context for traceability
     */
    public function emit(
        string $signalType,
        int $clientId,
        string $severity,
        string $message,
        array $context = [],
    ): void {
        $source = $this->getSignalSource();

        $idempotencyKey = $this->buildIdempotencyKey(
            $signalType,
            $clientId,
            $context['client_medication_id'] ?? null,
            $context['administration_id'] ?? null,
        );

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

        try {
            $signal = $this->signalProcessor->ingest($signalData);
            $alert = $this->signalProcessor->process($signal);

            if ($alert) {
                Log::info('MedicationSignalService: alert created', [
                    'signal_type' => $signalType,
                    'alert_id' => $alert->id,
                    'severity' => $severity,
                    'client_id' => $clientId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('MedicationSignalService: signal emission failed', [
                'signal_type' => $signalType,
                'client_id' => $clientId,
                'severity' => $severity,
                'error' => $e->getMessage(),
            ]);
        }
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
     * @param \App\Models\MedicationError $error The medication error record
     */
    public function emitError(\App\Models\MedicationError $error): void
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
            'Medication error: ' . str_replace('_', ' ', $error->error_type)
                . ($medication ? " — {$medication->name}" : ''),
            [
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
        ?int $medicationId,
        ?int $administrationId = null,
    ): string {
        $window = now()->format('Y-m-d H:') . (intdiv((int) now()->format('i'), 30) * 30);

        $parts = [
            'medication',
            $signalType,
            $clientId,
            $medicationId ?? 'all',
            $administrationId ?? 'check',
            $window,
        ];

        return hash('sha256', implode('|', $parts));
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
}
