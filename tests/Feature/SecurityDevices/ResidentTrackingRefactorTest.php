<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResidentTrackingRefactorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Client $clientA;
    private Client $clientB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->clientA = Client::factory()->create(['status' => 'active']);
        $this->clientB = Client::factory()->create(['status' => 'active']);
    }

    // ── Index: reads from canonical devices ────────────────────────

    public function test_index_shows_client_assigned_tracking_devices(): void
    {
        $device = Device::factory()->tracking()->create(['name' => 'Resident Tracker 1']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/fleet-assets/resident-tracking');

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $residents = $page->toArray()['props']['residents'];
            $this->assertCount(1, $residents);
            $this->assertEquals('Resident Tracker 1', $residents[0]['tracker_name']);
            $this->assertArrayHasKey('device_uid', $residents[0]);
            $this->assertArrayHasKey('detail_url', $residents[0]);
        });
    }

    public function test_index_excludes_released_assignments(): void
    {
        $device = Device::factory()->tracking()->create();
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now()->subDays(30),
            'released_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/fleet-assets/resident-tracking');

        $response->assertInertia(function ($page) {
            $this->assertCount(0, $page->toArray()['props']['residents']);
        });
    }

    public function test_index_excludes_other_clients_devices(): void
    {
        $device = Device::factory()->tracking()->create();
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->clientB->id,
            'assigned_at' => now(),
        ]);

        // Should appear because admin can see all clients.
        $response = $this->actingAs($this->admin)
            ->get('/fleet-assets/resident-tracking');

        $response->assertInertia(function ($page) {
            $residents = $page->toArray()['props']['residents'];
            $this->assertCount(1, $residents);
            $this->assertEquals($this->clientB->id, $residents[0]['client_id']);
        });
    }

    // ── Assign page: uses canonical devices ───────────────────────

    public function test_assign_page_shows_unassigned_trackers(): void
    {
        $available = Device::factory()->tracking()->create(['name' => 'Free Tracker']);
        $assigned = Device::factory()->tracking()->create(['name' => 'Busy Tracker']);

        DeviceAssignment::create([
            'device_id' => $assigned->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/fleet-assets/resident-tracking/assign');

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $available = collect($page->toArray()['props']['available_trackers']);
            $assigned = collect($page->toArray()['props']['assigned_trackers']);

            $this->assertCount(1, $available);
            $this->assertEquals('Free Tracker', $available->first()['name']);
            $this->assertCount(1, $assigned);
            $this->assertEquals('Busy Tracker', $assigned->first()['name']);
        });
    }

    // ── Assign: creates canonical device assignment ────────────────

    public function test_assign_creates_device_assignment(): void
    {
        $device = Device::factory()->tracking()->create();

        $response = $this->actingAs($this->admin)
            ->post('/fleet-assets/resident-tracking/assign', [
                'tracker_id' => $device->id,
                'client_id' => $this->clientA->id,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->clientA->id,
        ]);
    }

    // ── Unassign: releases canonical device assignment ─────────────

    public function test_unassign_releases_device_assignment(): void
    {
        $device = Device::factory()->tracking()->create();
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->post("/fleet-assets/resident-tracking/{$device->id}/unassign");

        $response->assertRedirect();

        $this->assertEquals(0, DeviceAssignment::where('device_id', $device->id)->active()->count());
    }

    // ── History: uses canonical device lookup ──────────────────────

    public function test_history_returns_tracker_info_from_canonical_device(): void
    {
        $device = Device::factory()->tracking()->create([
            'name' => 'History Tracker',
            'serial_number' => 'HIS-001',
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/resident-tracking/history/{$this->clientA->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $tracker = $page->toArray()['props']['tracker'];
            $this->assertNotNull($tracker);
            $this->assertEquals('History Tracker', $tracker['name']);
            $this->assertEquals('HIS-001', $tracker['serial']);
            $this->assertArrayHasKey('device_uid', $tracker);
            $this->assertArrayHasKey('detail_url', $tracker);
        });
    }

    public function test_history_returns_null_tracker_when_none_assigned(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/resident-tracking/history/{$this->clientA->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $this->assertNull($page->toArray()['props']['tracker']);
        });
    }

    // ── Canonical fields present ──────────────────────────────────

    public function test_resident_data_includes_canonical_fields(): void
    {
        $device = Device::factory()->tracking()->create([
            'battery_level' => 65,
            'provider' => 'queclink',
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/fleet-assets/resident-tracking');

        $response->assertInertia(function ($page) {
            $r = $page->toArray()['props']['residents'][0];
            $this->assertArrayHasKey('id', $r);
            $this->assertArrayHasKey('device_uid', $r);
            $this->assertArrayHasKey('tracker_name', $r);
            $this->assertArrayHasKey('tracker_serial', $r);
            $this->assertArrayHasKey('status', $r);
            $this->assertArrayHasKey('health_status', $r);
            $this->assertArrayHasKey('battery', $r);
            $this->assertArrayHasKey('detail_url', $r);
            $this->assertEquals(65, $r['battery']);
        });
    }
}
