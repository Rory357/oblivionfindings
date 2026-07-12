<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->seed(RbacSeeder::class);

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

    public function test_update_rejects_self_parenting_without_mutation_or_audit(): void
    {
        $task = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'Cannot parent itself',
            'priority' => 'medium',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 1,
        ]);
        $auditCount = DB::table('audit_logs')->count();

        $response = $this->actingAs($this->admin)
            ->putJson("/control-room/tasks/{$task->id}", [
                'parent_task_id' => $task->id,
            ]);

        $this->assertNull($task->fresh()->parent_task_id);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $response->assertUnprocessable()->assertJsonValidationErrors('parent_task_id');
    }

    public function test_update_rejects_transitive_parent_cycle_without_mutation_or_audit(): void
    {
        $ancestor = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'Ancestor',
            'priority' => 'medium',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 1,
        ]);
        $descendant = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'Descendant',
            'priority' => 'medium',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'parent_task_id' => $ancestor->id,
            'sort_order' => 2,
        ]);
        $auditCount = DB::table('audit_logs')->count();

        $response = $this->actingAs($this->admin)
            ->putJson("/control-room/tasks/{$ancestor->id}", [
                'parent_task_id' => $descendant->id,
            ]);

        $this->assertNull($ancestor->fresh()->parent_task_id);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $response->assertUnprocessable()->assertJsonValidationErrors('parent_task_id');
    }

    #[DataProvider('invalidReorderPayloads')]
    public function test_reorder_rejects_invalid_task_ids_without_sort_or_audit_mutation(string $invalidCase): void
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
        $foreignAlert = ControlRoomAlert::factory()->open()->create();
        $foreign = AlertTask::create([
            'alert_id' => $foreignAlert->id,
            'title' => 'Foreign',
            'priority' => 'medium',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 1,
        ]);
        $taskIds = $invalidCase === 'foreign'
            ? [$second->id, $foreign->id]
            : [$second->id, $second->id];
        $auditCount = DB::table('audit_logs')->count();

        $response = $this->actingAs($this->admin)
            ->postJson("/control-room/alerts/{$this->alert->id}/tasks/reorder", [
                'task_ids' => $taskIds,
            ]);

        $this->assertSame(1, $first->fresh()->sort_order);
        $this->assertSame(2, $second->fresh()->sort_order);
        $this->assertSame(1, $foreign->fresh()->sort_order);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $response->assertUnprocessable()->assertJsonValidationErrors('task_ids.1');
    }

    public static function invalidReorderPayloads(): array
    {
        return [
            'foreign alert task' => ['foreign'],
            'duplicate task' => ['duplicate'],
        ];
    }

    public function test_update_allows_valid_reparenting_and_explicit_nullable_field_clears(): void
    {
        $firstParent = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'First parent',
            'priority' => 'medium',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 1,
        ]);
        $secondParent = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'Second parent',
            'priority' => 'medium',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 2,
        ]);
        $task = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'Reparentable child',
            'description' => 'Clear me',
            'assigned_to_user_id' => $this->admin->id,
            'priority' => 'medium',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'due_at' => now()->addDay(),
            'estimated_minutes' => 30,
            'parent_task_id' => $firstParent->id,
            'sort_order' => 3,
        ]);

        $this->actingAs($this->admin)
            ->put("/control-room/tasks/{$task->id}", [
                'parent_task_id' => $secondParent->id,
            ])
            ->assertRedirect();
        $this->assertSame($secondParent->id, $task->fresh()->parent_task_id);

        $this->actingAs($this->admin)
            ->put("/control-room/tasks/{$task->id}", [
                'description' => null,
                'assigned_to_user_id' => null,
                'due_at' => null,
                'estimated_minutes' => null,
                'parent_task_id' => null,
            ])
            ->assertRedirect();

        $task->refresh();
        $this->assertNull($task->description);
        $this->assertNull($task->assigned_to_user_id);
        $this->assertNull($task->due_at);
        $this->assertNull($task->estimated_minutes);
        $this->assertNull($task->parent_task_id);
    }
}
