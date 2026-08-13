<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\Client;
use App\Models\LocationHardware;
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

    public function test_site_visibility_follows_site_access_and_integration_management_is_not_a_bypass(): void
    {
        $allowedSite = Site::factory()->create([]);
        $hiddenSite = Site::factory()->create([]);
        $viewer = $this->viewer('coordinator', $allowedSite);
        $this->grant($viewer, 'securityDevices.integrations.manage');

        $allowed = $this->assignedDevice($allowedSite, ['name' => 'Allowed by canonical Site']);
        $hidden = $this->assignedDevice($hiddenSite, ['name' => 'Hidden by canonical Site']);

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
        $unassigned = $this->assignedDevice($site, ['name' => 'Known Site stock']);
        $unassigned->assignments()->update([
            'released_at' => now(),
            'released_by_user_id' => $viewer->id,
        ]);

        $this->grant($viewer, 'securityDevices.devices.update');
        $this->assertFalse($this->access->visibleDevices($viewer)->whereKey($unassigned->id)->exists());

        $this->grant($viewer, 'securityDevices.devices.viewUnassigned');
        $this->assertTrue($this->access->visibleDevices($viewer)->whereKey($unassigned->id)->exists());
    }

    public function test_unassigned_and_quarantined_stock_require_explicit_temporal_custody(): void
    {
        $visibleSite = Site::factory()->create();
        $hiddenSite = Site::factory()->create();
        $viewer = $this->viewer('facilities_manager', $visibleSite);

        $visible = $this->assignedDevice($visibleSite, ['name' => 'Released local stock']);
        $visible->assignments()->update(['released_at' => now()]);
        $hidden = $this->assignedDevice($hiddenSite, ['name' => 'Released foreign stock']);
        $hidden->assignments()->update(['released_at' => now()]);
        $unknown = Device::factory()->create(['name' => 'Unknown stock']);
        $localHardware = LocationHardware::query()->create([
            'site_id' => $visibleSite->id,
            'provider' => 'manual',
            'category' => LocationHardware::CATEGORY_TRACKER,
            'name' => 'Released local hardware custody',
            'status' => LocationHardware::STATUS_ONLINE,
        ]);
        $localNewStock = Device::factory()->tracking()->create([
            'name' => 'New local stock',
            'legacy_location_hardware_id' => $localHardware->id,
        ]);
        $foreignHardware = LocationHardware::query()->create([
            'site_id' => $hiddenSite->id,
            'provider' => 'manual',
            'category' => LocationHardware::CATEGORY_TRACKER,
            'name' => 'Foreign hardware custody',
            'status' => LocationHardware::STATUS_ONLINE,
        ]);
        $foreignNewStock = Device::factory()->tracking()->create([
            'name' => 'New foreign stock',
            'legacy_location_hardware_id' => $foreignHardware->id,
        ]);
        $quarantined = Device::factory()->create([
            'name' => 'Quarantined stock',
            'status' => DeviceStatus::Quarantined,
        ]);

        $ids = $this->access->visibleDevices($viewer)->pluck('id')->all();
        $this->assertContains($visible->id, $ids);
        $this->assertContains($localNewStock->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
        $this->assertNotContains($foreignNewStock->id, $ids);
        $this->assertNotContains($unknown->id, $ids);
        $this->assertNotContains($quarantined->id, $ids);

        foreach ([$hidden, $unknown, $quarantined] as $concealed) {
            $this->actingAs($viewer)
                ->get("/security-devices/devices/{$concealed->id}")
                ->assertNotFound();
        }
    }

    public function test_explicit_global_role_can_see_unknown_and_quarantined_stock(): void
    {
        $admin = $this->viewer('admin');
        $unknown = Device::factory()->create(['name' => 'Global unknown stock']);
        $quarantined = Device::factory()->create([
            'name' => 'Global quarantined stock',
            'status' => DeviceStatus::Quarantined,
        ]);

        $this->assertTrue($this->access->canViewAllSites($admin));
        $this->assertTrue($this->access->canViewUnassigned($admin));
        $this->assertTrue($this->access->visibleDevices($admin)->whereKey($unknown->id)->exists());
        $this->assertTrue($this->access->visibleDevices($admin)->whereKey($quarantined->id)->exists());
    }

    public function test_current_custody_fails_closed_but_historical_site_snapshot_remains_stable(): void
    {
        $originalSite = Site::factory()->create();
        $newSite = Site::factory()->create();
        $viewer = $this->viewer('coordinator', $originalSite);
        $client = Client::factory()->create(['site_id' => $originalSite->id, 'status' => 'active']);
        $this->grant($viewer, 'clients.viewAny');
        $device = $this->assignedDevice($client, ['name' => 'Client tracker custody']);
        $assignment = $device->assignments()->firstOrFail();

        $this->assertSame($originalSite->id, (int) $assignment->custody_site_id);
        $this->assertTrue($this->access->visibleDevices($viewer)->whereKey($device->id)->exists());

        $client->update(['site_id' => $newSite->id]);
        $this->assertFalse($this->access->visibleDevices($viewer)->whereKey($device->id)->exists());

        $assignment->update(['released_at' => now()]);
        $this->assertSame($originalSite->id, (int) $assignment->fresh()->custody_site_id);
        $this->assertTrue($this->access->canAccessHistoricalAssignment($viewer, $assignment->fresh()));
    }

    public function test_event_history_uses_the_site_custody_window_at_the_time_of_each_observation(): void
    {
        $firstSite = Site::factory()->create();
        $secondSite = Site::factory()->create();
        $firstViewer = $this->viewer('coordinator', $firstSite);
        $secondViewer = $this->viewer('coordinator', $secondSite);
        $device = Device::factory()->create();
        $transferAt = now()->subHours(2)->startOfSecond();
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $firstSite->id,
            'custody_site_id' => $firstSite->id,
            'assigned_at' => now()->subHours(4),
            'released_at' => $transferAt,
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $secondSite->id,
            'custody_site_id' => $secondSite->id,
            'assigned_at' => $transferAt,
        ]);
        $firstEvent = DeviceEvent::query()->create([
            'device_id' => $device->id,
            'event_type' => 'first_site_observation',
            'severity' => 'info',
            'source' => 'test',
            'occurred_at' => now()->subHours(3),
        ]);
        $secondEvent = DeviceEvent::query()->create([
            'device_id' => $device->id,
            'event_type' => 'second_site_observation',
            'severity' => 'info',
            'source' => 'test',
            'occurred_at' => now()->subHour(),
        ]);

        $firstIds = $this->access
            ->applyTemporalEventCustodyScope(DeviceEvent::query(), $firstViewer)
            ->pluck('id')
            ->all();
        $secondIds = $this->access
            ->applyTemporalEventCustodyScope(DeviceEvent::query(), $secondViewer)
            ->pluck('id')
            ->all();

        $this->assertSame([$firstEvent->id], $firstIds);
        $this->assertSame([$secondEvent->id], $secondIds);
    }

    public function test_explicit_all_sites_access_does_not_bypass_client_staff_or_asset_privacy(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteViewer($site);
        $this->grant($viewer, 'securityDevices.devices.view');
        $this->grant($viewer, 'securityDevices.devices.viewAllSites');

        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
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

    public function test_room_parent_site_and_private_assignments_require_their_canonical_access(): void
    {
        $allowedSite = Site::factory()->create();
        $hiddenSite = Site::factory()->create();
        $viewer = $this->siteViewer($allowedSite);
        $this->grant($viewer, 'securityDevices.devices.view');
        $allowedRoom = SiteRoom::query()->create([
            'site_id' => $allowedSite->id,
            'name' => 'Allowed comms room',
        ]);
        $hiddenRoom = SiteRoom::query()->create([
            'site_id' => $hiddenSite->id,
            'name' => 'Hidden comms room',
        ]);
        $visible = $this->assignedDevice($allowedRoom);
        $hidden = $this->assignedDevice($hiddenRoom);
        $privateClient = Client::factory()->create(['site_id' => $allowedSite->id, 'status' => 'active']);
        $private = $this->assignedDevice($privateClient);

        $this->assertTrue($this->access->visibleDevices($viewer)->whereKey($visible->id)->exists());
        $this->assertFalse($this->access->visibleDevices($viewer)->whereKey($hidden->id)->exists());
        $this->assertFalse($this->access->visibleDevices($viewer)->whereKey($private->id)->exists());

        $this->grant($viewer, 'clients.viewAny');
        $this->assertTrue($this->access->visibleDevices($viewer)->whereKey($private->id)->exists());
    }

    public function test_mixed_site_provenance_fails_closed_in_the_access_kernel_and_site_registry(): void
    {
        $allowedSite = Site::factory()->create();
        $hiddenSite = Site::factory()->create();
        $viewer = $this->viewer('coordinator', $allowedSite);
        $device = $this->assignedDevice($allowedSite, ['name' => 'Ambiguous Site device']);
        $hiddenAsset = Asset::factory()->vehicle()->forSite($hiddenSite)->create();
        DeviceAssetLink::query()->create([
            'device_id' => $device->id,
            'asset_id' => $hiddenAsset->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
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
    ): User {
        $viewer = User::factory()->create([

            'approved_at' => now(),
        ]);
        $viewer->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        if ($site) {
            HrEmployeeProfile::factory()->create([
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
