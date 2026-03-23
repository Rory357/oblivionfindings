<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChecklistController extends Controller
{
    public function index(Request $request)
    {
        $templates = FleetChecklistTemplate::query()
            ->withCount('runs')
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'type' => $t->type,
                'items' => $t->items,
                'is_active' => $t->is_active,
                'runs_count' => $t->runs_count,
                'created_at' => optional($t->created_at)->toISOString(),
            ])->values();

        $recentRuns = FleetChecklistRun::query()
            ->with(['template:id,name', 'asset:id,name,asset_tag', 'user:id,name'])
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'template' => $r->template ? ['id' => $r->template->id, 'name' => $r->template->name] : null,
                'asset' => $r->asset ? ['id' => $r->asset->id, 'name' => $r->asset->name, 'asset_tag' => $r->asset->asset_tag] : null,
                'user' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
                'passed' => $r->passed,
                'responses' => $r->responses,
                'completed_at' => optional($r->completed_at)->toISOString(),
                'created_at' => optional($r->created_at)->toISOString(),
            ])->values();

        return Inertia::render('fleet-assets/maintenance/checklists/index', [
            'templates' => $templates,
            'recent_runs' => $recentRuns,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.label' => ['required', 'string', 'max:255'],
            'items.*.type' => ['required', 'string', 'in:checkbox,text,number,select'],
            'items.*.options' => ['nullable', 'array'],
            'items.*.required' => ['boolean'],
        ]);

        $template = FleetChecklistTemplate::create([
            'name' => $data['name'],
            'type' => 'custom',
            'items' => $data['items'],
        ]);

        AuditLogger::log('fleet.checklist_template.create', $template, [
            'name' => $data['name'],
        ]);

        return back()->with('success', 'Checklist template created.');
    }

    public function run(Request $request, FleetChecklistTemplate $template)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'results' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $run = FleetChecklistRun::create([
            'template_id' => $template->id,
            'asset_id' => $data['asset_id'],
            'user_id' => $request->user()->id,
            'responses' => $data['results'],
            'notes' => $data['notes'] ?? null,
            'passed' => true,
            'completed_at' => now(),
        ]);

        AuditLogger::log('fleet.checklist.run', $run, [
            'template_id' => $template->id,
            'asset_id' => $data['asset_id'],
        ]);

        return back()->with('success', 'Checklist completed.');
    }
}
