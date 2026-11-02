<?php

namespace App\Domain\SecurityDevices\Screens\Sites;

use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Network over the last 7 days" on a Site page: for each NZ day, the share of the Site's network
 * gear checks that answered. Only checks recorded while a device was at this Site count, so a
 * device that moved never brings another Site's history. Days with no checks stay empty.
 */
final class SiteNetworkWeek
{
    private const array NETWORK_KINDS = [DeviceKind::Router, DeviceKind::Switch, DeviceKind::WifiPoint, DeviceKind::NetworkDevice];

    /**
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array{values: list<float|null>, labels: list<string>, note: string}|null null when no network gear is watched
     */
    public function forEntries(Collection $entries, ?CarbonImmutable $now = null): ?array
    {
        $network = $entries->filter(fn (DeviceEntry $entry): bool => in_array($entry->kind, self::NETWORK_KINDS, true)
            && $entry->monitorIds !== [])->values();
        if ($network->isEmpty()) {
            return null;
        }
        $now ??= CarbonImmutable::now();
        $todayNz = $now->setTimezone('Pacific/Auckland')->startOfDay();
        $firstDayNz = $todayNz->subDays(6);
        $windowStart = $firstDayNz->utc();

        $dayIndex = [];
        for ($day = 0; $day < 7; $day++) {
            $dayIndex[$firstDayNz->addDays($day)->format('Y-m-d')] = $day;
        }
        // Counted in the database, by NZ date (today's offset; a daylight-saving change shifts at most an hour).
        $offset = $now->setTimezone('Pacific/Auckland')->getOffset();
        $up = array_fill(0, 7, 0);
        $answered = array_fill(0, 7, 0);
        foreach ($network as $entry) {
            $arrived = $entry->device->assignments->sortByDesc('assigned_at')->first()?->assigned_at;
            $start = $arrived !== null && CarbonImmutable::parse($arrived)->greaterThan($windowStart)
                ? CarbonImmutable::parse($arrived)
                : $windowStart;
            $rows = MonitorObservation::query()
                ->whereIn('monitor_id', $entry->monitorIds)
                ->where('observed_at', '>=', $start)
                ->where('observed_at', '<', $now)
                ->whereIn('state', ['healthy', 'degraded', 'failed'])
                ->selectRaw('DATE(DATE_ADD(observed_at, INTERVAL ? SECOND)) AS nz_day, state, COUNT(*) AS seen', [$offset])
                ->groupBy('nz_day', 'state')
                ->toBase()
                ->get();
            foreach ($rows as $row) {
                $day = $dayIndex[(string) $row->nz_day] ?? null;
                if ($day === null) {
                    continue;
                }
                $answered[$day] += (int) $row->seen;
                if ($row->state === 'healthy') {
                    $up[$day] += (int) $row->seen;
                }
            }
        }

        $values = [];
        $labels = [];
        for ($day = 0; $day < 7; $day++) {
            $labels[] = $firstDayNz->addDays($day)->format('D');
            $values[] = $answered[$day] === 0 ? null : round($up[$day] / $answered[$day] * 100, 1);
        }

        return ['values' => $values, 'labels' => $labels, 'note' => $this->note($values, $labels)];
    }

    /**
     * One sentence on what changed this week.
     *
     * @param  list<float|null>  $values
     * @param  list<string>  $labels
     */
    private function note(array $values, array $labels): string
    {
        $known = array_filter($values, fn (?float $value): bool => $value !== null);
        if ($known === []) {
            return 'No checks recorded this week yet.';
        }
        $lowest = min($known);
        if ($lowest >= 99.5) {
            return 'The network answered every check this week.';
        }
        $day = array_search($lowest, $values, true);

        return 'Lowest on '.$labels[$day].', when '.rtrim(rtrim(number_format($lowest, 1), '0'), '.').'% of checks answered.';
    }
}
