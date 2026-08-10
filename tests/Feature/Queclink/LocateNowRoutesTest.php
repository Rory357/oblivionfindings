<?php

namespace Tests\Feature\Queclink;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocateNowRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
    }

    public function test_authorized_user_is_handed_to_governed_location_refresh_from_resident_tracking(): void
    {
        ['client' => $client, 'device' => $device] = $this->createPairedResidentTracker('861106050000001');

        $this->actingAs($this->admin)
            ->from('/fleet-assets/resident-tracking')
            ->post("/fleet-assets/resident-tracking/{$client->id}/locate-now")
            ->assertRedirect("/security-devices/devices/{$device->id}?section=management&action=tracking.location_refresh")
            ->assertSessionHas('success');

        $this->assertDatabaseCount('queclink_pending_commands', 0);
        $this->assertDatabaseCount('device_command_requests', 0);
    }

    public function test_authorized_user_is_handed_to_governed_location_refresh_from_client_location_tab(): void
    {
        ['client' => $client, 'device' => $device] = $this->createPairedResidentTracker('861106050000002');

        $this->actingAs($this->admin)
            ->from("/operations/clients/{$client->id}?tab=location")
            ->post("/operations/clients/{$client->id}/location/locate-now")
            ->assertRedirect("/security-devices/devices/{$device->id}?section=management&action=tracking.location_refresh")
            ->assertSessionHas('success');

        $this->assertDatabaseCount('queclink_pending_commands', 0);
        $this->assertDatabaseCount('device_command_requests', 0);
    }

    public function test_locate_now_requires_a_resident_tracker(): void
    {
        $client = Client::create([
            'first_name' => 'No',
            'last_name' => 'Tracker',
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
        $this->createTrackingConsent($client);

        $this->actingAs($this->admin)
            ->post("/fleet-assets/resident-tracking/{$client->id}/locate-now")
            ->assertForbidden();

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_locate_now_rejects_unpaired_or_non_queclink_tracker(): void
    {
        $client = Client::create([
            'first_name' => 'Manual',
            'last_name' => 'Tracker',
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
        $consent = $this->createTrackingConsent($client);
        $device = Device::factory()->tracking()->create([
            'provider' => 'manual',
            'imei' => 'MANUAL-001',
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assigned_at' => now(),
            'consent_id' => $consent->id,
        ]);

        $this->actingAs($this->admin)
            ->from("/operations/clients/{$client->id}?tab=location")
            ->post("/operations/clients/{$client->id}/location/locate-now")
            ->assertRedirect("/operations/clients/{$client->id}?tab=location")
            ->assertSessionHasErrors('tracker');

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    /**
     * @return array{client: Client, device: Device, queclinkDevice: QueclinkDevice}
     */
    private function createPairedResidentTracker(string $imei): array
    {
        $client = Client::create([
            'first_name' => 'Amelia',
            'last_name' => 'Wilson',
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
        $consent = $this->createTrackingConsent($client);
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => $imei,
            'device_uid' => $imei,
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assigned_at' => now(),
            'consent_id' => $consent->id,
        ]);
        $queclinkDevice = QueclinkDevice::create([
            'imei' => $imei,
            'device_id' => $device->id,
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        return compact('client', 'device', 'queclinkDevice');
    }

    private function createTrackingConsent(Client $client): ClientConsent
    {
        $type = ConsentType::query()->firstOrCreate(
            ['name' => 'Asset Location Tracking (Safety)'],
            [
                'category' => 'operational',
                'description' => 'Fleet location tracking',
                'purpose' => 'Resident tracker safety',
                'legal_basis' => 'consent',
                'allows_withdrawal' => true,
                'active' => true,
            ],
        );
        $version = ConsentTypeVersion::query()->firstOrCreate(
            ['consent_type_id' => $type->id, 'version' => 1],
            [
                'description' => 'Fleet tracking v1',
                'purpose' => 'Resident tracker safety',
                'legal_basis' => 'consent',
                'effective_from' => now()->subDay(),
            ],
        );

        return ClientConsent::query()->create([
            'client_id' => $client->id,
            'consent_type_id' => $type->id,
            'consent_type_version_id' => $version->id,
            'status' => 'given',
            'given_at' => now(),
            'given_by_user_id' => $this->admin->id,
            'given_method' => 'electronic',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }
}
