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
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsWorkspacesTest extends TestCase
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

    public function test_native_monitoring_groups_collection_path_failure_and_redacts_probe_secrets(): void
    {
        $site = Site::factory()->create(['tenant_id' => 42, 'name' => 'Remote Care Site']);
        $directDevice = Device::factory()->itInfrastructure()->create([
            'tenant_id' => 42,
            'name' => 'Main WAN gateway',
        ]);
        $remoteDevice = Device::factory()->itInfrastructure()->create([
            'tenant_id' => 42,
            'name' => 'Remote switch',
        ]);
        $foreignDevice = Device::factory()->itInfrastructure()->create([
            'tenant_id' => 77,
            'name' => 'Foreign router',
        ]);
        $this->assignToSite($directDevice, $site);
        $this->assignToSite($remoteDevice, $site);

        $profile = MonitoringProfile::factory()->create([
            'tenant_id' => 42,
            'name' => 'Availability',
            'stale_after_seconds' => 300,
        ]);
        $collector = MonitoringCollector::factory()->create([
            'tenant_id' => 42,
            'site_id' => $site->id,
            'name' => 'Remote site collector',
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(12),
            'config' => ['token' => 'collector-secret-token'],
        ]);

        $direct = Monitor::factory()->create([
            'tenant_id' => 42,
            'device_id' => $directDevice->id,
            'profile_id' => $profile->id,
            'collector_id' => null,
            'name' => 'Direct ICMP',
            'target' => '10.0.0.1?password=direct-secret',
            'config' => ['community' => 'private-community'],
            'current_state' => MonitorState::Healthy,
            'last_observation_at' => now()->subMinute(),
        ]);
        $remote = Monitor::factory()->create([
            'tenant_id' => 42,
            'device_id' => $remoteDevice->id,
            'profile_id' => $profile->id,
            'collector_id' => $collector->id,
            'name' => 'Remote SNMP',
            'kind' => MonitorKind::Snmp,
            'target' => 'snmp://private-target',
            'config' => ['auth' => 'monitor-secret'],
            'current_state' => MonitorState::Failed,
            'last_observation_at' => now()->subMinutes(11),
        ]);
        MonitorObservation::factory()->create([
            'tenant_id' => 42,
            'monitor_id' => $direct->id,
            'source_key' => 'direct-observation',
            'state' => MonitorState::Healthy,
            'value' => 12.5,
            'unit' => 'ms',
            'latency_ms' => 12,
            'message' => 'probe-password=observation-secret',
            'metrics' => ['community' => 'metric-secret'],
            'observed_at' => now()->subMinute(),
            'ingested_at' => now()->subMinute(),
        ]);
        $legacyPartitionedMonitor = Monitor::factory()->create([
            'tenant_id' => 77,
            'device_id' => $foreignDevice->id,
            'name' => 'Foreign monitor',
            'current_state' => MonitorState::Failed,
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/monitoring');

        $response->assertOk()->assertInertia(function ($page) use ($direct, $legacyPartitionedMonitor, $remote): void {
            $page->component('security-devices/monitoring');
            $workspace = $page->toArray()['props']['workspace'];

            $this->assertSame(3, $workspace['summary']['total_monitors']);
            $this->assertSame(2, $workspace['summary']['direct_monitors']);
            $this->assertSame(1, $workspace['summary']['remote_monitors']);
            $this->assertSame(1, $workspace['summary']['collection_paths_unavailable']);
            $this->assertSame(2, $workspace['summary']['active_findings']);
            $this->assertSame(
                collect([$direct->id, $remote->id, $legacyPartitionedMonitor->id])->sort()->values()->all(),
                collect($workspace['monitors'])->pluck('id')->sort()->values()->all(),
            );
            $this->assertSame('direct', collect($workspace['monitors'])->firstWhere('id', $direct->id)['collection']['mode']);
            $this->assertSame('collection_unavailable', collect($workspace['monitors'])->firstWhere('id', $remote->id)['effective_state']);
            $this->assertSame('failed', collect($workspace['monitors'])->firstWhere('id', $remote->id)['reported_state']);
            $this->assertCount(1, $workspace['findings']['monitors']);
            $this->assertCount(1, $workspace['findings']['collection_paths']);
            $this->assertSame(1, $workspace['findings']['collection_paths'][0]['affected_devices']);
            $this->assertSame('not_assessed', $workspace['coverage']['unsupported_state']);
            $this->assertFalse($workspace['dependencies']['canonical_model_available']);
        });
        $response
            ->assertDontSee('collector-secret-token', false)
            ->assertDontSee('direct-secret', false)
            ->assertDontSee('private-community', false)
            ->assertDontSee('monitor-secret', false)
            ->assertDontSee('observation-secret', false)
            ->assertDontSee('metric-secret', false);
    }

    public function test_monitoring_is_limited_to_the_viewers_visible_sites(): void
    {
        [$viewer, $allowedSite, $hiddenSite] = $this->siteScopedViewer([
            'securityDevices.events.view',
        ]);
        $allowedDevice = Device::factory()->create(['tenant_id' => 42, 'name' => 'Allowed device']);
        $hiddenDevice = Device::factory()->create(['tenant_id' => 42, 'name' => 'Hidden device']);
        $this->assignToSite($allowedDevice, $allowedSite);
        $this->assignToSite($hiddenDevice, $hiddenSite);
        $profile = MonitoringProfile::factory()->create(['tenant_id' => 42]);
        foreach ([$allowedDevice, $hiddenDevice] as $device) {
            Monitor::factory()->create([
                'tenant_id' => 42,
                'device_id' => $device->id,
                'profile_id' => $profile->id,
                'name' => $device->name.' monitor',
            ]);
        }

        $response = $this->actingAs($viewer)->get('/security-devices/monitoring');

        $response->assertOk()->assertInertia(function ($page): void {
            $monitors = $page->toArray()['props']['workspace']['monitors'];
            $this->assertSame(['Allowed device'], collect($monitors)->pluck('device.name')->all());
        });
        $response->assertDontSee('Hidden device', false);
    }

    public function test_maintenance_workspace_classifies_work_and_supports_reconcilable_filters(): void
    {
        $site = Site::factory()->create(['tenant_id' => 42, 'name' => 'Clinical Site']);
        $device = Device::factory()->create([
            'tenant_id' => 42,
            'name' => 'Infusion pump',
            'domain' => 'iot_healthcare',
            'category' => 'medical_device',
        ]);
        $this->assignToSite($device, $site);

        $records = [
            ['description' => 'Overdue repair', 'type' => 'repair', 'status' => 'scheduled', 'scheduled_for' => now()->subDay()],
            ['description' => 'Calibration due', 'type' => 'calibration', 'status' => 'scheduled', 'scheduled_for' => now()->addDays(3)],
            ['description' => 'Firmware rollout', 'type' => 'firmware_update', 'status' => 'in_progress', 'scheduled_for' => now()],
            ['description' => 'Configuration baseline', 'type' => 'configuration_change', 'status' => 'scheduled', 'scheduled_for' => now()->addDays(30)],
            ['description' => 'Completed inspection', 'type' => 'inspection', 'status' => 'completed', 'scheduled_for' => now()->subDays(3), 'completed_at' => now()->subDay()],
        ];
        foreach ($records as $record) {
            DeviceMaintenanceRecord::create(['device_id' => $device->id] + $record);
        }

        $response = $this->actingAs($this->admin)->get("/security-devices/maintenance?site_id={$site->id}&type=calibration&tab=calibration");

        $response->assertOk()->assertInertia(function ($page) use ($site): void {
            $page->component('security-devices/maintenance');
            $workspace = $page->toArray()['props']['workspace'];

            $this->assertSame('calibration', $workspace['active_tab']);
            $this->assertSame(1, $workspace['summary']['overdue']);
            $this->assertSame(1, $workspace['summary']['due_soon']);
            $this->assertSame(1, $workspace['summary']['planned']);
            $this->assertSame(1, $workspace['summary']['in_progress']);
            $this->assertSame(1, $workspace['summary']['completed']);
            $this->assertSame(1, $workspace['summary']['calibration']);
            $this->assertSame(2, $workspace['summary']['firmware_configuration']);
            $this->assertSame(['Calibration due'], collect($workspace['records'])->pluck('description')->all());
            $this->assertSame($site->id, $workspace['filters']['site_id']);
            $this->assertSame('calibration', $workspace['filters']['type']);
            $this->assertArrayNotHasKey('notes', $workspace['records'][0]);
        });
    }

    public function test_maintenance_mutations_enforce_device_visibility_and_accept_configuration_work(): void
    {
        [$viewer, $allowedSite, $hiddenSite] = $this->siteScopedViewer([
            'securityDevices.maintenance.view',
            'securityDevices.maintenance.manage',
        ]);
        $allowedDevice = Device::factory()->create(['tenant_id' => 42]);
        $hiddenDevice = Device::factory()->create(['tenant_id' => 42]);
        $this->assignToSite($allowedDevice, $allowedSite);
        $this->assignToSite($hiddenDevice, $hiddenSite);

        $payload = [
            'type' => 'configuration_change',
            'description' => 'Apply approved configuration baseline',
            'scheduled_for' => now()->addDay()->toDateString(),
        ];

        $this->actingAs($viewer)
            ->post("/security-devices/devices/{$allowedDevice->id}/maintenance", $payload)
            ->assertRedirect();
        $this->assertDatabaseHas('device_maintenance_records', [
            'device_id' => $allowedDevice->id,
            'type' => 'configuration_change',
        ]);

        $this->actingAs($viewer)
            ->post("/security-devices/devices/{$hiddenDevice->id}/maintenance", $payload)
            ->assertNotFound();
    }

    public function test_discovery_distinguishes_direct_coverage_from_remote_paths_without_fake_candidates(): void
    {
        $site = Site::factory()->create(['tenant_id' => 42, 'name' => 'Remote Site']);
        $directDevice = Device::factory()->create(['tenant_id' => 42, 'name' => 'Direct gateway']);
        $remoteDevice = Device::factory()->create(['tenant_id' => 42, 'name' => 'Remote access point']);
        $this->assignToSite($remoteDevice, $site);
        $profile = MonitoringProfile::factory()->create(['tenant_id' => 42]);
        $collector = MonitoringCollector::factory()->create([
            'tenant_id' => 42,
            'site_id' => $site->id,
            'name' => 'Remote collector',
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(20),
            'config' => ['api_key' => 'discovery-secret'],
        ]);
        Monitor::factory()->create([
            'tenant_id' => 42,
            'device_id' => $directDevice->id,
            'profile_id' => $profile->id,
            'collector_id' => null,
        ]);
        Monitor::factory()->create([
            'tenant_id' => 42,
            'device_id' => $remoteDevice->id,
            'profile_id' => $profile->id,
            'collector_id' => $collector->id,
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/discovery');

        $response->assertOk()->assertInertia(function ($page): void {
            $workspace = $page->toArray()['props']['workspace'];

            $this->assertSame(1, $workspace['summary']['direct_monitors']);
            $this->assertSame(1, $workspace['summary']['remote_monitors']);
            $this->assertSame(1, $workspace['summary']['collection_paths_unavailable']);
            $this->assertSame(1, $workspace['direct_coverage']['monitors']);
            $this->assertSame('Main application over site connectivity', $workspace['direct_coverage']['path_label']);
            $this->assertSame(1, $workspace['collectors'][0]['affected_devices']);
            $this->assertSame('unavailable', $workspace['collectors'][0]['freshness_state']);
            $this->assertSame('not_assessed', $workspace['limitations']['unsupported_state']);
            $this->assertArrayNotHasKey('candidates', $workspace);
            $this->assertArrayNotHasKey('runs', $workspace);
        });
        $response->assertDontSee('discovery-secret', false);
    }

    private function assignToSite(Device $device, Site $site): void
    {
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
    }

    /** @param list<string> $additionalPermissions */
    private function siteScopedViewer(array $additionalPermissions): array
    {
        $allowedSite = Site::factory()->create(['tenant_id' => 42, 'name' => 'Allowed Site']);
        $hiddenSite = Site::factory()->create(['tenant_id' => 42, 'name' => 'Hidden Site']);
        $viewer = User::factory()->create([
            'organization_id' => 42,
            'approved_at' => now(),
        ]);
        $viewer->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $permissionIds = Permission::query()
            ->whereIn('key', $additionalPermissions)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $viewer->permissionOverrides()->syncWithoutDetaching($permissionIds);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 42,
            'user_id' => $viewer->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
        ]);

        return [$viewer, $allowedSite, $hiddenSite];
    }
}
