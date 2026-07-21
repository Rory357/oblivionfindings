<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceGroupControllerTest extends TestCase
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

        // support_worker does NOT have groups.manage.
        $this->viewer = User::factory()->create();
        $this->viewer->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->noPerms = User::factory()->create();
    }

    // ── Index ─────────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->get('/security-devices/device-groups')->assertRedirect('/login');
    }

    public function test_index_requires_groups_manage_permission(): void
    {
        $this->actingAs($this->viewer)
            ->get('/security-devices/device-groups')
            ->assertForbidden();
    }

    public function test_index_accessible_with_permission(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices/device-groups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/device-groups/index')
                ->has('groups.data')
                ->has('groups.meta')
            );
    }

    public function test_index_returns_groups_with_member_count(): void
    {
        $group = DeviceGroup::create(['tenant_id' => 1, 'name' => 'Test Group', 'type' => 'custom']);
        $device = Device::factory()->create();
        $group->devices()->attach($device);

        $response = $this->actingAs($this->admin)->get('/security-devices/device-groups');

        $response->assertInertia(fn ($page) => $page
            ->has('groups.data', 1)
            ->where('groups.data.0.devices_count', 1)
        );
    }

    public function test_index_search_by_name(): void
    {
        DeviceGroup::create(['tenant_id' => 1, 'name' => 'Auckland Office', 'type' => 'location']);
        DeviceGroup::create(['tenant_id' => 1, 'name' => 'Server Room', 'type' => 'location']);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/device-groups?search=Auckland');

        $response->assertInertia(fn ($page) => $page->has('groups.data', 1));
    }

    public function test_index_filter_by_type(): void
    {
        DeviceGroup::create(['tenant_id' => 1, 'name' => 'Location Group', 'type' => 'location']);
        DeviceGroup::create(['tenant_id' => 1, 'name' => 'Vendor Group', 'type' => 'vendor']);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/device-groups?type=vendor');

        $response->assertInertia(fn ($page) => $page->has('groups.data', 1));
    }

    // ── Show ──────────────────────────────────────────────────────

    public function test_show_renders_group_detail(): void
    {
        $group = DeviceGroup::create(['tenant_id' => 1, 'name' => 'Test Group', 'type' => 'custom']);

        $this->actingAs($this->admin)
            ->get("/security-devices/device-groups/{$group->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/device-groups/show')
                ->where('group.name', 'Test Group')
                ->has('members.data')
                ->has('availableDevices')
            );
    }

    public function test_show_lists_group_members(): void
    {
        $group = DeviceGroup::create(['tenant_id' => 1, 'name' => 'Test Group', 'type' => 'custom']);
        $device1 = Device::factory()->create(['name' => 'Device A']);
        $device2 = Device::factory()->create(['name' => 'Device B']);
        $group->devices()->attach([$device1->id, $device2->id]);

        $response = $this->actingAs($this->admin)
            ->get("/security-devices/device-groups/{$group->id}");

        $response->assertInertia(fn ($page) => $page
            ->has('members.data', 2)
            ->where('members.meta.total', 2)
        );
    }

    public function test_show_excludes_members_from_available_devices(): void
    {
        $group = DeviceGroup::create(['tenant_id' => 1, 'name' => 'Test Group', 'type' => 'custom']);
        $member = Device::factory()->create();
        $nonMember = Device::factory()->create();
        $group->devices()->attach($member);

        $response = $this->actingAs($this->admin)
            ->get("/security-devices/device-groups/{$group->id}");

        $response->assertInertia(function ($page) use ($nonMember, $member) {
            $available = collect($page->toArray()['props']['availableDevices']);
            $this->assertTrue($available->contains('id', $nonMember->id));
            $this->assertFalse($available->contains('id', $member->id));
        });
    }

    // ── Store ─────────────────────────────────────────────────────

    public function test_store_creates_group(): void
    {
        $this->actingAs($this->admin)
            ->post('/security-devices/device-groups', [
                'name' => 'New Test Group',
                'type' => 'location',
                'description' => 'A test group',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('device_groups', [
            'name' => 'New Test Group',
            'type' => 'location',
        ]);
    }

    public function test_store_uses_inert_legacy_storage_value_not_user_organization(): void
    {
        $this->admin->forceFill(['organization_id' => 77])->save();

        $this->actingAs($this->admin)
            ->post('/security-devices/device-groups', [
                'name' => 'Scoped Group',
                'type' => 'location',
                'description' => 'A scoped group',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('device_groups', [
            'name' => 'Scoped Group',
            'tenant_id' => 1,
        ]);
    }

    public function test_store_validates_required_name(): void
    {
        $this->actingAs($this->admin)
            ->post('/security-devices/device-groups', ['name' => ''])
            ->assertSessionHasErrors(['name']);
    }

    public function test_store_validates_unique_name(): void
    {
        DeviceGroup::create(['tenant_id' => 1, 'name' => 'Existing Group', 'type' => 'custom']);

        $this->actingAs($this->admin)
            ->post('/security-devices/device-groups', ['name' => 'Existing Group'])
            ->assertSessionHasErrors(['name']);
    }

    public function test_store_requires_permission(): void
    {
        $this->actingAs($this->viewer)
            ->post('/security-devices/device-groups', ['name' => 'Test'])
            ->assertForbidden();
    }

    // ── Update ────────────────────────────────────────────────────

    public function test_update_modifies_group(): void
    {
        $group = DeviceGroup::create(['tenant_id' => 1, 'name' => 'Old Name', 'type' => 'custom']);

        $this->actingAs($this->admin)
            ->put("/security-devices/device-groups/{$group->id}", [
                'name' => 'New Name',
                'type' => 'functional',
            ])
            ->assertRedirect();

        $group->refresh();
        $this->assertEquals('New Name', $group->name);
        $this->assertEquals('functional', $group->type);
    }

    // ── Destroy ───────────────────────────────────────────────────

    public function test_destroy_soft_deletes_group(): void
    {
        $group = DeviceGroup::create(['tenant_id' => 1, 'name' => 'Delete Me', 'type' => 'custom']);

        $this->actingAs($this->admin)
            ->delete("/security-devices/device-groups/{$group->id}")
            ->assertRedirect('/security-devices/device-groups');

        $this->assertSoftDeleted($group);
    }

    public function test_destroy_requires_permission(): void
    {
        $group = DeviceGroup::create(['tenant_id' => 1, 'name' => 'Test', 'type' => 'custom']);

        $this->actingAs($this->viewer)
            ->delete("/security-devices/device-groups/{$group->id}")
            ->assertForbidden();
    }

    // ── Add member ────────────────────────────────────────────────

    public function test_add_member_attaches_device(): void
    {
        $group = DeviceGroup::create(['tenant_id' => 1, 'name' => 'Test', 'type' => 'custom']);
        $device = Device::factory()->create();

        $this->actingAs($this->admin)
            ->post("/security-devices/device-groups/{$group->id}/members", [
                'device_id' => $device->id,
            ])
            ->assertRedirect();

        $this->assertTrue($group->devices()->where('devices.id', $device->id)->exists());
    }

    public function test_add_member_prevents_duplicate(): void
    {
        $group = DeviceGroup::create(['tenant_id' => 1, 'name' => 'Test', 'type' => 'custom']);
        $device = Device::factory()->create();
        $group->devices()->attach($device);

        $this->actingAs($this->admin)
            ->post("/security-devices/device-groups/{$group->id}/members", [
                'device_id' => $device->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['device_id']);
    }

    public function test_add_member_validates_device_exists(): void
    {
        $group = DeviceGroup::create(['tenant_id' => 1, 'name' => 'Test', 'type' => 'custom']);

        $this->actingAs($this->admin)
            ->post("/security-devices/device-groups/{$group->id}/members", [
                'device_id' => 99999,
            ])
            ->assertSessionHasErrors(['device_id']);
    }

    public function test_add_member_requires_permission(): void
    {
        $group = DeviceGroup::create(['tenant_id' => 1, 'name' => 'Test', 'type' => 'custom']);
        $device = Device::factory()->create();

        $this->actingAs($this->viewer)
            ->post("/security-devices/device-groups/{$group->id}/members", [
                'device_id' => $device->id,
            ])
            ->assertForbidden();
    }

    // ── Remove member ─────────────────────────────────────────────

    public function test_remove_member_detaches_device(): void
    {
        $group = DeviceGroup::create(['tenant_id' => 1, 'name' => 'Test', 'type' => 'custom']);
        $device = Device::factory()->create();
        $group->devices()->attach($device);

        $this->actingAs($this->admin)
            ->delete("/security-devices/device-groups/{$group->id}/members/{$device->id}")
            ->assertRedirect();

        $this->assertFalse($group->devices()->where('devices.id', $device->id)->exists());
    }

    public function test_remove_member_requires_permission(): void
    {
        $group = DeviceGroup::create(['tenant_id' => 1, 'name' => 'Test', 'type' => 'custom']);
        $device = Device::factory()->create();
        $group->devices()->attach($device);

        $this->actingAs($this->viewer)
            ->delete("/security-devices/device-groups/{$group->id}/members/{$device->id}")
            ->assertForbidden();
    }
}
