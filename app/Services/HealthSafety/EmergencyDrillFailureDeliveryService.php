<?php

namespace App\Services\HealthSafety;

use App\Enums\AlertSeverity;
use App\Jobs\DispatchEmergencyDrillFailure;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillFailureDelivery;
use App\Models\HsEvent;
use App\Services\ControlRoom\SignalProcessingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use Throwable;

/** Retained source-owned convergence through the existing H&S/signal processors. */
class EmergencyDrillFailureDeliveryService
{
    public function __construct(private readonly HsEventService $events, private readonly SignalProcessingService $signals) {}

    public function stage(EmergencyDrill $drill): EmergencyDrillFailureDelivery
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Drill failure delivery must be retained in the completion transaction.');
        }
        $intent = EmergencyDrillFailureDelivery::query()->firstOrCreate(['emergency_drill_id' => $drill->id], [
            'event_status' => 'pending', 'signal_status' => 'pending', 'next_attempt_at' => now(), 'initiated_by_user_id' => $drill->updated_by,
            'signal_key' => hash('sha256', 'health_safety:drill-failure:'.$drill->id),
        ]);
        if ($intent->wasRecentlyCreated) {
            DB::afterCommit(fn () => $this->queue($intent->id));
        }

        return $intent;
    }

    public function queue(int $id): void
    {
        try {
            DispatchEmergencyDrillFailure::dispatch($id);
        } catch (Throwable $exception) {
            Log::warning('Emergency drill failure queue unavailable', ['delivery_id' => $id, 'exception_type' => $exception::class]);
            try {
                EmergencyDrillFailureDelivery::query()->whereKey($id)->whereIn('event_status', ['pending', 'failed'])->update([
                    'event_status' => 'failed', 'failure_code' => 'queue_unavailable', 'next_attempt_at' => now()->addMinute(), 'updated_at' => now(),
                ]);
            } catch (Throwable $receiptFailure) {
                Log::warning('Drill queue failure receipt unavailable', ['delivery_id' => $id, 'exception_type' => $receiptFailure::class]);
            }
        }
    }

    public function deliver(int $id): void
    {
        $channel = 'event';
        try {
            DB::transaction(function () use ($id): void {
                [$drill, $intent] = $this->lockSource($id);
                if (! $intent || $intent->next_attempt_at?->isFuture() || in_array($intent->event_status, ['delivered', 'skipped'], true)) {
                    return;
                }
                if (! $this->eligible($drill)) {
                    $intent->forceFill(['event_status' => 'skipped', 'signal_status' => 'skipped', 'failure_code' => 'source_inactive', 'next_attempt_at' => null])->save();

                    return;
                }
                $event = $this->events->recordEvent(['source' => $drill, 'event_category' => HsEvent::CATEGORY_DRILL_FAILURE, 'severity' => 'medium', 'site_id' => $drill->site_id, 'occurred_at' => $drill->completed_at, 'reported_at' => now(), 'created_by' => $intent->initiated_by_user_id]);
                if (! $event || $event->source_type !== EmergencyDrill::class || (int) $event->source_id !== (int) $drill->id || (int) $event->site_id !== (int) $drill->site_id) {
                    throw new RuntimeException('Canonical drill safety review could not be delivered.');
                }
                $intent->forceFill(['event_status' => 'delivered', 'hs_event_id' => $event->id, 'event_delivered_at' => now(), 'failure_code' => null])->save();
            }, 3);

            $channel = 'signal';
            DB::transaction(function () use ($id): void {
                [$drill, $intent] = $this->lockSource($id);
                if (! $intent || $intent->next_attempt_at?->isFuture() || $intent->event_status !== 'delivered' || in_array($intent->signal_status, ['delivered', 'suppressed', 'skipped'], true)) {
                    return;
                }
                if (! $this->eligible($drill)) {
                    $intent->forceFill(['signal_status' => 'skipped', 'failure_code' => 'source_inactive', 'next_attempt_at' => null])->save();

                    return;
                }
                $source = SignalSource::query()->firstOrCreate(['slug' => 'health_safety'], ['name' => 'Health & Safety Monitoring', 'vendor' => 'internal', 'status' => 'active', 'config' => [], 'capabilities' => ['scheduled_checks', 'event_driven']]);
                $signal = $this->signals->ingest([
                    'signal_source_id' => $source->id, 'signal_type_code' => HsSignalService::TYPE_DRILL_FAILURE,
                    'idempotency_key' => $intent->signal_key, 'site_id' => $drill->site_id, 'severity_hint' => AlertSeverity::MEDIUM,
                    'occurred_at' => $drill->completed_at, 'payload' => [],
                    'normalized_data' => ['title' => 'Emergency drill needs corrective follow-up', 'description' => 'Open the canonical drill to review its recorded outcome.',
                        'source_module' => 'health_safety', 'signal_type' => HsSignalService::TYPE_DRILL_FAILURE,
                        'emergency_drill_id' => $drill->id, 'hs_event_id' => $intent->hs_event_id, 'drill_type' => $drill->drill_type,
                        'site_id' => $drill->site_id, 'outcome' => $drill->outcome],
                ]);
                if ((int) $signal->site_id !== (int) $drill->site_id || (int) data_get($signal->normalized_data, 'emergency_drill_id') !== (int) $drill->id) {
                    throw new RuntimeException('Retained drill signal has conflicting source evidence.');
                }
                $alert = $this->signals->process($signal);
                $signal->refresh();
                if ($signal->status !== 'suppressed' && ! $alert) {
                    throw new RuntimeException('Drill signal processing has not confirmed a destination.');
                }
                if ($alert) {
                    $event = HsEvent::query()->whereKey($intent->hs_event_id)->lockForUpdate()->firstOrFail();
                    if ($event->control_room_alert_id !== null && (int) $event->control_room_alert_id !== (int) $alert->id) {
                        throw new RuntimeException('Drill review already has a different canonical response link.');
                    }
                    $this->events->linkControlRoomAlert($event, $alert->id);
                }
                $intent->forceFill(['signal_status' => $signal->status === 'suppressed' ? 'suppressed' : 'delivered',
                    'signal_id' => $signal->id, 'signal_delivered_at' => now(), 'failure_code' => null, 'next_attempt_at' => null,
                    'attempts' => $intent->attempts + 1])->save();
            }, 3);
        } catch (Throwable $exception) {
            DB::transaction(function () use ($id, $channel): void {
                $intent = EmergencyDrillFailureDelivery::query()->whereKey($id)->lockForUpdate()->first();
                if (! $intent || in_array($intent->{$channel.'_status'}, ['delivered', 'suppressed', 'skipped'], true)) {
                    return;
                }
                $attempts = $intent->attempts + 1;
                $intent->forceFill([$channel.'_status' => 'failed', 'failure_code' => $channel.'_delivery_failed', 'attempts' => $attempts,
                    'next_attempt_at' => now()->addSeconds(min(900, 30 * (2 ** min(5, $attempts - 1))))])->save();
            }, 3);
            throw $exception;
        }
    }

    private function lockSource(int $id): array
    {
        $original = EmergencyDrillFailureDelivery::query()->find($id);
        if (! $original) {
            return [null, null];
        }
        $drill = EmergencyDrill::query()->whereKey($original->emergency_drill_id)->lockForUpdate()->first();
        $intent = EmergencyDrillFailureDelivery::query()->whereKey($id)->lockForUpdate()->first();

        return [$drill, $intent];
    }

    private function eligible(?EmergencyDrill $drill): bool
    {
        return $drill && $drill->status === 'completed' && in_array($drill->outcome, ['failed', 'passed_actions'], true) && $drill->completed_at !== null;
    }

    public function recoverPending(int $limit = 30): int
    {
        $ids = EmergencyDrillFailureDelivery::query()->where('next_attempt_at', '<=', now())
            ->where(fn ($query) => $query->whereIn('event_status', ['pending', 'failed'])->orWhereIn('signal_status', ['pending', 'failed']))
            ->orderBy('next_attempt_at')->limit(max(1, min(100, $limit)))->pluck('id');
        foreach ($ids as $id) {
            try {
                $this->deliver($id);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $ids->count();
    }
}
