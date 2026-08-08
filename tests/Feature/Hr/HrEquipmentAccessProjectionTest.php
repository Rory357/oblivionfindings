<?php

use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningWorkflow;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);

    $this->site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $this->hiddenSite = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);

    $this->viewer = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->viewer->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => today()->subYear(),
    ]);

    $this->employee = User::factory()->create(['approved_at' => now()]);
    $this->profile = HrEmployeeProfile::factory()->create([
        'user_id' => $this->employee->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => today()->subMonths(3),
    ]);

    grantEquipmentProjectionPermissions($this->viewer, [
        'hr.employees.viewAny',
        'hr.assets.view',
        'securityDevices.viewAny',
        'securityDevices.devices.view',
        'assets.viewAny',
        'it.view',
    ]);
});

function grantEquipmentProjectionPermissions(User $user, array $keys): void
{
    $permissionIds = Permission::query()->whereIn('key', $keys)->pluck('id');
    expect($permissionIds)->toHaveCount(count($keys));

    $user->permissionOverrides()->syncWithoutDetaching(
        $permissionIds->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all(),
    );
}

function createProjectionWorkflow(HrEmployeeProfile $profile, Site $site): ItProvisioningWorkflow
{
    $workflow = ItProvisioningWorkflow::query()->create([
        'employee_profile_id' => $profile->id,
        'lifecycle_type' => 'joiner',
        'source_type' => 'hr_onboarding_checklist',
        'source_id' => 9001,
        'source_event_key' => 'projection-'.$profile->id.'-'.fake()->uuid(),
        'status' => 'in_progress',
        'effective_at' => today()->addWeek(),
        'site_id_snapshot' => $site->id,
    ]);

    ItProvisioningRequest::query()->create([
        'employee_profile_id' => $profile->id,
        'provisioning_workflow_id' => $workflow->id,
        'type' => 'access',
        'task_key' => 'vpn-access',
        'action' => 'provision',
        'item' => 'VPN access',
        'status' => 'pending',
        'priority' => 'high',
        'due_date' => today()->addDays(5),
    ]);

    return $workflow;
}

test('employee equipment and access is a read-only projection of canonical source modules', function () {
    $device = Device::factory()->create([
        'name' => 'Managed staff laptop',
        'domain' => 'network_it',
        'category' => 'laptop',
        'asset_tag' => 'DEV-100',
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_STAFF,
        'assignable_id' => $this->employee->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subMonth(),
        'assigned_by_user_id' => $this->viewer->id,
    ]);

    $asset = Asset::factory()->create([
        'site_id' => $this->site->id,
        'name' => 'Staff desk chair',
        'category' => 'furniture',
        'asset_tag' => 'AST-200',
    ]);
    AssetAssignment::query()->create([
        'asset_id' => $asset->id,
        'assignee_type' => 'staff',
        'assignee_id' => $this->employee->id,
        'purpose' => 'Ergonomic setup',
        'assigned_at' => now()->subWeeks(2),
    ]);

    $legacy = HrAsset::query()->create([
        'asset_tag' => 'HR-300',
        'name' => 'Winter uniform',
        'category' => 'uniform',
        'status' => 'assigned',
    ]);
    HrAssetAssignment::query()->create([
        'asset_id' => $legacy->id,
        'employee_profile_id' => $this->profile->id,
        'assigned_at' => now()->subWeek(),
        'assigned_by' => $this->viewer->id,
    ]);

    $workflow = createProjectionWorkflow($this->profile, $this->site);

    $response = $this->actingAs($this->viewer)->get("/hr/people/{$this->profile->id}");

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/employees/show')
            ->where('equipmentAccess.summary.active_equipment', 3)
            ->where('equipmentAccess.summary.active_workflows', 1)
            ->where('equipmentAccess.summary.outstanding_access', 1)
            ->where('equipmentAccess.equipment', fn ($rows): bool => collect($rows)
                ->pluck('source', 'name')
                ->all() === [
                    'Winter uniform' => 'hr_assets',
                    'Staff desk chair' => 'assets',
                    'Managed staff laptop' => 'security_devices',
                ])
            ->where('equipmentAccess.workflows.0.id', $workflow->id)
            ->where('equipmentAccess.access_work.0.item', 'VPN access')
            ->where('equipmentAccess.links.devices', '/security-devices/devices')
            ->where('equipmentAccess.links.provisioning', '/it?tab=provisioning'));
});

test('source permissions and mixed device provenance fail closed without leaking names', function () {
    $device = Device::factory()->create([
        'name' => 'Hidden mixed-provenance laptop',
        'domain' => 'network_it',
        'category' => 'laptop',
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_STAFF,
        'assignable_id' => $this->employee->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $this->viewer->id,
    ]);
    $asset = Asset::factory()->create([
        'site_id' => $this->hiddenSite->id,
        'name' => 'Hidden-site equipment',
    ]);
    DeviceAssetLink::query()->create([
        'device_id' => $device->id,
        'asset_id' => $asset->id,
        'link_type' => 'installed_in',
        'linked_at' => now(),
        'linked_by_user_id' => $this->viewer->id,
    ]);
    AssetAssignment::query()->create([
        'asset_id' => $asset->id,
        'assignee_type' => 'staff',
        'assignee_id' => $this->employee->id,
        'assigned_at' => now(),
    ]);

    $response = $this->actingAs($this->viewer)->get("/hr/people/{$this->profile->id}");
    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('equipmentAccess.equipment', [])
            ->where('equipmentAccess.summary.active_equipment', 0));
    $response->assertDontSee('Hidden mixed-provenance laptop')
        ->assertDontSee('Hidden-site equipment');

    foreach (['hr.assets.view', 'securityDevices.viewAny', 'securityDevices.devices.view', 'assets.viewAny', 'it.view'] as $permission) {
        $this->viewer->permissionOverrides()->updateExistingPivot(
            Permission::query()->where('key', $permission)->firstOrFail()->id,
            ['allowed' => false],
        );
    }

    $legacy = HrAsset::query()->create([
        'asset_tag' => 'PRIVATE-HR',
        'name' => 'Permission-hidden uniform',
        'category' => 'uniform',
        'status' => 'assigned',
    ]);
    HrAssetAssignment::query()->create([
        'asset_id' => $legacy->id,
        'employee_profile_id' => $this->profile->id,
        'assigned_at' => now(),
        'assigned_by' => $this->viewer->id,
    ]);
    createProjectionWorkflow($this->profile, $this->site);

    $this->viewer->unsetRelation('permissionOverrides');
    $response = $this->actingAs($this->viewer->fresh())->get("/hr/people/{$this->profile->id}");
    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('equipmentAccess.equipment', [])
            ->where('equipmentAccess.workflows', [])
            ->where('equipmentAccess.access_work', [])
            ->where('equipmentAccess.links.hr_assets', null)
            ->where('equipmentAccess.links.devices', null)
            ->where('equipmentAccess.links.provisioning', null));
    $response->assertDontSee('Permission-hidden uniform')
        ->assertDontSee('VPN access');
});

test('an exact device still held by a former employee remains visible for recovery', function () {
    $this->profile->update([
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);

    $device = Device::factory()->create([
        'name' => 'Unreturned leaver laptop',
        'domain' => 'network_it',
        'category' => 'laptop',
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_STAFF,
        'assignable_id' => $this->employee->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subMonths(4),
        'assigned_by_user_id' => $this->viewer->id,
    ]);

    $this->actingAs($this->viewer)
        ->get("/hr/people/{$this->profile->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('equipmentAccess.summary.active_equipment', 1)
            ->where('equipmentAccess.summary.recovery_due', 1)
            ->where('equipmentAccess.equipment.0.name', 'Unreturned leaver laptop')
            ->where('equipmentAccess.equipment.0.needs_recovery', true)
            ->where('equipmentAccess.equipment.0.recovery_only', true)
            ->where('equipmentAccess.equipment.0.href', null));

    $this->actingAs($this->viewer)
        ->get("/security-devices/devices/{$device->id}")
        ->assertNotFound();
});

test('new technology cannot be entered into the legacy HR equipment register', function () {
    grantEquipmentProjectionPermissions($this->viewer, [
        'hr.assets.manage',
        'hr.assets.viewUnassigned',
    ]);

    $this->actingAs($this->viewer)
        ->post('/hr/assets', [
            'asset_tag' => 'DUP-LAPTOP-1',
            'name' => 'Duplicate laptop',
            'category' => 'laptop',
        ])
        ->assertStatus(422);

    expect(HrAsset::query()->where('asset_tag', 'DUP-LAPTOP-1')->exists())->toBeFalse();
});

test('current canonical duplicates stay actionable while federated HR rows are labelled history only', function () {
    grantEquipmentProjectionPermissions($this->viewer, [
        'hazards.view',
        'staff.viewAny',
    ]);

    $canonicalAsset = Asset::factory()->create([
        'site_id' => $this->site->id,
        'name' => 'Canonical fleet vehicle',
        'category' => 'vehicle',
        'asset_tag' => 'FLEET-CANONICAL-1',
    ]);
    AssetAssignment::query()->create([
        'asset_id' => $canonicalAsset->id,
        'assignee_type' => 'staff',
        'assignee_id' => $this->employee->id,
        'assigned_at' => now()->subDays(3),
    ]);

    $fleetHistory = HrAsset::query()->create([
        'asset_tag' => 'FLEET-HISTORY-1',
        'name' => 'Historical fleet duplicate',
        'category' => 'vehicle',
        'status' => 'assigned',
        'fleet_asset_id' => $canonicalAsset->id,
    ]);
    HrAssetAssignment::query()->create([
        'asset_id' => $fleetHistory->id,
        'employee_profile_id' => $this->profile->id,
        'assigned_at' => now()->subDays(4),
        'assigned_by' => $this->viewer->id,
    ]);

    $canonicalDevice = Device::factory()->create([
        'name' => 'Canonical staff laptop',
        'domain' => 'network_it',
        'category' => 'laptop',
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $canonicalDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_STAFF,
        'assignable_id' => $this->employee->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subDay(),
        'assigned_by_user_id' => $this->viewer->id,
    ]);

    $technologyHistory = HrAsset::query()->create([
        'asset_tag' => 'DEVICE-HISTORY-1',
        'name' => 'Historical laptop duplicate',
        'category' => 'laptop',
        'status' => 'assigned',
    ]);
    HrAssetAssignment::query()->create([
        'asset_id' => $technologyHistory->id,
        'employee_profile_id' => $this->profile->id,
        'assigned_at' => now()->subDays(2),
        'assigned_by' => $this->viewer->id,
    ]);

    $this->actingAs($this->viewer)
        ->get("/hr/people/{$this->profile->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('equipmentAccess.summary.active_equipment', 2)
            ->where('equipmentAccess.equipment', function ($rows) use ($canonicalAsset, $canonicalDevice): bool {
                $byName = collect($rows)->keyBy('name');

                expect($byName->get('Canonical fleet vehicle'))
                    ->toMatchArray([
                        'href' => "/assets/{$canonicalAsset->id}",
                        'historical_only' => false,
                    ])
                    ->and($byName->get('Canonical staff laptop'))
                    ->toMatchArray([
                        'href' => "/security-devices/devices/{$canonicalDevice->id}",
                        'historical_only' => false,
                    ])
                    ->and($byName->get('Historical fleet duplicate'))
                    ->toMatchArray([
                        'source_label' => 'Historical HR record',
                        'href' => null,
                        'historical_only' => true,
                    ])
                    ->and($byName->get('Historical laptop duplicate'))
                    ->toMatchArray([
                        'source_label' => 'Historical HR record',
                        'href' => null,
                        'historical_only' => true,
                    ]);

                return true;
            }));
});
