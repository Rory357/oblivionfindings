<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->seed(\Database\Seeders\RbacSeeder::class);

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

        foreach ([$this->outgoingStaff, $this->incomingStaff, $this->otherStaff] as $staffUser) {
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

    public function test_reassigned_incoming_shift_blocks_previous_staff_and_allows_current_assignee_to_acknowledge(): void
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
            ->assertForbidden();

        $this->actingAs($this->otherStaff)
            ->patch("/operations/handovers/{$handover->id}/acknowledge")
            ->assertRedirect()
            ->assertSessionHas('success', 'Handover acknowledged.');

        $this->assertDatabaseHas('shift_handovers', [
            'id' => $handover->id,
            'status' => ShiftHandoverService::STATUS_ACKNOWLEDGED,
            'incoming_staff_id' => $this->otherStaff->id,
            'acknowledged_by' => $this->otherStaff->id,
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

        $createResponse->assertForbidden();

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

        $acknowledgeResponse->assertForbidden();
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
        $this->makeScheduledIncomingShift($this->incomingStaff, $outgoingShift);

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

    public function test_shift_completion_can_record_handover_waiver_with_timeline_and_audit(): void
    {
        $shift = $this->makeInProgressShift($this->outgoingStaff);
        $incomingShift = $this->makeScheduledIncomingShift($this->incomingStaff, $shift);

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
        $this->makeScheduledIncomingShift($this->incomingStaff, $outgoingShift);

        $handover = ShiftHandover::factory()->draft()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->outgoingStaff->id,
            'handover_notes' => 'Original note',
        ]);

        $response = $this->actingAs($this->outgoingStaff)
            ->put("/operations/handovers/{$handover->id}", [
                'handover_notes' => 'Updated narrative after review.',
                'client_mood' => 'Bright',
                'follow_up_items_text' => 'Send art photo to family portal',
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

    public function test_edit_lock_blocks_staff_after_window_but_allows_manager(): void
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

        // Past the 7-day window, the outgoing worker is locked out…
        $this->actingAs($this->outgoingStaff)
            ->put("/operations/handovers/{$handover->id}", [
                'handover_notes' => 'Late staff edit should be blocked.',
            ])
            ->assertForbidden();

        // …but a manager can still correct it.
        $this->actingAs($this->manager)
            ->put("/operations/handovers/{$handover->id}", [
                'handover_notes' => 'Manager correction after the window.',
            ])
            ->assertRedirect();

        $handover->refresh();
        $this->assertSame('Manager correction after the window.', $handover->handover_notes);
        $this->assertSame(ShiftHandoverService::STATUS_SUBMITTED, $handover->status);
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
