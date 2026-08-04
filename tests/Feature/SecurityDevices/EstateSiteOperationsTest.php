<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteContact;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstateSiteOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create([

            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_estate_overview_answers_coverage_change_site_impact_and_required_action(): void
    {
        $siteA = Site::factory()->create(['name' => 'Harbour House']);
        $siteB = Site::factory()->create(['name' => 'Kauri House']);
        $unrelatedSite = Site::factory()->create(['name' => 'Unrelated House']);

        $failed = Device::factory()->offline()->create([
            'name' => 'Harbour edge gateway',
            'health_status' => HealthStatus::Critical,
            'updated_at' => now()->subMinutes(2),
        ]);
        $healthy = Device::factory()->create([
            'name' => 'Harbour switch',
            'updated_at' => now()->subMinutes(8),
        ]);
        $unmonitored = Device::factory()->create([
            'name' => 'Kauri camera',
            'updated_at' => now()->subMinutes(5),
        ]);
        $unrelated = Device::factory()->offline()->create([
            'name' => 'Unrelated gateway',
            'health_status' => HealthStatus::Critical,
            'updated_at' => now(),
        ]);

        $this->assignToSite($failed, $siteA);
        $this->assignToSite($healthy, $siteA);
        $this->assignToSite($unmonitored, $siteB);
        $this->assignToSite($unrelated, $unrelatedSite);

        $profile = MonitoringProfile::factory()->create([]);
        Monitor::factory()->create([
            'profile_id' => $profile->id,
            'device_id' => $failed->id,
            'current_state' => MonitorState::Failed,
            'last_observation_at' => now()->subMinute(),
        ]);
        Monitor::factory()->create([
            'profile_id' => $profile->id,
            'device_id' => $healthy->id,
            'current_state' => MonitorState::Healthy,
            'last_observation_at' => now()->subMinute(),
        ]);

        DeviceEvent::create([
            'device_id' => $failed->id,
            'event_type' => 'availability_failed',
            'severity' => 'critical',
            'occurred_at' => now()->subMinute(),
        ]);
        DeviceMaintenanceRecord::create([
            'device_id' => $failed->id,
            'type' => 'repair',
            'status' => 'scheduled',
            'description' => 'Restore edge gateway',
            'scheduled_for' => now()->subDay(),
        ]);
        ItTicket::factory()->create([
            'site_id' => $siteA->id,
            'requester_user_id' => $this->admin->id,
            'title' => 'Harbour internet unavailable',
            'status' => 'open',
        ]);
        ItTicket::factory()->create([
            'site_id' => $unrelatedSite->id,
            'requester_user_id' => $this->admin->id,
            'title' => 'Unrelated work',
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices');

        $response->assertOk()->assertInertia(function ($page): void {
            $operations = $page->toArray()['props']['operations'];

            $this->assertSame(4, $operations['coverage']['total_devices']);
            $this->assertSame(2, $operations['coverage']['monitored_devices']);
            $this->assertSame(2, $operations['coverage']['unmonitored_devices']);
            $this->assertSame(50, $operations['coverage']['percent']);
            $this->assertSame(3, $operations['summary']['affected_sites']);
            $this->assertSame(1, $operations['summary']['active_findings']);
            $this->assertSame(2, $operations['summary']['open_it_work']);
            $this->assertSame(
                ['Harbour House', 'Kauri House', 'Unrelated House'],
                collect($operations['site_impact'])->pluck('name')->sort()->values()->all(),
            );
            $this->assertContains(
                'Unrelated gateway',
                collect($operations['recent_changes'])->pluck('device_name')->all(),
            );
            $this->assertSame(
                ['failed_monitors', 'unmonitored_devices', 'overdue_maintenance', 'open_it_work'],
                collect($operations['action_queue'])->pluck('key')->all(),
            );
        });
    }

    public function test_sites_reconcile_counts_and_never_present_empty_or_unmonitored_sites_as_healthy(): void
    {
        $siteA = Site::factory()->create(['name' => 'Action Site']);
        $siteB = Site::factory()->create(['name' => 'Empty Site']);
        $unassignedDeviceSite = Site::factory()->create(['name' => 'Rimu House']);

        $device = Device::factory()->offline()->create([
            'health_status' => HealthStatus::Critical,
            'updated_at' => now()->subMinute(),
        ]);
        $this->assignToSite($device, $siteA);
        DeviceEvent::create([
            'device_id' => $device->id,
            'event_type' => 'offline',
            'severity' => 'critical',
            'occurred_at' => now(),
        ]);
        DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'repair',
            'status' => 'scheduled',
            'description' => 'Overdue repair',
            'scheduled_for' => now()->subDay(),
        ]);
        MonitoringCollector::factory()->create([
            'site_id' => $siteA->id,
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(10),
        ]);
        ItTicket::factory()->create([
            'site_id' => $siteA->id,
            'requester_user_id' => $this->admin->id,
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/sites');

        $response->assertOk()->assertInertia(function ($page) use ($unassignedDeviceSite, $siteA, $siteB): void {
            $props = $page->toArray()['props'];
            $sites = collect($props['sites'])->keyBy('id');

            $this->assertSame(
                collect([$siteA->id, $siteB->id, $unassignedDeviceSite->id])->sort()->values()->all(),
                $sites->keys()->sort()->values()->all(),
            );
            $this->assertSame(3, $props['summary']['total']);
            $this->assertSame(1, $props['summary']['requiring_attention']);

            $action = $sites[$siteA->id];
            $this->assertSame('critical', $action['health']);
            $this->assertSame(1, $action['devices']);
            $this->assertSame(0, $action['monitored_devices']);
            $this->assertSame(1, $action['unmonitored_devices']);
            $this->assertSame(0, $action['coverage_percent']);
            $this->assertSame(1, $action['active_findings']);
            $this->assertSame(1, $action['open_it_work']);
            $this->assertSame(1, $action['overdue_maintenance']);
            $this->assertSame('stale', $action['collector']['state']);
            $this->assertNotNull($action['last_change_at']);

            $empty = $sites[$siteB->id];
            $this->assertSame('unknown', $empty['health']);
            $this->assertNull($empty['coverage_percent']);
            $this->assertSame('not_configured', $empty['collector']['state']);
        });
    }

    public function test_site_technology_combines_canonical_context_without_duplicating_source_records(): void
    {
        $site = Site::factory()->create(['name' => 'Technology House']);
        $router = Device::factory()->itInfrastructure()->create([
            'name' => 'SD-WAN gateway',
            'subcategory' => 'edge_router',
        ]);
        $camera = Device::factory()->security()->create([
            'name' => 'Front camera',
        ]);
        $this->assignToSite($router, $site);
        $this->assignToSite($camera, $site);

        DeviceRelationship::create([
            'parent_device_id' => $router->id,
            'child_device_id' => $camera->id,
            'relationship_type' => 'connected_to',
        ]);
        $group = DeviceGroup::create([
            'name' => 'Front entrance',
            'type' => 'location',
        ]);
        $group->devices()->attach([$router->id, $camera->id]);

        $profile = MonitoringProfile::factory()->create([]);
        Monitor::factory()->create([
            'profile_id' => $profile->id,
            'device_id' => $router->id,
            'current_state' => MonitorState::Healthy,
            'last_observation_at' => now(),
        ]);
        ControlRoomAlert::factory()->critical()->create([
            'site_id' => $site->id,
            'status' => ControlRoomAlert::STATUS_OPEN,
            'alert_type' => 'WAN path unavailable',
        ]);
        ItTicket::factory()->create([
            'site_id' => $site->id,
            'requester_user_id' => $this->admin->id,
            'title' => 'Review WAN failover',
            'status' => 'open',
        ]);
        DeviceMaintenanceRecord::create([
            'device_id' => $camera->id,
            'type' => 'inspection',
            'status' => 'scheduled',
            'description' => 'Camera inspection',
            'scheduled_for' => now()->addDay(),
        ]);
        SiteContact::create([
            'site_id' => $site->id,
            'type' => 'technology',
            'name' => 'Alex Technician',
            'role' => 'Site technology contact',
            'email' => 'alex@example.test',
            'is_primary' => true,
        ]);

        $response = $this->actingAs($this->admin)->get("/security-devices/sites/{$site->id}");

        $response->assertOk()->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];

            $this->assertTrue($props['wan']['known']);
            $this->assertSame(['SD-WAN gateway'], collect($props['wan']['devices'])->pluck('name')->all());
            $this->assertSame(1, $props['topology']['edge_count']);
            $this->assertSame(2, $props['topology']['device_count']);
            $this->assertSame(['Front entrance'], collect($props['deviceGroups'])->pluck('name')->all());
            $this->assertSame(2, $props['monitoring']['total_devices']);
            $this->assertSame(1, $props['monitoring']['unmonitored_devices']);
            $this->assertSame(['WAN path unavailable'], collect($props['alerts'])->pluck('title')->all());
            $this->assertSame(['Review WAN failover'], collect($props['itWork'])->pluck('title')->all());
            $this->assertSame(['Camera inspection'], collect($props['maintenance'])->pluck('description')->all());
            $this->assertSame(['Alex Technician'], collect($props['contacts'])->pluck('name')->all());
            $this->assertTrue($props['can']['view_control_room']);
            $this->assertTrue($props['can']['view_it_work']);
        });
    }

    public function test_site_access_and_cross_module_projections_require_their_own_permissions(): void
    {
        $allowedSite = Site::factory()->create(['name' => 'Allowed Site']);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden Site']);
        $viewer = User::factory()->create([

            'approved_at' => now(),
        ]);
        $viewer->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $deniedCrossModulePermissions = Permission::query()
            ->whereIn('key', ['controlRoom.viewAny', 'controlRoom.alerts.view', 'it.view'])
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
            ->all();
        $viewer->permissionOverrides()->syncWithoutDetaching($deniedCrossModulePermissions);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
        ]);

        foreach ([$allowedSite, $hiddenSite] as $site) {
            $device = Device::factory()->create([]);
            $this->assignToSite($device, $site);
        }
        ControlRoomAlert::factory()->create([
            'site_id' => $allowedSite->id,
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        ItTicket::factory()->create([
            'site_id' => $allowedSite->id,
            'requester_user_id' => $viewer->id,
            'status' => 'open',
        ]);

        $this->actingAs($viewer)
            ->get('/security-devices/sites')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sites', 1)
                ->where('sites.0.id', $allowedSite->id)
                ->where('sites.0.open_it_work', null)
                ->where('sites.0.active_control_room_alerts', null));

        $this->actingAs($viewer)
            ->get("/security-devices/sites/{$allowedSite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('alerts', 0)
                ->has('itWork', 0)
                ->where('can.view_control_room', false)
                ->where('can.view_it_work', false));

        $this->actingAs($viewer)
            ->get("/security-devices/sites/{$hiddenSite->id}")
            ->assertNotFound();
    }

    public function test_all_sites_user_can_open_any_site(): void
    {
        $unrelatedSite = Site::factory()->create([]);

        $this->actingAs($this->admin)
            ->get("/security-devices/sites/{$unrelatedSite->id}")
            ->assertOk();
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
}
