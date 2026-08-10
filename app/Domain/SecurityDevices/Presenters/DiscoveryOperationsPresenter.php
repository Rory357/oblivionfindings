<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\MonitoringCollectorAvailabilityService;
use App\Domain\Monitoring\Services\NativeMonitoringDefinitionService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DiscoveryOperationsPresenter
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly MonitoringCollectorAvailabilityService $collectorAvailability,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer, mixed $requestedTab = null): array
    {
        $visibleDeviceIds = $this->access->visibleDevices($viewer)->pluck('devices.id');
        $siteIds = $this->access->accessibleSiteIds($viewer);
        $canViewAllSites = $this->access->canViewAllSites($viewer);
        $canManageCollectors = $viewer->canDo('securityDevices.integrations.manage');
        $canManageScopes = $viewer->canDo('securityDevices.integrations.manage');
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
            $canManageCollectors,
        ));
        $direct = $monitors->whereNull('collector_id');
        $remote = $monitors->whereNotNull('collector_id');
        $scopes = DiscoveryScope::query()
            ->when(! $canViewAllSites, fn (Builder $query) => $siteIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('site_id', $siteIds))
            ->with(['site:id,name', 'collector:id,name,status,last_seen_at,last_heartbeat_at,runtime_state,backlog_items,gap_count,revoked_at'])
            ->orderBy('name')
            ->get();
        $runQuery = $scopes->isEmpty()
            ? null
            : DiscoveryRun::query()
                ->whereIn('discovery_scope_id', $scopes->pluck('id'))
                ->with([
                    'scope:id,name,site_id,collector_id',
                    'scope.collector:id,name,status,last_seen_at,last_heartbeat_at,revoked_at',
                ])
                ->withCount([
                    'results as returned_count' => fn (Builder $query): Builder => $query->where('outcome', '!=', 'pending'),
                    'results as pending_count' => fn (Builder $query): Builder => $query->where('outcome', 'pending'),
                ]);
        $runTotal = $runQuery === null ? 0 : (clone $runQuery)->count();
        $runs = $runQuery === null
            ? collect()
            : $runQuery
                ->latest('id')
                ->limit(100)
                ->get();
        $candidateQuery = $scopes->isEmpty()
            ? null
            : DiscoveryCandidate::query()
                ->whereHas('run', fn (Builder $query): Builder => $query
                    ->whereIn('discovery_scope_id', $scopes->pluck('id')))
                ->where(function (Builder $query) use ($visibleDeviceIds): void {
                    $query->whereNull('canonical_device_id');
                    if ($visibleDeviceIds->isNotEmpty()) {
                        $query->orWhereIn('canonical_device_id', $visibleDeviceIds);
                    }
                });
        $candidateTotal = $candidateQuery === null ? 0 : (clone $candidateQuery)->count();
        $candidateReviewTotal = $candidateQuery === null
            ? 0
            : (clone $candidateQuery)->whereIn('decision', ['proposed', 'review'])->count();
        $candidates = $candidateQuery === null
            ? collect()
            : $candidateQuery
                ->with(['run:id,discovery_scope_id,run_uuid,status', 'canonicalDevice:id,name'])
                ->orderByRaw("CASE WHEN decision IN ('proposed', 'review') THEN 0 ELSE 1 END")
                ->latest('id')
                ->limit(200)
                ->get();
        $mappedScopes = $scopes->map(fn (DiscoveryScope $scope): array => [
            'id' => $scope->id,
            'name' => $scope->name,
            'status' => $scope->status,
            'site' => $scope->site ? [
                'id' => $scope->site->id,
                'name' => $scope->site->name,
                'href' => "/security-devices/sites/{$scope->site->id}",
            ] : null,
            'collection_mode' => $scope->collector_id === null ? 'direct' : 'remote_collector',
            'collector' => $scope->collector ? [
                'id' => $scope->collector->id,
                'name' => $scope->collector->name,
                'state' => $this->collectorAvailability->state($scope->collector),
            ] : null,
            'protocols' => collect($scope->protocols ?? [])->filter(fn ($value): bool => is_string($value))->values(),
            'network_ranges' => count($scope->cidrs ?? []),
            'seed_hosts' => count($scope->seed_hosts ?? []),
            'exclusions' => count($scope->exclusions ?? []),
            'port_bounds' => count($scope->port_bounds ?? []),
            'max_targets_per_run' => (int) $scope->max_targets_per_run,
            'packets_per_second' => (int) $scope->packets_per_second,
            'schedule' => $scope->schedule_cron,
            'actions' => [
                'can_manage' => $canManageScopes && $scope->collector_id === null,
                'update_url' => $canManageScopes && $scope->collector_id === null && $scope->status === 'active'
                    ? "/security-devices/discovery/scopes/{$scope->id}"
                    : null,
                'apply_url' => $canManageScopes && $scope->collector_id === null && $scope->status === 'active'
                    ? "/security-devices/discovery/scopes/{$scope->id}/apply"
                    : null,
                'deactivate_url' => $canManageScopes && $scope->collector_id === null && $scope->status === 'active'
                    ? "/security-devices/discovery/scopes/{$scope->id}/deactivate"
                    : null,
            ],
        ])->values();
        $mappedRuns = $runs->map(fn (DiscoveryRun $run): array => [
            'id' => $run->id,
            'run_uuid' => $run->run_uuid,
            'scope_id' => $run->discovery_scope_id,
            'scope_name' => $run->scope?->name,
            'status' => $run->status,
            'collection_mode' => $run->scope?->collector_id === null ? 'central' : 'remote_collector',
            'collector' => $run->scope?->collector ? [
                'id' => $run->scope->collector->id,
                'name' => $run->scope->collector->name,
                'state' => $this->collectorAvailability->state($run->scope->collector),
            ] : null,
            'trigger' => $run->trigger,
            'planned' => $run->planned_targets,
            'returned' => (int) $run->returned_count,
            'pending' => (int) $run->pending_count,
            'found' => $run->found_count,
            'matched' => $run->matched_count,
            'proposed' => $run->proposed_count,
            'changed' => $run->changed_count,
            'excluded' => $run->excluded_count,
            'failed' => $run->failed_count,
            'unresolved' => $run->unresolved_count,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ])->values();
        $mappedCandidates = $candidates->map(fn (DiscoveryCandidate $candidate): array => [
            'id' => $candidate->id,
            'candidate_uuid' => $candidate->candidate_uuid,
            'run_id' => $candidate->discovery_run_id,
            'scope_id' => $candidate->run?->discovery_scope_id,
            'decision' => $candidate->decision,
            'confidence' => $candidate->confidence,
            'reasons' => collect($candidate->reasons ?? [])
                ->filter(fn ($reason): bool => is_string($reason))
                ->take(10)
                ->values(),
            'canonical_device' => $candidate->canonicalDevice ? [
                'id' => $candidate->canonicalDevice->id,
                'name' => $candidate->canonicalDevice->name,
                'href' => "/security-devices/devices/{$candidate->canonicalDevice->id}",
            ] : null,
            'review' => [
                'action' => $candidate->review_action,
                'reviewed_at' => $candidate->reviewed_at?->toIso8601String(),
            ],
        ])->values();

        return [
            'tabs' => [
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'scopes', 'label' => 'Discovery scopes'],
                ['key' => 'runs', 'label' => 'Runs'],
                ['key' => 'candidates', 'label' => 'Candidates'],
                ['key' => 'collectors', 'label' => 'Remote collectors'],
                ['key' => 'paths', 'label' => 'Coverage & paths'],
                ['key' => 'limitations', 'label' => 'Limitations'],
            ],
            'active_tab' => in_array($requestedTab, ['overview', 'scopes', 'runs', 'candidates', 'collectors', 'paths', 'limitations'], true)
                ? $requestedTab
                : 'overview',
            'boundary' => [
                'title' => 'Direct first, collectors only where needed',
                'description' => 'Oblivion Findings monitors SD-WAN reachable sites from the main application. A collector is an explicit remote collection path, not a requirement for every monitor.',
                'runtime_note' => 'Governed scopes, immutable runs, candidate reasons, collector state, and canonical Device links are shown from the native runtime.',
            ],
            'summary' => [
                'monitors' => $monitors->count(),
                'direct_monitors' => $direct->count(),
                'remote_monitors' => $remote->count(),
                'collectors' => $collectors->count(),
                'online_collectors' => $mappedCollectors->where('freshness_state', 'available')->count(),
                'collection_paths_unavailable' => $mappedCollectors->where('freshness_state', '!=', 'available')->count(),
                'affected_devices' => $mappedCollectors->where('freshness_state', '!=', 'available')->sum('affected_devices'),
                'scopes' => $mappedScopes->count(),
                'runs' => $runTotal,
                'runs_shown' => $mappedRuns->count(),
                'runs_truncated' => $runTotal > $mappedRuns->count(),
                'candidates' => $candidateTotal,
                'candidates_shown' => $mappedCandidates->count(),
                'candidates_truncated' => $candidateTotal > $mappedCandidates->count(),
                'candidates_requiring_review' => $candidateReviewTotal,
            ],
            'direct_coverage' => [
                'path_label' => 'Collector-free monitor configuration',
                'monitors' => $direct->count(),
                'devices' => $direct->pluck('device_id')->unique()->count(),
                'description' => 'These checks are configured without a Site collector. Live Site reachability and durable central-runtime proof remain in Monitoring > Data collection.',
            ],
            'collectors' => $mappedCollectors->values(),
            'collector_management' => [
                'can_manage' => $canManageCollectors,
                'issue_url' => $canManageCollectors
                    ? '/security-devices/discovery/collectors/enrolments'
                    : null,
                'sites' => $canManageCollectors && $siteIds !== []
                    ? Site::query()
                        ->whereIn('id', $siteIds)
                        ->orderBy('name')
                        ->get(['id', 'name'])
                        ->map(fn (Site $site): array => [
                            'id' => (int) $site->id,
                            'name' => $site->name,
                        ])
                        ->values()
                        ->all()
                    : [],
            ],
            'scopes' => $mappedScopes,
            'scope_management' => [
                'can_manage' => $canManageScopes,
                'create_url' => $canManageScopes
                    ? '/security-devices/discovery/scopes'
                    : null,
                'protocols' => $canManageScopes
                    ? NativeMonitoringDefinitionService::discoveryProtocols()
                    : [],
                'sites' => $canManageScopes && $siteIds !== []
                    ? Site::query()
                        ->whereIn('id', $siteIds)
                        ->where('is_active', true)
                        ->where('archived', false)
                        ->whereNull('archived_at')
                        ->orderBy('name')
                        ->get(['id', 'name'])
                        ->map(fn (Site $site): array => [
                            'id' => (int) $site->id,
                            'name' => $site->name,
                        ])
                        ->values()
                        ->all()
                    : [],
            ],
            'runs' => $mappedRuns,
            'candidates' => $mappedCandidates,
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
                'not_configured_note' => 'Devices without monitors are reported in Monitoring coverage. This page only explains active collection paths.',
                'capacity_note' => 'Monitor, Device, backlog, and gap counts are exact. Capacity percentages are shown only when measured capacity evidence exists.',
            ],
        ];
    }

    /** @param Collection<int, Monitor> $monitors @return array<string, mixed> */
    private function mapCollector(
        MonitoringCollector $collector,
        Collection $monitors,
        bool $canManage,
    ): array {
        $state = $this->collectorAvailability->state($collector);
        $lastHeartbeat = $this->collectorAvailability->lastHeartbeat($collector);

        return [
            'id' => $collector->id,
            'name' => $collector->name,
            'site' => $collector->site ? [
                'id' => $collector->site->id,
                'name' => $collector->site->name,
                'href' => "/security-devices/sites/{$collector->site->id}",
            ] : null,
            'reported_status' => $collector->status,
            'freshness_state' => $state,
            'last_seen_at' => $lastHeartbeat?->toIso8601String(),
            'heartbeat_lag_seconds' => $lastHeartbeat?->diffInSeconds(now()),
            'runtime_state' => $collector->runtime_state,
            'backlog_items' => (int) $collector->backlog_items,
            'spool_bytes' => (int) $collector->spool_bytes,
            'gap_count' => (int) $collector->gap_count,
            'corrupted_frames' => (int) $collector->corrupted_frames,
            'clock_drift_seconds' => $collector->last_clock_drift_seconds === null ? null : (int) $collector->last_clock_drift_seconds,
            'revoked_at' => $collector->revoked_at?->toIso8601String(),
            'monitor_load' => $monitors->count(),
            'device_load' => $monitors->pluck('device_id')->unique()->count(),
            'affected_monitors' => $state === MonitoringCollectorAvailabilityService::AVAILABLE ? 0 : $monitors->count(),
            'affected_devices' => $state === MonitoringCollectorAvailabilityService::AVAILABLE ? 0 : $monitors->pluck('device_id')->unique()->count(),
            'impact_note' => $state === MonitoringCollectorAvailabilityService::AVAILABLE
                ? 'Collection path is reporting within the configured heartbeat window.'
                : 'Downstream monitor results are uncertain until this collection path reports again.',
            'actions' => [
                'can_manage' => $canManage,
                'revoke_url' => $canManage && $collector->revoked_at === null
                    ? "/security-devices/discovery/collectors/{$collector->id}/revoke"
                    : null,
                're_enrol_url' => $canManage && $collector->revoked_at !== null
                    ? "/security-devices/discovery/collectors/{$collector->id}/re-enrolment"
                    : null,
            ],
        ];
    }
}
