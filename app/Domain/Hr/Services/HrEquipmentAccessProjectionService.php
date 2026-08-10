<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItProvisioningAccessService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AssetAssignment;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningWorkflow;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only HR projection over the records owned by HR, Fleet & Assets,
 * Security & Devices, and IT provisioning.
 *
 * This service deliberately does not create or synchronise shadow inventory.
 * Each row links back to the module that owns its lifecycle and is returned
 * only after both the HR destination context and the source-domain permission
 * boundary have been satisfied.
 */
final class HrEquipmentAccessProjectionService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $devicesAccess,
        private readonly ItProvisioningAccessService $provisioningAccess,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer, HrEmployeeProfile $profile): array
    {
        $canViewHrAssets = $viewer->canDo('hr.assets.view');
        $canViewDevices = $viewer->canDo('securityDevices.viewAny')
            && $viewer->canDo('securityDevices.devices.view');
        $canViewAssets = $viewer->canDo('assets.viewAny') || $viewer->canDo('assets.viewAssigned');
        $canViewIt = $viewer->canDo('it.view') || $viewer->canDo('it.manage');

        $deviceRows = $canViewDevices
            ? $this->deviceAssignments($viewer, $profile)
            : collect();
        $linkedAssetIds = $deviceRows
            ->pluck('canonical_asset_ids')
            ->flatten()
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        $assetRows = $canViewAssets
            ? $this->canonicalAssetAssignments($viewer, $profile, $linkedAssetIds)
            : collect();
        $legacyRows = $canViewHrAssets
            ? $this->legacyHrAssetAssignments($profile)
            : collect();

        $equipment = $deviceRows
            ->concat($assetRows)
            ->concat($legacyRows)
            ->sortByDesc(fn (array $row): string => (string) ($row['assigned_at'] ?? ''))
            ->values();

        $workflows = $canViewIt
            ? $this->provisioningWorkflows($viewer, $profile)
            : collect();
        $accessWork = $canViewIt
            ? $this->accessWork($viewer, $profile)
            : collect();

        $actionableEquipment = $equipment->where('historical_only', false);
        $activeEquipment = $actionableEquipment->whereNull('returned_at')->count();
        $recoveryDue = $actionableEquipment
            ->whereNull('returned_at')
            ->where('needs_recovery', true)
            ->count();
        $outstandingProvisioning = $accessWork
            ->whereNotIn('status', ['done', 'cancelled'])
            ->count();

        return [
            'summary' => [
                'active_equipment' => $activeEquipment,
                'recovery_due' => $recoveryDue,
                'active_workflows' => $workflows->whereNotIn('status', ['completed', 'cancelled'])->count(),
                'outstanding_access' => $outstandingProvisioning,
            ],
            'equipment' => $equipment->all(),
            'workflows' => $workflows->all(),
            'access_work' => $accessWork->all(),
            'can' => [
                'view_hr_assets' => $canViewHrAssets,
                'view_devices' => $canViewDevices,
                'view_assets' => $canViewAssets,
                'view_it' => $canViewIt,
            ],
            'links' => [
                'hr_assets' => $canViewHrAssets ? '/hr/assets' : null,
                'devices' => $canViewDevices ? '/security-devices/devices' : null,
                'provisioning' => $canViewIt ? '/it?tab=provisioning' : null,
            ],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function deviceAssignments(User $viewer, HrEmployeeProfile $profile): Collection
    {
        $assignments = DeviceAssignment::query()
            ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
            ->where('assignable_id', $profile->user_id)
            ->with([
                'device.activeAssetLinks:id,device_id,asset_id',
            ])
            ->orderByDesc('assigned_at')
            ->get();

        if ($assignments->isEmpty()) {
            return collect();
        }

        $candidateIds = $assignments->pluck('device_id')->unique()->values();
        $visibleIds = $this->devicesAccess->visibleDevices($viewer)
            ->whereKey($candidateIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        // HR already authorised the exact employee destination context. The
        // source permission may therefore project an exclusively staff-held
        // device even when the general Device picker deliberately omits staff.
        // Tracking remains behind its additional privacy permissions.
        $exactStaffDeviceIds = Device::query()
            ->whereKey($candidateIds)
            ->whereHas('assignments', fn ($query) => $query
                ->active()
                ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
                ->where('assignable_id', $profile->user_id))
            ->whereDoesntHave('assignments', fn ($query) => $query
                ->active()
                ->where(function ($other) use ($profile): void {
                    $other->where('assignable_type', '!=', DeviceAssignment::TARGET_STAFF)
                        ->orWhere('assignable_id', '!=', $profile->user_id);
                }))
            ->whereDoesntHave('activeAssetLinks')
            ->where(function ($device) use ($viewer): void {
                $device->where('domain', '!=', 'tracking');
                if ($viewer->canDo('hazards.view') && $viewer->canDo('staff.viewAny')) {
                    $device->orWhere('domain', 'tracking');
                }
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $allowedIds = array_values(array_unique([...$visibleIds, ...$exactStaffDeviceIds]));

        return $assignments
            ->filter(fn (DeviceAssignment $assignment): bool => in_array((int) $assignment->device_id, $allowedIds, true))
            ->map(function (DeviceAssignment $assignment) use ($profile, $visibleIds): array {
                $device = $assignment->device;
                $isActive = $assignment->released_at === null;
                $canOpenDevice = $device
                    && in_array((int) $device->id, $visibleIds, true);
                $recoveryOnly = $device
                    && ! $this->profileIsCurrent($profile)
                    && ! $canOpenDevice;

                return [
                    'key' => 'device-'.$assignment->id,
                    'assignment_id' => $assignment->id,
                    'record_id' => $device?->id,
                    'source' => 'security_devices',
                    'source_label' => 'Security & Devices',
                    'name' => $device?->name,
                    'tag' => $device?->asset_tag ?: $device?->device_uid,
                    'category' => $device?->category,
                    'serial_number' => $device?->serial_number,
                    'assigned_at' => $assignment->assigned_at?->toDateString(),
                    'returned_at' => $assignment->released_at?->toDateString(),
                    'status' => $this->enumValue($device?->status),
                    'health' => $this->enumValue($device?->health_status),
                    'condition' => null,
                    'needs_recovery' => ! $this->profileIsCurrent($profile) && $isActive,
                    'href' => $canOpenDevice
                        ? "/security-devices/devices/{$device->id}"
                        : null,
                    'canonical_asset_ids' => $device?->activeAssetLinks
                        ?->pluck('asset_id')
                        ->map(fn (mixed $id): int => (int) $id)
                        ->all() ?? [],
                    'recovery_only' => $recoveryOnly,
                    'historical_only' => false,
                    'destination_access' => [
                        'state' => $canOpenDevice
                            ? 'available'
                            : ($recoveryOnly ? 'recovery_only' : 'restricted'),
                        'label' => $canOpenDevice
                            ? 'Open Device Profile'
                            : ($recoveryOnly
                                ? 'Recovery-only HR view'
                                : 'Device Profile access required'),
                    ],
                ];
            })
            ->values();
    }

    /** @param list<int> $excludeAssetIds @return Collection<int, array<string, mixed>> */
    private function canonicalAssetAssignments(
        User $viewer,
        HrEmployeeProfile $profile,
        array $excludeAssetIds,
    ): Collection {
        $siteIds = $this->siteAccess->accessibleSiteIds($viewer);

        return AssetAssignment::query()
            ->where('assignee_type', 'staff')
            ->where('assignee_id', $profile->user_id)
            ->with(['asset.site:id', 'asset.homeSite:id', 'asset.client:id,site_id'])
            ->orderByDesc('assigned_at')
            ->get()
            ->filter(function (AssetAssignment $assignment) use ($viewer, $siteIds, $excludeAssetIds): bool {
                $asset = $assignment->asset;
                if (! $asset || in_array((int) $asset->id, $excludeAssetIds, true)) {
                    return false;
                }

                if (! Gate::forUser($viewer)->allows('view', $asset)) {
                    return false;
                }

                $provenance = collect([
                    $asset->site_id,
                    $asset->home_site_id,
                    $asset->client?->site_id,
                ])
                    ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values();

                return $provenance->isNotEmpty()
                    && $provenance->diff($siteIds)->isEmpty()
                    && ($asset->client_id === null || $asset->client !== null);
            })
            ->map(function (AssetAssignment $assignment) use ($profile): array {
                $asset = $assignment->asset;

                return [
                    'key' => 'asset-'.$assignment->id,
                    'assignment_id' => $assignment->id,
                    'record_id' => $asset?->id,
                    'source' => 'assets',
                    'source_label' => 'Fleet & Assets',
                    'name' => $asset?->name,
                    'tag' => $asset?->asset_tag,
                    'category' => $asset?->category,
                    'serial_number' => $asset?->serial_number,
                    'assigned_at' => $assignment->assigned_at?->toDateString(),
                    'returned_at' => $assignment->released_at?->toDateString(),
                    'status' => $asset?->status,
                    'health' => null,
                    'condition' => null,
                    'needs_recovery' => ! $this->profileIsCurrent($profile) && $assignment->released_at === null,
                    'href' => $asset ? "/assets/{$asset->id}" : null,
                    'canonical_asset_ids' => $asset ? [(int) $asset->id] : [],
                    'recovery_only' => false,
                    'historical_only' => false,
                    'destination_access' => [
                        'state' => 'available',
                        'label' => 'Open Fleet & Assets record',
                    ],
                ];
            })
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function legacyHrAssetAssignments(HrEmployeeProfile $profile): Collection
    {
        return HrAssetAssignment::query()
            ->where('employee_profile_id', $profile->id)
            ->with('asset:id,asset_tag,name,category,serial_number,status,fleet_asset_id')
            ->orderByDesc('assigned_at')
            ->get()
            ->filter(fn (HrAssetAssignment $assignment): bool => $assignment->asset !== null)
            ->map(function (HrAssetAssignment $assignment) use ($profile): array {
                $asset = $assignment->asset;
                $historicalOnly = ! $asset->isHrLifecycleOwned();

                return [
                    'key' => 'hr-asset-'.$assignment->id,
                    'assignment_id' => $assignment->id,
                    'record_id' => $asset->id,
                    'source' => 'hr_assets',
                    'source_label' => $historicalOnly ? 'Historical HR record' : 'HR equipment',
                    'name' => $asset->name,
                    'tag' => $asset->asset_tag,
                    'category' => $asset->category,
                    'serial_number' => $asset->serial_number,
                    'assigned_at' => $assignment->assigned_at?->toDateString(),
                    'returned_at' => $assignment->returned_at?->toDateString(),
                    'status' => $asset->status,
                    'health' => null,
                    'condition' => $assignment->condition_on_assign,
                    'needs_recovery' => ! $historicalOnly
                        && ! $this->profileIsCurrent($profile)
                        && $assignment->returned_at === null,
                    'href' => $historicalOnly ? null : "/hr/assets/{$asset->id}",
                    'canonical_asset_ids' => $asset->fleet_asset_id ? [(int) $asset->fleet_asset_id] : [],
                    'recovery_only' => false,
                    'historical_only' => $historicalOnly,
                    'destination_access' => [
                        'state' => $historicalOnly ? 'historical_only' : 'available',
                        'label' => $historicalOnly
                            ? 'Historical HR record — no action available'
                            : 'Open HR equipment record',
                    ],
                ];
            })
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function provisioningWorkflows(User $viewer, HrEmployeeProfile $profile): Collection
    {
        $query = ItProvisioningWorkflow::query()
            ->where('employee_profile_id', $profile->id)
            ->with('requests:id,provisioning_workflow_id,status');
        $this->provisioningAccess->applyWorkflowScope($query, $viewer);

        return $query
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ItProvisioningWorkflow $workflow): array {
                $total = $workflow->requests->count();
                $done = $workflow->requests->whereIn('status', ['done', 'cancelled'])->count();

                return [
                    'id' => $workflow->id,
                    'lifecycle' => $workflow->lifecycle_type,
                    'status' => $workflow->status,
                    'effective_at' => $workflow->effective_at?->toDateString(),
                    'site_id' => $workflow->site_id_snapshot,
                    'total' => $total,
                    'completed' => $done,
                    'outstanding' => max(0, $total - $done),
                    'href' => '/it?tab=provisioning',
                ];
            })
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function accessWork(User $viewer, HrEmployeeProfile $profile): Collection
    {
        $query = ItProvisioningRequest::query()
            ->where('employee_profile_id', $profile->id)
            ->whereIn('type', ['account', 'access', 'equipment'])
            ->with('workflow:id,lifecycle_type');
        $this->provisioningAccess->applyRequestScope($query, $viewer);

        return $query
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ItProvisioningRequest $request): array => [
                'id' => $request->id,
                'workflow_id' => $request->provisioning_workflow_id,
                'lifecycle' => $request->workflow?->lifecycle_type,
                'type' => $request->type,
                'action' => $request->action,
                'item' => $request->item,
                'status' => $request->status,
                'priority' => $request->priority,
                'due_date' => $request->due_date?->toDateString(),
                'href' => '/it?tab=provisioning',
            ])
            ->values();
    }

    private function profileIsCurrent(HrEmployeeProfile $profile): bool
    {
        return ! $profile->trashed()
            && (bool) $profile->is_active
            && ($profile->start_date === null || $profile->start_date->lte(today()))
            && ($profile->end_date === null || $profile->end_date->gte(today()));
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
