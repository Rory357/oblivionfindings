<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
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

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
        ]);

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->alert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->site->id,
        ]);
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

    public function test_terminal_alert_rejects_new_or_reactivated_operational_tasks(): void
    {
        $completedTask = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'Already completed',
            'priority' => 'medium',
            'status' => AlertTask::STATUS_COMPLETED,
            'completed_at' => now(),
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 1,
        ]);
        $this->alert->forceFill([
            'status' => ControlRoomAlert::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $this->admin->id,
        ])->save();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/tasks", [
                'title' => 'Must not reopen work behind the terminal alert',
                'priority' => 'high',
            ])
            ->assertSessionHasErrors('alert');

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$completedTask->alert_id}/tasks/{$completedTask->id}/status", [
                'status' => AlertTask::STATUS_OPEN,
            ])
            ->assertSessionHasErrors('status');

        $this->assertDatabaseMissing('control_room_alert_tasks', [
            'alert_id' => $this->alert->id,
            'title' => 'Must not reopen work behind the terminal alert',
        ]);
        $this->assertSame(AlertTask::STATUS_COMPLETED, $completedTask->fresh()->status);
    }

    public function test_terminal_alert_rejects_task_field_edits(): void
    {
        $task = $this->makeTask('Historical task', 1);
        $this->resolveAlert();

        $this->actingAs($this->admin)
            ->put("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}", ['title' => 'Rewritten history'])
            ->assertSessionHasErrors('alert');

        $this->assertSame('Historical task', $task->fresh()->title);
    }

    public function test_terminal_alert_rejects_task_deletion(): void
    {
        $task = $this->makeTask('Retained history', 1);
        $this->resolveAlert();

        $this->actingAs($this->admin)
            ->delete("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}")
            ->assertSessionHasErrors('alert');

        $this->assertDatabaseHas('control_room_alert_tasks', ['id' => $task->id]);
    }

    public function test_terminal_alert_rejects_task_reordering(): void
    {
        $first = $this->makeTask('First historical step', 1);
        $second = $this->makeTask('Second historical step', 2);
        $this->resolveAlert();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/tasks/reorder", [
                'task_ids' => [$second->id, $first->id],
            ])
            ->assertSessionHasErrors('alert');

        $this->assertSame(1, $first->fresh()->sort_order);
        $this->assertSame(2, $second->fresh()->sort_order);
    }

    public function test_terminal_alert_rejects_task_cancellation(): void
    {
        $task = $this->makeTask('Unchanged historical outcome', 1);
        $this->resolveAlert();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/status", [
                'status' => AlertTask::STATUS_CANCELLED,
                'reason' => 'Attempted after the alert was resolved.',
            ])
            ->assertSessionHasErrors('task');

        $this->assertSame(AlertTask::STATUS_OPEN, $task->fresh()->status);
    }

    public function test_terminal_alert_rejects_a_new_hs_task_transfer(): void
    {
        $task = $this->makeTask('Do not transfer after resolution', 1);
        $event = HsEvent::factory()->handoverAccepted($this->admin, $this->admin)->create([
            'control_room_alert_id' => $this->alert->id,
        ]);
        $this->resolveAlert();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/transfer-to-health-safety")
            ->assertSessionHasErrors('task');

        $this->assertSame(AlertTask::STATUS_OPEN, $task->fresh()->status);
        $this->assertSame(0, DB::table('hs_corrective_actions')->where('hs_event_id', $event->id)->count());
    }

    public function test_every_task_mutation_locks_the_parent_alert_before_task_rows(): void
    {
        $storeQueries = $this->captureDatabaseQueries(fn () => $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/tasks", [
                'title' => 'Lock-order task',
                'priority' => 'high',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors());
        $this->assertAlertLockPrecedesTaskLock($storeQueries, 'create');

        $task = AlertTask::query()->where('title', 'Lock-order task')->firstOrFail();
        $updateQueries = $this->captureDatabaseQueries(fn () => $this->actingAs($this->admin)
            ->put("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}", ['title' => 'Lock-order task updated'])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors());
        $this->assertAlertLockPrecedesTaskLock($updateQueries, 'update');

        $statusQueries = $this->captureDatabaseQueries(fn () => $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/status", [
                'status' => AlertTask::STATUS_IN_PROGRESS,
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors());
        $this->assertAlertLockPrecedesTaskLock($statusQueries, 'status update');

        $deleteTask = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'Lock-order delete',
            'priority' => 'low',
            'status' => AlertTask::STATUS_OPEN,
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 2,
        ]);
        $deleteQueries = $this->captureDatabaseQueries(fn () => $this->actingAs($this->admin)
            ->delete("/control-room/alerts/{$deleteTask->alert_id}/tasks/{$deleteTask->id}")
            ->assertRedirect()
            ->assertSessionHasErrors('task'));
        $this->assertAlertLockPrecedesTaskLock($deleteQueries, 'delete');
        $this->assertDatabaseHas('control_room_alert_tasks', ['id' => $deleteTask->id]);

        $secondTask = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'Lock-order reorder',
            'priority' => 'medium',
            'status' => AlertTask::STATUS_OPEN,
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 2,
        ]);
        $reorderQueries = $this->captureDatabaseQueries(fn () => $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/tasks/reorder", [
                'task_ids' => [$secondTask->id, $task->id],
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors());
        $this->assertAlertLockPrecedesTaskLock($reorderQueries, 'reorder');
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
            ->post("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/status", ['status' => 'completed'])
            ->assertRedirect();

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertNotNull($task->completed_at);
    }

    #[DataProvider('terminalTaskStatuses')]
    public function test_terminal_tasks_are_historical_and_read_only_across_every_direct_mutation(string $status): void
    {
        $terminalTask = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => "Historical {$status} task",
            'priority' => 'medium',
            'status' => $status,
            'completed_at' => $status === AlertTask::STATUS_COMPLETED ? now() : null,
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 1,
        ]);
        $activeTask = $this->makeTask('Active task', 2);

        $this->actingAs($this->admin)
            ->put("/control-room/alerts/{$terminalTask->alert_id}/tasks/{$terminalTask->id}", [
                'title' => 'Rewritten terminal history',
            ])
            ->assertSessionHasErrors('task');

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$terminalTask->alert_id}/tasks/{$terminalTask->id}/status", [
                'status' => AlertTask::STATUS_OPEN,
            ])
            ->assertSessionHasErrors('task');

        $this->actingAs($this->admin)
            ->delete("/control-room/alerts/{$terminalTask->alert_id}/tasks/{$terminalTask->id}")
            ->assertSessionHasErrors('task');

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/tasks/reorder", [
                'task_ids' => [$activeTask->id, $terminalTask->id],
            ])
            ->assertSessionHasErrors('task_ids');

        $terminalTask->refresh();
        $this->assertSame("Historical {$status} task", $terminalTask->title);
        $this->assertSame($status, $terminalTask->status);
        $this->assertSame(1, $terminalTask->sort_order);
        $this->assertSame(2, $activeTask->fresh()->sort_order);
        $this->assertDatabaseHas('control_room_alert_tasks', ['id' => $terminalTask->id]);
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
            ->post("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/status", ['status' => 'invalid'])
            ->assertSessionHasErrors('status');
    }

    public function test_overdue_scope_excludes_every_terminal_status(): void
    {
        foreach ([
            AlertTask::STATUS_OPEN,
            AlertTask::STATUS_IN_PROGRESS,
            AlertTask::STATUS_BLOCKED,
            AlertTask::STATUS_COMPLETED,
            AlertTask::STATUS_CANCELLED,
            AlertTask::STATUS_TRANSFERRED,
        ] as $status) {
            AlertTask::create([
                'alert_id' => $this->alert->id,
                'title' => "{$status} overdue task",
                'priority' => 'medium',
                'status' => $status,
                'due_at' => now()->subHour(),
                'created_by_user_id' => $this->admin->id,
            ]);
        }

        $this->assertSame(
            [AlertTask::STATUS_BLOCKED, AlertTask::STATUS_IN_PROGRESS, AlertTask::STATUS_OPEN],
            AlertTask::overdue()->orderBy('status')->pluck('status')->all()
        );
    }

    public function test_task7_final_gap_task_deletion_is_fail_closed_and_cannot_bypass_resolution(): void
    {
        $task = AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => 'Complete this evidence-preserving task',
            'priority' => 'low',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 1,
        ]);
        $this->alert->forceFill([
            'status' => ControlRoomAlert::STATUS_TRIAGING,
            'acknowledged_at' => now()->subMinute(),
            'acknowledged_by_user_id' => $this->admin->id,
        ])->save();

        $this->actingAs($this->admin)
            ->delete("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}")
            ->assertRedirect()
            ->assertSessionHasErrors([
                'task' => 'Tasks are part of the alert history and cannot be deleted. Complete, cancel with a reason, or transfer active tasks instead.',
            ]);

        $this->assertDatabaseHas('control_room_alert_tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'controlRoom.task.deleted',
            'auditable_id' => $this->alert->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/resolve", [
                'resolution_notes' => 'Attempt to resolve after deleting the task.',
                'resolution_code' => 'complete',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('alert');

        $this->assertSame(ControlRoomAlert::STATUS_TRIAGING, $this->alert->fresh()->status);
        $this->assertDatabaseHas('control_room_alert_tasks', [
            'id' => $task->id,
            'status' => AlertTask::STATUS_OPEN,
        ]);
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
            ->putJson("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}", [
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
            ->putJson("/control-room/alerts/{$ancestor->alert_id}/tasks/{$ancestor->id}", [
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
        $foreignAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->site->id,
        ]);
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

    public static function terminalTaskStatuses(): array
    {
        return [
            'completed' => [AlertTask::STATUS_COMPLETED],
            'cancelled' => [AlertTask::STATUS_CANCELLED],
            'transferred' => [AlertTask::STATUS_TRANSFERRED],
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
            ->put("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}", [
                'parent_task_id' => $secondParent->id,
            ])
            ->assertRedirect();
        $this->assertSame($secondParent->id, $task->fresh()->parent_task_id);

        $this->actingAs($this->admin)
            ->put("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}", [
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

    /** @return list<array{query: string, bindings: array<mixed>, time: float}> */
    private function captureDatabaseQueries(callable $action): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $action();

            return $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }
    }

    /** @param list<array{query: string, bindings: array<mixed>, time: float}> $queries */
    private function assertAlertLockPrecedesTaskLock(array $queries, string $mutation): void
    {
        $sql = collect($queries)
            ->pluck('query')
            ->map(fn (string $query): string => strtolower(str_replace('`', '', $query)))
            ->values();
        $alertLockIndex = $sql->search(
            fn (string $query): bool => str_contains($query, 'from control_room_alerts')
                && str_contains($query, 'for update'),
        );
        $taskLockIndex = $sql->search(
            fn (string $query): bool => str_contains($query, 'from control_room_alert_tasks')
                && str_contains($query, 'for update'),
        );

        $this->assertNotFalse($alertLockIndex, "The {$mutation} mutation must lock its parent alert.");
        $this->assertNotFalse($taskLockIndex, "The {$mutation} mutation must lock its task rows.");
        $this->assertLessThan(
            $taskLockIndex,
            $alertLockIndex,
            "The {$mutation} mutation must acquire the alert lock before any task-row lock.",
        );
    }

    private function makeTask(string $title, int $sortOrder): AlertTask
    {
        return AlertTask::create([
            'alert_id' => $this->alert->id,
            'title' => $title,
            'priority' => 'medium',
            'status' => AlertTask::STATUS_OPEN,
            'created_by_user_id' => $this->admin->id,
            'sort_order' => $sortOrder,
        ]);
    }

    private function resolveAlert(): void
    {
        $this->alert->forceFill([
            'status' => ControlRoomAlert::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $this->admin->id,
        ])->save();
    }
}
