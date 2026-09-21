<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Services\AuditLogger;
use App\Services\Fleet\MaintenanceAccessService;
use App\Services\Fleet\MaintenanceCheckService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChecklistController extends Controller
{
    public function index(Request $request)
    {
        $canManage = $this->canManageMaintenance($request);
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteIds = app(MaintenanceAccessService::class)->approvedSiteIds($actor);

        $templates = FleetChecklistTemplate::query()
            ->withCount(['runs' => fn ($q) => $q->whereHas('asset', fn ($asset) => $asset->whereIn('site_id', $siteIds))])
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
            ->whereHas('asset', fn ($q) => $q->whereNotNull('site_id')->whereIn('site_id', $siteIds))
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
                'outcome' => $r->outcome ?? 'needs_assessment',
                'completed_at' => optional($r->completed_at)->toISOString(),
                'created_at' => optional($r->created_at)->toISOString(),
            ])->values();

        // Counts use the same approved-Site boundary as the rows.
        $since30 = now()->subDays(30);
        $runs = FleetChecklistRun::query()->whereHas('asset',
            fn ($q) => $q->whereNotNull('site_id')->whereIn('site_id', $siteIds));
        $stats = [
            'templates' => $templates->count(),
            'runs_30d' => (clone $runs)->where('created_at', '>=', $since30)->count(),
            'failed_30d' => (clone $runs)->where('created_at', '>=', $since30)->where('outcome', 'failed')->count(),
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
            'items.*.options' => ['nullable', 'array', 'max:50'],
            'items.*.options.*' => ['required', 'string', 'max:100'],
            'items.*.required' => ['boolean'],
        ]);

        $data['items'] = array_map(function (array $item, int $index): array {
            $options = $item['type'] === 'checkbox' ? ['yes', 'no']
                : ($item['type'] === 'select' ? array_map('trim', $item['options'] ?? []) : null);
            if ($item['type'] === 'select' && (! $options || in_array('', $options, true)
                || count($options) !== count(array_unique($options)))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.{$index}.options" => 'Enter at least one distinct, non-empty choice.',
                ]);
            }
            return [...$item, 'id' => \Illuminate\Support\Str::uuid()->toString(), 'options' => $options];
        }, $data['items'], array_keys($data['items']));

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
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteIds = app(MaintenanceAccessService::class)->approvedSiteIds($actor);

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
            ->whereNotNull('site_id')->whereIn('site_id', $siteIds)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'asset_tag', 'site_id', 'category'])
            ->map(function ($asset) {
                $policy = app(\App\Services\Fleet\MaintenancePolicyService::class)
                    ->current((int) $asset->site_id, (string) $asset->category, 'check');
                return [
                    'id' => $asset->id, 'name' => $asset->name, 'asset_tag' => $asset->asset_tag,
                    'approved_template_id' => $policy['rules']['template_id'] ?? null,
                    'rule_version_id' => $policy['id'] ?? null,
                    'policy_questions' => $policy['rules']['questions'] ?? null,
                ];
            });
        $workOrders = $canManage ? \App\Models\FleetWorkOrder::query()
            ->whereHas('asset', fn ($q) => $q->whereIn('site_id', $siteIds))
            ->whereNotIn('status', ['cancelled'])->latest('id')->limit(100)
            ->get(['id', 'asset_id', 'reference_number', 'title'])
            ->map(fn ($order) => [
                'id' => $order->id, 'asset_id' => $order->asset_id,
                'reference_number' => $order->reference_number, 'title' => $order->title,
                'attachments' => \Illuminate\Support\Facades\DB::table('fleet_maintenance_attachments')
                    ->where('work_order_id', $order->id)->orderBy('id')
                    ->get(['id', 'original_name']),
            ]) : collect();

        return Inertia::render('fleet-assets/maintenance/checklists/run', [
            'templates' => $templates,
            'assets' => $assets,
            'work_orders' => $workOrders,
            'selected_template_id' => $request->input('template_id'),
            'selected_asset_id' => $request->input('asset_id'),
            'selected_work_order_id' => $request->input('work_order_id'),
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    public function run(Request $request, FleetChecklistTemplate $template)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer'],
            'results' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'request_key' => ['required', 'string', 'min:8', 'max:100'],
            'rule_version_id' => ['nullable', 'integer'],
            'work_order_id' => ['nullable', 'integer'],
            'source_report_id' => ['nullable', 'integer'],
            'corrects_run_id' => ['nullable', 'integer'],
            'observed_at' => ['nullable', 'date'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimetypes:image/jpeg,image/png,application/pdf'],
        ]);

        $run = app(MaintenanceCheckService::class)->submit($request->user(), [
            ...$data,
            'template_id' => (int) $template->id,
            'check_kind' => 'check',
            'answers' => $data['results'],
        ]);

        return redirect()->route('fleet-assets.inspections.show', $run)->with('success', $run->outcome === 'needs_assessment'
            ? 'Check recorded. Approved rules are needed before it can be marked passed.'
            : 'Check recorded as '.$run->outcome.'.');
    }

    public function evidence(Request $request, FleetChecklistRun $run, string $question)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $access = app(MaintenanceAccessService::class);
        $asset = $access->asset($actor, (int) $run->asset_id);
        abort_unless($access->canManage($actor) || $access->canReview($actor, $asset), 403);
        $file = $run->responses[$question]['evidence_file'] ?? null;
        abort_unless(is_array($file) && isset($file['path'])
            && \Illuminate\Support\Facades\Storage::disk('private')->exists($file['path']), 404);
        return \Illuminate\Support\Facades\Storage::disk('private')->download(
            $file['path'], $file['original_name'], ['Content-Type' => $file['mime_type'], 'X-Content-Type-Options' => 'nosniff']);
    }

    private function canManageMaintenance(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user?->canDo('fleet.manage') || $user?->canDo('fleet.maintenance.manage'));
    }
}
