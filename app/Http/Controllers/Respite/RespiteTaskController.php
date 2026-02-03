<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\RespiteTask;
use App\Models\RespiteProcedureRun;
use App\Models\RespiteAuditLog;
use App\Models\User;
use App\Events\Respite\RespiteEvent;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RespiteTaskController extends Controller
{
    public function index(Request $request): Response
    {
        $tasks = RespiteTask::query()
            ->with(['procedureRun.template', 'assignedTo', 'completedBy'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->priority, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($request->assigned_to, fn ($q, $userId) => $q->where('assigned_to_user_id', $userId))
            ->when($request->procedure_run_id, fn ($q, $runId) => $q->where('procedure_run_id', $runId))
            ->when($request->active, fn ($q) => $q->active())
            ->when($request->overdue, fn ($q) => $q->overdue())
            ->orderByRaw('FIELD(priority, "critical", "high", "medium", "low")')
            ->orderBy('due_at')
            ->paginate(20);

        $staff = User::staff()->select('id', 'name')->orderBy('name')->get();

        return Inertia::render('respite/tasks/index', [
            'tasks' => $tasks,
            'staff' => $staff,
            'filters' => $request->only(['status', 'priority', 'assigned_to', 'procedure_run_id', 'active', 'overdue']),
        ]);
    }

    public function show(RespiteTask $task): Response
    {
        $task->load(['procedureRun.template', 'assignedTo', 'assignedBy', 'completedBy', 'approvedBy']);

        RespiteAuditLog::log(
            $task,
            RespiteAuditLog::ACTION_VIEWED,
            auth()->id(),
            null,
            null,
            null,
            RespiteAuditLog::CATEGORY_TASK
        );

        return Inertia::render('respite/tasks/show', [
            'task' => $task,
        ]);
    }

    public function assign(Request $request, RespiteTask $task): RedirectResponse
    {
        $validated = $request->validate([
            'assigned_to_user_id' => 'required|exists:users,id',
        ]);

        $oldAssignee = $task->assigned_to_user_id;

        $task->update([
            'assigned_to_user_id' => $validated['assigned_to_user_id'],
            'assigned_by_user_id' => auth()->id(),
            'assigned_at' => now(),
            'updated_by' => auth()->id(),
        ]);

        RespiteAuditLog::log(
            $task,
            RespiteAuditLog::ACTION_ASSIGNED,
            auth()->id(),
            ['assigned_to_user_id' => $oldAssignee],
            ['assigned_to_user_id' => $validated['assigned_to_user_id']],
            null,
            RespiteAuditLog::CATEGORY_TASK
        );

        event(new RespiteEvent('respite.task.assigned', [
            'id' => $task->id,
            'assigned_to' => $validated['assigned_to_user_id'],
            'procedure_run_id' => $task->procedure_run_id,
        ]));

        return back()->with('success', 'Task assigned.');
    }

    public function start(RespiteTask $task): RedirectResponse
    {
        if ($task->status !== RespiteTask::STATUS_PENDING) {
            return back()->with('error', 'Task has already been started.');
        }

        $task->markInProgress();

        RespiteAuditLog::log(
            $task,
            RespiteAuditLog::ACTION_STATUS_CHANGED,
            auth()->id(),
            ['status' => RespiteTask::STATUS_PENDING],
            ['status' => RespiteTask::STATUS_IN_PROGRESS],
            null,
            RespiteAuditLog::CATEGORY_TASK
        );

        event(new RespiteEvent('respite.task.started', [
            'id' => $task->id,
            'procedure_run_id' => $task->procedure_run_id,
        ]));

        return back()->with('success', 'Task started.');
    }

    public function complete(Request $request, RespiteTask $task): RedirectResponse
    {
        if (!$task->canComplete()) {
            if ($task->requires_approval && $task->status !== RespiteTask::STATUS_APPROVED) {
                return back()->with('error', 'Task requires approval before completion.');
            }
            if (!empty($task->required_evidence) && !$task->evidence_complete) {
                return back()->with('error', 'All required evidence must be collected before completion.');
            }
        }

        $validated = $request->validate([
            'completion_notes' => 'nullable|string|max:2000',
        ]);

        $task->markComplete(auth()->id(), $validated['completion_notes'] ?? null);

        // Advance procedure run
        if ($task->procedureRun) {
            $task->procedureRun->advanceStep();
        }

        RespiteAuditLog::log(
            $task,
            RespiteAuditLog::ACTION_STATUS_CHANGED,
            auth()->id(),
            ['status' => $task->getOriginal('status')],
            ['status' => RespiteTask::STATUS_COMPLETED],
            $validated['completion_notes'] ?? null,
            RespiteAuditLog::CATEGORY_TASK
        );

        event(new RespiteEvent('respite.task.completed', [
            'id' => $task->id,
            'procedure_run_id' => $task->procedure_run_id,
        ]));

        return back()->with('success', 'Task completed.');
    }

    public function submitForApproval(RespiteTask $task): RedirectResponse
    {
        if (!$task->requires_approval) {
            return back()->with('error', 'Task does not require approval.');
        }

        $task->submitForApproval();

        RespiteAuditLog::log(
            $task,
            RespiteAuditLog::ACTION_STATUS_CHANGED,
            auth()->id(),
            ['status' => $task->getOriginal('status')],
            ['status' => RespiteTask::STATUS_AWAITING_APPROVAL],
            null,
            RespiteAuditLog::CATEGORY_TASK
        );

        event(new RespiteEvent('respite.task.awaiting_approval', [
            'id' => $task->id,
            'procedure_run_id' => $task->procedure_run_id,
        ]));

        return back()->with('success', 'Task submitted for approval.');
    }

    public function approve(Request $request, RespiteTask $task): RedirectResponse
    {
        if ($task->status !== RespiteTask::STATUS_AWAITING_APPROVAL) {
            return back()->with('error', 'Task is not awaiting approval.');
        }

        $validated = $request->validate([
            'approval_notes' => 'nullable|string|max:1000',
        ]);

        $task->approve(auth()->id(), $validated['approval_notes'] ?? null);

        RespiteAuditLog::log(
            $task,
            RespiteAuditLog::ACTION_APPROVED,
            auth()->id(),
            ['status' => RespiteTask::STATUS_AWAITING_APPROVAL],
            ['status' => RespiteTask::STATUS_APPROVED],
            $validated['approval_notes'] ?? null,
            RespiteAuditLog::CATEGORY_TASK
        );

        event(new RespiteEvent('respite.task.approved', [
            'id' => $task->id,
            'procedure_run_id' => $task->procedure_run_id,
        ]));

        return back()->with('success', 'Task approved.');
    }

    public function reject(Request $request, RespiteTask $task): RedirectResponse
    {
        if ($task->status !== RespiteTask::STATUS_AWAITING_APPROVAL) {
            return back()->with('error', 'Task is not awaiting approval.');
        }

        $validated = $request->validate([
            'rejection_notes' => 'required|string|max:1000',
        ]);

        $task->reject(auth()->id(), $validated['rejection_notes']);

        RespiteAuditLog::log(
            $task,
            RespiteAuditLog::ACTION_REJECTED,
            auth()->id(),
            ['status' => RespiteTask::STATUS_AWAITING_APPROVAL],
            ['status' => RespiteTask::STATUS_REJECTED],
            $validated['rejection_notes'],
            RespiteAuditLog::CATEGORY_TASK
        );

        event(new RespiteEvent('respite.task.rejected', [
            'id' => $task->id,
            'procedure_run_id' => $task->procedure_run_id,
        ]));

        return back()->with('success', 'Task rejected.');
    }

    public function addEvidence(Request $request, RespiteTask $task): RedirectResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|max:50',
            'data' => 'required|array',
        ]);

        $task->addEvidence($validated['type'], $validated['data']);

        RespiteAuditLog::log(
            $task,
            'evidence_added',
            auth()->id(),
            null,
            ['evidence_type' => $validated['type']],
            null,
            RespiteAuditLog::CATEGORY_TASK
        );

        event(new RespiteEvent('respite.task.evidence_added', [
            'id' => $task->id,
            'evidence_type' => $validated['type'],
        ]));

        return back()->with('success', 'Evidence added.');
    }

    public function updateChecklist(Request $request, RespiteTask $task): RedirectResponse
    {
        $validated = $request->validate([
            'index' => 'required|integer|min:0',
            'completed' => 'required|boolean',
        ]);

        $task->updateChecklistItem($validated['index'], $validated['completed']);

        return back()->with('success', 'Checklist updated.');
    }

    public function myTasks(): Response
    {
        $tasks = RespiteTask::query()
            ->with(['procedureRun.template'])
            ->where('assigned_to_user_id', auth()->id())
            ->active()
            ->orderByRaw('FIELD(priority, "critical", "high", "medium", "low")')
            ->orderBy('due_at')
            ->get();

        return Inertia::render('respite/tasks/my-tasks', [
            'tasks' => $tasks,
        ]);
    }

    public function awaitingApproval(): Response
    {
        $tasks = RespiteTask::query()
            ->with(['procedureRun.template', 'assignedTo'])
            ->where('status', RespiteTask::STATUS_AWAITING_APPROVAL)
            ->orderByRaw('FIELD(priority, "critical", "high", "medium", "low")')
            ->orderBy('due_at')
            ->paginate(20);

        return Inertia::render('respite/tasks/awaiting-approval', [
            'tasks' => $tasks,
        ]);
    }

    public function overdue(): Response
    {
        $tasks = RespiteTask::query()
            ->with(['procedureRun.template', 'assignedTo'])
            ->overdue()
            ->orderBy('due_at')
            ->paginate(20);

        return Inertia::render('respite/tasks/overdue', [
            'tasks' => $tasks,
        ]);
    }
}
