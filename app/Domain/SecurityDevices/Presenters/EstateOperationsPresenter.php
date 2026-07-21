<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\SiteContact;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EstateOperationsPresenter
{
    private const ACTIVE_EVENT_WINDOW_HOURS = 24;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly ItWorkAccessService $itAccess,
    ) {}

    /** @return array<string, mixed> */
    public function estate(User $viewer): array
    {
        $context = $this->context($viewer);
        $devices = $context['devices'];
        $activeEvents = $context['activeEvents'];
        $maintenance = $context['maintenance'];
        $deviceIds = $devices->pluck('id')->all();

        $stats = [
            'totalDevices' => $devices->count(),
            'active' => $devices->where('status.value', DeviceStatus::Active->value)->count(),
            'offline' => $devices->where('status.value', DeviceStatus::Offline->value)->count(),
            'degraded' => $devices->where('status.value', DeviceStatus::Degraded->value)->count(),
            'lowBattery' => $devices->filter(fn (Device $device) => $device->battery_level !== null && $device->battery_level <= 20)->count(),
            'overdueMaintenance' => $maintenance->filter(fn (DeviceMaintenanceRecord $record) => $this->isOverdue($record))->count(),
            'serviceDueOverdue' => $devices->filter(fn (Device $device) => $device->next_service_due?->isPast())->count(),
            'serviceDueIn30d' => $devices->filter(fn (Device $device) => $device->next_service_due?->betweenIncluded(now(), now()->addDays(30)))->count(),
            'criticalEvents24h' => $activeEvents->where('severity', 'critical')->count(),
            'warningEvents24h' => $activeEvents->where('severity', 'warning')->count(),
        ];

        $domainSummary = collect(DeviceDomain::cases())->map(function (DeviceDomain $domain) use ($devices): array {
            return [
                'domain' => $domain->value,
                'label' => $domain->label(),
                'count' => $devices->filter(fn (Device $device) => $device->domain === $domain->value)->count(),
            ];
        });

        $healthSummary = collect(HealthStatus::cases())->map(function (HealthStatus $status) use ($devices): array {
            return [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => $devices->filter(fn (Device $device) => $device->health_status?->value === $status->value)->count(),
            ];
        });

        $attentionDevices = $devices
            ->filter(fn (Device $device) => $this->deviceNeedsAttention($device))
            ->sortBy(fn (Device $device) => match ($device->health_status?->value) {
                HealthStatus::Critical->value => 0,
                HealthStatus::Warning->value => 1,
                default => 2,
            })
            ->take(10)
            ->map(fn (Device $device) => [
                'id' => $device->id,
                'device_uid' => $device->device_uid,
                'name' => $device->name,
                'domain' => $device->domain,
                'category' => $device->category,
                'status' => $device->status?->value,
                'health_status' => $device->health_status?->value,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'battery_level' => $device->battery_level,
            ])
            ->values();

        $recentEvents = $activeEvents
            ->sortByDesc('occurred_at')
            ->take(10)
            ->map(fn (DeviceEvent $event) => [
                'id' => $event->id,
                'device_id' => $event->device_id,
                'device_name' => $event->device?->name,
                'event_type' => $event->event_type,
                'severity' => $event->severity,
                'source' => $event->source,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])
            ->values();

        $overdueMaintenance = $maintenance
            ->filter(fn (DeviceMaintenanceRecord $record) => $this->isOverdue($record))
            ->sortBy('scheduled_for')
            ->take(10)
            ->map(fn (DeviceMaintenanceRecord $record) => [
                'id' => $record->id,
                'device_id' => $record->device_id,
                'device_name' => $record->device?->name,
                'type' => $record->type,
                'description' => $record->description,
                'scheduled_for' => $record->scheduled_for?->toIso8601String(),
                'vendor_reference' => $record->vendor_reference,
            ])
            ->values();

        $groupQuery = DeviceGroup::query();
        if (! $this->access->canViewAllSites($viewer)) {
            $groupQuery->whereHas('devices', fn ($query) => $query->whereIn('devices.id', $deviceIds));
        }

        return [
            'stats' => $stats,
            'domainSummary' => $domainSummary,
            'healthSummary' => $healthSummary,
            'attentionDevices' => $attentionDevices,
            'recentEvents' => $recentEvents,
            'overdueMaintenance' => $overdueMaintenance,
            'groupCount' => $groupQuery->count(),
            'operations' => $this->estateOperations($context),
            'can' => [
                'create' => $viewer->canDo('securityDevices.devices.create'),
                'export' => $viewer->canDo('securityDevices.reports.view'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function sites(User $viewer): array
    {
        $context = $this->context($viewer);
        $sites = $context['sites']
            ->map(fn (Site $site) => $this->siteSummary($site, $context))
            ->values();

        return [
            'sites' => $sites,
            'summary' => [
                'total' => $sites->count(),
                'with_devices' => $sites->where('devices', '>', 0)->count(),
                'requiring_attention' => $sites->whereIn('health', ['critical', 'warning'])->count(),
                'unknown' => $sites->where('health', 'unknown')->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function site(User $viewer, Site $site): array
    {
        $this->access->assertCanViewSite($viewer, (int) $site->id);
        abort_unless(! $site->archived && $site->is_active && $site->archived_at === null, 404);

        $context = $this->context($viewer, [(int) $site->id]);
        $devices = $this->devicesForSite($context, (int) $site->id);
        $deviceIds = $devices->pluck('id')->all();
        $summary = $this->siteSummary($site, $context);
        $relationships = $deviceIds === []
            ? collect()
            : DeviceRelationship::query()
                ->with(['parent:id,name', 'child:id,name'])
                ->whereIn('parent_device_id', $deviceIds)
                ->whereIn('child_device_id', $deviceIds)
                ->get();

        $wanDevices = $devices
            ->filter(function (Device $device): bool {
                if ($device->domain !== DeviceDomain::ItInfrastructure->value) {
                    return false;
                }

                return Str::contains(
                    Str::lower(implode(' ', [$device->name, $device->category, $device->subcategory])),
                    ['wan', 'sd-wan', 'sd_wan', 'router', 'gateway', 'firewall', 'edge'],
                );
            })
            ->map(fn (Device $device) => $this->deviceRow($device))
            ->values();

        $groups = $deviceIds === []
            ? collect()
            : DeviceGroup::query()
                ->whereHas('devices', fn ($query) => $query->whereIn('devices.id', $deviceIds))
                ->withCount(['devices' => fn ($query) => $query->whereIn('devices.id', $deviceIds)])
                ->orderBy('name')
                ->get()
                ->map(fn (DeviceGroup $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'type' => $group->type,
                    'device_count' => (int) $group->devices_count,
                    'href' => "/security-devices/device-groups/{$group->id}",
                ]);

        $monitors = $devices
            ->flatMap(fn (Device $device) => $device->monitors->map(fn ($monitor) => [
                'id' => $monitor->id,
                'device_id' => $device->id,
                'device_name' => $device->name,
                'name' => $monitor->name,
                'kind' => $monitor->kind?->value,
                'state' => $monitor->current_state?->value,
                'last_observation_at' => $monitor->last_observation_at?->toIso8601String(),
            ]))
            ->values();

        $alerts = $this->alertsForSite($context, (int) $site->id)
            ->sortByDesc('triggered_at')
            ->take(20)
            ->map(fn (ControlRoomAlert $alert) => [
                'id' => $alert->id,
                'reference' => $alert->reference_number,
                'title' => $alert->alert_type,
                'severity' => $alert->severity,
                'status' => $alert->status,
                'triggered_at' => $alert->triggered_at?->toIso8601String(),
                'href' => "/control-room/alerts/{$alert->id}",
            ])
            ->values();

        $itWork = $this->ticketsForSite($context, (int) $site->id)
            ->sortByDesc('updated_at')
            ->take(20)
            ->map(fn (ItTicket $ticket) => [
                'id' => $ticket->id,
                'reference' => $ticket->reference,
                'title' => $ticket->title,
                'work_type' => $ticket->work_type,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'next_action' => $ticket->next_action,
                'updated_at' => $ticket->updated_at?->toIso8601String(),
                'href' => "/it/tickets/{$ticket->id}",
            ])
            ->values();

        $maintenance = $this->maintenanceForSite($context, (int) $site->id)
            ->sortBy(fn (DeviceMaintenanceRecord $record) => $record->scheduled_for?->timestamp ?? PHP_INT_MAX)
            ->take(20)
            ->map(fn (DeviceMaintenanceRecord $record) => [
                'id' => $record->id,
                'device_id' => $record->device_id,
                'device_name' => $record->device?->name,
                'type' => $record->type,
                'status' => $record->status,
                'description' => $record->description,
                'scheduled_for' => $record->scheduled_for?->toIso8601String(),
                'is_overdue' => $this->isOverdue($record),
            ])
            ->values();

        return [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'city' => $site->city,
                'address' => collect([$site->address_line_1, $site->suburb, $site->city])->filter()->implode(', '),
                'is_active' => (bool) $site->is_active,
            ],
            'summary' => $summary,
            'devices' => $devices->map(fn (Device $device) => $this->deviceRow($device))->values(),
            'wan' => [
                'known' => $wanDevices->isNotEmpty(),
                'label' => $wanDevices->isNotEmpty()
                    ? 'WAN / SD-WAN equipment identified'
                    : 'WAN / SD-WAN path not classified',
                'devices' => $wanDevices,
            ],
            'topology' => [
                'device_count' => $devices->count(),
                'edge_count' => $relationships->count(),
                'is_complete' => $devices->count() > 0 && $relationships->count() >= max(0, $devices->count() - 1),
                'edges' => $relationships->map(fn (DeviceRelationship $relationship) => [
                    'id' => $relationship->id,
                    'parent_device_id' => $relationship->parent_device_id,
                    'parent_name' => $relationship->parent?->name,
                    'child_device_id' => $relationship->child_device_id,
                    'child_name' => $relationship->child?->name,
                    'type' => $relationship->relationship_type?->value,
                    'port' => $relationship->port,
                ])->values(),
            ],
            'deviceGroups' => $groups,
            'monitoring' => [
                'total_devices' => $devices->count(),
                'monitored_devices' => $devices->filter(fn (Device $device) => $device->monitors->isNotEmpty())->count(),
                'unmonitored_devices' => $devices->filter(fn (Device $device) => $device->monitors->isEmpty())->count(),
                'failed_monitors' => $monitors->whereIn('state', [MonitorState::Failed->value, MonitorState::Degraded->value])->count(),
                'uncertain_monitors' => $monitors->whereIn('state', [MonitorState::Unknown->value, MonitorState::Stale->value, MonitorState::Pending->value])->count(),
                'monitors' => $monitors,
            ],
            'alerts' => $alerts,
            'itWork' => $itWork,
            'maintenance' => $maintenance,
            'collectors' => $this->collectorsForSite($context, (int) $site->id)
                ->map(fn (MonitoringCollector $collector) => $this->collectorRow($collector))
                ->values(),
            'changes' => $this->recentChanges($context, (int) $site->id),
            'contacts' => SiteContact::query()
                ->where('site_id', $site->id)
                ->orderByDesc('is_primary')
                ->orderBy('name')
                ->get()
                ->map(fn (SiteContact $contact) => [
                    'id' => $contact->id,
                    'type' => $contact->type,
                    'name' => $contact->name,
                    'role' => $contact->role,
                    'phone' => $contact->phone,
                    'email' => $contact->email,
                    'is_primary' => (bool) $contact->is_primary,
                ]),
            'can' => [
                'view_control_room' => $context['canControlRoom'],
                'view_it_work' => $context['canIt'],
                'export' => $viewer->canDo('securityDevices.reports.view'),
            ],
        ];
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function estateOperations(array $context): array
    {
        $operationalDevices = $context['devices']->reject(fn (Device $device) => in_array(
            $device->status?->value,
            [DeviceStatus::Decommissioned->value, DeviceStatus::InStock->value, DeviceStatus::Lost->value],
            true,
        ));
        $monitoredDevices = $operationalDevices->filter(fn (Device $device) => $device->monitors->isNotEmpty());
        $unmonitoredDevices = $operationalDevices->filter(fn (Device $device) => $device->monitors->isEmpty());
        $failedMonitors = $operationalDevices
            ->flatMap->monitors
            ->filter(fn ($monitor) => in_array(
                $monitor->current_state?->value,
                [MonitorState::Failed->value, MonitorState::Degraded->value],
                true,
            ));
        $siteImpact = $context['sites']
            ->map(fn (Site $site) => $this->siteSummary($site, $context))
            ->whereIn('health', ['critical', 'warning'])
            ->values();
        $overdueMaintenance = $context['maintenance']
            ->filter(fn (DeviceMaintenanceRecord $record) => $this->isOverdue($record));

        return [
            'coverage' => [
                'total_devices' => $operationalDevices->count(),
                'monitored_devices' => $monitoredDevices->count(),
                'unmonitored_devices' => $unmonitoredDevices->count(),
                'percent' => $operationalDevices->isEmpty()
                    ? null
                    : (int) round(($monitoredDevices->count() / $operationalDevices->count()) * 100),
            ],
            'summary' => [
                'affected_sites' => $siteImpact->count(),
                'active_findings' => $context['activeEvents']->count(),
                'open_it_work' => $context['canIt'] ? $context['tickets']->count() : null,
                'failed_monitors' => $failedMonitors->count(),
                'overdue_maintenance' => $overdueMaintenance->count(),
            ],
            'site_impact' => $siteImpact,
            'action_queue' => collect([
                [
                    'key' => 'failed_monitors',
                    'label' => 'Failed monitors',
                    'count' => $failedMonitors->count(),
                    'href' => '/security-devices/monitoring?state=failed',
                ],
                [
                    'key' => 'unmonitored_devices',
                    'label' => 'Devices without monitoring',
                    'count' => $unmonitoredDevices->count(),
                    'href' => '/security-devices/devices?view=unmonitored',
                ],
                [
                    'key' => 'overdue_maintenance',
                    'label' => 'Overdue maintenance',
                    'count' => $overdueMaintenance->count(),
                    'href' => '/security-devices/maintenance?status=overdue',
                ],
                [
                    'key' => 'open_it_work',
                    'label' => 'Open IT work',
                    'count' => $context['canIt'] ? $context['tickets']->count() : null,
                    'href' => $context['canIt'] ? '/it?view=open' : null,
                ],
            ])->values(),
            'recent_changes' => $this->recentChanges($context),
        ];
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function siteSummary(Site $site, array $context): array
    {
        $devices = $this->devicesForSite($context, (int) $site->id);
        $monitored = $devices->filter(fn (Device $device) => $device->monitors->isNotEmpty());
        $unmonitored = $devices->filter(fn (Device $device) => $device->monitors->isEmpty());
        $monitors = $devices->flatMap->monitors;
        $events = $this->eventsForSite($context, (int) $site->id);
        $maintenance = $this->maintenanceForSite($context, (int) $site->id);
        $collectors = $this->collectorsForSite($context, (int) $site->id);
        $tickets = $this->ticketsForSite($context, (int) $site->id);
        $alerts = $this->alertsForSite($context, (int) $site->id);
        $collector = $this->collectorSummary($collectors);
        $attention = $devices->filter(fn (Device $device) => $this->deviceNeedsAttention($device));
        $failedMonitors = $monitors->filter(fn ($monitor) => in_array(
            $monitor->current_state?->value,
            [MonitorState::Failed->value, MonitorState::Degraded->value],
            true,
        ));
        $uncertainMonitors = $monitors->filter(fn ($monitor) => in_array(
            $monitor->current_state?->value,
            [MonitorState::Unknown->value, MonitorState::Stale->value, MonitorState::Pending->value],
            true,
        ));
        $overdue = $maintenance->filter(fn (DeviceMaintenanceRecord $record) => $this->isOverdue($record));

        $health = match (true) {
            $devices->isEmpty() => 'unknown',
            $devices->contains(fn (Device $device) => $device->health_status?->value === HealthStatus::Critical->value),
            $failedMonitors->isNotEmpty(),
            $events->contains(fn (DeviceEvent $event) => $event->severity === 'critical') => 'critical',
            $attention->isNotEmpty(),
            $unmonitored->isNotEmpty(),
            $uncertainMonitors->isNotEmpty(),
            $overdue->isNotEmpty(),
            $collector['state'] === 'stale' => 'warning',
            default => 'healthy',
        };

        $latestChange = collect([
            $devices->max('updated_at'),
            $events->max('occurred_at'),
            $maintenance->max('updated_at'),
            $monitors->max('last_state_changed_at'),
            $monitors->max('last_observation_at'),
            $tickets->max('updated_at'),
            $alerts->max('triggered_at'),
            $collectors->max('last_seen_at'),
        ])->filter()->sortDesc()->first();

        return [
            'id' => $site->id,
            'name' => $site->name,
            'type' => $site->type,
            'city' => $site->city,
            'is_active' => (bool) $site->is_active,
            'health' => $health,
            'devices' => $devices->count(),
            'attention_devices' => $attention->count(),
            'offline_devices' => $devices->filter(fn (Device $device) => $device->status?->value === DeviceStatus::Offline->value)->count(),
            'monitored_devices' => $monitored->count(),
            'unmonitored_devices' => $unmonitored->count(),
            'coverage_percent' => $devices->isEmpty()
                ? null
                : (int) round(($monitored->count() / $devices->count()) * 100),
            'failed_monitors' => $failedMonitors->count(),
            'active_findings' => $events->count(),
            'active_control_room_alerts' => $context['canControlRoom'] ? $alerts->count() : null,
            'open_it_work' => $context['canIt'] ? $tickets->count() : null,
            'overdue_maintenance' => $overdue->count(),
            'collector' => $collector,
            'last_change_at' => $latestChange?->toIso8601String(),
            'requires_action' => in_array($health, ['critical', 'warning'], true),
            'href' => "/security-devices/sites/{$site->id}",
        ];
    }

    /** @return array<string, mixed> */
    private function context(User $viewer, ?array $onlySiteIds = null): array
    {
        $siteIds = $this->access->accessibleSiteIds($viewer);
        if ($onlySiteIds !== null) {
            $siteIds = array_values(array_intersect($siteIds, $onlySiteIds));
        }

        $sites = $siteIds === []
            ? collect()
            : Site::query()
                ->whereIn('id', $siteIds)
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get();

        $deviceQuery = $this->access->visibleDevices($viewer)
            ->with([
                'assignments' => fn ($query) => $query->active(),
                'monitors' => fn ($query) => $query->where('is_enabled', true),
            ]);
        $devices = $deviceQuery->get();
        $deviceIds = $devices->pluck('id');
        $deviceSiteMap = $this->deviceSiteMap($devices, $sites->pluck('id')->all());

        $activeEvents = $deviceIds->isEmpty()
            ? collect()
            : DeviceEvent::query()
                ->with('device:id,name')
                ->whereIn('device_id', $deviceIds)
                ->whereIn('severity', ['critical', 'warning'])
                ->where('occurred_at', '>=', now()->subHours(self::ACTIVE_EVENT_WINDOW_HOURS))
                ->get();

        $maintenance = $deviceIds->isEmpty()
            ? collect()
            : DeviceMaintenanceRecord::query()
                ->with('device:id,name')
                ->whereIn('device_id', $deviceIds)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->get();

        $collectors = $siteIds === []
            ? collect()
            : MonitoringCollector::query()
                ->whereIn('site_id', $siteIds)
                ->get();

        $canIt = $viewer->canDo('it.view');
        $ticketQuery = ItTicket::query()
            ->whereIn('status', ItTicket::OPEN_STATUSES)
            ->with(['links' => fn ($query) => $query->whereIn('relationship', ['affected_site', 'affected_device'])]);
        $tickets = $canIt ? $this->itAccess->applyViewScope($ticketQuery, $viewer)->get() : collect();

        $canControlRoom = $viewer->canDo('controlRoom.viewAny')
            || $viewer->canDo('controlRoom.alerts.view');
        $alerts = $canControlRoom && $siteIds !== []
            ? ControlRoomAlert::query()
                ->whereIn('status', ControlRoomAlert::ACTIVE_STATUSES)
                ->where(function ($query) use ($siteIds, $deviceIds): void {
                    $query->whereIn('site_id', $siteIds);
                    if ($deviceIds->isNotEmpty()) {
                        $query->orWhereHas('device', fn ($device) => $device->whereIn('canonical_device_id', $deviceIds));
                    }
                })
                ->with('device:id,canonical_device_id')
                ->get()
            : collect();

        return compact(
            'sites',
            'devices',
            'deviceSiteMap',
            'activeEvents',
            'maintenance',
            'collectors',
            'tickets',
            'alerts',
            'canIt',
            'canControlRoom',
        );
    }

    /** @param Collection<int, Device> $devices @param array<int, int> $allowedSiteIds @return Collection<int, int> */
    private function deviceSiteMap(Collection $devices, array $allowedSiteIds): Collection
    {
        $roomIds = $devices
            ->flatMap->assignments
            ->where('assignable_type', DeviceAssignment::TARGET_ROOM)
            ->pluck('assignable_id')
            ->unique();
        $roomSites = $roomIds->isEmpty()
            ? collect()
            : SiteRoom::query()->whereIn('id', $roomIds)->pluck('site_id', 'id');

        return $devices->mapWithKeys(function (Device $device) use ($roomSites, $allowedSiteIds): array {
            $siteId = $device->assignments
                ->map(function (DeviceAssignment $assignment) use ($roomSites): ?int {
                    return match ($assignment->assignable_type) {
                        DeviceAssignment::TARGET_SITE => (int) $assignment->assignable_id,
                        DeviceAssignment::TARGET_ROOM => ($roomSites[$assignment->assignable_id] ?? null)
                            ? (int) $roomSites[$assignment->assignable_id]
                            : null,
                        default => null,
                    };
                })
                ->filter()
                ->first();

            return $siteId !== null && in_array($siteId, $allowedSiteIds, true)
                ? [$device->id => $siteId]
                : [];
        });
    }

    /** @param array<string, mixed> $context @return Collection<int, Device> */
    private function devicesForSite(array $context, int $siteId): Collection
    {
        $deviceIds = $context['deviceSiteMap']
            ->filter(fn (int $mappedSiteId) => $mappedSiteId === $siteId)
            ->keys();

        return $context['devices']->whereIn('id', $deviceIds)->values();
    }

    /** @param array<string, mixed> $context @return Collection<int, DeviceEvent> */
    private function eventsForSite(array $context, int $siteId): Collection
    {
        $deviceIds = $this->devicesForSite($context, $siteId)->pluck('id');

        return $context['activeEvents']->whereIn('device_id', $deviceIds)->values();
    }

    /** @param array<string, mixed> $context @return Collection<int, DeviceMaintenanceRecord> */
    private function maintenanceForSite(array $context, int $siteId): Collection
    {
        $deviceIds = $this->devicesForSite($context, $siteId)->pluck('id');

        return $context['maintenance']->whereIn('device_id', $deviceIds)->values();
    }

    /** @param array<string, mixed> $context @return Collection<int, MonitoringCollector> */
    private function collectorsForSite(array $context, int $siteId): Collection
    {
        return $context['collectors']->where('site_id', $siteId)->values();
    }

    /** @param array<string, mixed> $context @return Collection<int, ItTicket> */
    private function ticketsForSite(array $context, int $siteId): Collection
    {
        if (! $context['canIt']) {
            return collect();
        }

        return $context['tickets']->filter(function (ItTicket $ticket) use ($context, $siteId): bool {
            if ((int) $ticket->site_id === $siteId) {
                return true;
            }

            return $ticket->links->contains(function ($link) use ($context, $siteId): bool {
                if ($link->relationship === 'affected_site'
                    && $link->linkable_type === Site::class
                    && (int) $link->linkable_id === $siteId) {
                    return true;
                }

                return $link->relationship === 'affected_device'
                    && $link->linkable_type === Device::class
                    && (int) ($context['deviceSiteMap'][(int) $link->linkable_id] ?? 0) === $siteId;
            });
        })->values();
    }

    /** @param array<string, mixed> $context @return Collection<int, ControlRoomAlert> */
    private function alertsForSite(array $context, int $siteId): Collection
    {
        if (! $context['canControlRoom']) {
            return collect();
        }

        return $context['alerts']->filter(function (ControlRoomAlert $alert) use ($context, $siteId): bool {
            if ((int) $alert->site_id === $siteId) {
                return true;
            }

            $canonicalDeviceId = $alert->device?->canonical_device_id;

            return $canonicalDeviceId !== null
                && (int) ($context['deviceSiteMap'][(int) $canonicalDeviceId] ?? 0) === $siteId;
        })->values();
    }

    /** @param Collection<int, MonitoringCollector> $collectors @return array<string, mixed> */
    private function collectorSummary(Collection $collectors): array
    {
        if ($collectors->isEmpty()) {
            return [
                'state' => 'not_configured',
                'label' => 'No local collector configured',
                'count' => 0,
                'last_seen_at' => null,
            ];
        }

        $stale = $collectors->contains(fn (MonitoringCollector $collector) => $collector->status !== 'online'
            || $collector->last_seen_at === null
            || $collector->last_seen_at->lt(now()->subMinutes(5)));

        return [
            'state' => $stale ? 'stale' : 'online',
            'label' => $stale ? 'Collector needs attention' : 'Collector online',
            'count' => $collectors->count(),
            'last_seen_at' => $collectors->max('last_seen_at')?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function collectorRow(MonitoringCollector $collector): array
    {
        $stale = $collector->status !== 'online'
            || $collector->last_seen_at === null
            || $collector->last_seen_at->lt(now()->subMinutes(5));

        return [
            'id' => $collector->id,
            'uuid' => $collector->collector_uuid,
            'name' => $collector->name,
            'status' => $collector->status,
            'state' => $stale ? 'stale' : 'online',
            'last_seen_at' => $collector->last_seen_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function deviceRow(Device $device): array
    {
        $assignment = $device->assignments->first();
        $states = $device->monitors->pluck('current_state.value');
        $monitoringState = match (true) {
            $device->monitors->isEmpty() => 'unmonitored',
            $states->contains(fn ($state) => in_array($state, [MonitorState::Failed->value, MonitorState::Degraded->value], true)) => 'attention',
            $states->contains(fn ($state) => in_array($state, [MonitorState::Unknown->value, MonitorState::Stale->value, MonitorState::Pending->value], true)) => 'unknown',
            default => 'healthy',
        };

        return [
            'id' => $device->id,
            'device_uid' => $device->device_uid,
            'name' => $device->name,
            'domain' => $device->domain,
            'category' => $device->category,
            'subcategory' => $device->subcategory,
            'manufacturer' => $device->manufacturer,
            'model' => $device->model,
            'status' => $device->status?->value,
            'health_status' => $device->health_status?->value,
            'provider' => $device->provider,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'last_changed_at' => $device->updated_at?->toIso8601String(),
            'battery_level' => $device->battery_level,
            'assignment_type' => $assignment?->assignable_type,
            'assigned_to' => $assignment
                ? ucfirst($assignment->assignable_type).' #'.$assignment->assignable_id
                : null,
            'monitor_count' => $device->monitors->count(),
            'monitoring_state' => $monitoringState,
            'href' => "/security-devices/devices/{$device->id}",
        ];
    }

    /** @param array<string, mixed> $context @return Collection<int, array<string, mixed>> */
    private function recentChanges(array $context, ?int $siteId = null): Collection
    {
        $devices = $siteId === null
            ? $context['devices']
            : $this->devicesForSite($context, $siteId);
        $deviceIds = $devices->pluck('id');
        $events = $siteId === null
            ? $context['activeEvents']
            : $context['activeEvents']->whereIn('device_id', $deviceIds);

        return $devices
            ->map(fn (Device $device) => [
                'key' => "device-{$device->id}-updated",
                'kind' => 'device_updated',
                'device_id' => $device->id,
                'device_name' => $device->name,
                'summary' => 'Device record updated',
                'at' => $device->updated_at?->toIso8601String(),
                'href' => "/security-devices/devices/{$device->id}",
            ])
            ->concat($events->map(fn (DeviceEvent $event) => [
                'key' => "event-{$event->id}",
                'kind' => 'device_event',
                'device_id' => $event->device_id,
                'device_name' => $event->device?->name,
                'summary' => Str::headline($event->event_type),
                'at' => $event->occurred_at?->toIso8601String(),
                'href' => "/security-devices/devices/{$event->device_id}",
            ]))
            ->filter(fn (array $change) => $change['at'] !== null)
            ->sortByDesc('at')
            ->take(12)
            ->values();
    }

    private function deviceNeedsAttention(Device $device): bool
    {
        return in_array($device->health_status?->value, [HealthStatus::Critical->value, HealthStatus::Warning->value], true)
            || $device->status?->value === DeviceStatus::Offline->value;
    }

    private function isOverdue(DeviceMaintenanceRecord $record): bool
    {
        return $record->status === 'scheduled'
            && $record->scheduled_for !== null
            && $record->scheduled_for->isPast();
    }
}
