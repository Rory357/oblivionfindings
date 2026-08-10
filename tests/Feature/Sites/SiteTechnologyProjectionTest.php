<?php

namespace Tests\Feature\Sites;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteTechnologyProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public function test_site_profile_lazily_projects_canonical_technology_without_a_second_register(): void
    {
        $site = Site::factory()->create(['name' => 'Harbour House']);
        $outsideSite = Site::factory()->create(['name' => 'Outside House']);
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $device = Device::factory()->itInfrastructure()->offline()->create([
            'name' => 'Harbour SD-WAN gateway',
            'health_status' => HealthStatus::Critical,
            'subcategory' => 'edge_router',
        ]);
        $outside = Device::factory()->offline()->create(['name' => 'OUTSIDE-DEVICE-SENTINEL']);
        $this->assignToSite($device, $site);
        $this->assignToSite($outside, $outsideSite);
        $profile = MonitoringProfile::factory()->create();
        Monitor::factory()->create([
            'profile_id' => $profile->id,
            'device_id' => $device->id,
            'name' => 'WAN availability',
            'current_state' => MonitorState::Failed,
            'last_observation_at' => now()->subMinute(),
        ]);
        $baseline = ConfigurationSnapshot::query()->create([
            'snapshot_uuid' => '018f0000-0000-7000-8000-000000000071',
            'site_id' => $site->id,
            'device_id' => $device->id,
            'source_kind' => 'provider',
            'source' => 'unifi',
            'storage_disk' => 'private',
            'storage_path' => 'monitoring/configuration-snapshots/baseline.json.enc',
            'storage_path_hash' => hash('sha256', 'baseline'),
            'storage_state' => 'available',
            'content_hash' => hash('sha256', 'baseline-content'),
            'configuration_hash' => hash('sha256', 'baseline-configuration'),
            'content_size' => 128,
            'mime_type' => 'application/json',
            'captured_at' => now()->subHours(2),
            'diff_summary' => ['added' => [], 'removed' => [], 'changed' => [], 'truncated' => false],
        ]);
        ConfigurationSnapshot::query()->create([
            'snapshot_uuid' => '018f0000-0000-7000-8000-000000000072',
            'site_id' => $site->id,
            'device_id' => $device->id,
            'source_kind' => 'provider',
            'source' => 'unifi',
            'storage_disk' => 'private',
            'storage_path' => 'monitoring/configuration-snapshots/current.json.enc',
            'storage_path_hash' => hash('sha256', 'current'),
            'storage_state' => 'available',
            'content_hash' => hash('sha256', 'current-content'),
            'configuration_hash' => hash('sha256', 'current-configuration'),
            'content_size' => 144,
            'mime_type' => 'application/json',
            'captured_at' => now()->subMinutes(2),
            'previous_snapshot_id' => $baseline->id,
            'diff_summary' => [
                'added' => [],
                'removed' => [],
                'changed' => ['configuration.site_to_site_vpn_tunnels.0.name'],
                'truncated' => false,
            ],
        ]);
        DeviceEvent::query()->create([
            'device_id' => $device->id,
            'event_type' => 'availability_failed',
            'severity' => 'critical',
            'occurred_at' => now()->subMinute(),
        ]);
        DeviceMaintenanceRecord::query()->create([
            'device_id' => $device->id,
            'type' => 'repair',
            'status' => 'scheduled',
            'description' => 'Restore WAN failover',
            'scheduled_for' => now()->subHour(),
        ]);
        MonitoringCollector::factory()->create([
            'site_id' => $site->id,
            'name' => 'Harbour remote collector',
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(10),
        ]);
        ItTicket::factory()->create([
            'site_id' => $site->id,
            'requester_user_id' => $viewer->id,
            'title' => 'Restore Harbour connectivity',
            'status' => 'open',
        ]);
        ItTicket::factory()->create([
            'site_id' => $outsideSite->id,
            'requester_user_id' => $viewer->id,
            'title' => 'OUTSIDE-TICKET-SENTINEL',
            'status' => 'open',
        ]);

        $this->actingAs($viewer)
            ->get("/sites/{$site->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sites/show')
                ->where('can.viewTechnology', true)
                ->where('can.viewHardwarePlacement', true)
                ->missing('technology'));

        $response = $this->partialTechnology($viewer, $site);
        $response->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'sites/show')
            ->assertJsonPath('props.technology.summary.health', 'critical')
            ->assertJsonPath('props.technology.summary.devices', 1)
            ->assertJsonPath('props.technology.summary.failed_monitors', 1)
            ->assertJsonPath('props.technology.summary.open_it_work', 1)
            ->assertJsonPath('props.technology.wan.known', true)
            ->assertJsonPath('props.technology.wan.configuration.state', 'warning')
            ->assertJsonPath('props.technology.wan.configuration.observed_devices', 1)
            ->assertJsonPath('props.technology.wan.configuration.changed_devices', 1)
            ->assertJsonPath('props.technology.devices.0.id', $device->id)
            ->assertJsonPath('props.technology.devices.0.href', "/security-devices/devices/{$device->id}")
            ->assertJsonPath('props.technology.it_work.0.title', 'Restore Harbour connectivity')
            ->assertJsonPath('props.technology.maintenance.0.description', 'Restore WAN failover')
            ->assertJsonPath('props.technology.collectors.0.name', 'Harbour remote collector')
            ->assertJsonPath('props.technology.links.full', "/security-devices/sites/{$site->id}");

        $encoded = json_encode($response->json('props.technology'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('OUTSIDE-DEVICE-SENTINEL', $encoded);
        $this->assertStringNotContainsString('OUTSIDE-TICKET-SENTINEL', $encoded);
        $this->assertStringNotContainsString('secret_encrypted', $encoded);
        $this->assertStringNotContainsString('external_ref', $encoded);
        $this->assertStringNotContainsString('ip_address', $encoded);
    }

    public function test_site_profile_conceals_technology_when_source_permission_is_denied(): void
    {
        $site = Site::factory()->create();
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'team_lead')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $permissionId = Permission::query()
            ->where('key', 'securityDevices.devices.view')
            ->value('id');
        $viewer->permissionOverrides()->syncWithoutDetaching([
            $permissionId => ['allowed' => false],
        ]);
        $device = Device::factory()->create(['name' => 'PRIVATE-DEVICE-SENTINEL']);
        $this->assignToSite($device, $site);

        $this->actingAs($viewer)
            ->get("/sites/{$site->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.viewTechnology', false)
                ->where('can.viewHardwarePlacement', false)
                ->missing('technology'));

        $response = $this->partialTechnology($viewer, $site)
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('props.technology', null);

        $this->assertStringNotContainsString(
            'PRIVATE-DEVICE-SENTINEL',
            json_encode($response->json(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_site_profile_does_not_leak_monitoring_or_maintenance_without_source_permissions(): void
    {
        $site = Site::factory()->create(['name' => 'Restricted Technology House']);
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'team_lead')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        $permissions = Permission::query()
            ->whereIn('key', [
                'securityDevices.viewAny',
                'securityDevices.devices.view',
                'securityDevices.events.view',
                'securityDevices.maintenance.view',
                'securityDevices.maintenance.manage',
            ])
            ->get()
            ->keyBy('key');
        $this->assertCount(5, $permissions);
        $viewer->permissionOverrides()->syncWithoutDetaching([
            $permissions['securityDevices.viewAny']->id => ['allowed' => true],
            $permissions['securityDevices.devices.view']->id => ['allowed' => true],
            $permissions['securityDevices.events.view']->id => ['allowed' => false],
            $permissions['securityDevices.maintenance.view']->id => ['allowed' => false],
            $permissions['securityDevices.maintenance.manage']->id => ['allowed' => false],
        ]);

        $device = Device::factory()->create(['name' => 'Visible canonical Device']);
        $this->assignToSite($device, $site);
        Monitor::factory()->create([
            'profile_id' => MonitoringProfile::factory()->create()->id,
            'device_id' => $device->id,
            'name' => 'RESTRICTED-MONITOR-SENTINEL',
            'current_state' => MonitorState::Failed,
        ]);
        DeviceEvent::query()->create([
            'device_id' => $device->id,
            'event_type' => 'RESTRICTED-EVENT-SENTINEL',
            'severity' => 'critical',
            'occurred_at' => now(),
        ]);
        DeviceMaintenanceRecord::query()->create([
            'device_id' => $device->id,
            'type' => 'repair',
            'status' => 'scheduled',
            'description' => 'RESTRICTED-MAINTENANCE-SENTINEL',
            'scheduled_for' => now()->subHour(),
        ]);
        MonitoringCollector::factory()->create([
            'site_id' => $site->id,
            'name' => 'RESTRICTED-COLLECTOR-SENTINEL',
            'status' => 'offline',
        ]);

        $response = $this->partialTechnology($viewer, $site)
            ->assertOk()
            ->assertJsonPath('props.technology.can.view_monitoring', false)
            ->assertJsonPath('props.technology.can.view_maintenance', false)
            ->assertJsonPath('props.technology.summary.monitored_devices', null)
            ->assertJsonPath('props.technology.summary.failed_monitors', null)
            ->assertJsonPath('props.technology.summary.active_findings', null)
            ->assertJsonPath('props.technology.summary.overdue_maintenance', null)
            ->assertJsonPath('props.technology.summary.collector', null)
            ->assertJsonPath('props.technology.links.monitoring', null)
            ->assertJsonPath('props.technology.links.maintenance', null)
            ->assertJsonCount(0, 'props.technology.monitoring.issues')
            ->assertJsonCount(0, 'props.technology.maintenance')
            ->assertJsonCount(0, 'props.technology.collectors');

        $encoded = json_encode($response->json('props.technology'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('RESTRICTED-MONITOR-SENTINEL', $encoded);
        $this->assertStringNotContainsString('RESTRICTED-EVENT-SENTINEL', $encoded);
        $this->assertStringNotContainsString('RESTRICTED-MAINTENANCE-SENTINEL', $encoded);
        $this->assertStringNotContainsString('RESTRICTED-COLLECTOR-SENTINEL', $encoded);

        $this->actingAs($viewer)->get('/security-devices/monitoring')->assertForbidden();
        $this->actingAs($viewer)->get('/security-devices/maintenance')->assertForbidden();

        $viewer->permissionOverrides()->updateExistingPivot(
            $permissions['securityDevices.viewAny']->id,
            ['allowed' => false],
        );
        $viewer->unsetRelation('permissionOverrides');
        $this->partialTechnology($viewer->fresh(), $site)
            ->assertOk()
            ->assertJsonPath('props.technology', null);
    }

    private function partialTechnology(User $viewer, Site $site)
    {
        $version = app(HandleInertiaRequests::class)->version(request());

        return $this->actingAs($viewer)->get("/sites/{$site->id}", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'sites/show',
            'X-Inertia-Partial-Data' => 'technology',
        ]);
    }

    private function assignToSite(Device $device, Site $site): void
    {
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
    }
}
