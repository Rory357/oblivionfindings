<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Shifts\Lifecycle\Data\CompleteShiftData;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleSource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\ShiftHandoverService;
use App\Services\ShiftTimelineService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftHandoverWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $outgoingStaff;

    protected User $incomingStaff;

    protected User $otherStaff;

    protected Site $site;

    protected ServiceContext $serviceContext;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->manager = $this->makeUser('admin');
        $this->outgoingStaff = $this->makeUser('support_worker', ['shifts.update', 'shifts.viewAssigned']);
        $this->incomingStaff = $this->makeUser('support_worker', ['shifts.update', 'shifts.viewAssigned']);
        $this->otherStaff = $this->makeUser('support_worker', ['shifts.update', 'shifts.viewAssigned']);

        $this->site = Site::factory()->create(['name' => 'Tui House']);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Residential Support',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);

        foreach ([$this->manager, $this->outgoingStaff, $this->incomingStaff, $this->otherStaff] as $staffUser) {
            HrEmployeeProfile::query()->create([
                'tenant_id' => 1,
                'user_id' => $staffUser->id,
                'employee_number' => 'EMP-HO-'.$staffUser->id,
                'work_email' => $staffUser->email,
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $this->site->id,
                'secondary_site_ids' => [],
            ]);
        }
    }

    public function test_outgoing_staff_can_create_draft_and_submit_handover(): void
    {
        $outgoingShift = $this->makeInProgressShift($this->outgoingStaff);
        $incomingShift = $this->makeScheduledIncomingShift($this->incomingStaff, $outgoingShift);

        $createResponse = $this->actingAs($this->outgoingStaff)
            ->post("/operations/shifts/{$outgoingShift->id}/handover", [
                'incoming_shift_id' => $incomingShift->id,
                'handover_notes' => 'Client settled. Medication prompt still due before bed.',
                'client_mood' => 'settled',
                'follow_up_items' => [
                    ['label' => 'Confirm evening medication prompt'],
                ],
                'submit' => false,
            ]);

        $createResponse->assertRedirect();
        $createResponse->assertSessionHas('success', 'Handover draft saved.');

        $handover = ShiftHandover::query()->latest('id')->firstOrFail();

        $this->assertDatabaseHas('shift_handovers', [
            'id' => $handover->id,
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift->id,
            'outgoing_staff_id' => $this->outgoingStaff->id,
            'incoming_staff_id' => $this->incomingStaff->id,
            'status' => ShiftHandoverService::STATUS_DRAFT,
        ]);

        $submitResponse = $this->actingAs($this->outgoingStaff)
            ->patch("/operations/handovers/{$handover->id}/submit");

        $submitResponse->assertRedirect();
        $submitResponse->assertSessionHas('success', 'Handover submitted.');

        $this->assertDatabaseHas('shift_handovers', [
            'id' => $handover->id,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'submitted_by' => $this->outgoingStaff->id,
        ]);

        $this->assertDatabaseHas('timeline_events', [
            'type' => ShiftTimelineService::HANDOVER_CREATED_EVENT_TYPE,
            'source_type' => ShiftHandover::class,
            'source_id' => $handover->id,
            'shift_id' => $outgoingShift->id,
        ]);
        $this->assertDatabaseHas('timeline_events', [
            'type' => ShiftTimelineService::HANDOVER_SUBMITTED_EVENT_TYPE,
            'source_type' => ShiftHandover::class,
            'source_id' => $handover->id,
            'shift_id' => $outgoingShift->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shift.handover.created',
            'auditable_id' => $handover->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shift.handover.submitted',
            'auditable_id' => $handover->id,
        ]);
    }

    public function test_seeded_support_worker_can_update_and_submit_only_their_owned_clock_out_draft(): void
    {
        $frontline = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $supportRole = Role::query()->where('name', 'support_worker')->firstOrFail();
        $frontline->roles()->attach($supportRole);
        $deniedPermissions = Permission::query()
            ->whereIn('key', ['handovers.create', 'shifts.update', 'shifts.manageAny'])
            ->pluck('id');
        $frontline->permissionOverrides()->syncWithoutDetaching(
            $deniedPermissions->mapWithKeys(fn ($id) => [(int) $id => ['allowed' => false]])->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $frontline->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
        ]);
        $frontline->unsetRelation('roles')->unsetRelation('permissionOverrides');

        $this->assertTrue($frontline->canDo('shifts.viewAssigned'));
        $this->assertFalse($frontline->canDo('handovers.create'));
        $this->assertFalse($frontline->canDo('shifts.update'));
        $this->assertFalse($frontline->canDo('shifts.manageAny'));

        $outgoingShift = $this->makeInProgressShift($frontline);
        $incomingShift = $this->makeScheduledIncomingShift($this->incomingStaff, $outgoingShift);
        $draft = ShiftHandover::factory()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => null,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $frontline->id,
            'incoming_staff_id' => null,
            'handover_notes' => 'Draft captured during clock-out.',
            'status' => ShiftHandoverService::STATUS_DRAFT,
            'version' => 2,
        ]);

        $this->actingAs($frontline)
            ->put("/operations/handovers/{$draft->id}", [
                'incoming_shift_id' => $incomingShift->id,
                'handover_notes' => 'Reviewed draft with the exact incoming Shift.',
                'version' => 2,
                'submit' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Handover updated.');

        $this->actingAs($frontline)
            ->patch("/operations/handovers/{$draft->id}/submit")
            ->assertRedirect()
            ->assertSessionHas('success', 'Handover submitted.');

        $this->assertDatabaseHas('shift_handovers', [
            'id' => $draft->id,
            'outgoing_staff_id' => $frontline->id,
            'incoming_shift_id' => $incomingShift->id,
            'incoming_staff_id' => $this->incomingStaff->id,
            'handover_notes' => 'Reviewed draft with the exact incoming Shift.',
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
        ]);
    }

    public function test_same_site_unrelated_handover_ids_match_missing_ids_across_show_update_and_submit(): void
    {
        $outgoingShift = $this->makeInProgressShift($this->outgoingStaff);
        $incomingShift = $this->makeScheduledIncomingShift($this->otherStaff, $outgoingShift);
        $draft = ShiftHandover::factory()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->outgoingStaff->id,
            'incoming_staff_id' => $this->otherStaff->id,
            'handover_notes' => 'This direct object is unrelated to the actor.',
            'status' => ShiftHandoverService::STATUS_DRAFT,
            'version' => 1,
        ]);
        $missingId = 999999999;

        foreach ([$draft->id, $missingId] as $id) {
            $this->actingAs($this->incomingStaff)
                ->get("/operations/handovers/{$id}")
                ->assertNotFound();
            $this->actingAs($this->incomingStaff)
                ->put("/operations/handovers/{$id}", [
                    'handover_notes' => 'Attempted unrelated change.',
                    'version' => 1,
                ])
                ->assertNotFound();
            $this->actingAs($this->incomingStaff)
                ->patch("/operations/handovers/{$id}/submit")
                ->assertNotFound();
        }

        $this->assertDatabaseHas('shift_handovers', [
            'id' => $draft->id,
            'handover_notes' => 'This direct object is unrelated to the actor.',
            'status' => ShiftHandoverService::STATUS_DRAFT,
            'version' => 1,
        ]);
    }

    public function test_incoming_staff_can_acknowledge_submitted_handover(): void
    {
        $outgoingShift = $this->makeInProgressShift($this->outgoingStaff);
        $incomingShift = $this->makeScheduledIncomingShift($this->incomingStaff, $outgoingShift);

        $handover = ShiftHandover::factory()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->outgoingStaff->id,
            'incoming_staff_id' => $this->incomingStaff->id,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'submitted_at' => now()->subMinutes(10),
            'submitted_by' => $this->outgoingStaff->id,
            'acknowledged_at' => null,
            'acknowledged_by' => null,
        ]);

        $response = $this->actingAs($this->incomingStaff)
            ->patch("/operations/handovers/{$handover->id}/acknowledge");

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Handover acknowledged.');

        $this->assertDatabaseHas('shift_handovers', [
            'id' => $handover->id,
            'status' => ShiftHandoverService::STATUS_ACKNOWLEDGED,
            'acknowledged_by' => $this->incomingStaff->id,
        ]);
        $this->assertDatabaseHas('timeline_events', [
            'type' => ShiftTimelineService::HANDOVER_ACKNOWLEDGED_EVENT_TYPE,
            'source_type' => ShiftHandover::class,
            'source_id' => $handover->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shift.handover.acknowledged',
            'auditable_id' => $handover->id,
        ]);
    }

    public function test_reassigned_incoming_shift_preserves_submitted_evidence_and_rebinds_acknowledgement_to_current_worker(): void
    {
        $outgoingShift = $this->makeInProgressShift($this->outgoingStaff);
        $incomingShift = $this->makeScheduledIncomingShift($this->incomingStaff, $outgoingShift);

        $handover = ShiftHandover::factory()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->outgoingStaff->id,
            'incoming_staff_id' => $this->incomingStaff->id,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'submitted_at' => now()->subMinutes(10),
            'submitted_by' => $this->outgoingStaff->id,
        ]);

        $incomingShift->update(['user_id' => $this->otherStaff->id]);

        $this->actingAs($this->incomingStaff)
            ->patch("/operations/handovers/{$handover->id}/acknowledge")
            ->assertNotFound();

        $this->actingAs($this->otherStaff)
            ->get('/operations/handovers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('handovers', 1)
                ->where('handovers.0.id', $handover->id)
                ->where('handovers.0.incoming_staff.id', $this->otherStaff->id)
                ->where('handovers.0.submitted_incoming_staff.id', $this->incomingStaff->id)
                ->where('handovers.0.current_incoming_staff.id', $this->otherStaff->id)
                ->where('handovers.0.can_acknowledge', true));

        $this->actingAs($this->otherStaff)
            ->patch("/operations/handovers/{$handover->id}/acknowledge")
            ->assertRedirect()
            ->assertSessionHas('success', 'Handover acknowledged.');

        $this->assertDatabaseHas('shift_handovers', [
            'id' => $handover->id,
            'status' => ShiftHandoverService::STATUS_ACKNOWLEDGED,
            'incoming_staff_id' => $this->incomingStaff->id,
            'acknowledged_by' => $this->otherStaff->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shift.handover.incomingAssignment.rebound',
            'auditable_type' => ShiftHandover::class,
            'auditable_id' => $handover->id,
        ]);

        $rebound = AuditLog::query()
            ->where('action', 'shift.handover.incomingAssignment.rebound')
            ->where('auditable_id', $handover->id)
            ->sole();
        $this->assertSame($this->incomingStaff->id, data_get($rebound->meta, 'submitted_incoming_staff_id'));
        $this->assertSame($this->otherStaff->id, data_get($rebound->meta, 'current_incoming_staff_id'));
    }

    public function test_reassigned_incoming_shift_acknowledgement_rolls_back_when_rebound_audit_fails(): void
    {
        $outgoingShift = $this->makeInProgressShift($this->outgoingStaff);
        $incomingShift = $this->makeScheduledIncomingShift($this->incomingStaff, $outgoingShift);
        $handover = ShiftHandover::factory()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->outgoingStaff->id,
            'incoming_staff_id' => $this->incomingStaff->id,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'submitted_at' => now()->subMinutes(10),
            'submitted_by' => $this->outgoingStaff->id,
        ]);
        $incomingShift->update(['user_id' => $this->otherStaff->id]);
        AuditLog::creating(static function (AuditLog $audit): void {
            if ($audit->action === 'shift.handover.incomingAssignment.rebound') {
                throw new \RuntimeException('Injected reassignment audit failure.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->otherStaff)
                ->patch("/operations/handovers/{$handover->id}/acknowledge");
            $this->fail('Reassignment audit failure did not escape the acknowledgement transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected reassignment audit failure.', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertDatabaseHas('shift_handovers', [
            'id' => $handover->id,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'incoming_staff_id' => $this->incomingStaff->id,
            'acknowledged_at' => null,
            'acknowledged_by' => null,
        ]);
        $this->assertDatabaseMissing('timeline_events', [
            'type' => ShiftTimelineService::HANDOVER_ACKNOWLEDGED_EVENT_TYPE,
            'source_type' => ShiftHandover::class,
            'source_id' => $handover->id,
        ]);
    }

    public function test_unrelated_staff_cannot_create_or_acknowledge_handover(): void
    {
        $outgoingShift = $this->makeInProgressShift($this->outgoingStaff);
        $incomingShift = $this->makeScheduledIncomingShift($this->incomingStaff, $outgoingShift);

        $createResponse = $this->actingAs($this->otherStaff)
            ->post("/operations/shifts/{$outgoingShift->id}/handover", [
                'handover_notes' => 'Attempted note',
            ]);

        $createResponse->assertNotFound();

        $handover = ShiftHandover::factory()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->outgoingStaff->id,
            'incoming_staff_id' => $this->incomingStaff->id,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'submitted_at' => now()->subMinutes(10),
            'submitted_by' => $this->outgoingStaff->id,
        ]);

        $acknowledgeResponse = $this->actingAs($this->otherStaff)
            ->patch("/operations/handovers/{$handover->id}/acknowledge");

        $acknowledgeResponse->assertNotFound();
    }

    public function test_manager_can_review_pending_handovers_and_submit_draft(): void
    {
        $outgoingShift = $this->makeInProgressShift($this->outgoingStaff);
        $incomingShift = $this->makeScheduledIncomingShift($this->incomingStaff, $outgoingShift);

        $handover = ShiftHandover::factory()->draft()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->outgoingStaff->id,
            'incoming_staff_id' => $this->incomingStaff->id,
        ]);

        $indexResponse = $this->actingAs($this->manager)->get('/operations/handovers');

        $indexResponse->assertOk();
        $indexResponse->assertInertia(fn (Assert $page) => $page
            ->component('operations/handovers/Index')
            ->has('handovers', 1)
            ->where('handovers.0.status', ShiftHandoverService::STATUS_DRAFT)
            ->where('handovers.0.can_submit', true)
            ->where('handovers.0.can_edit', true)
            ->has('catalogue')
            ->has('can')
        );

        $submitResponse = $this->actingAs($this->manager)
            ->patch("/operations/handovers/{$handover->id}/submit");

        $submitResponse->assertRedirect();
        $this->assertDatabaseHas('shift_handovers', [
            'id' => $handover->id,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'submitted_by' => $this->manager->id,
        ]);
    }

    public function test_shift_completion_requires_handover_or_waiver_when_valid_next_shift_exists(): void
    {
        $outgoingShift = $this->makeInProgressShift($this->outgoingStaff);
        $outgoingShift->update(['ends_at' => now()->addMinute()]);
        $incomingShift = $this->makeScheduledIncomingShift($this->incomingStaff, $outgoingShift->fresh());

        $preflight = app(ShiftHandoverService::class)->completionRequirement(
            $outgoingShift->fresh(),
            now(),
        );
        $this->assertTrue($preflight['requires_handover']);
        $this->assertSame($incomingShift->id, $preflight['matched_shift']?->id);

        $response = $this->actingAs($this->outgoingStaff)
            ->patch("/operations/shifts/{$outgoingShift->id}/complete", [
                'final_note_body' => 'Shift summary is complete.',
            ]);

        $response->assertSessionHasErrors(['handover_waiver_reason']);
        $this->assertDatabaseHas('shifts', [
            'id' => $outgoingShift->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_shift_completion_does_not_require_handover_when_no_valid_next_shift_exists(): void
    {
        $shift = $this->makeInProgressShift($this->outgoingStaff);

        $response = $this->actingAs($this->outgoingStaff)
            ->patch("/operations/shifts/{$shift->id}/complete", [
                'final_note_body' => 'Shift completed without a follow-on shift.',
            ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'status' => 'completed',
        ]);
    }

    public function test_shift_completion_fails_closed_on_ambiguous_incoming_shifts_at_the_proposed_actual_end(): void
    {
        $outgoingShift = $this->makeInProgressShift($this->outgoingStaff);
        $proposedActualEnd = now();
        $outgoingShift->update(['ends_at' => $proposedActualEnd->copy()->addHours(8)]);
        foreach ([$this->incomingStaff, $this->otherStaff] as $incomingWorker) {
            Shift::factory()->create([
                'client_id' => $this->client->id,
                'site_id' => $this->site->id,
                'service_context_id' => $this->serviceContext->id,
                'user_id' => $incomingWorker->id,
                'starts_at' => $proposedActualEnd->copy()->addMinutes(15),
                'ends_at' => $proposedActualEnd->copy()->addHours(4),
                'status' => 'scheduled',
                'created_by' => $this->manager->id,
            ]);
        }

        $preflight = app(ShiftHandoverService::class)->completionRequirement(
            $outgoingShift->fresh(),
            $proposedActualEnd,
        );
        $this->assertTrue($preflight['requires_handover']);
        $this->assertTrue($preflight['ambiguous']);
        $this->assertNull($preflight['matched_shift']);
        $this->assertCount(2, $preflight['candidate_ids']);

        try {
            app(ShiftLifecycleService::class)->complete(
                $outgoingShift,
                $this->outgoingStaff,
                new CompleteShiftData(
                    actualEndsAt: $proposedActualEnd,
                    finalNoteBody: 'The proposed finish has two possible incoming shifts.',
                ),
            );
            $this->fail('Ambiguous incoming Shifts allowed completion without a governed waiver.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('handover_waiver_reason', $exception->errors());
        }

        $this->assertDatabaseHas('shifts', [
            'id' => $outgoingShift->id,
            'status' => 'in_progress',
            'actual_ends_at' => null,
            'handover_waiver_reason' => null,
            'handover_waived_at' => null,
            'handover_waived_by' => null,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'shift.handover.waived',
            'auditable_type' => Shift::class,
            'auditable_id' => $outgoingShift->id,
        ]);
    }

    public function test_clock_out_completion_defers_without_transition_or_waiver_until_the_exact_handover_is_submitted(): void
    {
        $outgoingShift = $this->makeInProgressShift($this->outgoingStaff);
        $proposedActualEnd = now();
        $outgoingShift->update(['ends_at' => $proposedActualEnd]);
        $incomingShift = $this->makeScheduledIncomingShift($this->incomingStaff, $outgoingShift->fresh());
        $draft = ShiftHandover::factory()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => null,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->outgoingStaff->id,
            'incoming_staff_id' => null,
            'status' => ShiftHandoverService::STATUS_DRAFT,
            'submitted_at' => null,
            'submitted_by' => null,
        ]);

        $result = app(ShiftLifecycleService::class)->complete(
            $outgoingShift,
            $this->outgoingStaff,
            new CompleteShiftData(
                actualEndsAt: $proposedActualEnd,
                source: ShiftLifecycleSource::ClockOut,
                createSummaryNote: false,
                syncDraftTimesheet: false,
                deferCompletionUntilHandoverSubmitted: true,
            ),
        );

        $this->assertSame('in_progress', $result->status);
        $this->assertSame(
            $incomingShift->id,
            app(ShiftHandoverService::class)
                ->completionRequirement($outgoingShift->fresh(), $proposedActualEnd)['matched_shift']?->id,
        );
        $this->assertDatabaseHas('shift_handovers', [
            'id' => $draft->id,
            'status' => ShiftHandoverService::STATUS_DRAFT,
            'incoming_shift_id' => null,
            'submitted_at' => null,
        ]);
        $this->assertDatabaseHas('shifts', [
            'id' => $outgoingShift->id,
            'status' => 'in_progress',
            'actual_ends_at' => null,
            'handover_waiver_reason' => null,
            'handover_waived_at' => null,
            'handover_waived_by' => null,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'shift.handover.waived',
            'auditable_type' => Shift::class,
            'auditable_id' => $outgoingShift->id,
        ]);
    }

    public function test_shift_completion_can_record_handover_waiver_with_timeline_and_audit(): void
    {
        $shift = $this->makeInProgressShift($this->outgoingStaff);
        $shift->update(['ends_at' => now()->addMinute()]);
        $incomingShift = $this->makeScheduledIncomingShift($this->incomingStaff, $shift->fresh());

        $preflight = app(ShiftHandoverService::class)->completionRequirement(
            $shift->fresh(),
            now(),
        );
        $this->assertTrue($preflight['requires_handover']);
        $this->assertSame($incomingShift->id, $preflight['matched_shift']?->id);

        $response = $this->actingAs($this->outgoingStaff)
            ->patch("/operations/shifts/{$shift->id}/complete", [
                'final_note_body' => 'Shift summary with waiver.',
                'handover_waiver_reason' => 'Incoming shift was delayed and manager was briefed verbally.',
            ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'status' => 'completed',
            'handover_waived_by' => $this->outgoingStaff->id,
            'handover_waiver_reason' => 'Incoming shift was delayed and manager was briefed verbally.',
        ]);
        $this->assertDatabaseHas('timeline_events', [
            'type' => ShiftTimelineService::HANDOVER_WAIVED_EVENT_TYPE,
            'source_type' => Shift::class,
            'source_id' => $shift->id,
            'shift_id' => $shift->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shift.handover.waived',
            'auditable_id' => $shift->id,
        ]);

        $waiverEvent = TimelineEvent::query()
            ->where('type', ShiftTimelineService::HANDOVER_WAIVED_EVENT_TYPE)
            ->where('source_id', $shift->id)
            ->firstOrFail();

        $this->assertSame($incomingShift->id, $waiverEvent->meta['matched_incoming_shift_id'] ?? null);
    }

    public function test_outgoing_staff_can_create_handover_via_index_endpoint(): void
    {
        $outgoingShift = $this->makeInProgressShift($this->outgoingStaff);
        $incomingShift = $this->makeScheduledIncomingShift($this->incomingStaff, $outgoingShift);

        $response = $this->actingAs($this->outgoingStaff)
            ->post('/operations/handovers', [
                'shift_id' => $outgoingShift->id,
                'incoming_shift_id' => $incomingShift->id,
                'handover_notes' => 'Wizard-created handover with structured lists.',
                'client_mood' => 'Settled',
                'medications_due_text' => "Quetiapine 25mg — due 20:00\nParacetamol 1g — given",
                'tasks_pending_text' => 'Restock bathroom consumables',
                'submit' => true,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Handover submitted.');

        $handover = ShiftHandover::query()->latest('id')->firstOrFail();
        $this->assertSame(ShiftHandoverService::STATUS_SUBMITTED, $handover->status);
        $this->assertSame($outgoingShift->id, (int) $handover->outgoing_shift_id);
        // The newline-delimited *_text fields are parsed into {label} items.
        $this->assertCount(2, $handover->medications_due);
        $this->assertSame('Quetiapine 25mg — due 20:00', $handover->medications_due[0]['label']);
        $this->assertCount(1, $handover->tasks_pending);
    }

    public function test_outgoing_staff_can_update_draft_handover(): void
    {
        $outgoingShift = $this->makeInProgressShift($this->outgoingStaff);
        $incomingShift = $this->makeScheduledIncomingShift($this->incomingStaff, $outgoingShift);

        $handover = ShiftHandover::factory()->draft()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->outgoingStaff->id,
            'incoming_staff_id' => $this->incomingStaff->id,
            'handover_notes' => 'Original note',
            'version' => 1,
        ]);

        $response = $this->actingAs($this->outgoingStaff)
            ->put("/operations/handovers/{$handover->id}", [
                'handover_notes' => 'Updated narrative after review.',
                'client_mood' => 'Bright',
                'follow_up_items_text' => 'Send art photo to family portal',
                'version' => $handover->version,
                'submit' => false,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Handover updated.');

        $handover->refresh();
        $this->assertSame('Updated narrative after review.', $handover->handover_notes);
        $this->assertSame('Bright', $handover->client_mood);
        $this->assertSame(ShiftHandoverService::STATUS_DRAFT, $handover->status);
        $this->assertSame('Send art photo to family portal', $handover->follow_up_items[0]['label']);
    }

    public function test_submitted_handover_and_its_timeline_snapshot_are_immutable(): void
    {
        $outgoingShift = $this->makeInProgressShift($this->outgoingStaff);
        $incomingShift = $this->makeScheduledIncomingShift($this->incomingStaff, $outgoingShift);

        // Create + submit through the real flow so the lifecycle timeline events
        // exist with the original mood/notes.
        $this->actingAs($this->outgoingStaff)
            ->post('/operations/handovers', [
                'shift_id' => $outgoingShift->id,
                'incoming_shift_id' => $incomingShift->id,
                'handover_notes' => 'Original notes before the edit.',
                'client_mood' => 'settled',
                'submit' => true,
            ])
            ->assertRedirect();

        $handover = ShiftHandover::query()->latest('id')->firstOrFail();
        $this->assertSame(ShiftHandoverService::STATUS_SUBMITTED, $handover->status);

        $submittedEvent = TimelineEvent::query()
            ->where('type', ShiftTimelineService::HANDOVER_SUBMITTED_EVENT_TYPE)
            ->where('source_type', ShiftHandover::class)
            ->where('source_id', $handover->id)
            ->firstOrFail();
        $this->assertStringContainsString('Original notes before the edit.', (string) $submittedEvent->body);

        // Submitted clinical evidence cannot be edited in place, even by its
        // author. The durable row and both timeline snapshots stay unchanged.
        $this->actingAs($this->outgoingStaff)
            ->put("/operations/handovers/{$handover->id}", [
                'handover_notes' => 'Revised notes after the edit.',
                'client_mood' => 'bright',
                'version' => $handover->version,
                'submit' => false,
            ])
            ->assertForbidden();

        $handover->refresh();
        $this->assertSame('Original notes before the edit.', $handover->handover_notes);
        $this->assertSame('settled', $handover->client_mood);
        $this->assertSame(1, TimelineEvent::query()
            ->where('type', ShiftTimelineService::HANDOVER_SUBMITTED_EVENT_TYPE)
            ->where('source_id', $handover->id)
            ->count());

        $refreshedSubmitted = $submittedEvent->fresh();
        $this->assertStringContainsString('Original notes before the edit.', (string) $refreshedSubmitted->body);
        $this->assertStringNotContainsString('Revised notes after the edit.', (string) $refreshedSubmitted->body);

        $createdEvent = TimelineEvent::query()
            ->where('type', ShiftTimelineService::HANDOVER_CREATED_EVENT_TYPE)
            ->where('source_id', $handover->id)
            ->firstOrFail();
        $this->assertStringContainsString('Original notes before the edit.', (string) $createdEvent->body);
        $this->assertStringNotContainsString('Revised notes after the edit.', (string) $createdEvent->body);
    }

    public function test_submitted_handover_is_immutable_for_staff_and_manager(): void
    {
        $outgoingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->outgoingStaff->id,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDays(10)->addHours(8),
            'actual_starts_at' => now()->subDays(10),
            'status' => 'completed',
            'started_by' => $this->outgoingStaff->id,
            'created_by' => $this->manager->id,
        ]);

        $handover = ShiftHandover::factory()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => null,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->outgoingStaff->id,
            'incoming_staff_id' => $this->incomingStaff->id,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'submitted_at' => now()->subDays(10),
            'submitted_by' => $this->outgoingStaff->id,
        ]);

        // Submitted evidence is terminally immutable for both the outgoing
        // worker and a manager; correction needs a separately governed flow.
        $this->actingAs($this->outgoingStaff)
            ->put("/operations/handovers/{$handover->id}", [
                'handover_notes' => 'Late staff edit should be blocked.',
            ])
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->put("/operations/handovers/{$handover->id}", [
                'handover_notes' => 'Manager correction after the window.',
            ])
            ->assertForbidden();

        $handover->refresh();
        $this->assertNotSame('Manager correction after the window.', $handover->handover_notes);
        $this->assertSame(ShiftHandoverService::STATUS_SUBMITTED, $handover->status);
    }

    public function test_legacy_unbound_submitted_handover_cannot_be_acknowledged(): void
    {
        $outgoingShift = $this->makeInProgressShift($this->outgoingStaff);
        $handover = ShiftHandover::factory()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => null,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->outgoingStaff->id,
            'incoming_staff_id' => $this->incomingStaff->id,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'submitted_at' => now()->subMinutes(10),
            'submitted_by' => $this->outgoingStaff->id,
        ]);

        $this->actingAs($this->incomingStaff)
            ->patch("/operations/handovers/{$handover->id}/acknowledge")
            ->assertNotFound();

        $this->assertDatabaseHas('shift_handovers', [
            'id' => $handover->id,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'acknowledged_at' => null,
            'acknowledged_by' => null,
        ]);
    }

    public function test_index_filters_by_outgoing_shift_week_not_created_at(): void
    {
        // Shift happened two weeks ago, but the handover row is created "now".
        $shiftDay = now()->copy()->subWeeks(2)->startOfWeek(Carbon::MONDAY)->addDays(2);
        $outgoingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->outgoingStaff->id,
            'starts_at' => $shiftDay->copy()->setTime(10, 0),
            'ends_at' => $shiftDay->copy()->setTime(18, 0),
            'actual_starts_at' => $shiftDay->copy()->setTime(10, 0),
            'status' => 'completed',
            'started_by' => $this->outgoingStaff->id,
            'created_by' => $this->manager->id,
        ]);
        $handover = ShiftHandover::factory()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => null,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->outgoingStaff->id,
            'incoming_staff_id' => null,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by' => $this->outgoingStaff->id,
        ]);

        // Current week excludes it (the shift is two weeks ago)…
        $this->actingAs($this->manager)
            ->get('/operations/handovers')
            ->assertInertia(fn (Assert $page) => $page->has('handovers', 0));

        // …but the shift's own week includes it.
        $weekParam = $shiftDay->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $this->actingAs($this->manager)
            ->get('/operations/handovers?week='.$weekParam)
            ->assertInertia(fn (Assert $page) => $page
                ->has('handovers', 1)
                ->where('handovers.0.id', $handover->id));
    }

    protected function makeUser(string $roleName, array $extraPermissions = []): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);

        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $user->roles()->attach($role);

        if ($extraPermissions !== []) {
            $permissionIds = Permission::query()
                ->whereIn('key', $extraPermissions)
                ->pluck('id')
                ->all();

            $role->permissions()->syncWithoutDetaching($permissionIds);
        }

        return $user;
    }

    protected function makeInProgressShift(User $staff): Shift
    {
        return Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => now()->subHours(4),
            'ends_at' => now()->subMinutes(15),
            'actual_starts_at' => now()->subHours(4),
            'status' => 'in_progress',
            'started_by' => $staff->id,
            'created_by' => $this->manager->id,
        ]);
    }

    protected function makeScheduledIncomingShift(User $staff, Shift $outgoingShift): Shift
    {
        return Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => ($outgoingShift->ends_at ?? now())->copy()->addMinutes(15),
            'ends_at' => ($outgoingShift->ends_at ?? now())->copy()->addHours(4),
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
        ]);
    }
}
