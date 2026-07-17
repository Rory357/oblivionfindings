<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\Shift;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomHandoverControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    public function test_show_requires_manage_permission(): void
    {
        $stranger = User::factory()->create(['approved_at' => now()]);
        $shift = Shift::create([
            'name' => 'Day Shift',
            'starts_at' => now()->subHours(4),
            'status' => 'active',
            'shift_lead_user_id' => $this->admin->id,
        ]);

        $this->actingAs($stranger)
            ->get("/control-room/shifts/{$shift->id}/handover")
            ->assertForbidden();
    }

    public function test_show_returns_404_for_inactive_shift(): void
    {
        $shift = Shift::create([
            'name' => 'Day Shift',
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->subHour(),
            'status' => 'completed',
            'shift_lead_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get("/control-room/shifts/{$shift->id}/handover")
            ->assertNotFound();
    }

    public function test_show_renders_handover_wizard(): void
    {
        $shift = Shift::create([
            'name' => 'Active Shift',
            'starts_at' => now()->subHours(4),
            'status' => 'active',
            'shift_lead_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get("/control-room/shifts/{$shift->id}/handover")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/shifts/handover')
                ->where('shift.id', $shift->id)
                ->where('shift.status', 'active')
                ->has('openAlertsCount')
                ->has('requiredAlerts')
                ->has('handoverCriteria', 7)
                ->has('handoverCriteriaAt')
                ->has('carryForward')
                ->has('staff')
            );
    }

    public function test_review_gap_prepared_snapshot_is_hidden_from_an_uninvolved_manager(): void
    {
        $uninvolvedManager = User::factory()->create([
            'role' => 'coordinator',
            'approved_at' => now(),
        ]);
        $uninvolvedManager->roles()->attach(Role::where('name', 'coordinator')->first());
        $incomingLead = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $incomingLead->roles()->attach(Role::where('name', 'coordinator')->first());
        $shift = Shift::create([
            'name' => 'Prepared shift',
            'starts_at' => now()->subHours(8),
            'status' => 'active',
            'shift_lead_user_id' => $this->admin->id,
            'handed_over_to_user_id' => $incomingLead->id,
            'handover_status' => Shift::HANDOVER_PREPARED,
            'handover_prepared_at' => now(),
            'handover_snapshot' => [
                'prepared_by' => ['id' => $this->admin->id, 'name' => $this->admin->name],
                'incoming_shift' => [
                    'lead' => ['id' => $incomingLead->id, 'name' => $incomingLead->name],
                    'team_members' => [
                        ['id' => $uninvolvedManager->id, 'name' => $uninvolvedManager->name],
                    ],
                ],
                'alerts' => [[
                    'id' => 99,
                    'site' => ['id' => 123, 'name' => 'Restricted site'],
                    'person' => ['id' => 456, 'name' => 'Restricted person'],
                ]],
                'carry_forward' => ['total' => 0],
                'override' => [
                    'actor' => ['id' => $uninvolvedManager->id, 'name' => $uninvolvedManager->name],
                    'actor_id' => $uninvolvedManager->id,
                ],
            ],
        ]);
        $snapshot = $shift->handover_snapshot;
        data_set($snapshot, 'prepared_by.id', $uninvolvedManager->id);
        $shift->updateQuietly(['handover_snapshot' => $snapshot]);

        $this->assertTrue($uninvolvedManager->canDo('controlRoom.alerts.manage'));
        $this->assertFalse($uninvolvedManager->canDo('reports.viewAny'));

        $this->actingAs($uninvolvedManager)
            ->get("/control-room/shifts/{$shift->id}/handover")
            ->assertForbidden();

        $this->actingAs($incomingLead)
            ->get("/control-room/shifts/{$shift->id}/handover")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shift.id', $shift->id)
                ->where('shift.can_accept', false)
                ->where('shift.handover_snapshot', null)
                ->where('snapshotIssue', fn ($value): bool => is_string($value) && $value !== '')
                ->has('requiredAlerts', 0)
            );
    }
}
