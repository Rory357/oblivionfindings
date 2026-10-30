<?php

namespace App\Services\Overview;

use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Recorded probe results, never inferred uptime or an expected-check guarantee. */
final class OverviewConnectionHistory
{
    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /** @return array<int, list<array{at: string, state: string, checks: int}>> */
    public function read(User $user, Collection $devices, array $siteIds): array
    {
        if (! $user->canDo('securityDevices.events.view')) {
            return [];
        }
        $now = CarbonImmutable::now('UTC');
        $start = $now->startOfHour()->subHours(23);
        $query = MonitorObservation::query()->whereIn('monitor_observations.device_id', $devices->pluck('id'))
            ->whereIn('monitor_observations.site_id', $siteIds)
            ->where('observed_at', '>=', $start)->where('observed_at', '<=', $now)
            ->whereHas('monitor', fn ($monitor) => $monitor->whereColumn('monitors.device_id', 'monitor_observations.device_id')
                ->where('is_enabled', true)->where('affects_availability', true)->whereIn('kind', ['icmp', 'tcp', 'dns', 'http']));
        $this->access->applyTemporalEventCustodyScope($query, $user, 'monitor_observations.device_id', 'monitor_observations.observed_at');
        $hour = $query->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d %H:00:00', monitor_observations.observed_at)"
            : "DATE_FORMAT(monitor_observations.observed_at, '%Y-%m-%d %H:00:00')";
        $results = $query->select('monitor_observations.site_id', 'state')->selectRaw($hour.' AS observed_hour, COUNT(*) AS checks')
            ->groupBy('monitor_observations.site_id', 'state')->groupByRaw($hour)->get()->groupBy('site_id');

        return collect($siteIds)->mapWithKeys(function ($siteId) use ($start, $results) {
            $byHour = $results->get($siteId, collect())->groupBy('observed_hour');
            $hours = collect(range(0, 23))->map(function ($offset) use ($start, $byHour) {
                $at = $start->addHours($offset);
                $checks = $byHour->get($at->format('Y-m-d H:00:00'), collect());
                $states = $checks->map(fn ($row) => $row->state->value);
                $state = match (true) {
                    $states->contains('failed') => 'failed',
                    $states->contains('degraded') => 'degraded',
                    $states->isEmpty() || $states->contains(fn ($state) => $state !== 'healthy') => 'unknown',
                    default => 'healthy',
                };

                return ['at' => $at->toIso8601String(), 'state' => $state, 'checks' => (int) $checks->sum('checks')];
            })->all();

            return [$siteId => $hours];
        })->all();
    }
}
