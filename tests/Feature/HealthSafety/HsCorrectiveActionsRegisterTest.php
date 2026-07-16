<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * /health-safety/corrective-actions — sibling register for corrective actions,
 * cross-linked back into the H&S event governance modal.
 */
class HsCorrectiveActionsRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    protected function hsOfficer(): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        if ($role = Role::where('name', 'health_safety_officer')->first()) {
            $user->roles()->attach($role);
        }
        HrEmployeeProfile::factory()->create([
            'tenant_id' => $user->organization_id,
            'user_id' => $user->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    public function test_register_grants_manage_to_roles_holding_hazards_manage(): void
    {
        // Regression: can.manage was computed with Gate ->can('hazards.manage')
        // (no such Gate ability exists) so it was false for EVERYONE and the
        // whole corrective-action lifecycle (Start/Complete/Verify/Close) was
        // invisible in the register menu and the event dialog. It must come
        // from the RBAC helper canDo().
        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/corrective-actions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', true));
    }

    public function test_corrective_actions_register_renders_sibling_governance_payload(): void
    {
        $site = Site::factory()->create(['name' => 'Rata House']);
        $event = HsEvent::factory()->high()->create([
            'site_id' => $site->id,
            'status' => HsEvent::STATUS_CORRECTIVE_ACTION,
        ]);

        HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id,
            'title' => 'Replace failed evacuation light',
            'priority' => HsCorrectiveAction::PRIORITY_HIGH,
            'status' => HsCorrectiveAction::STATUS_OPEN,
            'due_date' => now()->addDays(2),
        ]);
        HsCorrectiveAction::factory()->completed()->create([
            'hs_event_id' => $event->id,
            'priority' => HsCorrectiveAction::PRIORITY_CRITICAL,
        ]);
        HsCorrectiveAction::factory()->closed()->create();

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/corrective-actions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/corrective-actions/index')
                ->has('actions.data', 3)
                ->has('tabCounts.all')
                ->where('tabCounts.awaiting_verification', 1)
                ->has('hero.live.open')
                ->has('hero.attention.overdue')
                ->has('sites')
                ->has('can.manage')
                ->where('filters.tab', 'all')
                ->where('actions.data.0.event.id', $event->id)
                ->where('actions.data.0.event.url', "/health-safety/events?event={$event->id}")
                ->where('actions.data.0.event.site_name', 'Rata House')
            );
    }

    public function test_corrective_actions_register_can_open_event_detail_over_list(): void
    {
        $event = HsEvent::factory()->high()->create([
            'status' => HsEvent::STATUS_CORRECTIVE_ACTION,
        ]);
        HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id]);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/corrective-actions?event='.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.id', $event->id)
                ->where('detail.reference_number', $event->reference_number)
                ->has('detail.corrective_actions', 1)
            );
    }

    public function test_corrective_actions_register_reflects_parent_event_monitoring_status(): void
    {
        $event = HsEvent::factory()->high()->create([
            'status' => HsEvent::STATUS_MONITORING,
        ]);
        $action = HsCorrectiveAction::factory()->closed()->create([
            'hs_event_id' => $event->id,
            'title' => 'Confirm monitoring checks are scheduled',
        ]);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/corrective-actions?tab=closed')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('actions.data', 1)
                ->where('actions.data.0.id', $action->id)
                ->where('actions.data.0.event.status', HsEvent::STATUS_MONITORING)
                ->where('actions.data.0.event.monitoring', true)
            );
    }

    public function test_register_exposes_recommendation_owner_due_date_and_transferred_task_source(): void
    {
        $owner = $this->hsOfficer();
        $alert = ControlRoomAlert::factory()->triaging()->create();
        $event = HsEvent::factory()->create(['control_room_alert_id' => $alert->id]);
        $investigation = HsInvestigation::factory()->completed()->create([
            'hs_event_id' => $event->id,
        ]);
        $task = AlertTask::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Replace the unsafe bathroom rail',
            'created_by_user_id' => $owner->id,
            'status' => AlertTask::STATUS_TRANSFERRED,
            'priority' => HsCorrectiveAction::PRIORITY_HIGH,
            'transferred_at' => now(),
            'transferred_by_user_id' => $owner->id,
        ]);
        $action = HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id,
            'hs_investigation_id' => $investigation->id,
            'recommendation_index' => 0,
            'source_control_room_task_id' => $task->id,
            'assigned_to_user_id' => $owner->id,
            'due_date' => '2026-08-31',
        ]);
        $task->update(['transferred_to_hs_corrective_action_id' => $action->id]);

        $this->actingAs($owner)
            ->get('/health-safety/corrective-actions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('actions.data.0.id', $action->id)
                ->where('actions.data.0.assigned_to_name', $owner->name)
                ->where('actions.data.0.due_date', '2026-08-31')
                ->where(
                    'actions.data.0.recommendation',
                    'Implement wet floor signage procedure',
                )
                ->where('actions.data.0.source.type', 'control_room_task')
                ->where('actions.data.0.source.title', $task->title)
            );
    }

    public function test_register_exposes_new_responsibility_reason(): void
    {
        $owner = $this->hsOfficer();
        $investigation = HsInvestigation::factory()->completed()->create();
        $action = HsCorrectiveAction::factory()->create([
            'hs_event_id' => $investigation->hs_event_id,
            'hs_investigation_id' => $investigation->id,
            'recommendation_index' => 1,
            'assigned_to_user_id' => $owner->id,
            'due_date' => '2026-09-15',
            'description' => 'No current operational task covers the training work.',
        ]);

        $this->actingAs($owner)
            ->get('/health-safety/corrective-actions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('actions.data.0.id', $action->id)
                ->where('actions.data.0.source.type', 'new_responsibility')
                ->where(
                    'actions.data.0.source.reason',
                    'No current operational task covers the training work.',
                )
            );
    }

    public function test_corrective_actions_register_requires_hazards_view(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->get('/health-safety/corrective-actions')
            ->assertForbidden();
    }
}
