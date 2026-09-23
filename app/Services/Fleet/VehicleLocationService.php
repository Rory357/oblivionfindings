<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Presenters\FleetVehicleTechnologyProjectionPresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\FleetTrip;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use App\Support\SchemaCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The vehicle profile's Location & geofences view: the last reported state,
 * recorded positions to review on the map, the recorded route behind one of
 * them, and the vehicle's geofences.
 *
 * Positions follow the trip rules (FleetTripSiteScope, the same Site boundary
 * the trip history uses). Consent-blocked telemetry never carries a position,
 * and positions recorded during a personal trip are withheld. A recorded
 * position is never presented as live, and it never establishes custody or
 * readiness.
 */
final class VehicleLocationService
{
    /** Reports older than this are shown as last known, not current. */
    public const FRESH_MINUTES = 15;

    public const RECENT_TRIPS = 5;

    public const TRAIL_POINT_CAP = 250;

    /** The same Site bypass the trip index and playback use. */
    private const SITE_BYPASS_PERMISSIONS = ['fleet.manage'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly SecurityDevicesAccessService $access,
        private readonly VehicleGeofenceService $geofences,
        private readonly FleetVehicleTechnologyProjectionPresenter $technology,
    ) {}

    public static function zone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }

    /** @return array<string,mixed> */
    public function present(User $user, Asset $vehicle): array
    {
        $vehicle->loadMissing(['site:id,name,latitude,longitude', 'homeSite:id,name,latitude,longitude']);
        $positions = $this->positionsVisible($user, $vehicle);
        $snapshot = FleetVehicleStateSnapshot::query()
            ->with(['lastEvent:id,occurred_at,received_at,external_power,event_type', 'lastTrip:id,is_personal,consent_blocked,started_at,ended_at'])
            ->find($vehicle->getKey());
        $state = $snapshot ? $this->state($vehicle, $snapshot, $positions) : null;
        $canViewAlerts = $user->canDo('controlRoom.viewAny')
            || $user->canDo('controlRoom.alerts.view')
            || $user->canDo('assets.alerts.view');

        return [
            'as_of' => now()->toIso8601String(),
            'timezone' => self::zone(),
            'fresh_minutes' => self::FRESH_MINUTES,
            'vehicle' => [
                'id' => (int) $vehicle->getKey(),
                'name' => (string) $vehicle->name,
                'registration_number' => $this->text($vehicle->registration_number ?? null),
                'asset_tag' => $this->text($vehicle->asset_tag ?? null),
                'home_site' => $this->homeSite($vehicle),
            ],
            'tracker' => ['linked' => $snapshot !== null || $this->trackerLinked($vehicle)],
            'positions_visible' => $positions,
            'state' => $state,
            'observations' => $positions ? $this->observations($vehicle, $state) : [],
            'alerts' => [
                'open' => $canViewAlerts
                    ? ControlRoomAlert::query()->actionable()->where('asset_id', $vehicle->getKey())->count()
                    : null,
            ],
            'geofences' => $this->geofences->linked($user, $vehicle),
            'can' => [
                'view_telemetry' => $this->technology->canView($user, $vehicle),
                'view_alerts' => $canViewAlerts,
                'add_reminder' => $user->canDo('fleet.manage'),
            ],
        ];
    }

    /**
     * The recorded route of one trip, for the map's historical trail.
     *
     * @return array<string,mixed>
     */
    public function trail(User $user, Asset $vehicle, ?int $tripId): array
    {
        abort_unless($this->positionsVisible($user, $vehicle), 404);
        $trip = $tripId !== null
            ? $this->trips($vehicle)->whereKey($tripId)->first()
            : $this->trips($vehicle)->orderByDesc('started_at')->orderByDesc('id')->first();
        abort_if($trip === null, 404);
        $facts = [
            'id' => (int) $trip->id,
            'started_at' => $trip->started_at?->toIso8601String(),
            'ended_at' => $trip->ended_at?->toIso8601String(),
            'distance_km' => $trip->distance_km === null ? null : round((float) $trip->distance_km, 1),
        ];
        if ($trip->consent_blocked || $trip->is_personal) {
            return ['trip' => $facts, 'points' => [], 'recorded_points' => 0, 'downsampled' => false,
                'withheld' => $trip->consent_blocked ? 'consent' : 'personal'];
        }

        $to = $trip->ended_at ?? now();
        $rows = DB::table('fleet_telemetry_events')
            ->where('asset_id', $vehicle->getKey())
            ->where('consent_blocked', false)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereBetween('occurred_at', [$trip->started_at->copy()->utc()->format('Y-m-d H:i:s'), $to->copy()->utc()->format('Y-m-d H:i:s')])
            ->orderBy('occurred_at')->orderBy('id')
            ->limit(20000)
            ->get(['latitude', 'longitude', 'occurred_at']);
        $points = $rows->map(fn (object $row): array => [
            'lat' => round((float) $row->latitude, 7),
            'lng' => round((float) $row->longitude, 7),
            'at' => CarbonImmutable::parse((string) $row->occurred_at, 'UTC')->toIso8601String(),
        ])->values()->all();
        $recorded = count($points);
        if ($recorded > self::TRAIL_POINT_CAP) {
            $stride = ($recorded - 1) / (self::TRAIL_POINT_CAP - 1);
            $sampled = [];
            for ($index = 0; $index < self::TRAIL_POINT_CAP; $index++) {
                $sampled[] = $points[(int) round($index * $stride)];
            }
            $points = $sampled;
        }
        AuditLogger::log('fleet.vehicle.location.trail.view', $trip, [
            'asset_id' => $vehicle->getKey(),
            'trip_id' => $trip->id,
        ]);

        return ['trip' => $facts, 'points' => $points, 'recorded_points' => $recorded,
            'downsampled' => $recorded > self::TRAIL_POINT_CAP, 'withheld' => null];
    }

    /**
     * Places to centre the boundary editor on: the viewer's Sites with a
     * recorded location. Street addresses come from the shared geocoder.
     *
     * @return list<array<string,mixed>>
     */
    public function sitePlaces(User $user, Asset $vehicle, string $search): array
    {
        $search = trim(mb_substr($search, 0, 100));
        $homeId = (int) ($vehicle->home_site_id ?? $vehicle->site_id ?? 0);

        return $this->access->accessibleSites($user)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $match) => $match->where('name', 'like', $like)
                    ->orWhere('address_line_1', 'like', $like)
                    ->orWhere('suburb', 'like', $like)
                    ->orWhere('city', 'like', $like));
            })
            ->orderByRaw('CASE WHEN sites.id = ? THEN 0 ELSE 1 END', [$homeId])
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'address_line_1', 'suburb', 'city', 'latitude', 'longitude'])
            ->map(fn (Site $site): array => [
                'key' => 'site:'.$site->id,
                'kind' => 'site',
                'label' => (string) $site->name,
                'detail' => collect([(int) $site->id === $homeId ? 'Home site' : 'Site', $site->address_line_1, $site->suburb ?? $site->city])
                    ->filter()->implode(' · '),
                'lat' => round((float) $site->latitude, 7),
                'lng' => round((float) $site->longitude, 7),
            ])->values()->all();
    }

    /** Whether this viewer may see this vehicle's recorded positions (the trip Site rule). */
    public function positionsVisible(User $user, Asset $vehicle): bool
    {
        $siteIds = array_values(array_map('intval', $this->siteAccess->accessibleSiteIds($user, self::SITE_BYPASS_PERMISSIONS)));

        return FleetTripSiteScope::vehicles($siteIds)->whereKey($vehicle->getKey())->exists();
    }

    /** @return array<string,mixed> */
    private function state(Asset $vehicle, FleetVehicleStateSnapshot $snapshot, bool $positions): array
    {
        $event = $snapshot->lastEvent;
        $observed = $event?->occurred_at ?? $snapshot->last_seen_at;
        $withheld = match (true) {
            (bool) $snapshot->consent_blocked => 'consent',
            ! $positions => 'access',
            $this->personalAt($vehicle, $observed, $snapshot->lastTrip) => 'personal',
            default => null,
        };
        $hasPosition = $withheld === null && $snapshot->latitude !== null && $snapshot->longitude !== null;
        $fresh = $observed !== null
            && $snapshot->status !== 'offline'
            && $observed->greaterThanOrEqualTo(now()->subMinutes(self::FRESH_MINUTES));
        $trip = $snapshot->lastTrip;

        return [
            'event_id' => $event ? (int) $event->id : null,
            'observed_at' => $observed?->toIso8601String(),
            'received_at' => ($event?->received_at ?? $snapshot->last_seen_at)?->toIso8601String(),
            'lat' => $hasPosition ? round((float) $snapshot->latitude, 7) : null,
            'lng' => $hasPosition ? round((float) $snapshot->longitude, 7) : null,
            'withheld' => $withheld,
            'speed_kph' => $withheld === null && $snapshot->speed_kph !== null ? round((float) $snapshot->speed_kph, 1) : null,
            'heading_deg' => $withheld === null ? $snapshot->heading_deg : null,
            'ignition' => $snapshot->ignition,
            'motion' => self::motion($snapshot->motion_status, $withheld === null ? $snapshot->speed_kph : null),
            'battery_pct' => $snapshot->battery_pct,
            'external_power' => $event?->external_power,
            'status' => (string) $snapshot->status,
            'fresh' => $fresh,
            'trip_id' => $trip && ! $trip->is_personal && ! $trip->consent_blocked ? (int) $trip->id : null,
        ];
    }

    /**
     * The latest report and where recent trips ended, newest first.
     *
     * @param  array<string,mixed>|null  $state
     * @return list<array<string,mixed>>
     */
    private function observations(Asset $vehicle, ?array $state): array
    {
        $observations = [];
        if ($state !== null && $state['observed_at'] !== null) {
            $observations[] = [
                'id' => 'current',
                'kind' => 'latest',
                'observed_at' => $state['observed_at'],
                'lat' => $state['lat'],
                'lng' => $state['lng'],
                'address' => null,
                'trip_id' => $state['trip_id'],
            ];
        }
        $trips = $this->trips($vehicle)
            ->whereNotNull('ended_at')
            ->where('is_personal', false)->where('consent_blocked', false)
            ->whereNotNull('end_latitude')->whereNotNull('end_longitude')
            ->orderByDesc('ended_at')->orderByDesc('id')
            ->limit(self::RECENT_TRIPS)
            ->get(['id', 'ended_at', 'end_latitude', 'end_longitude', 'end_address']);
        foreach ($trips as $trip) {
            $observations[] = [
                'id' => 'trip:'.$trip->id,
                'kind' => 'trip_end',
                'observed_at' => $trip->ended_at?->toIso8601String(),
                'lat' => round((float) $trip->end_latitude, 7),
                'lng' => round((float) $trip->end_longitude, 7),
                'address' => $this->text($trip->end_address),
                'trip_id' => (int) $trip->id,
            ];
        }

        return $observations;
    }

    /** Trips that belong in the vehicle's history (voided trips are left out). */
    private function trips(Asset $vehicle): Builder
    {
        return FleetTrip::query()->where('asset_id', $vehicle->getKey())
            ->where(fn (Builder $status) => $status->whereNull('status')->orWhere('status', '!=', 'cancelled'));
    }

    private function personalAt(Asset $vehicle, mixed $at, ?FleetTrip $lastTrip): bool
    {
        if ($lastTrip?->is_personal && ($lastTrip->ended_at === null || $at === null
            || CarbonImmutable::instance($at)->lessThanOrEqualTo($lastTrip->ended_at))) {
            return true;
        }
        if ($at === null) {
            return false;
        }
        $moment = CarbonImmutable::instance($at)->utc()->format('Y-m-d H:i:s');

        return FleetTrip::query()->where('asset_id', $vehicle->getKey())->where('is_personal', true)
            ->where('started_at', '<=', $moment)
            ->where(fn (Builder $end) => $end->whereNull('ended_at')->orWhere('ended_at', '>=', $moment))
            ->exists();
    }

    /** @return array<string,mixed>|null */
    private function homeSite(Asset $vehicle): ?array
    {
        $site = $vehicle->homeSite ?? $vehicle->site;
        if (! $site) {
            return null;
        }

        return [
            'id' => (int) $site->id,
            'name' => (string) $site->name,
            'lat' => $site->latitude !== null ? round((float) $site->latitude, 7) : null,
            'lng' => $site->longitude !== null ? round((float) $site->longitude, 7) : null,
        ];
    }

    private function trackerLinked(Asset $vehicle): bool
    {
        return (SchemaCache::hasTable('device_asset_links') && DB::table('device_asset_links')
            ->where('asset_id', $vehicle->getKey())->whereNull('unlinked_at')->exists())
            || (SchemaCache::hasTable('asset_trackers') && DB::table('asset_trackers')
                ->where('asset_id', $vehicle->getKey())->where('status', 'paired')->exists());
    }

    /** Reported motion in two honest states, or unknown. */
    public static function motion(?string $reported, mixed $speed): ?string
    {
        $reported = strtolower(trim((string) $reported));
        if (in_array($reported, ['moving', 'motion', 'driving', 'move'], true)) {
            return 'moving';
        }
        if (in_array($reported, ['stationary', 'rest', 'stopped', 'stop', 'parked', 'park', 'idle'], true)) {
            return 'stationary';
        }
        if ($speed === null || $speed === '') {
            return null;
        }

        return (float) $speed > 3 ? 'moving' : 'stationary';
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
