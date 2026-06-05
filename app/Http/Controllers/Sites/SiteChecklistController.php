<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistResponse;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteChecklistTemplateItem;
use App\Models\SiteDamage;
use App\Models\SiteHazard;
use App\Support\ChecklistsDashboardData;
use App\Support\SiteRecommendedChecklists;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SiteChecklistController extends Controller
{
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

    public function runs(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $runs = SiteChecklistRun::where('site_id', $site->id)
            ->with(['template', 'completedBy:id,name'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->orderByDesc('scheduled_date')
            ->paginate(20);

        return inertia('sites/checklists/runs', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
            ],
            'runs' => $runs,
            'filters' => $request->only(['status']),
        ]);
    }

    public function showRun(SiteChecklistRun $run)
    {
        $this->authorize('view', $run->site);

        $run->load([
            'site:id,name,type',
            'template.items',
            'responses.templateItem',
            'completedBy:id,name',
        ]);

        return inertia('sites/checklists/runs/[id]', [
            'site' => [
                'id' => $run->site->id,
                'name' => $run->site->name,
            ],
            'template' => [
                'id' => $run->template->id,
                'name' => $run->template->name,
            ],
            'run' => [
                'id' => $run->id,
                'scheduled_date' => $run->scheduled_date?->toDateString(),
                'status' => $run->status,
                'completion_percentage' => (float) $run->completion_percentage,
            ],
            'items' => $run->template->items
                ->sortBy('sort_order')
                ->values()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'question' => $item->question,
                    'response_type' => $item->response_type,
                    'response_config' => $item->response_config,
                    'is_required' => $item->is_required,
                    'guidance' => $item->guidance,
                    'failure_creates_hazard' => (bool) $item->failure_creates_hazard,
                ]),
            'responses' => $run->responses->map(fn ($response) => [
                'id' => $response->id,
                'template_item_id' => $response->template_item_id,
                'response_value' => $response->response_value,
                'notes' => $response->notes,
                'photo_path' => $response->photo_path,
                'is_failed' => (bool) $response->is_failed,
            ]),
        ]);
    }

    public function startRun(Request $request, SiteChecklistRun $run)
    {
        $this->authorize('update', $run->site);

        $run->update([
            'status' => 'in_progress',
            'started_at' => $run->started_at ?? now(),
        ]);

        return redirect()->route('sites.checklists.showRun', $run->id);
    }

    public function saveResponse(Request $request, SiteChecklistRun $run)
    {
        $this->extendChecklistExecutionWindow();
        $this->authorize('update', $run->site);

        $validated = $request->validate([
            'responses' => 'required|array|min:1',
            'responses.*.template_item_id' => 'required|integer',
            'responses.*.response_value' => 'nullable|string',
            'responses.*.notes' => 'nullable|string',
            'responses.*.photo_path' => 'nullable|string',
            'responses.*.is_failed' => 'boolean',
            'responses.*.create_hazard' => 'boolean',
            'responses.*.create_damage' => 'boolean',
            'overall_notes' => 'nullable|string',
            'signature_name' => 'nullable|string',
        ]);

        $this->bulkUpsertResponses($run, $validated['responses']);

        // Answering a scheduled run marks it in progress (also covers the run
        // modal's save path, which never calls startRun explicitly).
        if ($run->status === 'scheduled') {
            $run->update(['status' => 'in_progress', 'started_at' => $run->started_at ?? now()]);
        }

        $run->calculateCompletion();
        $this->raiseFollowUpsForFailures($run, $request->user()->id);

        return redirect()->back();
    }

    public function completeRun(Request $request, SiteChecklistRun $run)
    {
        $this->extendChecklistExecutionWindow();
        $this->authorize('update', $run->site);

        // If already completed, short-circuit so duplicate submits (page reloads,
        // double-clicks, retries after a previous timeout) don't redo all the work.
        if ($run->status === 'completed') {
            return redirect()->back()->with('success', 'Checklist already completed.');
        }

        $validated = $request->validate([
            'responses' => 'required|array|min:1',
            'responses.*.template_item_id' => 'required|integer',
            'responses.*.response_value' => 'nullable|string',
            'responses.*.notes' => 'nullable|string',
            'responses.*.photo_path' => 'nullable|string',
            'responses.*.is_failed' => 'boolean',
            'responses.*.create_hazard' => 'boolean',
            'responses.*.create_damage' => 'boolean',
            'overall_notes' => 'nullable|string',
            'signature_name' => 'required|string',
        ]);

        DB::transaction(function () use ($run, $validated, $request) {
            $this->bulkUpsertResponses($run, $validated['responses']);
            $run->calculateCompletion();
            $this->raiseFollowUpsForFailures($run, $request->user()->id);

            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by_user_id' => $request->user()->id,
                'overall_notes' => $validated['overall_notes'] ?? null,
            ]);
        }, 3);

        return redirect()->back()->with('success', 'Checklist completed.');
    }

    /**
     * Move a scheduled/overdue run to a different date (Schedule "Reschedule").
     */
    public function rescheduleRun(Request $request, SiteChecklistRun $run)
    {
        $this->authorize('update', $run->site);

        $validated = $request->validate([
            'scheduled_date' => ['required', 'date'],
        ]);

        $run->update(['scheduled_date' => $validated['scheduled_date']]);

        return redirect()->back()->with('success', 'Checklist run rescheduled.');
    }

    /**
     * Reassign a single run to a specific user (or clear it back to the
     * assignment's default assignee with a null value).
     */
    public function reassignRun(Request $request, SiteChecklistRun $run)
    {
        $this->authorize('update', $run->site);

        $validated = $request->validate([
            'assigned_to_user_id' => ['nullable', 'exists:users,id'],
        ]);

        $run->update(['assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null]);

        return redirect()->back()->with('success', 'Checklist run reassigned.');
    }

    /**
     * Skip a run that won't be completed this cycle (Schedule "Skip").
     */
    public function skipRun(Request $request, SiteChecklistRun $run)
    {
        $this->authorize('update', $run->site);

        if ($run->status === 'completed') {
            return redirect()->back()->with('error', 'A completed run cannot be skipped.');
        }

        $run->update(['status' => 'skipped']);

        return redirect()->back()->with('success', 'Checklist run skipped.');
    }

    private function extendChecklistExecutionWindow(): void
    {
        $currentLimit = (int) ini_get('max_execution_time');

        if ($currentLimit === 0 || $currentLimit >= 120) {
            return;
        }

        @set_time_limit(120);
    }

    /**
     * Bulk-upsert responses keyed by (run_id, template_item_id).
     * Single SQL statement instead of N updateOrCreate calls.
     * Filters out any template_item_ids that don't belong to this run's template.
     */
    private function bulkUpsertResponses(SiteChecklistRun $run, array $responses): void
    {
        if (empty($responses)) {
            return;
        }

        $validItemIds = SiteChecklistTemplateItem::where('template_id', $run->template_id)
            ->pluck('id')
            ->all();
        $validIdSet = array_flip($validItemIds);

        $now = now();
        $rows = [];
        foreach ($responses as $r) {
            $itemId = (int) ($r['template_item_id'] ?? 0);
            if (!isset($validIdSet[$itemId])) {
                continue;
            }
            $rows[] = [
                'run_id' => $run->id,
                'tenant_id' => $run->tenant_id,
                'template_item_id' => $itemId,
                'response_value' => $r['response_value'] ?? null,
                'notes' => $r['notes'] ?? null,
                'photo_path' => $r['photo_path'] ?? null,
                'is_failed' => (bool) ($r['is_failed'] ?? false),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            return;
        }

        SiteChecklistResponse::upsert(
            $rows,
            ['run_id', 'template_item_id'],
            ['response_value', 'notes', 'photo_path', 'is_failed', 'updated_at'],
        );
    }

    /**
     * Spawn the follow-up records a failed run item is flagged to raise: a
     * SiteHazard (failure_creates_hazard) and/or a SiteDamage report
     * (failure_creates_damage, folded in from the retired house-checklists).
     * Idempotent — the response's created_hazard_id / created_damage_id stop a
     * re-save (or save-then-complete) from duplicating either record.
     */
    private function raiseFollowUpsForFailures(SiteChecklistRun $run, int $userId): void
    {
        $responses = $run->responses()
            ->where('is_failed', true)
            ->where(fn ($q) => $q->whereNull('created_hazard_id')->orWhereNull('created_damage_id'))
            ->with('templateItem')
            ->get();

        foreach ($responses as $response) {
            $item = $response->templateItem;
            if (! $item) {
                continue;
            }

            if ($item->failure_creates_hazard && ! $response->created_hazard_id) {
                $hazard = SiteHazard::create([
                    'site_id' => $run->site_id,
                    'tenant_id' => $run->tenant_id,
                    'hazard_type' => 'safety',
                    'severity' => 'medium',
                    'likelihood' => 'possible',
                    'description' => $this->followUpDescription('Checklist check failed', $item->question, $response->notes),
                    'reported_by_user_id' => $userId,
                    'status' => 'open',
                    'linked_checklist_run_id' => $run->id,
                ]);
                $response->created_hazard_id = $hazard->id;
            }

            if ($item->failure_creates_damage && ! $response->created_damage_id) {
                $damage = SiteDamage::create([
                    'tenant_id' => $run->tenant_id,
                    'site_id' => $run->site_id,
                    'reported_by' => $userId,
                    'title' => 'Checklist issue: '.Str::limit($item->question, 200),
                    'description' => $response->notes ?: $item->question,
                    'severity' => 'minor',
                    'status' => 'reported',
                    'damage_date' => now()->toDateString(),
                    'discovered_date' => now()->toDateString(),
                    'insurance_status' => 'not_applicable',
                    'checklist_run_id' => $run->id,
                ]);
                $response->created_damage_id = $damage->id;
            }

            if ($response->isDirty()) {
                $response->save();
            }
        }
    }

    private function followUpDescription(string $prefix, string $question, ?string $notes): string
    {
        $text = "{$prefix}: {$question}";

        return $notes ? "{$text} — {$notes}" : $text;
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
            'tenant_id' => $site->tenant_id,
            'template_id' => $validated['template_id'],
            'frequency' => $validated['frequency'],
            'start_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        // Create the initial scheduled run
        SiteChecklistRun::create([
            'assignment_id' => $assignment->id,
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
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
                ->route('sites.checklists.showRun', $existing->id)
                ->with('success', 'Resumed in-progress checklist run.');
        }

        $run = SiteChecklistRun::create([
            'assignment_id' => $assignment->id,
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'template_id' => $assignment->template_id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        return redirect()
            ->route('sites.checklists.showRun', $run->id)
            ->with('success', 'New checklist run started.');
    }
}
