<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Models\Integration\Integration;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class FacilitiesWorkspacePresenter
{
    private const DEVICE_LIMIT = 100;

    private const FRESH_AFTER_HOURS = 6;

    private const ENVIRONMENT_CATEGORIES = [
        'leak_detection',
        'gas_detection',
        'cold_chain',
        'environmental',
    ];

    private const UTILITY_SUBCATEGORIES = [
        'generator_monitor',
        'pump_monitor',
        'electricity_meter',
        'water_meter',
        'gas_meter',
    ];

    private const SAFE_INTEGRATION_CAPABILITIES = [
        'environmental',
        'event_stream',
        'gateway_management',
        'iot',
        'telemetry',
        'utility_metering',
        'building_systems',
        'automation_status',
    ];

    /** @return array<string, mixed> */
    public function present(
        User $viewer,
        Builder $facilitiesScope,
        array $activeTab,
        array $filters = [],
    ): array {
        $permissions = [
            'events' => $viewer->canDo('securityDevices.events.view'),
            'maintenance' => $viewer->canDo('securityDevices.maintenance.view'),
            'integrations' => $viewer->canDo('securityDevices.integrations.view'),
            'export' => $viewer->canDo('securityDevices.events.view')
                && $viewer->canDo('securityDevices.reports.view'),
        ];
        $candidates = (clone $facilitiesScope)
            ->with([
                'assignments' => fn ($query) => $query->active(),
                'monitors' => fn ($query) => $query->orderBy('name'),
            ])
            ->orderBy('name')
            ->limit(self::DEVICE_LIMIT + 1)
            ->get();
        $inventoryTruncated = $candidates->count() > self::DEVICE_LIMIT;
        $devices = $candidates->take(self::DEVICE_LIMIT)->values();
        $deviceIds = $devices->pluck('id');
        $monitorIds = $devices->flatMap->monitors->pluck('id');
        $latestObservations = $this->latestObservations($viewer, $monitorIds);
        $siteContext = $this->siteContext($devices);
        $events = $permissions['events'] ? $this->events($deviceIds) : collect();
        $maintenance = $permissions['maintenance'] ? $this->maintenance($deviceIds) : collect();
        [$integrations, $syncs] = $permissions['integrations']
            ? $this->integrationContext($viewer, $devices)
            : [collect(), collect()];
        $mapped = $devices
            ->map(fn (Device $device): array => $this->mapDevice(
                $device,
                $siteContext,
                $latestObservations,
                $events,
                $maintenance,
                $integrations,
                $syncs,
                $permissions,
            ))
            ->values();
        $groups = [
            'environment' => $mapped->where('group', 'environment')->values(),
            'buildingSystems' => $mapped->where('group', 'building_systems')->values(),
            'utilities' => $mapped->where('group', 'utilities')->values(),
            'automations' => $mapped->where('group', 'automations')->values(),
        ];
        $history = $activeTab['key'] === 'history'
            ? $this->history($viewer, $devices, $events, $filters, $permissions)
            : $this->emptyHistory($filters, $permissions);

        return [
            'permissions' => $permissions,
            'boundary' => [
                'title' => 'Technical facilities evidence, not building control',
                'description' => 'Oblivion Findings reconciles canonical facility devices, retained observations, append-only events, maintenance references, and explicitly supported integration evidence.',
                'evidenceNote' => 'Missing sensor values, monitor coverage, integration state, or automation execution stays visible as not collected, unmonitored, not configured, or not observed.',
                'managementNote' => 'Building and utility controls remain read-only until governed device-command capabilities, approval, execution, and reconciliation are implemented.',
            ],
            'overview' => $this->overview($mapped, $events, $maintenance, $permissions),
            'activeTab' => [
                'key' => $activeTab['key'],
                'label' => $activeTab['label'],
                'description' => $activeTab['description'],
                'inventoryTruncated' => $inventoryTruncated,
                'devices' => in_array($activeTab['key'], ['overview'], true) ? $mapped : collect(),
                'environment' => $activeTab['key'] === 'environment' ? $groups['environment'] : collect(),
                'buildingSystems' => $activeTab['key'] === 'building-systems' ? $groups['buildingSystems'] : collect(),
                'utilities' => $activeTab['key'] === 'utilities' ? $groups['utilities'] : collect(),
                'automations' => $activeTab['key'] === 'automations' ? $groups['automations'] : collect(),
                'history' => $history,
                'gaps' => [
                    'environmentWithoutReadings' => $groups['environment']->where('freshness.state', 'not_collected')->count(),
                    'buildingSystemsUnmonitored' => $groups['buildingSystems']->where('unmonitored', true)->count(),
                    'utilitiesWithoutIntegrations' => $groups['utilities']->where('integration.state', 'not_configured')->count(),
                    'automationsWithoutExecutionEvidence' => $groups['automations']->where('automation.status', 'not_observed')->count(),
                ],
            ],
        ];
    }

    /** @param Collection<int, int> $monitorIds @return Collection<int, MonitorObservation> */
    private function latestObservations(User $viewer, Collection $monitorIds): Collection
    {
        if ($monitorIds->isEmpty()) {
            return collect();
        }

        $ids = MonitorObservation::query()
            ->forTenant((int) ($viewer->organization_id ?? 1))
            ->whereIn('monitor_id', $monitorIds)
            ->selectRaw('MAX(id)')
            ->groupBy('monitor_id');

        return MonitorObservation::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('monitor_id');
    }

    /** @param Collection<int, Device> $devices @return array<string, Collection> */
    private function siteContext(Collection $devices): array
    {
        $assignments = $devices->flatMap->assignments;
        $siteIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_SITE)
            ->pluck('assignable_id')
            ->unique();
        $roomIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_ROOM)
            ->pluck('assignable_id')
            ->unique();
        $sites = $siteIds->isEmpty()
            ? collect()
            : Site::query()->whereIn('id', $siteIds)->get(['id', 'name'])->keyBy('id');
        $rooms = $roomIds->isEmpty()
            ? collect()
            : SiteRoom::query()->whereIn('id', $roomIds)->with('site:id,name')->get()->keyBy('id');

        return compact('sites', 'rooms');
    }

    /** @param Collection<int, int> $deviceIds @return Collection<int, DeviceEvent> */
    private function events(Collection $deviceIds): Collection
    {
        return $deviceIds->isEmpty()
            ? collect()
            : DeviceEvent::query()
                ->whereIn('device_id', $deviceIds)
                ->latest('occurred_at')
                ->limit(250)
                ->get();
    }

    /** @param Collection<int, int> $deviceIds @return Collection<int, DeviceMaintenanceRecord> */
    private function maintenance(Collection $deviceIds): Collection
    {
        return $deviceIds->isEmpty()
            ? collect()
            : DeviceMaintenanceRecord::query()
                ->whereIn('device_id', $deviceIds)
                ->latest('scheduled_for')
                ->get();
    }

    /** @param Collection<int, Device> $devices @return array{0: Collection, 1: Collection} */
    private function integrationContext(User $viewer, Collection $devices): array
    {
        $providers = $devices->pluck('provider')->filter()->unique()->values();
        if ($providers->isEmpty()) {
            return [collect(), collect()];
        }

        $tenantId = (int) ($viewer->organization_id ?? 1);
        $integrations = Integration::query()
            ->forTenant($tenantId)
            ->whereIn('provider', $providers)
            ->get(['id', 'provider', 'display_name', 'status', 'capabilities', 'last_tested_at'])
            ->keyBy('provider');
        $syncs = IntegrationSyncLog::query()
            ->forTenant($tenantId)
            ->whereIn('provider', $providers)
            ->latest('completed_at')
            ->latest('id')
            ->get([
                'id',
                'provider',
                'action',
                'status',
                'items_processed',
                'completed_at',
            ])
            ->unique('provider')
            ->keyBy('provider');

        return [$integrations, $syncs];
    }

    /** @param array<string, Collection> $siteContext @param array<string, bool> $permissions */
    private function mapDevice(
        Device $device,
        array $siteContext,
        Collection $latestObservations,
        Collection $events,
        Collection $maintenance,
        Collection $integrations,
        Collection $syncs,
        array $permissions,
    ): array {
        $site = $this->siteForDevice($device, $siteContext);
        $deviceEvents = $events->where('device_id', $device->id)->values();
        $deviceMaintenance = $maintenance->where('device_id', $device->id)->values();
        $observation = $device->monitors
            ->map(fn (Monitor $monitor) => $latestObservations->get($monitor->id))
            ->filter()
            ->sortByDesc(fn (MonitorObservation $item) => $item->observed_at?->timestamp ?? 0)
            ->first();
        $enabledMonitors = $device->monitors->where('is_enabled', true);
        $attentionMonitors = $enabledMonitors->whereIn('current_state', [
            MonitorState::Failed,
            MonitorState::Degraded,
        ]);
        $thresholdEvent = $deviceEvents->first(fn (DeviceEvent $event): bool => $this->isThresholdEvent($event));
        $integration = $permissions['integrations']
            ? $this->mapIntegration($device, $integrations, $syncs)
            : null;

        return [
            'id' => $device->id,
            'name' => $device->name,
            'href' => "/security-devices/devices/{$device->id}",
            'group' => $this->group($device),
            'category' => $device->category,
            'categoryLabel' => $this->label($device->category),
            'subcategory' => $device->subcategory,
            'subcategoryLabel' => $this->label($device->subcategory),
            'status' => $device->status?->value,
            'health' => $device->health_status?->value,
            'technicalState' => $this->technicalState($device, $enabledMonitors),
            'site' => $site ? [
                'id' => $site->id,
                'name' => $site->name,
                'href' => "/security-devices/sites/{$site->id}",
            ] : null,
            'provider' => $device->provider,
            'monitoring' => [
                'enabled' => $enabledMonitors->count(),
                'attention' => $attentionMonitors->count(),
                'uncertain' => $enabledMonitors->whereIn('current_state', [
                    MonitorState::Unknown,
                    MonitorState::Stale,
                    MonitorState::Pending,
                ])->count(),
            ],
            'unmonitored' => $enabledMonitors->isEmpty(),
            'observation' => $this->mapObservation($observation),
            'freshness' => $this->freshness($observation),
            'thresholdEvent' => $thresholdEvent ? $this->mapEvent($thresholdEvent) : null,
            'activeEventCount' => $permissions['events']
                ? $deviceEvents->whereNull('processed_at')->count()
                : null,
            'maintenance' => $permissions['maintenance']
                ? $this->maintenanceSummary($device, $deviceMaintenance)
                : null,
            'integration' => $integration,
            'automation' => $this->automationEvidence($device),
        ];
    }

    /** @param array<string, Collection> $context */
    private function siteForDevice(Device $device, array $context): ?Site
    {
        $assignment = $device->assignments->first(fn (DeviceAssignment $candidate): bool => in_array(
            $candidate->assignable_type,
            [DeviceAssignment::TARGET_SITE, DeviceAssignment::TARGET_ROOM],
            true,
        ));
        if (! $assignment) {
            return null;
        }

        return $assignment->assignable_type === DeviceAssignment::TARGET_SITE
            ? $context['sites']->get($assignment->assignable_id)
            : $context['rooms']->get($assignment->assignable_id)?->site;
    }

    private function group(Device $device): string
    {
        if (in_array($device->category, self::ENVIRONMENT_CATEGORIES, true)) {
            return 'environment';
        }
        if ($device->category === 'utilities'
            || in_array($device->subcategory, self::UTILITY_SUBCATEGORIES, true)) {
            return 'utilities';
        }
        if ($device->subcategory === 'smart_relay'
            || is_array(data_get($device->meta ?? [], 'automation'))
            || is_array(data_get($device->config ?? [], 'automation'))) {
            return 'automations';
        }

        return 'building_systems';
    }

    private function technicalState(Device $device, Collection $enabledMonitors): string
    {
        if ($device->status === DeviceStatus::Offline) {
            return 'offline';
        }
        if ($enabledMonitors->where('current_state', MonitorState::Failed)->isNotEmpty()) {
            return 'failed';
        }
        if ($device->status === DeviceStatus::Degraded
            || $enabledMonitors->where('current_state', MonitorState::Degraded)->isNotEmpty()) {
            return 'degraded';
        }
        if ($enabledMonitors->isEmpty()) {
            return 'unmonitored';
        }

        return $device->health_status?->value ?? 'unknown';
    }

    /** @return array<string, mixed>|null */
    private function mapObservation(?MonitorObservation $observation): ?array
    {
        if (! $observation) {
            return null;
        }

        return [
            'value' => $this->decimal($observation->value),
            'unit' => $observation->unit,
            'state' => $observation->state?->value,
            'observedAt' => $observation->observed_at?->toISOString(),
            'source' => 'retained_native_observation',
        ];
    }

    /** @return array{state: string, observedAt: ?string} */
    private function freshness(?MonitorObservation $observation): array
    {
        if (! $observation?->observed_at) {
            return ['state' => 'not_collected', 'observedAt' => null];
        }

        $state = $observation->state === MonitorState::Stale
            || $observation->observed_at->lt(now()->subHours(self::FRESH_AFTER_HOURS))
                ? 'stale'
                : 'fresh';

        return ['state' => $state, 'observedAt' => $observation->observed_at->toISOString()];
    }

    private function isThresholdEvent(DeviceEvent $event): bool
    {
        return Str::contains(Str::lower($event->event_type), [
            'threshold',
            'temperature',
            'humidity',
            'air_quality',
            'leak',
            'gas',
            'cold_chain',
        ]);
    }

    /** @return array<string, mixed> */
    private function mapEvent(DeviceEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->event_type,
            'label' => $this->label($event->event_type),
            'severity' => $event->severity,
            'source' => $event->source,
            'occurredAt' => $event->occurred_at?->toISOString(),
            'processed' => $event->processed_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function maintenanceSummary(Device $device, Collection $records): array
    {
        $open = $records->whereNotIn('status', ['completed', 'cancelled']);
        $next = $open
            ->filter(fn (DeviceMaintenanceRecord $record): bool => $record->scheduled_for !== null)
            ->sortBy('scheduled_for')
            ->first();

        return [
            'openCount' => $open->count(),
            'overdueCount' => $open->filter(fn (DeviceMaintenanceRecord $record): bool => $record->scheduled_for?->lt(today()) ?? false)->count(),
            'nextDue' => $next?->scheduled_for?->toDateString(),
            'href' => "/security-devices/maintenance?device_id={$device->id}",
            'source' => 'canonical_device_maintenance',
        ];
    }

    /** @return array<string, mixed> */
    private function mapIntegration(Device $device, Collection $integrations, Collection $syncs): array
    {
        $provider = $device->provider;
        $integration = $provider ? $integrations->get($provider) : null;
        $sync = $provider ? $syncs->get($provider) : null;

        if (! $integration) {
            return [
                'provider' => $provider,
                'name' => $provider ? Str::headline($provider) : null,
                'state' => 'not_configured',
                'capabilities' => [],
                'lastTestedAt' => null,
                'lastSync' => null,
            ];
        }

        return [
            'provider' => $integration->provider,
            'name' => $integration->display_name,
            'state' => $integration->status,
            'capabilities' => collect($integration->capabilities ?? [])
                ->filter(fn ($capability): bool => is_string($capability)
                    && in_array($capability, self::SAFE_INTEGRATION_CAPABILITIES, true))
                ->values()
                ->all(),
            'lastTestedAt' => $integration->last_tested_at?->toISOString(),
            'lastSync' => $sync ? [
                'action' => $sync->action,
                'status' => $sync->status,
                'itemsProcessed' => $sync->items_processed,
                'completedAt' => $sync->completed_at?->toISOString(),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function automationEvidence(Device $device): array
    {
        $evidence = data_get($device->meta ?? [], 'automation');
        if (! is_array($evidence)) {
            $evidence = data_get($device->config ?? [], 'automation');
        }
        if (! is_array($evidence)) {
            return [
                'name' => null,
                'enabled' => null,
                'status' => 'not_observed',
                'lastExecutedAt' => null,
                'source' => 'no_canonical_execution_evidence',
            ];
        }

        $name = data_get($evidence, 'name');
        $status = Str::lower((string) data_get($evidence, 'status', 'not_observed'));
        $lastExecutedAt = $this->date(data_get($evidence, 'last_executed_at'));

        return [
            'name' => is_scalar($name) ? (string) $name : null,
            'enabled' => array_key_exists('enabled', $evidence) ? (bool) $evidence['enabled'] : null,
            'status' => in_array($status, [
                'success',
                'failed',
                'partial',
                'running',
                'pending',
                'disabled',
                'not_observed',
            ], true) ? $status : 'unknown',
            'lastExecutedAt' => $lastExecutedAt,
            'source' => 'allowlisted_device_automation_evidence',
        ];
    }

    /** @param array<string, bool> $permissions @return array<string, mixed> */
    private function overview(
        Collection $devices,
        Collection $events,
        Collection $maintenance,
        array $permissions,
    ): array {
        $activeEvents = $events->whereNull('processed_at');
        $overdue = $maintenance->filter(fn (DeviceMaintenanceRecord $record): bool => $record->status === 'scheduled'
            && ($record->scheduled_for?->lt(today()) ?? false));
        $sites = $devices
            ->pluck('site')
            ->filter()
            ->unique('id')
            ->map(function (array $site) use ($devices, $permissions): array {
                $siteDevices = $devices->filter(fn (array $device): bool => ($device['site']['id'] ?? null) === $site['id']);

                return [
                    'id' => $site['id'],
                    'name' => $site['name'],
                    'href' => $site['href'],
                    'devices' => $siteDevices->count(),
                    'attention' => $siteDevices->whereIn('technicalState', ['offline', 'failed', 'degraded'])->count(),
                    'activeEvents' => $permissions['events'] ? $siteDevices->sum('activeEventCount') : null,
                ];
            })
            ->values();
        $monitoringAttention = $devices->sum('monitoring.attention');
        $unmonitored = $devices->where('unmonitored', true)->count();
        $stale = $devices->where('freshness.state', 'stale')->count();
        $integrationAttention = $permissions['integrations']
            ? $devices->filter(fn (array $device): bool => in_array(
                data_get($device, 'integration.state'),
                [Integration::STATUS_ERROR, Integration::STATUS_INACTIVE],
                true,
            ))->count()
            : null;

        return [
            'inventory' => [
                'devices' => $devices->count(),
                'environment' => $devices->where('group', 'environment')->count(),
                'building_systems' => $devices->where('group', 'building_systems')->count(),
                'utilities' => $devices->where('group', 'utilities')->count(),
                'automations' => $devices->where('group', 'automations')->count(),
                'sites' => $sites->count(),
            ],
            'attention' => [
                'devices' => $devices->whereIn('technicalState', ['offline', 'failed', 'degraded'])->count(),
                'monitoring' => $monitoringAttention,
                'active_events' => $permissions['events'] ? $activeEvents->count() : null,
                'unmonitored' => $unmonitored,
                'stale' => $stale,
                'overdue_maintenance' => $permissions['maintenance'] ? $overdue->count() : null,
                'integration' => $integrationAttention,
            ],
            'freshness' => [
                'fresh' => $devices->where('freshness.state', 'fresh')->count(),
                'stale' => $stale,
                'not_collected' => $devices->where('freshness.state', 'not_collected')->count(),
            ],
            'requiredActions' => collect([
                $this->action('device-attention', 'Facility devices requiring attention', $devices->whereIn('technicalState', ['offline', 'failed', 'degraded'])->count(), 'Review failed, degraded, or offline technical state.', 'overview'),
                $this->action('monitoring', 'Monitor failures or degradation', $monitoringAttention, 'Review failed and degraded native checks.', 'environment'),
                $permissions['events'] ? $this->action('active-events', 'Active facility events', $activeEvents->count(), 'Review unprocessed canonical device events.', 'history') : null,
                $this->action('unmonitored', 'Unmonitored facility devices', $unmonitored, 'Add supported monitoring or record the unsupported gap.', 'overview'),
                $this->action('stale', 'Stale facility evidence', $stale, 'Restore observation freshness before relying on the reading.', 'environment'),
                $permissions['maintenance'] ? $this->action('maintenance', 'Overdue facility maintenance', $overdue->count(), 'Continue work in the canonical maintenance register.', 'building-systems') : null,
            ])->filter(fn (?array $action): bool => $action !== null && $action['count'] > 0)->values(),
            'sites' => $sites,
        ];
    }

    /** @param Collection<int, Device> $devices @param array<string, bool> $permissions @return array<string, mixed> */
    private function history(
        User $viewer,
        Collection $devices,
        Collection $events,
        array $filters,
        array $permissions,
    ): array {
        $normalised = $this->historyFilters($filters, $devices);
        $deviceIds = $devices->pluck('id');
        $filteredEvents = $permissions['events']
            ? $events
                ->when($normalised['deviceId'], fn (Collection $rows, int $id) => $rows->where('device_id', $id))
                ->when($normalised['severity'], fn (Collection $rows, string $severity) => $rows->where('severity', $severity))
                ->when($normalised['eventType'], fn (Collection $rows, string $type) => $rows->where('event_type', $type))
                ->when($normalised['source'], fn (Collection $rows, string $source) => $rows->where('source', $source))
                ->take(50)
                ->map(fn (DeviceEvent $event): array => [
                    ...$this->mapEvent($event),
                    'deviceId' => $event->device_id,
                    'deviceName' => $devices->firstWhere('id', $event->device_id)?->name,
                    'deviceHref' => "/security-devices/devices/{$event->device_id}",
                ])
                ->values()
            : collect();
        $observations = collect();
        if ($normalised['kind'] !== 'events') {
            $monitors = $devices->flatMap->monitors->keyBy('id');
            $deviceNames = $devices->keyBy('id');
            $monitorIds = $normalised['deviceId']
                ? $devices->firstWhere('id', $normalised['deviceId'])?->monitors->pluck('id') ?? collect()
                : $monitors->keys();
            if ($monitorIds->isNotEmpty()) {
                $observations = MonitorObservation::query()
                    ->forTenant((int) ($viewer->organization_id ?? 1))
                    ->whereIn('monitor_id', $monitorIds)
                    ->latest('observed_at')
                    ->limit(50)
                    ->get()
                    ->map(function (MonitorObservation $observation) use ($monitors, $deviceNames): array {
                        $monitor = $monitors->get($observation->monitor_id);

                        return [
                            'id' => $observation->id,
                            'deviceId' => $monitor?->device_id,
                            'deviceName' => $monitor ? $deviceNames->get($monitor->device_id)?->name : null,
                            'deviceHref' => $monitor ? "/security-devices/devices/{$monitor->device_id}" : null,
                            'monitorName' => $monitor?->name,
                            'state' => $observation->state?->value,
                            'value' => $this->decimal($observation->value),
                            'unit' => $observation->unit,
                            'observedAt' => $observation->observed_at?->toISOString(),
                            'source' => 'retained_native_observation',
                        ];
                    })
                    ->values();
            }
        }
        if ($normalised['kind'] === 'observations') {
            $filteredEvents = collect();
        }

        return [
            'events' => $filteredEvents,
            'observations' => $observations,
            'filters' => [
                'kind' => $normalised['kind'],
                'deviceId' => $normalised['deviceId'],
                'severity' => $normalised['severity'],
                'eventType' => $normalised['eventType'],
                'source' => $normalised['source'],
            ],
            'filterOptions' => [
                'devices' => $devices->map(fn (Device $device): array => ['value' => $device->id, 'label' => $device->name])->values(),
                'severities' => $permissions['events'] ? $events->pluck('severity')->filter()->unique()->sort()->values() : [],
                'eventTypes' => $permissions['events'] ? $events->pluck('event_type')->filter()->unique()->sort()->values() : [],
                'sources' => $permissions['events'] ? $events->pluck('source')->filter()->unique()->sort()->values() : [],
            ],
            'exportHref' => $permissions['export']
                ? '/security-devices/reports/events.csv?'.http_build_query(array_filter([
                    'domain' => 'facilities',
                    'device_id' => $normalised['deviceId'],
                    'severity' => $normalised['severity'],
                    'event_type' => $normalised['eventType'],
                    'source' => $normalised['source'],
                ], fn ($value): bool => $value !== null && $value !== ''))
                : null,
            'eventAccessRestricted' => ! $permissions['events'],
            'deviceCount' => $deviceIds->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyHistory(array $filters, array $permissions): array
    {
        return [
            'events' => [],
            'observations' => [],
            'filters' => [
                'kind' => $filters['history_kind'] ?? 'all',
                'deviceId' => null,
                'severity' => $filters['severity'] ?? null,
                'eventType' => $filters['event_type'] ?? null,
                'source' => $filters['source'] ?? null,
            ],
            'filterOptions' => [
                'devices' => [],
                'severities' => [],
                'eventTypes' => [],
                'sources' => [],
            ],
            'exportHref' => null,
            'eventAccessRestricted' => ! $permissions['events'],
            'deviceCount' => 0,
        ];
    }

    /** @param Collection<int, Device> $devices @return array<string, mixed> */
    private function historyFilters(array $filters, Collection $devices): array
    {
        $kind = in_array($filters['history_kind'] ?? null, ['events', 'observations'], true)
            ? $filters['history_kind']
            : 'all';
        $deviceId = filter_var($filters['device_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        if ($deviceId && ! $devices->contains('id', $deviceId)) {
            $deviceId = null;
        }

        return [
            'kind' => $kind,
            'deviceId' => $deviceId,
            'severity' => $this->filterString($filters['severity'] ?? null),
            'eventType' => $this->filterString($filters['event_type'] ?? null),
            'source' => $this->filterString($filters['source'] ?? null),
        ];
    }

    private function filterString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 100 ? $value : null;
    }

    /** @return array<string, mixed> */
    private function action(string $key, string $label, int $count, string $description, string $tab): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'description' => $description,
            'href' => "/security-devices/facilities-iot?tab={$tab}&attention={$key}",
        ];
    }

    private function label(?string $value): ?string
    {
        return $value ? Str::headline($value) : null;
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $normalised = rtrim(rtrim((string) $value, '0'), '.');

        return $normalised === '' ? '0' : $normalised;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toISOString();
        } catch (Throwable) {
            return null;
        }
    }
}
