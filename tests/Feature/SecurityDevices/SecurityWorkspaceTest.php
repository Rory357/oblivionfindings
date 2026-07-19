<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create([
            'organization_id' => 42,
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_overview_reconciles_security_inventory_site_impact_and_required_actions(): void
    {
        $entrance = Site::factory()->create(['tenant_id' => 42, 'name' => 'Harbour Entrance']);
        $office = Site::factory()->create(['tenant_id' => 42, 'name' => 'Harbour Office']);
        $foreignSite = Site::factory()->create(['tenant_id' => 77, 'name' => 'Foreign Site']);

        $camera = Device::factory()->offline()->create([
            'tenant_id' => 42,
            'domain' => 'security',
            'category' => 'cctv',
            'name' => 'Front camera',
            'health_status' => HealthStatus::Critical,
        ]);
        $alarm = Device::factory()->create([
            'tenant_id' => 42,
            'domain' => 'security',
            'category' => 'alarm',
            'name' => 'Main alarm panel',
        ]);
        $door = Device::factory()->create([
            'tenant_id' => 42,
            'domain' => 'security',
            'category' => 'access_control',
            'name' => 'Front door reader',
        ]);
        $foreign = Device::factory()->offline()->create([
            'tenant_id' => 77,
            'domain' => 'security',
            'category' => 'cctv',
            'name' => 'Foreign camera',
        ]);

        $this->assignToSite($camera, $entrance);
        $this->assignToSite($alarm, $office);
        $this->assignToSite($door, $office);
        $this->assignToSite($foreign, $foreignSite);

        DeviceEvent::create([
            'device_id' => $alarm->id,
            'event_type' => 'alarm_trigger',
            'severity' => 'critical',
            'source' => 'unifi',
            'occurred_at' => now()->subMinute(),
        ]);
        DeviceMaintenanceRecord::create([
            'device_id' => $camera->id,
            'type' => 'inspection',
            'status' => 'scheduled',
            'description' => 'Camera inspection overdue',
            'scheduled_for' => now()->subDay(),
        ]);
        $this->createControlRoomAlert($camera, $entrance, 'Camera unavailable');

        $this->actingAs($this->admin)
            ->get('/security-devices/security')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $security = $page->toArray()['props']['securityWorkspace'];

                $this->assertSame([
                    'total' => 3,
                    'cctv' => 1,
                    'alarms' => 1,
                    'access_control' => 1,
                    'other' => 0,
                ], $security['overview']['inventory']);
                $this->assertSame(1, $security['overview']['attention']['devices']);
                $this->assertSame(1, $security['overview']['attention']['sites']);
                $this->assertSame(1, $security['overview']['attention']['overdue_maintenance']);
                $this->assertSame(1, $security['overview']['attention']['unprocessed_events']);
                $this->assertSame(1, $security['overview']['attention']['active_control_room_alerts']);
                $this->assertSame(
                    ['offline_devices', 'unmonitored_devices', 'unprocessed_events', 'overdue_maintenance', 'active_control_room_alerts'],
                    collect($security['overview']['requiredActions'])->pluck('key')->all(),
                );
                $this->assertNotContains(
                    'Foreign camera',
                    collect($security['activeTab']['devices'])->pluck('name')->all(),
                );
                $this->assertArrayNotHasKey('commands', $security);
                $this->assertArrayNotHasKey('commands', $security['activeTab']);
            });
    }

    public function test_cctv_tab_exposes_only_observed_health_assignment_maintenance_and_authorised_media_links(): void
    {
        $site = Site::factory()->create(['tenant_id' => 42, 'name' => 'Camera Site']);
        $camera = Device::factory()->create([
            'tenant_id' => 42,
            'domain' => 'security',
            'category' => 'cctv',
            'subcategory' => 'dome_camera',
            'name' => 'Reception camera',
            'provider' => 'unifi',
            'config' => [
                'stream_health' => 'healthy',
                'recording_health' => 'degraded',
                'media_href' => '/security-devices/devices/preview/reception',
            ],
        ]);
        $this->assignToSite($camera, $site);
        DeviceMaintenanceRecord::create([
            'device_id' => $camera->id,
            'type' => 'inspection',
            'status' => 'scheduled',
            'description' => 'Lens and recording inspection',
            'scheduled_for' => now()->addDays(2),
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=cctv')
            ->assertOk()
            ->assertInertia(function ($page) use ($camera): void {
                $security = $page->toArray()['props']['securityWorkspace'];
                $device = $security['activeTab']['devices'][0];

                $this->assertSame('cctv', $security['activeTab']['key']);
                $this->assertSame(1, $security['activeTab']['inventoryTotal']);
                $this->assertSame($camera->id, $device['id']);
                $this->assertSame('Camera Site', $device['site']['name']);
                $this->assertSame('unifi', $device['provider']);
                $this->assertSame('healthy', $device['observed']['stream_health']);
                $this->assertSame('degraded', $device['observed']['recording_health']);
                $this->assertSame('available', $device['media']['state']);
                $this->assertSame('/security-devices/devices/preview/reception', $device['media']['href']);
                $this->assertSame(1, $device['maintenance']['open_count']);
                $this->assertSame('Lens and recording inspection', $device['maintenance']['next']['description']);
            });

        $viewer = $this->viewerWithRole('provider_manager');
        $this->assignToStaff($camera, $viewer);

        $this->actingAs($viewer)
            ->get('/security-devices/security?tab=cctv')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $device = $page->toArray()['props']['securityWorkspace']['activeTab']['devices'][0];

                $this->assertSame('restricted', $device['media']['state']);
                $this->assertArrayNotHasKey('href', $device['media']);
            });
    }

    public function test_cctv_media_link_must_be_an_internal_path_even_for_an_authorised_viewer(): void
    {
        Device::factory()->create([
            'tenant_id' => 42,
            'domain' => 'security',
            'category' => 'cctv',
            'config' => ['media_href' => 'https://camera-admin.example.test/live?token=secret'],
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=cctv')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $media = $page->toArray()['props']['securityWorkspace']['activeTab']['devices'][0]['media'];

                $this->assertSame('not_configured', $media['state']);
                $this->assertArrayNotHasKey('href', $media);
            });
    }

    public function test_alarm_tab_combines_panels_sensors_events_maintenance_and_control_room_context(): void
    {
        $site = Site::factory()->create(['tenant_id' => 42, 'name' => 'Alarm Site']);
        $panel = Device::factory()->create([
            'tenant_id' => 42,
            'domain' => 'security',
            'category' => 'alarm',
            'subcategory' => 'panel',
            'name' => 'Main panel',
            'config' => [
                'alarm_state' => 'armed',
                'zones' => ['total' => 8, 'faulted' => 1],
            ],
        ]);
        $sensor = Device::factory()->create([
            'tenant_id' => 42,
            'domain' => 'security',
            'category' => 'perimeter',
            'subcategory' => 'beam_sensor',
            'name' => 'Driveway beam',
            'config' => ['sensor_state' => 'triggered'],
        ]);
        $this->assignToSite($panel, $site);
        $this->assignToSite($sensor, $site);
        DeviceEvent::create([
            'device_id' => $panel->id,
            'event_type' => 'alarm_trigger',
            'severity' => 'critical',
            'source' => 'alarm-provider',
            'payload' => ['zone' => 'Reception'],
            'occurred_at' => now()->subMinute(),
        ]);
        DeviceMaintenanceRecord::create([
            'device_id' => $panel->id,
            'type' => 'battery_test',
            'status' => 'scheduled',
            'description' => 'Panel battery test',
            'scheduled_for' => now()->addDay(),
        ]);
        $this->createControlRoomAlert($panel, $site, 'Reception alarm');

        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=alarms')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $active = $page->toArray()['props']['securityWorkspace']['activeTab'];
                $devices = collect($active['devices'])->keyBy('name');

                $this->assertSame('alarms', $active['key']);
                $this->assertSame(2, $active['inventoryTotal']);
                $this->assertSame('armed', $devices['Main panel']['observed']['alarm_state']);
                $this->assertSame(['total' => 8, 'faulted' => 1], $devices['Main panel']['observed']['zones']);
                $this->assertSame('triggered', $devices['Driveway beam']['observed']['sensor_state']);
                $this->assertSame(['alarm_trigger'], collect($active['recentEvents'])->pluck('type')->all());
                $this->assertSame(['Reception alarm'], collect($active['controlRoomAlerts'])->pluck('title')->all());
                $this->assertSame('Panel battery test', $devices['Main panel']['maintenance']['next']['description']);
            });
    }

    public function test_access_control_is_physical_hardware_with_provider_capabilities_and_history_not_software_rbac(): void
    {
        $door = Device::factory()->create([
            'tenant_id' => 42,
            'domain' => 'security',
            'category' => 'access_control',
            'subcategory' => 'card_reader',
            'name' => 'Staff entrance reader',
            'config' => [
                'door_state' => 'secured',
                'credential_count' => 42,
                'schedule_count' => 3,
            ],
        ]);
        Device::factory()->create([
            'tenant_id' => 42,
            'domain' => 'it_infrastructure',
            'category' => 'endpoint',
            'name' => 'RBAC administration workstation',
        ]);
        DeviceEvent::create([
            'device_id' => $door->id,
            'event_type' => 'door_opened',
            'severity' => 'info',
            'source' => 'unifi',
            'occurred_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=access-control')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $active = $page->toArray()['props']['securityWorkspace']['activeTab'];

                $this->assertSame(1, $active['inventoryTotal']);
                $this->assertSame(['Staff entrance reader'], collect($active['devices'])->pluck('name')->all());
                $this->assertSame('secured', $active['devices'][0]['observed']['door_state']);
                $this->assertSame(42, $active['devices'][0]['observed']['credential_count']);
                $this->assertSame(3, $active['devices'][0]['observed']['schedule_count']);
                $this->assertSame(['door_opened'], collect($active['recentEvents'])->pluck('type')->all());
                $this->assertArrayNotHasKey('actions', $active['devices'][0]);
                $this->assertArrayNotHasKey('commands', $active['devices'][0]);
            });
    }

    public function test_security_events_tab_reuses_canonical_events_and_is_restricted_without_event_permission(): void
    {
        $device = Device::factory()->create([
            'tenant_id' => 42,
            'domain' => 'security',
            'category' => 'alarm',
            'name' => 'Restricted panel',
        ]);
        DeviceEvent::create([
            'device_id' => $device->id,
            'event_type' => 'tamper',
            'severity' => 'warning',
            'source' => 'canonical-device-events',
            'occurred_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workspace.activeTabState', 'available')
                ->where('securityWorkspace.activeTab.restricted', false)
                ->where('securityWorkspace.activeTab.recentEvents.0.type', 'tamper')
                ->where('securityWorkspace.activeTab.recentEvents.0.source', 'canonical-device-events'));

        $viewer = $this->viewerWithRole('support_worker');
        $this->assignToStaff($device, $viewer);

        $this->actingAs($viewer)
            ->get('/security-devices/security?tab=events')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];

                $this->assertSame('restricted', $props['workspace']['activeTabState']);
                $this->assertTrue($props['securityWorkspace']['activeTab']['restricted']);
                $this->assertSame([], $props['securityWorkspace']['activeTab']['recentEvents']);
                $this->assertSame([], $props['securityWorkspace']['activeTab']['controlRoomAlerts']);
                $this->assertNull($props['securityWorkspace']['overview']['attention']['unprocessed_events']);
            });
    }

    public function test_cctv_media_permission_is_explicit_and_not_granted_to_general_operational_roles(): void
    {
        $permission = Permission::query()->where('key', 'securityDevices.cctv.media.view')->first();

        $this->assertNotNull($permission);
        $this->assertTrue($this->admin->canDo('securityDevices.cctv.media.view'));
        $this->assertFalse($this->viewerWithRole('provider_manager')->canDo('securityDevices.cctv.media.view'));
        $this->assertTrue($this->viewerWithRole('it_manager')->canDo('securityDevices.cctv.media.view'));
    }

    private function viewerWithRole(string $role): User
    {
        $viewer = User::factory()->create([
            'organization_id' => 42,
            'approved_at' => now(),
        ]);
        $viewer->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $viewer;
    }

    private function assignToSite(Device $device, Site $site): void
    {
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
        ]);
    }

    private function assignToStaff(Device $device, User $viewer): void
    {
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_STAFF,
            'assignable_id' => $viewer->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
        ]);
    }

    private function createControlRoomAlert(Device $device, Site $site, string $title): ControlRoomAlert
    {
        $projection = ControlRoomDevice::create([
            'canonical_device_id' => $device->id,
            'name' => $device->name,
            'type' => match ($device->category) {
                'cctv' => ControlRoomDevice::TYPE_CAMERA,
                'access_control' => ControlRoomDevice::TYPE_DOOR,
                default => ControlRoomDevice::TYPE_ALARM_PANEL,
            },
            'site_id' => $site->id,
            'status' => 'online',
        ]);

        return ControlRoomAlert::factory()->critical()->create([
            'source' => 'security_devices',
            'alert_type' => $title,
            'device_id' => $projection->id,
            'site_id' => $site->id,
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
    }
}
