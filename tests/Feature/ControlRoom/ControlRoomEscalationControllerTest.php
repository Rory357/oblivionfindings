<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomEscalationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supportWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    public function test_index_requires_view_permission(): void
    {
        $stranger = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($stranger)
            ->get('/control-room/escalations')
            ->assertForbidden();
    }

    public function test_index_groups_alerts_by_queue(): void
    {
        $tier1 = TriageQueue::create([
            'name' => 'Tier 1',
            'code' => 't1',
            'tier' => 1,
            'is_active' => true,
        ]);

        ControlRoomAlert::factory()->open()->create(['queue_id' => $tier1->id]);
        ControlRoomAlert::factory()->open()->create(['queue_id' => $tier1->id]);

        $this->actingAs($this->admin)
            ->get('/control-room/escalations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/escalations')
                ->has('queues')
                ->has('allQueues')
            );
    }

    public function test_acknowledge_from_queue_marks_alert_acknowledged(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/escalations/{$alert->id}/acknowledge")
            ->assertRedirect();

        $alert->refresh();
        $this->assertSame('ack', $alert->status);
        $this->assertSame($this->admin->id, $alert->acknowledged_by_user_id);
    }

    public function test_acknowledge_from_queue_blocks_resolved_alert(): void
    {
        $alert = ControlRoomAlert::factory()->resolved()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/escalations/{$alert->id}/acknowledge")
            ->assertSessionHasErrors('alert');
    }

    public function test_assign_to_me_assigns_alert_to_current_user(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/escalations/{$alert->id}/assign-to-me")
            ->assertRedirect();

        $this->assertSame($this->admin->id, $alert->fresh()->assigned_to_user_id);
    }

    public function test_move_to_queue_changes_queue_assignment(): void
    {
        $tier1 = TriageQueue::create(['name' => 'Tier 1', 'code' => 't1', 'tier' => 1, 'is_active' => true]);
        $tier2 = TriageQueue::create(['name' => 'Tier 2', 'code' => 't2', 'tier' => 2, 'is_active' => true]);

        $alert = ControlRoomAlert::factory()->open()->create(['queue_id' => $tier1->id]);

        $this->actingAs($this->admin)
            ->post("/control-room/escalations/{$alert->id}/move", [
                'target_queue_id' => $tier2->id,
            ])
            ->assertRedirect();

        $this->assertSame($tier2->id, $alert->fresh()->queue_id);
    }

    public function test_bulk_escalate_promotes_alerts_to_next_queue(): void
    {
        $tier2 = TriageQueue::create(['name' => 'Tier 2', 'code' => 't2', 'tier' => 2, 'is_active' => true]);
        $tier1 = TriageQueue::create([
            'name' => 'Tier 1',
            'code' => 't1',
            'tier' => 1,
            'is_active' => true,
            'escalate_to_queue_id' => $tier2->id,
        ]);

        $alert = ControlRoomAlert::factory()->open()->create(['queue_id' => $tier1->id]);

        $this->actingAs($this->admin)
            ->post('/control-room/escalations/bulk-escalate', [
                'alert_ids' => [$alert->id],
                'reason' => 'Queue backlog — needs tier 2 eyes',
            ])
            ->assertRedirect();

        $alert->refresh();
        $this->assertSame($tier2->id, $alert->queue_id);
        $this->assertSame(1, $alert->escalation_level);
        $history = $alert->context['escalation_history'] ?? [];
        $this->assertNotEmpty($history, 'Bulk escalation must record a reason on the escalation history.');
        $this->assertSame('Queue backlog — needs tier 2 eyes', end($history)['reason']);
    }

    public function test_bulk_escalate_validates_alert_ids(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/escalations/bulk-escalate', [])
            ->assertSessionHasErrors('alert_ids');
    }

    public function test_bulk_escalate_requires_a_reason(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post('/control-room/escalations/bulk-escalate', [
                'alert_ids' => [$alert->id],
            ])
            ->assertSessionHasErrors('reason');
    }
}
