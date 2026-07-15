<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomShiftHandoverAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $outgoingLead;

    private User $incomingLead;

    private User $otherCoordinator;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->site = Site::factory()->create(['type' => 'house']);
        $this->outgoingLead = $this->coordinatorAt($this->site);
        $this->incomingLead = $this->coordinatorAt($this->site);
        $this->otherCoordinator = $this->coordinatorAt($this->site);
    }

    public function test_prepare_requires_explicit_review_of_every_visible_critical_and_high_alert(): void
    {
        $shift = $this->activeShift();
        $critical = $this->urgentAlert('critical', 'CR-2026-1201');
        $this->urgentAlert('high', 'CR-2026-1202');
        $this->urgentAlert('medium', 'CR-2026-1203');

        $this->saveDraft($shift, [
            'incoming_lead_user_id' => $this->incomingLead->id,
            'reviewed_alert_ids' => [$critical->id],
        ]);

        $shift->refresh();

        $this->actingAs($this->outgoingLead)
            ->post("/control-room/shifts/{$shift->id}/handover", [
                'incoming_lead_user_id' => $this->incomingLead->id,
                'reviewed_alert_ids' => [$critical->id],
                'expected_version' => $shift->handover_version,
            ])
            ->assertSessionHasErrors('reviewed_alert_ids');

        $this->assertSame('active', $shift->fresh()->status);
        $this->assertSame(Shift::HANDOVER_NONE, $shift->fresh()->handover_status);
    }

    public function test_prepare_stores_a_canonical_structured_snapshot_and_leaves_outgoing_shift_active(): void
    {
        $shift = $this->activeShift();
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $critical = $this->urgentAlert('critical', 'CR-2026-1210', [
            'client_id' => $client->id,
            'assigned_to_user_id' => $this->outgoingLead->id,
            'notes' => 'Person missed a scheduled safety check.',
        ]);
        $high = $this->urgentAlert('high', 'CR-2026-1211');
        AlertTask::query()->create([
            'alert_id' => $critical->id,
            'title' => 'Confirm the person is safe',
            'status' => AlertTask::STATUS_OPEN,
            'priority' => 'critical',
            'assigned_to_user_id' => $this->incomingLead->id,
            'created_by_user_id' => $this->outgoingLead->id,
            'due_at' => now()->addMinutes(15),
        ]);

        $this->saveDraft($shift, [
            'handover_notes' => 'Incoming lead to continue the welfare response.',
            'incoming_shift_name' => 'Night response desk',
            'incoming_lead_user_id' => $this->incomingLead->id,
            'incoming_team_members' => [$this->incomingLead->id],
            'reviewed_alert_ids' => [$critical->id, $high->id],
            'priority_alert_ids' => [$critical->id],
        ]);

        $shift->refresh();

        $this->actingAs($this->outgoingLead)
            ->post("/control-room/shifts/{$shift->id}/handover", [
                'incoming_lead_user_id' => $this->incomingLead->id,
                'reviewed_alert_ids' => [$critical->id, $high->id],
                'expected_version' => $shift->handover_version,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/control-room/shifts/{$shift->id}/handover");

        $prepared = $shift->fresh();

        $this->assertSame('active', $prepared->status);
        $this->assertSame(Shift::HANDOVER_PREPARED, $prepared->handover_status);
        $this->assertSame($this->incomingLead->id, $prepared->handed_over_to_user_id);
        $this->assertNotNull($prepared->handover_prepared_at);
        $this->assertNull($prepared->handover_accepted_at);
        $this->assertDatabaseCount('control_room_shifts', 1);

        $snapshot = $prepared->handover_snapshot;
        $this->assertSame('Night response desk', data_get($snapshot, 'incoming_shift.name'));
        $this->assertSame($this->incomingLead->name, data_get($snapshot, 'incoming_shift.lead.name'));
        $this->assertSame([$critical->id], data_get($snapshot, 'priority_alert_ids'));
        $this->assertEqualsCanonicalizing([$critical->id, $high->id], data_get($snapshot, 'reviewed_alert_ids'));

        $criticalSnapshot = collect(data_get($snapshot, 'alerts'))->firstWhere('id', $critical->id);
        $this->assertSame('CR-2026-1210', data_get($criticalSnapshot, 'reference_number'));
        $this->assertSame('Person missed a scheduled safety check.', data_get($criticalSnapshot, 'summary'));
        $this->assertSame($client->id, data_get($criticalSnapshot, 'person.id'));
        $this->assertSame($this->site->id, data_get($criticalSnapshot, 'site.id'));
        $this->assertSame($this->outgoingLead->name, data_get($criticalSnapshot, 'assignee.name'));
        $this->assertArrayHasKey('sla', $criticalSnapshot);
        $this->assertArrayHasKey('journey', $criticalSnapshot);
        $this->assertSame('/control-room/alerts/'.$critical->id, data_get($criticalSnapshot, 'next_action.href'));
        $this->assertSame('Confirm the person is safe', data_get($criticalSnapshot, 'tasks.0.title'));
    }

    public function test_only_selected_incoming_lead_can_accept_and_acceptance_switches_shifts_once(): void
    {
        $shift = $this->activeShift();
        $critical = $this->urgentAlert('critical', 'CR-2026-1220');

        $this->saveDraft($shift, [
            'incoming_shift_name' => 'Incoming control desk',
            'incoming_lead_user_id' => $this->incomingLead->id,
            'incoming_team_members' => [$this->incomingLead->id],
            'reviewed_alert_ids' => [$critical->id],
            'priority_alert_ids' => [$critical->id],
        ]);
        $shift->refresh();

        $this->actingAs($this->outgoingLead)
            ->post("/control-room/shifts/{$shift->id}/handover", [
                'incoming_lead_user_id' => $this->incomingLead->id,
                'reviewed_alert_ids' => [$critical->id],
                'expected_version' => $shift->handover_version,
            ])
            ->assertSessionHasNoErrors();

        $prepared = $shift->fresh();

        $this->actingAs($this->otherCoordinator)
            ->post("/control-room/shifts/{$shift->id}/accept-handover", [
                'expected_version' => $prepared->handover_version,
            ])
            ->assertForbidden();

        $this->assertSame('active', $shift->fresh()->status);
        $this->assertDatabaseCount('control_room_shifts', 1);

        $this->actingAs($this->incomingLead)
            ->post("/control-room/shifts/{$shift->id}/accept-handover", [
                'expected_version' => $prepared->handover_version,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('control-room.shifts.index'));

        $accepted = $shift->fresh();
        $acceptedAt = $accepted->handover_accepted_at?->toISOString();

        $this->assertSame('completed', $accepted->status);
        $this->assertSame(Shift::HANDOVER_ACCEPTED, $accepted->handover_status);
        $this->assertNotNull($acceptedAt);
        $this->assertNotNull($accepted->handed_over_at);
        $this->assertSame(1, Shift::query()->active()->count());
        $this->assertDatabaseHas('control_room_shifts', [
            'name' => 'Incoming control desk',
            'status' => 'active',
            'shift_lead_user_id' => $this->incomingLead->id,
        ]);

        $this->actingAs($this->incomingLead)
            ->post("/control-room/shifts/{$shift->id}/accept-handover", [
                'expected_version' => $accepted->handover_version,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Shift::query()->active()->count());
        $this->assertDatabaseCount('control_room_shifts', 2);
        $this->assertSame($acceptedAt, $shift->fresh()->handover_accepted_at?->toISOString());
    }

    public function test_stale_draft_version_does_not_overwrite_a_newer_handover(): void
    {
        $shift = $this->activeShift();

        $this->saveDraft($shift, ['handover_notes' => 'Newest saved notes.']);

        $this->actingAs($this->outgoingLead)
            ->from("/control-room/shifts/{$shift->id}/handover")
            ->patch("/control-room/shifts/{$shift->id}/handover/draft", [
                'handover_notes' => 'Stale notes must not win.',
                'expected_version' => 1,
            ])
            ->assertSessionHasErrors('handover_version');

        $this->assertSame('Newest saved notes.', data_get($shift->fresh()->handover_snapshot, 'draft.handover_notes'));
    }

    public function test_saved_draft_resumes_with_its_latest_version(): void
    {
        $shift = $this->activeShift();

        $this->saveDraft($shift, [
            'handover_notes' => 'Resume this context on the next visit.',
            'incoming_shift_name' => 'Overnight desk',
            'incoming_lead_user_id' => $this->incomingLead->id,
        ]);
        $saved = $shift->fresh();

        $this->actingAs($this->outgoingLead)
            ->get("/control-room/shifts/{$shift->id}/handover")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shift.handover_version', $saved->handover_version)
                ->where('shift.draft.handover_notes', 'Resume this context on the next visit.')
                ->where('shift.draft.incoming_shift_name', 'Overnight desk')
                ->where('shift.draft.incoming_lead_user_id', $this->incomingLead->id)
            );
    }

    public function test_starting_another_shift_cannot_bypass_a_prepared_handover(): void
    {
        $shift = $this->activeShift();
        $this->saveDraft($shift, [
            'incoming_lead_user_id' => $this->incomingLead->id,
        ]);
        $shift->refresh();

        $this->actingAs($this->outgoingLead)
            ->post("/control-room/shifts/{$shift->id}/handover", [
                'incoming_lead_user_id' => $this->incomingLead->id,
                'reviewed_alert_ids' => [],
                'expected_version' => $shift->handover_version,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->outgoingLead)
            ->post('/control-room/shifts', [
                'name' => 'Bypass attempt',
                'shift_lead_user_id' => $this->otherCoordinator->id,
                'team_members' => [],
            ])
            ->assertSessionHasErrors('shift');

        $this->assertSame('active', $shift->fresh()->status);
        $this->assertSame(Shift::HANDOVER_PREPARED, $shift->fresh()->handover_status);
        $this->assertDatabaseCount('control_room_shifts', 1);
    }

    public function test_legacy_acknowledge_never_reactivates_a_completed_shift_or_creates_two_active_shifts(): void
    {
        $completed = Shift::query()->create([
            'name' => 'Already completed',
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->subHour(),
            'status' => 'completed',
            'shift_lead_user_id' => $this->outgoingLead->id,
        ]);
        $this->activeShift('Current shift', $this->incomingLead);

        $this->actingAs($this->outgoingLead)
            ->post("/control-room/shifts/{$completed->id}/acknowledge-handover", [
                'expected_version' => $completed->handover_version,
            ])
            ->assertForbidden();

        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertSame(1, Shift::query()->active()->count());
    }

    /** @param array<string, mixed> $overrides */
    private function saveDraft(Shift $shift, array $overrides = []): void
    {
        $payload = array_replace([
            'handover_notes' => '',
            'incoming_shift_name' => '',
            'incoming_lead_user_id' => null,
            'incoming_team_members' => [],
            'reviewed_alert_ids' => [],
            'priority_alert_ids' => [],
            'expected_version' => $shift->fresh()->handover_version,
        ], $overrides);

        $this->actingAs($this->outgoingLead)
            ->from("/control-room/shifts/{$shift->id}/handover")
            ->patch("/control-room/shifts/{$shift->id}/handover/draft", $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect("/control-room/shifts/{$shift->id}/handover");
    }

    private function activeShift(string $name = 'Outgoing control desk', ?User $lead = null): Shift
    {
        return Shift::query()->create([
            'name' => $name,
            'starts_at' => now()->subHours(8),
            'status' => 'active',
            'shift_lead_user_id' => ($lead ?? $this->outgoingLead)->id,
            'team_members' => [$this->outgoingLead->id],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function urgentAlert(string $severity, string $reference, array $overrides = []): ControlRoomAlert
    {
        return ControlRoomAlert::factory()->open()->create(array_replace([
            'site_id' => $this->site->id,
            'severity' => $severity,
            'reference_number' => $reference,
        ], $overrides));
    }

    private function coordinatorAt(Site $site): User
    {
        $user = User::factory()->create([
            'role' => 'coordinator',
            'approved_at' => now(),
        ]);
        $user->roles()->syncWithoutDetaching([
            Role::query()->where('name', 'coordinator')->value('id'),
        ]);

        HrEmployeeProfile::query()->create([
            'user_id' => $user->id,
            'tenant_id' => 1,
            'employee_number' => 'EMP-HANDOVER-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Control Room Coordinator',
            'position_role' => 'coordinator',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }
}
