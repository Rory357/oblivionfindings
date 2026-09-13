<?php

namespace App\Domain\It\Services;

use App\Jobs\DispatchFleetMonitoringTicket;
use App\Models\ControlRoom\Signal;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Services\Fleet\FleetSignalService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Fleet's technical destination uses the owning source's durable outbox and episode proof. */
final class ItFleetDeliveryService extends ItTechnicalDeliveryService
{
    public function __construct(private readonly FleetSignalService $fleet) {}

    public function prepare(FleetSignal $event, Signal $signal): void
    {
        DB::transaction(function () use ($event, $signal): void {
            $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $event->id)->lockForUpdate()->sole();
            if ($outbox->it_status !== null || $outbox->status === 'sent') {
                // Never infer an old delivery's IT outcome from a source acknowledgement.
                return;
            }
            $event = FleetSignal::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $signal = Signal::query()->whereKey($signal->id)->lockForUpdate()->firstOrFail();
            $suppressed = $signal->status === 'suppressed';
            $eligible = ! $suppressed && in_array($event->signal_type, ['device.offline', 'device.online'], true);
            $outbox->forceFill([
                'it_status' => $eligible ? 'pending' : 'ignored',
                'it_signal_id' => $signal->id,
                'it_scope' => $this->identity($event, $signal),
                'it_outcome_code' => $eligible ? null : ($suppressed ? 'source_suppressed' : 'unsupported_event'),
                'it_completed_at' => $eligible ? null : now(),
            ])->save();
        }, 3);
    }

    protected function outboxes(): Builder
    {
        return FleetSignalOutbox::query();
    }

    protected function deliverySource(): string
    {
        return 'fleet_it';
    }

    protected function dispatch(int $outboxId): void
    {
        DispatchFleetMonitoringTicket::dispatch($outboxId);
    }

    protected function auditPrefix(): string
    {
        return 'it.fleet';
    }

    protected function canonicalEvent(Model $outbox): ItFleetAvailability
    {
        $event = FleetSignal::query()->whereKey($outbox->fleet_signal_id)->lockForUpdate()->first();
        $signal = Signal::query()->whereKey($outbox->it_signal_id)->lockForUpdate()->first();
        if (! $event || ! $signal
            || ! $signal->signalSource()->where('slug', 'queclink_fleet')->where('status', 'active')->lockForUpdate()->first(['id'])) {
            throw new DomainException('source_unavailable');
        }
        if (! is_array($outbox->it_scope) || Arr::sortRecursive($outbox->it_scope) !== Arr::sortRecursive($this->identity($event, $signal))
            || ! in_array($event->signal_type, ['device.offline', 'device.online'], true)
            || ! in_array($signal->status, ['processed', 'correlated'], true)
            || $signal->external_ref !== 'fleet_signal_'.$event->id
            || $signal->signal_type_code !== 'fleet_'.str_replace('.', '_', $event->signal_type)
            || data_get($signal->normalized_data, 'fleet_signal_id') !== (int) $event->id
            || (int) $signal->asset_id !== (int) $event->asset_id
            || $signal->occurred_at === null || $event->occurred_at === null
            || ! $signal->occurred_at->equalTo($event->occurred_at)
            || data_get($event->payload, 'availability.scope.site_id') !== (int) $signal->site_id) {
            throw new DomainException('source_scope_changed');
        }
        $offline = $event->signal_type === 'device.offline' ? $event : $this->fleet->matchedOfflineForRecovery($event);
        if ($offline === null || ! $this->fleet->offlineScopeIsCurrent($offline)) {
            throw new DomainException('source_scope_changed');
        }

        return new ItFleetAvailability($event, $offline, $signal, (int) $signal->site_id);
    }

    /** Pin only technical source identity; never copy coordinates, drivers, trips or diagnostics. */
    private function identity(FleetSignal $event, Signal $signal): array
    {
        return [
            'fleet_signal_id' => (int) $event->id,
            'asset_id' => (int) $event->asset_id,
            'device_id' => (int) $event->device_id,
            'site_id' => (int) $signal->site_id,
            'event_identity' => hash('sha256', json_encode([
                $event->signal_type, $event->occurred_at?->toIso8601String(), $event->idempotency_key,
                data_get($event->payload, 'last_seen_at'), data_get($event->payload, 'availability'),
            ], JSON_THROW_ON_ERROR)),
            'alert_id' => $signal->alert_id === null ? null : (int) $signal->alert_id,
            'correlated_alert_id' => $signal->correlated_alert_id === null ? null : (int) $signal->correlated_alert_id,
            'work_routing' => data_get($signal->normalized_data, 'it_work_routing'),
        ];
    }
}
