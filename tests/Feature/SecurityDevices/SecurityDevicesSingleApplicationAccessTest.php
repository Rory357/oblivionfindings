<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityDevicesSingleApplicationAccessTest extends TestCase
{
    use RefreshDatabase;

    private SecurityDevicesAccessService $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->access = app(SecurityDevicesAccessService::class);
    }

    public function test_all_sites_and_unassigned_stock_are_explicit_permissions(): void
    {
        $this->assertDatabaseHas('permissions', ['key' => 'securityDevices.devices.viewAllSites']);
        $this->assertDatabaseHas('permissions', ['key' => 'securityDevices.devices.viewUnassigned']);

        $facilities = $this->viewer('facilities_manager');
        $coordinator = $this->viewer('coordinator');
        $this->grant($coordinator, 'securityDevices.integrations.manage');

        $this->assertTrue($facilities->canDo('securityDevices.devices.viewUnassigned'));
        $this->assertFalse($facilities->canDo('securityDevices.devices.viewAllSites'));
        $this->assertTrue($coordinator->canDo('securityDevices.integrations.manage'));
        $this->assertFalse($this->access->canViewAllSites($coordinator));
    }

    public function test_site_visibility_ignores_legacy_partition_values_and_integration_management_is_not_a_bypass(): void
    {
        $allowedSite = Site::factory()->create(['tenant_id' => 41]);
        $hiddenSite = Site::factory()->create(['tenant_id' => 82]);
        $viewer = $this->viewer('coordinator', $allowedSite, organizationId: 999, profileTenantId: 333);
        $this->grant($viewer, 'securityDevices.integrations.manage');

        $allowed = $this->assignedDevice($allowedSite, ['tenant_id' => 777, 'name' => 'Allowed by canonical Site']);
        $hidden = $this->assignedDevice($hiddenSite, ['tenant_id' => 999, 'name' => 'Hidden by canonical Site']);

        $ids = $this->access->visibleDevices($viewer)->pluck('id')->all();

        $this->assertContains($allowed->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
        $this->assertSame([$allowedSite->id], $this->access->accessibleSiteIds($viewer));

        $this->actingAs($viewer)
            ->get("/security-devices/devices/{$hidden->id}")
            ->assertNotFound();
    }

    public function test_unassigned_inventory_requires_its_own_permission(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->viewer('support_worker', $site);
        $unassigned = Device::factory()->create(['name' => 'Unassigned stock']);

        $this->grant($viewer, 'securityDevices.devices.update');
        $this->assertFalse($this->access->visibleDevices($viewer)->whereKey($unassigned->id)->exists());

        $this->grant($viewer, 'securityDevices.devices.viewUnassigned');
        $this->assertTrue($this->access->visibleDevices($viewer)->whereKey($unassigned->id)->exists());
    }

    public function test_explicit_all_sites_access_does_not_bypass_client_staff_or_asset_privacy(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteViewer($site);
        $this->grant($viewer, 'securityDevices.devices.view');
        $this->grant($viewer, 'securityDevices.devices.viewAllSites');

        $client = Client::factory()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $staff->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);

        $clientDevice = $this->assignedDevice($client, ['name' => 'Private client tracker']);
        $staffDevice = $this->assignedDevice($staff, ['name' => 'Private staff tracker']);
        $vehicleDevice = $this->assignedDevice($vehicle, ['name' => 'Private fleet tracker']);

        $this->assertFalse($this->access->visibleDevices($viewer)->whereKey($clientDevice->id)->exists());
        $this->assertFalse($this->access->visibleDevices($viewer)->whereKey($staffDevice->id)->exists());
        $this->assertFalse($this->access->visibleDevices($viewer)->whereKey($vehicleDevice->id)->exists());

        foreach (['clients.viewAny', 'staff.viewAny', 'hazards.view', 'fleet.viewAny'] as $permission) {
            $this->grant($viewer, $permission);
        }

        $visibleIds = $this->access->visibleDevices($viewer)->pluck('id')->all();
        $this->assertContains($clientDevice->id, $visibleIds);
        $this->assertContains($staffDevice->id, $visibleIds);
        $this->assertContains($vehicleDevice->id, $visibleIds);
    }

    public function test_room_parent_site_and_ambiguous_private_assignments_fail_closed(): void
    {
        $allowedSite = Site::factory()->create();
        $hiddenSite = Site::factory()->create();
        $viewer = $this->siteViewer($allowedSite);
        $this->grant($viewer, 'securityDevices.devices.view');
        $allowedRoom = SiteRoom::query()->create([
            'tenant_id' => 900,
            'site_id' => $allowedSite->id,
            'name' => 'Allowed comms room',
        ]);
        $hiddenRoom = SiteRoom::query()->create([
            'tenant_id' => 900,
            'site_id' => $hiddenSite->id,
            'name' => 'Hidden comms room',
        ]);
        $visible = $this->assignedDevice($allowedRoom);
        $hidden = $this->assignedDevice($hiddenRoom);
        $privateClient = Client::factory()->create(['site_id' => $allowedSite->id]);
        DeviceAssignment::query()->create([
            'device_id' => $visible->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $privateClient->id,
            'assigned_at' => now(),
        ]);

        $this->assertFalse($this->access->visibleDevices($viewer)->whereKey($visible->id)->exists());
        $this->assertFalse($this->access->visibleDevices($viewer)->whereKey($hidden->id)->exists());

        $this->grant($viewer, 'clients.viewAny');
        $this->assertTrue($this->access->visibleDevices($viewer)->whereKey($visible->id)->exists());
    }

    public function test_mixed_site_provenance_fails_closed_in_the_access_kernel_and_site_registry(): void
    {
        $allowedSite = Site::factory()->create();
        $hiddenSite = Site::factory()->create();
        $viewer = $this->viewer('coordinator', $allowedSite);
        $device = $this->assignedDevice($allowedSite, ['name' => 'Ambiguous Site device']);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $hiddenSite->id,
            'assigned_at' => now(),
        ]);

        $this->assertFalse($this->access->visibleDevices($viewer)->whereKey($device->id)->exists());
        $this->assertFalse(app(DeviceRegistryService::class)
            ->visibleForSite($viewer, $allowedSite->id)
            ->whereKey($device->id)
            ->exists());
    }

    public function test_index_search_export_and_direct_id_share_the_same_visibility_boundary(): void
    {
        $allowedSite = Site::factory()->create();
        $hiddenSite = Site::factory()->create();
        $viewer = $this->viewer('coordinator', $allowedSite);
        $this->grant($viewer, 'securityDevices.reports.view');
        $allowed = $this->assignedDevice($allowedSite, ['name' => 'Visible switch']);
        $hidden = $this->assignedDevice($hiddenSite, ['name' => 'Secret switch']);

        $this->actingAs($viewer)
            ->get('/security-devices/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('devices.meta.total', 1)
                ->where('stats.total', 1)
                ->where('devices.data.0.id', $allowed->id));

        $this->actingAs($viewer)
            ->get('/security-devices/devices?search=Secret')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('devices.meta.total', 0));

        $this->actingAs($viewer)
            ->get("/security-devices/devices/{$hidden->id}")
            ->assertNotFound();

        $export = $this->actingAs($viewer)->get('/security-devices/reports/devices.csv');
        $export->assertOk();
        $csv = $export->streamedContent();
        $this->assertStringContainsString('Visible switch', $csv);
        $this->assertStringNotContainsString('Secret switch', $csv);
    }

    private function viewer(
        string $role,
        ?Site $site = null,
        ?int $organizationId = null,
        int $profileTenantId = 1,
    ): User {
        $viewer = User::factory()->create([
            'organization_id' => $organizationId,
            'approved_at' => now(),
        ]);
        $viewer->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        if ($site) {
            HrEmployeeProfile::factory()->create([
                'tenant_id' => $profileTenantId,
                'user_id' => $viewer->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
            ]);
        }

        return $viewer;
    }

    private function siteViewer(Site $site): User
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);

        return $viewer;
    }

    private function grant(User $user, string $permission): void
    {
        $permissionId = Permission::query()->where('key', $permission)->value('id');
        $this->assertNotNull($permissionId, "Missing permission {$permission}");
        $user->permissionOverrides()->syncWithoutDetaching([
            $permissionId => ['allowed' => true],
        ]);
    }

    private function assignedDevice(Site|SiteRoom|Client|User|Asset $target, array $attributes = []): Device
    {
        $device = Device::factory()->create($attributes);
        $type = match (true) {
            $target instanceof Site => DeviceAssignment::TARGET_SITE,
            $target instanceof SiteRoom => DeviceAssignment::TARGET_ROOM,
            $target instanceof Client => DeviceAssignment::TARGET_CLIENT,
            $target instanceof User => DeviceAssignment::TARGET_STAFF,
            $target instanceof Asset => DeviceAssignment::TARGET_VEHICLE,
        };

        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => $type,
            'assignable_id' => $target->id,
            'assigned_at' => now(),
        ]);

        return $device;
    }
}
