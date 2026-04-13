<?php

namespace Tests\Feature\SecurityDevices;

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

/**
 * Verify that all consumer paths previously reading from LocationHardware
 * now read from the canonical Security & Devices registry (devices table).
 */
class LocationHardwareRetirementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    // ── Sites hardware now reads from canonical devices ───────────

    public function test_site_hardware_page_reads_from_canonical_devices(): void
    {
        $site = Site::factory()->create();

        $device = Device::factory()->create(['name' => 'Canonical Camera']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$site->id}/hardware");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['devices'];
            $this->assertCount(1, $devices);
            // Verify canonical field present (not available on legacy LocationHardware).
            $this->assertArrayHasKey('device_uid', $devices[0]);
            $this->assertArrayHasKey('health_status', $devices[0]);
        });
    }

    // ── Client tracker reads from canonical devices ───────────────

    public function test_client_profile_tracker_reads_from_canonical_devices(): void
    {
        $client = Client::factory()->create();

        $device = Device::factory()->tracking()->create(['name' => 'Client GPS']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/operations/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $tracker = $page->toArray()['props']['location']['tracker'];
            $this->assertNotNull($tracker);
            // Canonical field — not present on legacy LocationHardware.
            $this->assertArrayHasKey('device_uid', $tracker);
            $this->assertArrayHasKey('detail_url', $tracker);
        });
    }

    // ── Fleet devices reads from canonical devices ────────────────

    public function test_fleet_devices_page_reads_from_canonical_devices(): void
    {
        Device::factory()->tracking()->create(['name' => 'Fleet Tracker']);

        $response = $this->actingAs($this->admin)
            ->get('/fleet-assets/devices');

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['devices']['data'];
            $this->assertCount(1, $devices);
            $this->assertEquals('canonical', $devices[0]['source']);
            $this->assertArrayHasKey('device_uid', $devices[0]);
        });
    }

    // ── Resident tracking reads from canonical devices ─────────────

    public function test_resident_tracking_reads_from_canonical_devices(): void
    {
        $client = Client::factory()->create(['status' => 'active']);

        $device = Device::factory()->tracking()->create(['name' => 'Resident Pendant']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/fleet-assets/resident-tracking');

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $residents = $page->toArray()['props']['residents'];
            $this->assertCount(1, $residents);
            $this->assertArrayHasKey('device_uid', $residents[0]);
            $this->assertArrayHasKey('detail_url', $residents[0]);
        });
    }

    // ── UniFi settings reads from canonical devices ───────────────

    public function test_unifi_settings_reads_from_canonical_devices(): void
    {
        Device::factory()->create(['provider' => 'unifi', 'name' => 'Canonical UniFi AP']);

        $response = $this->actingAs($this->admin)
            ->get('/settings/integrations/unifi');

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['syncedDevices'];
            $this->assertCount(1, $devices);
            $this->assertArrayHasKey('device_uid', $devices[0]);
            $this->assertArrayHasKey('detail_url', $devices[0]);
        });
    }

    // ── Security & Devices module reads from canonical devices ─────

    public function test_security_devices_dashboard_reads_from_canonical_devices(): void
    {
        Device::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->get('/security-devices');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('stats.totalDevices', 3)
        );
    }

    // ── LocationHardware model is marked deprecated ───────────────

    public function test_location_hardware_model_has_deprecation_annotation(): void
    {
        $reflection = new \ReflectionClass(\App\Models\LocationHardware::class);
        $docComment = $reflection->getDocComment();

        $this->assertNotFalse($docComment);
        $this->assertStringContainsString('@deprecated', $docComment);
    }
}
