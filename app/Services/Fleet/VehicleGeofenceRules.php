<?php

namespace App\Services\Fleet;

use App\Models\AssetGeofence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Geometry and schedule rules for a vehicle's geofence assignment. They follow
 * the Client Location zone contract (StoreClientLocationZoneDraftRequest) so a
 * boundary and a schedule mean the same thing on every profile: ISO weekdays
 * (1 = Monday), Pacific/Auckland wall times, an explicit following-day finish
 * and exception dates inside the schedule on a scheduled day.
 */
final class VehicleGeofenceRules
{
    public const TIMEZONE = 'Pacific/Auckland';

    /**
     * Validate a vehicle assignment's details and return the normalised values.
     *
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $extra  Rules for the caller's own fields.
     * @return array<string,mixed>
     */
    public static function validate(array $data, array $extra, bool $geometryRequired): array
    {
        $validator = Validator::make($data, [
            ...$extra,
            'label' => ['required', 'string', 'max:120'],
            'purpose' => ['required', 'string', 'max:2000'],
            'response_proposal' => ['nullable', 'string', 'max:2000'],
            ...($geometryRequired ? self::geometryRules() : ['geometry' => ['prohibited']]),
            ...self::scheduleRules(),
        ], [], [
            'label' => 'geofence name',
            'purpose' => 'purpose',
            'response_proposal' => 'response instructions',
            'schedule.weekdays' => 'scheduled days',
            'schedule.start' => 'start time',
            'schedule.end' => 'end time',
            'schedule.first_date' => 'first date',
            'schedule.last_date' => 'last date',
            'schedule.exception_dates' => 'exception dates',
        ]);
        $validator->after(function ($validator) use ($data, $geometryRequired): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach (self::scheduleProblems($data['schedule']) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
            if ($geometryRequired) {
                $problem = self::geometryProblem($data['geometry']);
                if ($problem !== null) {
                    $validator->errors()->add('geometry', $problem);
                }
            }
        });
        $validator->validate();

        return [
            'label' => trim((string) $data['label']),
            'purpose' => trim((string) $data['purpose']),
            'response_proposal' => self::text($data['response_proposal'] ?? null),
            'schedule' => self::schedule($data['schedule']),
            'geometry' => $geometryRequired ? self::normalise($data['geometry']) : null,
        ];
    }

    /** @return array<string,array<int,mixed>> */
    public static function geometryRules(): array
    {
        return [
            'geometry' => ['required', 'array:type,center,radius_m,coordinates'],
            'geometry.type' => ['required', Rule::in(['circle', 'polygon'])],
            'geometry.center' => ['required_if:geometry.type,circle', 'prohibited_if:geometry.type,polygon', 'array:lat,lng'],
            'geometry.center.lat' => ['required_with:geometry.center', 'numeric', 'between:-90,90'],
            'geometry.center.lng' => ['required_with:geometry.center', 'numeric', 'between:-180,180'],
            'geometry.radius_m' => ['required_if:geometry.type,circle', 'prohibited_if:geometry.type,polygon', 'numeric', 'gt:0', 'max:50000'],
            'geometry.coordinates' => ['required_if:geometry.type,polygon', 'prohibited_if:geometry.type,circle', 'array', 'min:3', 'max:200'],
            'geometry.coordinates.*' => ['array:lat,lng'],
            'geometry.coordinates.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'geometry.coordinates.*.lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /** @return array<string,array<int,mixed>> */
    public static function scheduleRules(): array
    {
        return [
            'schedule' => ['required', 'array:timezone,weekdays,start,end,following_day,first_date,last_date,exception_dates'],
            'schedule.timezone' => ['required', Rule::in([self::TIMEZONE])],
            'schedule.weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'schedule.weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'schedule.start' => ['required', 'date_format:H:i'],
            'schedule.end' => ['required', 'date_format:H:i'],
            'schedule.following_day' => ['required', 'boolean'],
            'schedule.first_date' => ['required', 'date_format:Y-m-d'],
            'schedule.last_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:schedule.first_date'],
            'schedule.exception_dates' => ['present', 'array', 'max:366'],
            'schedule.exception_dates.*' => ['date_format:Y-m-d', 'distinct', 'after_or_equal:schedule.first_date', 'before_or_equal:schedule.last_date'],
        ];
    }

    /**
     * Cross-field schedule checks the field rules can't express.
     *
     * @param  array<string,mixed>  $schedule
     * @return array<string,string>
     */
    public static function scheduleProblems(array $schedule): array
    {
        $problems = [];
        $overnight = filter_var($schedule['following_day'], FILTER_VALIDATE_BOOLEAN);
        if ((! $overnight && $schedule['end'] <= $schedule['start'])
            || ($overnight && $schedule['end'] > $schedule['start'])) {
            $problems['schedule.end'] = 'Choose a later finish, or mark an overnight finish on the following day (at most 24 hours).';
        }
        $days = array_map('intval', $schedule['weekdays']);
        foreach ($schedule['exception_dates'] as $date) {
            if (! in_array(CarbonImmutable::createFromFormat('!Y-m-d', $date)->isoWeekday(), $days, true)) {
                $problems['schedule.exception_dates'] = 'An exception must fall on a selected scheduled day.';
            }
        }

        return $problems;
    }

    /**
     * Why a boundary can't be saved, or null when it is valid. Polygons must
     * enclose an area without crossing or touching themselves.
     *
     * @param  array<string,mixed>  $geometry
     */
    public static function geometryProblem(array $geometry): ?string
    {
        if (($geometry['type'] ?? null) === 'circle') {
            return is_finite((float) $geometry['radius_m']) ? null : 'Enter a finite radius in metres.';
        }
        $points = array_values($geometry['coordinates'] ?? []);
        $count = count($points);
        $cross = fn (array $a, array $b, array $c): float => ($b['lng'] - $a['lng']) * ($c['lat'] - $a['lat'])
            - ($b['lat'] - $a['lat']) * ($c['lng'] - $a['lng']);
        $on = fn (array $a, array $b, array $p): bool => abs($cross($a, $b, $p)) < 1e-12
            && $p['lng'] >= min($a['lng'], $b['lng']) && $p['lng'] <= max($a['lng'], $b['lng'])
            && $p['lat'] >= min($a['lat'], $b['lat']) && $p['lat'] <= max($a['lat'], $b['lat']);
        $points = array_map(fn (array $point): array => ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']], $points);
        $area = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $a = $points[$i];
            $b = $points[($i + 1) % $count];
            if ($a == $b || abs($a['lng'] - $b['lng']) > 180) {
                return 'Use distinct corners without crossing the date line.';
            }
            // Translate to the first point to avoid cancellation on small local boundaries.
            $area += ($a['lng'] - $points[0]['lng']) * ($b['lat'] - $points[0]['lat'])
                - ($b['lng'] - $points[0]['lng']) * ($a['lat'] - $points[0]['lat']);
            for ($j = $i + 2; $j < $count; $j++) {
                if ($i === 0 && $j === $count - 1) {
                    continue;
                }
                $c = $points[$j];
                $d = $points[($j + 1) % $count];
                if (($cross($a, $b, $c) * $cross($a, $b, $d) < 0 && $cross($c, $d, $a) * $cross($c, $d, $b) < 0)
                    || $on($a, $b, $c) || $on($a, $b, $d) || $on($c, $d, $a) || $on($c, $d, $b)) {
                    return 'The boundary must not cross or touch itself. Move the affected corners.';
                }
            }
        }

        return abs($area) < 1e-12 ? 'Spread the corners out to enclose an area.' : null;
    }

    /**
     * A boundary in one canonical form: floats, fixed precision, lat/lng keys.
     *
     * @param  array<string,mixed>  $geometry
     * @return array<string,mixed>
     */
    public static function normalise(array $geometry): array
    {
        $point = fn (array $p): array => [
            'lat' => round((float) $p['lat'], 7),
            'lng' => round((float) $p['lng'], 7),
        ];

        return $geometry['type'] === 'circle'
            ? ['type' => 'circle', 'center' => $point($geometry['center']), 'radius_m' => round((float) $geometry['radius_m'], 2)]
            : ['type' => 'polygon', 'coordinates' => array_values(array_map($point, $geometry['coordinates']))];
    }

    /**
     * The stored shape for a new canonical AssetGeofence, in the form the fleet
     * evaluator, the Site profile editor and Client Location all read.
     *
     * @param  array<string,mixed>  $geometry  Normalised geometry.
     * @return array<string,mixed>
     */
    public static function shape(array $geometry): array
    {
        return $geometry['type'] === 'circle'
            ? ['center' => $geometry['center'], 'radius_m' => $geometry['radius_m']]
            : ['coordinates' => $geometry['coordinates']];
    }

    /**
     * A canonical boundary's geometry, read from any of the stored shape
     * variants the evaluator accepts. Null when the stored shape is incomplete.
     *
     * @return array<string,mixed>|null
     */
    public static function fromBoundary(AssetGeofence $fence): ?array
    {
        $shape = is_array($fence->shape) ? $fence->shape : [];
        $point = function (mixed $p): ?array {
            if (! is_array($p)) {
                return null;
            }
            $lat = $p['lat'] ?? $p['latitude'] ?? null;
            $lng = $p['lng'] ?? $p['lon'] ?? $p['longitude'] ?? null;

            return is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180
                ? ['lat' => round((float) $lat, 7), 'lng' => round((float) $lng, 7)]
                : null;
        };
        if ($fence->type === 'polygon') {
            $points = array_map($point, array_values((array) ($shape['coordinates'] ?? $shape['points'] ?? [])));
            if (count($points) < 3 || in_array(null, $points, true)) {
                return null;
            }

            return ['type' => 'polygon', 'coordinates' => $points];
        }
        $center = $point(is_array($shape['center'] ?? null) ? $shape['center'] : $shape);
        $radius = $shape['radius_m'] ?? $shape['radius'] ?? null;
        if ($center === null || ! is_numeric($radius) || (float) $radius <= 0) {
            return null;
        }

        return ['type' => 'circle', 'center' => $center, 'radius_m' => round((float) $radius, 2)];
    }

    /** The version of a canonical boundary a vehicle assignment was reviewed against. */
    public static function boundaryHash(AssetGeofence $fence): string
    {
        return MaintenanceFingerprint::of([
            'id' => (int) $fence->getKey(),
            'site_id' => $fence->site_id === null ? null : (int) $fence->site_id,
            'type' => (string) $fence->type,
            'geometry' => self::fromBoundary($fence),
        ]);
    }

    /**
     * @param  array<string,mixed>  $schedule
     * @return array<string,mixed>
     */
    public static function schedule(array $schedule): array
    {
        $weekdays = array_values(array_unique(array_map('intval', $schedule['weekdays'])));
        sort($weekdays);
        $exceptions = array_values(array_unique(array_map('strval', $schedule['exception_dates'])));
        sort($exceptions);

        return [
            'timezone' => self::TIMEZONE,
            'weekdays' => $weekdays,
            'start' => (string) $schedule['start'],
            'end' => (string) $schedule['end'],
            'following_day' => filter_var($schedule['following_day'], FILTER_VALIDATE_BOOLEAN),
            'first_date' => (string) $schedule['first_date'],
            'last_date' => (string) $schedule['last_date'],
            'exception_dates' => $exceptions,
        ];
    }

    /** Refuse a request with one field message, the way validation does. */
    public static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
