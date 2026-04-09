<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HsCorrectiveActionTest extends TestCase
{
    use RefreshDatabase;

    private HsCorrectiveActionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(HsCorrectiveActionService::class);
    }

    // ──────────────────────────────────────────────────────
    // Creation from investigation recommendations
    // ──────────────────────────────────────────────────────

    public function test_creates_action_from_recommendation(): void
    {
        $investigation = HsInvestigation::factory()->withFindings()->create();

        $action = $this->service->createFromRecommendation($investigation, 0);

        $this->assertDatabaseHas('hs_corrective_actions', [
            'hs_event_id' => $investigation->hs_event_id,
            'hs_investigation_id' => $investigation->id,
            'recommendation_index' => 0,
            'status' => HsCorrectiveAction::STATUS_OPEN,
        ]);

        $this->assertStringStartsWith('CA-', $action->reference_number);
        // Title from recommendation description
        $this->assertNotEmpty($action->title);
    }

    public function test_blocks_duplicate_action_for_same_recommendation(): void
    {
        $investigation = HsInvestigation::factory()->withFindings()->create();

        $this->service->createFromRecommendation($investigation, 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists for recommendation');

        $this->service->createFromRecommendation($investigation, 0);
    }

    public function test_allows_actions_for_different_recommendations(): void
    {
        $investigation = HsInvestigation::factory()->withFindings()->create();

        $action0 = $this->service->createFromRecommendation($investigation, 0);
        $action1 = $this->service->createFromRecommendation($investigation, 1);

        $this->assertNotEquals($action0->id, $action1->id);
        $this->assertDatabaseCount('hs_corrective_actions', 2);
    }

    public function test_rejects_invalid_recommendation_index(): void
    {
        $investigation = HsInvestigation::factory()->withFindings()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->service->createFromRecommendation($investigation, 99);
    }

    public function test_bulk_creates_from_all_recommendations(): void
    {
        $investigation = HsInvestigation::factory()->withFindings()->create();

        $actions = $this->service->createFromAllRecommendations($investigation);

        // withFindings factory has 2 recommendations
        $this->assertCount(2, $actions);
    }

    public function test_bulk_create_skips_existing(): void
    {
        $investigation = HsInvestigation::factory()->withFindings()->create();

        $this->service->createFromRecommendation($investigation, 0);

        // Calling bulk create should only create for index 1
        $actions = $this->service->createFromAllRecommendations($investigation);

        $this->assertCount(1, $actions);
        $this->assertEquals(1, $actions[0]->recommendation_index);
    }

    // ──────────────────────────────────────────────────────
    // Standalone creation
    // ──────────────────────────────────────────────────────

    public function test_creates_standalone_action(): void
    {
        $event = HsEvent::factory()->high()->create();

        $action = $this->service->createStandalone($event, [
            'title' => 'Install wet floor signage protocol',
            'priority' => 'high',
        ]);

        $this->assertDatabaseHas('hs_corrective_actions', [
            'hs_event_id' => $event->id,
            'hs_investigation_id' => null,
            'title' => 'Install wet floor signage protocol',
        ]);
    }

    public function test_standalone_action_moves_event_to_corrective_action_status(): void
    {
        $event = HsEvent::factory()->high()->create(['status' => HsEvent::STATUS_OPEN]);

        $this->service->createStandalone($event, [
            'title' => 'Fix hazard',
        ]);

        $event->refresh();
        $this->assertEquals(HsEvent::STATUS_CORRECTIVE_ACTION, $event->status);
    }

    public function test_cannot_create_action_on_closed_event(): void
    {
        $event = HsEvent::factory()->closed()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('closed');

        $this->service->createStandalone($event, [
            'title' => 'Too late',
        ]);
    }

    // ──────────────────────────────────────────────────────
    // Lifecycle transitions
    // ──────────────────────────────────────────────────────

    public function test_can_start_action(): void
    {
        $action = HsCorrectiveAction::factory()->assigned()->create();
        $assignee = User::factory()->create();

        $result = $this->service->start($action, $assignee->id);

        $this->assertEquals(HsCorrectiveAction::STATUS_IN_PROGRESS, $result->status);
    }

    public function test_can_complete_action_with_notes(): void
    {
        $action = HsCorrectiveAction::factory()->inProgress()->create();

        $result = $this->service->complete($action, [
            'completion_notes' => 'Signage installed in all corridors.',
        ]);

        $this->assertEquals(HsCorrectiveAction::STATUS_COMPLETED, $result->status);
        $this->assertNotNull($result->completed_at);
    }

    public function test_cannot_complete_without_evidence(): void
    {
        $action = HsCorrectiveAction::factory()->inProgress()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('completion notes or evidence');

        $this->service->complete($action, []);
    }

    public function test_can_return_for_rework(): void
    {
        $action = HsCorrectiveAction::factory()->completed()->create();

        $result = $this->service->returnForRework($action, 'Signage not in all areas.');

        $this->assertEquals(HsCorrectiveAction::STATUS_IN_PROGRESS, $result->status);
        $this->assertNull($result->completed_at);
    }

    public function test_can_verify_action(): void
    {
        $action = HsCorrectiveAction::factory()->completed()->create();
        $verifier = User::factory()->create();

        $result = $this->service->verify($action, [
            'verified_by_user_id' => $verifier->id,
            'effectiveness_confirmed' => true,
            'verification_notes' => 'All signage confirmed in place.',
        ]);

        $this->assertEquals(HsCorrectiveAction::STATUS_VERIFIED, $result->status);
        $this->assertTrue($result->effectiveness_confirmed);
        $this->assertNotNull($result->verified_at);
    }

    public function test_verifier_cannot_be_completer(): void
    {
        $completer = User::factory()->create();
        $action = HsCorrectiveAction::factory()->completed()->create([
            'completed_by_user_id' => $completer->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('separation of duties');

        $this->service->verify($action, [
            'verified_by_user_id' => $completer->id,
            'effectiveness_confirmed' => true,
        ]);
    }

    public function test_verification_requires_effectiveness_assessment(): void
    {
        $action = HsCorrectiveAction::factory()->completed()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('effectiveness');

        $this->service->verify($action, [
            'verified_by_user_id' => User::factory()->create()->id,
        ]);
    }

    public function test_can_close_verified_action(): void
    {
        $action = HsCorrectiveAction::factory()->verified()->create();

        $result = $this->service->close($action);

        $this->assertEquals(HsCorrectiveAction::STATUS_CLOSED, $result->status);
        $this->assertNotNull($result->closed_at);
    }

    // ──────────────────────────────────────────────────────
    // Invalid transitions
    // ──────────────────────────────────────────────────────

    public function test_cannot_verify_open_action(): void
    {
        $action = HsCorrectiveAction::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->verify($action, [
            'verified_by_user_id' => User::factory()->create()->id,
            'effectiveness_confirmed' => true,
        ]);
    }

    public function test_cannot_close_uncompleted_action(): void
    {
        $action = HsCorrectiveAction::factory()->inProgress()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->close($action);
    }

    // ──────────────────────────────────────────────────────
    // HsEvent status sync
    // ──────────────────────────────────────────────────────

    public function test_all_actions_resolved_moves_event_to_monitoring(): void
    {
        $event = HsEvent::factory()->high()->create([
            'status' => HsEvent::STATUS_CORRECTIVE_ACTION,
        ]);

        $action = HsCorrectiveAction::factory()->verified()->create([
            'hs_event_id' => $event->id,
        ]);

        $this->service->close($action);

        $event->refresh();
        $this->assertEquals(HsEvent::STATUS_MONITORING, $event->status);
    }

    public function test_event_stays_in_corrective_action_if_open_actions_remain(): void
    {
        $event = HsEvent::factory()->high()->create([
            'status' => HsEvent::STATUS_CORRECTIVE_ACTION,
        ]);

        // Two actions: close one, leave the other open
        $action1 = HsCorrectiveAction::factory()->verified()->create(['hs_event_id' => $event->id]);
        HsCorrectiveAction::factory()->inProgress()->create(['hs_event_id' => $event->id]);

        $this->service->close($action1);

        $event->refresh();
        // Should NOT advance because action2 is still open
        $this->assertEquals(HsEvent::STATUS_CORRECTIVE_ACTION, $event->status);
    }

    // ──────────────────────────────────────────────────────
    // Model helpers
    // ──────────────────────────────────────────────────────

    public function test_overdue_detection(): void
    {
        $action = HsCorrectiveAction::factory()->overdue()->create();

        $this->assertTrue($action->isOverdue());
    }

    public function test_closed_action_is_not_overdue(): void
    {
        $action = HsCorrectiveAction::factory()->closed()->create([
            'due_date' => now()->subDays(5),
        ]);

        $this->assertFalse($action->isOverdue());
    }

    public function test_overdue_scope(): void
    {
        HsCorrectiveAction::factory()->overdue()->create();
        HsCorrectiveAction::factory()->inProgress()->create(['due_date' => now()->addDays(10)]);
        HsCorrectiveAction::factory()->closed()->create(['due_date' => now()->subDays(5)]);

        $this->assertCount(1, HsCorrectiveAction::overdue()->get());
    }

    public function test_awaiting_verification_scope(): void
    {
        HsCorrectiveAction::factory()->completed()->create();
        HsCorrectiveAction::factory()->inProgress()->create();

        $this->assertCount(1, HsCorrectiveAction::awaitingVerification()->get());
    }

    // ──────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────

    public function test_event_has_corrective_actions_relationship(): void
    {
        $event = HsEvent::factory()->high()->create();
        HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id]);
        HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id]);

        $this->assertCount(2, $event->correctiveActions);
    }

    public function test_investigation_has_corrective_actions_relationship(): void
    {
        $investigation = HsInvestigation::factory()->withFindings()->create();
        $this->service->createFromAllRecommendations($investigation);

        $this->assertCount(2, $investigation->fresh()->correctiveActions);
    }

    public function test_event_all_corrective_actions_resolved_helper(): void
    {
        $event = HsEvent::factory()->high()->create();

        // No actions = vacuously true
        $this->assertTrue($event->allCorrectiveActionsResolved());

        // Add open action
        HsCorrectiveAction::factory()->inProgress()->create(['hs_event_id' => $event->id]);
        $event->refresh();
        $this->assertFalse($event->allCorrectiveActionsResolved());
    }

    public function test_reference_number_sequential(): void
    {
        $ref1 = HsCorrectiveAction::generateReferenceNumber();
        HsCorrectiveAction::factory()->create(['reference_number' => $ref1]);

        $ref2 = HsCorrectiveAction::generateReferenceNumber();

        $this->assertNotEquals($ref1, $ref2);
        $this->assertStringStartsWith('CA-' . now()->year . '-', $ref2);
    }
}
