<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SecurityWorkspacePresenter
{
    private const DEVICE_LIMIT = 50;

    private const EVENT_LIMIT = 25;

    private const OBSERVED_FIELDS = [
        'cctv' => ['stream_health', 'recording_health', 'camera_state', 'recorder_state'],
        'alarm' => ['alarm_state', 'partition_state', 'sensor_state', 'zones'],
        'perimeter' => ['alarm_state', 'sensor_state', 'zones'],
        'access_control' => ['door_state', 'lock_state', 'reader_state', 'panel_state', 'credential_count', 'schedule_count'],
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly UserSiteAccessService $siteAccess,
        private readonly AccessControlWorkspacePresenter $accessControlPresenter,
    ) {}

    public function present(User $viewer, Builder $securityScope, array $activeTab): array
    {
        $canViewEvents = $viewer->canDo('securityDevices.events.view');
        $canViewMaintenance = $viewer->canDo('securityDevices.maintenance.view');
        $canViewControlRoom = $viewer->canDo('controlRoom.viewAny')
            || $viewer->canDo('controlRoom.alerts.view');
        $canViewMedia = $viewer->canDo('securityDevices.cctv.media.view');

        $activeScope = clone $securityScope;
        if (isset($activeTab['categories'])) {
            $activeScope->whereIn('category', $activeTab['categories']);
        }

        $activeDevices = (clone $activeScope)
            ->with([
                'assignments' => fn ($query) => $query
                    ->active()
                    ->where('assigned_at', '<=', now())
                    ->latest('assigned_at'),
                'maintenanceRecords' => fn ($query) => $query
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->orderBy('scheduled_for'),
                'monitors' => fn ($query) => $query->where('is_enabled', true),
            ])
            ->orderBy('name')
            ->limit(self::DEVICE_LIMIT)
            ->get();
        $assignmentContext = $this->assignmentContext($viewer, $activeDevices);

        $events = $canViewEvents
            ? DeviceEvent::query()
                ->whereIn('device_id', (clone $activeScope)->select('devices.id'))
                ->with('device:id,name')
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(self::EVENT_LIMIT)
                ->get()
            : collect();
        $alerts = $canViewControlRoom
            ? ControlRoomAlert::query()
                ->whereIn('status', ControlRoomAlert::ACTIVE_STATUSES)
                ->whereHas('device', fn (Builder $device) => $device
                    ->whereIn('canonical_device_id', (clone $activeScope)->select('devices.id')))
                ->with('device:id,canonical_device_id')
                ->orderByDesc('triggered_at')
                ->limit(self::EVENT_LIMIT)
                ->get()
            : collect();

        $restricted = isset($activeTab['requiredPermission'])
            && ! $viewer->canDo($activeTab['requiredPermission']);

        return [
            'permissions' => [
                'events' => $canViewEvents,
                'maintenance' => $canViewMaintenance,
                'control_room' => $canViewControlRoom,
                'cctv_media' => $canViewMedia,
            ],
            'overview' => $this->overview(
                $viewer,
                $securityScope,
                $canViewEvents,
                $canViewMaintenance,
                $canViewControlRoom,
            ),
            'activeTab' => [
                'key' => $activeTab['key'],
                'label' => $activeTab['label'],
                'description' => $activeTab['description'],
                'restricted' => $restricted,
                'inventoryTotal' => (clone $activeScope)->count(),
                'inventoryShown' => $activeDevices->count(),
                'inventoryTruncated' => (clone $activeScope)->count() > self::DEVICE_LIMIT,
                'devices' => $restricted
                    ? []
                    : $activeDevices->map(fn (Device $device) => $this->mapDevice(
                        $device,
                        $assignmentContext,
                        $canViewMaintenance,
                        $canViewMedia,
                    ))->values(),
                'recentEvents' => $restricted
                    ? []
                    : $events->map(fn (DeviceEvent $event) => $this->mapEvent($event))->values(),
                'controlRoomAlerts' => $restricted
                    ? []
                    : $alerts->map(fn (ControlRoomAlert $alert) => $this->mapAlert($alert))->values(),
                'accessControl' => $activeTab['key'] === 'access-control'
                    ? $this->accessControlPresenter->present($viewer, clone $activeScope)
                    : null,
            ],
        ];
    }

    private function overview(
        User $viewer,
        Builder $scope,
        bool $canViewEvents,
        bool $canViewMaintenance,
        bool $canViewControlRoom,
    ): array {
        $total = (clone $scope)->count();
        $cctv = (clone $scope)->where('category', 'cctv')->count();
        $alarms = (clone $scope)->whereIn('category', ['alarm', 'perimeter'])->count();
        $accessControl = (clone $scope)->where('category', 'access_control')->count();
        $attention = (clone $scope)->needingAttention();
        $attentionDevices = (clone $attention)->count();
        $offlineDevices = (clone $scope)->where('status', DeviceStatus::Offline->value)->count();
        $unmonitoredDevices = (clone $scope)
            ->whereDoesntHave('monitors', fn (Builder $monitor) => $monitor->where('is_enabled', true))
            ->count();
        $affectedSites = $this->affectedSiteCount($viewer, clone $attention);

        $unprocessedEvents = $canViewEvents
            ? DeviceEvent::query()
                ->whereIn('device_id', (clone $scope)->select('devices.id'))
                ->unprocessed()
                ->count()
            : null;
        $overdueMaintenance = $canViewMaintenance
            ? DeviceMaintenanceRecord::query()
                ->whereIn('device_id', (clone $scope)->select('devices.id'))
                ->overdue()
                ->count()
            : null;
        $activeControlRoomAlerts = $canViewControlRoom
            ? ControlRoomAlert::query()
                ->whereIn('status', ControlRoomAlert::ACTIVE_STATUSES)
                ->whereHas('device', fn (Builder $device) => $device
                    ->whereIn('canonical_device_id', (clone $scope)->select('devices.id')))
                ->count()
            : null;

        $actions = collect([
            $this->action(
                'offline_devices',
                'Offline security devices',
                $offlineDevices,
                'Restore or investigate devices that are currently offline.',
                '/security-devices/security?status=offline',
            ),
            $this->action(
                'unmonitored_devices',
                'Security devices without monitoring',
                $unmonitoredDevices,
                'Add an approved native monitor or confirm why monitoring is unsupported.',
                '/security-devices/devices?domain=security&monitoring=unmonitored',
            ),
            $canViewEvents ? $this->action(
                'unprocessed_events',
                'Unprocessed security events',
                $unprocessedEvents ?? 0,
                'Review canonical device events that have not completed signal processing.',
                '/security-devices/security?tab=events',
            ) : null,
            $canViewMaintenance ? $this->action(
                'overdue_maintenance',
                'Overdue security maintenance',
                $overdueMaintenance ?? 0,
                'Schedule or complete overdue inspections and repairs.',
                '/security-devices/maintenance-health?status=overdue&domain=security',
            ) : null,
            $canViewControlRoom ? $this->action(
                'active_control_room_alerts',
                'Active Control Room alerts',
                $activeControlRoomAlerts ?? 0,
                'Open the canonical alert workspace for operational triage.',
                '/control-room/alerts?source=security_devices',
            ) : null,
        ])->filter()->values();

        return [
            'inventory' => [
                'total' => $total,
                'cctv' => $cctv,
                'alarms' => $alarms,
                'access_control' => $accessControl,
                'other' => max(0, $total - $cctv - $alarms - $accessControl),
            ],
            'attention' => [
                'devices' => $attentionDevices,
                'sites' => $affectedSites,
                'overdue_maintenance' => $overdueMaintenance,
                'unprocessed_events' => $unprocessedEvents,
                'active_control_room_alerts' => $activeControlRoomAlerts,
            ],
            'requiredActions' => $actions,
        ];
    }

    private function action(string $key, string $label, int $count, string $description, string $href): ?array
    {
        if ($count === 0) {
            return null;
        }

        return compact('key', 'label', 'count', 'description', 'href');
    }

    private function affectedSiteCount(User $viewer, Builder $attention): int
    {
        $assignments = DeviceAssignment::query()
            ->active()
            ->where('assigned_at', '<=', now())
            ->whereIn('device_id', (clone $attention)->select('devices.id'))
            ->get(['assignable_type', 'assignable_id']);
        $context = $this->assignmentSiteContext($viewer, $assignments);

        return $assignments
            ->map(fn (DeviceAssignment $assignment): ?int => $this->siteIdForAssignment($assignment, $context))
            ->filter(fn (?int $siteId): bool => $siteId !== null && $context['sites']->has($siteId))
            ->unique()
            ->count();
    }

    /** @param Collection<int, Device> $devices */
    private function assignmentContext(User $viewer, Collection $devices): array
    {
        $assignments = $devices->flatMap->assignments;
        $clientIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->pluck('assignable_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $staffIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
            ->pluck('assignable_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $canOpenClientProfiles = $viewer->canDo('clients.viewAny')
            || $viewer->canDo('clients.viewAssigned');
        $clients = $clientIds->isEmpty() || ! $canOpenClientProfiles
            ? collect()
            : Client::query()
                ->whereKey($clientIds)
                ->get(['id', 'site_id', 'first_name', 'last_name', 'preferred_name'])
                ->filter(fn (Client $client): bool => Gate::forUser($viewer)->allows('view', $client))
                ->keyBy('id');
        $staff = $staffIds->isEmpty()
            ? collect()
            : $this->access->assignableStaff($viewer)
                ->whereKey($staffIds)
                ->get(['id', 'name'])
                ->keyBy('id');

        $staffProfiles = collect();
        if ($viewer->canDo('hr.employees.viewAny') && $staff->isNotEmpty()) {
            $profileQuery = HrEmployeeProfile::withTrashed()
                ->whereIn('user_id', $staff->keys());
            $this->siteAccess->applyHistoricalStaffProfileScope($profileQuery, $viewer);
            $staffProfiles = $profileQuery
                ->get(['id', 'user_id'])
                ->keyBy('user_id');
        }

        return [
            ...$this->assignmentSiteContext($viewer, $assignments),
            'clients' => $clients,
            'staff' => $staff,
            'staffProfiles' => $staffProfiles,
        ];
    }

    /** @param Collection<int, DeviceAssignment> $assignments */
    private function assignmentSiteContext(User $viewer, Collection $assignments): array
    {
        $accessibleSiteIds = $this->access->accessibleSiteIds($viewer);
        $directSiteIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_SITE)
            ->pluck('assignable_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();
        $roomIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_ROOM)
            ->pluck('assignable_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();
        $clientIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->pluck('assignable_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();
        $staffIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
            ->pluck('assignable_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();
        $vehicleIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_VEHICLE)
            ->pluck('assignable_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();

        $rooms = $roomIds->isEmpty()
            ? collect()
            : SiteRoom::query()
                ->whereIn('id', $roomIds)
                ->whereIn('site_id', $accessibleSiteIds)
                ->with('site:id,name')
                ->get()
                ->keyBy('id');
        $roomSiteIds = $rooms->map(fn (SiteRoom $room): int => (int) $room->site_id);
        $clientSiteIds = $clientIds->isEmpty()
            ? collect()
            : Client::query()
                ->whereKey($clientIds)
                ->where('status', 'active')
                ->whereIn('site_id', $accessibleSiteIds)
                ->pluck('site_id', 'id')
                ->map(fn (mixed $id): int => (int) $id);
        $staffSiteIds = $staffIds->isEmpty()
            ? collect()
            : HrEmployeeProfile::query()
                ->whereIn('user_id', $staffIds)
                ->where('is_active', true)
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('start_date')
                    ->orWhereDate('start_date', '<=', today()))
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', today()))
                ->whereIn('primary_site_id', $accessibleSiteIds)
                ->pluck('primary_site_id', 'user_id')
                ->map(fn (mixed $id): int => (int) $id);
        $accessibleVehicleIds = collect($this->access->accessibleAssetIds($viewer));
        $vehicleSiteIds = $vehicleIds->isEmpty() || $accessibleVehicleIds->isEmpty()
            ? collect()
            : Asset::query()
                ->whereKey($vehicleIds->intersect($accessibleVehicleIds)->values()->all())
                ->where('status', 'active')
                ->with(['categoryRef:id,slug', 'client:id,site_id,status'])
                ->get(['id', 'category', 'asset_category_id', 'site_id', 'home_site_id', 'client_id'])
                ->filter(fn (Asset $asset): bool => strcasecmp((string) $asset->category, 'vehicle') === 0
                    || $asset->categoryRef?->slug === 'vehicle')
                ->mapWithKeys(function (Asset $asset) use ($accessibleSiteIds): array {
                    $siteIds = collect([
                        $asset->site_id,
                        $asset->home_site_id,
                        $asset->client?->status === 'active' ? $asset->client?->site_id : null,
                    ])->filter(fn (mixed $id): bool => is_numeric($id)
                        && in_array((int) $id, $accessibleSiteIds, true))
                        ->map(fn (mixed $id): int => (int) $id)
                        ->unique();

                    return $siteIds->count() === 1 ? [$asset->id => $siteIds->first()] : [];
                });
        $siteIds = collect([
            $directSiteIds,
            $roomSiteIds->values(),
            $clientSiteIds->values(),
            $staffSiteIds->values(),
            $vehicleSiteIds->values(),
        ])->flatten()
            ->filter(fn (mixed $id): bool => is_numeric($id)
                && in_array((int) $id, $accessibleSiteIds, true))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();

        return [
            'sites' => $siteIds->isEmpty()
                ? collect()
                : Site::query()
                    ->whereIn('id', $siteIds)
                    ->where('is_active', true)
                    ->where('archived', false)
                    ->whereNull('archived_at')
                    ->get(['id', 'name'])
                    ->keyBy('id'),
            'rooms' => $rooms,
            'roomSiteIds' => $roomSiteIds,
            'clientSiteIds' => $clientSiteIds,
            'staffSiteIds' => $staffSiteIds,
            'vehicleSiteIds' => $vehicleSiteIds,
        ];
    }

    private function mapDevice(
        Device $device,
        array $siteContext,
        bool $canViewMaintenance,
        bool $canViewMedia,
    ): array {
        $assignment = $device->assignments->first();
        $site = $this->siteForAssignment($assignment, $siteContext);
        $openMaintenance = $canViewMaintenance ? $device->maintenanceRecords : collect();
        $nextMaintenance = $openMaintenance->first();

        $mapped = [
            'id' => $device->id,
            'name' => $device->name,
            'category' => $device->category,
            'subcategory' => $device->subcategory,
            'provider' => $device->provider,
            'status' => $device->status?->value,
            'health' => $device->health_status?->value,
            'lastSeenAt' => $device->last_seen_at?->toISOString(),
            'deviceHref' => "/security-devices/devices/{$device->id}",
            'site' => $site,
            'assignment' => $assignment ? [
                'type' => $assignment->assignable_type,
                'label' => $this->assignmentLabel($assignment, $siteContext),
                'href' => $this->assignmentHref($assignment, $siteContext),
            ] : null,
            'monitoring' => [
                'state' => $device->monitors->isEmpty() ? 'unmonitored' : 'configured',
                'count' => $device->monitors->count(),
            ],
            'observed' => $this->observedCapabilities($device),
            'maintenance' => $canViewMaintenance ? [
                'open_count' => $openMaintenance->count(),
                'overdue_count' => $openMaintenance->filter(fn (DeviceMaintenanceRecord $record) => $record->status === 'scheduled'
                    && $record->scheduled_for?->isPast())->count(),
                'next' => $nextMaintenance ? [
                    'id' => $nextMaintenance->id,
                    'type' => $nextMaintenance->type,
                    'status' => $nextMaintenance->status,
                    'description' => $nextMaintenance->description,
                    'scheduledFor' => $nextMaintenance->scheduled_for?->toIso8601String(),
                ] : null,
            ] : null,
        ];

        if ($device->category === 'cctv') {
            $mapped['media'] = $this->mediaAccess($device, $canViewMedia);
        }

        return $mapped;
    }

    private function siteForAssignment(?DeviceAssignment $assignment, array $context): ?array
    {
        if (! $assignment) {
            return null;
        }

        $siteId = $this->siteIdForAssignment($assignment, $context);
        $site = $siteId === null ? null : $context['sites']->get($siteId);

        return $site ? [
            'id' => $site->id,
            'name' => $site->name,
            'href' => "/security-devices/sites/{$site->id}",
        ] : null;
    }

    private function siteIdForAssignment(DeviceAssignment $assignment, array $context): ?int
    {
        $siteId = match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_SITE => $assignment->assignable_id,
            DeviceAssignment::TARGET_ROOM => $context['roomSiteIds']->get($assignment->assignable_id),
            DeviceAssignment::TARGET_CLIENT => $context['clientSiteIds']->get($assignment->assignable_id),
            DeviceAssignment::TARGET_STAFF => $context['staffSiteIds']->get($assignment->assignable_id),
            DeviceAssignment::TARGET_VEHICLE => $context['vehicleSiteIds']->get($assignment->assignable_id),
            default => null,
        };

        return is_numeric($siteId) ? (int) $siteId : null;
    }

    private function assignmentLabel(DeviceAssignment $assignment, array $context): string
    {
        return match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_SITE => $context['sites']->get($assignment->assignable_id)?->name
                ?? 'Assigned Site',
            DeviceAssignment::TARGET_ROOM => $context['rooms']->get($assignment->assignable_id)?->name
                ?? 'Assigned room',
            DeviceAssignment::TARGET_CLIENT => ($client = $context['clients']->get($assignment->assignable_id))
                ? ($client->preferred_name ?: $client->first_name)
                : 'Assigned client',
            DeviceAssignment::TARGET_STAFF => $context['staff']->get($assignment->assignable_id)?->name
                ?? 'Assigned staff member',
            DeviceAssignment::TARGET_VEHICLE => 'Assigned vehicle',
            default => Str::headline($assignment->assignable_type),
        };
    }

    private function assignmentHref(DeviceAssignment $assignment, array $context): ?string
    {
        return match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_CLIENT => ($client = $context['clients']->get($assignment->assignable_id))
                ? route('operations.clients.show', $client, false)
                : null,
            DeviceAssignment::TARGET_STAFF => ($profile = $context['staffProfiles']->get($assignment->assignable_id))
                ? route('hr.people.show', $profile, false)
                : null,
            default => null,
        };
    }

    private function observedCapabilities(Device $device): array
    {
        return collect(self::OBSERVED_FIELDS[$device->category] ?? [])
            ->mapWithKeys(function (string $key) use ($device): array {
                $value = $this->observedValue($device, $key);

                return $value === null ? [] : [$key => $value];
            })
            ->all();
    }

    private function observedValue(Device $device, string $key): mixed
    {
        foreach ([$device->meta ?? [], $device->config ?? []] as $source) {
            foreach (["observed.{$key}", "capabilities.{$key}", $key] as $path) {
                if (data_get($source, $path) !== null) {
                    return data_get($source, $path);
                }
            }
        }

        return null;
    }

    private function mediaAccess(Device $device, bool $canViewMedia): array
    {
        $href = $this->observedValue($device, 'media_href');
        $validInternalHref = is_string($href)
            && Str::startsWith($href, '/')
            && ! Str::startsWith($href, '//');

        if (! $validInternalHref) {
            return ['state' => 'not_configured'];
        }

        if (! $canViewMedia) {
            return ['state' => 'restricted'];
        }

        return ['state' => 'available', 'href' => $href];
    }

    private function mapEvent(DeviceEvent $event): array
    {
        $context = collect(['zone', 'door_name', 'direction', 'state', 'summary'])
            ->mapWithKeys(fn (string $key) => data_get($event->payload, $key) === null
                ? []
                : [$key => data_get($event->payload, $key)])
            ->all();

        return [
            'id' => $event->id,
            'type' => $event->event_type,
            'severity' => $event->severity,
            'source' => $event->source,
            'occurredAt' => $event->occurred_at?->toISOString(),
            'processedAt' => $event->processed_at?->toISOString(),
            'device' => $event->device ? [
                'id' => $event->device->id,
                'name' => $event->device->name,
                'href' => "/security-devices/devices/{$event->device->id}",
            ] : null,
            'context' => $context,
        ];
    }

    private function mapAlert(ControlRoomAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'reference' => $alert->reference_number,
            'title' => $alert->alert_type,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'triggeredAt' => $alert->triggered_at?->toISOString(),
            'canonicalDeviceId' => $alert->device?->canonical_device_id,
            'href' => "/control-room/alerts/{$alert->id}",
        ];
    }
}
