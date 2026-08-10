<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Models\Client;
use App\Models\ItTicketLink;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

class HealthcareWorkspacePresenter
{
    private const DEVICE_LIMIT = 50;

    private const MAINTENANCE_LIMIT = 100;

    private const FLOW_ORDER = [
        'offline',
        'integration_failure',
        'stale_delivery',
        'unsupported',
        'unknown',
        'healthy',
    ];

    public function present(User $viewer, Builder $healthcareScope, array $activeTab): array
    {
        $canViewClientContext = $viewer->canDo('clients.viewAny')
            || $viewer->canDo('clients.viewAssigned');
        $canViewClinicalMonitoring = $viewer->canDo('clinical.monitoring.viewAny');
        $canViewMaintenance = $viewer->canDo('securityDevices.maintenance.view');
        $canViewIt = $viewer->canDo('it.view');
        $restricted = $this->restricted($viewer, $activeTab);

        $activeScope = $this->activeScope(clone $healthcareScope, $activeTab['key']);
        $inventoryTotal = $restricted ? 0 : (clone $activeScope)->count();
        $activeDevices = $restricted
            ? collect()
            : (clone $activeScope)
                ->with([
                    'assignments' => fn ($query) => $query->active(),
                    'maintenanceRecords' => fn ($query) => $query
                        ->whereNotIn('status', ['completed', 'cancelled'])
                        ->orderByRaw('scheduled_for IS NULL')
                        ->orderBy('scheduled_for'),
                    'monitors' => fn ($query) => $query->where('is_enabled', true),
                ])
                ->orderBy('name')
                ->limit(self::DEVICE_LIMIT)
                ->get();

        $context = $this->assignmentContext($viewer, $activeDevices);
        $ticketMap = $canViewIt
            ? $this->ticketMap($viewer, $activeDevices->pluck('id'))
            : collect();
        $mappedDevices = $activeDevices
            ->map(fn (Device $device) => $this->mapDevice(
                $viewer,
                $device,
                $context,
                $ticketMap,
                $canViewMaintenance,
            ))
            ->values();

        return [
            'permissions' => [
                'clientContext' => $canViewClientContext,
                'clinicalMonitoring' => $canViewClinicalMonitoring,
                'maintenance' => $canViewMaintenance,
                'it' => $canViewIt,
            ],
            'boundary' => [
                'title' => 'Technical device operations only',
                'description' => 'Clinical readings, thresholds, diagnoses, medications, and clinical review stay in Client Health Monitoring.',
                'clinicalHref' => $canViewClinicalMonitoring
                    ? '/health-clinical/health-monitoring'
                    : null,
            ],
            'overview' => $this->overview(
                clone $healthcareScope,
                $canViewMaintenance,
            ),
            'activeTab' => [
                'key' => $activeTab['key'],
                'label' => $activeTab['label'],
                'description' => $activeTab['description'],
                'restricted' => $restricted,
                'inventoryTotal' => $inventoryTotal,
                'inventoryShown' => $mappedDevices->count(),
                'inventoryTruncated' => $inventoryTotal > self::DEVICE_LIMIT,
                'devices' => $mappedDevices,
                'flowGroups' => $restricted
                    ? []
                    : $this->flowGroups($mappedDevices),
                'maintenanceRecords' => $restricted || ! $canViewMaintenance
                    ? []
                    : $this->maintenanceRecords(clone $activeScope),
            ],
        ];
    }

    private function restricted(User $viewer, array $tab): bool
    {
        if (isset($tab['requiredPermission'])
            && ! $viewer->canDo($tab['requiredPermission'])) {
            return true;
        }

        return isset($tab['requiredAnyPermission'])
            && ! collect($tab['requiredAnyPermission'])->contains(
                fn (string $permission): bool => $viewer->canDo($permission),
            );
    }

    private function activeScope(Builder $scope, string $tab): Builder
    {
        return match ($tab) {
            'client-devices' => $scope->whereHas('assignments', fn (Builder $assignment) => $assignment
                ->active()
                ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)),
            'shared-site-devices' => $scope->whereHas('assignments', fn (Builder $assignment) => $assignment
                ->active()
                ->whereIn('assignable_type', [
                    DeviceAssignment::TARGET_SITE,
                    DeviceAssignment::TARGET_ROOM,
                ])),
            default => $scope,
        };
    }

    private function overview(Builder $scope, bool $canViewMaintenance): array
    {
        $total = (clone $scope)->count();
        $clientAssigned = (clone $scope)->whereHas('assignments', fn (Builder $assignment) => $assignment
            ->active()
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT))->count();
        $sharedSite = (clone $scope)->whereHas('assignments', fn (Builder $assignment) => $assignment
            ->active()
            ->whereIn('assignable_type', [
                DeviceAssignment::TARGET_SITE,
                DeviceAssignment::TARGET_ROOM,
            ]))->count();
        $unassigned = (clone $scope)->whereDoesntHave(
            'assignments',
            fn (Builder $assignment) => $assignment->active(),
        )->count();
        $offline = (clone $scope)->where('status', DeviceStatus::Offline->value)->count();
        $flowCounts = $this->flowCounts(clone $scope);
        $dataFlowIssues = collect($flowCounts)
            // Offline is already a separate operational action. Keep this
            // count focused on delivery/integration evidence so a device is
            // not presented twice in the overview totals.
            ->except(['healthy', 'offline'])
            ->sum();
        $deviceIds = (clone $scope)->select('devices.id');
        $overdueCalibration = $canViewMaintenance
            ? DeviceMaintenanceRecord::query()
                ->whereIn('device_id', clone $deviceIds)
                ->where('type', 'calibration')
                ->overdue()
                ->count()
            : null;
        $maintenanceDue = $canViewMaintenance
            ? DeviceMaintenanceRecord::query()
                ->whereIn('device_id', clone $deviceIds)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->count()
            : null;

        return [
            'inventory' => [
                'total' => $total,
                'client_assigned' => $clientAssigned,
                'shared_site' => $sharedSite,
                'unassigned' => $unassigned,
            ],
            'attention' => [
                'offline' => $offline,
                'data_flow_issues' => $dataFlowIssues,
                'overdue_calibration' => $overdueCalibration,
                'maintenance_due' => $maintenanceDue,
            ],
            'requiredActions' => collect([
                $this->action(
                    'offline_devices',
                    'Offline healthcare devices',
                    $offline,
                    'Investigate devices whose canonical state is offline.',
                    '/security-devices/devices?domain=iot_healthcare&status=offline',
                ),
                $this->action(
                    'data_flow_issues',
                    'Connectivity and delivery issues',
                    $dataFlowIssues,
                    'Separate integration failures, stale delivery, and unsupported monitoring before clinical teams rely on the service.',
                    '/security-devices/healthcare?tab=data-flow',
                ),
                $this->action(
                    'overdue_calibration',
                    'Overdue calibration',
                    $overdueCalibration,
                    'Complete overdue calibration recorded in the canonical maintenance history.',
                    '/security-devices/maintenance?status=overdue&type=calibration&domain=iot_healthcare',
                ),
                $this->action(
                    'maintenance_due',
                    'Open maintenance work',
                    $maintenanceDue,
                    'Review scheduled and in-progress technical maintenance.',
                    '/security-devices/maintenance?status=open&domain=iot_healthcare',
                ),
            ])->filter()->values(),
        ];
    }

    private function action(
        string $key,
        string $label,
        ?int $count,
        string $description,
        string $href,
    ): ?array {
        if ($count === null) {
            return null;
        }

        return compact('key', 'label', 'count', 'description', 'href');
    }

    /** @return array<string, int> */
    private function flowCounts(Builder $scope): array
    {
        $counts = collect(self::FLOW_ORDER)->mapWithKeys(fn (string $state) => [$state => 0])->all();

        (clone $scope)
            ->select(['devices.id', 'devices.status', 'devices.config', 'devices.meta'])
            ->withCount([
                'monitors as enabled_monitors_count' => fn (Builder $monitor) => $monitor->where('is_enabled', true),
            ])
            ->chunkById(500, function (Collection $devices) use (&$counts): void {
                foreach ($devices as $device) {
                    $state = $this->flowState($device)['state'];
                    $counts[$state]++;
                }
            }, 'devices.id', 'id');

        return $counts;
    }

    /** @param Collection<int, Device> $devices */
    private function assignmentContext(User $viewer, Collection $devices): array
    {
        $assignments = $devices->flatMap->assignments;
        $clientIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->pluck('assignable_id')
            ->unique();
        $siteIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_SITE)
            ->pluck('assignable_id')
            ->unique();
        $roomIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_ROOM)
            ->pluck('assignable_id')
            ->unique();

        $clients = $clientIds->isEmpty()
            ? collect()
            : Client::query()
                ->whereIn('id', $clientIds)
                ->with('keyWorker:id,name')
                ->get(['id', 'site_id', 'first_name', 'preferred_name', 'key_worker_id'])
                ->filter(fn (Client $client): bool => Gate::forUser($viewer)->allows('view', $client))
                ->keyBy('id');
        $sites = $siteIds->isEmpty()
            ? collect()
            : Site::query()
                ->whereIn('id', $siteIds)
                ->with('primaryContact:id,name')
                ->get(['id', 'name', 'type', 'primary_contact_user_id'])
                ->keyBy('id');
        $rooms = $roomIds->isEmpty()
            ? collect()
            : SiteRoom::query()
                ->whereIn('id', $roomIds)
                ->with('site.primaryContact:id,name')
                ->get()
                ->keyBy('id');

        return compact('clients', 'sites', 'rooms');
    }

    /** @param Collection<int, int> $deviceIds @return Collection<int, Collection<int, ItTicketLink>> */
    private function ticketMap(User $viewer, Collection $deviceIds): Collection
    {
        if ($deviceIds->isEmpty()) {
            return collect();
        }

        return ItTicketLink::query()
            ->where('relationship', 'affected_device')
            ->where('linkable_type', Device::class)
            ->whereIn('linkable_id', $deviceIds)
            ->with('ticket:id,reference,title,status')
            ->latest('id')
            ->get()
            ->filter(fn (ItTicketLink $link): bool => $link->ticket !== null
                && Gate::forUser($viewer)->allows('view', $link->ticket))
            ->groupBy('linkable_id');
    }

    private function mapDevice(
        User $viewer,
        Device $device,
        array $context,
        Collection $ticketMap,
        bool $canViewMaintenance,
    ): array {
        $assignment = $device->assignments->first();
        $client = $assignment?->assignable_type === DeviceAssignment::TARGET_CLIENT
            ? $context['clients']->get($assignment->assignable_id)
            : null;
        $site = $this->siteForAssignment($assignment, $context);
        $room = $assignment?->assignable_type === DeviceAssignment::TARGET_ROOM
            ? $context['rooms']->get($assignment->assignable_id)
            : null;
        $canViewSiteProfile = $site !== null
            && Gate::forUser($viewer)->allows('view', $site);
        $openMaintenance = $canViewMaintenance ? $device->maintenanceRecords : collect();

        return [
            'id' => $device->id,
            'name' => $device->name,
            'category' => $device->category,
            'subcategory' => $device->subcategory,
            'provider' => $device->provider,
            'status' => $device->status?->value,
            'health' => $device->health_status?->value,
            'lastSeenAt' => $device->last_seen_at?->toISOString(),
            'deviceHref' => "/security-devices/devices/{$device->id}",
            'client' => $assignment?->assignable_type === DeviceAssignment::TARGET_CLIENT ? [
                'id' => $client?->id,
                'displayName' => $client
                    ? ($client->preferred_name ?: $client->first_name)
                    : 'Assigned client',
                'href' => $client
                    ? "/operations/clients/{$client->id}?tab=healthcare_devices"
                    : null,
                'access' => [
                    'state' => $client ? 'available' : 'restricted',
                    'label' => $client
                        ? 'Open Client Profile healthcare devices'
                        : 'Client Profile access required',
                ],
            ] : null,
            'location' => $site ? [
                'site' => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'href' => $canViewSiteProfile ? "/sites/{$site->id}" : null,
                    'access' => [
                        'state' => $canViewSiteProfile ? 'available' : 'restricted',
                        'label' => $canViewSiteProfile
                            ? 'Open Site profile'
                            : 'Site profile access required',
                    ],
                ],
                'room' => $room ? [
                    'id' => $room->id,
                    'name' => $room->name,
                ] : null,
            ] : null,
            'assignment' => $assignment ? [
                'type' => $assignment->assignable_type,
                'assignmentType' => $assignment->assignment_type?->value,
                'label' => $this->assignmentLabel($assignment, $client, $site, $room),
                'assignedAt' => $assignment->assigned_at?->toISOString(),
            ] : null,
            'supportContact' => $this->supportContact($client, $site),
            'technical' => $this->technicalState($device),
            'monitoring' => [
                'state' => $device->monitors->isEmpty() ? 'unsupported_or_not_configured' : 'configured',
                'enabledCount' => $device->monitors->count(),
            ],
            'maintenance' => $canViewMaintenance ? [
                'nextServiceDue' => $device->next_service_due?->toIso8601String(),
                'openCount' => $openMaintenance->count(),
                'overdueCount' => $openMaintenance->filter(fn (DeviceMaintenanceRecord $record) => $record->status === 'scheduled'
                    && $record->scheduled_for?->lt(today()))->count(),
                'next' => $openMaintenance->first()
                    ? $this->mapMaintenance($openMaintenance->first())
                    : null,
            ] : null,
            'itTickets' => collect($ticketMap->get($device->id, collect()))
                ->take(5)
                ->map(fn (ItTicketLink $link): array => [
                    'id' => $link->ticket->id,
                    'reference' => $link->ticket->reference,
                    'title' => $link->ticket->title,
                    'status' => $link->ticket->status,
                    'href' => "/it/tickets/{$link->ticket->id}",
                ])
                ->values(),
        ];
    }

    private function siteForAssignment(?DeviceAssignment $assignment, array $context): ?Site
    {
        if (! $assignment) {
            return null;
        }

        return match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_SITE => $context['sites']->get($assignment->assignable_id),
            DeviceAssignment::TARGET_ROOM => $context['rooms']->get($assignment->assignable_id)?->site,
            default => null,
        };
    }

    private function assignmentLabel(
        DeviceAssignment $assignment,
        ?Client $client,
        ?Site $site,
        ?SiteRoom $room,
    ): string {
        return match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_CLIENT => 'Assigned to '.($client?->preferred_name ?: $client?->first_name ?: 'authorised client'),
            DeviceAssignment::TARGET_SITE => Str::headline($assignment->assignment_type?->value ?? 'assigned').' at '.($site?->name ?? "site #{$assignment->assignable_id}"),
            DeviceAssignment::TARGET_ROOM => Str::headline($assignment->assignment_type?->value ?? 'assigned').' in '.($room?->name ?? "room #{$assignment->assignable_id}"),
            default => Str::headline($assignment->assignable_type)." #{$assignment->assignable_id}",
        };
    }

    private function supportContact(?Client $client, ?Site $site): ?array
    {
        if ($client?->keyWorker) {
            return [
                'name' => $client->keyWorker->name,
                'role' => 'key worker',
            ];
        }

        if ($site?->primaryContact) {
            return [
                'name' => $site->primaryContact->name,
                'role' => 'site primary contact',
            ];
        }

        return null;
    }

    private function technicalState(Device $device): array
    {
        $connectivity = $this->normalisedTechnicalValue($device, 'connectivity_state');
        $integration = $this->normalisedTechnicalValue($device, 'integration_state');
        $lastDelivery = $this->technicalDate($device, 'last_successful_delivery_at')
            ?? $this->technicalDate($device, 'last_delivery_at');
        $staleAfterMinutes = $this->staleAfterMinutes($device);
        $deliveryState = $lastDelivery === null
            ? 'not_observed'
            : ($lastDelivery->lt(now()->subMinutes($staleAfterMinutes)) ? 'stale' : 'fresh');

        return [
            'battery' => [
                'level' => $device->battery_level,
                'updatedAt' => $device->battery_updated_at?->toISOString(),
                'state' => $device->battery_level === null
                    ? 'unknown'
                    : ($device->battery_level <= 20 ? 'low' : 'ok'),
            ],
            'connectivity' => [
                'state' => $connectivity
                    ?? ($device->status === DeviceStatus::Offline ? 'offline' : 'unknown'),
                'source' => $connectivity === null ? 'canonical_device_state' : 'allowlisted_integration_evidence',
            ],
            'integration' => [
                'state' => $integration ?? 'not_configured',
                'source' => $integration === null ? 'not_observed' : 'allowlisted_integration_evidence',
            ],
            'delivery' => [
                'state' => $deliveryState,
                'lastSuccessfulAt' => $lastDelivery?->toISOString(),
                'staleAfterMinutes' => $staleAfterMinutes,
            ],
            'flow' => $this->flowState($device),
        ];
    }

    private function flowState(Device $device): array
    {
        $connectivity = $this->normalisedTechnicalValue($device, 'connectivity_state');
        $integration = $this->normalisedTechnicalValue($device, 'integration_state');
        $lastDelivery = $this->technicalDate($device, 'last_successful_delivery_at')
            ?? $this->technicalDate($device, 'last_delivery_at');
        $enabledMonitors = (int) ($device->enabled_monitors_count
            ?? ($device->relationLoaded('monitors') ? $device->monitors->count() : 0));

        if ($device->status === DeviceStatus::Offline
            || in_array($connectivity, ['offline', 'disconnected', 'unreachable'], true)) {
            return $this->flow('offline', 'Offline', 'The canonical device or connectivity state is offline.');
        }

        if (in_array($integration, ['failed', 'error', 'degraded'], true)) {
            return $this->flow('integration_failure', 'Integration failure', 'The integration reports a failed or degraded technical state.');
        }

        if ($lastDelivery?->lt(now()->subMinutes($this->staleAfterMinutes($device)))) {
            return $this->flow('stale_delivery', 'Stale delivery', 'The last successful delivery is older than the configured freshness window.');
        }

        if ($connectivity === null && $integration === null && $lastDelivery === null && $enabledMonitors === 0) {
            return $this->flow('unsupported', 'Monitoring unsupported', 'No supported technical monitoring or delivery evidence is configured.');
        }

        if (in_array($connectivity, ['connected', 'online', 'healthy'], true)
            && in_array($integration, ['healthy', 'connected', 'ok'], true)
            && $lastDelivery !== null) {
            return $this->flow('healthy', 'Healthy flow', 'Connectivity, integration, and delivery freshness all have positive evidence.');
        }

        return $this->flow('unknown', 'Unknown', 'Available evidence is not sufficient to call the data flow healthy.');
    }

    private function flow(string $state, string $label, string $description): array
    {
        return compact('state', 'label', 'description');
    }

    private function flowGroups(Collection $devices): Collection
    {
        return collect(self::FLOW_ORDER)
            ->map(function (string $state) use ($devices): array {
                $matching = $devices->filter(
                    fn (array $device): bool => $device['technical']['flow']['state'] === $state,
                );
                $definition = $matching->first()['technical']['flow'] ?? $this->flow(
                    $state,
                    Str::headline($state),
                    'No devices currently have this state.',
                );

                return [
                    ...$definition,
                    'count' => $matching->count(),
                    'deviceIds' => $matching->pluck('id')->values(),
                ];
            })
            ->filter(fn (array $group): bool => $group['count'] > 0)
            ->values();
    }

    private function maintenanceRecords(Builder $scope): Collection
    {
        return DeviceMaintenanceRecord::query()
            ->whereIn('device_id', (clone $scope)->select('devices.id'))
            ->with('device:id,name')
            ->orderByRaw("CASE WHEN status IN ('scheduled', 'in_progress') THEN 0 ELSE 1 END")
            ->orderByRaw('scheduled_for IS NULL')
            ->orderBy('scheduled_for')
            ->orderByDesc('completed_at')
            ->limit(self::MAINTENANCE_LIMIT)
            ->get()
            ->map(fn (DeviceMaintenanceRecord $record) => $this->mapMaintenance($record));
    }

    private function mapMaintenance(DeviceMaintenanceRecord $record): array
    {
        return [
            'id' => $record->id,
            'type' => $record->type,
            'status' => $record->status,
            'description' => $record->description,
            'scheduledFor' => $record->scheduled_for?->toIso8601String(),
            'completedAt' => $record->completed_at?->toISOString(),
            'vendorReference' => $record->vendor_reference,
            'overdue' => $record->status === 'scheduled'
                && $record->scheduled_for?->lt(today()),
            'device' => $record->device ? [
                'id' => $record->device->id,
                'name' => $record->device->name,
                'href' => "/security-devices/devices/{$record->device->id}",
            ] : null,
        ];
    }

    private function normalisedTechnicalValue(Device $device, string $key): ?string
    {
        $value = $this->technicalValue($device, $key);

        return is_string($value) && $value !== ''
            ? Str::of($value)->lower()->replace([' ', '-'], '_')->toString()
            : null;
    }

    private function technicalDate(Device $device, string $key): ?Carbon
    {
        $value = $this->technicalValue($device, $key);
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function staleAfterMinutes(Device $device): int
    {
        $value = $this->technicalValue($device, 'delivery_stale_after_minutes');

        return is_numeric($value)
            ? max(5, min(10_080, (int) $value))
            : 60;
    }

    private function technicalValue(Device $device, string $key): mixed
    {
        foreach ([$device->config ?? [], $device->meta ?? []] as $source) {
            foreach (["technical.{$key}", "monitoring.{$key}", $key] as $path) {
                $value = data_get($source, $path);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }
}
