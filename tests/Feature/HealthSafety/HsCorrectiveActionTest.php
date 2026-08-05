<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\HealthSafety\HsCorrectiveActionService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HsCorrectiveActionTest extends TestCase
{
    use RefreshDatabase;

    private HsCorrectiveActionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->service = app(HsCorrectiveActionService::class);
    }

    // ──────────────────────────────────────────────────────
    // Creation from investigation recommendations
    // ──────────────────────────────────────────────────────

    public function test_creates_action_from_recommendation(): void
    {
        [
            'investigation' => $investigation,
            'owner' => $owner,
            'actor' => $actor,
            'event' => $event,
        ] = $this->recommendationJourney();

        $action = $this->service->createFromRecommendation(
            $investigation,
            0,
            $this->newResponsibilityPayload($owner, [
                'due_date' => '2026-07-21',
            ]),
            $actor,
        );

        $this->assertDatabaseHas('hs_corrective_actions', [
            'hs_event_id' => $investigation->hs_event_id,
            'hs_investigation_id' => $investigation->id,
            'recommendation_index' => 0,
            'status' => HsCorrectiveAction::STATUS_OPEN,
            'assigned_to_user_id' => $owner->id,
            'due_date' => '2026-07-21',
        ]);
        $this->assertDatabaseHas('hs_recommendation_dispositions', [
            'hs_investigation_id' => $investigation->id,
            'recommendation_index' => 0,
            'hs_corrective_action_id' => $action->id,
            'disposition' => 'corrective_action',
            'decided_by_user_id' => $actor->id,
        ]);

        $this->assertStringStartsWith('CA-', $action->reference_number);
        $this->assertNotEmpty($action->title);
        $this->assertSame(
            'This is a new H&S responsibility because no operational task covers the recommendation.',
            $action->description,
        );

        $hazardsView = Permission::query()->where('key', 'hazards.view')->firstOrFail();
        $actor->permissionOverrides()->syncWithoutDetaching([
            $hazardsView->id => ['allowed' => true],
        ]);
        $this->actingAs($actor)
            ->get("/health-safety/events/{$event->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.corrective_actions.0.due_date', '2026-07-21')
            );
    }

    public function test_exact_retry_returns_the_same_action_and_transfers_the_same_task_once(): void
    {
        Notification::fake();
        [
            'investigation' => $investigation,
            'owner' => $owner,
            'actor' => $actor,
            'task' => $task,
        ] = $this->recommendationJourney();
        $payload = $this->transferPayload($owner, $task);

        $first = $this->service->createFromRecommendation($investigation, 0, $payload, $actor);
        $retry = $this->service->createFromRecommendation($investigation->fresh(), 0, $payload, $actor);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(AlertTask::STATUS_TRANSFERRED, $task->fresh()->status);
        $this->assertSame($first->id, $task->fresh()->transferred_to_hs_corrective_action_id);
        $this->assertSame($task->id, $first->fresh()->source_control_room_task_id);
        $this->assertDatabaseCount('hs_corrective_actions', 1);
        $this->assertDatabaseCount('hs_recommendation_dispositions', 1);
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'healthSafety.correctiveAction.handedOver')
                ->where('auditable_type', $first->getMorphClass())
                ->where('auditable_id', $first->id)
                ->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'controlRoom.task.transferredToHealthSafety')
                ->where('auditable_id', $task->alert_id)
                ->count(),
        );
        Notification::assertSentToTimes($owner, AppEventNotification::class, 1);
    }

    public function test_exact_retry_survives_event_closure_and_owner_eligibility_drift(): void
    {
        [
            'investigation' => $investigation,
            'owner' => $owner,
            'actor' => $actor,
            'event' => $event,
            'task' => $task,
        ] = $this->recommendationJourney();
        $payload = $this->transferPayload($owner, $task);
        $first = $this->service->createFromRecommendation($investigation, 0, $payload, $actor);

        $event->forceFill([
            'status' => HsEvent::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => $actor->id,
            'closure_summary' => 'Closed after the original handover response was lost.',
        ])->save();
        $owner->hrEmployeeProfile()->update(['is_active' => false]);
        $hazardsManage = Permission::query()->where('key', 'hazards.manage')->firstOrFail();
        $owner->permissionOverrides()->syncWithoutDetaching([
            $hazardsManage->id => ['allowed' => false],
        ]);

        $retry = $this->service->createFromRecommendation(
            $investigation->fresh(),
            0,
            $payload,
            $actor,
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('hs_corrective_actions', 1);
        $this->assertDatabaseCount('hs_recommendation_dispositions', 1);
    }

    public function test_allows_actions_for_different_recommendations(): void
    {
        ['investigation' => $investigation, 'owner' => $owner, 'actor' => $actor] = $this->recommendationJourney();

        $action0 = $this->service->createFromRecommendation(
            $investigation,
            0,
            $this->newResponsibilityPayload($owner),
            $actor,
        );
        $action1 = $this->service->createFromRecommendation(
            $investigation,
            1,
            $this->newResponsibilityPayload($owner, [
                'due_date' => '2026-09-15',
                'priority' => HsCorrectiveAction::PRIORITY_MEDIUM,
            ]),
            $actor,
        );

        $this->assertNotEquals($action0->id, $action1->id);
        $this->assertDatabaseCount('hs_corrective_actions', 2);
    }

    public function test_rejects_invalid_recommendation_index(): void
    {
        ['investigation' => $investigation, 'owner' => $owner, 'actor' => $actor] = $this->recommendationJourney();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->service->createFromRecommendation(
            $investigation,
            99,
            $this->newResponsibilityPayload($owner),
            $actor,
        );
    }

    public function test_recommendation_creation_requires_owner_due_date_and_responsibility_choice(): void
    {
        ['investigation' => $investigation, 'owner' => $owner, 'actor' => $actor] = $this->recommendationJourney();

        foreach ([
            'assigned_to_user_id' => ['due_date' => '2026-08-31', 'responsibility_choice' => 'new_responsibility', 'new_responsibility_reason' => str_repeat('x', 20), 'priority' => 'high'],
            'due_date' => ['assigned_to_user_id' => $owner->id, 'responsibility_choice' => 'new_responsibility', 'new_responsibility_reason' => str_repeat('x', 20), 'priority' => 'high'],
            'responsibility_choice' => ['assigned_to_user_id' => $owner->id, 'due_date' => '2026-08-31', 'priority' => 'high'],
        ] as $missingField => $payload) {
            try {
                $this->service->createFromRecommendation($investigation, 0, $payload, $actor);
                $this->fail("Missing {$missingField} must be rejected.");
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotSame('', trim($exception->getMessage()));
            }
        }

        $this->assertDatabaseCount('hs_corrective_actions', 0);
    }

    public function test_recommendation_seed_request_requires_the_complete_handover_contract(): void
    {
        [
            'investigation' => $investigation,
            'actor' => $actor,
        ] = $this->recommendationJourney();

        $this->actingAs($actor)
            ->post(
                "/health-safety/events/{$investigation->hs_event_id}/investigations/{$investigation->id}/seed-action",
                ['recommendation_index' => 0],
            )
            ->assertSessionHasErrors([
                'assigned_to_user_id',
                'due_date',
                'priority',
                'responsibility_choice',
            ]);

        $this->assertDatabaseCount('hs_corrective_actions', 0);
    }

    public function test_new_responsibility_requires_a_reason(): void
    {
        ['investigation' => $investigation, 'owner' => $owner, 'actor' => $actor] = $this->recommendationJourney();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reason');

        $this->service->createFromRecommendation(
            $investigation,
            0,
            $this->newResponsibilityPayload($owner, ['new_responsibility_reason' => '']),
            $actor,
        );
    }

    public function test_rejects_ineligible_or_cross_site_owner(): void
    {
        ['investigation' => $investigation, 'actor' => $actor, 'site' => $site] = $this->recommendationJourney();
        $otherSite = Site::factory()->create();
        $ineligible = $this->siteBoundUser($otherSite, []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('eligible');

        $this->service->createFromRecommendation(
            $investigation,
            0,
            $this->newResponsibilityPayload($ineligible),
            $actor,
        );
    }

    public function test_application_admin_can_assign_an_eligible_site_bound_owner_to_a_site_less_event(): void
    {
        $actor = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $actor->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $site = Site::factory()->create();
        $owner = $this->siteBoundUser($site, ['hazards.manage']);
        $event = HsEvent::factory()->create([
            'site_id' => null,
        ]);
        $investigation = HsInvestigation::factory()->completed()->create([
            'hs_event_id' => $event->id,
        ]);

        $action = $this->service->createFromRecommendation(
            $investigation,
            0,
            $this->newResponsibilityPayload($owner),
            $actor,
        );

        $this->assertSame($owner->id, $action->assigned_to_user_id);
    }

    public function test_rejects_an_owner_with_an_inactive_hr_profile(): void
    {
        ['investigation' => $investigation, 'owner' => $owner, 'actor' => $actor] = $this->recommendationJourney();
        $owner->hrEmployeeProfile()->update(['is_active' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('eligible');

        $this->service->createFromRecommendation(
            $investigation,
            0,
            $this->newResponsibilityPayload($owner),
            $actor,
        );
    }

    public function test_rejects_source_task_from_another_alert_journey(): void
    {
        [
            'investigation' => $investigation,
            'owner' => $owner,
            'actor' => $actor,
            'site' => $site,
        ] = $this->recommendationJourney();
        $foreignAlert = ControlRoomAlert::factory()->triaging()->create(['site_id' => $site->id]);
        $foreignTask = $this->task($foreignAlert, $actor);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('journey');

        $this->service->createFromRecommendation(
            $investigation,
            0,
            $this->transferPayload($owner, $foreignTask),
            $actor,
        );
    }

    public function test_rejects_control_room_transfer_before_health_safety_acceptance(): void
    {
        [
            'investigation' => $investigation,
            'owner' => $owner,
            'actor' => $actor,
            'task' => $task,
        ] = $this->recommendationJourney(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('accept');

        $this->service->createFromRecommendation(
            $investigation,
            0,
            $this->transferPayload($owner, $task),
            $actor,
        );
    }

    public function test_bulk_creation_requires_an_explicit_assignment_for_every_recommendation(): void
    {
        ['investigation' => $investigation, 'owner' => $owner, 'actor' => $actor] = $this->recommendationJourney();

        try {
            $this->service->createFromAllRecommendations($investigation, [
                0 => $this->newResponsibilityPayload($owner),
            ], $actor);
            $this->fail('Bulk creation must reject an incomplete recommendation assignment map.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('recommendation [1]', $exception->getMessage());
        }

        $this->assertDatabaseCount('hs_corrective_actions', 0);

        $actions = $this->service->createFromAllRecommendations($investigation, [
            0 => $this->newResponsibilityPayload($owner),
            1 => $this->newResponsibilityPayload($owner, [
                'due_date' => '2026-09-15',
                'priority' => HsCorrectiveAction::PRIORITY_MEDIUM,
            ]),
        ], $actor);

        $this->assertCount(2, $actions);
    }

    // ──────────────────────────────────────────────────────
    // Standalone creation
    // ──────────────────────────────────────────────────────

    public function test_creates_standalone_action(): void
    {
        $site = Site::factory()->create();
        $actor = $this->siteBoundUser($site, ['hazards.manage']);
        $owner = $this->siteBoundUser($site, ['hazards.manage']);
        $event = HsEvent::factory()->high()->create(['site_id' => $site->id]);

        $action = $this->service->createStandalone($event, [
            'title' => 'Install wet floor signage protocol',
            'priority' => 'high',
            'assigned_to_user_id' => $owner->id,
            'due_date' => '2026-08-31',
        ], $actor);

        $this->assertDatabaseHas('hs_corrective_actions', [
            'hs_event_id' => $event->id,
            'hs_investigation_id' => null,
            'title' => 'Install wet floor signage protocol',
            'assigned_to_user_id' => $owner->id,
            'due_date' => '2026-08-31',
        ]);
    }

    public function test_standalone_creation_rejects_missing_owner_or_due_date(): void
    {
        $site = Site::factory()->create();
        $actor = $this->siteBoundUser($site, ['hazards.manage']);
        $owner = $this->siteBoundUser($site, ['hazards.manage']);
        $event = HsEvent::factory()->high()->create(['site_id' => $site->id]);

        foreach ([
            ['title' => 'Missing owner', 'priority' => 'high', 'due_date' => '2026-08-31'],
            ['title' => 'Missing due date', 'priority' => 'high', 'assigned_to_user_id' => $owner->id],
        ] as $payload) {
            try {
                $this->service->createStandalone($event, $payload, $actor);
                $this->fail('Standalone corrective action ownership must be complete.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseCount('hs_corrective_actions', 0);
    }

    public function test_standalone_request_requires_owner_and_due_date(): void
    {
        $site = Site::factory()->create();
        $actor = $this->siteBoundUser($site, ['hazards.manage']);
        $event = HsEvent::factory()->high()->create(['site_id' => $site->id]);

        $this->actingAs($actor)
            ->post("/health-safety/events/{$event->id}/corrective-actions", [
                'title' => 'Incomplete HTTP action',
                'priority' => HsCorrectiveAction::PRIORITY_HIGH,
            ])
            ->assertSessionHasErrors([
                'assigned_to_user_id',
                'due_date',
            ]);

        $this->assertDatabaseCount('hs_corrective_actions', 0);
    }

    public function test_standalone_action_moves_event_to_corrective_action_status(): void
    {
        $site = Site::factory()->create();
        $actor = $this->siteBoundUser($site, ['hazards.manage']);
        $event = HsEvent::factory()->high()->create([
            'site_id' => $site->id,
            'status' => HsEvent::STATUS_OPEN,
        ]);

        $this->service->createStandalone($event, [
            'title' => 'Fix hazard',
            'assigned_to_user_id' => $actor->id,
            'due_date' => '2026-08-31',
            'priority' => 'high',
        ], $actor);

        $event->refresh();
        $this->assertEquals(HsEvent::STATUS_CORRECTIVE_ACTION, $event->status);
    }

    public function test_cannot_create_action_on_closed_event(): void
    {
        $site = Site::factory()->create();
        $actor = $this->siteBoundUser($site, ['hazards.manage']);
        $event = HsEvent::factory()->closed()->create(['site_id' => $site->id]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('closed');

        $this->service->createStandalone($event, [
            'title' => 'Too late',
            'assigned_to_user_id' => $actor->id,
            'due_date' => '2026-08-31',
            'priority' => 'high',
        ], $actor);
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
        $completer = User::factory()->create();
        $action = HsCorrectiveAction::factory()->inProgress()->create();

        $this->actingAs($completer);
        $result = $this->service->complete($action, [
            'completion_notes' => 'Signage installed in all corridors.',
            'completed_by_user_id' => $completer->id,
        ]);

        $this->assertEquals(HsCorrectiveAction::STATUS_COMPLETED, $result->status);
        $this->assertNotNull($result->completed_at);
        $this->assertSame($completer->id, $result->completed_by_user_id);
    }

    public function test_can_complete_action_with_a_retained_attachment_instead_of_notes(): void
    {
        $action = HsCorrectiveAction::factory()->inProgress()->create();
        $action->attachments()->create([
            'uploaded_by' => User::factory()->create()->id,
            'original_name' => 'after-photo.jpg',
            'path' => "health-safety/corrective-actions/{$action->id}/after-photo.jpg",
            'disk' => 'private',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 120,
        ]);

        $result = $this->service->complete($action, []);

        $this->assertSame(HsCorrectiveAction::STATUS_COMPLETED, $result->status);
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
        $completer = User::factory()->create();
        $completedAt = now()->subHour()->startOfSecond();
        $action = HsCorrectiveAction::factory()->completed()->create([
            'completed_at' => $completedAt,
            'completed_by_user_id' => $completer->id,
            'completion_notes' => 'Installed signage in the main corridor.',
        ]);

        $result = $this->service->returnForRework($action, 'Signage not in all areas.');

        $this->assertEquals(HsCorrectiveAction::STATUS_IN_PROGRESS, $result->status);
        $this->assertSame($completedAt->toIso8601String(), $result->completed_at?->toIso8601String());
        $this->assertSame($completer->id, $result->completed_by_user_id);
        $this->assertSame('Installed signage in the main corridor.', $result->completion_notes);
        $this->assertSame('Signage not in all areas.', $result->verification_notes);
    }

    public function test_can_verify_action(): void
    {
        $action = HsCorrectiveAction::factory()->completed()->create();
        $verifier = User::factory()->create();

        $result = $this->service->verify($action, [
            'verified_by_user_id' => $verifier->id,
            'evidence_reviewed' => true,
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
            'evidence_reviewed' => true,
            'effectiveness_confirmed' => true,
        ]);
    }

    public function test_action_owner_cannot_verify_when_another_user_completed(): void
    {
        $owner = User::factory()->create();
        $completer = User::factory()->create();
        $action = HsCorrectiveAction::factory()->completed()->create([
            'assigned_to_user_id' => $owner->id,
            'completed_by_user_id' => $completer->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('action owner and completer');

        $this->service->verify($action, [
            'verified_by_user_id' => $owner->id,
            'evidence_reviewed' => true,
            'effectiveness_confirmed' => true,
        ]);
    }

    public function test_verification_requires_evidence_acknowledgement(): void
    {
        $action = HsCorrectiveAction::factory()->completed()->create();

        try {
            $this->service->verify($action, [
                'verified_by_user_id' => User::factory()->create()->id,
                'effectiveness_confirmed' => true,
            ]);
            $this->fail('Verification must require explicit evidence acknowledgement.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Review the owner submission before verifying this action.'],
                $exception->errors()['evidence_reviewed'] ?? [],
            );
        }
    }

    public function test_verification_rechecks_that_completion_evidence_is_still_retained(): void
    {
        $action = HsCorrectiveAction::factory()->completed()->create([
            'completion_notes' => null,
            'completion_evidence_paths' => null,
        ]);

        try {
            $this->service->verify($action, [
                'verified_by_user_id' => User::factory()->create()->id,
                'evidence_reviewed' => true,
                'effectiveness_confirmed' => true,
            ]);
            $this->fail('Verification must fail when no completion evidence remains.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Completion evidence is no longer available. Return the action for rework.'],
                $exception->errors()['evidence_reviewed'] ?? [],
            );
        }
    }

    public function test_verification_requires_effectiveness_assessment(): void
    {
        $action = HsCorrectiveAction::factory()->completed()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('effectiveness');

        $this->service->verify($action, [
            'verified_by_user_id' => User::factory()->create()->id,
            'evidence_reviewed' => true,
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
            'evidence_reviewed' => true,
            'effectiveness_confirmed' => true,
        ]);
    }

    public function test_stale_lifecycle_requests_recheck_the_locked_status(): void
    {
        $staleVerification = HsCorrectiveAction::factory()->completed()->create();
        DB::table('hs_corrective_actions')
            ->where('id', $staleVerification->id)
            ->update(['status' => HsCorrectiveAction::STATUS_IN_PROGRESS]);

        try {
            $this->service->verify($staleVerification, [
                'verified_by_user_id' => User::factory()->create()->id,
                'evidence_reviewed' => true,
                'effectiveness_confirmed' => true,
            ]);
            $this->fail('A stale completed model must not verify after rework.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(
                HsCorrectiveAction::STATUS_IN_PROGRESS,
                $staleVerification->fresh()->status,
            );
        }

        $staleReturn = HsCorrectiveAction::factory()->completed()->create();
        DB::table('hs_corrective_actions')
            ->where('id', $staleReturn->id)
            ->update(['status' => HsCorrectiveAction::STATUS_VERIFIED]);

        try {
            $this->service->returnForRework(
                $staleReturn,
                'Stale return must not overwrite verification.',
            );
            $this->fail('A stale completed model must not overwrite verification.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(
                HsCorrectiveAction::STATUS_VERIFIED,
                $staleReturn->fresh()->status,
            );
        }
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
        ['investigation' => $investigation, 'owner' => $owner, 'actor' => $actor] = $this->recommendationJourney();
        $this->service->createFromAllRecommendations($investigation, [
            0 => $this->newResponsibilityPayload($owner),
            1 => $this->newResponsibilityPayload($owner, [
                'due_date' => '2026-09-15',
                'priority' => HsCorrectiveAction::PRIORITY_MEDIUM,
            ]),
        ], $actor);

        $this->assertCount(2, $investigation->fresh()->correctiveActions);
    }

    public function test_event_all_corrective_actions_resolved_helper(): void
    {
        $event = HsEvent::factory()->high()->create();

        // No recommendations or actions = no corrective-action work to resolve.
        $this->assertTrue($event->allCorrectiveActionsResolved());

        HsInvestigation::factory()->completed()->create(['hs_event_id' => $event->id]);
        $event->refresh();
        $this->assertFalse($event->allCorrectiveActionsResolved());

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
        $this->assertStringStartsWith('CA-'.now()->year.'-', $ref2);
    }

    /**
     * @return array{site: Site, actor: User, owner: User, alert: ControlRoomAlert, event: HsEvent, investigation: HsInvestigation, task: AlertTask}
     */
    private function recommendationJourney(bool $accepted = true): array
    {
        $site = Site::factory()->create();
        $actor = $this->siteBoundUser($site, ['hazards.manage']);
        $owner = $this->siteBoundUser($site, ['hazards.manage']);
        $alert = ControlRoomAlert::factory()->triaging()->create(['site_id' => $site->id]);
        $eventFactory = HsEvent::factory()->state([
            'site_id' => $site->id,
            'control_room_alert_id' => $alert->id,
        ]);
        $event = $accepted
            ? $eventFactory->handoverAccepted($owner, $actor)->create()
            : $eventFactory->awaitingHandoverAcceptance($owner)->create();
        $investigation = HsInvestigation::factory()->completed()->create([
            'hs_event_id' => $event->id,
        ]);
        $task = $this->task($alert, $actor);

        return compact('site', 'actor', 'owner', 'alert', 'event', 'investigation', 'task');
    }

    private function task(ControlRoomAlert $alert, User $actor): AlertTask
    {
        return AlertTask::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Replace unsafe bathroom rail',
            'description' => 'Permanent repair with evidence required.',
            'assigned_to_user_id' => $actor->id,
            'created_by_user_id' => $actor->id,
            'status' => AlertTask::STATUS_IN_PROGRESS,
            'priority' => HsCorrectiveAction::PRIORITY_HIGH,
            'due_at' => now()->addDays(5),
        ]);
    }

    /** @return array<string, mixed> */
    private function newResponsibilityPayload(User $owner, array $overrides = []): array
    {
        return array_merge([
            'assigned_to_user_id' => $owner->id,
            'due_date' => '2026-08-31',
            'priority' => HsCorrectiveAction::PRIORITY_HIGH,
            'responsibility_choice' => 'new_responsibility',
            'new_responsibility_reason' => 'This is a new H&S responsibility because no operational task covers the recommendation.',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function transferPayload(User $owner, AlertTask $task, array $overrides = []): array
    {
        return array_merge([
            'assigned_to_user_id' => $owner->id,
            'due_date' => '2026-08-31',
            'priority' => HsCorrectiveAction::PRIORITY_HIGH,
            'responsibility_choice' => 'transfer_task',
            'source_control_room_task_id' => $task->id,
        ], $overrides);
    }

    /** @param list<string> $permissionKeys */
    private function siteBoundUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
        ]);
        $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync(
            $permissions->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }
}
