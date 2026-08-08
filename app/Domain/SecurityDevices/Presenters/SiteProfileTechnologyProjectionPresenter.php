<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;

final class SiteProfileTechnologyProjectionPresenter
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly EstateOperationsPresenter $estate,
    ) {}

    public function canView(User $viewer, Site $site): bool
    {
        return $viewer->canDo('securityDevices.viewAny')
            && $viewer->canDo('securityDevices.devices.view')
            && $site->is_active
            && ! $site->archived
            && $site->archived_at === null
            && in_array((int) $site->id, $this->access->accessibleSiteIds($viewer), true);
    }

    /** @return array<string, mixed>|null */
    public function present(User $viewer, Site $site): ?array
    {
        if (! $this->canView($viewer, $site)) {
            return null;
        }

        $canViewMonitoring = $viewer->canDo('securityDevices.events.view');
        $canViewMaintenance = $viewer->canDo('securityDevices.maintenance.view')
            || $viewer->canDo('securityDevices.maintenance.manage');
        $source = $this->estate->site($viewer, $site);
        $summary = $source['summary'];
        $devices = collect($source['devices'])
            ->sortBy(fn (array $device): string => sprintf(
                '%02d-%s',
                $this->devicePriority($device),
                strtolower((string) ($device['name'] ?? '')),
            ))
            ->take(8)
            ->map(fn (array $device): array => collect($device)->only([
                'id',
                'name',
                'domain',
                'category',
                'status',
                'health_status',
                'provider',
                'last_seen_at',
                'monitor_count',
                'monitoring_state',
                'href',
            ])->all())
            ->values();
        $monitorIssues = collect($canViewMonitoring ? $source['monitoring']['monitors'] : [])
            ->whereIn('state', ['failed', 'degraded', 'unknown', 'stale', 'pending'])
            ->take(8)
            ->map(fn (array $monitor): array => collect($monitor)->only([
                'id',
                'device_id',
                'device_name',
                'name',
                'kind',
                'state',
                'last_observation_at',
            ])->all())
            ->values();
        $wanConfiguration = $this->wanConfigurationEvidence($site, $source['wan']);

        return [
            'summary' => collect($summary)->only([
                'health',
                'devices',
                'attention_devices',
                'offline_devices',
                'monitored_devices',
                'unmonitored_devices',
                'coverage_percent',
                'failed_monitors',
                'active_findings',
                'active_control_room_alerts',
                'open_it_work',
                'overdue_maintenance',
                'collector',
                'last_change_at',
            ])->all(),
            'wan' => [
                'known' => (bool) $source['wan']['known'],
                'label' => $source['wan']['label'],
                'devices' => collect($source['wan']['devices'])->take(3)->map(
                    fn (array $device): array => collect($device)->only(['id', 'name', 'status', 'health_status', 'href'])->all(),
                )->values(),
                'configuration' => $wanConfiguration,
            ],
            'topology' => collect($source['topology'])->only(['device_count', 'edge_count', 'is_complete'])->all(),
            'monitoring' => [
                'total_devices' => $source['monitoring']['total_devices'],
                'monitored_devices' => $source['monitoring']['monitored_devices'],
                'unmonitored_devices' => $source['monitoring']['unmonitored_devices'],
                'failed_monitors' => $source['monitoring']['failed_monitors'],
                'uncertain_monitors' => $source['monitoring']['uncertain_monitors'],
                'issues' => $monitorIssues,
            ],
            'devices' => $devices,
            'alerts' => collect($source['alerts'])->take(6)->values(),
            'it_work' => collect($source['itWork'])->take(6)->values(),
            'maintenance' => collect($canViewMaintenance ? $source['maintenance'] : [])->take(6)->values(),
            'collectors' => collect($canViewMonitoring ? $source['collectors'] : [])->take(6)->values(),
            'changes' => collect($source['changes'])->take(6)->values(),
            'links' => [
                'full' => "/security-devices/sites/{$site->id}",
                'devices' => "/security-devices/devices?site_id={$site->id}",
                'monitoring' => $canViewMonitoring
                    ? "/security-devices/monitoring?site_id={$site->id}"
                    : null,
                'maintenance' => $canViewMaintenance
                    ? "/security-devices/maintenance?site_id={$site->id}"
                    : null,
            ],
            'can' => [
                'view_control_room' => (bool) $source['can']['view_control_room'],
                'view_it_work' => (bool) $source['can']['view_it_work'],
                'view_monitoring' => $canViewMonitoring,
                'view_maintenance' => $canViewMaintenance,
                'view_room_placement' => $viewer->canDo('siteHardware.view'),
            ],
        ];
    }

    /** @param array<string, mixed> $wan @return array<string, mixed> */
    private function wanConfigurationEvidence(Site $site, array $wan): array
    {
        $deviceIds = collect($wan['devices'] ?? [])
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $total = $deviceIds->count();
        $snapshots = $deviceIds->isEmpty()
            ? collect()
            : ConfigurationSnapshot::query()
                ->where('site_id', $site->id)
                ->whereIn('device_id', $deviceIds)
                ->where('storage_state', 'available')
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->get()
                ->unique('device_id')
                ->values();
        $changed = $snapshots->filter(function (ConfigurationSnapshot $snapshot): bool {
            if ($snapshot->previous_snapshot_id === null) {
                return false;
            }
            $diff = is_array($snapshot->diff_summary) ? $snapshot->diff_summary : [];

            return collect(['added', 'removed', 'changed'])->sum(
                fn (string $key): int => is_array($diff[$key] ?? null) ? count($diff[$key]) : 0,
            ) > 0;
        })->count();
        $observed = $snapshots->count();
        $state = match (true) {
            $changed > 0 => 'warning',
            $total > 0 && $observed === $total => 'healthy',
            default => 'unknown',
        };
        $label = match (true) {
            $total === 0 => 'No WAN or SD-WAN device is classified for configuration evidence.',
            $changed === 1 => 'Configuration changed on 1 WAN device since its previous governed snapshot.',
            $changed > 1 => "Configuration changed on {$changed} WAN devices since their previous governed snapshots.",
            $observed === $total => "Governed configuration evidence is current for all {$total} WAN devices.",
            $observed > 0 => "Governed configuration evidence is available for {$observed} of {$total} WAN devices.",
            default => 'No governed WAN configuration snapshot has been captured.',
        };

        return [
            'state' => $state,
            'label' => $label,
            'observed_devices' => $observed,
            'changed_devices' => $changed,
            'total_devices' => $total,
            'observed_at' => $snapshots->max('captured_at')?->toISOString(),
            'href' => '/security-devices/network-it?tab=configuration-firmware',
        ];
    }

    /** @param array<string, mixed> $device */
    private function devicePriority(array $device): int
    {
        return match (true) {
            in_array($device['health_status'] ?? null, ['critical', 'warning'], true),
            ($device['status'] ?? null) === 'offline',
            ($device['monitoring_state'] ?? null) === 'attention' => 0,
            in_array($device['monitoring_state'] ?? null, ['unmonitored', 'unknown'], true) => 1,
            default => 2,
        };
    }
}
