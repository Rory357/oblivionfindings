<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /security-devices. S&D rebuild C4: the old dashboard became the Overview (pg-sdr-1). The dashboard's
 * own lists and counts (domain and health summaries, overdue maintenance, group count, the first-run
 * checklist and its action queue) went with it; what they protected (who may open it, who is offered
 * which action, that nothing counts as healthy without evidence, that every Site counts for all-Sites
 * people, and that future-stamped events never show) is checked here against the Overview.
 */
class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $noPerms;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create(['approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->noPerms = User::factory()->create();
        $this->site = Site::factory()->create(['name' => 'Aurora House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    }

    private function deviceAt(Site $site, array $attributes = [], ?MonitorState $check = null): Device
    {
        $device = Device::factory()->itInfrastructure()->create(['subcategory' => 'switch', ...$attributes]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now()->subDays(3),
        ]);
        if ($check !== null) {
            Monitor::factory()->create([
                'device_id' => $device->id,
                'profile_id' => MonitoringProfile::factory()->create()->id,
                'current_state' => $check,
                'is_enabled' => true,
                'last_observation_at' => now()->subMinute(),
                'last_state_changed_at' => now()->subHours(2),
            ]);
        }

        return $device;
    }

    /** @param  list<string>  $keys */
    private function viewerWith(array $keys): User
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        foreach ($keys as $key) {
            $viewer->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $viewer;
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

    // ── The Overview's shape ──────────────────────────────────────

    public function test_returns_the_overview(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/overview/index')
                ->where('tab', 'overview')
                ->where('site', 'all')
                ->has('sites')
                ->has('meters.online')
                ->has('meters.offline')
                ->has('meters.cant_check')
                ->has('meters.alerts')
                ->has('can')
            );
    }

    // ── Actions follow the viewer's own permissions ───────────────

    public function test_overview_actions_are_not_offered_without_their_permissions(): void
    {
        // reports.view: someone who looks after devices (the IT Overview), but who may not add, scan or move them.
        $viewer = $this->viewerWith(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.devices.viewAllSites', 'securityDevices.reports.view']);

        $this->actingAs($viewer)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/overview/index')
                ->where('can.add', false)
                ->where('can.scan', false)
                ->where('can.connect', false)
                ->where('can.assign', false)
                ->where('can.add_hub', false)
                ->where('can.approve_hub', false)
            );
    }

    public function test_overview_actions_are_offered_to_an_administrator(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.add', true)
                ->where('can.scan', true)
                ->where('can.connect', true)
                ->where('can.assign', true)
            );
    }

    // ── Counts ────────────────────────────────────────────────────

    public function test_nothing_counts_as_online_without_check_evidence(): void
    {
        $this->deviceAt($this->site, ['health_status' => HealthStatus::Healthy], MonitorState::Healthy);
        $this->deviceAt($this->site, ['health_status' => HealthStatus::Healthy], MonitorState::Healthy);
        // A stored "healthy" with no check behind it must never count as online.
        $this->deviceAt($this->site, ['health_status' => HealthStatus::Healthy]);
        $this->deviceAt($this->site, ['health_status' => HealthStatus::Critical]);

        $this->actingAs($this->admin)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('meters.online.count', 2)
                ->where('meters.online.of', 2)
                ->where('meters.offline.count', 0)
            );
    }

    public function test_the_overview_covers_every_site_for_all_sites_users(): void
    {
        $other = Site::factory()->create(['name' => 'Kōwhai Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->deviceAt($this->site, ['name' => 'Aurora switch'], MonitorState::Failed);
        $this->deviceAt($other, ['name' => 'Kōwhai switch'], MonitorState::Failed);

        $this->actingAs($this->admin)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];

                $this->assertSame(2, $props['meters']['offline']['count']);
                $this->assertEqualsCanonicalizing(
                    ['Aurora switch is offline', 'Kōwhai switch is offline'],
                    collect($props['overview']['attention'])->pluck('title')->all(),
                );
            });

        $this->actingAs($this->admin)
            ->get('/security-devices?tab=trends')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('trends.device_count', 2));
    }

    // ── Activity ──────────────────────────────────────────────────

    public function test_activity_excludes_future_signals(): void
    {
        $this->travelTo(now()->startOfSecond());
        $device = $this->deviceAt($this->site);
        foreach (['window_start' => now()->subHours(24), 'window_end' => now(), 'future' => now()->addSecond()] as $type => $at) {
            DeviceEvent::create(['device_id' => $device->id, 'event_type' => $type, 'severity' => 'critical', 'source' => 'test', 'occurred_at' => $at]);
        }

        $this->actingAs($this->admin)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('overview.activity', 2)
                ->where('overview.activity.0.title', 'Window end')
                ->where('overview.activity.1.title', 'Window start')
            );
    }

    public function test_activity_needs_the_device_events_permission(): void
    {
        $device = $this->deviceAt($this->site);
        DeviceEvent::create(['device_id' => $device->id, 'event_type' => 'private_event_detail', 'severity' => 'critical', 'source' => 'test', 'occurred_at' => now()->subMinute()]);
        $viewer = $this->viewerWith(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.devices.viewAllSites', 'securityDevices.reports.view']);

        $response = $this->actingAs($viewer)->get('/security-devices');

        $response->assertOk()->assertInertia(fn ($page) => $page->where('overview.activity', []));
        $this->assertStringNotContainsString('Private event detail', $response->getContent());
    }

    // ── First time (the old first-run checklist) ──────────────────

    public function test_first_time_steps_reflect_real_setup_state_without_fake_completion(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('first_time.steps', 4)
                ->where('first_time.steps.0.state', 'now')
                ->where('first_time.steps.1.state', 'todo')
                ->where('first_time.steps.2.state', 'todo')
                ->where('first_time.steps.3.state', 'todo')
                ->where('overview', null)
            );

        $this->deviceAt($this->site, [], MonitorState::Healthy);

        $this->actingAs($this->admin)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('first_time', null)
                ->where('meters.online.count', 1)
            );
    }
}
