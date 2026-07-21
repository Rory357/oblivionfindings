<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AuditLog;
use App\Models\Integration\Integration;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;

class SettingsAuditPresenter
{
    private const AUDIT_TYPES = [
        Device::class,
        DeviceAssetLink::class,
        DeviceAssignment::class,
        DeviceGroup::class,
        DeviceMaintenanceRecord::class,
        MonitoringProfile::class,
        Monitor::class,
        Integration::class,
        IntegrationProviderConnection::class,
        IntegrationSiteConfig::class,
        IntegrationSiteSecret::class,
        IntegrationSyncLog::class,
    ];

    private const SAFE_FIELDS = [
        'name', 'domain', 'category', 'subcategory', 'manufacturer', 'model', 'asset_tag',
        'firmware_version', 'status', 'health_status', 'last_seen_at', 'last_signal_at',
        'provider', 'site_id', 'is_active', 'mapped_external_site_name', 'action',
        'items_processed', 'items_created', 'items_updated', 'items_errored', 'started_at',
        'completed_at', 'assignable_type', 'assignable_id', 'assignment_type', 'assigned_at',
        'released_at', 'description', 'interval_seconds', 'failure_confirmations',
        'recovery_confirmations', 'stale_after_seconds', 'current_state', 'is_enabled',
        'last_observation_at', 'last_tested_at', 'last_synced_at', 'rotated_at', 'secret_last4',
    ];

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /** @return array<string, mixed> */
    public function present(User $viewer): array
    {
        $visibleDevices = $this->access->visibleDevices($viewer)->get(['id', 'provider', 'external_ref']);
        $deviceIds = $visibleDevices->pluck('id');
        $siteIds = $this->access->accessibleSiteIds($viewer);
        $canReport = $viewer->canDo('securityDevices.reports.view');
        $canManageIntegrations = $viewer->canDo('securityDevices.integrations.manage');
        $canViewAllSites = $this->access->canViewAllSites($viewer);

        $providerDefaults = $canManageIntegrations
            ? IntegrationProviderConnection::query()->get()->map(function (IntegrationProviderConnection $connection): array {
                $config = is_array($connection->config) ? $connection->config : [];
                $values = collect($config)->only([
                    'refresh_interval_minutes', 'alert_motion_events', 'alert_device_offline',
                    'quiet_hours_start', 'quiet_hours_end',
                ])->all();

                return ['provider' => $connection->provider, 'state' => $values === [] ? 'not_configured' : 'configured', 'values' => $values];
            })->filter(fn (array $row): bool => $row['values'] !== [])->values()->all()
            : [];

        $duplicateCandidates = $visibleDevices
            ->groupBy(fn (Device $device): string => $device->provider.'|'.hash('sha256', (string) data_get($device->external_ref, 'provider_entity_id', '')))
            ->filter(fn ($group, string $key): bool => ! str_ends_with($key, hash('sha256', '')) && $group->count() > 1)
            ->count();

        return [
            'summary' => [
                'device_groups' => DeviceGroup::query()
                    ->when(! $canViewAllSites, fn ($query) => $query->whereHas('devices', fn ($devices) => $devices->whereIn('devices.id', $deviceIds)))
                    ->count(),
                'audit_entries' => $canReport ? $this->auditQuery($deviceIds->all(), $siteIds, $canViewAllSites)->count() : 0,
            ],
            'areas' => $this->areas($viewer),
            'classificationDefaults' => [
                'state' => 'not_configured',
                'values' => [],
                'note' => 'No application classification-default record exists. Devices use the current Security & Devices taxonomy and explicit record values.',
            ],
            'providerOperationalDefaults' => $providerDefaults,
            'monitoringProfiles' => MonitoringProfile::query()->orderBy('name')->get()->map(fn (MonitoringProfile $profile): array => [
                'id' => $profile->id,
                'name' => $profile->name,
                'description' => $profile->description,
                'interval_seconds' => $profile->interval_seconds,
                'failure_confirmations' => $profile->failure_confirmations,
                'recovery_confirmations' => $profile->recovery_confirmations,
                'stale_after_seconds' => $profile->stale_after_seconds,
                'state' => $profile->is_active ? 'active' : 'inactive',
            ])->all(),
            'dataQuality' => [
                'visible_devices' => $visibleDevices->count(),
                'unassigned_devices' => Device::query()->whereIn('id', $deviceIds)->whereDoesntHave('assignments', fn ($query) => $query->active())->count(),
                'duplicate_candidates' => $duplicateCandidates,
                'note' => 'Counts use the same device visibility boundary as the estate and device workspaces.',
            ],
            'featureSupport' => [
                'classification_taxonomy' => ['state' => 'supported', 'note' => 'Current code-defined device taxonomy.'],
                'monitoring_profiles' => ['state' => 'supported', 'note' => 'Current application monitoring profile records.'],
                'integration_site_mapping' => ['state' => 'supported', 'note' => 'Current provider site configuration records.'],
                'discovery_candidates' => ['state' => 'unsupported', 'note' => 'No canonical discovery-candidate records exist yet.'],
                'database_audit_immutability' => ['state' => 'unsupported', 'note' => 'The application exposes read-only append-only evidence; database-level immutability is not claimed.'],
            ],
            'audit' => [
                'visible' => $canReport,
                'evidence_state' => 'read_only_append_only_application_evidence',
                'entries' => $canReport ? $this->auditEntries($deviceIds->all(), $siteIds, $canViewAllSites) : [],
                'limit' => 50,
            ],
        ];
    }

    private function areas(User $viewer): array
    {
        return collect([
            $viewer->canDo('securityDevices.groups.manage') ? ['title' => 'Device groups', 'description' => 'Organise devices using current manual membership and reviewed rules.', 'href' => '/security-devices/device-groups'] : null,
            $viewer->canDo('securityDevices.integrations.view') ? ['title' => 'Integrations', 'description' => 'Review provider connections, site mappings, sync, and imported-device exceptions.', 'href' => '/security-devices/integrations'] : null,
            $viewer->canDo('securityDevices.reports.view') ? ['title' => 'Reports & exports', 'description' => 'Review device, event, and maintenance reports with permission-gated exports.', 'href' => '/security-devices/reports'] : null,
        ])->filter()->values()->all();
    }

    private function auditQuery(array $visibleDeviceIds, array $siteIds, bool $canViewAllSites)
    {
        $monitorIds = Monitor::query()->whereIn('device_id', $visibleDeviceIds)->pluck('id');
        $deviceChildTypes = collect([DeviceAssetLink::class, DeviceMaintenanceRecord::class])
            ->flatMap(fn (string $type): array => $this->typeNames($type))->unique()->values()->all();

        return AuditLog::query()
            ->whereIn('auditable_type', $this->auditTypeNames())
            ->whereIn('action', collect(self::AUDIT_TYPES)->flatMap(fn (string $type) => collect(['create', 'update', 'delete'])->map(fn (string $verb) => strtolower(class_basename($type)).'.'.$verb)))
            ->when($canViewAllSites, fn ($query) => $query, fn ($query) => $query->where(function ($query) use ($visibleDeviceIds, $monitorIds, $siteIds, $deviceChildTypes): void {
                $query->where(fn ($q) => $q->whereIn('auditable_type', $this->typeNames(Device::class))->whereIn('auditable_id', $visibleDeviceIds))
                    ->orWhere(fn ($q) => $q->whereIn('auditable_type', $this->typeNames(Device::class))->where(function ($scope) use ($visibleDeviceIds, $siteIds): void {
                        $scope->whereIn('meta->scope->device_id', $visibleDeviceIds)->orWhereIn('meta->scope->site_id', $siteIds);
                        foreach ($siteIds as $siteId) {
                            $scope->orWhereJsonContains('meta->scope->site_ids', (int) $siteId);
                        }
                    }))
                    ->orWhere(fn ($q) => $q->whereIn('auditable_type', $this->typeNames(DeviceAssignment::class))->where(function ($scope) use ($siteIds): void {
                        $scope->whereIn('meta->scope->site_id', $siteIds);
                        foreach ($siteIds as $siteId) {
                            $scope->orWhereJsonContains('meta->scope->site_ids', (int) $siteId);
                        }
                    }))
                    ->orWhere(fn ($q) => $q->whereIn('auditable_type', $deviceChildTypes)->where(function ($scope) use ($visibleDeviceIds, $siteIds): void {
                        $scope->whereIn('meta->scope->device_id', $visibleDeviceIds)->orWhereIn('meta->scope->site_id', $siteIds);
                        foreach ($siteIds as $siteId) {
                            $scope->orWhereJsonContains('meta->scope->site_ids', (int) $siteId);
                        }
                    }))
                    ->orWhere(fn ($q) => $q->where('auditable_type', Monitor::class)->whereIn('auditable_id', $monitorIds))
                    ->orWhere(fn ($q) => $q->where('auditable_type', Monitor::class)->whereIn('meta->scope->device_id', $visibleDeviceIds))
                    ->orWhere(fn ($q) => $q->where('auditable_type', DeviceGroup::class)->whereIn('auditable_id', DeviceGroup::query()->whereHas('devices', fn ($devices) => $devices->whereIn('devices.id', $visibleDeviceIds))->select('id')))
                    ->orWhere(function ($q) use ($visibleDeviceIds): void {
                        $q->where('auditable_type', DeviceGroup::class)
                            ->where(function ($scopes) use ($visibleDeviceIds): void {
                                if ($visibleDeviceIds === []) {
                                    $scopes->whereRaw('1 = 0');
                                }
                                foreach ($visibleDeviceIds as $deviceId) {
                                    $scopes->orWhereJsonContains('meta->scope->device_ids', (int) $deviceId);
                                }
                            });
                    })
                    ->orWhere(fn ($q) => $q->where('auditable_type', MonitoringProfile::class)->whereIn('auditable_id', MonitoringProfile::query()->select('id')))
                    ->orWhere(fn ($q) => $q->where('auditable_type', IntegrationSiteConfig::class)->whereIn('auditable_id', IntegrationSiteConfig::query()->whereIn('site_id', $siteIds)->select('id')))
                    ->orWhere(fn ($q) => $q->where('auditable_type', IntegrationSiteConfig::class)->whereIn('meta->scope->site_id', $siteIds))
                    ->orWhere(fn ($q) => $q->where('auditable_type', IntegrationSiteSecret::class)->whereIn('auditable_id', IntegrationSiteSecret::query()->whereIn('site_id', $siteIds)->select('id')))
                    ->orWhere(fn ($q) => $q->where('auditable_type', IntegrationSiteSecret::class)->whereIn('meta->scope->site_id', $siteIds))
                    ->orWhere(fn ($q) => $q->where('auditable_type', IntegrationSyncLog::class)->whereIn('auditable_id', IntegrationSyncLog::query()->whereIn('site_id', $siteIds)->select('id')))
                    ->orWhere(fn ($q) => $q->where('auditable_type', IntegrationSyncLog::class)->whereIn('meta->scope->site_id', $siteIds));
            }));
    }

    private function auditEntries(array $visibleDeviceIds, array $siteIds, bool $canViewAllSites): array
    {
        return $this->auditQuery($visibleDeviceIds, $siteIds, $canViewAllSites)
            ->with('user:id,name')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action,
                'actor' => $entry->user?->name ?? 'System',
                'record_type' => $this->recordType((string) $entry->auditable_type),
                'record_reference' => $entry->auditable_id ? '#'.$entry->auditable_id : null,
                'fields' => collect(is_array(data_get($entry->meta, 'fields')) ? data_get($entry->meta, 'fields') : [])
                    ->filter(fn ($field): bool => is_string($field) && in_array($field, self::SAFE_FIELDS, true))
                    ->unique()->values()->all(),
                'created_at' => $entry->created_at?->toISOString(),
            ])->all();
    }

    /** @return array<int, string> */
    private function auditTypeNames(): array
    {
        return collect(self::AUDIT_TYPES)
            ->flatMap(fn (string $class): array => $this->typeNames($class))
            ->unique()->values()->all();
    }

    /** @return array<int, string> */
    private function typeNames(string $class): array
    {
        $morph = (new $class)->getMorphClass();

        return array_values(array_unique([$class, $morph]));
    }

    private function recordType(string $type): string
    {
        return class_basename(Relation::getMorphedModel($type) ?? $type);
    }
}
