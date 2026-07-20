<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceAssignmentControllerTest extends TestCase
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

        $this->admin = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->viewer = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        $this->viewer->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->noPerms = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
    }

    // ── Assign ────────────────────────────────────────────────────

    public function test_assign_requires_authentication(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);
        $this->post("/security-devices/devices/{$device->id}/assign")->assertRedirect('/login');
    }

    public function test_assign_requires_assign_permission(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);
        $site = Site::factory()->create(['tenant_id' => 1]);

        $this->actingAs($this->viewer)
            ->post("/security-devices/devices/{$device->id}/assign", [
                'assignable_type' => 'site',
                'assignable_id' => $site->id,
            ])
            ->assertForbidden();
    }

    public function test_assign_to_site(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);
        $site = Site::factory()->create(['tenant_id' => 1]);

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/assign", [
                'assignable_type' => 'site',
                'assignable_id' => $site->id,
                'assignment_type' => 'permanent',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'released_at' => null,
        ]);
    }

    public function test_assign_to_staff(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);
        $site = Site::factory()->create(['tenant_id' => 1]);
        $staff = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 1,
            'user_id' => $staff->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/assign", [
                'assignable_type' => 'staff',
                'assignable_id' => $staff->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $device->id,
            'assignable_type' => 'staff',
            'assignable_id' => $staff->id,
        ]);
    }

    public function test_assign_as_loan_with_return_date(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);
        $site = Site::factory()->create(['tenant_id' => 1]);
        $returnDate = now()->addDays(14)->format('Y-m-d');

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/assign", [
                'assignable_type' => 'site',
                'assignable_id' => $site->id,
                'assignment_type' => 'loan',
                'expected_return_at' => $returnDate,
            ])
            ->assertRedirect();

        $assignment = DeviceAssignment::where('device_id', $device->id)->active()->first();
        $this->assertEquals('loan', $assignment->assignment_type->value);
        $this->assertNotNull($assignment->expected_return_at);
    }

    public function test_assign_validates_required_fields(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/assign", [])
            ->assertSessionHasErrors(['assignable_type', 'assignable_id']);
    }

    public function test_assign_validates_target_type(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/assign", [
                'assignable_type' => 'invalid',
                'assignable_id' => 1,
            ])
            ->assertSessionHasErrors(['assignable_type']);
    }

    public function test_client_assign_requires_consent(): void
    {
        $device = Device::factory()->tracking()->create(['tenant_id' => 1]);
        $site = Site::factory()->create(['tenant_id' => 1]);
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/assign", [
                'assignable_type' => 'client',
                'assignable_id' => $client->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();
    }

    public function test_transfer_releases_previous_assignment(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);
        $siteA = Site::factory()->create(['tenant_id' => 1]);
        $siteB = Site::factory()->create(['tenant_id' => 1]);

        // First assignment
        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/assign", [
                'assignable_type' => 'site',
                'assignable_id' => $siteA->id,
            ]);

        // Transfer to new site
        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/assign", [
                'assignable_type' => 'site',
                'assignable_id' => $siteB->id,
            ]);

        // Only one active assignment
        $activeCount = DeviceAssignment::where('device_id', $device->id)->active()->count();
        $this->assertEquals(1, $activeCount);

        // Total history preserved (2 records)
        $totalCount = DeviceAssignment::where('device_id', $device->id)->count();
        $this->assertEquals(2, $totalCount);

        // First assignment is released
        $first = DeviceAssignment::where('device_id', $device->id)
            ->where('assignable_id', $siteA->id)
            ->first();
        $this->assertNotNull($first->released_at);
    }

    // ── Release ───────────────────────────────────────────────────

    public function test_release_requires_authentication(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);
        $this->post("/security-devices/devices/{$device->id}/release")->assertRedirect('/login');
    }

    public function test_release_requires_assign_permission(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);

        $this->actingAs($this->viewer)
            ->post("/security-devices/devices/{$device->id}/release")
            ->assertForbidden();
    }

    public function test_release_sets_released_at(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);
        $site = Site::factory()->create(['tenant_id' => 1]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/release")
            ->assertRedirect();

        $this->assertEquals(0, DeviceAssignment::where('device_id', $device->id)->active()->count());
    }

    public function test_release_with_no_active_assignment(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/release")
            ->assertRedirect()
            ->assertSessionHas('info');
    }

    // ── History ───────────────────────────────────────────────────

    public function test_history_requires_authentication(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);
        $this->getJson("/security-devices/devices/{$device->id}/assignments")->assertUnauthorized();
    }

    public function test_history_returns_assignment_records(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);
        $siteA = Site::factory()->create(['tenant_id' => 1]);
        $siteB = Site::factory()->create(['tenant_id' => 1]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $siteA->id,
            'assigned_at' => now()->subDays(30),
            'released_at' => now()->subDays(5),
            'assigned_by_user_id' => $this->admin->id,
            'released_by_user_id' => $this->admin->id,
        ]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $siteB->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/security-devices/devices/{$device->id}/assignments");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);

        // Newest first
        $this->assertTrue($data[0]['is_active']);
        $this->assertFalse($data[1]['is_active']);
        $this->assertNotNull($data[1]['released_at']);
    }

    // ── Show page includes assignment data ────────────────────────

    public function test_show_page_includes_assignment_history_and_targets(): void
    {
        $device = Device::factory()->create(['tenant_id' => 1]);
        $site = Site::factory()->create(['tenant_id' => 1]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/security-devices/devices/{$device->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('activeAssignment')
            ->has('assignmentHistory')
            ->has('assignmentTargets.sites')
            ->has('assignmentTargets.staff')
            ->has('assignmentTargets.clients')
            ->has('assignmentTargets.vehicles')
            ->has('assignmentTargets.rooms')
            ->where('activeAssignment.assignable_type', 'site')
        );
    }
}
