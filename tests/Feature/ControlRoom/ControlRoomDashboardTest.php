<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\Providers\ControlRoomAlertProvider;
use Database\Factories\ControlRoomAlertFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControlRoomDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $coordinator;

    protected User $supportWorker;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'organization_id' => 1,
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
        ]);

        $this->coordinator = User::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'role' => 'coordinator',
            'approved_at' => now(),
        ]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->supportWorker = User::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    // ──────────────────────────────────────
    // Authentication & Authorization
    // ──────────────────────────────────────

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/control-room')->assertRedirect('/login');
    }

    public function test_dashboard_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk();
    }

    public function test_dashboard_accessible_by_coordinator(): void
    {
        $this->actingAs($this->coordinator)
            ->get('/control-room')
            ->assertOk();
    }

    public function test_dashboard_blocked_for_support_worker_without_full_operator_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/control-room')
            ->assertForbidden();
    }

    public function test_dashboard_blocked_for_user_without_permission(): void
    {
        $noPermUser = User::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'approved_at' => now(),
        ]);
        // No roles attached

        $this->actingAs($noPermUser)
            ->get('/control-room')
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Dashboard Data Rendering
    // ──────────────────────────────────────

    public function test_dashboard_returns_inertia_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/index')
                ->has('hero')
                ->has('worklist')
                ->has('handover')
                ->has('activity')
                ->has('freshness')
                ->has('alerts')
                ->has('stats')
                ->has('staff')
                ->has('filters')
                ->has('can')
                ->missing('analytics')
            );
    }

    public function test_dashboard_shows_correct_stats(): void
    {
        $this->alertFactory()->open()->count(3)->create();
        $this->alertFactory()->acknowledged()->count(2)->create();
        $this->alertFactory()->triaging()->count(1)->create();
        $this->alertFactory()->resolved()->count(4)->create();
        $this->alertFactory()->closed()->count(2)->create();

        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 12)
                ->where('stats.open', 3)
                ->where('stats.acknowledged', 2)
                ->where('stats.triaging', 1)
                ->where('stats.resolved', 4)
                ->where('stats.closed', 2)
            );
    }

    public function test_dashboard_shows_empty_state(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 0)
                ->where('stats.open', 0)
                ->where('alerts.meta.total', 0)
            );
    }

    public function test_dashboard_returns_paginated_alerts(): void
    {
        $this->alertFactory()->count(30)->create();

        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 30)
                ->where('alerts.meta.per_page', 25)
                ->has('alerts.data', 25)
            );
    }

    public function test_task7_final_gap_dashboard_default_and_all_lists_exclude_terminal_and_unknown_history(): void
    {
        $active = $this->alertFactory()->open()->create();
        $resolved = $this->alertFactory()->resolved()->create();
        $this->alertFactory()->closed()->create();
        $this->alertFactory()->create(['status' => ControlRoomAlert::STATUS_DISMISSED]);
        $legacy = $this->alertFactory()->open()->create();
        DB::table('control_room_alerts')
            ->where('id', $legacy->id)
            ->update(['status' => 'legacy_unknown']);

        $onlyActive = fn ($rows): bool => collect($rows)->pluck('id')->all() === [$active->id];

        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('alerts.data', $onlyActive));

        $this->actingAs($this->admin)
            ->get('/control-room?status=all')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('alerts.data', $onlyActive));

        $this->actingAs($this->admin)
            ->get('/control-room?status=resolved')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', $resolved->id));
    }

    public function test_dashboard_defers_historical_trends_from_the_initial_live_desk(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('analytics')
            );
    }

    public function test_residual_terminal_sla_is_omitted_from_dashboard_status_and_daily_compliance(): void
    {
        $alert = $this->alertFactory()->open()->create();
        AlertSla::query()->create([
            'alert_id' => $alert->id,
            'ended_as' => AlertSla::ENDED_RECONCILED_NO_MATCH,
            'cycle_history' => [['ended_as' => AlertSla::ENDED_RECONCILED_NO_MATCH]],
        ]);

        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.data.0.id', $alert->id)
                ->where('alerts.data.0.sla_status', null)
                ->missing('analytics')
            );
    }

    public function test_dashboard_scopes_alerts_stats_staff_and_sites_for_site_bound_user(): void
    {
        $visibleSite = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
            'name' => 'Visible Site',
            'type' => 'house',
        ]);
        $hiddenSite = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
            'name' => 'Hidden Site',
            'type' => 'house',
        ]);
        $visibleOperator = $this->makeRoleUser('coordinator');
        $hiddenOperator = $this->makeRoleUser('coordinator');

        $this->scopeUserToSite($this->coordinator, $visibleSite);
        $this->scopeUserToSite($visibleOperator, $visibleSite);
        $this->scopeUserToSite($hiddenOperator, $hiddenSite);

        $visibleAlert = $this->alertFactory()->open()->create([
            'site_id' => $visibleSite->id,
        ]);

        $this->alertFactory()->open()->create([
            'site_id' => $hiddenSite->id,
        ]);

        $this->actingAs($this->coordinator)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 1)
                ->where('alerts.meta.total', 1)
                ->where('alerts.data.0.id', $visibleAlert->id)
                ->has('staff', 2)
                ->has('sites', 1)
                ->where('sites.0.id', $visibleSite->id)
            );
    }

    public function test_dashboard_recent_activity_is_scoped_to_visible_alerts(): void
    {
        $visibleSite = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
            'name' => 'Visible Site',
            'type' => 'house',
        ]);
        $hiddenSite = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
            'name' => 'Hidden Site',
            'type' => 'house',
        ]);

        $this->scopeUserToSite($this->coordinator, $visibleSite);

        $visibleAlert = $this->alertFactory()->open()->create([
            'site_id' => $visibleSite->id,
        ]);

        $hiddenAlert = $this->alertFactory()->open()->create([
            'site_id' => $hiddenSite->id,
        ]);

        AuditLog::create([
            'organization_id' => $this->admin->organization_id,
            'user_id' => $this->admin->id,
            'action' => 'controlRoom.alert.acknowledge',
            'auditable_type' => $visibleAlert->getMorphClass(),
            'auditable_id' => $visibleAlert->id,
            'meta' => ['alert_id' => $visibleAlert->id],
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        AuditLog::create([
            'organization_id' => $this->admin->organization_id,
            'user_id' => $this->admin->id,
            'action' => 'controlRoom.alert.escalate',
            'auditable_type' => $hiddenAlert->getMorphClass(),
            'auditable_id' => $hiddenAlert->id,
            'meta' => ['alert_id' => $hiddenAlert->id],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->coordinator)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('recent_activity', 1)
                ->where('recent_activity.0.meta.alert_id', $visibleAlert->id)
            );
    }

    public function test_manage_any_permissions_do_not_bypass_dashboard_site_scope(): void
    {
        $visibleSite = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
            'name' => 'Visible Site',
            'type' => 'house',
        ]);
        $hiddenSite = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
            'name' => 'Hidden Site',
            'type' => 'house',
        ]);

        $this->scopeUserToSite($this->coordinator, $visibleSite);

        $permissionMap = Permission::query()
            ->whereIn('key', ['shifts.manageAny', 'timesheets.manageAny'])
            ->pluck('id', 'key');

        $this->coordinator->permissionOverrides()->syncWithoutDetaching([
            $permissionMap['shifts.manageAny'] => ['allowed' => true],
            $permissionMap['timesheets.manageAny'] => ['allowed' => true],
        ]);

        $this->alertFactory()->open()->create([
            'site_id' => $visibleSite->id,
        ]);

        $this->alertFactory()->open()->create([
            'site_id' => $hiddenSite->id,
        ]);

        $this->actingAs($this->coordinator)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 1)
                ->where('alerts.meta.total', 1)
                ->has('sites', 1)
                ->where('sites.0.id', $visibleSite->id)
            );
    }

    // ──────────────────────────────────────
    // Filtering
    // ──────────────────────────────────────

    public function test_filter_by_status(): void
    {
        $this->alertFactory()->open()->count(3)->create();
        $this->alertFactory()->resolved()->count(2)->create();

        $this->actingAs($this->admin)
            ->get('/control-room?status=open')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 3)
            );
    }

    public function test_filter_by_severity(): void
    {
        $this->alertFactory()->critical()->count(2)->create();
        $this->alertFactory()->low()->count(5)->create();

        $this->actingAs($this->admin)
            ->get('/control-room?severity=critical')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 2)
            );
    }

    public function test_filter_by_source(): void
    {
        $this->alertFactory()->fromFleet()->count(3)->create();
        $this->alertFactory()->fromCompliance()->count(4)->create();

        $this->actingAs($this->admin)
            ->get('/control-room?source=fleet')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 3)
            );
    }

    public function test_filter_by_assigned_to_me(): void
    {
        $this->alertFactory()->assignedTo($this->admin)->count(2)->create();
        $this->alertFactory()->count(3)->create();

        $this->actingAs($this->admin)
            ->get('/control-room?assigned_to=me')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 2)
            );
    }

    public function test_filter_by_unassigned(): void
    {
        $this->alertFactory()->assignedTo($this->admin)->count(2)->create();
        $this->alertFactory()->count(3)->create(); // These will have null assigned_to

        $this->actingAs($this->admin)
            ->get('/control-room?assigned_to=unassigned')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 3)
            );
    }

    public function test_filter_by_escalation_level(): void
    {
        $this->alertFactory()->escalated(2)->count(2)->create();
        $this->alertFactory()->count(5)->create(); // Level 0

        $this->actingAs($this->admin)
            ->get('/control-room?escalation_level=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 2)
            );
    }

    public function test_filter_by_search(): void
    {
        $this->alertFactory()->create(['alert_type' => 'Fire Alarm']);
        $this->alertFactory()->create(['alert_type' => 'Speeding']);
        $this->alertFactory()->create(['notes' => 'Fire detected in kitchen']);

        $this->actingAs($this->admin)
            ->get('/control-room?search=Fire')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 2)
            );
    }

    public function test_filter_by_date_range(): void
    {
        $this->alertFactory()->create(['triggered_at' => now()->subDays(2)]);
        $this->alertFactory()->create(['triggered_at' => now()->subDays(10)]);
        $this->alertFactory()->create(['triggered_at' => now()->subDays(20)]);

        $from = now()->subDays(5)->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $this->actingAs($this->admin)
            ->get("/control-room?date_from={$from}&date_to={$to}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 1)
            );
    }

    public function test_combined_filters(): void
    {
        $this->alertFactory()->critical()->fromFleet()->open()->count(2)->create();
        $this->alertFactory()->critical()->fromCompliance()->open()->create();
        $this->alertFactory()->low()->fromFleet()->open()->create();

        $this->actingAs($this->admin)
            ->get('/control-room?severity=critical&source=fleet&status=open')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 2)
            );
    }

    // ──────────────────────────────────────
    // Sorting
    // ──────────────────────────────────────

    public function test_sort_by_severity(): void
    {
        $this->alertFactory()->critical()->create();
        $this->alertFactory()->low()->create();

        $this->actingAs($this->admin)
            ->get('/control-room?sort=severity&dir=asc')
            ->assertOk();
    }

    public function test_sort_by_triggered_at(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room?sort=triggered_at&dir=desc')
            ->assertOk();
    }

    public function test_invalid_sort_field_uses_default(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room?sort=invalid_field')
            ->assertOk();
    }

    // ──────────────────────────────────────
    // Permissions exposed to frontend
    // ──────────────────────────────────────

    public function test_admin_gets_all_permissions(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.manage', true)
                ->where('can.assign', true)
                ->where('can.escalate', true)
                ->where('can.create', true)
                ->where('can.viewReports', true)
            );
    }

    public function test_task5_failed_gate_support_worker_keeps_narrow_alert_task_visibility_without_operator_dashboard(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/control-room')
            ->assertForbidden();

        $this->assertFalse($this->supportWorker->canDo('controlRoom.viewAny'));
        $this->assertTrue($this->supportWorker->canDo('controlRoom.alerts.view'));
        $this->assertTrue(app(ControlRoomAlertProvider::class)->canView($this->supportWorker));
    }

    // ──────────────────────────────────────
    // Pagination
    // ──────────────────────────────────────

    public function test_pagination_page_2(): void
    {
        $this->alertFactory()->count(30)->create();

        $this->actingAs($this->admin)
            ->get('/control-room?page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.current_page', 2)
                ->has('alerts.data', 5) // 30 - 25 = 5
            );
    }

    private function alertFactory(): ControlRoomAlertFactory
    {
        return ControlRoomAlert::factory()->state([
            'site_id' => $this->site->id,
        ]);
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'role' => $roleName,
            'approved_at' => now(),
        ]);

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    protected function scopeUserToSite(User $user, Site $site): void
    {
        HrEmployeeProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => $user->organization_id,
                'employee_number' => 'EMP-DASH-'.$user->id,
                'work_email' => $user->email,
                'position_title' => 'Control Room',
                'position_role' => $user->role,
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
            ],
        );
    }
}
