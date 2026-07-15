<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\ControlRoom\AlertController as IntegrationAlertController;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IntegrationAlertControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $teamLead;

    protected User $coordinator;

    protected User $assignee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->teamLead = $this->roleUser('team_lead');
        $this->coordinator = $this->roleUser('coordinator');
        $this->assignee = $this->roleUser('coordinator');
    }

    public function test_integration_alert_index_allows_alert_view_role_and_scopes_nested_context_site(): void
    {
        $visibleSite = Site::factory()->create(['name' => 'Visible House', 'type' => 'house']);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden House', 'type' => 'house']);

        $this->scopeUserToSite($this->teamLead, $visibleSite);

        $visibleAlert = ControlRoomAlert::factory()->create([
            'source' => 'integration_unifi',
            'site_id' => null,
            'client_id' => null,
            'context' => [
                'site' => ['id' => $visibleSite->id],
            ],
        ]);

        ControlRoomAlert::factory()->create([
            'source' => 'integration_unifi',
            'site_id' => null,
            'client_id' => null,
            'context' => [
                'site' => ['id' => $hiddenSite->id],
            ],
        ]);

        $this->actingAs($this->teamLead)
            ->get('/control-room/integration-alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/alerts/index')
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', $visibleAlert->id)
                ->where('can.assign', false)
                ->where('can.manage', false)
                ->has('staff', 0)
            );
    }

    public function test_task7_spec_followup_integration_default_and_all_lists_are_positive_actionable_worklists(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $this->scopeUserToSite($this->teamLead, $site);
        $active = ControlRoomAlert::factory()->open()->create([
            'source' => 'integration_unifi',
            'site_id' => $site->id,
        ]);
        $resolved = ControlRoomAlert::factory()->resolved()->create([
            'source' => 'integration_unifi',
            'site_id' => $site->id,
        ]);
        ControlRoomAlert::factory()->closed()->create([
            'source' => 'integration_unifi',
            'site_id' => $site->id,
        ]);
        ControlRoomAlert::factory()->create([
            'source' => 'integration_unifi',
            'site_id' => $site->id,
            'status' => ControlRoomAlert::STATUS_DISMISSED,
        ]);
        $legacy = ControlRoomAlert::factory()->open()->create([
            'source' => 'integration_unifi',
            'site_id' => $site->id,
        ]);
        DB::table('control_room_alerts')->where('id', $legacy->id)->update([
            'status' => 'legacy_unknown',
        ]);
        $onlyActive = fn ($rows): bool => collect($rows)->pluck('id')->all() === [$active->id];

        $this->actingAs($this->teamLead)
            ->get('/control-room/integration-alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('alerts.data', $onlyActive));

        $this->actingAs($this->teamLead)
            ->get('/control-room/integration-alerts?status=all')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('alerts.data', $onlyActive));

        $this->actingAs($this->teamLead)
            ->get('/control-room/integration-alerts?status=resolved')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', $resolved->id));
    }

    public function test_integration_alert_assign_blocks_foreign_site_alert_for_scoped_user(): void
    {
        $visibleSite = Site::factory()->create(['type' => 'house']);
        $hiddenSite = Site::factory()->create(['type' => 'house']);

        $this->scopeUserToSite($this->coordinator, $visibleSite);

        $hiddenAlert = ControlRoomAlert::factory()->open()->create([
            'source' => 'integration_unifi',
            'site_id' => $hiddenSite->id,
        ]);

        $this->actingAs($this->coordinator)
            ->post("/control-room/integration-alerts/{$hiddenAlert->id}/assign", [
                'user_id' => $this->assignee->id,
            ])
            ->assertForbidden();
    }

    public function test_integration_alert_actions_reject_non_integration_alerts(): void
    {
        $manualAlert = ControlRoomAlert::factory()->open()->create([
            'source' => 'manual',
        ]);

        $this->actingAs($this->coordinator)
            ->post("/control-room/integration-alerts/{$manualAlert->id}/ack", [
                'notes' => 'Should not pass',
            ])
            ->assertNotFound();
    }

    public function test_create_incident_placeholder_route_is_removed(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'integration_unifi',
        ]);

        $this->actingAs($this->coordinator)
            ->post("/control-room/integration-alerts/{$alert->id}/create-incident")
            ->assertNotFound();
    }

    public function test_residual_terminal_sla_is_omitted_from_integration_alert_status(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $this->scopeUserToSite($this->teamLead, $site);
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'integration_unifi',
            'site_id' => $site->id,
        ]);
        AlertSla::query()->create([
            'alert_id' => $alert->id,
            'ended_as' => AlertSla::ENDED_RECONCILED_NO_MATCH,
            'cycle_history' => [['ended_as' => AlertSla::ENDED_RECONCILED_NO_MATCH]],
        ]);

        $this->actingAs($this->teamLead)
            ->get('/control-room/integration-alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.data.0.id', $alert->id)
                ->where('alerts.data.0.sla_status', null)
            );
    }

    public function test_integration_alert_assign_blocks_out_of_scope_assignee_for_scoped_user(): void
    {
        $visibleSite = Site::factory()->create(['type' => 'house']);
        $hiddenSite = Site::factory()->create(['type' => 'house']);

        $this->scopeUserToSite($this->coordinator, $visibleSite);
        $this->scopeUserToSite($this->assignee, $hiddenSite);

        $visibleAlert = ControlRoomAlert::factory()->open()->create([
            'source' => 'integration_unifi',
            'site_id' => $visibleSite->id,
        ]);

        $this->actingAs($this->coordinator)
            ->post("/control-room/integration-alerts/{$visibleAlert->id}/assign", [
                'user_id' => $this->assignee->id,
            ])
            ->assertForbidden();
    }

    public function test_integration_assignment_locks_and_rechecks_a_stale_alert_status_before_writing(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $this->scopeUserToSite($this->coordinator, $site);
        $this->scopeUserToSite($this->assignee, $site);
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'integration_unifi',
            'site_id' => $site->id,
        ]);
        $staleAlert = ControlRoomAlert::query()->findOrFail($alert->id);
        ControlRoomAlert::query()->whereKey($alert->id)->update([
            'status' => ControlRoomAlert::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $this->coordinator->id,
        ]);
        $request = $this->assignmentRequest($alert);

        DB::enableQueryLog();

        try {
            app(IntegrationAlertController::class)->assign($request, $staleAlert);
            $this->fail('A stale open model must not assign an alert that has already become terminal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('alert', $exception->errors());
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }

        $this->assertTrue(
            collect($queries)->contains(
                fn (array $query): bool => str_contains(strtolower($query['query']), 'control_room_alerts')
                    && str_contains(strtolower($query['query']), 'for update'),
            ),
            'Integration assignment must lock the current alert row before rechecking it.',
        );
        $this->assertNull($alert->fresh()->assigned_to_user_id);
        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->fresh()->status);
    }

    public function test_integration_assignment_rechecks_site_access_after_locking_a_stale_alert(): void
    {
        $visibleSite = Site::factory()->create(['type' => 'house']);
        $hiddenSite = Site::factory()->create(['type' => 'house']);
        $this->scopeUserToSite($this->coordinator, $visibleSite);
        $this->scopeUserToSite($this->assignee, $visibleSite);
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'integration_unifi',
            'site_id' => $visibleSite->id,
        ]);
        $staleAlert = ControlRoomAlert::query()->findOrFail($alert->id);
        ControlRoomAlert::query()->whereKey($alert->id)->update(['site_id' => $hiddenSite->id]);

        try {
            app(IntegrationAlertController::class)->assign($this->assignmentRequest($alert), $staleAlert);
            $this->fail('A stale visible model must not bypass the locked alert site-access check.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertNull($alert->fresh()->assigned_to_user_id);
        $this->assertSame($hiddenSite->id, $alert->fresh()->site_id);
    }

    protected function roleUser(string $roleName): User
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
                'employee_number' => 'EMP-INT-'.$user->id,
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

    protected function assignmentRequest(ControlRoomAlert $alert): Request
    {
        $request = Request::create(
            "/control-room/integration-alerts/{$alert->id}/assign",
            'POST',
            ['user_id' => $this->assignee->id],
        );
        $request->setUserResolver(fn () => $this->coordinator);

        return $request;
    }
}
