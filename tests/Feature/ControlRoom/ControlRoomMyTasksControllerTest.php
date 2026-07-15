<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomMyTasksControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->other = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->other->roles()->attach(Role::where('name', 'admin')->first());
    }

    public function test_index_requires_authentication(): void
    {
        $this->get('/control-room/my-tasks')->assertRedirect('/login');
    }

    public function test_index_blocked_for_user_without_permission(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->get('/control-room/my-tasks')
            ->assertForbidden();
    }

    public function test_index_returns_only_alerts_assigned_to_current_user(): void
    {
        $mine = ControlRoomAlert::factory()->open()->assignedTo($this->admin)->create();
        ControlRoomAlert::factory()->open()->assignedTo($this->other)->create();

        $this->actingAs($this->admin)
            ->get('/control-room/my-tasks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/my-tasks')
                ->has('my_alerts', 1)
                ->where('my_alerts.0.id', $mine->id)
                ->has('stats')
                ->has('can')
            );
    }

    public function test_index_excludes_resolved_alerts_from_my_alerts_list(): void
    {
        ControlRoomAlert::factory()->open()->assignedTo($this->admin)->create();
        ControlRoomAlert::factory()->resolved()->assignedTo($this->admin)->create();

        $this->actingAs($this->admin)
            ->get('/control-room/my-tasks')
            ->assertInertia(fn ($page) => $page
                ->has('my_alerts', 1)
                ->where('stats.my_open', 1)
            );
    }

    public function test_residual_terminal_sla_is_omitted_from_my_alert_status(): void
    {
        $alert = ControlRoomAlert::factory()->open()->assignedTo($this->admin)->create();
        AlertSla::query()->create([
            'alert_id' => $alert->id,
            'ended_as' => AlertSla::ENDED_RECONCILED_NO_MATCH,
            'cycle_history' => [['ended_as' => AlertSla::ENDED_RECONCILED_NO_MATCH]],
        ]);

        $this->actingAs($this->admin)
            ->get('/control-room/my-tasks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('my_alerts.0.id', $alert->id)
                ->where('my_alerts.0.sla_status', null)
            );
    }

    public function test_complete_followup_clears_followup_flag(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();
        $note = OperatorNote::create([
            'alert_id' => $alert->id,
            'user_id' => $this->admin->id,
            'type' => 'note',
            'content' => 'Need to follow up tomorrow',
            'requires_followup' => true,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/my-tasks/followups/{$note->id}/complete")
            ->assertRedirect();

        $this->assertFalse($note->fresh()->requires_followup);
    }

    public function test_complete_followup_only_allows_owner(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();
        $note = OperatorNote::create([
            'alert_id' => $alert->id,
            'user_id' => $this->other->id,
            'type' => 'note',
            'content' => 'Someone else needs to handle this',
            'requires_followup' => true,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/my-tasks/followups/{$note->id}/complete")
            ->assertNotFound();

        $this->assertTrue($note->fresh()->requires_followup);
    }
}
