<?php

namespace App\Services\HealthSafety;

use App\Enums\AlertSeverity;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\LoneWorkerSession;
use App\Models\Site;
use App\Services\ControlRoom\SignalProcessingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
            'Lone worker emergency: '.($session->user?->name ?? 'Unknown worker'),
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
            "Lone worker check-in overdue ({$minutesOverdue}min): ".($session->user?->name ?? 'Unknown'),
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
            "Lone worker session overrun ({$minutesOverrun}min): ".($session->user?->name ?? 'Unknown'),
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
        $session->load([
            'user:id,name,organization_id',
            'site:id,name,tenant_id',
            'client:id,first_name,last_name,organization_id,site_id',
            'shift:id,organization_id,site_id,client_id,user_id',
            'shift.client:id,organization_id,site_id',
        ]);
        [$siteId, $siteName] = $this->canonicalSessionSite($session);
        $source = $this->getSignalSource();

        $idempotencyKey = $this->buildIdempotencyKey($signalType, $session);

        $signalData = [
            'signal_source_id' => $source?->id,
            'signal_type_code' => $signalType,
            'idempotency_key' => $idempotencyKey,
            'site_id' => $siteId,
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
                'site_id' => $siteId,
                'site_name' => $siteName,
                'client_id' => $session->client_id,
                'client_name' => $session->client
                    ? trim($session->client->first_name.' '.$session->client->last_name)
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

        $alert = DB::transaction(function () use (
            $signalData,
            $source,
            $signalType,
            $idempotencyKey,
            $session,
            $siteId,
        ): ControlRoomAlert {
            $signal = $this->signalProcessor->ingest($signalData);
            if (! $this->canonicalSignalMatchesSession(
                $signal,
                $source,
                $signalType,
                $idempotencyKey,
                $session,
                $siteId,
            )) {
                throw new RuntimeException(sprintf(
                    'Canonical signal does not match lone worker session %d.',
                    $session->id,
                ));
            }

            $alert = $this->signalProcessor->process($signal);
            if (! $alert || ! $this->canonicalAlertMatchesSession(
                $alert,
                $signal,
                $signalType,
                $session,
                $siteId,
            )) {
                throw new RuntimeException(sprintf(
                    'Canonical Control Room alert does not match lone worker session %d.',
                    $session->id,
                ));
            }

            return $alert;
        }, 3);

        Log::info('LoneWorkerSignalService: alert created', [
            'signal_type' => $signalType,
            'alert_id' => $alert->id,
            'severity' => $severity,
            'session_id' => $session->id,
            'worker' => $session->user?->name,
        ]);
    }

    public static function canonicalAlertType(string $signalType): ?string
    {
        return match ($signalType) {
            self::TYPE_EMERGENCY => 'Lone Worker Emergency',
            self::TYPE_OVERDUE_CHECKIN => 'Lone Worker Overdue Check-in',
            self::TYPE_SESSION_OVERRUN => 'Lone Worker Session Overrun',
            default => null,
        };
    }

    /** @return array{int, string} */
    private function canonicalSessionSite(LoneWorkerSession $session): array
    {
        $organizationId = $this->positiveId($session->user?->organization_id);
        if (! $session->user || $organizationId === null) {
            throw new RuntimeException('Lone worker signal requires a tenant-owned worker.');
        }

        $clientSiteId = null;
        if ($session->client_id !== null) {
            $clientSiteId = $this->positiveId($session->client?->site_id);
            if (! $session->client
                || $this->positiveId($session->client->organization_id) !== $organizationId
                || $clientSiteId === null) {
                throw new RuntimeException('Lone worker signal client provenance is invalid.');
            }
        }

        $shiftSiteId = null;
        $shiftClientSiteId = null;
        if ($session->shift_id !== null) {
            $shift = $session->shift;
            if (! $shift
                || $this->positiveId($shift->organization_id) !== $organizationId
                || $this->positiveId($shift->user_id) !== $this->positiveId($session->user_id)
                || ! $this->nullableIdMatches($shift->client_id, $this->positiveId($session->client_id))) {
                throw new RuntimeException('Lone worker signal shift provenance is invalid.');
            }

            if ($shift->client_id !== null) {
                $shiftClientSiteId = $this->positiveId($shift->client?->site_id);
                if (! $shift->client
                    || $this->positiveId($shift->client->organization_id) !== $organizationId
                    || $shiftClientSiteId === null) {
                    throw new RuntimeException('Lone worker signal shift client provenance is invalid.');
                }
            }

            $directShiftSiteId = $this->positiveId($shift->site_id);
            if ($directShiftSiteId !== null
                && $shiftClientSiteId !== null
                && $directShiftSiteId !== $shiftClientSiteId) {
                throw new RuntimeException('Lone worker signal shift site provenance is contradictory.');
            }
            $shiftSiteId = $directShiftSiteId ?? $shiftClientSiteId;
            if ($shiftSiteId === null) {
                throw new RuntimeException('Lone worker signal shift has no authoritative site.');
            }
        }

        $siteIds = collect([
            $this->positiveId($session->site_id),
            $clientSiteId,
            $shiftSiteId,
            $shiftClientSiteId,
        ])->filter()->unique()->values();
        if ($siteIds->count() !== 1) {
            throw new RuntimeException('Lone worker signal session has contradictory site provenance.');
        }

        $siteId = (int) $siteIds->first();
        $site = Site::query()
            ->whereKey($siteId)
            ->where('tenant_id', $organizationId)
            ->first();
        if (! $site) {
            throw new RuntimeException('Lone worker signal site does not belong to the worker tenant.');
        }

        return [$siteId, (string) $site->name];
    }

    private function canonicalSignalMatchesSession(
        Signal $signal,
        SignalSource $source,
        string $signalType,
        string $idempotencyKey,
        LoneWorkerSession $session,
        int $siteId,
    ): bool {
        $context = $signal->normalized_data;

        return is_array($context)
            && $this->positiveId($signal->signal_source_id) === (int) $source->id
            && $signal->signal_type_code === $signalType
            && hash_equals($idempotencyKey, (string) $signal->idempotency_key)
            && $this->positiveId($signal->site_id) === $siteId
            && $this->nullableIdMatches($signal->client_id, $this->positiveId($session->client_id))
            && ($context['source_module'] ?? null) === 'lone_worker'
            && ($context['signal_type'] ?? null) === $signalType
            && $this->positiveId($context['lone_worker_session_id'] ?? null) === (int) $session->id
            && $this->positiveId($context['worker_user_id'] ?? null) === (int) $session->user_id
            && $this->positiveId($context['site_id'] ?? null) === $siteId
            && array_key_exists('client_id', $context)
            && $this->nullableIdMatches($context['client_id'], $this->positiveId($session->client_id));
    }

    private function canonicalAlertMatchesSession(
        ControlRoomAlert $alert,
        Signal $signal,
        string $signalType,
        LoneWorkerSession $session,
        int $siteId,
    ): bool {
        $expectedAlertType = self::canonicalAlertType($signalType);
        $context = $alert->context;
        $normalized = data_get($context, 'normalized_data');

        return $expectedAlertType !== null
            && is_array($context)
            && is_array($normalized)
            && $alert->source === 'lone_worker'
            && $alert->alert_type === $expectedAlertType
            && $this->positiveId($alert->site_id) === $siteId
            && $this->nullableIdMatches($alert->client_id, $this->positiveId($session->client_id))
            && $this->positiveId($context['signal_id'] ?? null) === (int) $signal->id
            && ($context['signal_type_code'] ?? null) === $signalType
            && ($normalized['source_module'] ?? null) === 'lone_worker'
            && ($normalized['signal_type'] ?? null) === $signalType
            && $this->positiveId($normalized['lone_worker_session_id'] ?? null) === (int) $session->id
            && $this->positiveId($normalized['worker_user_id'] ?? null) === (int) $session->user_id
            && $this->positiveId($normalized['site_id'] ?? null) === $siteId
            && array_key_exists('client_id', $normalized)
            && $this->nullableIdMatches($normalized['client_id'], $this->positiveId($session->client_id));
    }

    private function nullableIdMatches(mixed $actual, ?int $expected): bool
    {
        return $expected === null
            ? $actual === null
            : $this->positiveId($actual) === $expected;
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value)
            || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
            || (string) (int) $value !== $value) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Build idempotency key. Uses 15-minute windows for emergencies,
     * 30-minute windows for overdue check-ins and session overruns.
     */
    protected function buildIdempotencyKey(string $signalType, LoneWorkerSession $session): string
    {
        $windowMinutes = $signalType === self::TYPE_EMERGENCY ? 15 : 30;
        $window = now()->format('Y-m-d H:').(intdiv((int) now()->format('i'), $windowMinutes) * $windowMinutes);

        return hash('sha256', implode('|', [
            'lone_worker',
            $signalType,
            $session->id,
            $session->user_id,
            $window,
        ]));
    }

    protected function getSignalSource(): SignalSource
    {
        if ($this->signalSource) {
            return $this->signalSource;
        }

        $this->signalSource = SignalSource::firstOrCreate(
            ['slug' => 'lone_worker'],
            [
                'name' => 'Lone Worker Safety',
                'vendor' => 'internal',
                'status' => 'active',
                'config' => [],
                'capabilities' => ['manual_trigger', 'scheduled_checks'],
            ],
        );

        return $this->signalSource;
    }
}
