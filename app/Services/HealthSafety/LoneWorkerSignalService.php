<?php

namespace App\Services\HealthSafety;

use App\Enums\AlertSeverity;
use App\Models\ControlRoom\SignalSource;
use App\Models\LoneWorkerSession;
use App\Services\ControlRoom\SignalProcessingService;
use Illuminate\Support\Facades\Log;

/**
 * Canonical lone worker signal emission service.
 *
 * This is the SINGLE place where lone worker safety events enter
 * the Control Room signal pipeline. All lone worker operational alerts
 * must flow through this service.
 *
 * Flow: lone worker event → LoneWorkerSignalService → SignalProcessingService → ControlRoomAlert
 */
class LoneWorkerSignalService
{
    public const TYPE_EMERGENCY = 'lone_worker_emergency';
    public const TYPE_OVERDUE_CHECKIN = 'lone_worker_overdue_checkin';
    public const TYPE_SESSION_OVERRUN = 'lone_worker_session_overrun';

    protected ?SignalSource $signalSource = null;

    public function __construct(
        protected SignalProcessingService $signalProcessor,
    ) {}

    /**
     * Emit a lone worker emergency signal (SOS / distress / manual trigger).
     */
    public function emitEmergency(LoneWorkerSession $session, ?string $notes = null): void
    {
        $session->loadMissing(['user:id,name', 'site:id,name', 'client:id,first_name,last_name']);

        $this->emit(
            self::TYPE_EMERGENCY,
            $session,
            AlertSeverity::CRITICAL,
            'Lone worker emergency: ' . ($session->user?->name ?? 'Unknown worker'),
            [
                'emergency_notes' => $notes,
                'emergency_triggered_at' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * Emit a lone worker overdue check-in signal.
     */
    public function emitOverdueCheckIn(LoneWorkerSession $session, int $minutesOverdue): void
    {
        $session->loadMissing(['user:id,name', 'site:id,name', 'client:id,first_name,last_name']);

        // Severity escalates with time: >60min = critical (life-safety), else = high
        $severity = $minutesOverdue >= 60 ? AlertSeverity::CRITICAL : AlertSeverity::HIGH;

        $this->emit(
            self::TYPE_OVERDUE_CHECKIN,
            $session,
            $severity,
            "Lone worker check-in overdue ({$minutesOverdue}min): " . ($session->user?->name ?? 'Unknown'),
            [
                'minutes_overdue' => $minutesOverdue,
                'last_check_in_at' => $session->last_check_in_at?->toIso8601String(),
                'check_in_interval_minutes' => $session->check_in_interval_minutes,
            ],
        );
    }

    /**
     * Emit a lone worker session overrun signal (past expected end time).
     */
    public function emitSessionOverrun(LoneWorkerSession $session, int $minutesOverrun): void
    {
        $session->loadMissing(['user:id,name', 'site:id,name', 'client:id,first_name,last_name']);

        $severity = $minutesOverrun >= 60 ? AlertSeverity::HIGH : AlertSeverity::MEDIUM;

        $this->emit(
            self::TYPE_SESSION_OVERRUN,
            $session,
            $severity,
            "Lone worker session overrun ({$minutesOverrun}min): " . ($session->user?->name ?? 'Unknown'),
            [
                'minutes_overrun' => $minutesOverrun,
                'expected_end_at' => $session->expected_end_at?->toIso8601String(),
            ],
        );
    }

    /**
     * Core signal emission method.
     */
    protected function emit(
        string $signalType,
        LoneWorkerSession $session,
        string $severity,
        string $message,
        array $extraContext = [],
    ): void {
        $source = $this->getSignalSource();

        $idempotencyKey = $this->buildIdempotencyKey($signalType, $session);

        $signalData = [
            'signal_source_id' => $source?->id,
            'signal_type_code' => $signalType,
            'idempotency_key' => $idempotencyKey,
            'site_id' => $session->site_id,
            'client_id' => $session->client_id,
            'severity_hint' => $severity,
            'occurred_at' => now(),
            'payload' => [],
            'normalized_data' => array_merge([
                'title' => $message,
                'description' => $message,
                'source_module' => 'lone_worker',
                'signal_type' => $signalType,
                // Worker context
                'lone_worker_session_id' => $session->id,
                'worker_user_id' => $session->user_id,
                'worker_name' => $session->user?->name,
                'site_id' => $session->site_id,
                'site_name' => $session->site?->name,
                'client_id' => $session->client_id,
                'client_name' => $session->client
                    ? trim($session->client->first_name . ' ' . $session->client->last_name)
                    : null,
                // Session context
                'location' => $session->location,
                'location_lat' => $session->location_lat,
                'location_lng' => $session->location_lng,
                'activity_description' => $session->activity_description,
                'started_at' => $session->started_at?->toIso8601String(),
                'expected_end_at' => $session->expected_end_at?->toIso8601String(),
            ], $extraContext),
        ];

        try {
            $signal = $this->signalProcessor->ingest($signalData);
            $alert = $this->signalProcessor->process($signal);

            if ($alert) {
                Log::info('LoneWorkerSignalService: alert created', [
                    'signal_type' => $signalType,
                    'alert_id' => $alert->id,
                    'severity' => $severity,
                    'session_id' => $session->id,
                    'worker' => $session->user?->name,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('LoneWorkerSignalService: signal emission failed', [
                'signal_type' => $signalType,
                'session_id' => $session->id,
                'severity' => $severity,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build idempotency key. Uses 15-minute windows for emergencies,
     * 30-minute windows for overdue check-ins and session overruns.
     */
    protected function buildIdempotencyKey(string $signalType, LoneWorkerSession $session): string
    {
        $windowMinutes = $signalType === self::TYPE_EMERGENCY ? 15 : 30;
        $window = now()->format('Y-m-d H:') . (intdiv((int) now()->format('i'), $windowMinutes) * $windowMinutes);

        return hash('sha256', implode('|', [
            'lone_worker',
            $signalType,
            $session->id,
            $session->user_id,
            $window,
        ]));
    }

    protected function getSignalSource(): ?SignalSource
    {
        if ($this->signalSource) {
            return $this->signalSource;
        }

        try {
            $this->signalSource = SignalSource::firstOrCreate(
                ['slug' => 'lone_worker'],
                [
                    'name' => 'Lone Worker Safety',
                    'vendor' => 'internal',
                    'status' => 'active',
                    'config' => [],
                    'capabilities' => ['manual_trigger', 'scheduled_checks'],
                ]
            );
        } catch (\Throwable $e) {
            Log::error('LoneWorkerSignalService: failed to resolve signal source', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->signalSource;
    }
}
