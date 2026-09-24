<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\FleetDriverSession;
use App\Models\FleetTrip;
use App\Models\FleetTripDriverConfirmation;
use App\Models\FleetVehicleBooking;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use App\Support\SchemaCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Trip history for one vehicle: the filtered trip list with its totals, one
 * trip's recorded journey and behaviour, driver attribution and the data
 * behind the PDF and Excel exports.
 *
 * Access is the intersection of the vehicle profile's rule
 * (SecurityDevicesAccessService) and the trip playback rule
 * (FleetTripSiteScope, as FleetTripController applies it); anything outside
 * either answers 404. Driver names are shown only to viewers at the driver's
 * Sites. Consent-blocked telemetry is never read; personal trips are listed
 * as the trips index lists them but never scored, totalled as driving
 * events or exported.
 *
 * One list request reads a limited stretch of history: with no dates, the
 * latest DEFAULT_WINDOW_DAYS days of the vehicle's trips; a date range is
 * kept to MAX_RANGE_DAYS; and at most MAX_LIST_TRIPS trips, newest first.
 * The response says when a limit left trips out.
 */
final class VehicleTripHistoryService
{
    public const EVENTS = ['all', 'overspeed', 'faults', 'partial'];

    public const DEFAULT_PAGE_SIZE = 2;

    public const MAX_PAGE_SIZE = 25;

    /** "All recorded dates": the days up to and including the latest trip's day. */
    public const DEFAULT_WINDOW_DAYS = 90;

    /** The most days between a range's first and last date (a year, as exports allow). */
    public const MAX_RANGE_DAYS = 366;

    /** The most trips one list request reads and analyses, newest first. */
    public const MAX_LIST_TRIPS = 500;

    public const DETAIL_POINT_CAP = 2000;

    public const ROUTE_SKETCH_POINT_CAP = 400;

    public const PDF_TRIP_LIMIT = 200;

    public const SPREADSHEET_TRIP_LIMIT = 5000;

    /** The same Site bypass the trip index and playback use. */
    private const SITE_BYPASS_PERMISSIONS = ['fleet.manage'];

    private const REPORT_TYPE_SQL = "CASE WHEN event_type IN ('harsh_behaviour', 'speed_alarm') "
        ."THEN JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.report_id_type')) END AS report_type";

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly SecurityDevicesAccessService $deviceAccess,
        private readonly VehicleStaffDirectory $staff,
        private readonly VehicleBookingAccessService $bookingAccess,
    ) {}

    public static function zone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }

    /** The vehicle, when both the profile and the trip Site rules allow it. */
    public function vehicle(User $user, int $assetId, bool $lock = false): Asset
    {
        abort_unless($user->canDo('fleet.viewAny'), 403);
        $asset = $this->deviceAccess->assignableVehicle($user, $assetId, $lock) ?? abort(404);
        abort_unless(
            FleetTripSiteScope::vehicles($this->siteIds($user))->whereKey($asset->getKey())->exists(),
            404,
        );

        return $asset;
    }

    public function canConfirmDriver(User $user): bool
    {
        return $user->canDo('fleet.manage') || $user->canDo('fleet.trips.manage');
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{q:string,from:?string,to:?string,driver:string,event:string}
     */
    public function filters(array $input): array
    {
        $driver = trim((string) ($input['driver'] ?? 'all'));
        if (! in_array($driver, ['all', 'unassigned'], true) && ! ctype_digit($driver)) {
            $driver = 'all';
        }
        $event = (string) ($input['event'] ?? 'all');

        return [
            'q' => trim(mb_substr((string) ($input['q'] ?? ''), 0, 100)),
            'from' => self::date($input['from'] ?? null),
            'to' => self::date($input['to'] ?? null),
            'driver' => $driver === '' ? 'all' : $driver,
            'event' => in_array($event, self::EVENTS, true) ? $event : 'all',
        ];
    }

    /**
     * @param  array{q:string,from:?string,to:?string,driver:string,event:string}  $filters
     * @return array<string,mixed>
     */
    public function list(User $user, Asset $asset, array $filters, int $page = 1, int $perPage = self::DEFAULT_PAGE_SIZE, bool $summaryOnly = false): array
    {
        $window = $this->window($asset, $filters);
        $result = $this->pipeline($user, $asset, ['from' => $window['from'], 'to' => $window['to']] + $filters,
            $this->siteIds($user), self::MAX_LIST_TRIPS);
        $trips = $result['trips'];
        $analysis = $result['analysis'];
        $summary = $this->summary($trips, $analysis);
        $response = [
            'vehicle' => $this->vehicleIdentity($asset),
            'summary' => $summary,
            'filters' => $filters,
            // The dates actually read, and why they differ from the request.
            'window' => $window + [
                'earlier_trips' => $window['limited'] !== null && $this->baseTrips($asset)
                    ->where('started_at', '<', self::localDayStart($window['from']))->exists(),
            ],
            'truncated' => $result['truncated'],
            'limits' => [
                'default_days' => self::DEFAULT_WINDOW_DAYS,
                'max_range_days' => self::MAX_RANGE_DAYS,
                'max_trips' => self::MAX_LIST_TRIPS,
            ],
            'timezone' => self::zone(),
        ];
        if ($summaryOnly) {
            return $response;
        }

        $perPage = max(1, min(self::MAX_PAGE_SIZE, $perPage));
        $total = count($trips);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($lastPage, $page));
        $slice = array_slice($trips, ($page - 1) * $perPage, $perPage);
        // Personal and consent-restricted trips are only analysed when shown.
        $unread = array_values(array_filter($slice, fn (FleetTrip $trip): bool => ! isset($analysis[$trip->id])));
        $analysis += $this->analyse($asset, $unread, false, 0);

        return $response + [
            'data' => array_map(fn (FleetTrip $trip): array => $this->listItem(
                $trip,
                $result['attribution'][$trip->id],
                $analysis[$trip->id],
            ), $slice),
            'trip_ids' => array_map(fn (FleetTrip $trip): int => (int) $trip->id, $trips),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage],
            'drivers' => $result['drivers'],
            'unassigned_trips' => $result['unassigned'],
            'recent_days' => $this->recentDays($asset),
            'has_trips' => $this->baseTrips($asset)->exists(),
            'tracker_linked' => $this->trackerLinked($asset),
            'policy' => TripBehaviourAnalyzer::policy(),
        ];
    }

    /** @return array<string,mixed> */
    public function detail(User $user, Asset $asset, int $tripId): array
    {
        $trip = $this->baseTrips($asset)->whereKey($tripId)->first() ?? abort(404);
        $siteIds = $this->siteIds($user);
        $attribution = $this->attribute($user, $asset, collect([$trip]), $siteIds)[$trip->id];
        $analysis = $this->analyse($asset, [$trip], true, self::DETAIL_POINT_CAP)[$trip->id];
        $canConfirm = $this->canConfirmDriver($user);

        AuditLogger::log('fleet.trip.history.view', $trip, [
            'trip_id' => $trip->id,
            'asset_id' => $asset->id,
        ]);

        $zone = self::zone();
        $booking = $attribution['booking'];
        $withheld = self::withheld($trip);
        // Human reviews in Driving insights also decide the score shown here:
        // a dismissed event stops deducting and a disputed one withholds it.
        if (! $withheld && ($analysis['score_state'] ?? null) === 'scored') {
            [$analysis['score'], $analysis['score_state']] = VehicleDrivingInsightsService::reviewedTripScore(
                $trip, $analysis, TripBehaviourAnalyzer::policy(),
            );
        }
        $confirmations = FleetTripDriverConfirmation::query()->where('fleet_trip_id', $trip->id)
            ->orderByDesc('confirmed_at')->orderByDesc('id')->get();
        $historyPeople = FleetTripSiteScope::visiblePeople(
            $confirmations->flatMap(fn ($row) => [(int) $row->driver_user_id, (int) $row->confirmed_by_user_id])->all(),
            $siteIds,
        );
        $names = User::query()->whereIn('id', array_keys($historyPeople))->pluck('name', 'id');
        $confirmedBy = $trip->driver_confirmed_by ? (int) $trip->driver_confirmed_by : null;
        $confirmedByVisible = $confirmedBy !== null
            && isset(FleetTripSiteScope::visiblePeople([$confirmedBy], $siteIds)[$confirmedBy]);

        return [
            'vehicle' => $this->vehicleIdentity($asset),
            'trip' => $this->tripFacts($trip) + [
                'start' => [
                    'address' => $withheld ? null : self::text($trip->start_address),
                    'lat' => ! $withheld && $trip->start_latitude !== null ? (float) $trip->start_latitude : null,
                    'lng' => ! $withheld && $trip->start_longitude !== null ? (float) $trip->start_longitude : null,
                    'trigger' => $this->ignitionNear($asset, $trip->started_at, 'ignition_on', -600, 120) ? 'ignition' : 'movement',
                ],
                'end' => [
                    'address' => $withheld ? null : self::text($trip->end_address),
                    'lat' => ! $withheld && $trip->end_latitude !== null ? (float) $trip->end_latitude : null,
                    'lng' => ! $withheld && $trip->end_longitude !== null ? (float) $trip->end_longitude : null,
                    'trigger' => $trip->ended_at === null ? 'in_progress'
                        : ($this->ignitionNear($asset, $trip->ended_at, 'ignition_off', -120, 600) ? 'ignition' : 'stopped'),
                ],
                'stop_after_minutes' => (int) config('fleet.trip.stop_after_minutes', 5),
            ],
            // Personal and consent-restricted trips keep no route or events on screen.
            'points' => $withheld ? [] : array_map(fn (array $point): array => [
                'at' => CarbonImmutable::createFromTimestampUTC($point['at'])->toIso8601String(),
                'lat' => $point['lat'],
                'lng' => $point['lng'],
                'speed_kph' => $point['speed_kph'],
                'heading' => $point['heading'],
                'ignition' => $point['ignition'],
                'address' => $point['address'],
            ], $analysis['points']),
            'recorded_points' => $analysis['recorded_points'],
            'downsampled' => $analysis['downsampled'],
            'events' => $withheld ? [] : array_map(fn (array $event): array => [
                'key' => $event['key'],
                'type' => $event['type'],
                'kind' => $event['kind'],
                'title' => $event['title'],
                'detail' => $event['detail'],
                'at' => CarbonImmutable::createFromTimestampUTC($event['at'])->toIso8601String(),
                'point' => $event['point'],
                'peak_kph' => $event['peak_kph'] ?? null,
                'seconds' => $event['seconds'] ?? null,
                'source' => $event['source'] ?? null,
            ], $analysis['events']),
            'behaviour' => $this->behaviour($analysis, $withheld),
            'driver' => $this->driverView($attribution) + [
                'confirmed_at' => $trip->driver_confirmed_at?->toIso8601String(),
                'confirmed_by' => $confirmedByVisible ? User::query()->whereKey($confirmedBy)->value('name') : null,
                'version' => $confirmations->count(),
            ],
            'source' => [
                'vendors' => array_values(array_map(fn (string $vendor): string => ucfirst($vendor), $analysis['vendors'])),
                'recorded_points' => $analysis['recorded_points'],
                'booking' => $booking ? [
                    'id' => (int) $booking->id,
                    'reference' => $booking->reference_number ?: 'Booking #'.$booking->id,
                    'checked_out_at' => $booking->checked_out_at?->toIso8601String(),
                    'returned_at' => $booking->returned_at?->toIso8601String(),
                    'odometer_out' => $booking->odometer_out !== null ? (float) $booking->odometer_out : null,
                    'odometer_in' => $booking->odometer_in !== null ? (float) $booking->odometer_in : null,
                ] : null,
            ],
            'driver_candidates' => $canConfirm
                ? $this->staff->candidates($asset, null, array_values(array_filter([
                    $attribution['user_id'],
                    $attribution['booking_driver_visible'] ? $attribution['booking_driver_id'] : null,
                ])))->all()
                : [],
            'driver_history' => $canConfirm ? $confirmations->map(fn (FleetTripDriverConfirmation $row): array => [
                'id' => (int) $row->id,
                'driver' => $names[(int) $row->driver_user_id] ?? null,
                'source' => $row->source,
                'reason' => $row->reason,
                'confirmed_by' => $names[(int) $row->confirmed_by_user_id] ?? null,
                'confirmed_at' => $row->confirmed_at?->toIso8601String(),
            ])->values()->all() : [],
            'can' => ['confirm_driver' => $canConfirm],
            'policy' => TripBehaviourAnalyzer::policy(),
            'timezone' => $zone,
        ];
    }

    /**
     * Record who actually drove a trip. The booked driver, a handover to
     * someone else or a driver with no booking are all kept as evidence.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function confirmDriver(User $actor, int $assetId, int $tripId, array $data, string $requestKey): array
    {
        if (trim($requestKey) === '' || mb_strlen($requestKey) > 100) {
            throw ValidationException::withMessages(['request_key' => 'Provide an idempotency key of at most 100 characters.']);
        }

        return DB::transaction(function () use ($actor, $assetId, $tripId, $data, $requestKey): array {
            $current = User::query()->findOrFail($actor->id);
            abort_unless($this->canConfirmDriver($current), 403);
            $asset = $this->vehicle($current, $assetId, true);
            $trip = $this->baseTrips($asset)->whereKey($tripId)->lockForUpdate()->first() ?? abort(404);

            $driverId = (int) ($data['driver_user_id'] ?? 0);
            $reason = trim((string) ($data['reason'] ?? ''));
            $fingerprint = MaintenanceFingerprint::of([
                'actor' => (int) $current->id, 'trip' => (int) $trip->id, 'driver' => $driverId, 'reason' => $reason,
            ]);
            // Locking reads: a plain read here would use the snapshot taken before the
            // trip lock and miss a confirmation another manager just committed.
            $prior = FleetTripDriverConfirmation::query()->where('fleet_trip_id', $trip->id)
                ->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($prior) {
                abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
                    'This request was already used for a different confirmation.');

                return $this->confirmationResult($trip->fresh());
            }

            Validator::make($data, [
                'driver_user_id' => ['required', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'max:2000'],
                'verified' => ['accepted'],
                'expected_version' => ['required', 'integer', 'min:0'],
            ], [
                'driver_user_id.required' => 'Choose who actually drove this trip.',
                'reason.required' => 'Record the checkout or handover evidence.',
                'verified.accepted' => 'Confirm that you checked who drove and any handover.',
            ], [
                'driver_user_id' => 'actual driver',
                'reason' => 'checkout or handover evidence',
            ])->validate();

            $version = FleetTripDriverConfirmation::query()->where('fleet_trip_id', $trip->id)->lockForUpdate()->count();
            abort_unless((int) $data['expected_version'] === $version, 409,
                "This trip's driver was confirmed by someone else while you were working. Reload the trip before saving.");
            if (! $this->staff->isCandidate($asset, $driverId)) {
                throw ValidationException::withMessages([
                    'driver_user_id' => "Choose a current staff member at this vehicle's site.",
                ]);
            }

            $booking = $this->coveringBookings($asset, collect([$trip]))[$trip->id] ?? null;
            $bookingDriver = $booking ? $this->bookingDriverId($booking) : null;
            $source = $booking === null ? 'manual' : ($bookingDriver === $driverId ? 'booking' : 'handover');
            $previousDriver = $trip->driver_user_id ? (int) $trip->driver_user_id : null;
            $previousSource = $trip->driver_attribution_source;

            $trip->forceFill([
                'driver_user_id' => $driverId,
                'driver_attribution_source' => $source,
                'driver_confirmed_by' => $current->id,
                'driver_confirmed_at' => now(),
            ])->save();
            FleetTripDriverConfirmation::query()->create([
                'fleet_trip_id' => $trip->id,
                'asset_id' => $asset->id,
                'driver_user_id' => $driverId,
                'previous_driver_user_id' => $previousDriver,
                'previous_source' => $previousSource,
                'source' => $source,
                'booking_id' => $booking?->id,
                'reason' => $reason,
                'confirmed_by_user_id' => $current->id,
                'confirmed_at' => now(),
                'request_key' => $requestKey,
                'request_fingerprint' => $fingerprint,
            ]);
            AuditLogger::logOrFail('fleet.trip.driver_confirmed', $asset, [
                'trip_id' => (int) $trip->id,
                'driver_user_id' => $driverId,
                'previous_driver_user_id' => $previousDriver,
                'source' => $source,
                'booking_id' => $booking?->id,
            ]);

            return $this->confirmationResult($trip->fresh());
        }, 3);
    }

    /**
     * Trips, totals and (optionally) events and route positions for an
     * export. Personal and consent-blocked trips are left out and counted.
     *
     * @param  array{q:string,from:?string,to:?string,driver:string,event:string}  $filters
     * @return array<string,mixed>
     */
    public function exportReport(User $user, Asset $asset, array $filters, int $limit, bool $withEvents, bool $withRoutes): array
    {
        $siteIds = $this->siteIds($user);
        $result = $this->pipeline($user, $asset, $filters, $siteIds);
        $excluded = ['personal' => 0, 'restricted' => 0];
        $trips = [];
        foreach ($result['trips'] as $trip) {
            if ($trip->consent_blocked) {
                $excluded['restricted']++;
            } elseif ($trip->is_personal) {
                $excluded['personal']++;
            } else {
                $trips[] = $trip;
            }
        }
        if ($trips === []) {
            throw ValidationException::withMessages(['range' => 'There are no business trips to export for these dates and filters.']);
        }
        if (count($trips) > $limit) {
            throw ValidationException::withMessages(['range' => 'These dates and filters include '.count($trips)
                ." trips. This format includes up to {$limit}; choose a shorter date range."]);
        }
        // Chronological, as a report reads.
        $trips = array_reverse($trips);
        $analysis = ($withEvents || $withRoutes)
            ? $this->analyse($asset, $trips, true, self::ROUTE_SKETCH_POINT_CAP)
            : array_intersect_key($result['analysis'], array_flip(array_map(fn (FleetTrip $trip) => $trip->id, $trips)));

        $rows = [];
        foreach ($trips as $trip) {
            $a = $analysis[$trip->id];
            $attribution = $result['attribution'][$trip->id];
            $rows[] = $this->tripFacts($trip) + [
                'driver' => $this->driverView($attribution),
                'behaviour' => $this->behaviour($a),
                'events' => $withEvents ? array_map(fn (array $event): array => $event + [
                    'location' => $a['points'][$event['point']]['address'] ?? null,
                    'lat' => $a['points'][$event['point']]['lat'] ?? null,
                    'lng' => $a['points'][$event['point']]['lng'] ?? null,
                ], $a['events']) : [],
                'points' => $withRoutes ? array_map(fn (array $point): array => [
                    'lat' => $point['lat'], 'lng' => $point['lng'],
                ], $a['points']) : [],
                'booking_reference' => $attribution['booking']
                    ? ($attribution['booking']->reference_number ?: 'Booking #'.$attribution['booking']->id)
                    : null,
                'vendors' => $a['vendors'],
            ];
        }

        return [
            'vehicle' => $this->vehicleIdentity($asset) + ['site' => $asset->site?->name ?? $asset->homeSite?->name],
            'filters' => $filters,
            'driver_label' => $this->driverLabel($filters['driver'], $result['drivers']),
            'trips' => $rows,
            'totals' => [
                'trips' => count($rows),
                'distance_km' => round(array_sum(array_map(fn (array $row) => $row['distance_km'], $rows)), 1),
                'duration_s' => array_sum(array_map(fn (array $row) => $row['duration_s'], $rows)),
                'driving_events' => array_sum(array_map(fn (array $row) => $row['behaviour']['driving_events'], $rows)),
            ],
            'excluded' => $excluded,
            'policy' => TripBehaviourAnalyzer::policy(),
            'include_events' => $withEvents,
            'include_routes' => $withRoutes,
        ];
    }

    /**
     * PKG-02B Driving insights: the trips of an Auckland date range (or one
     * trip), each with the same driver attribution and behaviour analysis
     * the trip list uses, including the trip's recorded events. Personal and
     * consent-blocked trips are returned flagged (so they can be counted as
     * withheld) but are not analysed or counted towards any driver.
     *
     * @return array{trips:list<FleetTrip>,driver_views:array<int,array<string,mixed>>,analysis:array<int,array<string,mixed>>,drivers:list<array<string,mixed>>,unassigned:int}
     */
    public function insightData(User $user, Asset $asset, ?string $from, ?string $to, ?int $tripId = null, bool $analyse = true): array
    {
        $zone = self::zone();
        $query = $this->baseTrips($asset)->orderByDesc('started_at')->orderByDesc('id');
        if ($tripId !== null) {
            $query->whereKey($tripId);
        }
        $from = self::date($from);
        $to = self::date($to);
        if ($from !== null) {
            $query->where('started_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $from, $zone)->utc());
        }
        if ($to !== null) {
            $query->where('started_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $to, $zone)->addDay()->utc());
        }
        $trips = $query->limit(500)->get();
        $attribution = $this->attribute($user, $asset, $trips, $this->siteIds($user));
        // Withheld trips are never analysed, scored or counted towards a driver.
        $business = $trips->reject(fn (FleetTrip $trip): bool => self::withheld($trip))->values();
        [$drivers, $unassigned] = $this->driverOptions($business, $attribution);

        return [
            'trips' => $trips->all(),
            'driver_views' => array_map(fn (array $row): array => $this->driverView($row), $attribution),
            'analysis' => $analyse ? $this->analyse($asset, $business->all(), true, 2) : [],
            'drivers' => $drivers,
            'unassigned' => $unassigned,
        ];
    }

    /** @return list<int> */
    private function siteIds(User $user): array
    {
        return array_values(array_map('intval', $this->siteAccess->accessibleSiteIds($user, self::SITE_BYPASS_PERMISSIONS)));
    }

    /** Trips that belong in the vehicle's history (voided trips are left out). */
    private function baseTrips(Asset $asset): Builder
    {
        return FleetTrip::query()->where('asset_id', $asset->getKey())
            ->where(fn (Builder $status) => $status->whereNull('status')->orWhere('status', '!=', 'cancelled'));
    }

    /**
     * The local dates one list request reads. With no dates, the latest
     * DEFAULT_WINDOW_DAYS days up to the vehicle's most recent trip
     * ('recent'); a range longer than MAX_RANGE_DAYS, or one without a first
     * date, keeps its most recent MAX_RANGE_DAYS ('range').
     *
     * @param  array{q:string,from:?string,to:?string,driver:string,event:string}  $filters
     * @return array{from:string,to:?string,limited:?string}
     */
    private function window(Asset $asset, array $filters): array
    {
        $zone = self::zone();
        if ($filters['from'] === null && $filters['to'] === null) {
            // Never after today, so a trip with a wrong future time can't hide the rest.
            $latest = $this->baseTrips($asset)->max('started_at');
            $anchor = $latest !== null
                ? CarbonImmutable::parse((string) $latest, 'UTC')->setTimezone($zone)->min(CarbonImmutable::now($zone))
                : CarbonImmutable::now($zone);

            return [
                'from' => $anchor->startOfDay()->subDays(self::DEFAULT_WINDOW_DAYS - 1)->toDateString(),
                'to' => null,
                'limited' => 'recent',
            ];
        }

        $last = $filters['to'] !== null
            ? CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], $zone)
            : CarbonImmutable::now($zone)->startOfDay();
        $earliest = $last->subDays(self::MAX_RANGE_DAYS)->toDateString();
        if ($filters['from'] === null || $filters['from'] < $earliest) {
            return ['from' => $earliest, 'to' => $filters['to'], 'limited' => 'range'];
        }

        return ['from' => $filters['from'], 'to' => $filters['to'], 'limited' => null];
    }

    /** The UTC moment a Pacific/Auckland day begins. */
    private static function localDayStart(string $day): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day, self::zone())->utc();
    }

    /**
     * The trips matching the filters, with their attribution and analysis.
     * With a cap, only the newest $cap trips in the dates are read and
     * `truncated` says whether more exist. Business trips are always
     * analysed (the totals count their driving events); personal and
     * consent-restricted trips only when an event filter reads them.
     *
     * @param  array{q:string,from:?string,to:?string,driver:string,event:string}  $filters
     * @param  list<int>  $siteIds
     * @return array{trips:list<FleetTrip>,attribution:array<int,array<string,mixed>>,analysis:array<int,array<string,mixed>>,drivers:list<array<string,mixed>>,unassigned:int,truncated:bool}
     */
    private function pipeline(User $viewer, Asset $asset, array $filters, array $siteIds, ?int $cap = null): array
    {
        $zone = self::zone();
        $query = $this->baseTrips($asset)
            ->orderByDesc('started_at')->orderByDesc('id');
        if ($filters['from']) {
            $query->where('started_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], $zone)->utc());
        }
        if ($filters['to']) {
            $query->where('started_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], $zone)->addDay()->utc());
        }
        if ($cap !== null) {
            $query->limit($cap + 1);
        }
        $inRange = $query->get();
        $truncated = $cap !== null && $inRange->count() > $cap;
        if ($truncated) {
            $inRange = $inRange->take($cap)->values();
        }
        $attribution = $this->attribute($viewer, $asset, $inRange, $siteIds);
        [$drivers, $unassigned] = $this->driverOptions($inRange, $attribution);

        $matching = $inRange->filter(fn (FleetTrip $trip): bool => $this->matches($trip, $attribution[$trip->id], $filters))
            ->values()->all();
        $analysis = $this->analyse($asset, in_array($filters['event'], ['faults', 'partial'], true)
            ? $matching
            : array_values(array_filter($matching, fn (FleetTrip $trip): bool => ! self::withheld($trip))), false, 0);
        $trips = array_values(array_filter($matching, fn (FleetTrip $trip): bool => match ($filters['event']) {
            'overspeed' => ! self::withheld($trip) && $analysis[$trip->id]['overspeed_episodes'] > 0,
            'faults' => $analysis[$trip->id]['faults'] > 0,
            'partial' => (bool) $analysis[$trip->id]['partial'],
            default => true,
        }));

        return [
            'trips' => $trips,
            'attribution' => $attribution,
            'analysis' => $analysis,
            'drivers' => $drivers,
            'unassigned' => $unassigned,
            'truncated' => $truncated,
        ];
    }

    /** @param  array<string,mixed>  $attribution */
    private function matches(FleetTrip $trip, array $attribution, array $filters): bool
    {
        $driver = $filters['driver'];
        if ($driver === 'unassigned' && $attribution['state'] !== 'none') {
            return false;
        }
        if (ctype_digit($driver) && $attribution['user_id'] !== (int) $driver) {
            return false;
        }
        $search = mb_strtolower(trim($filters['q']));
        if ($search === '') {
            return true;
        }
        // A trip reference ("#123" or "Trip #123") finds exactly that trip.
        if (preg_match('/^(?:trip\s*)?#\s*(\d+)$/', $search, $reference) === 1) {
            return (int) $reference[1] === (int) $trip->id;
        }
        // Withheld places aren't searchable either, or a search could reveal them.
        $haystack = mb_strtolower(implode(' ', array_filter([
            'trip #'.$trip->id,
            self::withheld($trip) ? '' : (string) $trip->start_address,
            self::withheld($trip) ? '' : (string) $trip->end_address,
            (string) ($attribution['name'] ?? ''),
        ])));

        return str_contains($haystack, $search);
    }

    /**
     * Who drove each trip, as far as the records say: a confirmed driver, a
     * driver sign-in on the vehicle, or the driver of a booking checked out
     * when the trip started. Only confirmation establishes the actual driver.
     *
     * @param  Collection<int,FleetTrip>  $trips
     * @param  list<int>  $siteIds
     * @return array<int,array<string,mixed>>
     */
    private function attribute(User $viewer, Asset $asset, Collection $trips, array $siteIds): array
    {
        if ($trips->isEmpty()) {
            return [];
        }
        $sessionIds = $trips->pluck('driver_session_id')->filter()->unique()->values()->all();
        $sessions = $sessionIds === [] ? collect() : FleetDriverSession::query()
            ->whereIn('id', $sessionIds)->where('asset_id', $asset->getKey())
            ->get(['id', 'user_id'])->keyBy('id');
        $bookings = $this->coveringBookings($asset, $trips);
        // The booking itself (reference, times, readings) follows the booking Site rule.
        $openable = $bookings === [] ? [] : array_fill_keys($this->bookingAccess->accessibleBookings($viewer)
            ->whereKey(array_map(fn (FleetVehicleBooking $booking): int => (int) $booking->id, $bookings))
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(), true);

        $userIds = [];
        foreach ($trips as $trip) {
            $userIds[] = (int) $trip->driver_user_id;
            $userIds[] = (int) ($sessions->get($trip->driver_session_id)?->user_id ?? 0);
            $userIds[] = isset($bookings[$trip->id]) ? $this->bookingDriverId($bookings[$trip->id]) : 0;
        }
        $visible = FleetTripSiteScope::visiblePeople($userIds, $siteIds);
        $names = User::query()->whereIn('id', array_keys($visible))->pluck('name', 'id');

        $out = [];
        foreach ($trips as $trip) {
            $booking = $bookings[$trip->id] ?? null;
            $bookingDriver = $booking ? $this->bookingDriverId($booking) : null;
            $sessionUser = (int) ($sessions->get($trip->driver_session_id)?->user_id ?? 0);
            [$source, $userId] = match (true) {
                (int) $trip->driver_user_id > 0 => ['confirmed', (int) $trip->driver_user_id],
                $sessionUser > 0 => ['session', $sessionUser],
                $bookingDriver !== null => ['booking', $bookingDriver],
                default => [null, null],
            };
            $shown = $userId !== null && isset($visible[$userId]) && $names->has($userId);
            $out[$trip->id] = [
                'state' => $userId === null ? 'none' : (! $shown ? 'hidden' : ($source === 'confirmed' ? 'confirmed' : 'recorded')),
                'source' => $source,
                'user_id' => $shown ? $userId : null,
                'name' => $shown ? (string) $names[$userId] : null,
                'attribution_source' => $source === 'confirmed' ? $trip->driver_attribution_source : null,
                'booking' => $booking && isset($openable[(int) $booking->id]) ? $booking : null,
                'booking_driver_id' => $bookingDriver,
                'booking_driver_visible' => $bookingDriver !== null && isset($visible[$bookingDriver]) && $names->has($bookingDriver),
                'booking_driver_name' => $bookingDriver !== null && isset($visible[$bookingDriver]) ? ($names[$bookingDriver] ?? null) : null,
            ];
        }

        return $out;
    }

    /**
     * The booking checked out (and not yet returned) when each trip started.
     *
     * @param  Collection<int,FleetTrip>  $trips
     * @return array<int,FleetVehicleBooking>
     */
    private function coveringBookings(Asset $asset, Collection $trips): array
    {
        $starts = $trips->map(fn (FleetTrip $trip) => $trip->started_at)->filter();
        if ($starts->isEmpty()) {
            return [];
        }
        $columns = ['id', 'reference_number', 'user_id', 'status', 'checked_out_at', 'returned_at', 'odometer_out', 'odometer_in'];
        if (SchemaCache::hasColumn('fleet_vehicle_bookings', 'driver_user_id')) {
            $columns[] = 'driver_user_id';
        }
        $bookings = FleetVehicleBooking::query()->where('asset_id', $asset->getKey())
            ->whereNotNull('checked_out_at')
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->where('checked_out_at', '<=', $starts->max())
            ->where(fn (Builder $open) => $open->whereNull('returned_at')->orWhere('returned_at', '>=', $starts->min()))
            ->orderBy('checked_out_at')->orderBy('id')
            ->get($columns)->values();

        $out = [];
        $pointer = -1;
        $count = $bookings->count();
        foreach ($trips->filter(fn (FleetTrip $trip) => $trip->started_at !== null)
            ->sortBy(fn (FleetTrip $trip) => $trip->started_at->getTimestamp()) as $trip) {
            while ($pointer + 1 < $count && $bookings[$pointer + 1]->checked_out_at->lessThanOrEqualTo($trip->started_at)) {
                $pointer++;
            }
            if ($pointer < 0) {
                continue;
            }
            $booking = $bookings[$pointer];
            if ($booking->returned_at === null || $booking->returned_at->greaterThanOrEqualTo($trip->started_at)) {
                $out[$trip->id] = $booking;
            }
        }

        return $out;
    }

    private function bookingDriverId(FleetVehicleBooking $booking): int
    {
        return (int) ($booking->getAttribute('driver_user_id') ?: $booking->user_id);
    }

    /**
     * @param  Collection<int,FleetTrip>  $trips
     * @param  array<int,array<string,mixed>>  $attribution
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function driverOptions(Collection $trips, array $attribution): array
    {
        $options = [];
        $unassigned = 0;
        foreach ($trips as $trip) {
            $a = $attribution[$trip->id];
            if ($a['state'] === 'none') {
                $unassigned++;

                continue;
            }
            if ($a['user_id'] === null) {
                continue;
            }
            $options[$a['user_id']] ??= ['id' => $a['user_id'], 'name' => $a['name'], 'trips' => 0, 'confirmed' => 0];
            $options[$a['user_id']]['trips']++;
            if ($a['state'] === 'confirmed') {
                $options[$a['user_id']]['confirmed']++;
            }
        }
        usort($options, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [array_values($options), $unassigned];
    }

    private function driverLabel(string $driver, array $options): string
    {
        if ($driver === 'unassigned') {
            return 'No driver recorded';
        }
        if (ctype_digit($driver)) {
            foreach ($options as $option) {
                if ((int) $option['id'] === (int) $driver) {
                    return (string) $option['name'];
                }
            }

            return 'Selected driver';
        }

        return 'All drivers';
    }

    /**
     * Run the behaviour analysis over each trip's telemetry, reading the
     * recorded events in time order a batch of trips at a time.
     *
     * @param  list<FleetTrip>  $trips
     * @return array<int,array<string,mixed>>
     */
    private function analyse(Asset $asset, array $trips, bool $collect, int $pointCap): array
    {
        if ($trips === []) {
            return [];
        }
        $policy = TripBehaviourAnalyzer::policy();
        $now = now()->getTimestamp();
        usort($trips, fn (FleetTrip $a, FleetTrip $b): int => [$a->started_at?->getTimestamp() ?? 0, $a->id]
            <=> [$b->started_at?->getTimestamp() ?? 0, $b->id]);
        $columns = ['id', 'occurred_at', 'latitude', 'longitude', 'speed_kph', 'heading_deg', 'ignition', 'event_type', 'vendor'];
        if (SchemaCache::hasColumn('fleet_telemetry_events', 'address')) {
            $columns[] = 'address';
        }

        $results = [];
        foreach (array_chunk($trips, $collect ? 20 : 100) as $batch) {
            $windows = [];
            foreach ($batch as $trip) {
                $start = $trip->started_at?->getTimestamp() ?? $now;
                $end = $trip->ended_at?->getTimestamp();
                $windows[] = [
                    'id' => (int) $trip->id,
                    'start' => $start,
                    'end' => $end ?? $now,
                    'analyzer' => new TripBehaviourAnalyzer($policy, $start, $end, (float) $trip->distance_km,
                        (bool) $trip->is_personal, (bool) $trip->consent_blocked, $collect),
                ];
            }
            $from = min(array_column($windows, 'start'));
            $to = max(array_column($windows, 'end'));
            $rows = DB::table('fleet_telemetry_events')
                ->where('asset_id', $asset->getKey())
                ->where('consent_blocked', false)
                ->whereBetween('occurred_at', [gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to)])
                ->orderBy('occurred_at')->orderBy('id')
                ->select($columns)->selectRaw(self::REPORT_TYPE_SQL)
                ->get();

            $active = [];
            $next = 0;
            $count = count($windows);
            foreach ($rows as $row) {
                $at = TripBehaviourAnalyzer::timestamp($row->occurred_at);
                if ($at === null) {
                    continue;
                }
                while ($next < $count && $windows[$next]['start'] <= $at) {
                    $active[] = $next++;
                }
                foreach ($active as $slot => $index) {
                    if ($windows[$index]['end'] < $at) {
                        unset($active[$slot]);

                        continue;
                    }
                    $windows[$index]['analyzer']->add((array) $row);
                }
            }
            foreach ($windows as $window) {
                $results[$window['id']] = $window['analyzer']->finish($now, $pointCap);
            }
        }

        return $results;
    }

    /**
     * @param  list<FleetTrip>  $trips
     * @param  array<int,array<string,mixed>>  $analysis
     * @return array<string,int|float|string|null>
     */
    private function summary(array $trips, array $analysis): array
    {
        $summary = [
            'trips' => count($trips), 'distance_km' => 0.0, 'duration_s' => 0, 'driving_events' => 0,
            'personal_trips' => 0, 'restricted_trips' => 0, 'business_trips' => 0, 'business_distance_km' => 0.0,
            'first_day' => null, 'last_day' => null,
        ];
        $zone = self::zone();
        foreach ($trips as $trip) {
            $day = $trip->started_at?->copy()->setTimezone($zone)->toDateString();
            if ($day !== null) {
                $summary['first_day'] = $summary['first_day'] === null ? $day : min($summary['first_day'], $day);
                $summary['last_day'] = $summary['last_day'] === null ? $day : max($summary['last_day'], $day);
            }
            $distance = (float) $trip->distance_km;
            $summary['distance_km'] += $distance;
            $summary['duration_s'] += (int) $trip->duration_s;
            if ($trip->consent_blocked) {
                $summary['restricted_trips']++;
            } elseif ($trip->is_personal) {
                $summary['personal_trips']++;
            } else {
                // Personal and consent-blocked trips never enter driving insights.
                $summary['driving_events'] += (int) $analysis[$trip->id]['driving_events'];
                $summary['business_trips']++;
                $summary['business_distance_km'] += $distance;
            }
        }
        $summary['distance_km'] = round($summary['distance_km'], 1);
        $summary['business_distance_km'] = round($summary['business_distance_km'], 1);

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $attribution
     * @param  array<string,mixed>  $analysis
     * @return array<string,mixed>
     */
    private function listItem(FleetTrip $trip, array $attribution, array $analysis): array
    {
        return $this->tripFacts($trip) + [
            'coverage_pct' => $analysis['coverage_pct'],
            'partial' => $analysis['partial'],
            // Personal and consent-restricted trips are not analysed for driving behaviour.
            'driving_events' => self::withheld($trip) ? 0 : $analysis['driving_events'],
            'overspeed_episodes' => self::withheld($trip) ? 0 : $analysis['overspeed_episodes'],
            'faults' => $analysis['faults'],
            'driver' => $this->driverView($attribution),
        ];
    }

    /** @return array<string,mixed> */
    private function tripFacts(FleetTrip $trip): array
    {
        $zone = self::zone();

        return [
            'id' => (int) $trip->id,
            'reference' => 'Trip #'.$trip->id,
            'started_at' => $trip->started_at?->toIso8601String(),
            'ended_at' => $trip->ended_at?->toIso8601String(),
            'local_date' => $trip->started_at?->copy()->setTimezone($zone)->toDateString(),
            'in_progress' => $trip->ended_at === null,
            'distance_km' => round((float) $trip->distance_km, 1),
            'duration_s' => (int) $trip->duration_s,
            'from' => self::withheld($trip) ? null : self::text($trip->start_address),
            'to' => self::withheld($trip) ? null : self::text($trip->end_address),
            'is_personal' => (bool) $trip->is_personal,
            'consent_blocked' => (bool) $trip->consent_blocked,
        ];
    }

    /**
     * Personal and consent-restricted trips are listed (so the history stays
     * complete) without their places, route, events or driving behaviour, as
     * the Map withholds their positions.
     */
    private static function withheld(FleetTrip $trip): bool
    {
        return (bool) $trip->is_personal || (bool) $trip->consent_blocked;
    }

    /**
     * @param  array<string,mixed>  $attribution
     * @return array<string,mixed>
     */
    private function driverView(array $attribution): array
    {
        return [
            'state' => $attribution['state'],
            'source' => $attribution['source'],
            'id' => $attribution['user_id'],
            'name' => $attribution['name'],
            'attribution' => $attribution['attribution_source'],
            'booked_driver_id' => $attribution['booking_driver_visible'] ? $attribution['booking_driver_id'] : null,
            'booked_driver' => $attribution['booking_driver_visible'] ? $attribution['booking_driver_name'] : null,
            'booking_reference' => $attribution['booking']
                ? ($attribution['booking']->reference_number ?: 'Booking #'.$attribution['booking']->id)
                : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $analysis
     * @return array<string,mixed>
     */
    private function behaviour(array $analysis, bool $withheld = false): array
    {
        if ($withheld) {
            return [
                'samples' => $analysis['samples'],
                'coverage_pct' => $analysis['coverage_pct'],
                'partial' => $analysis['partial'],
                'max_speed_kph' => null,
                'overspeed_episodes' => 0,
                'overspeed_seconds' => 0,
                'harsh' => array_map(fn (): int => 0, (array) $analysis['harsh']),
                'faults' => $analysis['faults'],
                'driving_events' => 0,
                'idle_minutes' => null,
                'events_per_100km' => null,
                'score' => null,
                'score_state' => $analysis['score_state'],
            ];
        }

        return [
            'samples' => $analysis['samples'],
            'coverage_pct' => $analysis['coverage_pct'],
            'partial' => $analysis['partial'],
            'max_speed_kph' => $analysis['max_speed_kph'] !== null ? round($analysis['max_speed_kph'], 1) : null,
            'overspeed_episodes' => $analysis['overspeed_episodes'],
            'overspeed_seconds' => $analysis['overspeed_seconds'],
            'harsh' => $analysis['harsh'],
            'faults' => $analysis['faults'],
            'driving_events' => $analysis['driving_events'],
            'idle_minutes' => $analysis['idle_minutes'],
            'events_per_100km' => $analysis['events_per_100km'],
            'score' => $analysis['score'],
            'score_state' => $analysis['score_state'],
        ];
    }

    /** @return array<string,mixed> */
    private function confirmationResult(FleetTrip $trip): array
    {
        return ['trip' => [
            'id' => (int) $trip->id,
            'driver_user_id' => $trip->driver_user_id ? (int) $trip->driver_user_id : null,
            'driver_attribution_source' => $trip->driver_attribution_source,
            'driver_confirmed_at' => $trip->driver_confirmed_at?->toIso8601String(),
        ]];
    }

    /** @return array<string,mixed> */
    private function vehicleIdentity(Asset $asset): array
    {
        return [
            'id' => (int) $asset->getKey(),
            'name' => (string) $asset->name,
            'registration_number' => self::text($asset->registration_number ?? null),
            'asset_tag' => self::text($asset->asset_tag ?? null),
        ];
    }

    /** Local days with trips, newest first, for the date shortcuts. @return list<string> */
    private function recentDays(Asset $asset): array
    {
        $zone = self::zone();

        return $this->baseTrips($asset)->orderByDesc('started_at')->limit(400)->pluck('started_at')
            ->filter()
            ->map(fn ($startedAt) => ($startedAt instanceof \DateTimeInterface
                ? CarbonImmutable::instance($startedAt)
                : CarbonImmutable::parse((string) $startedAt, 'UTC'))->setTimezone($zone)->toDateString())
            ->unique()->take(31)->values()->all();
    }

    private function trackerLinked(Asset $asset): bool
    {
        return (SchemaCache::hasTable('device_asset_links') && DB::table('device_asset_links')
            ->where('asset_id', $asset->getKey())->whereNull('unlinked_at')->exists())
            || (SchemaCache::hasTable('asset_trackers') && DB::table('asset_trackers')
                ->where('asset_id', $asset->getKey())->where('status', 'paired')->exists());
    }

    /** Whether the tracker reported this ignition change close to a trip boundary. */
    private function ignitionNear(Asset $asset, ?\DateTimeInterface $at, string $type, int $before, int $after): bool
    {
        if ($at === null) {
            return false;
        }
        $moment = CarbonImmutable::instance($at)->utc();

        return DB::table('fleet_telemetry_events')
            ->where('asset_id', $asset->getKey())
            ->where('consent_blocked', false)
            ->where('event_type', $type)
            ->whereBetween('occurred_at', [
                $moment->addSeconds($before)->format('Y-m-d H:i:s'),
                $moment->addSeconds($after)->format('Y-m-d H:i:s'),
            ])
            ->exists();
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && CarbonImmutable::createFromFormat('!Y-m-d', $value) !== false ? $value : null;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
