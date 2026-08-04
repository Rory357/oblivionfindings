<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    private User $noPerms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->viewer = User::factory()->create();
        $this->viewer->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->noPerms = User::factory()->create();
    }

    // ── Index ─────────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->get('/security-devices/devices')->assertRedirect('/login');
    }

    public function test_index_requires_view_permission(): void
    {
        $this->actingAs($this->noPerms)
            ->get('/security-devices/devices')
            ->assertForbidden();
    }

    public function test_index_accessible_with_view_permission(): void
    {
        $this->actingAs($this->viewer)
            ->get('/security-devices/devices')
            ->assertOk();
    }

    public function test_index_returns_paginated_devices(): void
    {
        Device::factory()->count(5)->create();

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/devices');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('security-devices/devices/index')
            ->has('devices.data', 5)
            ->has('devices.meta')
            ->has('stats')
            ->has('filters')
            ->has('filterOptions')
        );
    }

    public function test_index_filters_by_domain(): void
    {
        Device::factory()->security()->create();
        Device::factory()->itInfrastructure()->create();

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/devices?domain=security');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('devices.data', 1)
        );
    }

    public function test_index_filters_by_status(): void
    {
        Device::factory()->create(['status' => DeviceStatus::Active]);
        Device::factory()->create(['status' => DeviceStatus::Offline]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/devices?status=offline');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('devices.data', 1)
        );
    }

    public function test_index_search_by_name(): void
    {
        Device::factory()->create(['name' => 'Lobby Camera Alpha']);
        Device::factory()->create(['name' => 'Server Room Switch']);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/devices?search=Lobby');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('devices.data', 1)
        );
    }

    public function test_index_search_by_serial(): void
    {
        Device::factory()->create(['serial_number' => 'XYZ-999-UNIQUE']);
        Device::factory()->create(['serial_number' => 'ABC-111']);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/devices?search=XYZ-999');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('devices.data', 1)
        );
    }

    public function test_index_filters_by_assignment_state(): void
    {
        $assigned = Device::factory()->create();
        $unassigned = Device::factory()->create();

        DeviceAssignment::create([
            'device_id' => $assigned->id,
            'assignable_type' => 'site',
            'assignable_id' => 1,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/devices?assigned=no');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('devices.data', 1)
            ->where('devices.data.0.id', $unassigned->id)
        );
    }

    public function test_index_stats_saved_views_and_provider_options_cover_the_single_application_registry(): void
    {

        $profile = MonitoringProfile::factory()->create([]);
        $monitored = Device::factory()->create([
            'name' => 'Monitored device',
            'provider' => 'primary-provider',
        ]);
        $unmonitored = Device::factory()->create([
            'name' => 'Unmonitored device',
            'provider' => 'primary-provider',
        ]);
        $unassignedDevice = Device::factory()->create([
            'name' => 'Unassigned device',
            'provider' => 'legacy-provider',
        ]);
        Monitor::factory()->create([
            'profile_id' => $profile->id,
            'device_id' => $monitored->id,
            'current_state' => MonitorState::Healthy,
            'is_enabled' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/devices?view=unmonitored');

        $response->assertOk()->assertInertia(function ($page) use ($unassignedDevice, $unmonitored): void {
            $props = $page->toArray()['props'];

            $this->assertEqualsCanonicalizing(
                [$unmonitored->id, $unassignedDevice->id],
                collect($props['devices']['data'])->pluck('id')->all(),
            );
            $this->assertSame(3, $props['stats']['total']);
            $this->assertSame(2, collect($props['savedViews'])->firstWhere('key', 'unmonitored')['count']);
            $this->assertSame(['legacy-provider', 'primary-provider'], $props['filterOptions']['providers']);
            $this->assertTrue($props['can']['export']);
            $this->assertSame('unmonitored', $props['filters']['view']);
        });
    }

    public function test_inventory_and_device_direct_links_honour_site_access(): void
    {

        $allowedSite = Site::factory()->create([]);
        $hiddenSite = Site::factory()->create([]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->viewer->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
        ]);
        $allowed = Device::factory()->create(['name' => 'Allowed device']);
        $hidden = Device::factory()->create(['name' => 'Hidden device']);
        foreach ([[$allowed, $allowedSite], [$hidden, $hiddenSite]] as [$device, $site]) {
            DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $site->id,
                'assigned_at' => now(),
            ]);
        }

        $this->actingAs($this->viewer)
            ->get('/security-devices/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('devices.data', 1)
                ->where('devices.data.0.id', $allowed->id)
                ->where('can.export', false));

        $this->actingAs($this->viewer)
            ->get("/security-devices/devices/{$allowed->id}")
            ->assertOk();

        $this->actingAs($this->viewer)
            ->get("/security-devices/devices/{$hidden->id}")
            ->assertNotFound();
    }

    public function test_admin_can_open_unassigned_stock_with_all_sites_access(): void
    {

        $unrelated = Device::factory()->create([]);

        $this->actingAs($this->admin)
            ->get("/security-devices/devices/{$unrelated->id}")
            ->assertOk();
    }

    // ── Show ──────────────────────────────────────────────────────

    public function test_show_requires_authentication(): void
    {
        $device = Device::factory()->create();
        $this->get("/security-devices/devices/{$device->id}")->assertRedirect('/login');
    }

    public function test_show_requires_view_permission(): void
    {
        $device = Device::factory()->create();
        $this->actingAs($this->noPerms)
            ->get("/security-devices/devices/{$device->id}")
            ->assertForbidden();
    }

    public function test_show_renders_device_detail(): void
    {
        $device = Device::factory()->security()->create(['name' => 'Dome Camera 1']);

        $response = $this->actingAs($this->admin)
            ->get("/security-devices/devices/{$device->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('security-devices/devices/show')
            ->where('device.name', 'Dome Camera 1')
            ->has('profile.header.identity.uid')
            ->has('activeAssignment')
            ->has('assetLinks')
            ->has('recentEvents')
            ->has('maintenanceRecords')
            ->has('relationships')
            ->has('can')
        );
    }

    public function test_show_includes_permission_flags(): void
    {
        $device = Device::factory()->create();
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_STAFF,
            'assignable_id' => $this->viewer->id,
            'assigned_at' => now(),
        ]);

        // Viewer (support_worker) should not have update/delete/assign.
        $response = $this->actingAs($this->viewer)
            ->get("/security-devices/devices/{$device->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('can.update', false)
            ->where('can.delete', false)
            ->where('can.assign', false)
        );
    }

    // ── Create / Store ────────────────────────────────────────────

    public function test_create_form_requires_create_permission(): void
    {
        // Viewer doesn't have create permission.
        $this->actingAs($this->viewer)
            ->get('/security-devices/devices/create')
            ->assertForbidden();
    }

    public function test_create_form_accessible_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices/devices/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/devices/create')
                ->has('taxonomy')
                ->has('domains')
                ->has('statuses')
            );
    }

    public function test_store_creates_device(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/security-devices/devices', [
                'name' => 'New Test Camera',
                'domain' => 'security',
                'category' => 'cctv',
                'subcategory' => 'dome_camera',
                'manufacturer' => 'Hikvision',
                'serial_number' => 'HIK-TEST-001',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('devices', [
            'name' => 'New Test Camera',
            'domain' => 'security',
            'category' => 'cctv',
            'manufacturer' => 'Hikvision',
        ]);

        $device = Device::where('name', 'New Test Camera')->first();
        $this->assertNotNull($device->device_uid);
        $this->assertEquals($this->admin->id, $device->created_by_user_id);
    }

    public function test_store_uses_application_storage_defaults(): void
    {

        $this->actingAs($this->admin)
            ->post('/security-devices/devices', [
                'name' => 'Scoped Camera',
                'domain' => 'security',
                'category' => 'cctv',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('devices', [
            'name' => 'Scoped Camera',
            'created_by_user_id' => $this->admin->id,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/security-devices/devices', [
                'name' => '',
                'domain' => '',
                'category' => '',
            ]);

        $response->assertSessionHasErrors(['name', 'domain', 'category']);
    }

    public function test_store_validates_domain(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/security-devices/devices', [
                'name' => 'Test',
                'domain' => 'invalid_domain',
                'category' => 'cctv',
            ]);

        $response->assertSessionHasErrors(['domain']);
    }

    public function test_store_requires_create_permission(): void
    {
        $this->actingAs($this->viewer)
            ->post('/security-devices/devices', [
                'name' => 'Test',
                'domain' => 'security',
                'category' => 'cctv',
            ])
            ->assertForbidden();
    }

    // ── Update ────────────────────────────────────────────────────

    public function test_update_modifies_device(): void
    {
        $device = Device::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($this->admin)
            ->put("/security-devices/devices/{$device->id}", [
                'name' => 'New Name',
                'domain' => $device->domain,
                'category' => $device->category,
            ]);

        $response->assertRedirect();
        $this->assertEquals('New Name', $device->fresh()->name);
    }

    public function test_update_requires_update_permission(): void
    {
        $device = Device::factory()->create();

        $this->actingAs($this->viewer)
            ->put("/security-devices/devices/{$device->id}", [
                'name' => 'Should Fail',
                'domain' => 'security',
                'category' => 'cctv',
            ])
            ->assertForbidden();
    }

    // ── Destroy (decommission) ────────────────────────────────────

    public function test_destroy_soft_deletes_and_decommissions(): void
    {
        $device = Device::factory()->create();

        $response = $this->actingAs($this->admin)
            ->delete("/security-devices/devices/{$device->id}");

        $response->assertRedirect('/security-devices/devices');

        $device = Device::withTrashed()->find($device->id);
        $this->assertNotNull($device->deleted_at);
        $this->assertEquals(DeviceStatus::Decommissioned, $device->status);
    }

    public function test_destroy_requires_delete_permission(): void
    {
        $device = Device::factory()->create();

        $this->actingAs($this->viewer)
            ->delete("/security-devices/devices/{$device->id}")
            ->assertForbidden();
    }
}
