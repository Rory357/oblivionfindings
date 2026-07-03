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
        $canManage = $this->canManageMaintenance($request);

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

        // Hero band stats — efficient COUNTs over the whole tables
        $since30 = now()->subDays(30);
        $stats = [
            'templates' => FleetChecklistTemplate::count(),
            'runs_30d' => FleetChecklistRun::where('created_at', '>=', $since30)->count(),
            'failed_30d' => FleetChecklistRun::where('created_at', '>=', $since30)
                ->where('passed', false)
                ->count(),
        ];

        return Inertia::render('fleet-assets/maintenance/checklists/index', [
            'templates' => $templates,
            'recent_runs' => $recentRuns,
            'stats' => $stats,
            'can' => [
                'manage' => $canManage,
            ],
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

    public function runPage(Request $request)
    {
        $canManage = $this->canManageMaintenance($request);

        $templates = FleetChecklistTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'type' => $t->type,
                'items' => $t->items,
            ])->values();

        $assets = \App\Models\Asset::query()
            ->where('category', 'vehicle')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'asset_tag']);

        return Inertia::render('fleet-assets/maintenance/checklists/run', [
            'templates' => $templates,
            'assets' => $assets,
            'selected_template_id' => $request->input('template_id'),
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    public function run(Request $request, FleetChecklistTemplate $template)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'results' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // Determine pass/fail from required items
        $passed = true;
        $templateItems = collect($template->items ?? []);
        foreach ($templateItems as $index => $item) {
            if (!empty($item['required'])) {
                $response = $data['results'][$index] ?? $data['results'][$item['label'] ?? ''] ?? null;
                if ($response === null || $response === '' || $response === false) {
                    $passed = false;
                    break;
                }
            }
        }

        $run = FleetChecklistRun::create([
            'template_id' => $template->id,
            'asset_id' => $data['asset_id'],
            'user_id' => $request->user()->id,
            'responses' => $data['results'],
            'notes' => $data['notes'] ?? null,
            'passed' => $passed,
            'completed_at' => now(),
        ]);

        AuditLogger::log('fleet.checklist.run', $run, [
            'template_id' => $template->id,
            'asset_id' => $data['asset_id'],
        ]);

        return back()->with('success', 'Checklist completed.');
    }

    private function canManageMaintenance(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user?->canDo('fleet.manage') || $user?->canDo('fleet.maintenance.manage'));
    }
}
