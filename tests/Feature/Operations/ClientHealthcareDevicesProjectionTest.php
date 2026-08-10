<?php

namespace Tests\Feature\Operations;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientHealthcareDevicesProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public function test_client_profile_lazily_projects_only_assigned_technical_healthcare_device_context(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $outsideSite = Site::factory()->create(['tenant_id' => 1]);
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
            'first_name' => 'Mere',
        ]);
        $outsideClient = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $outsideSite->id,
        ]);
        $viewer = $this->viewerWithRole('admin');
        $device = Device::factory()->iotHealthcare()->create([
            'name' => 'Mere bed sensor',
            'status' => DeviceStatus::Offline,
            'battery_level' => 64,
            'config' => [
                'connectivity_state' => 'offline',
                'integration_state' => 'healthy',
                'last_successful_delivery_at' => now()->subMinutes(5)->toIso8601String(),
                'clinical_reading' => 'CLINICAL-READING-SENTINEL',
                'clinical_threshold' => 'CLINICAL-THRESHOLD-SENTINEL',
            ],
            'meta' => [
                'diagnosis' => 'DIAGNOSIS-SENTINEL',
                'medication' => 'MEDICATION-SENTINEL',
                'private_note' => 'PRIVATE-NOTE-SENTINEL',
            ],
        ]);
        $outsideDevice = Device::factory()->iotHealthcare()->create([
            'name' => 'OUTSIDE-HEALTHCARE-DEVICE-SENTINEL',
        ]);
        $this->assignToClient($device, $client);
        $this->assignToClient($outsideDevice, $outsideClient);
        $ticket = ItTicket::factory()->create([
            'requester_user_id' => $viewer->id,
            'site_id' => $site->id,
            'title' => 'Restore bed sensor delivery',
            'status' => 'open',
        ]);
        ItTicketLink::query()->create([
            'ticket_id' => $ticket->id,
            'relationship' => 'affected_device',
            'linkable_type' => Device::class,
            'linkable_id' => $device->id,
            'created_by_user_id' => $viewer->id,
        ]);

        $this->actingAs($viewer)
            ->get("/operations/clients/{$client->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('operations/clients/show')
                ->where('profile_section_access.healthcare_devices', true)
                ->where('can.view_healthcare_devices', true)
                ->missing('healthcare_devices'));

        $response = $this->partialHealthcareDevices($viewer, $client)
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'operations/clients/show')
            ->assertJsonPath('props.healthcare_devices.summary.total', 1)
            ->assertJsonPath('props.healthcare_devices.summary.offline', 1)
            ->assertJsonPath('props.healthcare_devices.devices.0.id', $device->id)
            ->assertJsonPath('props.healthcare_devices.devices.0.name', 'Mere bed sensor')
            ->assertJsonPath('props.healthcare_devices.devices.0.technical.battery.level', 64)
            ->assertJsonPath('props.healthcare_devices.devices.0.technical.flow.state', 'offline')
            ->assertJsonPath('props.healthcare_devices.devices.0.it_tickets.0.id', $ticket->id)
            ->assertJsonPath('props.healthcare_devices.links.healthcare', '/security-devices/healthcare?tab=client-devices');

        $payload = json_encode($response->json('props.healthcare_devices'), JSON_THROW_ON_ERROR);
        foreach ([
            'OUTSIDE-HEALTHCARE-DEVICE-SENTINEL',
            'CLINICAL-READING-SENTINEL',
            'CLINICAL-THRESHOLD-SENTINEL',
            'DIAGNOSIS-SENTINEL',
            'MEDICATION-SENTINEL',
            'PRIVATE-NOTE-SENTINEL',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
        $projectedDevice = $response->json('props.healthcare_devices.devices.0');
        $this->assertArrayNotHasKey('config', $projectedDevice);
        $this->assertArrayNotHasKey('meta', $projectedDevice);
    }

    public function test_client_profile_conceals_healthcare_devices_when_source_permission_is_denied(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
        ]);
        $viewer = $this->viewerWithRole('admin');
        $permissionId = Permission::query()
            ->where('key', 'securityDevices.devices.view')
            ->value('id');
        $viewer->permissionOverrides()->syncWithoutDetaching([
            $permissionId => ['allowed' => false],
        ]);
        $device = Device::factory()->iotHealthcare()->create([
            'name' => 'PRIVATE-HEALTHCARE-DEVICE-SENTINEL',
        ]);
        $this->assignToClient($device, $client);

        $this->actingAs($viewer)
            ->get("/operations/clients/{$client->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('profile_section_access.healthcare_devices', false)
                ->where('can.view_healthcare_devices', false)
                ->missing('healthcare_devices'));

        $response = $this->partialHealthcareDevices($viewer, $client)
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('props.healthcare_devices', null);

        $this->assertStringNotContainsString(
            'PRIVATE-HEALTHCARE-DEVICE-SENTINEL',
            json_encode($response->json(), JSON_THROW_ON_ERROR),
        );
    }

    private function viewerWithRole(string $role): User
    {
        $viewer = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        $viewer->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $viewer;
    }

    private function partialHealthcareDevices(User $viewer, Client $client)
    {
        $version = app(HandleInertiaRequests::class)->version(request());

        return $this->actingAs($viewer)->get("/operations/clients/{$client->id}", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'operations/clients/show',
            'X-Inertia-Partial-Data' => 'healthcare_devices',
        ]);
    }

    private function assignToClient(Device $device, Client $client): void
    {
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
        ]);
    }
}
