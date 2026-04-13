<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteHardwareRefactorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $noPerms;
    private Site $siteA;
    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->noPerms = User::factory()->create();

        $this->siteA = Site::factory()->create(['name' => 'Site Alpha']);
        $this->siteB = Site::factory()->create(['name' => 'Site Beta']);
    }

    // ── Authorization ─────────────────────────────────────────────

    public function test_requires_authentication(): void
    {
        $this->get("/sites/{$this->siteA->id}/hardware")->assertRedirect('/login');
    }

    // ── Data source is canonical devices ──────────────────────────

    public function test_returns_devices_assigned_to_site(): void
    {
        $device = Device::factory()->create(['name' => 'Camera Alpha']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['devices'];
            $this->assertCount(1, $devices);
            $this->assertEquals('Camera Alpha', $devices[0]['name']);
            $this->assertArrayHasKey('device_uid', $devices[0]);
            $this->assertArrayHasKey('domain', $devices[0]);
            $this->assertArrayHasKey('health_status', $devices[0]);
        });
    }

    public function test_returns_devices_assigned_to_rooms_within_site(): void
    {
        $room = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $this->siteA->id,
            'name' => 'Server Room',
        ]);

        $device = Device::factory()->itInfrastructure()->create(['name' => 'Core Switch']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'room',
            'assignable_id' => $room->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) use ($room) {
            $devices = $page->toArray()['props']['devices'];
            $this->assertCount(1, $devices);
            $this->assertEquals('Core Switch', $devices[0]['name']);
            $this->assertEquals('room', $devices[0]['assignment_type']);
            $this->assertEquals($room->id, $devices[0]['assignment_id']);
        });
    }

    public function test_does_not_return_devices_from_other_sites(): void
    {
        // Device at Site A.
        $deviceA = Device::factory()->create(['name' => 'Device at A']);
        DeviceAssignment::create([
            'device_id' => $deviceA->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now(),
        ]);

        // Device at Site B.
        $deviceB = Device::factory()->create(['name' => 'Device at B']);
        DeviceAssignment::create([
            'device_id' => $deviceB->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteB->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['devices'];
            $this->assertCount(1, $devices);
            $this->assertEquals('Device at A', $devices[0]['name']);
        });
    }

    public function test_does_not_return_released_assignments(): void
    {
        $device = Device::factory()->create(['name' => 'Former Device']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now()->subDays(30),
            'released_at' => now()->subDays(5), // released
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['devices'];
            $this->assertCount(0, $devices);
        });
    }

    public function test_unassigned_devices_do_not_appear(): void
    {
        // Device with no assignment.
        Device::factory()->create(['name' => 'Floating Device']);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $this->assertCount(0, $page->toArray()['props']['devices']);
        });
    }

    // ── Device data shape ─────────────────────────────────────────

    public function test_device_data_includes_canonical_fields(): void
    {
        $device = Device::factory()->security()->create([
            'name' => 'Dome Camera',
            'manufacturer' => 'Hikvision',
            'model' => 'DS-2CD2143G2',
            'serial_number' => 'HIK-001',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'ip_address' => '192.168.1.100',
            'firmware_version' => '5.7.1',
            'battery_level' => null,
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['devices'][0];
            $this->assertEquals('Dome Camera', $d['name']);
            $this->assertNotEmpty($d['device_uid']);
            $this->assertEquals('security', $d['domain']);
            $this->assertEquals('cctv', $d['category']);
            $this->assertEquals('Hikvision', $d['manufacturer']);
            $this->assertEquals('DS-2CD2143G2', $d['model']);
            $this->assertEquals('HIK-001', $d['serial_number']);
            $this->assertEquals('AA:BB:CC:DD:EE:FF', $d['mac_address']);
            $this->assertEquals('192.168.1.100', $d['ip_address']);
            $this->assertEquals('5.7.1', $d['firmware_version']);
            $this->assertArrayHasKey('status', $d);
            $this->assertArrayHasKey('health_status', $d);
        });
    }

    // ── Integration/rooms data still present ──────────────────────

    public function test_rooms_and_unifi_data_still_passed(): void
    {
        SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $this->siteA->id,
            'name' => 'Reception',
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $this->assertNotEmpty($page->toArray()['props']['rooms']);
            $this->assertArrayHasKey('unifi', $page->toArray()['props']);
            $this->assertArrayHasKey('categories', $page->toArray()['props']);
            $this->assertArrayHasKey('can', $page->toArray()['props']);
        });
    }
}
