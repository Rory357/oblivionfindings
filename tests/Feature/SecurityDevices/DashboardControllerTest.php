<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\System\ServerReadinessService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $noPerms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->noPerms = User::factory()->create();
    }

    // ── Authorization ─────────────────────────────────────────────

    public function test_requires_authentication(): void
    {
        $this->get('/security-devices')->assertRedirect('/login');
    }

    public function test_requires_view_any_permission(): void
    {
        $this->actingAs($this->noPerms)
            ->get('/security-devices')
            ->assertForbidden();
    }

    public function test_accessible_with_permission(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices')
            ->assertOk();
    }

    // ── Props structure ───────────────────────────────────────────

    public function test_returns_expected_props(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/dashboard')
                ->where('actor_id', $this->admin->id)
                ->has('as_of')
                ->has('stats')
                ->has('domainSummary')
                ->has('healthSummary')
                ->has('attentionDevices')
                ->has('recentEvents')
                ->has('overdueMaintenance')
                ->has('groupCount')
                ->has('can')
            );
    }

    public function test_dashboard_actions_are_noninteractive_with_plain_reasons_without_destination_permissions(): void
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        $modulePermission = Permission::query()
            ->where('key', 'securityDevices.viewAny')
            ->firstOrFail();
        $viewer->permissionOverrides()->attach($modulePermission->id, ['allowed' => true]);

        $this->actingAs($viewer)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.view_devices', false)
                ->where('can.view_events', false)
                ->where('can.view_maintenance', false)
                ->where('operations.action_queue.0.href', null)
                ->where('operations.action_queue.0.restriction_reason', 'Monitoring access is required to open this queue.')
                ->where('operations.action_queue.1.href', null)
                ->where('operations.action_queue.1.restriction_reason', 'Device inventory access is required to open this queue.')
                ->where('operations.action_queue.2.href', null)
                ->where('operations.action_queue.2.restriction_reason', 'Maintenance access is required to open this queue.')
                ->where('operations.action_queue.3.href', null)
                ->where('operations.action_queue.3.restriction_reason', 'IT work access is required to open this queue.')
            );
    }

    public function test_dashboard_authorised_actions_target_real_destination_queues(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.view_devices', true)
                ->where('can.view_events', true)
                ->where('can.view_maintenance', true)
                ->where('operations.action_queue.0.href', '/security-devices/monitoring?state=failed')
                ->where('operations.action_queue.1.href', '/security-devices/devices?view=unmonitored')
                ->where('operations.action_queue.2.href', '/security-devices/maintenance?status=overdue')
                ->where('operations.action_queue.3.href', '/it?tab=tickets&view=all_open')
            );
    }

    // ── Stats correctness ─────────────────────────────────────────

    public function test_stats_count_devices_by_status(): void
    {
        Device::factory()->count(3)->create(['status' => DeviceStatus::Active]);
        Device::factory()->create(['status' => DeviceStatus::Offline]);
        Device::factory()->create(['status' => DeviceStatus::Degraded]);

        $response = $this->actingAs($this->admin)->get('/security-devices');

        $response->assertInertia(fn ($page) => $page
            ->where('stats.totalDevices', 5)
            ->where('stats.active', 3)
            ->where('stats.offline', 1)
            ->where('stats.degraded', 1)
        );
    }

    public function test_stats_count_low_battery(): void
    {
        Device::factory()->create(['battery_level' => 10, 'battery_updated_at' => now()]);
        Device::factory()->create(['battery_level' => 80, 'battery_updated_at' => now()]);
        Device::factory()->create(); // no battery

        $response = $this->actingAs($this->admin)->get('/security-devices');

        $response->assertInertia(fn ($page) => $page
            ->where('stats.lowBattery', 1)
        );
    }

    public function test_stats_count_overdue_maintenance(): void
    {
        $device = Device::factory()->create();

        DeviceMaintenanceRecord::create([
            'device_id' => $device->id, 'type' => 'inspection', 'status' => 'scheduled',
            'description' => 'Overdue', 'scheduled_for' => now()->subDays(5),
        ]);
        DeviceMaintenanceRecord::create([
            'device_id' => $device->id, 'type' => 'repair', 'status' => 'scheduled',
            'description' => 'Not yet due', 'scheduled_for' => now()->addDays(10),
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices');

        $response->assertInertia(fn ($page) => $page
            ->where('stats.overdueMaintenance', 1)
        );
    }

    public function test_stats_count_events_24h(): void
    {
        $device = Device::factory()->create();

        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'alarm_trigger',
            'severity' => 'critical', 'occurred_at' => now()->subHours(2),
        ]);
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'battery_low',
            'severity' => 'warning', 'occurred_at' => now()->subHours(6),
        ]);
        // Old event — should not count.
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'alarm_trigger',
            'severity' => 'critical', 'occurred_at' => now()->subDays(3),
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices');

        $response->assertInertia(fn ($page) => $page
            ->where('stats.criticalEvents24h', 1)
            ->where('stats.warningEvents24h', 1)
        );
    }

    // ── Domain summary ────────────────────────────────────────────

    public function test_domain_summary_counts(): void
    {
        Device::factory()->security()->count(3)->create();
        Device::factory()->itInfrastructure()->count(2)->create();
        Device::factory()->tracking()->create();

        $response = $this->actingAs($this->admin)->get('/security-devices');

        $response->assertInertia(function ($page) {
            $summary = collect($page->toArray()['props']['domainSummary']);
            $this->assertEquals(3, $summary->firstWhere('domain', 'security')['count']);
            $this->assertEquals(2, $summary->firstWhere('domain', 'it_infrastructure')['count']);
            $this->assertEquals(1, $summary->firstWhere('domain', 'tracking')['count']);
            $this->assertEquals(0, $summary->firstWhere('domain', 'facilities')['count']);
        });
    }

    // ── Health summary ────────────────────────────────────────────

    public function test_health_summary_counts(): void
    {
        $profile = MonitoringProfile::factory()->create();
        $healthyDevices = Device::factory()->count(2)->create(['health_status' => HealthStatus::Healthy]);
        foreach ($healthyDevices as $healthyDevice) {
            Monitor::factory()->create([
                'device_id' => $healthyDevice->id,
                'profile_id' => $profile->id,
                'current_state' => MonitorState::Healthy,
                'is_enabled' => true,
                'last_observation_at' => now(),
            ]);
        }

        // Unmonitored device with stored "healthy" must never count as healthy without evidence.
        Device::factory()->create(['health_status' => HealthStatus::Healthy]);
        Device::factory()->create(['health_status' => HealthStatus::Critical]);

        $response = $this->actingAs($this->admin)->get('/security-devices');

        $response->assertInertia(function ($page) {
            $summary = collect($page->toArray()['props']['healthSummary']);
            $this->assertEquals(2, $summary->firstWhere('status', 'healthy')['count']);
            $this->assertEquals(1, $summary->firstWhere('status', 'critical')['count']);
            $this->assertEquals(1, $summary->firstWhere('status', 'unknown')['count']);
        });
    }

    // ── Attention devices ─────────────────────────────────────────

    public function test_attention_devices_only_includes_needing_attention(): void
    {
        Device::factory()->create(['health_status' => HealthStatus::Critical, 'status' => DeviceStatus::Active, 'name' => 'Critical One']);
        Device::factory()->create(['health_status' => HealthStatus::Healthy, 'status' => DeviceStatus::Active, 'name' => 'Healthy One']);

        $response = $this->actingAs($this->admin)->get('/security-devices');

        $response->assertInertia(function ($page) {
            $attention = $page->toArray()['props']['attentionDevices'];
            $this->assertCount(1, $attention);
            $this->assertEquals('Critical One', $attention[0]['name']);
        });
    }

    // ── Recent events ─────────────────────────────────────────────

    public function test_recent_events_only_critical_and_warning(): void
    {
        $device = Device::factory()->create();

        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'alarm_trigger',
            'severity' => 'critical', 'occurred_at' => now(),
        ]);
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices');

        $response->assertInertia(function ($page) {
            $events = $page->toArray()['props']['recentEvents'];
            $this->assertCount(1, $events);
            $this->assertEquals('critical', $events[0]['severity']);
        });
    }

    // ── Overdue maintenance ───────────────────────────────────────

    public function test_overdue_maintenance_list(): void
    {
        $device = Device::factory()->create();

        DeviceMaintenanceRecord::create([
            'device_id' => $device->id, 'type' => 'inspection', 'status' => 'scheduled',
            'description' => 'Overdue one', 'scheduled_for' => now()->subDays(3),
        ]);
        DeviceMaintenanceRecord::create([
            'device_id' => $device->id, 'type' => 'repair', 'status' => 'completed',
            'description' => 'Done one', 'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices');

        $response->assertInertia(function ($page) {
            $overdue = $page->toArray()['props']['overdueMaintenance'];
            $this->assertCount(1, $overdue);
            $this->assertEquals('Overdue one', $overdue[0]['description']);
        });
    }

    // ── Group count ───────────────────────────────────────────────

    public function test_group_count(): void
    {
        DeviceGroup::create(['name' => 'Group A', 'type' => 'custom']);
        DeviceGroup::create(['name' => 'Group B', 'type' => 'location']);

        $response = $this->actingAs($this->admin)->get('/security-devices');

        $response->assertInertia(fn ($page) => $page->where('groupCount', 2));
    }

    public function test_dashboard_data_covers_the_single_application_registry_for_all_sites_users(): void
    {

        $primaryDevice = Device::factory()->security()->create([
            'name' => 'Primary camera',
            'health_status' => HealthStatus::Critical,
        ]);
        $unrelatedDevice = Device::factory()->security()->create([
            'name' => 'Unrelated camera',
            'health_status' => HealthStatus::Critical,
        ]);

        foreach ([$primaryDevice, $unrelatedDevice] as $device) {
            DeviceEvent::create([
                'device_id' => $device->id,
                'event_type' => 'offline',
                'severity' => 'critical',
                'source' => 'unifi',
                'occurred_at' => now(),
            ]);
            DeviceMaintenanceRecord::create([
                'device_id' => $device->id,
                'type' => 'inspection',
                'status' => 'scheduled',
                'description' => $device->name.' maintenance',
                'scheduled_for' => now()->subDay(),
            ]);
        }

        DeviceGroup::create(['name' => 'Primary group', 'type' => 'custom']);
        DeviceGroup::create(['name' => 'Unrelated group', 'type' => 'custom']);

        $response = $this->actingAs($this->admin)->get('/security-devices');

        $response->assertInertia(function ($page) {
            $props = $page->toArray()['props'];

            $this->assertSame(2, $props['stats']['totalDevices']);
            $this->assertSame(2, $props['stats']['criticalEvents24h']);
            $this->assertSame(2, $props['stats']['overdueMaintenance']);
            $this->assertSame(2, $props['groupCount']);
            $this->assertEqualsCanonicalizing(
                ['Primary camera', 'Unrelated camera'],
                collect($props['attentionDevices'])->pluck('name')->all(),
            );
            $this->assertEqualsCanonicalizing(
                ['Primary camera', 'Unrelated camera'],
                collect($props['recentEvents'])->pluck('device_name')->all(),
            );
            $this->assertEqualsCanonicalizing(
                ['Primary camera maintenance', 'Unrelated camera maintenance'],
                collect($props['overdueMaintenance'])->pluck('description')->all(),
            );
        });
    }

    public function test_estate_findings_use_an_inclusive_past_window_and_exclude_future_signals(): void
    {
        $this->travelTo(now()->startOfSecond());
        $device = Device::factory()->create();
        foreach (['window_start' => now()->subHours(24), 'window_end' => now(), 'too_old' => now()->subHours(24)->subSecond(), 'future' => now()->addSecond()] as $type => $at) {
            DeviceEvent::create(['device_id' => $device->id, 'event_type' => $type, 'severity' => 'critical', 'source' => 'test', 'occurred_at' => $at]);
        }
        $this->actingAs($this->admin)->get('/security-devices')->assertOk()->assertInertia(fn ($page) => $page
            ->where('actor_id', $this->admin->id)->where('as_of', now()->toISOString())->where('stats.criticalEvents24h', 2)->has('recentEvents', 2)
            ->where('recentEvents.0.event_type', 'window_end')->where('recentEvents.1.event_type', 'window_start')->where('operations.summary.active_findings', 2));
    }

    public function test_first_run_checklist_reflects_real_setup_state_without_fake_completion(): void
    {
        Cache::forget(ServerReadinessService::CHECKLIST_CACHE_KEY);

        $this->actingAs($this->admin)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('first_run_checklist.total', 4)
                ->where('first_run_checklist.completed', 0)
                ->where('first_run_checklist.all_complete', false)
                ->where('first_run_checklist.steps.0.key', 'server_readiness')
                ->where('first_run_checklist.steps.0.done', false)
                ->where('first_run_checklist.steps.0.href', '/settings/server-readiness')
                ->where('first_run_checklist.steps.1.key', 'sites')
                ->where('first_run_checklist.steps.1.done', false)
                ->where('first_run_checklist.steps.2.key', 'devices')
                ->where('first_run_checklist.steps.2.done', false)
                ->where('first_run_checklist.steps.3.key', 'monitoring')
                ->where('first_run_checklist.steps.3.done', false)
            );

        Cache::put(ServerReadinessService::CHECKLIST_CACHE_KEY, [
            'overall_status' => 'pass',
            'passed_count' => 7,
            'failed_count' => 0,
            'total_count' => 7,
            'checked_at' => now()->utc()->toIso8601String(),
        ], 60);

        Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $profile = MonitoringProfile::factory()->create();
        $device = Device::factory()->create();
        Monitor::factory()->create([
            'device_id' => $device->id,
            'profile_id' => $profile->id,
            'is_enabled' => true,
            'current_state' => MonitorState::Healthy,
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('first_run_checklist.completed', 4)
                ->where('first_run_checklist.all_complete', true)
            );
    }
}
