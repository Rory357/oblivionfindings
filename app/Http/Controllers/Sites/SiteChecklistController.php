<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Services\Sites\SiteChecklistRunExecutionService;
use App\Support\ChecklistsDashboardData;
use App\Support\SiteRecommendedChecklists;
use Illuminate\Http\Request;

class SiteChecklistController extends Controller
{
    public function __construct(
        private readonly SiteChecklistRunExecutionService $runExecution,
    ) {}

    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        return inertia('sites/checklists/index', array_merge(
            (new ChecklistsDashboardData($request))->forSite($site),
            [
                'site' => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'type' => $site->type,
                ],
                'backHref' => "/sites/{$site->id}",
                'recommendedChecklists' => SiteRecommendedChecklists::forType($site->type),
            ],
        ));
    }

    public function saveResponse(Request $request, SiteChecklistRun $run)
    {
        $this->extendChecklistExecutionWindow();
        $this->runExecution->assertVisible($run, $request->user());

        $validated = $request->validate([
            'responses' => 'required|array|min:1',
            'responses.*.template_item_id' => 'required|integer|distinct',
            'responses.*.response_value' => 'nullable|string',
            'responses.*.notes' => 'nullable|string',
            'responses.*.photo_path' => 'nullable|string',
            'responses.*.is_failed' => 'boolean',
            'responses.*.create_hazard' => 'boolean',
            'responses.*.create_damage' => 'boolean',
            'overall_notes' => 'nullable|string',
            'signature_name' => 'nullable|string',
        ]);

        $this->runExecution->saveResponses(
            $run,
            $request->user(),
            $validated['responses'],
        );

        return redirect()->back();
    }

    public function completeRun(Request $request, SiteChecklistRun $run)
    {
        $this->extendChecklistExecutionWindow();
        $this->runExecution->assertVisible($run, $request->user());

        $validated = $request->validate([
            'responses' => 'required|array|min:1',
            'responses.*.template_item_id' => 'required|integer|distinct',
            'responses.*.response_value' => 'nullable|string',
            'responses.*.notes' => 'nullable|string',
            'responses.*.photo_path' => 'nullable|string',
            'responses.*.is_failed' => 'boolean',
            'responses.*.create_hazard' => 'boolean',
            'responses.*.create_damage' => 'boolean',
            'overall_notes' => 'nullable|string',
            'signature_name' => 'required|string|max:255',
        ]);

        $result = $this->runExecution->complete(
            $run,
            $request->user(),
            $validated['responses'],
            $validated['signature_name'],
            $validated['overall_notes'] ?? null,
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()->back()->with(
            'success',
            $result['replayed'] ? 'Checklist already completed.' : 'Checklist completed.',
        );
    }

    /**
     * Move a scheduled/overdue run to a different date (Schedule "Reschedule").
     */
    public function rescheduleRun(Request $request, SiteChecklistRun $run)
    {
        $this->runExecution->assertVisible($run, $request->user());

        $validated = $request->validate([
            'scheduled_date' => ['required', 'date'],
        ]);

        $this->runExecution->reschedule(
            $run,
            $request->user(),
            $validated['scheduled_date'],
        );

        return redirect()->back()->with('success', 'Checklist run rescheduled.');
    }

    /**
     * Reassign a single run to a specific user (or clear it back to the
     * assignment's default assignee with a null value).
     */
    public function reassignRun(Request $request, SiteChecklistRun $run)
    {
        $this->runExecution->assertVisible($run, $request->user());

        $validated = $request->validate([
            'assigned_to_user_id' => ['nullable', 'exists:users,id'],
        ]);
        $this->runExecution->reassign(
            $run,
            $request->user(),
            isset($validated['assigned_to_user_id'])
                ? (int) $validated['assigned_to_user_id']
                : null,
        );

        return redirect()->back()->with('success', 'Checklist run reassigned.');
    }

    /**
     * Skip a run that won't be completed this cycle (Schedule "Skip").
     */
    public function skipRun(Request $request, SiteChecklistRun $run)
    {
        $this->runExecution->assertVisible($run, $request->user());
        $skipped = $this->runExecution->skip($run, $request->user());
        if ($skipped->status === 'completed') {
            return redirect()->back()->with('error', 'A completed run cannot be skipped.');
        }

        return redirect()->back()->with('success', 'Checklist run skipped.');
    }

    /**
     * Restore a skipped run back into the scheduled worklist.
     */
    public function restoreRun(Request $request, SiteChecklistRun $run)
    {
        $this->runExecution->assertVisible($run, $request->user());
        $this->runExecution->restore($run, $request->user());

        return redirect()->back()->with('success', 'Checklist run restored.');
    }

    private function extendChecklistExecutionWindow(): void
    {
        $currentLimit = (int) ini_get('max_execution_time');

        if ($currentLimit === 0 || $currentLimit >= 120) {
            return;
        }

        @set_time_limit(120);
    }

    public function assignChecklist(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'template_id' => 'required|exists:site_checklist_templates,id',
            'frequency' => 'required|in:once,daily,weekly,fortnightly,monthly,quarterly',
        ]);

        $assignment = SiteChecklistAssignment::create([
            'site_id' => $site->id,
            'template_id' => $validated['template_id'],
            'frequency' => $validated['frequency'],
            'start_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        // Create the initial scheduled run
        SiteChecklistRun::create([
            'assignment_id' => $assignment->id,
            'site_id' => $site->id,
            'template_id' => $validated['template_id'],
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        return redirect()
            ->route('sites.checklists.index', $site->id)
            ->with('success', 'Checklist assigned successfully.');
    }

    public function removeAssignment(Request $request, Site $site, SiteChecklistAssignment $assignment)
    {
        $this->authorize('update', $site);
        abort_unless($assignment->site_id === $site->id, 404);

        // Deactivate instead of hard-delete to preserve run history
        $assignment->update(['is_active' => false]);

        return redirect()
            ->route('sites.checklists.index', $site->id)
            ->with('success', 'Checklist assignment removed.');
    }

    public function createRun(Request $request, Site $site, SiteChecklistAssignment $assignment)
    {
        $this->authorize('update', $site);
        abort_unless($assignment->site_id === $site->id, 404);

        // Reuse any run still awaiting completion instead of creating duplicates.
        // Prefer in_progress, then scheduled, then overdue.
        $existing = SiteChecklistRun::where('assignment_id', $assignment->id)
            ->awaitingCompletion()
            ->orderByRaw("FIELD(status, 'in_progress', 'scheduled', 'overdue')")
            ->orderByDesc('scheduled_date')
            ->first();

        if ($existing) {
            if (in_array($existing->status, ['scheduled', 'overdue'], true)) {
                $existing->update([
                    'status' => 'in_progress',
                    'started_at' => $existing->started_at ?? now(),
                ]);
            }

            return redirect()
                ->to("/sites/{$site->id}/checklists?run={$existing->id}")
                ->with('success', 'Resumed in-progress checklist run.');
        }

        $run = SiteChecklistRun::create([
            'assignment_id' => $assignment->id,
            'site_id' => $site->id,
            'template_id' => $assignment->template_id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        return redirect()
            ->to("/sites/{$site->id}/checklists?run={$run->id}")
            ->with('success', 'New checklist run started.');
    }
}
