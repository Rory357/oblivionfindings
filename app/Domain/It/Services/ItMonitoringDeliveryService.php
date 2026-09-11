<?php

namespace App\Domain\It\Services;

use App\Domain\Monitoring\Services\MonitoringAvailabilityEpisode;
use App\Domain\SecurityDevices\Events\DeviceSignalPublished;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceMonitoringTicket;
use App\Models\ControlRoom\Signal;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** The IT destination of a device delivery is independent of its Control Room acknowledgement. */
final class ItMonitoringDeliveryService extends ItTechnicalDeliveryService
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

    protected function deliverySource(): string
    {
        return 'device_it';
    }

    protected function dispatch(int $outboxId): void
    {
        DispatchDeviceMonitoringTicket::dispatch($outboxId);
    }

    protected function outboxes(): Builder
    {
        return DeviceEventSignalOutbox::query();
    }

    protected function auditPrefix(): string
    {
        return 'it.monitoring';
    }

    protected function canonicalEvent(Model $outbox): DeviceSignalPublished
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
