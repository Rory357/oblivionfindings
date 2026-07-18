<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Factories\ControlRoomAlertFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomEscalationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supportWorker;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->site = Site::factory()->create(['tenant_id' => 1]);

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

        $this->alertFactory()->open()->create(['queue_id' => $tier1->id]);
        $this->alertFactory()->open()->create(['queue_id' => $tier1->id]);

        $this->actingAs($this->admin)
            ->get('/control-room/escalations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/escalations')
                ->where('queues.0.alert_count', 2)
                ->where('queues.0.capacity', 20)
                ->where('queues.0.utilization_percent', 10)
                ->missing('queues.0.alerts')
                ->has('worklist.data', 2)
                ->has('allQueues')
            );
    }

    public function test_index_paginates_the_primary_worklist_and_explains_over_capacity_pressure(): void
    {
        $queue = TriageQueue::create([
            'name' => 'Emergency',
            'code' => 'emergency-pressure',
            'tier' => 3,
            'is_active' => true,
        ]);

        $this->alertFactory()->open()->count(31)->create(['queue_id' => $queue->id]);

        $this->actingAs($this->admin)
            ->get('/control-room/escalations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('queues.0.alert_count', 31)
                ->where('queues.0.capacity', 20)
                ->where('queues.0.utilization_percent', 155)
                ->where('queues.0.pressure_label', 'Over capacity')
                ->where('queues.0.capacity_explanation', '20-alert operational display threshold; alerts remain paginated and no work is hidden.')
                ->has('worklist.data', 30)
                ->where('worklist.total', 31)
                ->where('summary.active_queues', 1)
                ->where('summary.total_alerts', 31)
            );
    }

    public function test_index_filters_one_shared_worklist_by_queue(): void
    {
        $tier1 = TriageQueue::create(['name' => 'Tier 1', 'code' => 'filter-t1', 'tier' => 1, 'is_active' => true]);
        $tier2 = TriageQueue::create(['name' => 'Tier 2', 'code' => 'filter-t2', 'tier' => 2, 'is_active' => true]);
        $visible = $this->alertFactory()->open()->create(['queue_id' => $tier2->id]);
        $this->alertFactory()->open()->create(['queue_id' => $tier1->id]);

        $this->actingAs($this->admin)
            ->get('/control-room/escalations?queue_id='.$tier2->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('worklist.data', 1)
                ->where('worklist.data.0.id', $visible->id)
                ->where('filters.queue_id', (string) $tier2->id)
            );
    }

    public function test_worklist_orders_breaches_then_queue_tier_then_severity_and_queue_age(): void
    {
        $tier1 = TriageQueue::create(['name' => 'Tier 1', 'code' => 'order-t1', 'tier' => 1, 'is_active' => true]);
        $tier3 = TriageQueue::create(['name' => 'Emergency', 'code' => 'order-t3', 'tier' => 3, 'is_active' => true]);
        $slaDefinition = SlaDefinition::create([
            'name' => 'Ordering SLA',
            'code' => 'ordering-sla',
            'acknowledge_target_minutes' => 5,
            'is_active' => true,
        ]);

        $tier3Older = $this->alertFactory()->open()->create([
            'queue_id' => $tier3->id,
            'severity' => 'medium',
            'triggered_at' => now()->subHours(2),
        ]);
        $tier3Newer = $this->alertFactory()->open()->create([
            'queue_id' => $tier3->id,
            'severity' => 'medium',
            'triggered_at' => now()->subHour(),
        ]);
        $tier1Critical = $this->alertFactory()->open()->create([
            'queue_id' => $tier1->id,
            'severity' => 'critical',
        ]);
        $breached = $this->alertFactory()->open()->create([
            'queue_id' => $tier1->id,
            'severity' => 'low',
        ]);
        AlertSla::create([
            'alert_id' => $breached->id,
            'sla_definition_id' => $slaDefinition->id,
            'acknowledge_deadline' => now()->subMinute(),
            'acknowledge_breached' => true,
        ]);
        AlertQueue::create(['alert_id' => $tier3Older->id, 'queue_id' => $tier3->id, 'entered_at' => now()->subHours(3)]);
        AlertQueue::create(['alert_id' => $tier3Newer->id, 'queue_id' => $tier3->id, 'entered_at' => now()->subHours(2)]);

        $this->actingAs($this->admin)
            ->get('/control-room/escalations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('worklist.data.0.id', $breached->id)
                ->where('worklist.data.1.id', $tier3Older->id)
                ->where('worklist.data.2.id', $tier3Newer->id)
                ->where('worklist.data.3.id', $tier1Critical->id)
            );
    }

    public function test_acknowledge_from_queue_marks_alert_acknowledged(): void
    {
        $alert = $this->alertFactory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/escalations/{$alert->id}/acknowledge")
            ->assertRedirect();

        $alert->refresh();
        $this->assertSame('ack', $alert->status);
        $this->assertSame($this->admin->id, $alert->acknowledged_by_user_id);
    }

    public function test_acknowledge_from_queue_blocks_resolved_alert(): void
    {
        $alert = $this->alertFactory()->resolved()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/escalations/{$alert->id}/acknowledge")
            ->assertSessionHasErrors('alert');
    }

    public function test_assign_to_me_assigns_alert_to_current_user(): void
    {
        $alert = $this->alertFactory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/escalations/{$alert->id}/assign-to-me")
            ->assertRedirect();

        $this->assertSame($this->admin->id, $alert->fresh()->assigned_to_user_id);
    }

    public function test_move_to_queue_changes_queue_assignment(): void
    {
        $tier1 = TriageQueue::create(['name' => 'Tier 1', 'code' => 't1', 'tier' => 1, 'is_active' => true]);
        $tier2 = TriageQueue::create(['name' => 'Tier 2', 'code' => 't2', 'tier' => 2, 'is_active' => true]);

        $alert = $this->alertFactory()->open()->create(['queue_id' => $tier1->id]);

        $this->actingAs($this->admin)
            ->post("/control-room/escalations/{$alert->id}/move", [
                'target_queue_id' => $tier2->id,
            ])
            ->assertRedirect();

        $this->assertSame($tier2->id, $alert->fresh()->queue_id);
    }

    public function test_move_to_queue_rejects_an_inactive_destination_queue(): void
    {
        $tier1 = TriageQueue::create(['name' => 'Tier 1', 'code' => 'move-active-current', 'tier' => 1, 'is_active' => true]);
        $inactiveTier2 = TriageQueue::create(['name' => 'Tier 2', 'code' => 'move-inactive-target', 'tier' => 2, 'is_active' => false]);
        $alert = $this->alertFactory()->open()->create(['queue_id' => $tier1->id]);

        $this->actingAs($this->admin)
            ->post("/control-room/escalations/{$alert->id}/move", [
                'target_queue_id' => $inactiveTier2->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('alert');

        $this->assertSame($tier1->id, $alert->fresh()->queue_id);
    }

    public function test_move_to_queue_rejects_an_inactive_current_queue(): void
    {
        $inactiveTier1 = TriageQueue::create(['name' => 'Tier 1', 'code' => 'move-inactive-current', 'tier' => 1, 'is_active' => false]);
        $tier2 = TriageQueue::create(['name' => 'Tier 2', 'code' => 'move-active-target', 'tier' => 2, 'is_active' => true]);
        $alert = $this->alertFactory()->open()->create(['queue_id' => $inactiveTier1->id]);

        $this->actingAs($this->admin)
            ->post("/control-room/escalations/{$alert->id}/move", [
                'target_queue_id' => $tier2->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('alert');

        $this->assertSame($inactiveTier1->id, $alert->fresh()->queue_id);
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

        $alert = $this->alertFactory()->open()->create(['queue_id' => $tier1->id]);

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

    public function test_bulk_escalation_history_records_the_same_clamped_level_that_is_persisted(): void
    {
        $tier2 = TriageQueue::create(['name' => 'Tier 2', 'code' => 'clamp-t2', 'tier' => 2, 'is_active' => true]);
        $tier1 = TriageQueue::create([
            'name' => 'Tier 1',
            'code' => 'clamp-t1',
            'tier' => 1,
            'is_active' => true,
            'escalate_to_queue_id' => $tier2->id,
        ]);
        $alert = $this->alertFactory()->open()->create([
            'queue_id' => $tier1->id,
            'escalation_level' => ControlRoomAlert::MAX_ESCALATION_LEVEL,
        ]);

        $this->actingAs($this->admin)
            ->post('/control-room/escalations/bulk-escalate', [
                'alert_ids' => [$alert->id],
                'reason' => 'Move to the next specialist queue.',
            ])
            ->assertRedirect();

        $alert->refresh();
        $history = collect($alert->context['escalation_history'] ?? [])->last();

        $this->assertSame($tier2->id, $alert->queue_id);
        $this->assertSame(ControlRoomAlert::MAX_ESCALATION_LEVEL, $alert->escalation_level);
        $this->assertSame($alert->escalation_level, $history['level'] ?? null);
    }

    public function test_bulk_escalation_skips_inactive_current_and_destination_queues(): void
    {
        $activeDestination = TriageQueue::create([
            'name' => 'Active destination',
            'code' => 'bulk-active-destination',
            'tier' => 2,
            'is_active' => true,
        ]);
        $inactiveCurrent = TriageQueue::create([
            'name' => 'Inactive current',
            'code' => 'bulk-inactive-current',
            'tier' => 1,
            'is_active' => false,
            'escalate_to_queue_id' => $activeDestination->id,
        ]);
        $inactiveDestination = TriageQueue::create([
            'name' => 'Inactive destination',
            'code' => 'bulk-inactive-destination',
            'tier' => 2,
            'is_active' => false,
        ]);
        $activeCurrent = TriageQueue::create([
            'name' => 'Active current',
            'code' => 'bulk-active-current',
            'tier' => 1,
            'is_active' => true,
            'escalate_to_queue_id' => $inactiveDestination->id,
        ]);
        $inactiveCurrentAlert = $this->alertFactory()->open()->create([
            'queue_id' => $inactiveCurrent->id,
        ]);
        $inactiveDestinationAlert = $this->alertFactory()->open()->create([
            'queue_id' => $activeCurrent->id,
        ]);

        $this->actingAs($this->admin)
            ->post('/control-room/escalations/bulk-escalate', [
                'alert_ids' => [$inactiveCurrentAlert->id, $inactiveDestinationAlert->id],
                'reason' => 'Attempted bulk escalation through inactive configuration.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame($inactiveCurrent->id, $inactiveCurrentAlert->fresh()->queue_id);
        $this->assertSame($activeCurrent->id, $inactiveDestinationAlert->fresh()->queue_id);
        $this->assertSame(0, (int) $inactiveCurrentAlert->fresh()->escalation_level);
        $this->assertSame(0, (int) $inactiveDestinationAlert->fresh()->escalation_level);
    }

    public function test_bulk_escalate_validates_alert_ids(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/escalations/bulk-escalate', [])
            ->assertSessionHasErrors('alert_ids');
    }

    public function test_bulk_escalate_requires_a_reason(): void
    {
        $alert = $this->alertFactory()->open()->create();

        $this->actingAs($this->admin)
            ->post('/control-room/escalations/bulk-escalate', [
                'alert_ids' => [$alert->id],
            ])
            ->assertSessionHasErrors('reason');
    }

    private function alertFactory(): ControlRoomAlertFactory
    {
        return ControlRoomAlert::factory()->state(['site_id' => $this->site->id]);
    }
}
