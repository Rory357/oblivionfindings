<?php

namespace App\Domain\SecurityDevices\Screens\Devices;

use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A device's last 24 hours, read from its own checks (monitor observations): one mark per hour
 * ("o" answered, "c" failed, "n" not checked) or, for a sensor whose checks carry numbers, the
 * hourly average. Only what was recorded while the device was at its current Site counts, so a
 * device that moved never brings another Site's history with it. No checks means no graph.
 */
final class DeviceLast24Hours
{
    /**
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array<int, array{hours: ?string, spark: ?list<float>, unit: ?string, latency_ms: ?int}>
     */
    public function forEntries(Collection $entries, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $windowStart = $now->subHours(24);

        $byStart = [];
        $monitorDevice = [];
        foreach ($entries as $entry) {
            if ($entry->monitorIds === []) {
                continue;
            }
            $custodyStart = $entry->device->assignments->sortByDesc('assigned_at')->first()?->assigned_at;
            $start = $custodyStart !== null && $custodyStart->greaterThan($windowStart)
                ? CarbonImmutable::parse($custodyStart)
                : $windowStart;
            $byStart[$start->format('Y-m-d H:i:s')][] = $entry;
            foreach ($entry->monitorIds as $monitorId) {
                $monitorDevice[$monitorId] = $entry->id();
            }
        }

        $buckets = [];
        $latency = [];
        foreach ($byStart as $start => $group) {
            $monitorIds = collect($group)->flatMap(fn (DeviceEntry $entry) => $entry->monitorIds)->values()->all();
            foreach (array_chunk($monitorIds, 500) as $chunk) {
                $rows = MonitorObservation::query()
                    ->whereIn('monitor_id', $chunk)
                    ->where('observed_at', '>=', $start)
                    ->where('observed_at', '<', $now)
                    ->selectRaw(
                        'monitor_id, state, FLOOR(TIMESTAMPDIFF(SECOND, ?, observed_at) / 3600) AS bucket, COUNT(*) AS seen, AVG(value) AS average, MAX(unit) AS unit',
                        [$windowStart->format('Y-m-d H:i:s')],
                    )
                    ->groupBy('monitor_id', 'state', 'bucket')
                    ->toBase()
                    ->get();
                foreach ($rows as $row) {
                    $deviceId = $monitorDevice[(int) $row->monitor_id] ?? null;
                    $bucket = (int) $row->bucket;
                    if ($deviceId === null || $bucket < 0 || $bucket > 23) {
                        continue;
                    }
                    $buckets[$deviceId][$bucket][] = $row;
                }

                $latestIds = MonitorObservation::query()
                    ->whereIn('monitor_id', $chunk)
                    ->where('observed_at', '>=', max($start, $now->subHour()->format('Y-m-d H:i:s')))
                    ->whereNotNull('latency_ms')
                    ->groupBy('monitor_id')
                    ->selectRaw('MAX(id) AS id')
                    ->toBase()
                    ->pluck('id');
                if ($latestIds->isNotEmpty()) {
                    MonitorObservation::query()
                        ->whereIn('id', $latestIds)
                        ->toBase()
                        ->get(['monitor_id', 'latency_ms', 'observed_at'])
                        ->each(function (object $row) use ($monitorDevice, &$latency): void {
                            $deviceId = $monitorDevice[(int) $row->monitor_id] ?? null;
                            if ($deviceId === null) {
                                return;
                            }
                            $latency[$deviceId] = min($latency[$deviceId] ?? PHP_INT_MAX, (int) $row->latency_ms);
                        });
                }
            }
        }

        $result = [];
        foreach ($entries as $entry) {
            $id = $entry->id();
            if ($entry->monitorIds === []) {
                $result[$id] = ['hours' => null, 'spark' => null, 'unit' => null, 'latency_ms' => null];

                continue;
            }

            $marks = '';
            $spark = [];
            $unit = null;
            for ($hour = 0; $hour < 24; $hour++) {
                $rows = collect($buckets[$id][$hour] ?? []);
                $failed = $rows->contains(fn (object $row): bool => in_array($row->state, ['failed', 'degraded'], true));
                $healthy = $rows->contains(fn (object $row): bool => $row->state === 'healthy');
                $marks .= $failed ? 'c' : ($healthy ? 'o' : 'n');

                $numbers = $rows->filter(fn (object $row): bool => $row->average !== null);
                $spark[] = $numbers->isEmpty() ? null : round((float) $numbers->avg('average'), 2);
                $unit ??= $numbers->first()?->unit;
            }

            // Behind something that is down: those failed hours were never the device's own fault.
            if ($entry->state === DeviceState::CantCheck) {
                $marks = preg_replace_callback('/c+$/', fn (array $m): string => str_repeat('n', strlen($m[0])), $marks) ?? $marks;
            }

            $sparkValues = array_values(array_filter($spark, fn (?float $value): bool => $value !== null));
            $isSensor = in_array($entry->kind, [DeviceKind::Sensor], true);

            $result[$id] = [
                'hours' => $marks === str_repeat('n', 24) && $isSensor && count($sparkValues) < 2 ? null : $marks,
                'spark' => $isSensor && count($sparkValues) >= 2 ? array_map(fn (?float $value): ?float => $value, $spark) : null,
                'unit' => $isSensor && count($sparkValues) >= 2 ? (is_string($unit) ? $unit : null) : null,
                'latency_ms' => isset($latency[$id]) && $latency[$id] !== PHP_INT_MAX ? $latency[$id] : null,
            ];
        }

        return $result;
    }
}
