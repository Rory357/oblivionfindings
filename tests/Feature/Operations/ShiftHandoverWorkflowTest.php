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
            ->has('handovers.data', 1)
            ->where('handovers.data.0.status', ShiftHandoverService::STATUS_DRAFT)
            ->where('handovers.data.0.can_submit', true)
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
                'create_timesheet' => false,
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
                'create_timesheet' => false,
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
                'create_timesheet' => false,
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
