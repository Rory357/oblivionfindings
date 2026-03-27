<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoom\PlaybookRunStep;
use App\Models\ControlRoom\PlaybookStep;
use App\Models\ControlRoomAlert;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ControlRoomPlaybookController extends Controller
{
    /**
     * List playbooks with category/active filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $filters = $request->only(['category', 'is_active']);

        $query = Playbook::query()
            ->withCount(['steps', 'runs'])
            ->with(['runs' => fn($q) => $q->latest()->limit(1)]);

        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== 'all') {
            $query->where('is_active', $filters['is_active'] === '1' || $filters['is_active'] === 'true');
        }

        $playbooks = $query->orderBy('name')->get()->map(fn(Playbook $pb) => [
            'id' => $pb->id,
            'name' => $pb->name,
            'code' => $pb->code,
            'description' => $pb->description,
            'category' => $pb->category,
            'version' => $pb->version,
            'is_active' => $pb->is_active,
            'auto_attach' => $pb->auto_attach,
            'requires_approval' => $pb->requires_approval,
            'sla_acknowledge_minutes' => $pb->sla_acknowledge_minutes,
            'sla_response_minutes' => $pb->sla_response_minutes,
            'sla_resolution_minutes' => $pb->sla_resolution_minutes,
            'steps_count' => $pb->steps_count,
            'runs_count' => $pb->runs_count,
            'last_run_at' => $pb->runs->first()?->started_at?->toISOString(),
            'created_at' => $pb->created_at?->toISOString(),
        ]);

        return Inertia::render('control-room/playbooks/index', [
            'playbooks' => $playbooks,
            'filters' => $filters,
            'categories' => Playbook::categories(),
            'stepTypes' => PlaybookStep::types(),
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
            ],
        ]);
    }

    /**
     * Show playbook detail with steps, trigger conditions, and run history.
     */
    public function show(Request $request, Playbook $playbook)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $playbook->load(['steps', 'createdBy:id,name', 'updatedBy:id,name']);

        $recentRuns = PlaybookRun::where('playbook_id', $playbook->id)
            ->with([
                'alert:id,alert_type,severity,status',
                'startedBy:id,name',
                'completedBy:id,name',
            ])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn(PlaybookRun $run) => [
                'id' => $run->id,
                'alert_id' => $run->alert_id,
                'alert' => $run->alert ? [
                    'id' => $run->alert->id,
                    'alert_type' => $run->alert->alert_type,
                    'severity' => $run->alert->severity,
                    'status' => $run->alert->status,
                ] : null,
                'status' => $run->status,
                'current_step' => $run->current_step,
                'completed_steps' => $run->completed_steps,
                'total_steps' => $run->total_steps,
                'progress' => $run->getProgressPercentage(),
                'started_at' => $run->started_at?->toISOString(),
                'completed_at' => $run->completed_at?->toISOString(),
                'started_by' => $run->startedBy ? [
                    'id' => $run->startedBy->id,
                    'name' => $run->startedBy->name,
                ] : null,
                'completed_by' => $run->completedBy ? [
                    'id' => $run->completedBy->id,
                    'name' => $run->completedBy->name,
                ] : null,
            ]);

        return Inertia::render('control-room/playbooks/show', [
            'playbook' => [
                'id' => $playbook->id,
                'name' => $playbook->name,
                'code' => $playbook->code,
                'description' => $playbook->description,
                'category' => $playbook->category,
                'version' => $playbook->version,
                'is_active' => $playbook->is_active,
                'auto_attach' => $playbook->auto_attach,
                'trigger_alert_types' => $playbook->trigger_alert_types ?? [],
                'trigger_severities' => $playbook->trigger_severities ?? [],
                'sla_acknowledge_minutes' => $playbook->sla_acknowledge_minutes,
                'sla_response_minutes' => $playbook->sla_response_minutes,
                'sla_resolution_minutes' => $playbook->sla_resolution_minutes,
                'required_evidence' => $playbook->required_evidence ?? [],
                'requires_approval' => $playbook->requires_approval,
                'approval_roles' => $playbook->approval_roles ?? [],
                'escalation_after_minutes' => $playbook->escalation_after_minutes,
                'escalation_targets' => $playbook->escalation_targets ?? [],
                'created_by' => $playbook->createdBy ? [
                    'id' => $playbook->createdBy->id,
                    'name' => $playbook->createdBy->name,
                ] : null,
                'updated_by' => $playbook->updatedBy ? [
                    'id' => $playbook->updatedBy->id,
                    'name' => $playbook->updatedBy->name,
                ] : null,
                'created_at' => $playbook->created_at?->toISOString(),
                'updated_at' => $playbook->updated_at?->toISOString(),
                'steps' => $playbook->steps->map(fn(PlaybookStep $step) => [
                    'id' => $step->id,
                    'order' => $step->order,
                    'title' => $step->title,
                    'type' => $step->type,
                    'instructions' => $step->instructions,
                    'is_required' => $step->is_required,
                    'is_blocking' => $step->is_blocking,
                    'time_limit_minutes' => $step->time_limit_minutes,
                    'decision_options' => $step->decision_options,
                    'notify_config' => $step->notify_config,
                    'evidence_config' => $step->evidence_config,
                ]),
            ],
            'recentRuns' => $recentRuns,
            'categories' => Playbook::categories(),
            'stepTypes' => PlaybookStep::types(),
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
            ],
        ]);
    }

    /**
     * Create a new playbook with steps.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'string', 'in:emergency,safety,compliance,maintenance,investigation'],
            'auto_attach' => ['boolean'],
            'trigger_alert_types' => ['nullable', 'array'],
            'trigger_alert_types.*' => ['string', 'max:100'],
            'trigger_severities' => ['nullable', 'array'],
            'trigger_severities.*' => ['string', 'in:low,medium,high,critical'],
            'sla_acknowledge_minutes' => ['nullable', 'integer', 'min:1'],
            'sla_response_minutes' => ['nullable', 'integer', 'min:1'],
            'sla_resolution_minutes' => ['nullable', 'integer', 'min:1'],
            'required_evidence' => ['nullable', 'array'],
            'required_evidence.*' => ['string', 'max:100'],
            'requires_approval' => ['boolean'],
            'escalation_after_minutes' => ['nullable', 'integer', 'min:1'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.title' => ['required', 'string', 'max:255'],
            'steps.*.type' => ['required', 'string', 'in:task,decision,notification,escalation,evidence,approval'],
            'steps.*.instructions' => ['nullable', 'string', 'max:2000'],
            'steps.*.is_required' => ['boolean'],
            'steps.*.is_blocking' => ['boolean'],
            'steps.*.time_limit_minutes' => ['nullable', 'integer', 'min:1'],
            'steps.*.decision_options' => ['nullable', 'array'],
            'steps.*.notify_config' => ['nullable', 'array'],
            'steps.*.evidence_config' => ['nullable', 'array'],
        ]);

        $playbook = DB::transaction(function () use ($data, $user) {
            $playbook = Playbook::create([
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'description' => $data['description'] ?? null,
                'category' => $data['category'],
                'version' => 1,
                'is_active' => true,
                'auto_attach' => $data['auto_attach'] ?? false,
                'trigger_alert_types' => $data['trigger_alert_types'] ?? [],
                'trigger_severities' => $data['trigger_severities'] ?? [],
                'sla_acknowledge_minutes' => $data['sla_acknowledge_minutes'] ?? null,
                'sla_response_minutes' => $data['sla_response_minutes'] ?? null,
                'sla_resolution_minutes' => $data['sla_resolution_minutes'] ?? null,
                'required_evidence' => $data['required_evidence'] ?? [],
                'requires_approval' => $data['requires_approval'] ?? false,
                'escalation_after_minutes' => $data['escalation_after_minutes'] ?? null,
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ]);

            foreach ($data['steps'] as $index => $stepData) {
                PlaybookStep::create([
                    'playbook_id' => $playbook->id,
                    'order' => $index + 1,
                    'title' => $stepData['title'],
                    'type' => $stepData['type'],
                    'instructions' => $stepData['instructions'] ?? null,
                    'is_required' => $stepData['is_required'] ?? true,
                    'is_blocking' => $stepData['is_blocking'] ?? false,
                    'time_limit_minutes' => $stepData['time_limit_minutes'] ?? null,
                    'decision_options' => $stepData['decision_options'] ?? null,
                    'notify_config' => $stepData['notify_config'] ?? null,
                    'evidence_config' => $stepData['evidence_config'] ?? null,
                ]);
            }

            return $playbook;
        });

        AuditLogger::log('controlRoom.playbook.create', $playbook, [
            'playbook_id' => $playbook->id,
            'name' => $playbook->name,
        ]);

        return redirect()->route('control-room.playbooks.show', $playbook)
            ->with('success', 'Playbook created.');
    }

    /**
     * Update a playbook and its steps.
     */
    public function update(Request $request, Playbook $playbook)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'string', 'in:emergency,safety,compliance,maintenance,investigation'],
            'auto_attach' => ['boolean'],
            'trigger_alert_types' => ['nullable', 'array'],
            'trigger_alert_types.*' => ['string', 'max:100'],
            'trigger_severities' => ['nullable', 'array'],
            'trigger_severities.*' => ['string', 'in:low,medium,high,critical'],
            'sla_acknowledge_minutes' => ['nullable', 'integer', 'min:1'],
            'sla_response_minutes' => ['nullable', 'integer', 'min:1'],
            'sla_resolution_minutes' => ['nullable', 'integer', 'min:1'],
            'required_evidence' => ['nullable', 'array'],
            'required_evidence.*' => ['string', 'max:100'],
            'requires_approval' => ['boolean'],
            'escalation_after_minutes' => ['nullable', 'integer', 'min:1'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.id' => ['nullable', 'integer'],
            'steps.*.title' => ['required', 'string', 'max:255'],
            'steps.*.type' => ['required', 'string', 'in:task,decision,notification,escalation,evidence,approval'],
            'steps.*.instructions' => ['nullable', 'string', 'max:2000'],
            'steps.*.is_required' => ['boolean'],
            'steps.*.is_blocking' => ['boolean'],
            'steps.*.time_limit_minutes' => ['nullable', 'integer', 'min:1'],
            'steps.*.decision_options' => ['nullable', 'array'],
            'steps.*.notify_config' => ['nullable', 'array'],
            'steps.*.evidence_config' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($data, $playbook, $user) {
            $playbook->update([
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'description' => $data['description'] ?? null,
                'category' => $data['category'],
                'auto_attach' => $data['auto_attach'] ?? false,
                'trigger_alert_types' => $data['trigger_alert_types'] ?? [],
                'trigger_severities' => $data['trigger_severities'] ?? [],
                'sla_acknowledge_minutes' => $data['sla_acknowledge_minutes'] ?? null,
                'sla_response_minutes' => $data['sla_response_minutes'] ?? null,
                'sla_resolution_minutes' => $data['sla_resolution_minutes'] ?? null,
                'required_evidence' => $data['required_evidence'] ?? [],
                'requires_approval' => $data['requires_approval'] ?? false,
                'escalation_after_minutes' => $data['escalation_after_minutes'] ?? null,
                'version' => $playbook->version + 1,
                'updated_by_user_id' => $user->id,
            ]);

            // Sync steps: delete removed, update existing, create new
            $existingStepIds = collect($data['steps'])->pluck('id')->filter()->toArray();
            $playbook->steps()->whereNotIn('id', $existingStepIds)->delete();

            foreach ($data['steps'] as $index => $stepData) {
                if (!empty($stepData['id'])) {
                    PlaybookStep::where('id', $stepData['id'])
                        ->where('playbook_id', $playbook->id)
                        ->update([
                            'order' => $index + 1,
                            'title' => $stepData['title'],
                            'type' => $stepData['type'],
                            'instructions' => $stepData['instructions'] ?? null,
                            'is_required' => $stepData['is_required'] ?? true,
                            'is_blocking' => $stepData['is_blocking'] ?? false,
                            'time_limit_minutes' => $stepData['time_limit_minutes'] ?? null,
                            'decision_options' => $stepData['decision_options'] ?? null,
                            'notify_config' => $stepData['notify_config'] ?? null,
                            'evidence_config' => $stepData['evidence_config'] ?? null,
                        ]);
                } else {
                    PlaybookStep::create([
                        'playbook_id' => $playbook->id,
                        'order' => $index + 1,
                        'title' => $stepData['title'],
                        'type' => $stepData['type'],
                        'instructions' => $stepData['instructions'] ?? null,
                        'is_required' => $stepData['is_required'] ?? true,
                        'is_blocking' => $stepData['is_blocking'] ?? false,
                        'time_limit_minutes' => $stepData['time_limit_minutes'] ?? null,
                        'decision_options' => $stepData['decision_options'] ?? null,
                        'notify_config' => $stepData['notify_config'] ?? null,
                        'evidence_config' => $stepData['evidence_config'] ?? null,
                    ]);
                }
            }
        });

        AuditLogger::log('controlRoom.playbook.update', $playbook, [
            'playbook_id' => $playbook->id,
            'version' => $playbook->version,
        ]);

        return back()->with('success', 'Playbook updated to version ' . $playbook->fresh()->version . '.');
    }

    /**
     * Toggle a playbook's active status.
     */
    public function toggleActive(Request $request, Playbook $playbook)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $playbook->update([
            'is_active' => !$playbook->is_active,
            'updated_by_user_id' => $user->id,
        ]);

        AuditLogger::log('controlRoom.playbook.toggleActive', $playbook, [
            'playbook_id' => $playbook->id,
            'is_active' => $playbook->is_active,
        ]);

        return back()->with('success', $playbook->is_active ? 'Playbook activated.' : 'Playbook deactivated.');
    }

    /**
     * Start a playbook run for an alert.
     */
    public function startRun(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $data = $request->validate([
            'playbook_id' => ['required', 'integer', 'exists:control_room_playbooks,id'],
        ]);

        $playbook = Playbook::findOrFail($data['playbook_id']);

        if (!$playbook->is_active) {
            return back()->withErrors(['playbook' => 'Cannot start an inactive playbook.']);
        }

        // Check if alert already has an active playbook run
        if ($alert->playbook_run_id) {
            $existingRun = PlaybookRun::find($alert->playbook_run_id);
            if ($existingRun && in_array($existingRun->status, ['pending', 'in_progress'])) {
                return back()->withErrors(['playbook' => 'Alert already has an active playbook run.']);
            }
        }

        $run = DB::transaction(function () use ($playbook, $alert, $user) {
            $run = PlaybookRun::create([
                'playbook_id' => $playbook->id,
                'alert_id' => $alert->id,
                'status' => 'pending',
                'current_step' => 0,
                'completed_steps' => 0,
                'total_steps' => $playbook->steps()->count(),
            ]);

            $run->start($user);

            $alert->update(['playbook_run_id' => $run->id]);

            return $run;
        });

        AuditLogger::log('controlRoom.playbook.startRun', $alert, [
            'alert_id' => $alert->id,
            'playbook_id' => $playbook->id,
            'run_id' => $run->id,
        ]);

        return back()->with('success', 'Playbook run started.');
    }

    /**
     * Advance current step in the playbook run.
     */
    public function advanceStep(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'decision_taken' => ['nullable', 'string', 'max:500'],
            'evidence' => ['nullable', 'array'],
        ]);

        $run = PlaybookRun::where('alert_id', $alert->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        $currentStep = $run->steps()->where('status', 'in_progress')->first();

        if ($currentStep) {
            $currentStep->complete($user, $data['notes'] ?? null, $data['evidence'] ?? null);

            if (!empty($data['decision_taken'])) {
                $currentStep->recordDecision($data['decision_taken'], $user);
            }
        }

        $nextStep = $run->advanceToNextStep();

        if (!$nextStep) {
            $run->complete($user);

            AuditLogger::log('controlRoom.playbook.runCompleted', $alert, [
                'alert_id' => $alert->id,
                'run_id' => $run->id,
            ]);

            return back()->with('success', 'Playbook run completed.');
        }

        AuditLogger::log('controlRoom.playbook.advanceStep', $alert, [
            'alert_id' => $alert->id,
            'run_id' => $run->id,
            'step' => $nextStep->order,
        ]);

        return back()->with('success', 'Step completed, advanced to next step.');
    }

    /**
     * Skip the current step.
     */
    public function skipStep(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $run = PlaybookRun::where('alert_id', $alert->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        $currentStep = $run->steps()->where('status', 'in_progress')->first();

        if (!$currentStep) {
            return back()->withErrors(['step' => 'No active step to skip.']);
        }

        // Check if step is required and blocking
        $playbookStep = $currentStep->step;
        if ($playbookStep && $playbookStep->is_required && $playbookStep->is_blocking) {
            return back()->withErrors(['step' => 'This step is required and blocking. It cannot be skipped.']);
        }

        $currentStep->skip($user, $data['reason'] ?? null);
        $run->increment('completed_steps');

        $nextStep = $run->steps()
            ->where('status', 'pending')
            ->orderBy('order')
            ->first();

        if ($nextStep) {
            $nextStep->update([
                'status' => 'in_progress',
                'started_at' => now(),
            ]);
            $run->update(['current_step' => $nextStep->order]);
        } else {
            $run->complete($user);
        }

        AuditLogger::log('controlRoom.playbook.skipStep', $alert, [
            'alert_id' => $alert->id,
            'run_id' => $run->id,
        ]);

        return back()->with('success', 'Step skipped.');
    }
}
