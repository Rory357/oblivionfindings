<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class ControlRoomTaskController extends Controller
{
    /**
     * List tasks for an alert with assignee name and subtask count.
     */
    public function index(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $tasks = AlertTask::where('alert_id', $alert->id)
            ->with('assignedTo:id,name')
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

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['required', 'in:critical,high,medium,low'],
            'due_at' => ['nullable', 'date'],
            'estimated_minutes' => ['nullable', 'integer'],
            'parent_task_id' => ['nullable', 'integer'],
        ]);

        $maxSort = AlertTask::where('alert_id', $alert->id)->max('sort_order') ?? 0;

        $task = AlertTask::create([
            'alert_id' => $alert->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
            'created_by_user_id' => $user->id,
            'status' => 'open',
            'priority' => $data['priority'],
            'due_at' => $data['due_at'] ?? null,
            'estimated_minutes' => $data['estimated_minutes'] ?? null,
            'parent_task_id' => $data['parent_task_id'] ?? null,
            'sort_order' => $maxSort + 1,
        ]);

        AuditLogger::log('controlRoom.task.created', $alert, [
            'alert_id' => $alert->id,
            'task_id' => $task->id,
        ]);

        return back()->with('success', 'Task created.');
    }

    /**
     * Update a task's fields.
     */
    public function update(Request $request, AlertTask $task)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['nullable', 'in:critical,high,medium,low'],
            'due_at' => ['nullable', 'date'],
            'estimated_minutes' => ['nullable', 'integer'],
            'parent_task_id' => ['nullable', 'integer'],
        ]);

        $task->update(array_filter($data, fn ($v) => $v !== null));

        AuditLogger::log('controlRoom.task.updated', $task->alert, [
            'task_id' => $task->id,
            'changes' => array_keys($data),
        ]);

        return back()->with('success', 'Task updated.');
    }

    /**
     * Update a task's status (toggle/set).
     */
    public function updateStatus(Request $request, AlertTask $task)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $data = $request->validate([
            'status' => ['required', 'in:open,in_progress,blocked,completed,cancelled'],
        ]);

        $oldStatus = $task->status;
        $newStatus = $data['status'];

        $updates = ['status' => $newStatus];

        if ($newStatus === 'completed') {
            $updates['completed_at'] = now();
        } elseif ($oldStatus === 'completed' && $newStatus !== 'completed') {
            $updates['completed_at'] = null;
        }

        $task->update($updates);

        AuditLogger::log('controlRoom.task.statusChanged', $task->alert, [
            'task_id' => $task->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);

        return back()->with('success', 'Task status updated.');
    }

    /**
     * Delete a task.
     */
    public function destroy(Request $request, AlertTask $task)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $alert = $task->alert;
        $taskId = $task->id;

        $task->delete();

        AuditLogger::log('controlRoom.task.deleted', $alert, [
            'task_id' => $taskId,
        ]);

        return back()->with('success', 'Task deleted.');
    }

    /**
     * Reorder tasks for an alert.
     */
    public function reorder(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $data = $request->validate([
            'task_ids' => ['required', 'array'],
            'task_ids.*' => ['integer'],
        ]);

        foreach ($data['task_ids'] as $index => $taskId) {
            AlertTask::where('id', $taskId)
                ->where('alert_id', $alert->id)
                ->update(['sort_order' => $index + 1]);
        }

        AuditLogger::log('controlRoom.task.reordered', $alert, [
            'alert_id' => $alert->id,
            'task_ids' => $data['task_ids'],
        ]);

        return back()->with('success', 'Tasks reordered.');
    }
}
