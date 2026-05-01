<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomTaskControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supportWorker;

    protected ControlRoomAlert $alert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->alert = ControlRoomAlert::factory()->open()->create();
    }

    public function test_index_requires_manage_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get("/control-room/alerts/{$this->alert->id}/tasks")
            ->assertForbidden();
    }

    public function test_index_returns_tasks_for_alert(): void
    {
        AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'Investigate scene',
            'priority' => 'high',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 1,
        ]);

        $this->actingAs($this->admin)
            ->getJson("/control-room/alerts/{$this->alert->id}/tasks")
            ->assertOk()
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.title', 'Investigate scene');
    }

    public function test_store_creates_task(): void
    {
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/tasks", [
                'title' => 'Call site lead',
                'priority' => 'high',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_alert_tasks', [
            'alert_id' => $this->alert->id,
            'title' => 'Call site lead',
            'priority' => 'high',
            'status' => 'open',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/tasks", [])
            ->assertSessionHasErrors(['title', 'priority']);
    }

    public function test_update_status_to_completed_sets_completed_at(): void
    {
        $task = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'Step 1',
            'priority' => 'medium',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 1,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/tasks/{$task->id}/status", ['status' => 'completed'])
            ->assertRedirect();

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_update_status_validates_status_value(): void
    {
        $task = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'Step 1',
            'priority' => 'medium',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 1,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/tasks/{$task->id}/status", ['status' => 'invalid'])
            ->assertSessionHasErrors('status');
    }

    public function test_destroy_removes_task(): void
    {
        $task = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'To delete',
            'priority' => 'low',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 1,
        ]);

        $this->actingAs($this->admin)
            ->delete("/control-room/tasks/{$task->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('control_room_alert_tasks', ['id' => $task->id]);
    }

    public function test_reorder_updates_sort_order(): void
    {
        $first = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'First',
            'priority' => 'medium',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 1,
        ]);
        $second = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'Second',
            'priority' => 'medium',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 2,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/tasks/reorder", [
                'task_ids' => [$second->id, $first->id],
            ])
            ->assertRedirect();

        $this->assertSame(1, $second->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);
    }
}
