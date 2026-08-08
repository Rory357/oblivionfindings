<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds a privacy-safe, Site-specific proof of the direct monitoring path.
 *
 * Callers supply their already-authorised Device set and canonical Device to
 * Site map. The report never returns targets, credentials, raw observations,
 * discovery addresses, topology evidence payloads, or global estate counts.
 */
final class CentralSiteMonitoringReadinessService
{
    /** @var list<string> */
    public const REQUIRED_WORKERS = ['orchestration', 'checks', 'events', 'topology'];

    /**
     * @param  Collection<int, Site>  $sites
     * @param  Collection<int, Device>  $devices
     * @param  Collection<int, Monitor>  $monitors
     * @param  Collection<int, Site|int|null>  $sitesByDevice
     * @param  array<string, array<string, mixed>>  $workerStates
     * @return Collection<int, array<string, mixed>>
     */
    public function assess(
        Collection $sites,
        Collection $devices,
        Collection $monitors,
        Collection $sitesByDevice,
        array $workerStates,
    ): Collection {
        if ($sites->isEmpty()) {
            return collect();
        }

        $siteIds = $sites->pluck('id')->map(fn (mixed $id): int => (int) $id)->values();
        $deviceSiteIds = $sitesByDevice->map(fn (mixed $site): ?int => $site instanceof Site
            ? (int) $site->id
            : (is_numeric($site) ? (int) $site : null));
        $directEvidence = $this->directEvidence($monitors, $deviceSiteIds);
        $latestTopology = $this->latestTopology($siteIds);
        $directDiscovery = $this->directDiscovery($siteIds);
        $runtime = $this->runtime($workerStates);

        return $sites
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->map(function (Site $site) use (
                $devices,
                $monitors,
                $deviceSiteIds,
                $directEvidence,
                $latestTopology,
                $directDiscovery,
                $runtime,
            ): array {
                $siteDeviceIds = $devices
                    ->filter(fn (Device $device): bool => $deviceSiteIds->get($device->id) === (int) $site->id)
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values();
                $siteMonitors = $monitors->whereIn('device_id', $siteDeviceIds);
                $operational = $siteMonitors->filter(fn (Monitor $monitor): bool => (bool) $monitor->is_enabled
                    && ($monitor->profile?->is_active ?? true));
                $direct = $operational->whereNull('collector_id')->values();
                $remote = $operational->whereNotNull('collector_id')->values();
                $directDeviceIds = $direct->pluck('device_id')->unique();
                $monitoredDeviceIds = $operational->pluck('device_id')->unique();
                $freshness = $direct->countBy(fn (Monitor $monitor): string => $this->freshness(
                    $monitor,
                    $directEvidence->get($monitor->id),
                ));
                $fresh = (int) ($freshness['fresh'] ?? 0);
                $stale = (int) ($freshness['stale'] ?? 0);
                $never = (int) ($freshness['never_observed'] ?? 0);
                $durableDirectEvidence = $direct->filter(
                    fn (Monitor $monitor): bool => $directEvidence->has($monitor->id),
                )->count();
                $completeDirectEvidence = $direct->isNotEmpty()
                    && $durableDirectEvidence === $direct->count()
                    && $fresh === $direct->count()
                    && $stale === 0
                    && $never === 0;
                $attention = $direct->filter(fn (Monitor $monitor): bool => $this->needsAttention($monitor)
                    || $this->freshness($monitor, $directEvidence->get($monitor->id)) !== 'fresh')->count();
                $latestEvidence = $direct
                    ->map(fn (Monitor $monitor): ?CarbonImmutable => $directEvidence->get($monitor->id))
                    ->filter()
                    ->sortDesc()
                    ->first();
                $oldestEvidence = $direct
                    ->map(fn (Monitor $monitor): ?CarbonImmutable => $directEvidence->get($monitor->id))
                    ->filter()
                    ->sort()
                    ->first();
                $directMonitorFingerprint = hash('sha256', $direct
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->sort(SORT_NUMERIC)
                    ->values()
                    ->implode(','));
                $topology = $this->topology($latestTopology->get($site->id));
                $discovery = $this->discovery($directDiscovery->get($site->id));
                $proofState = $completeDirectEvidence
                    && $runtime['state'] === 'available'
                    && $topology['state'] === 'current'
                    && $discovery['state'] === 'current'
                    ? 'verified'
                    : 'not_verified';
                $state = match (true) {
                    $direct->isEmpty() && $remote->isNotEmpty() => 'remote_only',
                    $direct->isEmpty() => 'not_configured',
                    $runtime['state'] !== 'available' => 'runtime_unavailable',
                    ! $completeDirectEvidence => 'awaiting_evidence',
                    $topology['state'] !== 'current' || $discovery['state'] !== 'current' => 'evidence_incomplete',
                    $attention > 0 => 'attention',
                    default => 'ready',
                };

                return [
                    'site' => [
                        'id' => (int) $site->id,
                        'name' => $site->name,
                        'href' => "/security-devices/sites/{$site->id}",
                    ],
                    'state' => $state,
                    'proof_state' => $proofState,
                    'label' => $this->label($state),
                    'note' => $this->note($state, $attention),
                    'devices' => $siteDeviceIds->count(),
                    'monitored_devices' => $monitoredDeviceIds->count(),
                    'unmonitored_devices' => max(0, $siteDeviceIds->count() - $monitoredDeviceIds->count()),
                    'direct_monitors' => $direct->count(),
                    'direct_devices' => $directDeviceIds->count(),
                    'remote_monitors' => $remote->count(),
                    'disabled_monitors' => $siteMonitors->count() - $operational->count(),
                    'durable_direct_evidence' => $durableDirectEvidence,
                    'fresh' => $fresh,
                    'stale' => $stale,
                    'never_observed' => $never,
                    'attention' => $attention,
                    'evidence_at' => $latestEvidence?->toIso8601String(),
                    'oldest_evidence_at' => $oldestEvidence?->toIso8601String(),
                    'evidence_age_seconds' => $latestEvidence?->diffInSeconds(now()),
                    'direct_monitor_fingerprint' => $directMonitorFingerprint,
                    'runtime' => $runtime,
                    'topology' => $topology,
                    'discovery' => $discovery,
                ];
            });
    }

    /** @param array<string, array<string, mixed>> $workerStates @return array<string, mixed> */
    private function runtime(array $workerStates): array
    {
        $components = collect(self::REQUIRED_WORKERS)->mapWithKeys(function (string $component) use ($workerStates): array {
            $state = data_get($workerStates, "{$component}.worker_state")
                ?? data_get($workerStates, "{$component}.state")
                ?? 'not_observed';

            return [$component => is_string($state) ? $state : 'not_observed'];
        });
        $available = $components->filter(fn (string $state): bool => $state === 'available')->count();
        $state = match (true) {
            $available === $components->count() => 'available',
            $components->every(fn (string $component): bool => $component === 'not_observed') => 'not_observed',
            default => 'unavailable',
        };

        return [
            'state' => $state,
            'available' => $available,
            'required' => $components->count(),
            'components' => $components->all(),
        ];
    }

    /**
     * Return only durable observations produced by the central direct-check
     * runtime. A denormalised monitor timestamp, provider sample, listener
     * event, or collector observation must never prove the no-collector path.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @param  Collection<int, int|null>  $deviceSiteIds
     * @return Collection<int, CarbonImmutable>
     */
    private function directEvidence(Collection $monitors, Collection $deviceSiteIds): Collection
    {
        $direct = $monitors
            ->filter(fn (Monitor $monitor): bool => $monitor->collector_id === null
                && (bool) $monitor->is_enabled
                && ($monitor->profile?->is_active ?? true))
            ->keyBy(fn (Monitor $monitor): int => (int) $monitor->id);
        if ($direct->isEmpty()) {
            return collect();
        }

        return MonitorObservation::query()
            ->whereIn('monitor_id', $direct->keys())
            ->whereNull('collector_id')
            ->whereNotNull('device_id')
            ->whereNotNull('site_id')
            ->where('source_key', 'like', 'runtime:%')
            ->selectRaw('monitor_id, device_id, site_id, MAX(observed_at) AS latest_observed_at')
            ->groupBy('monitor_id', 'device_id', 'site_id')
            ->get()
            ->mapWithKeys(function (MonitorObservation $observation) use ($direct, $deviceSiteIds): array {
                $monitor = $direct->get((int) $observation->monitor_id);
                $expectedSiteId = $monitor === null ? null : $deviceSiteIds->get((int) $monitor->device_id);
                if ($monitor === null
                    || $expectedSiteId === null
                    || (int) $observation->device_id !== (int) $monitor->device_id
                    || (int) $observation->site_id !== (int) $expectedSiteId
                    || ! is_string($observation->latest_observed_at)
                    || $observation->latest_observed_at === '') {
                    return [];
                }

                return [(int) $monitor->id => CarbonImmutable::parse($observation->latest_observed_at)];
            });
    }

    private function freshness(Monitor $monitor, ?CarbonImmutable $observedAt): string
    {
        if ($observedAt === null) {
            return 'never_observed';
        }

        $staleAfter = max(30, (int) ($monitor->profile?->stale_after_seconds ?? 300));

        return $observedAt->lt(now()->subSeconds($staleAfter))
            ? 'stale'
            : 'fresh';
    }

    private function needsAttention(Monitor $monitor): bool
    {
        $state = $monitor->effective_state ?? $monitor->current_state;
        $value = $state instanceof MonitorState ? $state->value : (string) $state;

        return in_array($value, ['degraded', 'failed', 'unknown', 'stale'], true);
    }

    /** @param Collection<int, int> $siteIds @return Collection<int, TopologySnapshot> */
    private function latestTopology(Collection $siteIds): Collection
    {
        $latestIds = TopologySnapshot::query()
            ->selectRaw('MAX(id)')
            ->whereIn('site_id', $siteIds)
            ->where('status', 'completed')
            ->groupBy('site_id');

        return TopologySnapshot::query()
            ->whereIn('id', $latestIds)
            ->get([
                'id', 'site_id', 'source', 'captured_at', 'completed_at',
                'node_count', 'edge_count', 'change_count',
            ])
            ->keyBy('site_id');
    }

    /** @param Collection<int, int> $siteIds @return Collection<int, array<string, mixed>> */
    private function directDiscovery(Collection $siteIds): Collection
    {
        $scopes = DiscoveryScope::query()
            ->whereIn('site_id', $siteIds)
            ->whereNull('collector_id')
            ->where('status', 'active')
            ->get(['id', 'site_id']);
        if ($scopes->isEmpty()) {
            return collect();
        }

        $latestRunIds = DiscoveryRun::query()
            ->selectRaw('MAX(id)')
            ->whereIn('discovery_scope_id', $scopes->pluck('id'))
            ->where('status', 'completed')
            ->groupBy('discovery_scope_id');
        $runsByScope = DiscoveryRun::query()
            ->whereIn('id', $latestRunIds)
            ->get(['id', 'discovery_scope_id', 'completed_at'])
            ->keyBy('discovery_scope_id');

        return $scopes
            ->groupBy('site_id')
            ->map(function (Collection $siteScopes) use ($runsByScope): array {
                $latest = $siteScopes
                    ->map(fn (DiscoveryScope $scope): ?DiscoveryRun => $runsByScope->get($scope->id))
                    ->filter()
                    ->sortByDesc(fn (DiscoveryRun $run) => $run->completed_at)
                    ->first();

                return [
                    'scopes' => $siteScopes->count(),
                    'completed_at' => $latest?->completed_at,
                ];
            });
    }

    /** @return array<string, mixed> */
    private function topology(?TopologySnapshot $snapshot): array
    {
        if ($snapshot === null) {
            return [
                'state' => 'not_observed',
                'source' => null,
                'captured_at' => null,
                'node_count' => 0,
                'edge_count' => 0,
                'change_count' => 0,
            ];
        }

        $staleAfter = max(300, (int) config('monitoring.runtime.site_topology_stale_seconds', 3600));

        return [
            'state' => $snapshot->captured_at?->lt(now()->subSeconds($staleAfter)) ? 'stale' : 'current',
            'source' => $snapshot->source,
            'captured_at' => $snapshot->captured_at?->toIso8601String(),
            'node_count' => (int) $snapshot->node_count,
            'edge_count' => (int) $snapshot->edge_count,
            'change_count' => (int) $snapshot->change_count,
        ];
    }

    /** @param array<string, mixed>|null $evidence @return array<string, mixed> */
    private function discovery(?array $evidence): array
    {
        if ($evidence === null) {
            return ['state' => 'not_configured', 'scopes' => 0, 'completed_at' => null];
        }

        $completedAt = $evidence['completed_at'] ?? null;
        if ($completedAt === null) {
            return ['state' => 'not_observed', 'scopes' => (int) $evidence['scopes'], 'completed_at' => null];
        }

        $staleAfter = max(3600, (int) config('monitoring.runtime.site_discovery_stale_seconds', 86400));

        return [
            'state' => $completedAt->lt(now()->subSeconds($staleAfter)) ? 'stale' : 'current',
            'scopes' => (int) $evidence['scopes'],
            'completed_at' => $completedAt->toIso8601String(),
        ];
    }

    private function label(string $state): string
    {
        return match ($state) {
            'ready' => 'Direct monitoring proven',
            'attention' => 'Direct path proven; findings need attention',
            'runtime_unavailable' => 'Monitoring runtime unavailable',
            'awaiting_evidence' => 'Waiting for direct evidence',
            'evidence_incomplete' => 'Direct monitoring proof incomplete',
            'remote_only' => 'Collector-dependent monitoring',
            default => 'Monitoring is not configured',
        };
    }

    private function note(string $state, int $attention): string
    {
        return match ($state) {
            'ready' => 'The main application has current direct evidence for this Site; no collector is required.',
            'attention' => "The main application path is proven, with {$attention} direct check(s) needing attention.",
            'runtime_unavailable' => 'Direct checks exist, but the required orchestration, checks, events, and topology workers have not all consumed a current heartbeat.',
            'awaiting_evidence' => 'The required workers are available, but one or more configured direct checks do not have a current durable central-runtime observation yet.',
            'evidence_incomplete' => 'The direct check is current, but topology and discovery evidence must also be current before this Site is release-ready.',
            'remote_only' => 'This Site currently depends on a collector. Add at least one direct check to prove the central path over Site connectivity.',
            default => 'No enabled direct or collector-backed checks are configured for visible Devices at this Site.',
        };
    }
}
