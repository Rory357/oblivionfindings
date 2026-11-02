<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\Device;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What a device's own checks recorded (monitor observations), for its page: reply time over a
 * range, how often it answered, its last reply, uptime and named readings. Only what was recorded
 * while the device was at its current Site counts, so a moved device never brings another Site's
 * history. Nothing recorded means null ("Not available"), never 0.
 */
final class DeviceObservations
{
    /** Range key => [seconds covered, seconds per point]. */
    public const array RANGES = [
        '24h' => [86_400, 3_600],
        '7d' => [604_800, 21_600],
        '30d' => [2_592_000, 86_400],
    ];

    /** Named readings a check can carry (SNMP sensor names), matched by these words. */
    public const array READINGS = [
        'toner' => ['toner', 'cartridge', 'ink'],
        'pages' => ['page count', 'pages printed', 'impressions'],
        'paper' => ['paper', 'tray'],
        'runtime' => ['runtime', 'time remaining', 'time on battery'],
        'load' => ['output load', 'load'],
        'battery' => ['battery capacity', 'battery charge', 'charge remaining'],
    ];

    /** The start of the device's time at its current Site, or null when it has no Site. */
    public static function custodyStart(Device $device): ?CarbonImmutable
    {
        $assignment = $device->relationLoaded('assignments')
            ? $device->assignments->whereNull('released_at')->sortByDesc('assigned_at')->first()
            : $device->assignments()->whereNull('released_at')->latest('assigned_at')->first();

        return $assignment?->assigned_at === null ? null : CarbonImmutable::parse($assignment->assigned_at);
    }

    private static function windowStart(?CarbonImmutable $custody, CarbonImmutable $from): CarbonImmutable
    {
        return $custody !== null && $custody->greaterThan($from) ? $custody : $from;
    }

    /**
     * The checks that say whether it answers: availability checks, else pings.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @return Collection<int, Monitor>
     */
    public static function availabilityMonitors(Collection $monitors): Collection
    {
        $availability = $monitors->filter(fn (Monitor $monitor): bool => (bool) $monitor->affects_availability)->values();

        return $availability->isNotEmpty()
            ? $availability
            : $monitors->filter(fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::Icmp)->values();
    }

    /**
     * Average reply time per point over the range, oldest first. Null when nothing was recorded.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @return array{range: string, step_seconds: int, start: string, points: list<?float>, unit: string}|null
     */
    public function replyTimes(Collection $monitors, ?CarbonImmutable $custody, string $range, CarbonImmutable $now): ?array
    {
        $ids = self::availabilityMonitors($monitors)->pluck('id')->all();

        return $this->series($ids, $custody, $range, $now, 'latency_ms', 'ms');
    }

    /**
     * One check's numbers over the range (a sensor reading such as toner or load).
     *
     * @return array{range: string, step_seconds: int, start: string, points: list<?float>, unit: string}|null
     */
    public function values(int $monitorId, ?string $unit, ?CarbonImmutable $custody, string $range, CarbonImmutable $now): ?array
    {
        return $this->series([$monitorId], $custody, $range, $now, 'value', $unit ?? '');
    }

    /**
     * @param  list<int|string>  $monitorIds
     * @return array{range: string, step_seconds: int, start: string, points: list<?float>, unit: string}|null
     */
    private function series(array $monitorIds, ?CarbonImmutable $custody, string $range, CarbonImmutable $now, string $column, string $unit): ?array
    {
        if ($monitorIds === []) {
            return null;
        }
        [$span, $step] = self::RANGES[$range] ?? self::RANGES['24h'];
        $count = intdiv($span, $step);
        $start = $now->subSeconds($span);
        $from = self::windowStart($custody, $start);

        $rows = MonitorObservation::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where('observed_at', '>=', $from)
            ->where('observed_at', '<', $now)
            ->whereNotNull($column)
            ->selectRaw(
                "FLOOR(TIMESTAMPDIFF(SECOND, ?, observed_at) / ?) AS bucket, AVG({$column}) AS average",
                [$start->format('Y-m-d H:i:s'), $step],
            )
            ->groupBy('bucket')
            ->toBase()
            ->get();

        $points = array_fill(0, $count, null);
        foreach ($rows as $row) {
            $bucket = (int) $row->bucket;
            if ($bucket >= 0 && $bucket < $count && $row->average !== null) {
                $points[$bucket] = round((float) $row->average, 2);
            }
        }

        if (count(array_filter($points, fn (?float $point): bool => $point !== null)) === 0) {
            return null;
        }

        return [
            'range' => array_key_exists($range, self::RANGES) ? $range : '24h',
            'step_seconds' => $step,
            'start' => $start->toIso8601String(),
            'points' => $points,
            'unit' => $unit,
        ];
    }

    /**
     * How often it answered its checks in the last 30 days. Null when nothing was recorded.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @return array{answered: int, missed: int, percent: float, last_missed_at: ?string, days: int}|null
     */
    public function availability(Collection $monitors, ?CarbonImmutable $custody, CarbonImmutable $now): ?array
    {
        $ids = self::availabilityMonitors($monitors)->pluck('id')->all();
        if ($ids === []) {
            return null;
        }
        $from = self::windowStart($custody, $now->subDays(30));

        $counts = MonitorObservation::query()
            ->whereIn('monitor_id', $ids)
            ->where('observed_at', '>=', $from)
            ->where('observed_at', '<', $now)
            ->whereIn('state', ['healthy', 'degraded', 'failed'])
            ->selectRaw("SUM(CASE WHEN state = 'failed' THEN 0 ELSE 1 END) AS answered, SUM(CASE WHEN state = 'failed' THEN 1 ELSE 0 END) AS missed, MAX(CASE WHEN state = 'failed' THEN observed_at END) AS last_missed")
            ->toBase()
            ->first();

        $answered = (int) ($counts->answered ?? 0);
        $missed = (int) ($counts->missed ?? 0);
        if ($answered + $missed === 0) {
            return null;
        }

        return [
            'answered' => $answered,
            'missed' => $missed,
            'percent' => round($answered / ($answered + $missed) * 100, 1),
            'last_missed_at' => $counts->last_missed ? CarbonImmutable::parse($counts->last_missed, 'UTC')->toIso8601String() : null,
            'days' => max(1, (int) ceil($from->diffInSeconds($now) / 86_400)),
        ];
    }

    /**
     * The last time it answered, with its reply time.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @return array{at: string, latency_ms: ?int}|null
     */
    public function lastReply(Collection $monitors, ?CarbonImmutable $custody): ?array
    {
        $ids = self::availabilityMonitors($monitors)->pluck('id')->all();
        if ($ids === []) {
            return null;
        }
        $row = MonitorObservation::query()
            ->whereIn('monitor_id', $ids)
            ->whereIn('state', ['healthy', 'degraded'])
            ->when($custody !== null, fn ($query) => $query->where('observed_at', '>=', $custody))
            ->latest('observed_at')
            ->first(['observed_at', 'latency_ms']);

        return $row === null ? null : [
            'at' => CarbonImmutable::parse($row->observed_at)->toIso8601String(),
            'latency_ms' => $row->latency_ms === null ? null : (int) $row->latency_ms,
        ];
    }

    /**
     * Missed checks since a time (a device that's down).
     *
     * @param  Collection<int, Monitor>  $monitors
     * @return array{count: int, first_at: ?string, last_at: ?string}
     */
    public function missedSince(Collection $monitors, CarbonInterface $since): array
    {
        $ids = self::availabilityMonitors($monitors)->pluck('id')->all();
        if ($ids === []) {
            return ['count' => 0, 'first_at' => null, 'last_at' => null];
        }
        $row = MonitorObservation::query()
            ->whereIn('monitor_id', $ids)
            ->where('state', 'failed')
            ->where('observed_at', '>=', $since)
            ->selectRaw('COUNT(*) AS seen, MIN(observed_at) AS first_at, MAX(observed_at) AS last_at')
            ->toBase()
            ->first();

        return [
            'count' => (int) ($row->seen ?? 0),
            'first_at' => $row?->first_at ? CarbonImmutable::parse($row->first_at, 'UTC')->toIso8601String() : null,
            'last_at' => $row?->last_at ? CarbonImmutable::parse($row->last_at, 'UTC')->toIso8601String() : null,
        ];
    }

    /**
     * The newest observation of each check.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @return Collection<int, MonitorObservation>
     */
    public function latest(Collection $monitors, ?CarbonImmutable $custody): Collection
    {
        $ids = $monitors->pluck('id')->filter()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $latestIds = MonitorObservation::query()
            ->whereIn('monitor_id', $ids)
            ->when($custody !== null, fn ($query) => $query->where('observed_at', '>=', $custody))
            ->selectRaw('MAX(id)')
            ->groupBy('monitor_id');

        return MonitorObservation::query()->whereIn('id', $latestIds)->get()->keyBy('monitor_id');
    }

    /**
     * How long it has been up, from the newest check that reported it.
     *
     * @param  Collection<int, MonitorObservation>  $latest
     * @return array{seconds: int, since: string, read_at: string}|null
     */
    public function uptime(Collection $latest): ?array
    {
        $best = null;
        foreach ($latest as $observation) {
            $seconds = data_get($observation->metrics, 'uptime_seconds');
            if (! is_numeric($seconds) || (float) $seconds <= 0 || $observation->observed_at === null) {
                continue;
            }
            if ($best === null || $observation->observed_at->greaterThan($best[1])) {
                $best = [(int) $seconds, $observation->observed_at];
            }
        }
        if ($best === null) {
            return null;
        }
        $readAt = CarbonImmutable::parse($best[1]);

        return [
            'seconds' => $best[0],
            'since' => $readAt->subSeconds($best[0])->toIso8601String(),
            'read_at' => $readAt->toIso8601String(),
        ];
    }

    /**
     * Ports up out of the ports Oblivion reads (SNMP port checks).
     *
     * @param  Collection<int, Monitor>  $monitors
     * @param  Collection<int, MonitorObservation>  $latest
     * @return array{up: int, total: int, read_at: ?string}|null
     */
    public function ports(Collection $monitors, Collection $latest): ?array
    {
        $ports = $monitors->filter(fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::SnmpInterface)->values();
        if ($ports->isEmpty()) {
            return null;
        }
        $up = 0;
        $readAt = null;
        foreach ($ports as $port) {
            $observation = $latest->get($port->id);
            $status = strtolower((string) (data_get($observation?->metrics, 'operational_status') ?? data_get($observation?->metrics, 'oper_status') ?? ''));
            if ($status === 'up' || ($status === '' && $observation?->state?->value === 'healthy')) {
                $up++;
            }
            if ($observation?->observed_at !== null && ($readAt === null || $observation->observed_at->greaterThan($readAt))) {
                $readAt = $observation->observed_at;
            }
        }

        return ['up' => $up, 'total' => $ports->count(), 'read_at' => $readAt === null ? null : CarbonImmutable::parse($readAt)->toIso8601String()];
    }

    /**
     * A named reading (toner, load, runtime…) from the check that reports it, if any does.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @param  Collection<int, MonitorObservation>  $latest
     * @return array{monitor_id: int, value: float, unit: ?string, at: string}|null
     */
    public function reading(string $key, Collection $monitors, Collection $latest): ?array
    {
        $words = self::READINGS[$key] ?? [];
        foreach ($monitors as $monitor) {
            if (! in_array($monitor->kind, [MonitorKind::Snmp, MonitorKind::Provider], true)) {
                continue;
            }
            $observation = $latest->get($monitor->id);
            if ($observation === null || $observation->value === null || $observation->observed_at === null) {
                continue;
            }
            $name = mb_strtolower(trim(implode(' ', array_filter([
                (string) data_get($observation->metrics, 'sensor_name', ''),
                (string) data_get($observation->metrics, 'sensor_type', ''),
                (string) $monitor->name,
            ]))));
            foreach ($words as $word) {
                if ($name !== '' && str_contains($name, $word)) {
                    return [
                        'monitor_id' => (int) $monitor->id,
                        'value' => round((float) $observation->value, 2),
                        'unit' => is_string($observation->unit) && $observation->unit !== '' ? mb_substr($observation->unit, 0, 12) : null,
                        'at' => CarbonImmutable::parse($observation->observed_at)->toIso8601String(),
                    ];
                }
            }
        }

        return null;
    }
}
