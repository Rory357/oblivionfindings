<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
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

class DeviceProfileWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->worker = User::factory()->create();
        $this->worker->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $this->worker->permissionOverrides()->syncWithoutDetaching([
            Permission::query()->where('key', 'controlRoom.alerts.view')->value('id') => ['allowed' => false],
        ]);
    }

    public function test_profile_reconciles_required_sections_and_redacts_raw_runtime_evidence(): void
    {
        $sentinel = 'DEVICE-PROFILE-RAW-EVIDENCE-MUST-NOT-RENDER';
        $site = Site::factory()->create(['name' => 'Harbour Care']);
        $device = Device::factory()->create([
            'name' => 'Harbour edge gateway',
            'domain' => 'it_infrastructure',
            'category' => 'networking',
            'subcategory' => 'gateway',
            'status' => 'offline',
            'health_status' => 'critical',
            'provider' => 'oblivion_native',
            'last_seen_at' => now()->subMinutes(20),
            'commissioned_at' => now()->subYear(),
            'warranty_expires_at' => now()->addYear(),
            'next_service_due' => now()->addMonth(),
            'expected_lifespan_months' => 60,
            'purchase_price' => 1299.50,
            'external_ref' => ['secret' => $sentinel],
            'config' => ['secret' => $sentinel],
            'meta' => ['secret' => $sentinel],
            'notes' => 'Primary WAN edge device for Harbour Care.',
        ]);
        $group = DeviceGroup::query()->create([
            'name' => 'Critical network edge',
            'type' => 'manual',
        ]);
        $device->groups()->attach($group->id);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
        ]);
        $profile = MonitoringProfile::factory()->create([]);
        $monitor = Monitor::factory()->create([
            'device_id' => $device->id,
            'profile_id' => $profile->id,
            'name' => 'WAN interface',
            'kind' => MonitorKind::SnmpInterface,
            'target' => $sentinel,
            'config' => ['community' => $sentinel],
            'current_state' => MonitorState::Failed,
            'last_observation_at' => now()->subMinutes(2),
        ]);
        MonitorObservation::factory()->create([
            'monitor_id' => $monitor->id,
            'state' => MonitorState::Failed,
            'value' => 91.4,
            'unit' => $sentinel,
            'message' => $sentinel,
            'metrics' => [
                'interface_name' => 'wan0',
                'admin_status' => $sentinel,
                'in_utilization_pct' => 91.4,
                'secret' => $sentinel,
            ],
            'observed_at' => now()->subMinutes(2),
        ]);
        DeviceEvent::query()->create([
            'device_id' => $device->id,
            'event_type' => 'availability.failed',
            'severity' => 'critical',
            'payload' => ['secret' => $sentinel],
            'source' => 'native_monitoring',
            'occurred_at' => now()->subMinute(),
        ]);
        DeviceMaintenanceRecord::query()->create([
            'device_id' => $device->id,
            'type' => 'repair',
            'status' => 'scheduled',
            'description' => 'Restore the WAN path',
            'scheduled_for' => now()->subDay(),
            'notes' => $sentinel,
        ]);
        $ticket = ItTicket::factory()->create([
            'requester_user_id' => $this->admin->id,
            'title' => 'Investigate Harbour connectivity',
        ]);
        ItTicketLink::query()->create([
            'ticket_id' => $ticket->id,
            'relationship' => 'affected_device',
            'linkable_type' => Device::class,
            'linkable_id' => $device->id,
            'context' => ['secret' => $sentinel],
            'created_by_user_id' => $this->admin->id,
        ]);
        AuditLog::query()->create([

            'user_id' => $this->admin->id,
            'action' => 'device.update',
            'auditable_type' => Device::class,
            'auditable_id' => $device->id,
            'meta' => ['fields' => ['firmware_version'], 'after' => ['secret' => $sentinel]],
        ]);
        $controlRoomAlert = $this->createControlRoomAlert($device, $site);

        $response = $this->actingAs($this->admin)
            ->get("/security-devices/devices/{$device->id}");

        $response->assertOk()->assertInertia(function ($page) use ($controlRoomAlert, $site, $ticket, $sentinel): void {
            $props = $page->toArray()['props'];

            $this->assertSame('Harbour edge gateway', $props['profile']['header']['identity']['name']);
            $this->assertSame('Harbour Care', $props['profile']['header']['location']['name']);
            $this->assertSame("/security-devices/sites/{$site->id}", $props['profile']['header']['location']['href']);
            $this->assertSame('critical', $props['profile']['header']['requiredAction']['state']);
            $this->assertSame([
                'health',
                'monitors',
                'topology',
                'interfaces-sensors',
                'configuration',
                'management',
                'assignments',
                'tickets',
                'events',
                'maintenance',
                'documents',
                'audit',
            ], collect($props['profile']['sections'])->pluck('key')->all());
            $this->assertSame('WAN interface', $props['profile']['monitors'][0]['name']);
            $this->assertSame('wan0', $props['profile']['interfacesSensors'][0]['name']);
            $this->assertNull($props['profile']['interfacesSensors'][0]['unit']);
            $this->assertNull($props['profile']['interfacesSensors'][0]['adminStatus']);
            $this->assertSame($ticket->reference, $props['profile']['tickets'][0]['reference']);
            $this->assertSame($controlRoomAlert->reference_number, $props['profile']['controlRoomAlerts'][0]['reference']);
            $this->assertSame("/control-room/alerts/{$controlRoomAlert->id}", $props['profile']['controlRoomAlerts'][0]['href']);
            $this->assertSame('device.update', $props['profile']['audit'][0]['action']);
            $this->assertSame('Primary WAN edge device for Harbour Care.', $props['profile']['configuration']['registry']['notes']);
            $this->assertSame('Critical network edge', $props['profile']['configuration']['registry']['groups'][0]['name']);
            $this->assertNotNull($props['profile']['configuration']['registry']['commissionedAt']);
            $this->assertNotNull($props['profile']['configuration']['registry']['warrantyExpiresAt']);
            $this->assertArrayNotHasKey('config', $props['device']);
            $this->assertArrayNotHasKey('meta', $props['device']);
            $this->assertArrayNotHasKey('external_ref', $props['device']);
            $this->assertStringNotContainsString($sentinel, json_encode($props, JSON_THROW_ON_ERROR));
            $this->assertFalse($props['profile']['capabilities']['control']['available']);
        });
    }

    public function test_vehicle_assignment_uses_the_canonical_fleet_destination_when_the_viewer_can_open_it(): void
    {
        $site = Site::factory()->create(['name' => 'Fleet operations']);
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create([
            'name' => 'Fleet response van',
        ]);
        $device = Device::factory()->tracking()->create(['name' => 'Response van tracker']);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_VEHICLE,
            'assignable_id' => $vehicle->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get("/security-devices/devices/{$device->id}")
            ->assertOk()
            ->assertInertia(function ($page) use ($vehicle): void {
                $location = $page->toArray()['props']['profile']['header']['location'];

                $this->assertSame('vehicle', $location['type']);
                $this->assertSame('Fleet response van', $location['name']);
                $this->assertSame("/fleet-assets/vehicles/{$vehicle->id}", $location['href']);
            });
    }

    public function test_management_uses_server_declared_actions_and_fails_closed_without_an_execution_adapter(): void
    {
        $site = Site::factory()->create(['name' => 'Harbour Care']);
        $device = Device::factory()->security()->create([
            'name' => 'Harbour service door',
            'category' => 'access_control',
            'subcategory' => 'door_controller',
            'provider' => 'unconfigured-door-provider',
            'config' => [
                'management' => [
                    'capabilities' => ['access.door.unlock_timed'],
                ],
                'provider_token' => 'DEVICE-PROFILE-COMMAND-SECRET',
            ],
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get("/security-devices/devices/{$device->id}");

        $response->assertOk()->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];
            $management = $props['profile']['management'];
            $action = $management['actions'][0];

            $this->assertTrue(collect($props['profile']['sections'])->pluck('key')->contains('management'));
            $this->assertTrue($management['visible']);
            $this->assertTrue($management['canObserve']);
            $this->assertTrue($management['stepUpCurrent']);
            $this->assertSame(1, $management['summary']['declared']);
            $this->assertSame(0, $management['summary']['available']);
            $this->assertSame('access.door.unlock_timed', $action['key']);
            $this->assertSame('control', $action['level']);
            $this->assertSame('high', $action['risk']);
            $this->assertNotSame('', $action['impact']);
            $this->assertNotSame('', $action['expectedResult']);
            $this->assertSame('acknowledge_impact', $action['confirmationMode']);
            $this->assertSame('unavailable', $action['executionMode']);
            $this->assertTrue($action['allowed']);
            $this->assertFalse($action['adapterAvailable']);
            $this->assertFalse($action['available']);
            $this->assertSame('provider_adapter_required', $action['state']);
            $this->assertSame('duration_seconds', $action['parameters'][0]['name']);
            $this->assertSame([], $management['history']);
            $this->assertStringNotContainsString(
                'DEVICE-PROFILE-COMMAND-SECRET',
                json_encode($props, JSON_THROW_ON_ERROR),
            );
        });
    }

    public function test_capabilities_require_device_evidence_as_well_as_actor_permission(): void
    {
        $configured = Device::factory()->create([
            'domain' => 'iot_healthcare',
            'meta' => [
                'capabilities' => [
                    'monitoring' => false,
                    'maintenance' => true,
                    'control' => true,
                ],
            ],
        ]);
        $unknown = Device::factory()->create([
            'domain' => 'it_infrastructure',
            'meta' => [],
            'config' => [],
        ]);

        $configuredResponse = $this->actingAs($this->admin)
            ->get("/security-devices/devices/{$configured->id}");
        $unknownResponse = $this->actingAs($this->admin)
            ->get("/security-devices/devices/{$unknown->id}");

        $configuredResponse->assertOk()->assertInertia(function ($page): void {
            $capabilities = $page->toArray()['props']['profile']['capabilities'];
            $this->assertFalse($capabilities['monitoring']['supported']);
            $this->assertFalse($capabilities['monitoring']['available']);
            $this->assertSame('unsupported', $capabilities['monitoring']['state']);
            $this->assertTrue($capabilities['maintenance']['supported']);
            $this->assertTrue($capabilities['maintenance']['available']);
            $this->assertFalse($capabilities['control']['supported']);
            $this->assertFalse($capabilities['control']['available']);
            $this->assertSame('unknown_not_configured', $capabilities['control']['state']);
        });
        $unknownResponse->assertOk()->assertInertia(function ($page): void {
            $capabilities = $page->toArray()['props']['profile']['capabilities'];
            $this->assertFalse($capabilities['monitoring']['supported']);
            $this->assertFalse($capabilities['monitoring']['available']);
            $this->assertSame('unknown_not_configured', $capabilities['monitoring']['state']);
        });
    }

    public function test_freshness_and_required_action_share_the_newest_authoritative_observation(): void
    {
        $site = Site::factory()->create([]);
        $device = Device::factory()->create([
            'status' => 'active',
            'health_status' => 'healthy',
            'last_seen_at' => now()->subHour(),
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        $profile = MonitoringProfile::factory()->create([
            'stale_after_seconds' => 900,
        ]);
        $observedAt = now()->subMinute()->startOfSecond();
        Monitor::factory()->create([
            'device_id' => $device->id,
            'profile_id' => $profile->id,
            'kind' => MonitorKind::Icmp,
            'current_state' => MonitorState::Healthy,
            'last_observation_at' => $observedAt,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/security-devices/devices/{$device->id}");

        $response->assertOk()->assertInertia(function ($page) use ($observedAt): void {
            $header = $page->toArray()['props']['profile']['header'];
            $this->assertSame('fresh', $header['freshness']['state']);
            $this->assertSame($observedAt->toISOString(), $header['freshness']['observedAt']);
            $this->assertSame($observedAt->toISOString(), $header['providerObservation']['observedAt']);
            $this->assertSame('native_monitoring', $header['providerObservation']['source']);
            $this->assertSame('none', $header['requiredAction']['state']);
        });
    }

    public function test_a_stale_monitor_cannot_be_masked_by_a_fresh_slow_cadence_monitor(): void
    {
        $site = Site::factory()->create([]);
        $device = Device::factory()->create([
            'status' => 'active',
            'health_status' => 'healthy',
            'last_seen_at' => now()->subMinute(),
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        $fastProfile = MonitoringProfile::factory()->create([
            'stale_after_seconds' => 60,
        ]);
        $slowProfile = MonitoringProfile::factory()->create([
            'stale_after_seconds' => 3600,
        ]);
        Monitor::factory()->create([
            'device_id' => $device->id,
            'profile_id' => $fastProfile->id,
            'current_state' => MonitorState::Stale,
            'last_observation_at' => now()->subMinutes(5),
        ]);
        Monitor::factory()->create([
            'device_id' => $device->id,
            'profile_id' => $slowProfile->id,
            'current_state' => MonitorState::Healthy,
            'last_observation_at' => now()->subMinute(),
        ]);

        $this->actingAs($this->admin)
            ->get("/security-devices/devices/{$device->id}")
            ->assertOk()
            ->assertInertia(function ($page): void {
                $header = $page->toArray()['props']['profile']['header'];
                $this->assertSame('stale', $header['freshness']['state']);
                $this->assertSame('warning', $header['requiredAction']['state']);
                $this->assertSame('monitors', $header['requiredAction']['section']);
            });
    }

    public function test_unsupported_monitoring_capability_never_prompts_for_monitoring_coverage(): void
    {
        $site = Site::factory()->create([]);
        $device = Device::factory()->create([
            'status' => 'active',
            'health_status' => 'healthy',
            'last_seen_at' => now(),
            'meta' => ['capabilities' => ['monitoring' => false]],
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get("/security-devices/devices/{$device->id}")
            ->assertOk()
            ->assertInertia(function ($page): void {
                $header = $page->toArray()['props']['profile']['header'];
                $this->assertSame('none', $header['requiredAction']['state']);
                $this->assertNotSame('Add monitoring coverage', $header['requiredAction']['label']);
            });
    }

    public function test_narrow_integration_alert_permission_does_not_emit_inaccessible_control_room_links(): void
    {
        $site = Site::factory()->create([]);
        $viewer = User::factory()->create();
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);
        $viewer->permissionOverrides()->syncWithoutDetaching([
            Permission::query()->where('key', 'controlRoom.alerts.view')->value('id') => ['allowed' => true],
            Permission::query()->where('key', 'controlRoom.viewAny')->value('id') => ['allowed' => false],
        ]);
        $device = Device::factory()->create([]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        $this->createControlRoomAlert($device, $site);

        $this->actingAs($viewer)
            ->get("/security-devices/devices/{$device->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('profile.controlRoomAlerts', 0)
                ->where('profile.sections', fn ($sections): bool => collect($sections)
                    ->every(fn (array $section): bool => ! str_starts_with((string) ($section['href'] ?? ''), '/control-room/alerts/'))));
    }

    public function test_profile_uses_canonical_device_relationships_for_monitor_profile_and_collector_projections(): void
    {
        $device = Device::factory()->create([]);
        $unassignedDeviceProfile = MonitoringProfile::factory()->create([]);
        $unassignedDeviceCollector = MonitoringCollector::factory()->create([
            'name' => 'Remote site collector',
        ]);
        Monitor::factory()->create([
            'device_id' => $device->id,
            'profile_id' => $unassignedDeviceProfile->id,
            'collector_id' => $unassignedDeviceCollector->id,
            'name' => 'Primary monitor',
        ]);
        Monitor::factory()->create([
            'device_id' => $device->id,
            'profile_id' => $unassignedDeviceProfile->id,
            'collector_id' => $unassignedDeviceCollector->id,
            'name' => 'Secondary monitor',
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/security-devices/devices/{$device->id}");

        $response->assertOk()->assertInertia(function ($page): void {
            $monitors = $page->toArray()['props']['profile']['monitors'];
            $this->assertCount(2, $monitors);
            $this->assertEqualsCanonicalizing(
                ['Primary monitor', 'Secondary monitor'],
                collect($monitors)->pluck('name')->all(),
            );
            foreach ($monitors as $monitor) {
                $this->assertNotNull($monitor['profile']);
                $this->assertSame('Remote site collector', $monitor['collector']['name']);
            }
        });
    }

    public function test_profile_omits_sections_and_data_the_viewer_cannot_open(): void
    {
        $site = Site::factory()->create([]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);
        $device = Device::factory()->create([]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
        ]);
        DeviceEvent::query()->create([
            'device_id' => $device->id,
            'event_type' => 'private.event',
            'severity' => 'info',
            'occurred_at' => now(),
        ]);
        DeviceMaintenanceRecord::query()->create([
            'device_id' => $device->id,
            'type' => 'inspection',
            'status' => 'scheduled',
            'description' => 'Private maintenance',
            'scheduled_for' => now(),
        ]);
        $monitoringProfile = MonitoringProfile::factory()->create([]);
        Monitor::factory()->create([
            'device_id' => $device->id,
            'profile_id' => $monitoringProfile->id,
            'name' => 'Private monitor',
            'kind' => MonitorKind::Icmp,
        ]);
        $ticket = ItTicket::factory()->create([
            'requester_user_id' => $this->admin->id,
            'title' => 'Private linked ticket',
        ]);
        ItTicketLink::query()->create([
            'ticket_id' => $ticket->id,
            'relationship' => 'affected_device',
            'linkable_type' => Device::class,
            'linkable_id' => $device->id,
            'created_by_user_id' => $this->admin->id,
        ]);
        AuditLog::query()->create([

            'user_id' => $this->admin->id,
            'action' => 'private.device.action',
            'auditable_type' => Device::class,
            'auditable_id' => $device->id,
        ]);
        $this->createControlRoomAlert($device, $site);

        $response = $this->actingAs($this->worker)
            ->get("/security-devices/devices/{$device->id}");

        $response->assertOk()->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];
            $keys = collect($props['profile']['sections'])->pluck('key');

            $this->assertFalse($keys->contains('monitors'));
            $this->assertFalse($keys->contains('interfaces-sensors'));
            $this->assertFalse($keys->contains('tickets'));
            $this->assertFalse($keys->contains('events'));
            $this->assertFalse($keys->contains('maintenance'));
            $this->assertFalse($keys->contains('audit'));
            $this->assertSame([], $props['profile']['monitors']);
            $this->assertSame([], $props['profile']['tickets']);
            $this->assertSame([], $props['profile']['audit']);
            $this->assertSame([], $props['profile']['controlRoomAlerts']);
            $this->assertSame([], $props['recentEvents']);
            $this->assertSame([], $props['maintenanceRecords']);
        });
    }

    private function createControlRoomAlert(Device $device, Site $site): ControlRoomAlert
    {
        $projection = ControlRoomDevice::query()->create([
            'canonical_device_id' => $device->id,
            'name' => $device->name,
            'type' => ControlRoomDevice::TYPE_ALARM_PANEL,
            'site_id' => $site->id,
            'status' => 'offline',
        ]);

        return ControlRoomAlert::factory()->critical()->create([
            'reference_number' => 'CR-TEST-DEVICE-PROFILE-'.$device->id,
            'source' => 'security_devices',
            'alert_type' => 'Device needs Control Room triage',
            'device_id' => $projection->id,
            'site_id' => $site->id,
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
    }
}
