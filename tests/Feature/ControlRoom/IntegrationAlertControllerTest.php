<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->seed(\Database\Seeders\RbacSeeder::class);

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
}
