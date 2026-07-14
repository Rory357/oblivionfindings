<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ControlRoom\Concerns\AuthorizesControlRoomAlertAccess;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ControlRoomTaskController extends Controller
{
    use AuthorizesControlRoomAlertAccess;

    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * List tasks for an alert with assignee name and subtask count.
     */
    public function index(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $tasks = AlertTask::where('alert_id', $alert->id)
            ->with(['assignedTo:id,name', 'transferredBy:id,name'])
            ->withCount('subtasks')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (AlertTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status,
                'priority' => $task->priority,
                'assigned_to_user_id' => $task->assigned_to_user_id,
                'assigned_to_name' => $task->assignedTo?->name,
                'created_by_user_id' => $task->created_by_user_id,
                'parent_task_id' => $task->parent_task_id,
                'subtask_count' => $task->subtasks_count,
                'due_at' => $task->due_at?->toISOString(),
                'completed_at' => $task->completed_at?->toISOString(),
                'transferred_to_hs_corrective_action_id' => $task->transferred_to_hs_corrective_action_id,
                'transferred_at' => $task->transferred_at?->toISOString(),
                'transferred_by_user_id' => $task->transferred_by_user_id,
                'transferred_by_name' => $task->transferredBy?->name,
                'estimated_minutes' => $task->estimated_minutes,
                'actual_minutes' => $task->actual_minutes,
                'sort_order' => $task->sort_order,
                'created_at' => $task->created_at?->toISOString(),
            ]);

        return response()->json(['tasks' => $tasks]);
    }

    /**
     * Create a new task for an alert.
     */
    public function store(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['required', 'in:critical,high,medium,low'],
            'due_at' => ['nullable', 'date'],
            'estimated_minutes' => ['nullable', 'integer'],
            'parent_task_id' => [
                'nullable',
                'integer',
                Rule::exists('control_room_alert_tasks', 'id')
                    ->where(fn ($query) => $query->where('alert_id', $alert->id)),
            ],
        ]);

        if (isset($data['assigned_to_user_id'])) {
            $this->assertCanAssignAlertToUser($user, (int) $data['assigned_to_user_id']);
        }

        DB::transaction(function () use ($alert, $data, $user): void {
            $lockedAlert = $this->lockAlert($alert);
            if ($lockedAlert->isTerminal()) {
                throw ValidationException::withMessages([
                    'alert' => 'Operational tasks cannot be created for a resolved, closed, or dismissed alert.',
                ]);
            }

            $lockedTasks = AlertTask::query()
                ->where('alert_id', $lockedAlert->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'sort_order']);
            $parentTaskId = $data['parent_task_id'] ?? null;
            if ($parentTaskId !== null && ! $lockedTasks->contains('id', (int) $parentTaskId)) {
                throw ValidationException::withMessages([
                    'parent_task_id' => 'The selected parent task is no longer available on this alert.',
                ]);
            }

            $task = AlertTask::create([
                'alert_id' => $lockedAlert->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
                'created_by_user_id' => $user->id,
                'status' => AlertTask::STATUS_OPEN,
                'priority' => $data['priority'],
                'due_at' => $data['due_at'] ?? null,
                'estimated_minutes' => $data['estimated_minutes'] ?? null,
                'parent_task_id' => $parentTaskId,
                'sort_order' => ((int) ($lockedTasks->max('sort_order') ?? 0)) + 1,
            ]);

            AuditLogger::log('controlRoom.task.created', $lockedAlert, [
                'alert_id' => $lockedAlert->id,
                'task_id' => $task->id,
            ]);
        }, self::TRANSACTION_ATTEMPTS);

        return back()->with('success', 'Task created.');
    }

    /**
     * Update a task's fields.
     */
    public function update(Request $request, AlertTask $task)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $alert = $task->alert;
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string'],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'priority' => ['sometimes', 'required', 'in:critical,high,medium,low'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'estimated_minutes' => ['sometimes', 'nullable', 'integer'],
            'parent_task_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('control_room_alert_tasks', 'id')
                    ->where(fn ($query) => $query->where('alert_id', $alert->id)),
            ],
        ]);

        if (isset($data['assigned_to_user_id'])) {
            $this->assertCanAssignAlertToUser($user, (int) $data['assigned_to_user_id']);
        }

        DB::transaction(function () use ($alert, $task, $data): void {
            $lockedAlert = $this->lockAlert($alert);
            $lockedTask = $this->lockTaskForAlert($task, $lockedAlert);
            $this->assertAlertAllowsTaskMutation($lockedAlert, 'alert');
            $this->assertTaskIsMutable($lockedTask);

            if (array_key_exists('parent_task_id', $data)) {
                $this->assertTaskParentDoesNotCreateCycle(
                    $lockedTask,
                    $data['parent_task_id'] === null ? null : (int) $data['parent_task_id'],
                );
            }

            $lockedTask->update($data);

            AuditLogger::log('controlRoom.task.updated', $lockedAlert, [
                'task_id' => $lockedTask->id,
                'changes' => array_keys($data),
            ]);
        }, self::TRANSACTION_ATTEMPTS);

        return back()->with('success', 'Task updated.');
    }

    /**
     * Update a task's status (toggle/set).
     */
    public function updateStatus(
        Request $request,
        AlertTask $task,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $task->alert);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                AlertTask::STATUS_OPEN,
                AlertTask::STATUS_IN_PROGRESS,
                AlertTask::STATUS_BLOCKED,
                AlertTask::STATUS_COMPLETED,
                AlertTask::STATUS_CANCELLED,
            ])],
            'reason' => [
                Rule::requiredIf(fn () => $request->input('status') === AlertTask::STATUS_CANCELLED),
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $newStatus = $data['status'];

        if ($newStatus === AlertTask::STATUS_CANCELLED) {
            try {
                $lifecycle->cancelTask($task, $user, $data['reason']);
            } catch (InvalidArgumentException $exception) {
                return back()->withErrors(['task' => $exception->getMessage()]);
            }

            return back()->with('success', 'Task cancelled.');
        }

        DB::transaction(function () use ($task, $newStatus, $user): void {
            $lockedAlert = $this->lockAlertForTask($task);
            $lockedTask = $this->lockTaskForAlert($task, $lockedAlert);
            $this->assertAlertAllowsTaskMutation($lockedAlert, 'status');
            $this->assertTaskIsMutable($lockedTask);

            $oldStatus = (string) $lockedTask->status;
            $updates = ['status' => $newStatus];
            if ($newStatus === AlertTask::STATUS_COMPLETED) {
                $updates['completed_at'] = now();
            } elseif ($oldStatus === AlertTask::STATUS_COMPLETED) {
                $updates['completed_at'] = null;
            }

            $lockedTask->update($updates);

            AuditLogger::log('controlRoom.task.statusChanged', $lockedAlert, [
                'actor_id' => $user->id,
                'task_id' => $lockedTask->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);
        }, self::TRANSACTION_ATTEMPTS);

        return back()->with('success', 'Task status updated.');
    }

    /**
     * Transfer an active operational task to the canonical H&S corrective action.
     */
    public function transferToHealthSafety(
        Request $request,
        AlertTask $task,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $task->alert);

        $request->validate([
            'hs_event_id' => ['prohibited'],
        ]);

        try {
            $lifecycle->transferTaskToHealthSafety($task, $user);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['task' => $exception->getMessage()]);
        }

        return back()->with('success', 'Task transferred to Health & Safety.');
    }

    /**
     * Delete a task.
     */
    public function destroy(Request $request, AlertTask $task)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $alert = $task->alert;
        $this->assertCanAccessAlert($user, $alert);

        DB::transaction(function () use ($alert, $task): void {
            $lockedAlert = $this->lockAlert($alert);
            $lockedTask = $this->lockTaskForAlert($task, $lockedAlert);
            $this->assertAlertAllowsTaskMutation($lockedAlert, 'alert');
            $this->assertTaskIsMutable($lockedTask);
            throw ValidationException::withMessages([
                'task' => 'Tasks are part of the alert history and cannot be deleted. Complete, cancel with a reason, or transfer active tasks instead.',
            ]);
        }, self::TRANSACTION_ATTEMPTS);

        return back();
    }

    /**
     * Reorder tasks for an alert.
     */
    public function reorder(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'task_ids' => ['required', 'array'],
            'task_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('control_room_alert_tasks', 'id')
                    ->where(fn ($query) => $query->where('alert_id', $alert->id)),
            ],
        ]);

        DB::transaction(function () use ($alert, $data, $user): void {
            $lockedAlert = $this->lockAlert($alert);
            $this->assertAlertAllowsTaskMutation($lockedAlert, 'alert');
            $lockedTasks = AlertTask::query()
                ->where('alert_id', $lockedAlert->id)
                ->whereIn('id', $data['task_ids'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedTasks->count() !== count($data['task_ids'])) {
                throw ValidationException::withMessages([
                    'task_ids' => 'One or more tasks are no longer available on this alert.',
                ]);
            }
            if ($lockedTasks->contains(
                fn (AlertTask $lockedTask): bool => in_array(
                    $lockedTask->status,
                    AlertTask::TERMINAL_STATUSES,
                    true,
                ),
            )) {
                throw ValidationException::withMessages([
                    'task_ids' => 'Completed, cancelled, and transferred tasks are historical and cannot be reordered.',
                ]);
            }

            foreach ($data['task_ids'] as $index => $taskId) {
                $lockedTasks->get((int) $taskId)->update(['sort_order' => $index + 1]);
            }

            AuditLogger::log('controlRoom.task.reordered', $lockedAlert, [
                'actor_id' => $user->id,
                'alert_id' => $lockedAlert->id,
                'task_ids' => $data['task_ids'],
            ]);
        }, self::TRANSACTION_ATTEMPTS);

        return back()->with('success', 'Tasks reordered.');
    }

    private function lockAlert(ControlRoomAlert $alert): ControlRoomAlert
    {
        return ControlRoomAlert::query()
            ->whereKey($alert->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockAlertForTask(AlertTask $task): ControlRoomAlert
    {
        return ControlRoomAlert::query()
            ->whereKey($task->alert_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockTaskForAlert(AlertTask $task, ControlRoomAlert $alert): AlertTask
    {
        return AlertTask::query()
            ->whereKey($task->id)
            ->where('alert_id', $alert->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertTaskParentDoesNotCreateCycle(AlertTask $task, ?int $parentTaskId): void
    {
        if ($parentTaskId === null) {
            return;
        }

        $parentIdsByTask = AlertTask::query()
            ->where('alert_id', $task->alert_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('parent_task_id', 'id');
        if (! $parentIdsByTask->has($parentTaskId)) {
            throw ValidationException::withMessages([
                'parent_task_id' => 'The selected parent task is no longer available on this alert.',
            ]);
        }
        $visitedTaskIds = [];
        $currentTaskId = $parentTaskId;

        while ($currentTaskId !== null) {
            if ($currentTaskId === $task->id || isset($visitedTaskIds[$currentTaskId])) {
                throw ValidationException::withMessages([
                    'parent_task_id' => 'A task cannot be parented beneath itself or one of its descendants.',
                ]);
            }

            $visitedTaskIds[$currentTaskId] = true;
            $nextTaskId = $parentIdsByTask->get($currentTaskId);
            $currentTaskId = $nextTaskId === null ? null : (int) $nextTaskId;
        }
    }

    private function assertTaskIsMutable(AlertTask $task): void
    {
        if (! in_array($task->status, AlertTask::TERMINAL_STATUSES, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'task' => 'Completed, cancelled, and transferred tasks are historical and read-only.',
        ]);
    }

    private function assertAlertAllowsTaskMutation(ControlRoomAlert $alert, string $field): void
    {
        if (! $alert->isTerminal()) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Operational tasks are historical and read-only once their alert is resolved, closed, or dismissed.',
        ]);
    }

    private function assertCanAssignAlertToUser(User $user, int $assigneeUserId): void
    {
        $this->siteAccess()->assertCanAssignControlRoomAlertToUser(
            $user,
            $assigneeUserId,
            $this->alertBypassPermissions(),
            'You are not authorized to assign alerts to that staff member.',
        );
    }
}
