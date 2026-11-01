<?php

namespace App\Domain\SecurityDevices\Iot\Readings;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sensor readings over time (S&D Phase E): written as readings arrive, read by the sensor page's
 * graphs and the battery forecast. One row per metric per reading; kept 13 months.
 */
final class SensorReadingHistory
{
    public const int KEEP_MONTHS = 13;

    /** Battery forecasts need at least this many days of readings, and a real drop. */
    public const int FORECAST_MIN_DAYS = 3;

    /** @param  array<string, float|int>  $values  metric => value */
    public function record(int $deviceId, array $values, CarbonImmutable $at): void
    {
        $rows = [];
        foreach ($values as $metric => $value) {
            if (! is_string($metric) || $metric === '' || ! is_numeric($value) || ! is_finite((float) $value)) {
                continue;
            }
            $rows[] = [
                'device_id' => $deviceId,
                'metric' => mb_substr($metric, 0, 32),
                'value' => round((float) $value, 2),
                'observed_at' => $at->setTimezone('UTC')->format('Y-m-d H:i:s'),
            ];
        }
        if ($rows !== []) {
            DB::table('security_device_sensor_readings')->insert($rows);
            $this->afterRecorded($deviceId, $values, $at);
        }
    }

    /**
     * S&D Phase F: readings some care rules watch, once they're stored. A hot-water probe's
     * temperature (scald watch) and a bed sensor's in/out of bed (a falls plan).
     *
     * @param  array<string, float|int>  $values
     */
    private function afterRecorded(int $deviceId, array $values, CarbonImmutable $at): void
    {
        app(\App\Domain\SecurityDevices\Automations\AutomationEngine::class)->onReading($deviceId, $values, $at); // S&D Phase F: house automations (never throws)
        $temperature = $values['temperature'] ?? null;
        $occupancy = $values['occupancy'] ?? null;
        if (! is_numeric($temperature) && ! is_numeric($occupancy)) {
            return;
        }
        $device = \App\Domain\SecurityDevices\Models\Device::query()->find($deviceId);
        if ($device === null) {
            return;
        }
        if (is_numeric($temperature)) {
            app(\App\Domain\SecurityDevices\Care\ScaldWatch::class)->onTemperature($device, (float) $temperature, $at);
        }
        if (is_numeric($occupancy)) {
            app(\App\Domain\SecurityDevices\Care\CareRules::class)->onOccupancy($device, (float) $occupancy >= 0.5, $at);
        }
    }

    /**
     * Readings for one metric, oldest first, thinned to at most $points by averaging buckets.
     *
     * @return list<array{at: string, value: float}>
     */
    public function series(int $deviceId, string $metric, CarbonImmutable $from, CarbonImmutable $to, int $points = 96): array
    {
        $rows = DB::table('security_device_sensor_readings')
            ->where('device_id', $deviceId)->where('metric', $metric)
            ->whereBetween('observed_at', [$from->utc()->format('Y-m-d H:i:s'), $to->utc()->format('Y-m-d H:i:s')])
            ->orderBy('observed_at')
            ->limit(20_000)
            ->get(['value', 'observed_at']);
        if ($rows->isEmpty()) {
            return [];
        }
        $span = max(1, $to->getTimestamp() - $from->getTimestamp());
        $bucket = max(1, intdiv($span, max(1, $points)));
        $buckets = [];
        foreach ($rows as $row) {
            $at = CarbonImmutable::parse((string) $row->observed_at, 'UTC')->getTimestamp();
            $key = intdiv($at - $from->getTimestamp(), $bucket);
            $buckets[$key][] = (float) $row->value;
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $key => $values) {
            $out[] = [
                'at' => CarbonImmutable::createFromTimestamp($from->getTimestamp() + $key * $bucket + intdiv($bucket, 2), 'UTC')->toIso8601String(),
                'value' => round(array_sum($values) / count($values), 2),
            ];
        }

        return $out;
    }

    /**
     * About how many days until the battery is empty, from the last 30 days' drop (least squares).
     * Null when there isn't enough history or the battery isn't dropping (just changed, mains).
     */
    public function batteryDaysLeft(int $deviceId, ?CarbonImmutable $now = null): ?int
    {
        $now ??= CarbonImmutable::now('UTC');
        $rows = DB::table('security_device_sensor_readings')
            ->where('device_id', $deviceId)->where('metric', 'battery')
            ->where('observed_at', '>=', $now->subDays(30)->format('Y-m-d H:i:s'))
            ->orderBy('observed_at')
            ->limit(5000)
            ->get(['value', 'observed_at']);
        if ($rows->count() < 3) {
            return null;
        }
        // Only since the last battery change: a jump up of 15 points or more starts again.
        $points = [];
        $previous = null;
        foreach ($rows as $row) {
            $value = (float) $row->value;
            if ($previous !== null && $value - $previous >= 15) {
                $points = [];
            }
            $points[] = [CarbonImmutable::parse((string) $row->observed_at, 'UTC')->getTimestamp() / 86_400, $value];
            $previous = $value;
        }
        if (count($points) < 3 || end($points)[0] - $points[0][0] < self::FORECAST_MIN_DAYS) {
            return null;
        }
        $n = count($points);
        $sumX = array_sum(array_column($points, 0));
        $sumY = array_sum(array_column($points, 1));
        $sumXY = 0.0;
        $sumXX = 0.0;
        foreach ($points as [$x, $y]) {
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }
        $denominator = $n * $sumXX - $sumX * $sumX;
        if (abs($denominator) < 1e-9) {
            return null;
        }
        $slope = ($n * $sumXY - $sumX * $sumY) / $denominator; // percent per day
        if ($slope >= -0.01) {
            return null;
        }
        $latest = (float) end($points)[1];

        return max(0, (int) floor($latest / -$slope));
    }

    /** Drop readings older than 13 months (scheduled daily). */
    public function prune(?CarbonImmutable $now = null): int
    {
        $cutoff = ($now ?? CarbonImmutable::now('UTC'))->subMonths(self::KEEP_MONTHS)->format('Y-m-d H:i:s');

        return DB::table('security_device_sensor_readings')->where('observed_at', '<', $cutoff)->limit(50_000)->delete();
    }
}
