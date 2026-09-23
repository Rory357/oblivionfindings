<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\FleetObligationReminder;
use App\Models\FleetObligationReminderEvent;
use App\Models\FleetServiceSchedule;
use App\Models\FleetVehicleComplianceRecord;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reminders for vehicle obligations: each due point of an active service
 * schedule or an applicable compliance record. The owner is told in the app
 * when the due point comes within its lead time; every attempt is kept.
 * Acknowledging a reminder never completes the service or the evidence.
 */
class VehicleObligationReminderService
{
    public const SOURCES = ['service_schedule', 'compliance_record'];

    private const ZONE = 'Pacific/Auckland';

    public const STATUS_LABELS = [
        FleetObligationReminder::STATE_SCHEDULED => 'Scheduled',
        FleetObligationReminder::STATE_SENT => 'Sent',
        FleetObligationReminder::STATE_FAILED => 'Delivery failed',
        FleetObligationReminder::STATE_ACKNOWLEDGED => 'Acknowledged',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly VehicleOdometerService $odometer,
        private readonly VehicleMileageFeedService $mileageFeed,
    ) {}

    /**
     * The vehicle's obligations with the reminder for each current due point.
     *
     * @return list<array<string,mixed>>
     */
    public function forVehicle(User $viewer, Asset $asset, ?float $odometerKm): array
    {
        $obligations = $this->obligations([$asset], [(int) $asset->id => $odometerKm])[(int) $asset->id] ?? [];
        if ($obligations === []) {
            return [];
        }
        $rows = FleetObligationReminder::query()->where('asset_id', $asset->id)
            ->whereIn('cycle_key', array_column($obligations, 'cycle_key'))
            ->with(['events' => fn ($events) => $events->with(['actor:id,name', 'recipient:id,name'])->orderByDesc('id')->limit(20),
                'acknowledgedBy:id,name'])
            ->get()->keyBy(fn (FleetObligationReminder $row): string => $row->source_type.':'.$row->source_id.'|'.$row->cycle_key);
        $owners = User::query()->whereKey(array_filter(array_column($obligations, 'owner_user_id')))->pluck('name', 'id');
        $manage = $viewer->canDo('fleet.manage');

        return array_map(function (array $obligation) use ($rows, $owners, $manage, $viewer): array {
            $row = $rows->get($obligation['source_type'].':'.$obligation['source_id'].'|'.$obligation['cycle_key']);
            $state = $row?->state ?? FleetObligationReminder::STATE_SCHEDULED;
            $isOwner = $obligation['owner_user_id'] !== null && (int) $obligation['owner_user_id'] === (int) $viewer->id;

            return [
                'key' => $obligation['source_type'].':'.$obligation['source_id'],
                'source_type' => $obligation['source_type'],
                'source_id' => $obligation['source_id'],
                'name' => $obligation['name'],
                'due_on' => $obligation['due_on'],
                'due_km' => $obligation['due_km'],
                'lead' => $obligation['lead'],
                'due_now' => $obligation['due_now'],
                'owner' => $obligation['owner_user_id'] ? [
                    'id' => (int) $obligation['owner_user_id'],
                    'name' => (string) ($owners[$obligation['owner_user_id']] ?? 'Owner unavailable'),
                ] : null,
                'reminder_id' => $row?->id,
                'state' => $state,
                'status_label' => self::STATUS_LABELS[$state],
                'last_attempt_at' => $row?->last_attempt_at?->toIso8601String(),
                'last_error' => $row?->last_error,
                'acknowledged_at' => $row?->acknowledged_at?->toIso8601String(),
                'acknowledged_by' => $row?->acknowledgedBy?->name,
                'events' => $row ? $row->events->map(fn (FleetObligationReminderEvent $event): array => [
                    'id' => (int) $event->id,
                    'action' => $event->action,
                    'channel' => $event->channel,
                    'recipient' => $event->recipient?->name,
                    'actor' => $event->actor?->name,
                    'note' => $event->note,
                    'at' => $event->created_at?->toIso8601String(),
                ])->values()->all() : [],
                'can' => [
                    'acknowledge' => ($manage || $isOwner) && $state !== FleetObligationReminder::STATE_ACKNOWLEDGED,
                    'retry' => $manage && $state === FleetObligationReminder::STATE_FAILED,
                ],
            ];
        }, $obligations);
    }

    /**
     * Tell owners about obligations that have come within their lead time.
     * Each due point is delivered once; a failed delivery is tried again the
     * next day until it goes through or is acknowledged.
     *
     * @return array{sent:int,failed:int}
     */
    public function deliverDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $counts = ['sent' => 0, 'failed' => 0];
        Asset::query()->vehicles()->where('status', 'active')->orderBy('id')
            ->chunkById(100, function ($assets) use ($now, &$counts): void {
                // Planning distance: the recorded reading, or the calibrated tracker distance when enabled.
                $readings = $this->odometer->currentObservedMany($assets->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
                $odometers = [];
                foreach ($assets as $asset) {
                    $odometers[(int) $asset->id] = $this->mileageFeed->planning($asset, $readings[(int) $asset->id] ?? null,
                        $this->odometer->latestTrackerEstimate((int) $asset->id))['planning_km'];
                }
                foreach ($this->obligations($assets->all(), $odometers, $now) as $assetId => $obligations) {
                    foreach ($obligations as $obligation) {
                        if (! $obligation['due_now']) {
                            continue;
                        }
                        $outcome = $this->deliverScheduled((int) $assetId, $obligation, $now);
                        if ($outcome !== null) {
                            $counts[$outcome]++;
                        }
                    }
                }
            });

        return $counts;
    }

    public function acknowledge(User $actor, int $assetId, string $sourceType, int $sourceId, string $note, string $requestKey): FleetObligationReminder
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['note' => 'Record what was done or who is following up.']);
        }
        self::assertKey($requestKey);

        return DB::transaction(function () use ($actor, $assetId, $sourceType, $sourceId, $note, $requestKey): FleetObligationReminder {
            [$current, $asset, $obligation] = $this->resolve($actor, $assetId, $sourceType, $sourceId);
            $isOwner = $obligation['owner_user_id'] !== null && (int) $obligation['owner_user_id'] === (int) $current->id;
            abort_unless($current->canDo('fleet.manage') || $isOwner, 403);
            $reminder = $this->cycleRow((int) $asset->id, $obligation, true);
            if ($this->replayed($reminder, $requestKey)) {
                return $reminder;
            }
            if ($reminder->state === FleetObligationReminder::STATE_ACKNOWLEDGED) {
                return $reminder;
            }
            $reminder->forceFill([
                'state' => FleetObligationReminder::STATE_ACKNOWLEDGED,
                'acknowledged_by_user_id' => $current->id,
                'acknowledged_at' => now(),
                'acknowledgement_note' => $note,
                'lock_version' => $reminder->lock_version + 1,
            ])->save();
            $reminder->events()->create([
                'action' => 'acknowledged', 'actor_user_id' => $current->id, 'note' => $note, 'request_key' => $requestKey,
            ]);
            AuditLogger::logOrFail('fleet.obligation_reminder.acknowledge', $reminder, [
                'asset_id' => $asset->id, 'source_type' => $sourceType, 'source_id' => $sourceId,
                'cycle' => $reminder->cycle_key, 'note' => $note,
            ]);

            return $reminder;
        }, 3);
    }

    public function retry(User $actor, int $assetId, string $sourceType, int $sourceId, string $requestKey): FleetObligationReminder
    {
        self::assertKey($requestKey);

        return DB::transaction(function () use ($actor, $assetId, $sourceType, $sourceId, $requestKey): FleetObligationReminder {
            [$current, $asset, $obligation] = $this->resolve($actor, $assetId, $sourceType, $sourceId);
            abort_unless($current->canDo('fleet.manage'), 403);
            $reminder = $this->cycleRow((int) $asset->id, $obligation, true);
            if ($this->replayed($reminder, $requestKey)) {
                return $reminder;
            }
            abort_unless($reminder->state === FleetObligationReminder::STATE_FAILED, 409,
                'Only a failed delivery can be tried again. Reload the reminders.');
            $this->deliver($asset, $reminder, $obligation, $current, $requestKey);
            AuditLogger::logOrFail('fleet.obligation_reminder.retry', $reminder, [
                'asset_id' => $asset->id, 'source_type' => $sourceType, 'source_id' => $sourceId,
                'cycle' => $reminder->cycle_key, 'outcome' => $reminder->state,
            ]);

            return $reminder;
        }, 3);
    }

    /**
     * Current obligations for some vehicles, keyed by asset id.
     *
     * @param  list<Asset>  $assets
     * @param  array<int,?float>  $odometers
     * @return array<int,list<array{source_type:string,source_id:int,name:string,cycle_key:string,due_on:?string,due_km:?float,owner_user_id:?int,lead:string,due_now:bool}>>
     */
    private function obligations(array $assets, array $odometers, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone(self::ZONE)->startOfDay();
        $byId = collect($assets)->keyBy(fn (Asset $asset): int => (int) $asset->id);
        $ids = $byId->keys()->all();
        if ($ids === []) {
            return [];
        }
        $scheduleLead = (int) config('fleet.obligation_reminders.schedule_lead_days', 14);
        $complianceLead = (int) config('fleet.obligation_reminders.compliance_lead_days', 7);
        $rucLeadKm = (float) config('fleet.obligation_reminders.ruc_lead_km', 1000);
        $result = [];

        FleetServiceSchedule::query()->whereIn('asset_id', $ids)->where('is_active', true)
            ->where(fn ($due) => $due->whereNotNull('next_due_at')->orWhereNotNull('next_due_km'))
            ->orderBy('next_due_at')->orderBy('id')->get()
            ->each(function (FleetServiceSchedule $schedule) use (&$result, $byId, $odometers, $today, $scheduleLead): void {
                $asset = $byId->get((int) $schedule->asset_id);
                $dueOn = $schedule->next_due_at?->toDateString();
                $dueKm = $schedule->next_due_km === null ? null : (float) $schedule->next_due_km;
                $leadDays = $schedule->reminder_days_before ?? $scheduleLead;
                $leadKm = $schedule->reminder_km_before === null ? null : (float) $schedule->reminder_km_before;
                $odometer = $odometers[(int) $schedule->asset_id] ?? null;
                $byDate = $dueOn !== null && $today->greaterThanOrEqualTo(
                    CarbonImmutable::parse($dueOn, self::ZONE)->subDays((int) $leadDays));
                $byKm = $dueKm !== null && $odometer !== null && $odometer >= $dueKm - ($leadKm ?? 0);
                $result[(int) $schedule->asset_id][] = [
                    'source_type' => 'service_schedule',
                    'source_id' => (int) $schedule->id,
                    'name' => (string) ($schedule->name ?: 'Service'),
                    'cycle_key' => self::cycleKey($dueOn, $dueKm),
                    'due_on' => $dueOn,
                    'due_km' => $dueKm,
                    'owner_user_id' => $schedule->owner_user_id ?: ($asset?->fleet_responsible_user_id ?: null),
                    'lead' => trim(($dueOn !== null ? $leadDays.' days' : '')
                        .($dueOn !== null && $leadKm !== null ? ' or ' : '')
                        .($leadKm !== null ? number_format($leadKm).' km' : '')) ?: $leadDays.' days',
                    'due_now' => $byDate || $byKm,
                ];
            });

        FleetVehicleComplianceRecord::query()->whereIn('asset_id', $ids)->whereNotNull('current_version_id')
            ->with('currentVersion')->orderBy('id')->get()
            ->each(function (FleetVehicleComplianceRecord $record) use (&$result, $byId, $odometers, $today, $complianceLead, $rucLeadKm): void {
                $version = $record->currentVersion;
                if (! $version || $version->applicability !== 'applicable') {
                    return;
                }
                $dueOn = $version->expires_on?->toDateString();
                $dueKm = $record->kind === 'ruc' && $version->ruc_end_km !== null ? (float) $version->ruc_end_km : null;
                if ($dueOn === null && $dueKm === null) {
                    return;
                }
                $odometer = $odometers[(int) $record->asset_id] ?? null;
                $byDate = $dueOn !== null && $today->greaterThanOrEqualTo(
                    CarbonImmutable::parse($dueOn, self::ZONE)->subDays($complianceLead));
                $byKm = $dueKm !== null && $odometer !== null && $odometer >= $dueKm - $rucLeadKm;
                $label = VehicleComplianceService::LABELS[$record->kind] ?? (string) $record->kind;
                $result[(int) $record->asset_id][] = [
                    'source_type' => 'compliance_record',
                    'source_id' => (int) $record->id,
                    'name' => $dueKm !== null && $dueOn === null ? $label.' licence' : $label.' expiry',
                    'cycle_key' => self::cycleKey($dueOn, $dueKm),
                    'due_on' => $dueOn,
                    'due_km' => $dueKm,
                    'owner_user_id' => $byId->get((int) $record->asset_id)?->fleet_responsible_user_id ?: null,
                    'lead' => $dueOn !== null ? $complianceLead.' days' : number_format($rucLeadKm).' km',
                    'due_now' => $byDate || $byKm,
                ];
            });

        return $result;
    }

    /** Deliver a due point that hasn't gone out yet, or retry yesterday's failure. */
    private function deliverScheduled(int $assetId, array $obligation, CarbonImmutable $now): ?string
    {
        try {
            return DB::transaction(function () use ($assetId, $obligation, $now): ?string {
                $asset = Asset::query()->whereKey($assetId)->lockForUpdate()->first();
                if (! $asset) {
                    return null;
                }
                $reminder = $this->cycleRow($assetId, $obligation, true);
                $due = $reminder->state === FleetObligationReminder::STATE_SCHEDULED
                    || ($reminder->state === FleetObligationReminder::STATE_FAILED
                        && ($reminder->last_attempt_at === null || $reminder->last_attempt_at->lessThan($now->subHours(20))));
                if (! $due) {
                    return null;
                }
                $this->deliver($asset, $reminder, $obligation, null, null);

                return $reminder->state === FleetObligationReminder::STATE_SENT ? 'sent' : 'failed';
            }, 3);
        } catch (QueryException $exception) {
            report($exception);

            return null;
        }
    }

    /** One in-app delivery attempt to the owner; the outcome is recorded either way. */
    private function deliver(Asset $asset, FleetObligationReminder $reminder, array $obligation, ?User $actor, ?string $requestKey): void
    {
        // Deliver to whoever owns the obligation now, so fixing a missing owner and retrying works.
        $reminder->owner_user_id = $obligation['owner_user_id'];
        $owner = $reminder->owner_user_id ? User::query()->find($reminder->owner_user_id) : null;
        $error = null;
        if (! $owner) {
            $error = 'No owner to notify. Record a responsible person for this vehicle or schedule.';
        } else {
            $dueLabel = $obligation['due_on'] !== null
                ? CarbonImmutable::parse($obligation['due_on'], self::ZONE)->format('j M Y')
                : number_format((float) $obligation['due_km']).' km';
            try {
                $owner->notify(new AppEventNotification([
                    'kind' => 'fleet_obligation_reminder',
                    'event_key' => 'fleet.obligation_reminder.due',
                    'action' => 'reminder',
                    'entity' => 'Vehicle',
                    'entity_id' => $asset->id,
                    'title' => "{$obligation['name']} due {$dueLabel} · {$asset->name}",
                    'body' => 'Plan or record it on the vehicle. This reminder does not complete it.',
                    'url' => "/fleet-assets/vehicles/{$asset->id}?tab=service&view=reminders",
                    'actor' => $actor ? ['id' => $actor->id, 'name' => $actor->name] : null,
                ]));
            } catch (Throwable $exception) {
                report($exception);
                $error = 'The in-app notification could not be saved. Try again.';
            }
        }
        $reminder->forceFill([
            'state' => $error === null ? FleetObligationReminder::STATE_SENT : FleetObligationReminder::STATE_FAILED,
            'last_attempt_at' => now(),
            'last_error' => $error,
            'lock_version' => $reminder->lock_version + 1,
        ])->save();
        $reminder->events()->create([
            'action' => $error === null ? 'sent' : 'failed',
            'channel' => 'in_app',
            'recipient_user_id' => $owner?->id,
            'actor_user_id' => $actor?->id,
            'note' => $error,
            'request_key' => $requestKey,
        ]);
    }

    /** The reminder row for an obligation's current due point, created when first needed. */
    private function cycleRow(int $assetId, array $obligation, bool $lock): FleetObligationReminder
    {
        $find = fn () => FleetObligationReminder::query()->where('asset_id', $assetId)
            ->where('source_type', $obligation['source_type'])->where('source_id', $obligation['source_id'])
            ->where('cycle_key', $obligation['cycle_key'])->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $row = $find();
        if ($row) {
            return $row;
        }
        try {
            return FleetObligationReminder::query()->create([
                'asset_id' => $assetId,
                'source_type' => $obligation['source_type'],
                'source_id' => $obligation['source_id'],
                'cycle_key' => $obligation['cycle_key'],
                'due_on' => $obligation['due_on'],
                'due_km' => $obligation['due_km'],
                'state' => FleetObligationReminder::STATE_SCHEDULED,
                'owner_user_id' => $obligation['owner_user_id'],
                'lock_version' => 1,
            ]);
        } catch (QueryException $exception) {
            // Another request created it first.
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            return $find() ?? throw $exception;
        }
    }

    private function replayed(FleetObligationReminder $reminder, string $requestKey): bool
    {
        return $reminder->events()->where('request_key', $requestKey)->exists();
    }

    /** @return array{0:User,1:Asset,2:array<string,mixed>} */
    private function resolve(User $actor, int $assetId, string $sourceType, int $sourceId): array
    {
        abort_unless(in_array($sourceType, self::SOURCES, true), 404);
        $current = User::query()->findOrFail($actor->id);
        $asset = $this->access->assignableVehicle($current, $assetId, true) ?? abort(404);
        $odometer = $this->odometer->currentObserved((int) $asset->id);
        $obligation = collect($this->obligations([$asset], [(int) $asset->id => $odometer ? (float) $odometer->value_km : null])[(int) $asset->id] ?? [])
            ->first(fn (array $item): bool => $item['source_type'] === $sourceType && $item['source_id'] === $sourceId);

        return [$current, $asset, $obligation ?? abort(404)];
    }

    private static function cycleKey(?string $dueOn, ?float $dueKm): string
    {
        return implode('|', array_filter([
            $dueOn !== null ? 'date:'.$dueOn : null,
            $dueKm !== null ? 'km:'.rtrim(rtrim(number_format($dueKm, 1, '.', ''), '0'), '.') : null,
        ]));
    }

    private static function assertKey(string $requestKey): void
    {
        if (mb_strlen($requestKey) < 8 || mb_strlen($requestKey) > 100) {
            throw ValidationException::withMessages(['request_key' => 'Reload and try again.']);
        }
    }
}
