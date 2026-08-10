<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomReportScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $coordinator;

    protected Site $visibleSite;

    protected Site $hiddenSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->coordinator = $this->makeRoleUser('coordinator');
        $this->visibleSite = Site::factory()->create(['type' => 'house']);
        $this->hiddenSite = Site::factory()->create(['type' => 'house']);

        $this->scopeUserToSite($this->coordinator, $this->visibleSite);
    }

    public function test_reports_index_scopes_volume_to_visible_sites(): void
    {
        ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->visibleSite->id,
            'triggered_at' => now()->subDay(),
        ]);

        ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->hiddenSite->id,
            'triggered_at' => now()->subDay(),
        ]);

        $this->actingAs($this->coordinator)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/reports')
                ->where('volume.total', 1)
                ->where('volume.open', 1)
            );
    }

    public function test_reports_export_only_includes_visible_site_alerts(): void
    {
        $visibleAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->visibleSite->id,
            'triggered_at' => now()->subDay(),
            'notes' => 'VISIBLE-SCOPE-ALERT',
        ]);

        $hiddenAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->hiddenSite->id,
            'triggered_at' => now()->subDay(),
            'notes' => 'HIDDEN-SCOPE-ALERT',
        ]);

        $response = $this->actingAs($this->coordinator)
            ->get('/control-room/reports/export?period=7d');

        $response->assertOk();

        $content = $response->getContent();
        $lines = array_filter(explode("\n", trim($content)));

        $this->assertCount(2, $lines);
        $this->assertStringContainsString((string) $visibleAlert->id, $content);
        $this->assertStringContainsString('VISIBLE-SCOPE-ALERT', $content);
        $this->assertStringNotContainsString('HIDDEN-SCOPE-ALERT', $content);
    }

    public function test_reports_reject_foreign_site_filter_for_scoped_user(): void
    {
        $this->actingAs($this->coordinator)
            ->get("/control-room/reports?site_id={$this->hiddenSite->id}")
            ->assertForbidden();
    }

    public function test_reports_route_allows_reports_only_permission_without_control_room_view_any(): void
    {
        $reportViewer = User::factory()->create([
            'approved_at' => now(),
        ]);

        $permission = Permission::query()->where('key', 'controlRoom.reports.view')->firstOrFail();
        $reportViewer->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        $this->scopeUserToSite($reportViewer, $this->visibleSite);

        $this->actingAs($reportViewer)
            ->get('/control-room/reports')
            ->assertOk();
    }

    public function test_manage_any_permissions_do_not_bypass_control_room_report_scope(): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', ['shifts.manageAny', 'timesheets.manageAny'])
            ->pluck('id', 'key');

        $this->coordinator->permissionOverrides()->syncWithoutDetaching([
            $permissionMap['shifts.manageAny'] => ['allowed' => true],
            $permissionMap['timesheets.manageAny'] => ['allowed' => true],
        ]);

        ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->visibleSite->id,
            'triggered_at' => now()->subDay(),
        ]);

        ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->hiddenSite->id,
            'triggered_at' => now()->subDay(),
        ]);

        $this->actingAs($this->coordinator)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/reports')
                ->where('volume.total', 1)
                ->where('volume.open', 1)
            );
    }

    public function test_report_site_scope_uses_alert_site_before_client_site(): void
    {
        $visibleClient = Client::factory()->create([
            'site_id' => $this->visibleSite->id,
        ]);
        $hiddenClient = Client::factory()->create([
            'site_id' => $this->hiddenSite->id,
        ]);

        ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->hiddenSite->id,
            'client_id' => $visibleClient->id,
            'triggered_at' => now()->subDay(),
        ]);
        ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->visibleSite->id,
            'client_id' => $hiddenClient->id,
            'triggered_at' => now()->subDay(),
        ]);
        ControlRoomAlert::factory()->open()->create([
            'site_id' => null,
            'client_id' => $visibleClient->id,
            'triggered_at' => now()->subDay(),
        ]);

        $this->actingAs($this->coordinator)
            ->get('/control-room/reports/alerts?period=7d')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('open', 2);
    }

    public function test_playbook_performance_is_limited_to_the_report_viewers_accessible_sites(): void
    {
        $playbook = Playbook::query()->create([
            'name' => 'Site-scoped response',
            'code' => 'site-scoped-response',
            'category' => Playbook::CATEGORY_SAFETY,
            'is_active' => true,
        ]);
        $visibleAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->visibleSite->id,
            'triggered_at' => now()->subDay(),
        ]);
        $foreignAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->hiddenSite->id,
            'triggered_at' => now()->subDay(),
        ]);

        PlaybookRun::query()->create([
            'playbook_id' => $playbook->id,
            'alert_id' => $visibleAlert->id,
            'status' => PlaybookRun::STATUS_COMPLETED,
            'started_at' => now()->subHours(3),
            'completed_at' => now()->subHours(2),
            'created_at' => now()->subDay(),
        ]);
        PlaybookRun::query()->create([
            'playbook_id' => $playbook->id,
            'alert_id' => $foreignAlert->id,
            'status' => PlaybookRun::STATUS_IN_PROGRESS,
            'started_at' => now()->subHours(3),
            'created_at' => now()->subDay(),
        ]);

        $this->actingAs($this->coordinator)
            ->get('/control-room/reports?period=7d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('playbooks.total_runs', 1)
                ->where('playbooks.completed', 1)
                ->where('playbooks.in_progress', 0)
                ->where('playbooks.by_playbook.0.total_runs', 1)
            );
    }

    public function test_application_wide_report_site_filter_does_not_let_client_site_override_alert_site(): void
    {
        $reportsPermission = Permission::query()->where('key', 'reports.viewAny')->firstOrFail();
        $this->coordinator->permissionOverrides()->attach($reportsPermission->id, ['allowed' => true]);
        $visibleClient = Client::factory()->create([
            'site_id' => $this->visibleSite->id,
        ]);

        ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->hiddenSite->id,
            'client_id' => $visibleClient->id,
            'triggered_at' => now()->subDay(),
            'notes' => 'EXPLICIT-HIDDEN-SITE',
        ]);
        ControlRoomAlert::factory()->open()->create([
            'site_id' => null,
            'client_id' => $visibleClient->id,
            'triggered_at' => now()->subDay(),
            'notes' => 'CLIENT-SITE-FALLBACK',
        ]);

        $response = $this->actingAs($this->coordinator)
            ->get("/control-room/reports/export?period=7d&site_id={$this->visibleSite->id}")
            ->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('CLIENT-SITE-FALLBACK', $content);
        $this->assertStringNotContainsString('EXPLICIT-HIDDEN-SITE', $content);
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
        $profile = HrEmployeeProfile::query()->where('user_id', $user->id)->first()
            ?? HrEmployeeProfile::factory()->make(['user_id' => $user->id]);

        $profile->fill([
            'employee_number' => 'EMP-REPORT-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Control Room',
            'position_role' => $user->role ?: 'coordinator',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ])->save();
    }
}
