<?php

namespace App\Domain\It\Services;

use App\Domain\Monitoring\Services\MonitoringAvailabilityEpisode;
use App\Domain\SecurityDevices\Events\DeviceSignalPublished;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceMonitoringTicket;
use App\Models\ControlRoom\Signal;
use App\Services\AuditLogger;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** The IT destination of a device delivery is independent of its Control Room acknowledgement. */
final class ItMonitoringDeliveryService
{
    public function __construct(private readonly ItTicketLinkService $links) {}

    public function prepare(DeviceEvent $event, Signal $signal): void
    {
        DB::transaction(function () use ($event, $signal): void {
            $outbox = DeviceEventSignalOutbox::query()->where('device_event_id', $event->id)->lockForUpdate()->firstOrFail();
            if ($outbox->it_status !== null) {
                return;
            }
            if ($outbox->status === 'sent') {
                // A replay of an old source acknowledgement cannot prove whether
                // its original queued IT consumer already created or settled work.
                return;
            }
            $device = Device::query()->find($event->device_id);
            $suppressed = $signal->status === 'suppressed';
            $eligible = ! $suppressed && $device?->domain === 'it_infrastructure' && in_array($event->event_type, ['offline', 'online'], true);
            $outbox->forceFill([
                'it_status' => $eligible ? 'pending' : 'ignored',
                'it_signal_id' => $signal->id,
                'it_scope' => [
                    'device_id' => (int) $event->device_id,
                    'site_id' => (int) $signal->site_id,
                    'domain' => $device?->domain,
                    'event_identity' => $this->eventIdentity($event),
                    'alert_id' => $signal->alert_id === null ? null : (int) $signal->alert_id,
                    'correlated_alert_id' => $signal->correlated_alert_id === null ? null : (int) $signal->correlated_alert_id,
                    'work_routing' => data_get($signal->normalized_data, 'it_work_routing'),
                ],
                'it_outcome_code' => $eligible ? null : ($suppressed ? 'source_suppressed' : 'unsupported_event'),
                'it_completed_at' => $eligible ? null : now(),
            ])->save();
        }, 3);
    }

    /** @param Closure(DeviceSignalPublished): array{outcome: string, ticket_ids: list<int>} $apply */
    public function deliver(int $outboxId, Closure $apply): void
    {
        try {
            DB::transaction(function () use ($outboxId, $apply): void {
                $outbox = DeviceEventSignalOutbox::query()->whereKey($outboxId)->lockForUpdate()->firstOrFail();
                if ($outbox->status !== 'sent' || ! in_array($outbox->it_status, ['pending', 'failed'], true)) {
                    return;
                }
                if ($outbox->it_attempts >= $outbox->it_attempt_limit) {
                    $outbox->update(['it_status' => 'dead_letter']);

                    return;
                }
                $outbox->update(['it_attempts' => $outbox->it_attempts + 1, 'it_last_attempt_at' => now()]);
                $event = $this->canonicalEvent($outbox);
                $result = $apply($event);
                if (! in_array($result['outcome'], ['ticket_created', 'ticket_updated', 'recovery_recorded', 'recovery_unmatched'], true)) {
                    throw new DomainException('unsupported_outcome');
                }
                $outbox->update([
                    'it_status' => $result['outcome'] === 'recovery_unmatched' ? 'ignored' : 'applied',
                    'it_outcome_code' => $result['outcome'],
                    'it_ticket_ids' => $result['ticket_ids'],
                    'it_completed_at' => now(),
                ]);
                AuditLogger::logOrFail('it.monitoring.delivery_completed', $outbox, [
                    'fields' => ['status', 'state', 'it_attempts', 'completed_at'],
                    'after' => ['status' => $outbox->it_status, 'state' => $result['outcome'],
                        'it_attempts' => $outbox->it_attempts, 'completed_at' => $outbox->it_completed_at],
                ], systemActor: true);
            }, 3);
        } catch (Throwable $exception) {
            $unroutable = $exception instanceof DomainException;
            $code = $unroutable && in_array($exception->getMessage(), [
                'source_scope_changed', 'source_unavailable', 'technical_routing_unavailable',
            ], true) ? $exception->getMessage() : ($unroutable ? 'canonical_evidence_unavailable' : 'processing_failed');
            DB::transaction(function () use ($outboxId, $unroutable, $code): void {
                $outbox = DeviceEventSignalOutbox::query()->whereKey($outboxId)->lockForUpdate()->first();
                if (! $outbox || ! in_array($outbox->it_status, ['pending', 'failed'], true)) {
                    return;
                }
                $attempts = $outbox->it_attempts + 1;
                $outbox->update([
                    'it_attempts' => $attempts,
                    'it_last_attempt_at' => now(),
                    'it_status' => $unroutable ? 'unroutable' : ($attempts >= $outbox->it_attempt_limit ? 'dead_letter' : 'failed'),
                    'it_outcome_code' => $code,
                ]);
            }, 3);
            // Never persist exception messages or SQL/provider payloads in a cross-module outcome.
            Log::warning('Monitoring IT delivery did not complete', ['outbox_id' => $outboxId, 'code' => $code, 'exception_type' => $exception::class]);
            if (! $unroutable) {
                throw $exception;
            }
        }
    }

    /** Existing scheduler sweep calls this after recovering Control Room deliveries. */
    /** @return array{queued: int, pending: int, failures: int, legacy_unverified: int, failure_rows: list<array<string, mixed>>} */
    public function recover(int $limit, bool $reportOnly = false): array
    {
        $queued = 0;
        if (! $reportOnly) {
            DeviceEventSignalOutbox::query()->where('it_status', 'failed')->whereColumn('it_attempts', '>=', 'it_attempt_limit')
                ->update(['it_status' => 'dead_letter']);
            $rows = DeviceEventSignalOutbox::query()->where('status', 'sent')->whereIn('it_status', ['pending', 'failed'])
                ->whereColumn('it_attempts', '<', 'it_attempt_limit')
                ->where(fn ($query) => $query->whereNull('it_last_attempt_at')->orWhere('it_last_attempt_at', '<=', now()->subMinute()))
                ->orderBy('id')->limit(max(1, min($limit, 1000)))->get(['id']);
            foreach ($rows as $row) {
                try {
                    DispatchDeviceMonitoringTicket::dispatch((int) $row->id);
                    $queued++;
                } catch (Throwable $exception) {
                    Log::warning('Monitoring IT recovery dispatch failed', ['outbox_id' => $row->id, 'exception_type' => $exception::class]);
                }
            }
        }

        return [
            'queued' => $queued,
            'pending' => DeviceEventSignalOutbox::query()->where('it_status', 'pending')->count(),
            'failures' => DeviceEventSignalOutbox::query()->whereIn('it_status', ['failed', 'dead_letter', 'unroutable'])->count(),
            'legacy_unverified' => DeviceEventSignalOutbox::query()->where('status', 'sent')->whereNull('it_status')->count(),
            'failure_rows' => DeviceEventSignalOutbox::query()->whereIn('it_status', ['failed', 'dead_letter', 'unroutable'])
                ->orderBy('id')->limit(max(1, min($limit, 1000)))->get()->map(fn (DeviceEventSignalOutbox $row): array => [
                    'source' => 'device_it', 'id' => $row->id, 'status' => $row->it_status,
                    'attempts' => $row->it_attempts, 'last_attempt_at' => $row->it_last_attempt_at?->toIso8601String(),
                    'last_error' => $row->it_outcome_code,
                ])->all(),
        ];
    }

    public function retry(int $outboxId): void
    {
        DB::transaction(function () use ($outboxId): void {
            $outbox = DeviceEventSignalOutbox::query()->whereKey($outboxId)->lockForUpdate()->firstOrFail();
            if ($outbox->status !== 'sent' || ! in_array($outbox->it_status, ['failed', 'dead_letter', 'unroutable'], true)) {
                throw new DomainException('Only failed IT outcomes of delivered source events can be retried.');
            }
            // Keep lifetime attempt evidence. A manual retry grants one bounded attempt.
            AuditLogger::logOrFail('it.monitoring.delivery_retry_requested', $outbox, [
                'fields' => ['status', 'state', 'it_attempts', 'it_attempt_limit'],
                'before' => ['status' => $outbox->it_status, 'state' => $outbox->it_outcome_code,
                    'it_attempts' => $outbox->it_attempts, 'it_attempt_limit' => $outbox->it_attempt_limit],
                'after' => ['status' => 'pending', 'state' => 'retry_requested',
                    'it_attempts' => $outbox->it_attempts, 'it_attempt_limit' => $outbox->it_attempts + 1],
            ]);
            $outbox->update(['it_status' => 'pending', 'it_attempt_limit' => $outbox->it_attempts + 1]);
        }, 3);
        try {
            DispatchDeviceMonitoringTicket::dispatch($outboxId);
        } catch (Throwable $exception) {
            // Pending intent and the explicit retry allowance survive a queue outage.
            Log::warning('Monitoring IT retry dispatch failed', ['outbox_id' => $outboxId, 'exception_type' => $exception::class]);
        }
    }

    private function canonicalEvent(DeviceEventSignalOutbox $outbox): DeviceSignalPublished
    {
        $event = DeviceEvent::query()->whereKey($outbox->device_event_id)->lockForUpdate()->first();
        $device = $event ? Device::query()->whereKey($event->device_id)->lockForUpdate()->first() : null;
        $signal = Signal::query()->whereKey($outbox->it_signal_id)->lockForUpdate()->first();
        $scope = $outbox->it_scope;
        if (! $event || ! $device || ! $signal || ! is_array($scope)
            || ! $signal->signalSource()->where('slug', 'security_devices')->where('status', 'active')->lockForUpdate()->first(['id'])) {
            throw new DomainException('source_unavailable');
        }
        if ($device->domain !== 'it_infrastructure'
            || (int) $event->device_id !== ($scope['device_id'] ?? null)
            || ($scope['domain'] ?? null) !== $device->domain
            || ($scope['event_identity'] ?? null) !== $this->eventIdentity($event)
            || ! in_array($event->event_type, ['offline', 'online'], true)
            || $signal->external_ref !== 'device_event_'.$event->id
            || $signal->signal_type_code !== 'device_'.$event->event_type
            || (int) data_get($signal->normalized_data, 'device_event_id') !== (int) $event->id
            || (int) data_get($signal->normalized_data, 'canonical_device_id') !== (int) $device->id
            || (int) $signal->site_id !== ($scope['site_id'] ?? null)
            || ($signal->alert_id === null ? null : (int) $signal->alert_id) !== ($scope['alert_id'] ?? null)
            || ($signal->correlated_alert_id === null ? null : (int) $signal->correlated_alert_id) !== ($scope['correlated_alert_id'] ?? null)
            || $this->links->canonicalDeviceSiteId($device, true) !== ($scope['site_id'] ?? null)) {
            throw new DomainException('source_scope_changed');
        }
        if (($scope['work_routing'] ?? null) !== data_get($signal->normalized_data, 'it_work_routing')) {
            throw new DomainException('source_scope_changed');
        }
        if (data_get($event->payload, 'availability_episode_version') === 1
            && ((! ($event->event_type === 'online' && data_get($event->payload, 'availability_episode') === null)
                    && ! MonitoringAvailabilityEpisode::hasCanonicalObservations($event, (int) $signal->site_id))
                || data_get($signal->normalized_data, 'availability_episode_version') !== 1
                || data_get($signal->normalized_data, 'availability_episode_key') !== (MonitoringAvailabilityEpisode::fromEvent($event, (int) $signal->site_id)['key'] ?? null))) {
            throw new DomainException('source_scope_changed');
        }

        return new DeviceSignalPublished($device, $event, $signal, $signal->alert_id !== null);
    }

    private function eventIdentity(DeviceEvent $event): string
    {
        $identity = [
            (int) $event->id, (int) $event->device_id, $event->event_type, $event->source,
            $event->severity, $event->occurred_at?->toIso8601String(),
            data_get($event->payload, 'monitor_correlation_key'),
            data_get($event->payload, 'legacy_monitoring_recovery') === true,
        ];
        // Keep pre-episode pending intents compatible with their original digest.
        if (data_get($event->payload, 'availability_episode_version') === 1) {
            $identity[] = ['native-availability-v1', MonitoringAvailabilityEpisode::fromEvent($event)['key'] ?? null,
                data_get($event->payload, 'monitor_id'), data_get($event->payload, 'observation_id'),
                data_get($event->payload, 'site_id'), data_get($event->payload, 'from_state'), data_get($event->payload, 'to_state')];
        }

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }
}
