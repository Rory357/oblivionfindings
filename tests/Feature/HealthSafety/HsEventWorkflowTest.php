<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Investigation + corrective-action workflow exposed over HTTP (E-Gap 3). The
 * controllers are thin: every guard lives in the (already tested) services, so
 * these tests confirm the transitions are reachable, the gates surface as
 * flash.error, separation of duties is enforced, the auto-advances fire, and
 * the routes are gated.
 */
class HsEventWorkflowTest extends TestCase
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

        return $user;
    }

    public function test_investigation_lifecycle_advances_event_to_corrective_action(): void
    {
        $officer = $this->hsOfficer();
        $lead = User::factory()->create();
        $event = HsEvent::factory()->high()->create();

        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/investigations", [
                'methodology' => '5_whys',
                'lead_investigator_id' => $lead->id,
            ])->assertSessionHas('success');

        $inv = HsInvestigation::where('hs_event_id', $event->id)->firstOrFail();
        $this->assertEquals(HsInvestigation::STATUS_IN_PROGRESS, $inv->status);
        $this->assertEquals(HsEvent::STATUS_INVESTIGATING, $event->fresh()->status);

        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/investigations/{$inv->id}/findings", [
                'root_causes' => [['description' => 'Missing guard rail']],
                'recommendations' => [['description' => 'Install a grab rail', 'priority' => 'high']],
                'findings_summary' => 'Fall caused by a missing rail.',
            ])->assertSessionHas('success');
        $this->assertEquals(HsInvestigation::STATUS_FINDINGS_RECORDED, $inv->fresh()->status);

        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/investigations/{$inv->id}/submit")
            ->assertSessionHas('success');
        $this->assertEquals(HsInvestigation::STATUS_UNDER_REVIEW, $inv->fresh()->status);

        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/investigations/{$inv->id}/complete")
            ->assertSessionHas('success');
        $this->assertEquals(HsInvestigation::STATUS_COMPLETED, $inv->fresh()->status);
        $this->assertEquals(HsEvent::STATUS_CORRECTIVE_ACTION, $event->fresh()->status);
    }

    public function test_forbidden_investigation_transition_surfaces_gate_error(): void
    {
        $officer = $this->hsOfficer();
        $lead = User::factory()->create();
        $event = HsEvent::factory()->high()->create();

        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/investigations", [
                'methodology' => 'icam',
                'lead_investigator_id' => $lead->id,
            ]);
        $inv = HsInvestigation::where('hs_event_id', $event->id)->firstOrFail();

        // Skip findings → submit (in_progress → under_review) is not an allowed transition.
        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/investigations/{$inv->id}/submit")
            ->assertSessionHas('error');
        $this->assertEquals(HsInvestigation::STATUS_IN_PROGRESS, $inv->fresh()->status);
    }

    public function test_corrective_action_lifecycle_enforces_separation_of_duties_and_auto_advances(): void
    {
        $officer = $this->hsOfficer();   // completes
        $verifier = $this->hsOfficer();  // a different manager, verifies
        $event = HsEvent::factory()->create();

        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/corrective-actions", [
                'title' => 'Install a grab rail',
                'priority' => 'high',
            ])->assertSessionHas('success');
        $action = HsCorrectiveAction::where('hs_event_id', $event->id)->firstOrFail();
        $this->assertEquals(HsCorrectiveAction::STATUS_OPEN, $action->status);
        $this->assertEquals(HsEvent::STATUS_CORRECTIVE_ACTION, $event->fresh()->status);

        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/corrective-actions/{$action->id}/start")
            ->assertSessionHas('success');
        $this->assertEquals(HsCorrectiveAction::STATUS_IN_PROGRESS, $action->fresh()->status);

        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/corrective-actions/{$action->id}/complete", [
                'completion_notes' => 'Rail installed and inspected.',
            ])->assertSessionHas('success');
        $this->assertEquals(HsCorrectiveAction::STATUS_COMPLETED, $action->fresh()->status);

        // Same person cannot verify their own completion.
        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/corrective-actions/{$action->id}/verify", [
                'effectiveness_confirmed' => true,
            ])->assertSessionHas('error');
        $this->assertEquals(HsCorrectiveAction::STATUS_COMPLETED, $action->fresh()->status);

        // A different manager can.
        $this->actingAs($verifier)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/corrective-actions/{$action->id}/verify", [
                'effectiveness_confirmed' => true,
            ])->assertSessionHas('success');
        $this->assertEquals(HsCorrectiveAction::STATUS_VERIFIED, $action->fresh()->status);

        // Closing the last open action auto-advances the event to monitoring.
        $this->actingAs($verifier)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/corrective-actions/{$action->id}/close")
            ->assertSessionHas('success');
        $this->assertEquals(HsCorrectiveAction::STATUS_CLOSED, $action->fresh()->status);
        $this->assertEquals(HsEvent::STATUS_MONITORING, $event->fresh()->status);
    }

    public function test_seed_corrective_action_from_recommendation(): void
    {
        $officer = $this->hsOfficer();
        $lead = User::factory()->create();
        $event = HsEvent::factory()->high()->create();

        // Build a completed investigation with one recommendation.
        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/investigations", ['methodology' => '5_whys', 'lead_investigator_id' => $lead->id]);
        $inv = HsInvestigation::where('hs_event_id', $event->id)->firstOrFail();
        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/investigations/{$inv->id}/findings", [
                'root_causes' => [['description' => 'Missing rail']],
                'recommendations' => [['description' => 'Install a grab rail', 'priority' => 'high']],
            ]);
        $this->actingAs($officer)->from('/health-safety/events')->post("/health-safety/events/{$event->id}/investigations/{$inv->id}/submit");
        $this->actingAs($officer)->from('/health-safety/events')->post("/health-safety/events/{$event->id}/investigations/{$inv->id}/complete");

        // Seed an action from recommendation 0.
        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/investigations/{$inv->id}/seed-action", ['recommendation_index' => 0])
            ->assertSessionHas('success');

        $action = HsCorrectiveAction::where('hs_investigation_id', $inv->id)->where('recommendation_index', 0)->first();
        $this->assertNotNull($action);
        $this->assertEquals('Install a grab rail', $action->title);

        // Re-seeding the same recommendation is blocked.
        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/investigations/{$inv->id}/seed-action", ['recommendation_index' => 0])
            ->assertSessionHas('error');
        $this->assertEquals(1, HsCorrectiveAction::where('hs_investigation_id', $inv->id)->count());
    }

    public function test_workflow_routes_require_hazards_manage(): void
    {
        $event = HsEvent::factory()->create();
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->post("/health-safety/events/{$event->id}/corrective-actions", [
                'title' => 'No permission',
                'priority' => 'low',
            ])->assertForbidden();
    }
}
