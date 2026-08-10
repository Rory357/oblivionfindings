<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
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
        $group = DeviceGroup::create(['name' => 'Test Group', 'type' => 'custom']);
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
        DeviceGroup::create(['name' => 'Auckland Office', 'type' => 'location']);
        DeviceGroup::create(['name' => 'Server Room', 'type' => 'location']);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/device-groups?search=Auckland');

        $response->assertInertia(fn ($page) => $page->has('groups.data', 1));
    }

    public function test_index_filter_by_type(): void
    {
        DeviceGroup::create(['name' => 'Location Group', 'type' => 'location']);
        DeviceGroup::create(['name' => 'Vendor Group', 'type' => 'vendor']);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/device-groups?type=vendor');

        $response->assertInertia(fn ($page) => $page->has('groups.data', 1));
    }

    // ── Show ──────────────────────────────────────────────────────

    public function test_show_renders_group_detail(): void
    {
        $group = DeviceGroup::create(['name' => 'Test Group', 'type' => 'custom']);

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
        $group = DeviceGroup::create(['name' => 'Test Group', 'type' => 'custom']);
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
        $group = DeviceGroup::create(['name' => 'Test Group', 'type' => 'custom']);
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

    public function test_store_uses_application_storage_defaults(): void
    {

        $this->actingAs($this->admin)
            ->post('/security-devices/device-groups', [
                'name' => 'Scoped Group',
                'type' => 'location',
                'description' => 'A scoped group',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('device_groups', [
            'name' => 'Scoped Group',
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
        DeviceGroup::create(['name' => 'Existing Group', 'type' => 'custom']);

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
        $group = DeviceGroup::create(['name' => 'Old Name', 'type' => 'custom']);

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
        $group = DeviceGroup::create(['name' => 'Delete Me', 'type' => 'custom']);

        $this->actingAs($this->admin)
            ->delete("/security-devices/device-groups/{$group->id}")
            ->assertRedirect('/security-devices/device-groups');

        $this->assertSoftDeleted($group);
    }

    public function test_destroy_requires_permission(): void
    {
        $group = DeviceGroup::create(['name' => 'Test', 'type' => 'custom']);

        $this->actingAs($this->viewer)
            ->delete("/security-devices/device-groups/{$group->id}")
            ->assertForbidden();
    }

    // ── Add member ────────────────────────────────────────────────

    public function test_add_member_attaches_device(): void
    {
        $group = DeviceGroup::create(['name' => 'Test', 'type' => 'custom']);
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
        $group = DeviceGroup::create(['name' => 'Test', 'type' => 'custom']);
        $device = Device::factory()->create();
        $group->devices()->attach($device);

        $this->actingAs($this->admin)
            ->post("/security-devices/device-groups/{$group->id}/members", [
                'device_id' => $device->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['device_id']);
    }

    public function test_add_member_conceals_a_missing_device(): void
    {
        $group = DeviceGroup::create(['name' => 'Test', 'type' => 'custom']);

        $this->actingAs($this->admin)
            ->post("/security-devices/device-groups/{$group->id}/members", [
                'device_id' => 99999,
            ])
            ->assertNotFound();
    }

    public function test_add_member_requires_permission(): void
    {
        $group = DeviceGroup::create(['name' => 'Test', 'type' => 'custom']);
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
        $group = DeviceGroup::create(['name' => 'Test', 'type' => 'custom']);
        $device = Device::factory()->create();
        $group->devices()->attach($device);

        $this->actingAs($this->admin)
            ->delete("/security-devices/device-groups/{$group->id}/members/{$device->id}")
            ->assertRedirect();

        $this->assertFalse($group->devices()->where('devices.id', $device->id)->exists());
    }

    public function test_remove_member_requires_permission(): void
    {
        $group = DeviceGroup::create(['name' => 'Test', 'type' => 'custom']);
        $device = Device::factory()->create();
        $group->devices()->attach($device);

        $this->actingAs($this->viewer)
            ->delete("/security-devices/device-groups/{$group->id}/members/{$device->id}")
            ->assertForbidden();
    }

    public function test_group_counts_lists_auto_rules_and_direct_mutations_preserve_private_device_boundaries(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $actor = User::factory()->create(['approved_at' => now()]);
        $permissionIds = Permission::query()->whereIn('key', [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.devices.viewAllSites',
            'securityDevices.groups.manage',
        ])->pluck('id');
        $actor->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all(),
        );

        $visibleMember = Device::factory()->tracking()->create(['name' => 'Visible Site Tracker']);
        $visibleCandidate = Device::factory()->tracking()->create(['name' => 'Visible Candidate Tracker']);
        $privateMember = Device::factory()->security()->create(['name' => 'Private Client Camera']);
        $privateCandidate = Device::factory()->tracking()->create(['name' => 'Private Client Tracker']);
        foreach ([$visibleMember, $visibleCandidate] as $device) {
            DeviceAssignment::query()->create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $site->id,
                'assigned_at' => now(),
            ]);
        }
        foreach ([$privateMember, $privateCandidate] as $device) {
            DeviceAssignment::query()->create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_CLIENT,
                'assignable_id' => $client->id,
                'assigned_at' => now(),
            ]);
        }

        $group = DeviceGroup::query()->create([
            'name' => 'Governed tracking group',
            'type' => 'functional',
            'auto_rules' => [
                'match' => 'all',
                'conditions' => [['field' => 'domain', 'op' => 'equals', 'value' => 'tracking']],
            ],
        ]);
        $group->devices()->attach([$visibleMember->id, $privateMember->id]);

        $this->assertFalse(Gate::forUser($actor)->allows('view', $privateMember));
        $this->assertFalse(Gate::forUser($actor)->allows('update', $privateMember));

        $this->actingAs($actor)
            ->get('/security-devices/device-groups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('groups.data.0.devices_count', 1));

        $this->actingAs($actor)
            ->get("/security-devices/device-groups/{$group->id}")
            ->assertOk()
            ->assertInertia(function ($page) use ($visibleMember, $visibleCandidate, $privateMember, $privateCandidate): void {
                $props = $page->toArray()['props'];
                $memberIds = collect($props['members']['data'])->pluck('id');
                $availableIds = collect($props['availableDevices'])->pluck('id');

                $this->assertSame([$visibleMember->id], $memberIds->all());
                $this->assertSame(1, $props['members']['meta']['total']);
                $this->assertTrue($availableIds->contains($visibleCandidate->id));
                $this->assertFalse($availableIds->contains($privateMember->id));
                $this->assertFalse($availableIds->contains($privateCandidate->id));
                $this->assertStringNotContainsString('Private Client', json_encode($props, JSON_THROW_ON_ERROR));
            });

        $preview = $this->actingAs($actor)
            ->getJson("/security-devices/device-groups/{$group->id}/auto-rules/preview")
            ->assertOk()
            ->assertJson(['count' => 2]);
        $this->assertEqualsCanonicalizing(
            [$visibleMember->id, $visibleCandidate->id],
            collect($preview->json('sample'))->pluck('id')->all(),
        );

        $this->actingAs($actor)
            ->post("/security-devices/device-groups/{$group->id}/members", ['device_id' => $privateCandidate->id])
            ->assertNotFound();
        $this->actingAs($actor)
            ->delete("/security-devices/device-groups/{$group->id}/members/{$privateMember->id}")
            ->assertNotFound();
        $this->actingAs($actor)
            ->post("/security-devices/device-groups/{$group->id}/auto-rules/sync")
            ->assertRedirect();

        $memberIds = $group->devices()->pluck('devices.id')->all();
        $this->assertContains($visibleMember->id, $memberIds);
        $this->assertContains($visibleCandidate->id, $memberIds);
        $this->assertContains($privateMember->id, $memberIds);
        $this->assertNotContains($privateCandidate->id, $memberIds);
    }
}
