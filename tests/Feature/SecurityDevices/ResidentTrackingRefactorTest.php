<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\FleetTelemetryEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthoritativeConsentFixture;
use Tests\TestCase;

class ResidentTrackingRefactorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $clientA;

    private Client $clientB;

    private Site $site;

    private int $clientAConsentId;

    private int $clientBConsentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',

        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->site = Site::factory()->create([]);
        $this->clientA = Client::factory()->create([

            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
        $this->clientB = Client::factory()->create([

            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
        $this->clientAConsentId = $this->createResidentTrackingConsent($this->clientA)->id;
        $this->clientBConsentId = $this->createResidentTrackingConsent($this->clientB)->id;
    }

    // ── Index: reads from canonical devices ────────────────────────

    public function test_index_shows_client_assigned_tracking_devices(): void
    {
        $device = Device::factory()->tracking()->create([
            'name' => 'Resident Tracker 1',
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now(),
            'consent_id' => $this->clientAConsentId,
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
        $device = Device::factory()->tracking()->create([]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now()->subDays(30),
            'released_at' => now()->subDays(5),
            'consent_id' => $this->clientAConsentId,
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/fleet-assets/resident-tracking');

        $response->assertInertia(function ($page) {
            $this->assertCount(0, $page->toArray()['props']['residents']);
        });
    }

    public function test_index_excludes_other_clients_devices(): void
    {
        $device = Device::factory()->tracking()->create([]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->clientB->id,
            'assigned_at' => now(),
            'consent_id' => $this->clientBConsentId,
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
        $available = Device::factory()->tracking()->create([
            'name' => 'Free Tracker',
        ]);
        $assigned = Device::factory()->tracking()->create([
            'name' => 'Busy Tracker',
        ]);

        DeviceAssignment::create([
            'device_id' => $assigned->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now(),
            'consent_id' => $this->clientAConsentId,
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/fleet-assets/resident-tracking/assign');

        $response->assertRedirect('/fleet-assets/resident-tracking?new=1');

        $this->actingAs($this->admin)
            ->get('/fleet-assets/resident-tracking?new=1')
            ->assertOk()
            ->assertInertia(function ($page) {
                $assign = $page->toArray()['props']['assign'];
                $available = collect($assign['available_trackers']);
                $assigned = collect($assign['assigned_trackers']);

                $this->assertCount(1, $available);
                $this->assertEquals('Free Tracker', $available->first()['name']);
                $this->assertCount(1, $assigned);
                $this->assertEquals('Busy Tracker', $assigned->first()['name']);
            });
    }

    // ── Assign: creates canonical device assignment ────────────────

    public function test_assign_creates_device_assignment(): void
    {
        $device = Device::factory()->tracking()->create([]);

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
            'consent_id' => $this->clientAConsentId,
        ]);
    }

    private function createResidentTrackingConsent(Client $client, array $overrides = []): ClientConsent
    {
        $type = ConsentType::firstOrCreate(
            ['name' => 'Personal Tracker (Wandering Risk)'],
            [
                'category' => 'safety',
                'description' => 'Resident personal location tracking consent',
                'purpose' => 'Resident wandering-risk location collection',
                'legal_basis' => 'consent',
                'is_mandatory' => false,
                'requires_capacity_assessment' => false,
                'allows_withdrawal' => true,
                'renewal_required' => false,
                'active' => true,
            ],
        );

        $version = ConsentTypeVersion::firstOrCreate(
            ['consent_type_id' => $type->id, 'version' => 1],
            [
                'description' => 'Resident tracking v1',
                'purpose' => 'Resident wandering-risk location collection',
                'legal_basis' => 'consent',
                'effective_from' => now()->subDay(),
            ],
        );

        return AuthoritativeConsentFixture::manualSelf($client, $type, $this->admin, array_merge([
            'status' => 'given',
            'given_at' => now(),
        ], $overrides));
    }

    // ── Unassign: releases canonical device assignment ─────────────

    public function test_unassign_releases_device_assignment(): void
    {
        $device = Device::factory()->tracking()->create([]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now()->subHour(),
            'consent_id' => $this->clientAConsentId,
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
            'consent_id' => $this->clientAConsentId,
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

    public function test_history_direct_url_is_forbidden_when_none_assigned(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/resident-tracking/history/{$this->clientA->id}");

        $response->assertForbidden();
    }

    public function test_history_includes_fleet_telemetry_for_canonical_tracker(): void
    {
        $asset = Asset::create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->site->id,
            'name' => 'Amelia pendant',
            'status' => 'active',
            'risk_level' => 'medium',
            'category' => 'personal_tracker',
        ]);
        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-AMELIA',
            'imei' => 'QUE-AMELIA',
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $device = Device::factory()->tracking()->create([
            'name' => 'Amelia tracker',
            'provider' => 'queclink',
            'imei' => 'QUE-AMELIA',
            'device_uid' => 'QUE-AMELIA',
            'legacy_asset_tracker_id' => $tracker->id,
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->clientA->id,
            'assigned_at' => now()->subHour(),
            'consent_id' => $this->clientAConsentId,
        ]);
        FleetTelemetryEvent::create([
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'vendor' => 'queclink',
            'occurred_at' => now()->subMinute(),
            'received_at' => now(),
            'latitude' => -36.8485,
            'longitude' => 174.7633,
            'speed_kph' => 7.5,
            'battery_pct' => 88,
            'event_type' => 'location_report',
            'idempotency_key' => 'history-queclink-amalia',
            'raw_payload' => ['lat' => -36.8485, 'lng' => 174.7633],
            'consent_blocked' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/resident-tracking/history/{$this->clientA->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $locations = $page->toArray()['props']['locations'];
            $this->assertCount(1, $locations);
            $this->assertEqualsWithDelta(-36.8485, $locations[0]['lat'], 0.0001);
            $this->assertEqualsWithDelta(174.7633, $locations[0]['lng'], 0.0001);
            $this->assertEquals(7.5, $locations[0]['speed']);
            $this->assertEquals(88, $locations[0]['battery']);
            $this->assertEquals('location_report', $locations[0]['event_type']);
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
            'consent_id' => $this->clientAConsentId,
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
