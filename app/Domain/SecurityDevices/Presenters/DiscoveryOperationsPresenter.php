<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DiscoveryOperationsPresenter
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer, mixed $requestedTab = null): array
    {
        $visibleDeviceIds = $this->access->visibleDevices($viewer)->pluck('devices.id');
        $monitors = $visibleDeviceIds->isEmpty()
            ? collect()
            : Monitor::query()
                ->whereIn('device_id', $visibleDeviceIds)
                ->with(['device:id,name', 'collector:id,name,site_id,status,last_seen_at', 'collector.site:id,name'])
                ->get();
        $collectorIds = $monitors->pluck('collector_id')->filter()->unique();
        $collectors = MonitoringCollector::query()
            ->with('site:id,name')
            ->when(! $this->access->canViewAllSites($viewer), function (Builder $query) use ($viewer, $collectorIds): void {
                $siteIds = $this->access->accessibleSiteIds($viewer);
                $query->where(function (Builder $visibility) use ($siteIds, $collectorIds): void {
                    if ($siteIds !== []) {
                        $visibility->whereIn('site_id', $siteIds);
                    } else {
                        $visibility->whereRaw('1 = 0');
                    }

                    if ($collectorIds->isNotEmpty()) {
                        $visibility->orWhereIn('id', $collectorIds);
                    }
                });
            })
            ->orderBy('name')
            ->get();
        $mappedCollectors = $collectors->map(fn (MonitoringCollector $collector): array => $this->mapCollector(
            $collector,
            $monitors->where('collector_id', $collector->id),
        ));
        $direct = $monitors->whereNull('collector_id');
        $remote = $monitors->whereNotNull('collector_id');

        return [
            'tabs' => [
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'collectors', 'label' => 'Remote collectors'],
                ['key' => 'paths', 'label' => 'Coverage & paths'],
                ['key' => 'limitations', 'label' => 'Limitations'],
            ],
            'active_tab' => in_array($requestedTab, ['overview', 'collectors', 'paths', 'limitations'], true)
                ? $requestedTab
                : 'overview',
            'boundary' => [
                'title' => 'Direct first, collectors only where needed',
                'description' => 'Oblivion Findings monitors SD-WAN reachable sites from the main application. A collector is an explicit remote collection path, not a requirement for every monitor.',
                'runtime_note' => 'Discovery runs, candidates, adoption, credential handling, and protocol execution are enabled only by the governed runtime plan.',
            ],
            'summary' => [
                'monitors' => $monitors->count(),
                'direct_monitors' => $direct->count(),
                'remote_monitors' => $remote->count(),
                'collectors' => $collectors->count(),
                'online_collectors' => $mappedCollectors->where('freshness_state', 'available')->count(),
                'collection_paths_unavailable' => $mappedCollectors->where('freshness_state', 'unavailable')->count(),
                'affected_devices' => $mappedCollectors->where('freshness_state', 'unavailable')->sum('affected_devices'),
            ],
            'direct_coverage' => [
                'path_label' => 'Main application over site connectivity',
                'monitors' => $direct->count(),
                'devices' => $direct->pluck('device_id')->unique()->count(),
                'description' => 'These checks run without a site collector and are correctly configured as direct coverage.',
            ],
            'collectors' => $mappedCollectors->values(),
            'collection_paths' => $mappedCollectors->map(fn (array $collector): array => [
                'collector_id' => $collector['id'],
                'collector_name' => $collector['name'],
                'site' => $collector['site'],
                'state' => $collector['freshness_state'],
                'monitor_load' => $collector['monitor_load'],
                'device_load' => $collector['device_load'],
                'affected_devices' => $collector['affected_devices'],
            ])->values(),
            'limitations' => [
                'unsupported_state' => 'not_assessed',
                'unsupported_note' => 'There is no canonical device capability record yet, so unsupported protocols are not guessed from vendor or model names.',
                'not_configured_monitors' => 0,
                'not_configured_note' => 'Devices without monitors are reported in Monitoring coverage. This page only explains active collection paths.',
                'capacity_note' => 'Monitor and device load are exact counts. Capacity percentages are not shown because no canonical collector capacity limit exists.',
            ],
        ];
    }

    /** @param Collection<int, Monitor> $monitors @return array<string, mixed> */
    private function mapCollector(MonitoringCollector $collector, Collection $monitors): array
    {
        $available = $collector->status === 'online'
            && $collector->last_seen_at
            && $collector->last_seen_at->gte(now()->subMinutes(5));

        return [
            'id' => $collector->id,
            'name' => $collector->name,
            'site' => $collector->site ? [
                'id' => $collector->site->id,
                'name' => $collector->site->name,
                'href' => "/security-devices/sites/{$collector->site->id}",
            ] : null,
            'reported_status' => $collector->status,
            'freshness_state' => $available ? 'available' : 'unavailable',
            'last_seen_at' => $collector->last_seen_at?->toIso8601String(),
            'heartbeat_lag_seconds' => $collector->last_seen_at?->diffInSeconds(now()),
            'monitor_load' => $monitors->count(),
            'device_load' => $monitors->pluck('device_id')->unique()->count(),
            'affected_monitors' => $available ? 0 : $monitors->count(),
            'affected_devices' => $available ? 0 : $monitors->pluck('device_id')->unique()->count(),
            'impact_note' => $available
                ? 'Collection path is reporting within five minutes.'
                : 'Downstream monitor results are uncertain until this collection path reports again.',
        ];
    }
}
