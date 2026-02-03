<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\RespiteProcedureRun;
use App\Models\RespiteTask;
use App\Models\ProcedureTemplate;
use App\Models\RespiteAuditLog;
use App\Models\User;
use App\Events\Respite\RespiteEvent;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RespiteProcedureRunController extends Controller
{
    public function index(Request $request): Response
    {
        $runs = RespiteProcedureRun::query()
            ->with(['template', 'initiatedBy', 'escalatedTo'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->template_id, fn ($q, $templateId) => $q->where('procedure_template_id', $templateId))
            ->when($request->active, fn ($q) => $q->active())
            ->when($request->overdue, fn ($q) => $q->overdue())
            ->orderByDesc('created_at')
            ->paginate(20);

        $templates = ProcedureTemplate::where('is_active', true)
            ->select('id', 'name', 'category')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return Inertia::render('respite/procedure-runs/index', [
            'runs' => $runs,
            'templates' => $templates,
            'filters' => $request->only(['status', 'template_id', 'active', 'overdue']),
        ]);
    }

    public function create(Request $request): Response
    {
        $templates = ProcedureTemplate::where('is_active', true)
            ->select('id', 'name', 'category', 'description', 'estimated_duration_minutes')
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->groupBy('category');

        return Inertia::render('respite/procedure-runs/create', [
            'templates' => $templates,
            'subjectType' => $request->subject_type,
            'subjectId' => $request->subject_id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'procedure_template_id' => 'required|exists:procedure_templates,id',
            'subject_type' => 'required|string|max:255',
            'subject_id' => 'required|integer',
            'variables' => 'nullable|array',
        ]);

        $template = ProcedureTemplate::findOrFail($validated['procedure_template_id']);

        $run = DB::transaction(function () use ($validated, $template) {
            $slaDeadline = $template->sla_minutes
                ? now()->addMinutes($template->sla_minutes)
                : null;

            $run = RespiteProcedureRun::create([
                'procedure_template_id' => $template->id,
                'subject_type' => $validated['subject_type'],
                'subject_id' => $validated['subject_id'],
                'status' => RespiteProcedureRun::STATUS_PENDING,
                'current_step' => 0,
                'total_steps' => count($template->steps ?? []),
                'step_states' => [],
                'collected_evidence' => [],
                'variables' => $validated['variables'] ?? [],
                'sla_deadline' => $slaDeadline,
                'sla_breached' => false,
                'escalation_level' => 0,
                'initiated_by' => auth()->id(),
                'created_by' => auth()->id(),
            ]);

            // Create tasks from template steps
            $this->createTasksFromTemplate($run, $template);

            return $run;
        });

        RespiteAuditLog::log(
            $run,
            RespiteAuditLog::ACTION_CREATED,
            auth()->id(),
            null,
            ['template' => $template->name, 'subject_type' => $validated['subject_type']],
            null,
            RespiteAuditLog::CATEGORY_PROCEDURE
        );

        event(new RespiteEvent('respite.procedure.started', [
            'id' => $run->id,
            'template_id' => $template->id,
            'template_name' => $template->name,
            'subject_type' => $validated['subject_type'],
            'subject_id' => $validated['subject_id'],
        ]));

        return redirect()
            ->route('respite.procedure-runs.show', $run)
            ->with('success', 'Procedure started.');
    }

    public function show(RespiteProcedureRun $procedureRun): Response
    {
        $procedureRun->load(['template', 'tasks', 'initiatedBy', 'escalatedTo']);

        RespiteAuditLog::log(
            $procedureRun,
            RespiteAuditLog::ACTION_VIEWED,
            auth()->id(),
            null,
            null,
            null,
            RespiteAuditLog::CATEGORY_PROCEDURE
        );

        return Inertia::render('respite/procedure-runs/show', [
            'run' => $procedureRun,
        ]);
    }

    public function start(RespiteProcedureRun $procedureRun): RedirectResponse
    {
        if ($procedureRun->status !== RespiteProcedureRun::STATUS_PENDING) {
            return back()->with('error', 'Procedure has already been started.');
        }

        $procedureRun->markStarted();

        RespiteAuditLog::log(
            $procedureRun,
            'started',
            auth()->id(),
            ['status' => RespiteProcedureRun::STATUS_PENDING],
            ['status' => RespiteProcedureRun::STATUS_IN_PROGRESS],
            null,
            RespiteAuditLog::CATEGORY_PROCEDURE
        );

        event(new RespiteEvent('respite.procedure.in_progress', [
            'id' => $procedureRun->id,
        ]));

        return back()->with('success', 'Procedure started.');
    }

    public function complete(RespiteProcedureRun $procedureRun): RedirectResponse
    {
        if (!$procedureRun->canProgress()) {
            return back()->with('error', 'Procedure cannot be completed in its current state.');
        }

        // Check all tasks are complete
        $incompleteTasks = $procedureRun->tasks()
            ->whereNotIn('status', [RespiteTask::STATUS_COMPLETED, RespiteTask::STATUS_APPROVED, RespiteTask::STATUS_SKIPPED])
            ->count();

        if ($incompleteTasks > 0) {
            return back()->with('error', "Cannot complete procedure: {$incompleteTasks} task(s) still pending.");
        }

        $procedureRun->markCompleted();

        RespiteAuditLog::log(
            $procedureRun,
            RespiteAuditLog::ACTION_STATUS_CHANGED,
            auth()->id(),
            ['status' => $procedureRun->getOriginal('status')],
            ['status' => RespiteProcedureRun::STATUS_COMPLETED],
            null,
            RespiteAuditLog::CATEGORY_PROCEDURE
        );

        event(new RespiteEvent('respite.procedure.completed', [
            'id' => $procedureRun->id,
        ]));

        return back()->with('success', 'Procedure completed.');
    }

    public function fail(Request $request, RespiteProcedureRun $procedureRun): RedirectResponse
    {
        $validated = $request->validate([
            'failure_reason' => 'required|string|max:1000',
        ]);

        $procedureRun->markFailed($validated['failure_reason']);

        RespiteAuditLog::log(
            $procedureRun,
            RespiteAuditLog::ACTION_STATUS_CHANGED,
            auth()->id(),
            ['status' => $procedureRun->getOriginal('status')],
            ['status' => RespiteProcedureRun::STATUS_FAILED, 'failure_reason' => $validated['failure_reason']],
            $validated['failure_reason'],
            RespiteAuditLog::CATEGORY_PROCEDURE
        );

        event(new RespiteEvent('respite.procedure.failed', [
            'id' => $procedureRun->id,
            'reason' => $validated['failure_reason'],
        ]));

        return back()->with('success', 'Procedure marked as failed.');
    }

    public function cancel(Request $request, RespiteProcedureRun $procedureRun): RedirectResponse
    {
        $validated = $request->validate([
            'cancellation_reason' => 'required|string|max:1000',
        ]);

        $procedureRun->update([
            'status' => RespiteProcedureRun::STATUS_CANCELLED,
            'failure_reason' => $validated['cancellation_reason'],
            'updated_by' => auth()->id(),
        ]);

        RespiteAuditLog::log(
            $procedureRun,
            RespiteAuditLog::ACTION_STATUS_CHANGED,
            auth()->id(),
            ['status' => $procedureRun->getOriginal('status')],
            ['status' => RespiteProcedureRun::STATUS_CANCELLED],
            $validated['cancellation_reason'],
            RespiteAuditLog::CATEGORY_PROCEDURE
        );

        event(new RespiteEvent('respite.procedure.cancelled', [
            'id' => $procedureRun->id,
        ]));

        return back()->with('success', 'Procedure cancelled.');
    }

    public function escalate(Request $request, RespiteProcedureRun $procedureRun): RedirectResponse
    {
        $validated = $request->validate([
            'escalate_to_user_id' => 'required|exists:users,id',
            'escalation_reason' => 'required|string|max:1000',
        ]);

        $procedureRun->escalate($validated['escalate_to_user_id']);

        RespiteAuditLog::log(
            $procedureRun,
            RespiteAuditLog::ACTION_ESCALATED,
            auth()->id(),
            ['escalation_level' => $procedureRun->escalation_level - 1],
            ['escalation_level' => $procedureRun->escalation_level, 'escalated_to' => $validated['escalate_to_user_id']],
            $validated['escalation_reason'],
            RespiteAuditLog::CATEGORY_PROCEDURE
        );

        event(new RespiteEvent('respite.procedure.escalated', [
            'id' => $procedureRun->id,
            'escalated_to' => $validated['escalate_to_user_id'],
            'level' => $procedureRun->escalation_level,
        ]));

        return back()->with('success', 'Procedure escalated.');
    }

    public function myActive(): Response
    {
        $runs = RespiteProcedureRun::query()
            ->with(['template', 'tasks' => fn ($q) => $q->where('assigned_to_user_id', auth()->id())->active()])
            ->whereHas('tasks', fn ($q) => $q->where('assigned_to_user_id', auth()->id())->active())
            ->active()
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('respite/procedure-runs/my-active', [
            'runs' => $runs,
        ]);
    }

    public function overdue(): Response
    {
        $runs = RespiteProcedureRun::query()
            ->with(['template', 'escalatedTo'])
            ->overdue()
            ->orderBy('sla_deadline')
            ->paginate(20);

        return Inertia::render('respite/procedure-runs/overdue', [
            'runs' => $runs,
        ]);
    }

    protected function createTasksFromTemplate(RespiteProcedureRun $run, ProcedureTemplate $template): void
    {
        $steps = $template->steps ?? [];

        foreach ($steps as $index => $step) {
            $dueAt = null;
            if (isset($step['sla_minutes'])) {
                $dueAt = now()->addMinutes($step['sla_minutes']);
            }

            RespiteTask::create([
                'procedure_run_id' => $run->id,
                'subject_type' => $run->subject_type,
                'subject_id' => $run->subject_id,
                'title' => $step['title'] ?? "Step " . ($index + 1),
                'description' => $step['description'] ?? null,
                'task_type' => $step['type'] ?? RespiteTask::TYPE_ACTION,
                'status' => RespiteTask::STATUS_PENDING,
                'priority' => $step['priority'] ?? RespiteTask::PRIORITY_MEDIUM,
                'requires_approval' => $step['requires_approval'] ?? false,
                'required_evidence' => $step['required_evidence'] ?? null,
                'is_stop_gate' => $step['is_stop_gate'] ?? false,
                'checklist_items' => $step['checklist_items'] ?? null,
                'step_order' => $index,
                'due_at' => $dueAt,
                'sla_minutes' => $step['sla_minutes'] ?? null,
                'created_by' => auth()->id(),
            ]);
        }
    }
}
