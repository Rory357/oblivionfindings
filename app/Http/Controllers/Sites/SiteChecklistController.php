<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistResponse;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteChecklistTemplateItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteChecklistController extends Controller
{
    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $assignments = SiteChecklistAssignment::where('site_id', $site->id)
            ->with(['template', 'assignedTo:id,name'])
            ->where('is_active', true)
            ->get();

        $templates = SiteChecklistTemplate::active()
            ->forType($site->type)
            ->withCount('items')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'frequency' => $t->frequency,
                'items_count' => $t->items_count,
            ]);

        return inertia('sites/checklists/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
            ],
            'assignments' => $assignments,
            'templates' => $templates,
        ]);
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
        @set_time_limit(120);
        $this->authorize('update', $run->site);

        $validated = $request->validate([
            'responses' => 'required|array|min:1',
            'responses.*.template_item_id' => 'required|integer',
            'responses.*.response_value' => 'nullable|string',
            'responses.*.notes' => 'nullable|string',
            'responses.*.photo_path' => 'nullable|string',
            'responses.*.is_failed' => 'boolean',
            'responses.*.create_hazard' => 'boolean',
            'overall_notes' => 'nullable|string',
            'signature_name' => 'nullable|string',
        ]);

        $this->bulkUpsertResponses($run, $validated['responses']);
        $run->calculateCompletion();

        return redirect()->back();
    }

    public function completeRun(Request $request, SiteChecklistRun $run)
    {
        @set_time_limit(120);
        $this->authorize('update', $run->site);

        // If already completed, short-circuit so duplicate submits (page reloads,
        // double-clicks, retries after a previous timeout) don't redo all the work.
        if ($run->status === 'completed') {
            return redirect()
                ->route('sites.checklists.index', $run->site_id)
                ->with('success', 'Checklist already completed.');
        }

        $validated = $request->validate([
            'responses' => 'required|array|min:1',
            'responses.*.template_item_id' => 'required|integer',
            'responses.*.response_value' => 'nullable|string',
            'responses.*.notes' => 'nullable|string',
            'responses.*.photo_path' => 'nullable|string',
            'responses.*.is_failed' => 'boolean',
            'responses.*.create_hazard' => 'boolean',
            'overall_notes' => 'nullable|string',
            'signature_name' => 'required|string',
        ]);

        DB::transaction(function () use ($run, $validated, $request) {
            $this->bulkUpsertResponses($run, $validated['responses']);

            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by_user_id' => $request->user()->id,
                'overall_notes' => $validated['overall_notes'] ?? null,
            ]);

            $run->calculateCompletion();
        });

        return redirect()
            ->route('sites.checklists.index', $run->site_id)
            ->with('success', 'Checklist completed.');
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

        // Reuse an existing open run for this assignment instead of creating duplicates.
        // Prefer in_progress, then today's scheduled run, then any other scheduled run.
        $existing = SiteChecklistRun::where('assignment_id', $assignment->id)
            ->whereIn('status', ['in_progress', 'scheduled'])
            ->orderByRaw("FIELD(status, 'in_progress', 'scheduled')")
            ->orderByDesc('scheduled_date')
            ->first();

        if ($existing) {
            if ($existing->status === 'scheduled') {
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
