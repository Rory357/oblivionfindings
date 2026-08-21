<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\LocationHardware;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthoritativeConsentFixture;
use Tests\TestCase;

class ClientDeviceRefactorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $clientA;

    private Client $clientB;

    private ClientConsent $trackingConsent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->site = Site::factory()->create(['name' => 'Client device profile Site']);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->syncWithoutDetaching([
            Role::query()->where('name', 'admin')->firstOrFail()->id,
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->admin->id,
            'employee_number' => 'SEC-CLIENT-ADMIN',
            'position_role' => 'admin',
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->clientA = Client::factory()->create(['site_id' => $this->site->id]);
        $this->clientB = Client::factory()->create(['site_id' => $this->site->id]);

        $trackingConsentType = ConsentType::factory()->create([
            'name' => 'Personal Tracker (Wandering Risk)',
            'active' => true,
        ]);
        $this->trackingConsent = AuthoritativeConsentFixture::manualSelf(
            $this->clientA,
            $trackingConsentType,
            $this->admin,
            [
                'status' => 'given',
                'given_at' => now(),
                'expires_at' => now()->addMonth(),
            ],
        );
    }

    // ── Client profile: active tracker appears ────────────────────

    public function test_client_profile_shows_active_tracking_device(): void
    {
        $device = Device::factory()->tracking()->create([
            'name' => 'GPS Pendant A',
            'serial_number' => 'GPS-001',
            'battery_level' => 75,
        ]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $this->admin->id,
            'consent_id' => $this->trackingConsent->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->clientA->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $location = $page->toArray()['props']['location'];
            $tracker = $location['tracker'];

            $this->assertNotNull($tracker);
            $this->assertEquals('GPS Pendant A', $tracker['name']);
            $this->assertEquals('GPS-001', $tracker['serial']);
            $this->assertEquals(75, $tracker['battery']);
            $this->assertArrayHasKey('device_uid', $tracker);
            $this->assertArrayHasKey('detail_url', $tracker);
            $this->assertStringContains('/security-devices/devices/', $tracker['detail_url']);
        });
    }

    // ── Released assignment does not appear ────────────────────────

    public function test_released_assignment_not_shown(): void
    {
        $device = Device::factory()->tracking()->create();

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now()->subDays(30),
            'assigned_by_user_id' => $this->admin->id,
            'released_at' => now()->subDays(5),
            'released_by_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->clientA->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $tracker = $page->toArray()['props']['location']['tracker'];
            $this->assertNull($tracker);
        });
    }

    // ── Other client's devices don't appear ───────────────────────

    public function test_other_clients_devices_not_shown(): void
    {
        $device = Device::factory()->tracking()->create(['name' => 'Client B Tracker']);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $this->clientB->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->clientA->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $tracker = $page->toArray()['props']['location']['tracker'];
            $this->assertNull($tracker);
        });
    }

    // ── No device assigned = null tracker ─────────────────────────

    public function test_no_device_assigned_returns_null_tracker(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->clientA->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $tracker = $page->toArray()['props']['location']['tracker'];
            $this->assertNull($tracker);
        });
    }

    // ── Non-tracking devices not shown ─────────────────────────────

    public function test_non_tracking_device_not_shown(): void
    {
        // Security camera assigned to client (shouldn't appear as tracker).
        $camera = Device::factory()->security()->create();

        DeviceAssignment::create([
            'device_id' => $camera->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->clientA->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $tracker = $page->toArray()['props']['location']['tracker'];
            $this->assertNull($tracker);
        });
    }

    // ── Available trackers: unassigned tracking devices only ──────

    public function test_available_trackers_only_shows_unassigned_tracking_devices(): void
    {
        $availableHardware = LocationHardware::query()->create([
            'site_id' => $this->site->id,
            'provider' => 'manual',
            'category' => LocationHardware::CATEGORY_TRACKER,
            'name' => 'Available Tracker Shadow',
            'status' => LocationHardware::STATUS_ONLINE,
        ]);

        // Unassigned tracker — should appear.
        $available = Device::factory()->tracking()->create([
            'name' => 'Available Tracker',
            'status' => DeviceStatus::Active,
            'legacy_location_hardware_id' => $availableHardware->id,
        ]);

        $assignedHardware = LocationHardware::query()->create([
            'site_id' => $this->site->id,
            'provider' => 'manual',
            'category' => LocationHardware::CATEGORY_TRACKER,
            'name' => 'Assigned Tracker Shadow',
            'status' => LocationHardware::STATUS_ONLINE,
        ]);

        // Assigned tracker — should NOT appear.
        $assigned = Device::factory()->tracking()->create([
            'name' => 'Assigned Tracker',
            'status' => DeviceStatus::Active,
            'legacy_location_hardware_id' => $assignedHardware->id,
        ]);
        DeviceAssignment::create([
            'device_id' => $assigned->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $this->clientB->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $this->admin->id,
        ]);

        // Decommissioned tracker — should NOT appear.
        $retiredHardware = LocationHardware::query()->create([
            'site_id' => $this->site->id,
            'provider' => 'manual',
            'category' => LocationHardware::CATEGORY_TRACKER,
            'name' => 'Retired Tracker Shadow',
            'status' => LocationHardware::STATUS_ONLINE,
        ]);
        Device::factory()->tracking()->create([
            'name' => 'Retired Tracker',
            'status' => DeviceStatus::Decommissioned,
            'legacy_location_hardware_id' => $retiredHardware->id,
        ]);

        // Non-tracking device — should NOT appear.
        Device::factory()->security()->create(['name' => 'Camera']);

        $response = $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->clientA->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $trackers = collect($page->toArray()['props']['available_trackers']);
            $this->assertCount(1, $trackers);
            $this->assertEquals('Available Tracker', $trackers->first()['name']);
        });
    }

    // ── Canonical fields present ──────────────────────────────────

    public function test_tracker_data_includes_canonical_fields(): void
    {
        $device = Device::factory()->tracking()->create([
            'name' => 'Tracker X',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'provider' => 'queclink',
        ]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $this->admin->id,
            'consent_id' => $this->trackingConsent->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->clientA->id}");

        $response->assertInertia(function ($page) {
            $tracker = $page->toArray()['props']['location']['tracker'];
            $this->assertNotNull($tracker);
            $this->assertArrayHasKey('id', $tracker);
            $this->assertArrayHasKey('device_uid', $tracker);
            $this->assertArrayHasKey('name', $tracker);
            $this->assertArrayHasKey('serial', $tracker);
            $this->assertArrayHasKey('mac', $tracker);
            $this->assertArrayHasKey('provider', $tracker);
            $this->assertArrayHasKey('status', $tracker);
            $this->assertArrayHasKey('health_status', $tracker);
            $this->assertArrayHasKey('last_seen_at', $tracker);
            $this->assertArrayHasKey('battery', $tracker);
            $this->assertArrayHasKey('detail_url', $tracker);
            $this->assertEquals('queclink', $tracker['provider']);
            $this->assertEquals('AA:BB:CC:DD:EE:FF', $tracker['mac']);
        });
    }

    // ── Helper ─────────────────────────────────────────────────────

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'."
        );
    }
}
