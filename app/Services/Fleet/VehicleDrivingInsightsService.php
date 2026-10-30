<?php

namespace App\Services\Fleet;

use App\Models\Asset;
use App\Models\FleetDrivingEventReview;
use App\Models\FleetSpeedLimit;
use App\Models\FleetTrip;
use App\Models\User;
use App\Support\SchemaCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The vehicle profile's Driving insights: trip scores, driving events, the
 * overspeed episodes that can go to Control Room and the evidence behind a
 * score, all read from the same trips, driver attribution and behaviour
 * analysis as Trip history.
 *
 * Access is Trip history's: the vehicle and its trips follow the trip Site
 * rule and driver names show only at the driver's Sites. Personal and
 * consent-blocked trips are never scored, listed as events or totalled;
 * they are only counted as withheld. A reviewed score leaves out dismissed
 * events and is withheld while an event is disputed. A person's score needs
 * confirmed whole-trip attribution, every event reviewed and the published
 * policy's minimum trips and distance.
 */
final class VehicleDrivingInsightsService
{
    /** Period key => number of Auckland days, ending today. */
    public const PERIODS = ['week' => 7, 'today' => 1];

    /** Trips offered for review and speed-limit evaluation. */
    public const REVIEW_WINDOW_DAYS = 30;

    public function __construct(
        private readonly VehicleTripHistoryService $trips,
        private readonly DrivingScorePolicyStore $policies,
        private readonly RecordedVehicleEvents $events,
        private readonly VehicleStaffDirectory $staff,
    ) {}

    public function canReview(User $user): bool
    {
        return $user->canDo('fleet.manage') || $user->canDo('fleet.trips.manage');
    }

    public static function canViewAlerts(User $user): bool
    {
        return $user->canDo('controlRoom.viewAny')
            || $user->canDo('controlRoom.alerts.view')
            || $user->canDo('controlRoom.alerts.manage')
            || $user->canDo('assets.alerts.view');
    }

    /**
     * Analytics for a period and a driver filter ('all', 'unassigned' or a
     * person's id).
     *
     * @return array<string,mixed>
     */
    public function present(User $viewer, Asset $vehicle, string $period, string $driver): array
    {
        $zone = VehicleTripHistoryService::zone();
        $period = array_key_exists($period, self::PERIODS) ? $period : 'week';
        $driver = in_array($driver, ['all', 'unassigned'], true) || ctype_digit($driver) ? $driver : 'all';
        $today = CarbonImmutable::now($zone)->startOfDay();
        $from = $today->subDays(self::PERIODS[$period] - 1)->toDateString();
        $to = $today->toDateString();
        $policy = $this->policies->describe();
        $data = $this->trips->insightData($viewer, $vehicle, $from, $to);
        $rows = $this->tripRows($data, TripBehaviourAnalyzer::policy());
        $withheld = ['personal' => 0, 'consent' => 0];
        foreach ($data['trips'] as $trip) {
            if ($trip->consent_blocked) {
                $withheld['consent']++;
            } elseif ($trip->is_personal) {
                $withheld['personal']++;
            }
        }

        $shown = array_values(array_filter($rows, fn (array $row): bool => match (true) {
            $driver === 'all' => true,
            $driver === 'unassigned' => $row['driver']['state'] === 'none',
            default => (int) ($row['driver']['id'] ?? 0) === (int) $driver,
        }));
        $person = ctype_digit($driver);
        $eligible = array_values(array_filter($shown, fn (array $row): bool => $row['score'] !== null));
        $personal = array_values(array_filter($eligible, fn (array $row): bool => $row['driver']['state'] === 'confirmed' && $row['all_reviewed']));
        $scoring = $person ? $personal : $eligible;
        $scoringKm = round(array_sum(array_column($scoring, 'distance_km')), 1);
        $enough = ! $person || ($policy['min_trips'] !== null && $policy['min_distance_km'] !== null
            && count($personal) >= $policy['min_trips'] && $scoringKm >= $policy['min_distance_km']);

        $overspeedSeconds = 0;
        $secondsKnown = true;
        $idle = 0.0;
        $idleKnownMinutes = 0.0;
        foreach ($shown as $row) {
            if ($row['overspeed_episodes'] > 0 && $row['overspeed_seconds'] === null) {
                $secondsKnown = false;
            }
            $overspeedSeconds += (int) ($row['overspeed_seconds'] ?? 0);
            if ($row['idle_minutes'] !== null) {
                $idle += (float) $row['idle_minutes'];
                $idleKnownMinutes += $row['duration_s'] / 60;
            }
        }

        $episodes = [];
        foreach ($shown as $row) {
            foreach ($row['events'] as $event) {
                if ($event['type'] === 'overspeed') {
                    $episodes[] = $event + [
                        'trip_id' => $row['id'],
                        'trip_reference' => $row['reference'],
                        'local_date' => $row['local_date'],
                        'driver' => $row['driver'],
                        'source_key' => RecordedVehicleEvents::overspeedSourceKey($row['id'], $event['key']),
                    ];
                }
            }
        }
        usort($episodes, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
        $states = $this->events->routeStates($vehicle, array_column($episodes, 'source_key'), self::canViewAlerts($viewer));
        $episodes = array_map(fn (array $episode): array => $episode + ['route' => $states[$episode['source_key']] ?? null], $episodes);

        return [
            'as_of' => now()->toIso8601String(),
            'timezone' => $zone,
            'vehicle' => [
                'id' => (int) $vehicle->getKey(),
                'name' => (string) $vehicle->name,
                'registration_number' => $vehicle->registration_number ?: null,
            ],
            'period' => ['key' => $period, 'from' => $from, 'to' => $to],
            'driver' => $driver,
            'drivers' => $data['drivers'],
            'unassigned_trips' => $data['unassigned'],
            'has_data' => $this->trackerLinked($vehicle) || FleetTrip::query()->where('asset_id', $vehicle->getKey())->exists(),
            'policy' => $policy,
            'score' => [
                'kind' => $person ? 'driver' : 'vehicle',
                'value' => $scoringKm > 0 && $enough ? $this->weighted($scoring) : null,
                'eligible_trips' => count($scoring),
                'eligible_km' => $scoringKm,
                'personal_trips' => count($personal),
                'enough' => $enough,
            ],
            'summary' => [
                'trips' => count($shown),
                'scored_trips' => count($eligible),
                'partial_trips' => count(array_filter($shown, fn (array $row): bool => $row['score_state'] === 'coverage')),
                'distance_km' => round(array_sum(array_column($shown, 'distance_km')), 1),
                'overspeed_episodes' => array_sum(array_column($shown, 'overspeed_episodes')),
                'overspeed_seconds' => $secondsKnown ? $overspeedSeconds : null,
                'idle_minutes' => $idleKnownMinutes > 0 ? round($idle, 1) : null,
                'idle_pct' => $idleKnownMinutes > 0 ? (int) round(100 * $idle / $idleKnownMinutes) : null,
                'events_per_100km' => $scoringKm > 0
                    ? round(array_sum(array_column($scoring, 'driving_events')) * 100 / $scoringKm, 1) : null,
                'confirmed_trips' => count(array_filter($shown, fn (array $row): bool => $row['driver']['state'] === 'confirmed')),
                'cornering_events' => array_sum(array_map(fn (array $row): int => (int) ($row['harsh']['cornering'] ?? 0), $shown)),
                'withheld' => $withheld,
            ],
            'days' => $this->days($shown, $scoring),
            'trips' => array_map(fn (array $row): array => array_diff_key($row, ['events' => true]), $shown),
            'events' => $this->eventList($shown),
            'overspeed' => $episodes,
            'manual_limits' => SchemaCache::hasTable('fleet_speed_limits')
                ? FleetSpeedLimit::query()->where('asset_id', $vehicle->getKey())->where('status', 'approved')
                    ->where('expires_at', '>', now())->count()
                : 0,
            'can' => $this->can($viewer, $vehicle),
        ];
    }

    /**
     * The review workspace: business trips of the last 30 days and one trip's
     * reviewable events with their review history.
     *
     * @return array<string,mixed>
     */
    public function reviews(User $viewer, Asset $vehicle, ?int $tripId): array
    {
        $zone = VehicleTripHistoryService::zone();
        $today = CarbonImmutable::now($zone)->startOfDay();
        $list = $this->trips->insightData($viewer, $vehicle, $today->subDays(self::REVIEW_WINDOW_DAYS - 1)->toDateString(),
            $today->toDateString(), null, false);
        $options = [];
        foreach ($list['trips'] as $trip) {
            if ($trip->is_personal || $trip->consent_blocked) {
                continue;
            }
            $options[] = [
                'id' => (int) $trip->id,
                'reference' => 'Trip #'.$trip->id,
                'started_at' => $trip->started_at?->toIso8601String(),
                'local_date' => $trip->started_at?->copy()->setTimezone($zone)->toDateString(),
                'from' => self::text($trip->start_address),
                'to' => self::text($trip->end_address),
                'driver' => $list['driver_views'][$trip->id] ?? null,
            ];
        }
        $selected = $tripId ?? ($options[0]['id'] ?? null);

        return [
            'as_of' => now()->toIso8601String(),
            'timezone' => $zone,
            'trips' => $options,
            'trip' => $selected !== null ? $this->tripReview($viewer, $vehicle, $selected) : null,
            'policy' => $this->policies->describe(),
            'people' => $this->canReview($viewer)
                ? $this->staff->candidates($vehicle, null, array_filter([$viewer->id]))->all()
                : [],
            'can' => $this->can($viewer, $vehicle),
        ];
    }

    /**
     * One business trip's reviewable events, reviews and reviewed score.
     * Personal, consent-blocked and foreign trips answer 404.
     *
     * @return array<string,mixed>
     */
    public function tripReview(User $viewer, Asset $vehicle, int $tripId): array
    {
        $data = $this->trips->insightData($viewer, $vehicle, null, null, $tripId);
        $trip = $data['trips'][0] ?? abort(404);
        abort_if($trip->is_personal || $trip->consent_blocked, 404);
        $row = $this->tripRows($data, TripBehaviourAnalyzer::policy())[0];
        $history = FleetDrivingEventReview::query()->where('fleet_trip_id', $trip->id)
            ->with(['reviewOwner:id,name', 'recordedBy:id,name'])
            ->orderBy('event_key')->orderByDesc('sequence')->get()->groupBy('event_key');
        $driver = $row['driver'];
        $row['events'] = array_map(fn (array $event): array => $event + [
            'driver_at_event' => $driver['state'] === 'confirmed' ? $driver['name'] : null,
            'history' => ($history[$event['key']] ?? collect())
                ->map(fn (FleetDrivingEventReview $review): array => $this->reviewView($review))->values()->all(),
        ], $row['events']);

        return $row;
    }

    /** One reviewable event of a business trip, freshly analysed. @return array<string,mixed>|null */
    public function reviewableEvent(User $viewer, Asset $vehicle, FleetTrip $trip, string $eventKey): ?array
    {
        $data = $this->trips->insightData($viewer, $vehicle, null, null, (int) $trip->id);
        $row = $this->tripRows($data, TripBehaviourAnalyzer::policy())[0] ?? null;
        foreach ($row['events'] ?? [] as $event) {
            if ($event['key'] === $eventKey) {
                return $event;
            }
        }

        return null;
    }

    /**
     * A trip's score after human review: dismissed events no longer deduct
     * points and a disputed event withholds the score. The same policy and
     * formula as TripBehaviourAnalyzer, so an unreviewed trip keeps its
     * analysed score.
     *
     * @param  array<string,mixed>  $analysis
     * @param  list<array<string,mixed>>  $events  reviewable events with their current review
     * @param  array<string,mixed>  $policy  TripBehaviourAnalyzer::policy()
     * @return array{0:?int,1:string}
     */
    public static function reviewedScore(array $analysis, array $events, array $policy): array
    {
        $state = (string) ($analysis['score_state'] ?? 'no_samples');
        if ($state !== 'scored') {
            return [null, $state];
        }
        $counts = ['braking' => 0, 'acceleration' => 0, 'other' => 0, 'overspeed' => 0];
        foreach ($events as $event) {
            $outcome = $event['review']['outcome'] ?? null;
            if ($outcome === 'disputed') {
                return [null, 'disputed'];
            }
            if ($outcome === 'dismissed') {
                continue;
            }
            $counts[match ($event['type']) {
                'harsh-braking' => 'braking',
                'harsh-acceleration' => 'acceleration',
                'overspeed' => 'overspeed',
                default => 'other',
            }]++;
        }
        $weights = $policy['weights'];
        $idle = (float) ($analysis['idle_minutes'] ?? 0);

        return [(int) max(0, min(100, round(100
            - $counts['braking'] * $weights['braking']
            - $counts['acceleration'] * $weights['acceleration']
            - $counts['other'] * $weights['other_harsh']
            - $counts['overspeed'] * $weights['overspeed']
            - round($idle * $weights['idle_per_minute'])))), 'scored'];
    }

    /**
     * Business trips with their reviewable events and reviewed scores.
     *
     * @param  array<string,mixed>  $data  VehicleTripHistoryService::insightData()
     * @param  array<string,mixed>  $policy
     * @return list<array<string,mixed>>
     */
    private function tripRows(array $data, array $policy): array
    {
        $zone = VehicleTripHistoryService::zone();
        $business = array_values(array_filter($data['trips'], fn (FleetTrip $trip): bool => ! $trip->is_personal && ! $trip->consent_blocked));
        $reviews = $this->currentReviews(array_map(fn (FleetTrip $trip): int => (int) $trip->id, $business));
        $rows = [];
        foreach ($business as $trip) {
            $analysis = $data['analysis'][$trip->id] ?? null;
            if ($analysis === null) {
                continue;
            }
            $events = [];
            foreach ($analysis['events'] ?? [] as $event) {
                if (! in_array($event['type'], RecordedVehicleEvents::REVIEWABLE_TYPES, true)) {
                    continue;
                }
                $key = RecordedVehicleEvents::stableKey((string) $event['key']);
                $current = $reviews[$trip->id][$key] ?? null;
                $events[] = [
                    'key' => $key,
                    'type' => (string) $event['type'],
                    'kind' => (string) $event['kind'],
                    'title' => (string) $event['title'],
                    'detail' => (string) $event['detail'],
                    'at' => CarbonImmutable::createFromTimestampUTC((int) $event['at'])->toIso8601String(),
                    'peak_kph' => isset($event['peak_kph']) ? round((float) $event['peak_kph'], 1) : null,
                    'seconds' => isset($event['seconds']) ? (int) $event['seconds'] : null,
                    'source' => $event['source'] ?? null,
                    'review' => $current ? $this->reviewView($current) : null,
                    'version' => $current ? (int) $current->sequence : 0,
                ];
            }
            [$score, $state] = self::reviewedScore($analysis, $events, $policy);
            $rows[] = [
                'id' => (int) $trip->id,
                'reference' => 'Trip #'.$trip->id,
                'started_at' => $trip->started_at?->toIso8601String(),
                'ended_at' => $trip->ended_at?->toIso8601String(),
                'local_date' => $trip->started_at?->copy()->setTimezone($zone)->toDateString(),
                'in_progress' => $trip->ended_at === null,
                'from' => self::text($trip->start_address),
                'to' => self::text($trip->end_address),
                'distance_km' => round((float) $trip->distance_km, 1),
                'duration_s' => (int) $trip->duration_s,
                'coverage_pct' => $analysis['coverage_pct'],
                'partial' => (bool) $analysis['partial'],
                'score' => $score,
                'score_state' => $state,
                'original_score' => $analysis['score'],
                'driving_events' => (int) $analysis['driving_events'],
                'overspeed_episodes' => (int) $analysis['overspeed_episodes'],
                'overspeed_seconds' => $analysis['overspeed_seconds'],
                'harsh' => $analysis['harsh'],
                'faults' => (int) $analysis['faults'],
                'idle_minutes' => $analysis['idle_minutes'],
                'driver' => $data['driver_views'][$trip->id] ?? ['state' => 'none', 'source' => null, 'id' => null, 'name' => null],
                'all_reviewed' => collect($events)->every(fn (array $event): bool => in_array($event['review']['outcome'] ?? null, ['confirmed', 'dismissed'], true)),
                'events' => $events,
            ];
        }

        return $rows;
    }

    /**
     * One business trip's score after its current reviews, so Trip history
     * shows the same score as Driving insights for the same trip.
     *
     * @param  array<string,mixed>  $analysis  TripBehaviourAnalyzer result for the trip
     * @param  array<string,mixed>  $policy  TripBehaviourAnalyzer::policy()
     * @return array{0:?int,1:string}
     */
    public static function reviewedTripScore(FleetTrip $trip, array $analysis, array $policy): array
    {
        $reviews = self::latestReviews([(int) $trip->id])[(int) $trip->id] ?? [];
        $events = [];
        foreach ($analysis['events'] ?? [] as $event) {
            if (! in_array($event['type'], RecordedVehicleEvents::REVIEWABLE_TYPES, true)) {
                continue;
            }
            $review = $reviews[RecordedVehicleEvents::stableKey((string) $event['key'])] ?? null;
            $events[] = ['type' => (string) $event['type'], 'review' => $review ? ['outcome' => (string) $review->outcome] : null];
        }

        return self::reviewedScore($analysis, $events, $policy);
    }

    /**
     * The latest review of each event of the given trips.
     *
     * @param  list<int>  $tripIds
     * @return array<int,array<string,FleetDrivingEventReview>>
     */
    private function currentReviews(array $tripIds): array
    {
        return self::latestReviews($tripIds);
    }

    /**
     * @param  list<int>  $tripIds
     * @return array<int,array<string,FleetDrivingEventReview>>
     */
    private static function latestReviews(array $tripIds): array
    {
        if ($tripIds === [] || ! SchemaCache::hasTable('fleet_driving_event_reviews')) {
            return [];
        }
        $latest = FleetDrivingEventReview::query()
            ->select(DB::raw('MAX(id) as id'))
            ->whereIn('fleet_trip_id', $tripIds)
            ->groupBy('fleet_trip_id', 'event_key');
        $rows = FleetDrivingEventReview::query()->whereIn('id', $latest)
            ->with(['reviewOwner:id,name', 'recordedBy:id,name'])->get();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->fleet_trip_id][(string) $row->event_key] = $row;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function reviewView(FleetDrivingEventReview $review): array
    {
        return [
            'id' => (int) $review->id,
            'outcome' => (string) $review->outcome,
            'reason' => (string) $review->reason,
            'review_owner' => $review->reviewOwner?->name,
            'recorded_by' => $review->recordedBy?->name,
            'at' => $review->created_at?->toIso8601String(),
            'policy_version' => (int) $review->policy_version,
            'sequence' => (int) $review->sequence,
        ];
    }

    /**
     * Distance-weighted score per Auckland day with trips; null when no trip
     * that day could be scored.
     *
     * @param  list<array<string,mixed>>  $shown
     * @param  list<array<string,mixed>>  $scoring
     * @return list<array{day:string,score:?int,trips:int}>
     */
    private function days(array $shown, array $scoring): array
    {
        $days = [];
        foreach ($shown as $row) {
            if ($row['local_date'] !== null) {
                $days[$row['local_date']] ??= ['day' => $row['local_date'], 'score' => null, 'trips' => 0];
                $days[$row['local_date']]['trips']++;
            }
        }
        foreach (array_keys($days) as $day) {
            $scored = array_values(array_filter($scoring, fn (array $row): bool => $row['local_date'] === $day));
            $km = array_sum(array_column($scored, 'distance_km'));
            $days[$day]['score'] = $km > 0 ? $this->weighted($scored) : null;
        }
        ksort($days);

        return array_values($days);
    }

    /** @param list<array<string,mixed>> $rows */
    private function weighted(array $rows): ?int
    {
        $km = array_sum(array_column($rows, 'distance_km'));
        if ($km <= 0) {
            return null;
        }

        return (int) round(array_sum(array_map(fn (array $row): float => $row['score'] * $row['distance_km'], $rows)) / $km);
    }

    /**
     * Driving and overspeed events of the shown trips, newest first.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function eventList(array $rows): array
    {
        $events = [];
        foreach ($rows as $row) {
            foreach ($row['events'] as $event) {
                $events[] = $event + [
                    'trip_id' => $row['id'],
                    'trip_reference' => $row['reference'],
                    'local_date' => $row['local_date'],
                    'driver' => $row['driver'],
                ];
            }
        }
        usort($events, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return $events;
    }

    /** @return array<string,bool> */
    private function can(User $viewer, Asset $vehicle): array
    {
        $manage = $viewer->canDo('fleet.manage');

        return [
            'review' => $this->canReview($viewer),
            'manage_policy' => $this->policies->canPublish($viewer),
            'route' => $manage,
            'coach' => $manage,
            'confirm_driver' => $this->trips->canConfirmDriver($viewer),
            'view_alerts' => self::canViewAlerts($viewer),
            'manage_limits' => $manage,
            'upload_evidence' => Gate::forUser($viewer)->allows('manageDocuments', $vehicle),
        ];
    }

    private function trackerLinked(Asset $vehicle): bool
    {
        return (SchemaCache::hasTable('device_asset_links') && DB::table('device_asset_links')
            ->where('asset_id', $vehicle->getKey())->whereNull('unlinked_at')->exists())
            || (SchemaCache::hasTable('asset_trackers') && DB::table('asset_trackers')
                ->where('asset_id', $vehicle->getKey())->where('status', 'paired')->exists());
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
