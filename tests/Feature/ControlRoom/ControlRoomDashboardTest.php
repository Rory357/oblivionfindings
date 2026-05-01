<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $coordinator;

    protected User $supportWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->coordinator = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
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

    public function test_dashboard_accessible_by_support_worker_with_view_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/control-room')
            ->assertOk();
    }

    public function test_dashboard_blocked_for_user_without_permission(): void
    {
        $noPermUser = User::factory()->create(['approved_at' => now()]);
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
                ->has('alerts')
                ->has('stats')
                ->has('daily_trend')
                ->has('by_severity')
                ->has('staff')
                ->has('filters')
                ->has('can')
            );
    }

    public function test_dashboard_shows_correct_stats(): void
    {
        ControlRoomAlert::factory()->open()->count(3)->create();
        ControlRoomAlert::factory()->acknowledged()->count(2)->create();
        ControlRoomAlert::factory()->triaging()->count(1)->create();
        ControlRoomAlert::factory()->resolved()->count(4)->create();
        ControlRoomAlert::factory()->closed()->count(2)->create();

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
        ControlRoomAlert::factory()->count(30)->create();

        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 30)
                ->where('alerts.meta.per_page', 25)
                ->has('alerts.data', 25)
            );
    }

    public function test_dashboard_daily_trend_has_14_entries(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('daily_trend', 14)
            );
    }

    public function test_dashboard_scopes_alerts_stats_staff_and_sites_for_site_bound_user(): void
    {
        $visibleSite = Site::factory()->create(['name' => 'Visible Site', 'type' => 'house']);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden Site', 'type' => 'house']);
        $visibleOperator = $this->makeRoleUser('coordinator');
        $hiddenOperator = $this->makeRoleUser('coordinator');

        $this->scopeUserToSite($this->coordinator, $visibleSite);
        $this->scopeUserToSite($visibleOperator, $visibleSite);
        $this->scopeUserToSite($hiddenOperator, $hiddenSite);

        $visibleAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $visibleSite->id,
        ]);

        ControlRoomAlert::factory()->open()->create([
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
        $visibleSite = Site::factory()->create(['name' => 'Visible Site', 'type' => 'house']);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden Site', 'type' => 'house']);

        $this->scopeUserToSite($this->coordinator, $visibleSite);

        $visibleAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $visibleSite->id,
        ]);

        $hiddenAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $hiddenSite->id,
        ]);

        AuditLog::create([
            'user_id' => $this->admin->id,
            'action' => 'controlRoom.alert.acknowledge',
            'auditable_type' => $visibleAlert->getMorphClass(),
            'auditable_id' => $visibleAlert->id,
            'meta' => ['alert_id' => $visibleAlert->id],
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        AuditLog::create([
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
        $visibleSite = Site::factory()->create(['name' => 'Visible Site', 'type' => 'house']);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden Site', 'type' => 'house']);

        $this->scopeUserToSite($this->coordinator, $visibleSite);

        $permissionMap = Permission::query()
            ->whereIn('key', ['shifts.manageAny', 'timesheets.manageAny'])
            ->pluck('id', 'key');

        $this->coordinator->permissionOverrides()->syncWithoutDetaching([
            $permissionMap['shifts.manageAny'] => ['allowed' => true],
            $permissionMap['timesheets.manageAny'] => ['allowed' => true],
        ]);

        ControlRoomAlert::factory()->open()->create([
            'site_id' => $visibleSite->id,
        ]);

        ControlRoomAlert::factory()->open()->create([
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
        ControlRoomAlert::factory()->open()->count(3)->create();
        ControlRoomAlert::factory()->resolved()->count(2)->create();

        $this->actingAs($this->admin)
            ->get('/control-room?status=open')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 3)
            );
    }

    public function test_filter_by_severity(): void
    {
        ControlRoomAlert::factory()->critical()->count(2)->create();
        ControlRoomAlert::factory()->low()->count(5)->create();

        $this->actingAs($this->admin)
            ->get('/control-room?severity=critical')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 2)
            );
    }

    public function test_filter_by_source(): void
    {
        ControlRoomAlert::factory()->fromFleet()->count(3)->create();
        ControlRoomAlert::factory()->fromCompliance()->count(4)->create();

        $this->actingAs($this->admin)
            ->get('/control-room?source=fleet')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 3)
            );
    }

    public function test_filter_by_assigned_to_me(): void
    {
        ControlRoomAlert::factory()->assignedTo($this->admin)->count(2)->create();
        ControlRoomAlert::factory()->count(3)->create();

        $this->actingAs($this->admin)
            ->get('/control-room?assigned_to=me')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 2)
            );
    }

    public function test_filter_by_unassigned(): void
    {
        ControlRoomAlert::factory()->assignedTo($this->admin)->count(2)->create();
        ControlRoomAlert::factory()->count(3)->create(); // These will have null assigned_to

        $this->actingAs($this->admin)
            ->get('/control-room?assigned_to=unassigned')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 3)
            );
    }

    public function test_filter_by_escalation_level(): void
    {
        ControlRoomAlert::factory()->escalated(2)->count(2)->create();
        ControlRoomAlert::factory()->count(5)->create(); // Level 0

        $this->actingAs($this->admin)
            ->get('/control-room?escalation_level=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 2)
            );
    }

    public function test_filter_by_search(): void
    {
        ControlRoomAlert::factory()->create(['alert_type' => 'Fire Alarm']);
        ControlRoomAlert::factory()->create(['alert_type' => 'Speeding']);
        ControlRoomAlert::factory()->create(['notes' => 'Fire detected in kitchen']);

        $this->actingAs($this->admin)
            ->get('/control-room?search=Fire')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.total', 2)
            );
    }

    public function test_filter_by_date_range(): void
    {
        ControlRoomAlert::factory()->create(['triggered_at' => now()->subDays(2)]);
        ControlRoomAlert::factory()->create(['triggered_at' => now()->subDays(10)]);
        ControlRoomAlert::factory()->create(['triggered_at' => now()->subDays(20)]);

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
        ControlRoomAlert::factory()->critical()->fromFleet()->open()->count(2)->create();
        ControlRoomAlert::factory()->critical()->fromCompliance()->open()->create();
        ControlRoomAlert::factory()->low()->fromFleet()->open()->create();

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
        ControlRoomAlert::factory()->critical()->create();
        ControlRoomAlert::factory()->low()->create();

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

    public function test_support_worker_gets_limited_permissions(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.manage', false)
                ->where('can.assign', false)
                ->where('can.escalate', false)
                ->where('can.create', false)
            );
    }

    // ──────────────────────────────────────
    // Pagination
    // ──────────────────────────────────────

    public function test_pagination_page_2(): void
    {
        ControlRoomAlert::factory()->count(30)->create();

        $this->actingAs($this->admin)
            ->get('/control-room?page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.meta.current_page', 2)
                ->has('alerts.data', 5) // 30 - 25 = 5
            );
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create([
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
                'tenant_id' => 1,
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
