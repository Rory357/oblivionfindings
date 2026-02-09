<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistResponse;
use Illuminate\Http\Request;

class SiteChecklistController extends Controller
{
    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $assignments = SiteChecklistAssignment::where('site_id', $site->id)
            ->with(['template', 'assignedTo:id,name'])
            ->where('is_active', true)
            ->get();

        return inertia('sites/checklists/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
            ],
            'assignments' => $assignments,
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

        return inertia('sites/checklists/showRun', [
            'run' => $run,
        ]);
    }

    public function startRun(Request $request, SiteChecklistRun $run)
    {
        $this->authorize('update', $run->site);

        $run->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        return redirect()->back();
    }

    public function saveResponse(Request $request, SiteChecklistRun $run)
    {
        $this->authorize('update', $run->site);

        $validated = $request->validate([
            'template_item_id' => 'required|exists:site_checklist_template_items,id',
            'response_value' => 'required|string',
            'notes' => 'nullable|string',
            'photo_path' => 'nullable|string',
            'is_failed' => 'boolean',
        ]);

        SiteChecklistResponse::updateOrCreate(
            [
                'run_id' => $run->id,
                'template_item_id' => $validated['template_item_id'],
            ],
            [
                'response_value' => $validated['response_value'],
                'notes' => $validated['notes'] ?? null,
                'photo_path' => $validated['photo_path'] ?? null,
                'is_failed' => $validated['is_failed'] ?? false,
            ]
        );

        // Recalculate completion
        $run->calculateCompletion();

        return redirect()->back();
    }

    public function completeRun(Request $request, SiteChecklistRun $run)
    {
        $this->authorize('update', $run->site);

        $validated = $request->validate([
            'overall_notes' => 'nullable|string',
        ]);

        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by_user_id' => $request->user()->id,
            'overall_notes' => $validated['overall_notes'] ?? null,
        ]);

        // Recalculate final stats
        $run->calculateCompletion();

        return redirect()
            ->route('sites.checklists.index', $run->site_id)
            ->with('success', 'Checklist completed.');
    }
}
