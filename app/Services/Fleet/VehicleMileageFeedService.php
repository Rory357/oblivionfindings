<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Presenters\FleetVehicleTechnologyProjectionPresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\FleetVehicleMileageFeed;
use App\Models\FleetVehicleMileageFeedEvent;
use App\Models\FleetVehicleOdometerObservation;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The tracker distance feed. A person compares the dashboard with the
 * tracker's distance at one sample; the dashboard figure is kept as a recorded
 * reading and the tracker figure becomes the baseline. When automatic planning
 * is on, later tracker distance moves service and RUC planning forward from
 * that baseline. Readiness evidence always uses recorded readings.
 */
class VehicleMileageFeedService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly VehicleOdometerService $odometer,
        private readonly FleetVehicleTechnologyProjectionPresenter $technology,
    ) {}

    /**
     * The feed as the vehicle workspace shows it, or null when the viewer
     * may not see tracker data.
     *
     * @param  array<string,mixed>|null  $tracker  latest tracker estimate
     * @return array<string,mixed>|null
     */
    public function present(User $viewer, Asset $asset, ?FleetVehicleOdometerObservation $current, ?array $tracker, bool $canViewTechnology): ?array
    {
        if (! $canViewTechnology) {
            return null;
        }
        $feed = FleetVehicleMileageFeed::query()->where('asset_id', $asset->id)
            ->with(['baseline:id,value_km,observed_at', 'events' => fn ($events) => $events->with('actor:id,name')->orderByDesc('id')->limit(20)])
            ->first();
        $planning = $this->planning($asset, $current, $tracker, $feed);
        $manage = $viewer->canDo('fleet.manage');
        $device = empty($tracker['device_id']) ? null
            : Device::query()->whereKey((int) $tracker['device_id'])->first(['id', 'name', 'model']);

        return $planning + [
            'device_label' => $device ? ($device->model ?: $device->name) : null,
            'automatic' => (bool) $feed?->automatic,
            'tolerance_km' => $feed?->tolerance_km ?? (int) config('fleet.mileage_feed.default_tolerance_km', 25),
            'reconciled_at' => $feed?->reconciled_at?->toIso8601String(),
            'lock_version' => $feed?->lock_version ?? 0,
            'history' => $feed ? $feed->events->map(fn (FleetVehicleMileageFeedEvent $event): array => [
                'id' => (int) $event->id,
                'action' => $event->action,
                'actor' => $event->actor?->name,
                'dashboard_km' => $event->dashboard_km,
                'tracker_km' => $event->tracker_km,
                'automatic' => $event->automatic,
                'note' => $event->note,
                'at' => $event->created_at?->toIso8601String(),
            ])->values()->all() : [],
            'can' => [
                'reconcile' => $manage && $planning['fresh'],
                'pause' => $manage && (bool) $feed?->automatic,
            ],
        ];
    }

    /**
     * Planning distance: the calibrated tracker distance when automatic
     * planning is on and still matches the current reading, otherwise the
     * recorded reading.
     *
     * @param  array<string,mixed>|null  $tracker
     * @return array{available:bool,fresh:bool,verified_km:?float,verified_at:?string,estimate_km:?float,raw_tracker_km:?float,tracker_observed_at:?string,difference_km:?float,reconciled:bool,usable:bool,planning_km:?float}
     */
    public function planning(Asset $asset, ?FleetVehicleOdometerObservation $current, ?array $tracker, ?FleetVehicleMileageFeed $feed = null): array
    {
        $feed ??= FleetVehicleMileageFeed::query()->where('asset_id', $asset->id)->first();
        $verified = $current ? (float) $current->value_km : null;
        $raw = $tracker ? (float) $tracker['value_km'] : null;
        $observedAt = $tracker['observed_at'] ?? null;
        $fresh = $raw !== null && $observedAt !== null
            && CarbonImmutable::parse($observedAt)->greaterThanOrEqualTo(now()->subMinutes((int) config('fleet.mileage_feed.fresh_minutes', 60)));
        $reconciled = $feed && $current && (int) $feed->baseline_observation_id === (int) $current->id && $feed->baseline_tracker_km !== null;
        // After a reconciliation the tracker's distance is counted from that baseline.
        $estimate = $raw === null ? null
            : ($feed && $feed->baseline_tracker_km !== null && $feed->baseline
                ? round((float) $feed->baseline->value_km + ($raw - $feed->baseline_tracker_km), 1)
                : $raw);
        $usable = $fresh && $reconciled && (bool) $feed?->automatic && $estimate !== null && $verified !== null && $estimate >= $verified;

        return [
            'available' => $raw !== null,
            'fresh' => $fresh,
            'verified_km' => $verified,
            'verified_at' => $current?->observed_at?->toIso8601String(),
            'estimate_km' => $estimate,
            'raw_tracker_km' => $raw,
            'tracker_observed_at' => $observedAt,
            'difference_km' => $estimate !== null && $verified !== null ? round($estimate - $verified, 1) : null,
            'reconciled' => (bool) $reconciled,
            'usable' => $usable,
            'planning_km' => $usable ? $estimate : $verified,
        ];
    }

    /** @param array<string,mixed> $data */
    public function reconcile(User $actor, int $assetId, array $data, string $requestKey): FleetVehicleMileageFeed
    {
        self::assertKey($requestKey);
        Validator::make($data, [
            'value_km' => ['required', 'integer', 'min:0', 'max:9999999'],
            'reason' => ['required', 'string', 'max:2000'],
            'automatic' => ['required', 'boolean'],
            'tolerance_km' => ['required', 'integer', 'min:1', 'max:5000'],
            'confirmed' => ['accepted'],
        ], [
            'reason.required' => 'Record the observation and explain any difference.',
            'confirmed.accepted' => 'Confirm you checked the dashboard against this tracker sample.',
        ], ['value_km' => 'dashboard reading', 'tolerance_km' => 'review difference'])->validate();
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'asset' => $assetId, 'data' => [
            'value_km' => (int) $data['value_km'], 'reason' => trim((string) $data['reason']),
            'automatic' => filter_var($data['automatic'], FILTER_VALIDATE_BOOL), 'tolerance_km' => (int) $data['tolerance_km'],
        ]]);

        return DB::transaction(function () use ($actor, $assetId, $data, $requestKey, $fingerprint): FleetVehicleMileageFeed {
            [$current, $asset] = $this->manager($actor, $assetId);
            // Insert-or-ignore, then a locking read: two first reconciles can't
            // both insert, and the lock sees rows a stale snapshot would miss.
            DB::table('fleet_vehicle_mileage_feeds')->insertOrIgnore([
                'asset_id' => $asset->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $feed = FleetVehicleMileageFeed::query()->where('asset_id', $asset->id)->lockForUpdate()->firstOrFail();
            if ($prior = $feed->events()->where('request_key', $requestKey)->lockForUpdate()->first()) {
                abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
                    'This request was already used for a different reconciliation.');

                return $feed;
            }
            $tracker = $this->odometer->latestTrackerEstimate((int) $asset->id);
            $planning = $this->planning($asset, $this->odometer->currentObserved((int) $asset->id, true), $tracker, $feed);
            if (! $planning['fresh']) {
                throw ValidationException::withMessages(['value_km' => 'A current tracker sample is needed. Record a manual reading while the tracker is unavailable.']);
            }
            if ($planning['verified_at'] !== null && CarbonImmutable::parse($planning['verified_at'])
                ->greaterThan(CarbonImmutable::parse((string) $tracker['observed_at']))) {
                throw ValidationException::withMessages(['value_km' => 'The dashboard record is newer than this tracker sample. Wait for a newer sample before reconciling.']);
            }
            if ($planning['verified_km'] !== null && (int) $data['value_km'] < $planning['verified_km']) {
                throw ValidationException::withMessages(['value_km' => 'Use a reading at least as high as the current record. Correct an incorrect earlier reading in Mileage history first.']);
            }
            // The dashboard figure is kept as an ordinary recorded reading at the tracker sample's time.
            $reading = $this->odometer->recordManual($current, (int) $asset->id, [
                'value_km' => (int) $data['value_km'],
                'observed_at' => (string) $tracker['observed_at'],
                'source_reference' => 'Dashboard / tracker cross-check',
                'notes' => trim((string) $data['reason']),
            ], $requestKey.':reading');
            $automatic = filter_var($data['automatic'], FILTER_VALIDATE_BOOL);
            $feed->forceFill([
                'automatic' => $automatic,
                'tolerance_km' => (int) $data['tolerance_km'],
                'baseline_observation_id' => $reading->id,
                'baseline_tracker_km' => (float) $tracker['value_km'],
                'baseline_event_id' => $tracker['event_id'] ?? null,
                'reconciled_at' => now(),
                'reconciled_by_user_id' => $current->id,
                'paused_at' => $automatic ? null : $feed->paused_at,
                'lock_version' => $feed->lock_version + 1,
            ])->save();
            $feed->events()->create([
                'action' => 'reconciled', 'actor_user_id' => $current->id,
                'dashboard_km' => (int) $data['value_km'], 'tracker_km' => (float) $tracker['value_km'],
                'automatic' => $automatic, 'tolerance_km' => (int) $data['tolerance_km'],
                'note' => trim((string) $data['reason']),
                'request_key' => $requestKey, 'request_fingerprint' => $fingerprint,
            ]);
            AuditLogger::logOrFail('fleet.mileage_feed.reconcile', $feed, [
                'asset_id' => $asset->id, 'observation_id' => $reading->id, 'dashboard_km' => (int) $data['value_km'],
                'tracker_km' => (float) $tracker['value_km'], 'automatic' => $automatic, 'tolerance_km' => (int) $data['tolerance_km'],
            ]);

            return $feed;
        }, 3);
    }

    public function pause(User $actor, int $assetId, string $reason, int $expectedVersion, string $requestKey): FleetVehicleMileageFeed
    {
        self::assertKey($requestKey);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Record why automatic planning is paused.']);
        }
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'asset' => $assetId, 'pause' => $reason]);

        return DB::transaction(function () use ($actor, $assetId, $reason, $expectedVersion, $requestKey, $fingerprint): FleetVehicleMileageFeed {
            [$current, $asset] = $this->manager($actor, $assetId);
            $feed = FleetVehicleMileageFeed::query()->where('asset_id', $asset->id)->lockForUpdate()->first() ?? abort(404);
            if ($prior = $feed->events()->where('request_key', $requestKey)->lockForUpdate()->first()) {
                abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
                    'This request was already used for a different change.');

                return $feed;
            }
            abort_unless($feed->lock_version === $expectedVersion, 409, 'The feed changed while you were reviewing it. Reload and try again.');
            abort_unless($feed->automatic, 409, 'Automatic planning is already off.');
            $feed->forceFill(['automatic' => false, 'paused_at' => now(), 'lock_version' => $feed->lock_version + 1])->save();
            $feed->events()->create([
                'action' => 'paused', 'actor_user_id' => $current->id, 'automatic' => false, 'note' => $reason,
                'request_key' => $requestKey, 'request_fingerprint' => $fingerprint,
            ]);
            AuditLogger::logOrFail('fleet.mileage_feed.pause', $feed, ['asset_id' => $asset->id, 'reason' => $reason]);

            return $feed;
        }, 3);
    }

    /** @return array{0:User,1:Asset} */
    private function manager(User $actor, int $assetId): array
    {
        $current = User::query()->findOrFail($actor->id);
        abort_unless($current->canDo('fleet.manage'), 403);
        $asset = $this->access->assignableVehicle($current, $assetId, true) ?? abort(404);
        // The feed works from tracker figures, which are separately permissioned.
        abort_unless($this->technology->canView($current, $asset), 403);

        return [$current, $asset];
    }

    private static function assertKey(string $requestKey): void
    {
        if (mb_strlen($requestKey) < 8 || mb_strlen($requestKey) > 90) {
            throw ValidationException::withMessages(['request_key' => 'Reload and try again.']);
        }
    }
}
