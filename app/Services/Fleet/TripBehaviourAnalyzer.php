<?php

namespace App\Services\Fleet;

/**
 * Reads the telemetry recorded during one trip, in time order, and works out
 * what it can honestly say about the journey: coverage, speed, overspeed
 * episodes, harsh events, idle time and a behaviour score with its basis.
 *
 * The same analysis feeds the trip list, the trip detail and the exports so
 * every surface reports the same numbers. Nothing is inferred beyond the
 * records: missing positions reduce coverage, unknown ignition leaves idle
 * time unknown, and a score is withheld whenever its basis is too thin.
 *
 * Rows are plain arrays with occurred_at (UTC 'Y-m-d H:i:s' or a timestamp),
 * latitude, longitude, speed_kph, heading_deg, ignition, event_type,
 * report_type and address. Callers pass only rows that are not
 * consent-blocked.
 */
final class TripBehaviourAnalyzer
{
    /** Tracker alarms this close to a threshold episode are the same episode. */
    private const ALARM_MERGE_SECONDS = 60;

    private int $positional = 0;

    private ?int $firstPositionAt = null;

    private ?int $lastPositionAt = null;

    private int $gapExcess = 0;

    /** @var list<array{from:int,to:int}> */
    private array $gaps = [];

    private ?float $maxSpeed = null;

    private int $speedSamples = 0;

    /** @var list<array<string,mixed>> */
    private array $episodes = [];

    /** @var array<string,mixed>|null */
    private ?array $openEpisode = null;

    /** @var list<array{at:int,phase:string,speed:float|null,id:string}> */
    private array $alarms = [];

    /** @var array<string,int> */
    private array $harsh = [
        FleetDrivingEventClassifier::BRAKING => 0,
        FleetDrivingEventClassifier::ACCELERATION => 0,
        FleetDrivingEventClassifier::CORNERING => 0,
        FleetDrivingEventClassifier::UNCLASSIFIED => 0,
    ];

    private int $faults = 0;

    private ?bool $ignition = null;

    private bool $ignitionKnown = false;

    /** @var array{at:int,speed:float,ignition:bool|null}|null */
    private ?array $previousSpeedSample = null;

    private float $idleMinutes = 0.0;

    /** @var list<array<string,mixed>> */
    private array $points = [];

    /** @var list<array<string,mixed>> */
    private array $events = [];

    /** @var array<string,true> */
    private array $vendors = [];

    /**
     * @param  array{speed_threshold_kph:float,idle_speed_kph:float,idle_after_minutes:float,max_idle_increment_minutes:float,coverage_gap_seconds:int,min_score_coverage_pct:int,weights:array{braking:float,acceleration:float,other_harsh:float,overspeed:float,idle_per_minute:float}}  $policy
     */
    public function __construct(
        private readonly array $policy,
        private readonly int $startAt,
        private readonly ?int $endAt,
        private readonly float $distanceKm,
        private readonly bool $personal = false,
        private readonly bool $consentBlocked = false,
        private readonly bool $collect = false,
    ) {}

    public static function policy(): array
    {
        $weights = (array) config('fleet.behaviour.score_weights', []);
        $acceleration = (float) ($weights['accel'] ?? 3);

        // PKG-02B: a published scoring policy version supersedes the
        // configured weights and coverage minimum on every scoring surface.
        return DrivingScorePolicyStore::apply([
            'speed_threshold_kph' => (float) config('fleet.behaviour.speeding_kph', 100),
            'idle_speed_kph' => (float) config('fleet.behaviour.idle_speed_kph', 3),
            'idle_after_minutes' => (float) config('fleet.behaviour.idle_after_minutes', 2),
            'max_idle_increment_minutes' => (float) config('fleet.behaviour.max_idle_increment_minutes', 15),
            'coverage_gap_seconds' => max(10, (int) config('fleet.trip.coverage_gap_seconds', 120)),
            'min_score_coverage_pct' => min(100, max(0, (int) config('fleet.behaviour.score_min_coverage_pct', 90))),
            'weights' => [
                'braking' => (float) ($weights['harsh_brake'] ?? 5),
                'acceleration' => $acceleration,
                // No weight is configured for cornering or unclassified harsh
                // reports; they take the lower acceleration weight.
                'other_harsh' => (float) ($weights['harsh_other'] ?? $acceleration),
                'overspeed' => (float) ($weights['speeding'] ?? 4),
                'idle_per_minute' => (float) ($weights['idle'] ?? 0.5),
            ],
        ]);
    }

    public static function timestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        $parsed = strtotime($value.' UTC');

        return $parsed === false ? null : $parsed;
    }

    /** @param  array<string,mixed>  $row */
    public function add(array $row): void
    {
        $at = self::timestamp($row['occurred_at'] ?? null);
        if ($at === null) {
            return;
        }
        $type = strtolower((string) ($row['event_type'] ?? ''));
        $reportType = isset($row['report_type']) ? (string) $row['report_type'] : null;
        $speed = is_numeric($row['speed_kph'] ?? null) ? (float) $row['speed_kph'] : null;
        $id = (string) ($row['id'] ?? $at);
        if (! empty($row['vendor'])) {
            $this->vendors[(string) $row['vendor']] = true;
        }

        // Ignition is carried forward from the last report that stated it.
        if ($type === 'ignition_on' || $type === 'ignition_off') {
            $this->ignition = $type === 'ignition_on';
            $this->ignitionKnown = true;
            $this->event($type, $at, $id, 'journey', $type === 'ignition_on' ? 'Ignition on' : 'Ignition off',
                'Reported by the tracker.');
        } elseif (($row['ignition'] ?? null) !== null) {
            $this->ignition = (bool) $row['ignition'];
            $this->ignitionKnown = true;
        }

        $hasPosition = is_numeric($row['latitude'] ?? null) && is_numeric($row['longitude'] ?? null);
        if ($hasPosition) {
            $this->position($at, $row, $speed);
        }
        if ($speed !== null) {
            $this->speed($at, $speed, $id);
        }

        $harsh = FleetDrivingEventClassifier::harshKind($type, $reportType);
        if ($harsh !== null) {
            $this->harsh[$harsh]++;
            [$title, $detail] = match ($harsh) {
                FleetDrivingEventClassifier::BRAKING => ['Harsh braking', 'Reported by the tracker.'],
                FleetDrivingEventClassifier::ACCELERATION => ['Harsh acceleration', 'Reported by the tracker.'],
                FleetDrivingEventClassifier::CORNERING => ['Harsh cornering', 'Reported by the tracker.'],
                default => ['Harsh driving', 'The tracker did not report whether this was braking, acceleration or cornering.'],
            };
            $this->event('harsh-'.$harsh, $at, $id, 'driving', $title,
                $detail.($speed !== null ? ' Speed at the time: '.self::kph($speed).'.' : '')
                .' Review the circumstances before drawing conclusions.');
        }

        $phase = FleetDrivingEventClassifier::speedAlarmPhase($type, $reportType);
        if ($phase !== null) {
            $this->alarms[] = ['at' => $at, 'phase' => $phase, 'speed' => $speed, 'id' => $id];
            if ($phase === 'end') {
                $this->event('speed-alarm-end', $at, $id, 'speed', 'Speed back within tracker range',
                    'The tracker reported the speed back inside its configured range.');
            }
        }

        if (FleetDrivingEventClassifier::isFault($type)) {
            $this->faults++;
            [$title, $detail] = match ($type) {
                'external_power' => ['Vehicle power alert', 'The tracker reported its external power supply outside the configured range. Check the vehicle battery and wiring.'],
                'power_off' => ['Tracker power lost', 'The tracker reported losing its main power.'],
                default => ['Tracker backup battery low', 'The tracker reported its own backup battery is low. This is not the vehicle battery.'],
            };
            $this->event($type, $at, $id, 'power', $title, $detail);
        } elseif ($type === 'power_on') {
            $this->event($type, $at, $id, 'power', 'Tracker power restored', 'The tracker reported its main power is back.');
        } elseif (in_array($type, ['geofence_enter', 'geofence_exit'], true)) {
            $this->event($type, $at, $id, 'journey', $type === 'geofence_enter' ? 'Entered a geofence' : 'Left a geofence',
                'Reported by the tracker against a geofence configured on it.');
        } elseif ($type === 'tamper') {
            $this->event($type, $at, $id, 'journey', 'Tow or tamper alarm', 'Reported by the tracker. Alarms are handled in Control Room.');
        } elseif ($type === 'vehicle_sos') {
            $this->event($type, $at, $id, 'journey', 'SOS alarm', 'Reported by the tracker. Alarms are handled in Control Room.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function finish(int $now, int $pointCap = 2000): array
    {
        $policy = $this->policy;
        $gapLimit = (int) $policy['coverage_gap_seconds'];
        $inProgress = $this->endAt === null;
        $windowEnd = $this->endAt ?? max($this->startAt, min($now, $this->lastPositionAt ?? $now));
        $duration = max(0, $windowEnd - $this->startAt);

        if ($this->openEpisode !== null) {
            $this->closeEpisode($this->openEpisode['last_above'], 'last_sample');
        }

        // Coverage: the share of the trip with a recorded position no further
        // apart than the gap limit, including the start and the end.
        $coverage = null;
        if ($this->positional === 0) {
            $coverage = 0;
        } else {
            $lead = max(0, $this->firstPositionAt - $this->startAt - $gapLimit);
            $tail = $inProgress ? 0 : max(0, $windowEnd - $this->lastPositionAt - $gapLimit);
            if ($lead > 0) {
                $this->gaps[] = ['from' => $this->startAt, 'to' => $this->firstPositionAt];
            }
            if ($tail > 0) {
                $this->gaps[] = ['from' => $this->lastPositionAt, 'to' => $windowEnd];
            }
            $excess = $this->gapExcess + $lead + $tail;
            $coverage = $duration > 0 ? (int) round(100 * max(0, $duration - $excess) / $duration) : 100;
        }

        // Tracker speed alarms join the threshold episode they belong to;
        // alarms outside every episode are episodes of their own.
        foreach ($this->alarms as $index => $alarm) {
            if ($alarm['phase'] === 'end') {
                continue;
            }
            foreach ($this->episodes as $key => $episode) {
                if ($episode['source'] === 'fleet_threshold'
                    && $alarm['at'] >= $episode['start'] - self::ALARM_MERGE_SECONDS
                    && $alarm['at'] <= $episode['end'] + self::ALARM_MERGE_SECONDS) {
                    $this->episodes[$key]['tracker_alarm'] = true;

                    continue 2;
                }
            }
            // The alarm lasts until the tracker's next report, if that report
            // says the speed came back into range.
            $next = $this->alarms[$index + 1] ?? null;
            $end = $next !== null && $next['phase'] === 'end' ? $next['at'] : null;
            $this->episodes[] = [
                'source' => 'tracker_alarm', 'start' => $alarm['at'], 'end' => $end ?? $alarm['at'],
                'seconds' => $end !== null ? max(0, $end - $alarm['at']) : null,
                'peak' => $alarm['speed'], 'id' => $alarm['id'], 'tracker_alarm' => true,
            ];
        }
        usort($this->episodes, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $threshold = (float) $policy['speed_threshold_kph'];
        foreach ($this->episodes as $episode) {
            $this->event('overspeed', $episode['start'], (string) $episode['id'], 'speed',
                $episode['source'] === 'fleet_threshold' ? 'Over fleet speed threshold' : 'Tracker speed alarm',
                $this->episodeDetail($episode, $threshold), [
                    'peak_kph' => $episode['peak'],
                    'seconds' => $episode['seconds'],
                    'source' => $episode['source'],
                ]);
        }
        foreach ($this->gaps as $gap) {
            $minutes = max(1, (int) round(($gap['to'] - $gap['from']) / 60));
            $this->event('gap', $gap['from'], 'gap-'.$gap['from'], 'journey', 'Missing report window',
                "No position was recorded for {$minutes} min. The route between these points is unknown.");
        }

        $episodes = count($this->episodes);
        $knownSeconds = array_values(array_filter(array_column($this->episodes, 'seconds'), fn ($value) => $value !== null));
        $harshTotal = array_sum($this->harsh);
        $drivingEvents = $harshTotal + $episodes;
        $idle = $this->ignitionKnown ? round($this->idleMinutes, 1) : null;
        $minCoverage = (int) $policy['min_score_coverage_pct'];
        $partial = ! $this->consentBlocked && $coverage !== null && $coverage < $minCoverage;

        $state = match (true) {
            $this->consentBlocked => 'consent',
            $this->personal => 'personal',
            $inProgress => 'in_progress',
            $this->speedSamples < 2 || $this->positional < 2 => 'no_samples',
            $coverage === null || $coverage < $minCoverage => 'coverage',
            default => 'scored',
        };
        $weights = $policy['weights'];
        $score = null;
        if ($state === 'scored') {
            $score = (int) max(0, min(100, round(100
                - $this->harsh[FleetDrivingEventClassifier::BRAKING] * $weights['braking']
                - $this->harsh[FleetDrivingEventClassifier::ACCELERATION] * $weights['acceleration']
                - ($this->harsh[FleetDrivingEventClassifier::CORNERING] + $this->harsh[FleetDrivingEventClassifier::UNCLASSIFIED]) * $weights['other_harsh']
                - $episodes * $weights['overspeed']
                - round(($idle ?? 0) * $weights['idle_per_minute']))));
        }

        $result = [
            'samples' => $this->positional,
            'speed_samples' => $this->speedSamples,
            'coverage_pct' => $coverage,
            'partial' => $partial,
            'max_speed_kph' => $this->maxSpeed,
            'overspeed_episodes' => $episodes,
            'overspeed_seconds' => $knownSeconds === [] ? ($episodes > 0 ? null : 0) : (int) array_sum($knownSeconds),
            'harsh' => [
                'braking' => $this->harsh[FleetDrivingEventClassifier::BRAKING],
                'acceleration' => $this->harsh[FleetDrivingEventClassifier::ACCELERATION],
                'cornering' => $this->harsh[FleetDrivingEventClassifier::CORNERING],
                'unclassified' => $this->harsh[FleetDrivingEventClassifier::UNCLASSIFIED],
                'total' => $harshTotal,
            ],
            'faults' => $this->faults,
            'driving_events' => $drivingEvents,
            'idle_minutes' => $idle,
            'events_per_100km' => $this->distanceKm >= 1 ? round($drivingEvents * 100 / $this->distanceKm, 1) : null,
            'score' => $score,
            'score_state' => $state,
            'in_progress' => $inProgress,
            'vendors' => array_keys($this->vendors),
        ];

        if (! $this->collect) {
            return $result;
        }

        return $result + $this->collected($pointCap);
    }

    /** @param  array<string,mixed>  $row */
    private function position(int $at, array $row, ?float $speed): void
    {
        $gapLimit = (int) $this->policy['coverage_gap_seconds'];
        if ($this->lastPositionAt !== null) {
            $gap = $at - $this->lastPositionAt;
            if ($gap > $gapLimit) {
                $this->gapExcess += $gap - $gapLimit;
                $this->gaps[] = ['from' => $this->lastPositionAt, 'to' => $at];
            }
        }
        $this->firstPositionAt ??= $at;
        $this->lastPositionAt = $at;
        $this->positional++;

        if ($this->collect) {
            $address = trim((string) ($row['address'] ?? ''));
            $this->points[] = [
                'at' => $at,
                'lat' => round((float) $row['latitude'], 7),
                'lng' => round((float) $row['longitude'], 7),
                'speed_kph' => $speed !== null ? round($speed, 1) : null,
                'heading' => is_numeric($row['heading_deg'] ?? null) ? (int) $row['heading_deg'] : null,
                'ignition' => $this->ignitionKnown ? $this->ignition : null,
                'address' => $address !== '' ? $address : null,
            ];
        }
    }

    private function speed(int $at, float $speed, string $id): void
    {
        $this->speedSamples++;
        $this->maxSpeed = $this->maxSpeed === null ? $speed : max($this->maxSpeed, $speed);
        $threshold = (float) $this->policy['speed_threshold_kph'];

        if ($speed >= $threshold) {
            if ($this->openEpisode === null) {
                $this->openEpisode = ['start' => $at, 'peak' => $speed, 'last_above' => $at, 'id' => $id];
            } else {
                $this->openEpisode['peak'] = max($this->openEpisode['peak'], $speed);
                $this->openEpisode['last_above'] = $at;
            }
        } elseif ($this->openEpisode !== null) {
            $this->closeEpisode($at, 'below');
        }

        // Idle mirrors the daily driving metric: consecutive slow samples
        // with the ignition known to be on.
        $previous = $this->previousSpeedSample;
        $ignition = $this->ignitionKnown ? $this->ignition : null;
        $idleSpeed = (float) $this->policy['idle_speed_kph'];
        if ($previous !== null && $speed <= $idleSpeed && $previous['speed'] <= $idleSpeed
            && $ignition === true && $previous['ignition'] === true) {
            $minutes = ($at - $previous['at']) / 60;
            if ($minutes >= (float) $this->policy['idle_after_minutes']) {
                $this->idleMinutes += min($minutes, (float) $this->policy['max_idle_increment_minutes']);
            }
        }
        $this->previousSpeedSample = ['at' => $at, 'speed' => $speed, 'ignition' => $ignition];
    }

    private function closeEpisode(int $end, string $closedBy): void
    {
        $open = $this->openEpisode;
        $this->openEpisode = null;
        if ($open === null) {
            return;
        }
        $this->episodes[] = [
            'source' => 'fleet_threshold', 'start' => $open['start'], 'end' => $end,
            'seconds' => max(0, $end - $open['start']), 'peak' => $open['peak'], 'id' => $open['id'],
            'closed_by' => $closedBy, 'tracker_alarm' => false,
        ];
    }

    /** @param  array<string,mixed>  $episode */
    private function episodeDetail(array $episode, float $threshold): string
    {
        if ($episode['source'] === 'tracker_alarm') {
            return 'The tracker reported the speed outside its own configured range'
                .($episode['peak'] !== null ? ' at '.self::kph($episode['peak']) : '')
                .($episode['seconds'] !== null ? ' for '.$episode['seconds'].' sec' : '')
                .'. That threshold is set on the tracker and the road speed limit is not checked.';
        }

        return self::kph($episode['peak']).' peak · '.$episode['seconds'].' sec at or above the '
            .self::kph($threshold).' fleet threshold, measured between recorded samples.'
            .($episode['tracker_alarm'] ? ' The tracker also raised a speed alarm.' : '')
            .' The road speed limit is not checked.';
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function event(string $type, int $at, string $id, string $kind, string $title, string $detail, array $extra = []): void
    {
        if (! $this->collect) {
            return;
        }
        $this->events[] = [
            'key' => $type.'-'.$id.'-'.count($this->events),
            'type' => $type,
            'kind' => $kind,
            'title' => $title,
            'detail' => $detail,
            'at' => $at,
            ...$extra,
        ];
    }

    /** @return array{points:list<array<string,mixed>>,events:list<array<string,mixed>>,recorded_points:int,downsampled:bool} */
    private function collected(int $cap): array
    {
        $points = $this->points;
        $recorded = count($points);
        $downsampled = false;
        if ($cap > 1 && $recorded > $cap) {
            $step = ($recorded - 1) / ($cap - 1);
            $kept = [];
            for ($k = 0; $k < $cap; $k++) {
                $kept[] = $points[(int) round($k * $step)];
            }
            $points = $kept;
            $downsampled = true;
        }

        $events = $this->events;
        usort($events, fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        // Each event points at the last recorded position at or before it.
        $times = array_column($points, 'at');
        foreach ($events as $index => $event) {
            $events[$index]['point'] = self::pointAt($times, (int) $event['at']);
        }

        return [
            'points' => $points,
            'events' => $events,
            'recorded_points' => $recorded,
            'downsampled' => $downsampled,
        ];
    }

    /** @param  list<int>  $times */
    private static function pointAt(array $times, int $at): int
    {
        if ($times === []) {
            return 0;
        }
        $low = 0;
        $high = count($times) - 1;
        $found = 0;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($times[$mid] <= $at) {
                $found = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $found;
    }

    private static function kph(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').' km/h';
    }
}
