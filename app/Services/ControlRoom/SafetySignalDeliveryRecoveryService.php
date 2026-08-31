<?php

namespace App\Services\ControlRoom;

use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceEventSignalOutbox;
use App\Jobs\DispatchFacilitySignalOutbox;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Jobs\DispatchIncidentLifecycleSignalOutbox;
use App\Jobs\DispatchShiftSignalOutbox;
use App\Models\ClientIncident;
use App\Models\FacilitySignal;
use App\Models\FacilitySignalOutbox;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\IncidentLifecycleSignal;
use App\Models\IncidentLifecycleSignalOutbox;
use App\Models\ShiftSignal;
use App\Models\ShiftSignalOutbox;
use App\Services\Incidents\IncidentAlertLifecycleSignalService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class SafetySignalDeliveryRecoveryService
{
    public function __construct(
        private readonly IncidentAlertLifecycleSignalService $incidentLifecycleSignals,
    ) {}

    /**
     * @return array{
     *   reconciled: array{fleet: int, shift: int, device: int, incident: int, facility: int},
     *   queued: array{fleet: int, shift: int, device: int, incident: int, facility: int},
     *   failures: array{fleet: int, shift: int, device: int, incident: int, facility: int},
     *   failure_rows: list<array{source: string, id: int, status: string, attempts: int, last_attempt_at: ?string, last_error: ?string}>
     * }
     */
    public function recover(int $limit = 100, bool $reportOnly = false): array
    {
        $limit = max(1, min($limit, 1000));
        $reconciled = ['fleet' => 0, 'shift' => 0, 'device' => 0, 'incident' => 0, 'facility' => 0];
        $queued = ['fleet' => 0, 'shift' => 0, 'device' => 0, 'incident' => 0, 'facility' => 0];

        if (! $reportOnly) {
            $this->terminalizeExhausted(FleetSignalOutbox::class);
            $this->terminalizeExhausted(ShiftSignalOutbox::class);
            $this->terminalizeExhausted(DeviceEventSignalOutbox::class);
            $this->terminalizeExhausted(IncidentLifecycleSignalOutbox::class);
            $this->terminalizeExhausted(FacilitySignalOutbox::class);
            $reconciled['fleet'] = $this->reconcileFleet($limit);
            $reconciled['shift'] = $this->reconcileShift($limit);
            $reconciled['device'] = $this->reconcileDevice($limit);
            $reconciled['incident'] = $this->reconcileIncident($limit);
            $reconciled['facility'] = $this->reconcileFacility($limit);
            $queued['fleet'] = $this->dispatchEligible(
                FleetSignalOutbox::class,
                fn (int $id) => DispatchFleetSignalOutbox::dispatch($id),
                $limit,
            );
            $queued['shift'] = $this->dispatchEligible(
                ShiftSignalOutbox::class,
                fn (int $id) => DispatchShiftSignalOutbox::dispatch($id),
                $limit,
            );
            $queued['device'] = $this->dispatchEligible(
                DeviceEventSignalOutbox::class,
                fn (int $id) => DispatchDeviceEventSignalOutbox::dispatch($id),
                $limit,
            );
            $queued['incident'] = $this->dispatchEligible(
                IncidentLifecycleSignalOutbox::class,
                fn (int $id) => DispatchIncidentLifecycleSignalOutbox::dispatch($id),
                $limit,
            );
            $queued['facility'] = $this->dispatchEligible(
                FacilitySignalOutbox::class,
                fn (int $id) => DispatchFacilitySignalOutbox::dispatch($id),
                $limit,
            );
        }

        return [
            'reconciled' => $reconciled,
            'queued' => $queued,
            'failures' => [
                'fleet' => $this->failureCount(FleetSignalOutbox::class),
                'shift' => $this->failureCount(ShiftSignalOutbox::class),
                'device' => $this->failureCount(DeviceEventSignalOutbox::class),
                'incident' => $this->failureCount(IncidentLifecycleSignalOutbox::class),
                'facility' => $this->failureCount(FacilitySignalOutbox::class),
            ],
            'failure_rows' => $this->failureRows($limit),
        ];
    }

    public function retry(string $source, int $outboxId): void
    {
        [$modelClass, $dispatch] = match ($source) {
            'fleet' => [FleetSignalOutbox::class, fn (int $id) => DispatchFleetSignalOutbox::dispatch($id)],
            'shift' => [ShiftSignalOutbox::class, fn (int $id) => DispatchShiftSignalOutbox::dispatch($id)],
            'device' => [DeviceEventSignalOutbox::class, fn (int $id) => DispatchDeviceEventSignalOutbox::dispatch($id)],
            'incident' => [IncidentLifecycleSignalOutbox::class, fn (int $id) => DispatchIncidentLifecycleSignalOutbox::dispatch($id)],
            'facility' => [FacilitySignalOutbox::class, fn (int $id) => DispatchFacilitySignalOutbox::dispatch($id)],
            default => throw new InvalidArgumentException('Source must be fleet, shift, device, incident, or facility.'),
        };

        DB::transaction(function () use ($modelClass, $outboxId): void {
            /** @var Model|null $outbox */
            $outbox = $modelClass::query()->whereKey($outboxId)->lockForUpdate()->first();
            if ($outbox === null) {
                throw new InvalidArgumentException('Safety-signal outbox was not found.');
            }
            if (! in_array($outbox->status, ['failed', 'dead_letter', 'unroutable'], true)) {
                throw new DomainException('Only failed, dead-letter, or unroutable deliveries can be replayed.');
            }

            $outbox->forceFill([
                'status' => 'pending',
                'last_error' => null,
            ])->save();
        }, 3);

        try {
            $dispatch($outboxId);
        } catch (Throwable $exception) {
            // The pending row is durable and the scheduled sweep will retry it.
            Log::error('Safety-signal replay queue dispatch failed', [
                'source' => $source,
                'outbox_id' => $outboxId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function reconcileFleet(int $limit): int
    {
        $count = 0;

        FleetSignal::query()
            ->whereDoesntHave('outbox')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id'])
            ->each(function (FleetSignal $signal) use (&$count): void {
                $outbox = FleetSignalOutbox::query()->firstOrCreate(
                    ['fleet_signal_id' => $signal->id],
                    ['status' => 'pending'],
                );
                $count += $outbox->wasRecentlyCreated ? 1 : 0;
            });

        return $count;
    }

    private function reconcileShift(int $limit): int
    {
        $count = 0;

        ShiftSignal::query()
            ->whereDoesntHave('outbox')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id'])
            ->each(function (ShiftSignal $signal) use (&$count): void {
                $outbox = ShiftSignalOutbox::query()->firstOrCreate(
                    ['shift_signal_id' => $signal->id],
                    ['status' => 'pending'],
                );
                $count += $outbox->wasRecentlyCreated ? 1 : 0;
            });

        return $count;
    }

    private function reconcileDevice(int $limit): int
    {
        $count = 0;

        DeviceEvent::query()
            ->whereNull('processed_at')
            ->where('event_type', '!=', 'heartbeat')
            ->whereDoesntHave('signalOutbox')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id'])
            ->each(function (DeviceEvent $event) use (&$count): void {
                $outbox = DeviceEventSignalOutbox::query()->firstOrCreate(
                    ['device_event_id' => $event->id],
                    ['status' => 'pending'],
                );
                $count += $outbox->wasRecentlyCreated ? 1 : 0;
            });

        return $count;
    }

    private function reconcileIncident(int $limit): int
    {
        $count = 0;

        ClientIncident::query()
            ->where(function ($query): void {
                $query->where(function ($closed): void {
                    $closed->where('status', 'closed')
                        ->whereNotNull('closed_by')
                        ->whereNotNull('closed_at')
                        ->whereNotNull('closed_outcome');
                })->orWhere(function ($reopened): void {
                    $reopened->where('status', 'reviewed')
                        ->whereNotNull('reopened_by')
                        ->whereNotNull('reopened_at')
                        ->whereNotNull('reopened_reason');
                });
            })
            ->whereNotExists(function ($signals): void {
                $signals->selectRaw('1')
                    ->from('incident_lifecycle_signals')
                    ->whereColumn(
                        'incident_lifecycle_signals.client_incident_id',
                        'client_incidents.id',
                    )
                    ->where(function ($transition): void {
                        $transition->where(function ($closed): void {
                            $closed->where('client_incidents.status', 'closed')
                                ->where(
                                    'incident_lifecycle_signals.signal_type',
                                    IncidentLifecycleSignal::TYPE_CLOSED,
                                )
                                ->whereColumn(
                                    'incident_lifecycle_signals.actor_user_id',
                                    'client_incidents.closed_by',
                                )
                                ->whereColumn(
                                    'incident_lifecycle_signals.effective_at',
                                    'client_incidents.closed_at',
                                );
                        })->orWhere(function ($reopened): void {
                            $reopened->where('client_incidents.status', 'reviewed')
                                ->where(
                                    'incident_lifecycle_signals.signal_type',
                                    IncidentLifecycleSignal::TYPE_REOPENED,
                                )
                                ->whereColumn(
                                    'incident_lifecycle_signals.actor_user_id',
                                    'client_incidents.reopened_by',
                                )
                                ->whereColumn(
                                    'incident_lifecycle_signals.effective_at',
                                    'client_incidents.reopened_at',
                                );
                        });
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get(['id'])
            ->each(function (ClientIncident $incident) use (&$count): void {
                try {
                    $outbox = $this->incidentLifecycleSignals->reconcileLatestTransition($incident);
                    $count += $outbox?->wasRecentlyCreated ? 1 : 0;
                } catch (Throwable $exception) {
                    Log::error('Incident lifecycle signal reconciliation failed', [
                        'incident_id' => $incident->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        return $count;
    }

    private function reconcileFacility(int $limit): int
    {
        $count = 0;

        FacilitySignal::query()
            ->whereDoesntHave('outbox')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id'])
            ->each(function (FacilitySignal $signal) use (&$count): void {
                $outbox = FacilitySignalOutbox::query()->firstOrCreate(
                    ['facility_signal_id' => $signal->id],
                    ['status' => 'pending'],
                );
                $count += $outbox->wasRecentlyCreated ? 1 : 0;
            });

        return $count;
    }

    /** @param class-string<Model> $modelClass */
    private function dispatchEligible(string $modelClass, callable $dispatch, int $limit): int
    {
        $queued = 0;

        $modelClass::query()
            ->where(function ($query): void {
                $query->whereIn('status', ['pending', 'processing'])
                    ->orWhere(fn ($failed) => $failed
                        ->where('status', 'failed')
                        ->where('attempts', '<', 3));
            })
            ->where(fn ($query) => $query
                ->whereNull('last_attempt_at')
                ->orWhere('last_attempt_at', '<=', now()->subSeconds(30)))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'status', 'attempts', 'last_attempt_at'])
            ->each(function (Model $outbox) use ($dispatch, &$queued): void {
                $delay = min(300, 30 * (2 ** max(0, (int) $outbox->attempts - 1)));
                if ($outbox->last_attempt_at?->gt(now()->subSeconds($delay))) {
                    return;
                }

                try {
                    $dispatch((int) $outbox->getKey());
                    $queued++;
                } catch (Throwable $exception) {
                    Log::error('Safety-signal recovery queue dispatch failed', [
                        'outbox_type' => $outbox::class,
                        'outbox_id' => $outbox->getKey(),
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        return $queued;
    }

    /** @param class-string<Model> $modelClass */
    private function terminalizeExhausted(string $modelClass): void
    {
        $modelClass::query()
            ->where('status', 'failed')
            ->where('attempts', '>=', 3)
            ->update(['status' => 'dead_letter']);
    }

    /** @param class-string<Model> $modelClass */
    private function failureCount(string $modelClass): int
    {
        return $modelClass::query()
            ->whereIn('status', ['failed', 'dead_letter', 'unroutable'])
            ->count();
    }

    /**
     * @return list<array{source: string, id: int, status: string, attempts: int, last_attempt_at: ?string, last_error: ?string}>
     */
    private function failureRows(int $limit): array
    {
        return collect([
            'fleet' => FleetSignalOutbox::class,
            'shift' => ShiftSignalOutbox::class,
            'device' => DeviceEventSignalOutbox::class,
            'incident' => IncidentLifecycleSignalOutbox::class,
            'facility' => FacilitySignalOutbox::class,
        ])->flatMap(fn (string $modelClass, string $source) => $modelClass::query()
            ->whereIn('status', ['failed', 'dead_letter', 'unroutable'])
            ->latest('last_attempt_at')
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'status', 'attempts', 'last_attempt_at', 'last_error'])
            ->map(fn (Model $outbox): array => [
                'source' => $source,
                'id' => (int) $outbox->getKey(),
                'status' => (string) $outbox->status,
                'attempts' => (int) $outbox->attempts,
                'last_attempt_at' => $outbox->last_attempt_at?->toISOString(),
                'last_error' => $outbox->last_error,
            ]))
            ->sortByDesc('last_attempt_at')
            ->take($limit)
            ->values()
            ->all();
    }
}
