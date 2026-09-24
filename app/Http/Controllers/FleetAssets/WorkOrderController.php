<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetWorkOrder;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\MaintenanceAccessService;
use App\Services\Fleet\MaintenanceReportService;
use App\Services\Fleet\MaintenanceCheckService;
use App\Services\Fleet\MaintenanceFinanceService;
use App\Services\Fleet\MaintenancePolicyService;
use App\Services\Fleet\MaintenanceTransitionService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WorkOrderController extends Controller
{
    public function __construct(
        private readonly ?UserSiteAccessService $siteAccess = null,
        private readonly ?MaintenanceAccessService $maintenanceAccess = null,
        private readonly ?MaintenanceReportService $reports = null,
        private readonly ?MaintenanceTransitionService $transitions = null,
    ) {}

    private function siteAccessService(): UserSiteAccessService
    {
        return $this->siteAccess ?? app(UserSiteAccessService::class);
    }

    private function access(): MaintenanceAccessService
    {
        return $this->maintenanceAccess ?? app(MaintenanceAccessService::class);
    }

    private function reportService(): MaintenanceReportService
    {
        return $this->reports ?? app(MaintenanceReportService::class);
    }

    private function transitionService(): MaintenanceTransitionService
    {
        return $this->transitions ?? app(MaintenanceTransitionService::class);
    }

    public function index(Request $request)
    {
        $siteService = $this->siteAccessService();
        $user = $request->user();
        abort_unless($user, 403);
        $access = $this->access();
        $siteIds = $access->approvedSiteIds($user);

        $query = $access->scopedWorkOrders($user)
            ->with(['asset:id,name,asset_tag,site_id,category', 'asset.site:id,name', 'reportedBy:id,name', 'assignedTo:id,name']);

        // CSV export
        if ($request->input('export') === 'csv') {
            $exportQuery = (clone $query)->latest();
            return response()->streamDownload(function () use ($exportQuery) {
                $handle = fopen('php://output', 'w');
                $this->putCsv($handle, ['Title', 'Asset', 'Priority', 'Status', 'Assigned To', 'Due Date', 'Created']);
                foreach ($exportQuery->lazy(200) as $wo) {
                    $this->putCsv($handle, [
                        $wo->title, $wo->asset?->name ?? '', $wo->priority, $wo->status,
                        $wo->assignedTo?->name ?? '', optional($wo->due_at)->format('Y-m-d') ?? '',
                        optional($wo->created_at)->format('Y-m-d') ?? '',
                    ]);
                }
                fclose($handle);
            }, 'work-orders-export.csv');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Hero tile drill-down: open work past its due date (mirrors the
        // hero's overdue count definition below).
        if ($request->input('overdue') === '1') {
            $query->whereNotIn('status', ['completed', 'cancelled'])
                ->whereNotNull('due_at')
                ->where('due_at', '<', now());
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('asset_id')) {
            $query->where('asset_id', (int) $request->input('asset_id'));
        }
        if ($request->filled('site_id')) {
            $selectedSite = (int) $request->input('site_id');
            abort_unless(in_array($selectedSite, $siteIds, true), 404);
            $query->whereHas('asset', fn ($assets) => $assets->where('site_id', $selectedSite));
        }
        if ($request->filled('q')) {
            $term = trim((string) $request->input('q'));
            abort_if(mb_strlen($term) > 100, 422);
            $query->where(fn ($work) => $work->where('title', 'like', "%{$term}%")
                ->orWhere('reference_number', 'like', "%{$term}%")
                ->orWhereHas('asset', fn ($assets) => $assets->where('name', 'like', "%{$term}%")
                    ->orWhere('asset_tag', 'like', "%{$term}%"))
                ->orWhereHas('assignedTo', fn ($staff) => $staff->where('name', 'like', "%{$term}%")));
        }
        $view = (string) $request->input('view', 'all');
        abort_unless(in_array($view, ['all', 'mine', 'holds', 'release', 'closed', 'unassigned'], true), 422);
        $heldAssetIds = DB::table('fleet_maintenance_restrictions')->where('state', 'active')->select('asset_id');
        match ($view) {
            'mine' => $query->where('assigned_to_user_id', $user->id)->whereNotIn('status', ['completed', 'cancelled']),
            'holds' => $query->whereIn('asset_id', clone $heldAssetIds),
            'release' => $query->where('status', 'completed')->whereIn('asset_id', clone $heldAssetIds),
            'closed' => $query->where('status', 'completed'),
            'unassigned' => $query->whereNull('assigned_to_user_id'),
            default => null,
        };

        // Sorting
        $allowedSorts = ['created_at', 'priority', 'status', 'title', 'due_at'];
        $sort = $request->input('sort', 'created_at');
        $direction = $request->input('direction', 'desc');
        if (!in_array($sort, $allowedSorts)) $sort = 'created_at';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'desc';

        $workOrders = $query->reorder()->orderBy($sort, $direction)->paginate(25)->withQueryString();
        $visibleAssetIds = $workOrders->getCollection()->pluck('asset_id')->filter()->all();
        $activeHoldAssetIds = DB::table('fleet_maintenance_restrictions')
            ->where('state', 'active')->whereIn('asset_id', $visibleAssetIds)
            ->pluck('asset_id')->map(fn ($id) => (int) $id)->all();

        $users = $siteService->applyStaffScope(User::query(), $user, ['sites.viewAll'])
            ->orderBy('name')->limit(20)->get(['id', 'name']);

        // Hero band stats — whole-table counts scoped to accessible sites
        $statsBase = $access->scopedWorkOrders($user);

        $stats = [
            'all' => (clone $statsBase)->count(),
            'mine' => (clone $statsBase)->where('assigned_to_user_id', $user->id)
                ->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'holds' => (clone $statsBase)->whereIn('asset_id', clone $heldAssetIds)->count(),
            'release' => (clone $statsBase)->where('status', 'completed')->whereIn('asset_id', clone $heldAssetIds)->count(),
            'closed' => (clone $statsBase)->where('status', 'completed')->count(),
            'unassigned' => (clone $statsBase)->whereNull('assigned_to_user_id')->count(),
            'open' => (clone $statsBase)->where('status', 'open')->count(),
            'overdue' => (clone $statsBase)->whereNotIn('status', ['completed', 'cancelled'])
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->count(),
            'in_progress' => (clone $statsBase)->where('status', 'in_progress')->count(),
            'completed_30d' => (clone $statsBase)->where('status', 'completed')
                ->where('completed_at', '>=', now()->subDays(30))
                ->count(),
        ];

        // Create-wizard options (modal lives on this page — ?new=1 shim)
        $assetsQuery = Asset::query()->whereNotNull('site_id')->whereIn('site_id', $siteIds)->orderBy('name');
        $assets = $assetsQuery->limit(20)->get(['id', 'name', 'asset_tag', 'category']);
        $selectedAssetId = $request->integer('asset_id');
        if ($selectedAssetId && ! $assets->contains('id', $selectedAssetId)) {
            $selectedAsset = Asset::query()->whereIn('site_id', $siteIds)
                ->find($selectedAssetId, ['id', 'name', 'asset_tag', 'category']);
            if ($selectedAsset) {
                $assets->prepend($selectedAsset);
            }
        }
        $checklistRuns = \App\Models\FleetChecklistRun::query()
            ->where(fn ($q) => $q->where('passed', false)->orWhere('outcome', 'needs_assessment')
                ->orWhere('id', $request->integer('checklist_run_id')))
            ->whereHas('asset', fn ($q) => $q->whereNotNull('site_id')->whereIn('site_id', $siteIds))
            ->with('asset:id,name', 'template:id,name')
            ->orderByRaw('id = ? desc', [$request->integer('checklist_run_id')])->latest()
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'asset_id' => $r->asset_id,
                'asset_name' => $r->asset?->name ?? 'Unknown',
                'template_name' => $r->template?->name ?? 'Unknown',
                'run_at' => optional($r->created_at)->toISOString(),
            ])
            ->values();

        if ($request->integer('checklist_run_id')) {
            $selectedRun = $checklistRuns->firstWhere('id', $request->integer('checklist_run_id'));
            abort_unless($selectedRun && (! $request->integer('asset_id')
                || (int) $selectedRun['asset_id'] === $request->integer('asset_id')), 404);
        }

        return Inertia::render('fleet-assets/maintenance/work-orders/index', [
            'work_orders' => [
                'data' => $workOrders->getCollection()->map(fn ($wo) => [
                    'id' => $wo->id,
                    'reference_number' => $wo->reference_number,
                    'title' => $wo->title,
                    'status' => $wo->status,
                    'priority' => $wo->priority,
                    'next_action' => $wo->next_action,
                    'waiting_reason' => $wo->waiting_reason,
                    'active_hold' => in_array((int) $wo->asset_id, $activeHoldAssetIds, true),
                    'asset' => $wo->asset ? ['id' => $wo->asset->id, 'name' => $wo->asset->name,
                        'asset_tag' => $wo->asset->asset_tag, 'category' => $wo->asset->category,
                        'site_id' => $wo->asset->site_id, 'site_name' => $wo->asset->site?->name] : null,
                    'reported_by' => $wo->reportedBy ? ['id' => $wo->reportedBy->id, 'name' => $wo->reportedBy->name] : null,
                    'assigned_to' => $wo->assignedTo ? ['id' => $wo->assignedTo->id, 'name' => $wo->assignedTo->name] : null,
                    'due_at' => optional($wo->due_at)->toISOString(),
                    'created_at' => optional($wo->created_at)->toISOString(),
                ])->values(),
                'links' => $workOrders->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $workOrders->currentPage(),
                    'last_page' => $workOrders->lastPage(),
                    'total' => $workOrders->total(),
                ],
            ],
            'filters' => $request->only(['status', 'priority', 'asset_id', 'overdue', 'site_id', 'q', 'view']),
            'site_options' => Site::query()->whereIn('id', $siteIds)->orderBy('name')->get(['id', 'name']),
            'current_user_id' => $user->id,
            'can' => ['report' => $access->canReport($user), 'manage' => $access->canManage($user)],
            'users' => $users,
            'stats' => $stats,
            'assets' => $assets,
            'checklist_runs' => $checklistRuns,
            'prefill_asset_id' => $request->input('asset_id'),
            'prefill_checklist_run_id' => $request->input('checklist_run_id'),
            'prefill_existing_work_order_id' => $request->input('work_order_id'),
            'prefill_corrects_report_id' => $request->input('corrects_report_id'),
        ]);
    }

    /** Legacy full-page create — the wizard now lives on the index as a modal (?new=1 shim). */
    public function create(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor && $this->access()->canReport($actor), 403);
        if (! $this->access()->canRead($actor)) {
            $siteIds = $this->access()->approvedSiteIds($actor);
            $assets = Asset::query()->whereNotNull('site_id')->whereIn('site_id', $siteIds)
                ->orderBy('name')->limit(20)->get(['id', 'name', 'asset_tag', 'category', 'site_id', 'registration_number', 'location']);
            $selected = $request->integer('asset_id');
            if ($selected && ! $assets->contains('id', $selected)) {
                $asset = Asset::query()->whereKey($selected)->whereIn('site_id', $siteIds)
                    ->first(['id', 'name', 'asset_tag', 'category', 'site_id', 'registration_number', 'location']);
                if ($asset) {
                    $assets->prepend($asset);
                }
            }

            return Inertia::render('fleet-assets/maintenance/work-orders/report', [
                'assets' => $assets,
                'prefill_asset_id' => $selected ?: null,
            ]);
        }

        return redirect()->to('/fleet-assets/maintenance/work-orders?' . http_build_query(array_filter([
            'new' => 1,
            'asset_id' => $request->input('asset_id'),
            'checklist_run_id' => $request->input('checklist_run_id'),
            'work_order_id' => $request->input('work_order_id'),
            'corrects_report_id' => $request->input('corrects_report_id'),
        ])));
    }

    public function searchOptions(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor && $this->access()->canReport($actor), 403);
        $data = $request->validate([
            'type' => ['required', 'in:assets,users,work_orders,finance_bills'],
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'asset_id' => ['nullable', 'integer'],
        ]);
        abort_unless($data['type'] === 'assets' || $this->access()->canManage($actor), 403);
        if ($data['type'] === 'finance_bills') {
            abort_unless($actor->canDo('finance.ap.view'), 403);
        }

        $term = $data['q'];
        $siteIds = $this->access()->approvedSiteIds($actor);
        if ($data['type'] === 'finance_bills') {
            abort_unless(! empty($data['asset_id']), 422);
            $asset = $this->access()->asset($actor, (int) $data['asset_id']);
            $results = \App\Domain\Finance\Models\FinBill::query()
                ->where('asset_id', $asset->id)->where('site_id', $asset->site_id)
                ->whereNotIn('id', DB::table('fleet_maintenance_fin_bill_links')->select('fin_bill_id'))
                ->where('bill_number', 'like', "%{$term}%")
                ->orderByDesc('id')->limit(20)->get(['id', 'bill_number', 'status']);
        } elseif ($data['type'] === 'work_orders') {
            abort_unless(! empty($data['asset_id']), 422);
            $asset = $this->access()->asset($actor, (int) $data['asset_id']);
            $results = FleetWorkOrder::query()->where('asset_id', $asset->id)
                ->where(fn ($query) => $query->where('title', 'like', "%{$term}%")
                    ->orWhere('reference_number', 'like', "%{$term}%"))
                ->orderByDesc('id')->limit(20)->get(['id', 'reference_number', 'title', 'status']);
        } elseif ($data['type'] === 'assets') {
            $results = Asset::query()
                ->whereNotNull('site_id')->whereIn('site_id', $siteIds)
                ->where(fn ($query) => $query
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('asset_tag', 'like', "%{$term}%")
                    ->orWhere('registration_number', 'like', "%{$term}%")
                    ->orWhere('location', 'like', "%{$term}%"))
                ->orderBy('name')
                ->limit(20)
                ->get(['id', 'name', 'asset_tag', 'category', 'site_id', 'registration_number', 'location']);
        } else {
            $results = $this->siteAccessService()->applyStaffScope(User::query(), $actor, ['sites.viewAll'])
                ->where('name', 'like', "%{$term}%")
                ->orderBy('name')
                ->limit(20)
                ->get(['id', 'name']);
        }

        return response()->json(['results' => $results]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor && $this->access()->canReport($actor), 403);
        if ($request->filled('asset_id')) {
            $this->access()->asset($actor, (int) $request->input('asset_id'));
        }
        $data = $request->validate([
            'asset_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', 'string', 'in:low,medium,high,critical'],
            'observed_at' => ['nullable', 'date'],
            'observed_local' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/'],
            'observed_offset' => ['nullable', 'string', 'regex:/^[+-]\d{2}:\d{2}$/'],
            'estimated_start_date' => ['nullable', 'date_format:Y-m-d', 'required_with:estimated_end_date'],
            'estimated_end_date' => ['nullable', 'date_format:Y-m-d', 'required_with:estimated_start_date', 'after_or_equal:estimated_start_date'],
            'source_type' => ['nullable', 'string', 'in:fleet_checklist_run'],
            'source_id' => ['nullable', 'integer', 'required_with:source_type'],
            'existing_work_order_id' => ['nullable', 'integer'],
            'corrects_report_id' => ['nullable', 'integer'],
            'request_key' => ['required', 'string', 'min:8', 'max:100'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*.file' => ['required', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,application/pdf'],
            'files.*.category' => ['nullable', 'string', 'max:64'],
            'files.*.description' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! empty($data['observed_local'])) {
            try {
                $data['observed_at'] = \App\Services\Fleet\MaintenanceLocalTime::toUtc(
                    $data['observed_local'], $data['observed_offset'] ?? null);
            } catch (\Illuminate\Validation\ValidationException $error) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'observed_local' => $error->errors()['time'][0] ?? 'Choose an unambiguous Auckland date and time.',
                ]);
            }
        }
        unset($data['observed_local'], $data['observed_offset']);
        $files = $data['files'] ?? [];
        unset($data['files']);

        $workOrder = $this->reportService()->submit($actor, $data);
        if ($files !== []) {
            $reportId = DB::table('fleet_maintenance_reports')
                ->where('submitted_by_user_id', $actor->id)
                ->where('request_key', $data['request_key'])
                ->where('work_order_id', $workOrder->id)->value('id');
            abort_unless($reportId, 404);
            foreach ($files as $index => $item) {
                app(\App\Services\Fleet\MaintenanceAttachmentService::class)->upload(
                    $actor, (int) $workOrder->id, 'report', (int) $reportId,
                    'report-file-'.substr(hash('sha256', $data['request_key'].':'.$index), 0, 40),
                    $item['file'], $item['category'] ?? null, $item['description'] ?? null,
                );
            }
        }

        if ($this->access()->canRead($actor)) {
            return redirect()->route('fleet-assets.work-orders.show', $workOrder)
                ->with('success', 'Report saved as '.$workOrder->reference_number.'.');
        }

        return back()->with('success', 'Report saved as '.$workOrder->reference_number.'. The site Coordinator will assess it.');
    }

    public function show(Request $request, FleetWorkOrder $workOrder)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $workOrder = $this->access()->workOrder($user, (int) $workOrder->id);

        $workOrder->load([
            'asset:id,name,asset_tag,category,status,site_id,registration_number',
            'reportedBy:id,name,email',
            'assignedTo:id,name,email',
        ]);

        $asset = $workOrder->asset;
        abort_unless($asset, 404);
        $canManage = $this->access()->canManage($user);
        $canReview = $this->access()->canReview($user, $asset);
        $canCustody = $this->access()->isCurrentCustodyParticipant($user, (int) $workOrder->id);
        $canDetails = $this->access()->canRead($user) || $canManage || $canReview;
        $actions = DB::table('fleet_maintenance_actions as action')
            ->join('users as actor', 'actor.id', '=', 'action.actor_user_id')
            ->leftJoin('users as target', 'target.id', '=', 'action.target_user_id')
            ->where('action.work_order_id', $workOrder->id)
            ->when(! $canManage && ! $canReview, fn ($query) => $query
                ->whereIn('action.action_type', ['propose_handover', 'accept_handover',
                    'propose_custody', 'acknowledge_custody']))
            ->when($canCustody && ! $canManage && ! $canReview && ! $this->access()->canRead($user),
                fn ($query) => $query->whereIn('action.action_type', ['propose_custody', 'acknowledge_custody']))
            ->orderBy('action.id')
            ->get(['action.id', 'action.action_type', 'action.actor_user_id', 'action.policy_version_id',
                'actor.name as actor_name', 'action.target_user_id', 'target.name as target_name',
                'action.payload_json', 'action.occurred_at', 'action.resulting_version'])
            ->map(fn ($row) => [
                'id' => (int) $row->id, 'type' => $row->action_type,
                'actor_id' => (int) $row->actor_user_id, 'actor_name' => $row->actor_name,
                'target_id' => $row->target_user_id ? (int) $row->target_user_id : null,
                'target_name' => $row->target_name,
                'policy_version_id' => $row->policy_version_id ? (int) $row->policy_version_id : null,
                'payload' => json_decode((string) $row->payload_json, true) ?: [],
                'occurred_at' => \Illuminate\Support\Carbon::parse($row->occurred_at, 'UTC')->toISOString(),
                'version' => (int) $row->resulting_version,
            ]);
        $linkedCheckIds = DB::table('fleet_maintenance_reports')->where('work_order_id', $workOrder->id)
            ->where('source_type', 'fleet_checklist_run')->whereNotNull('source_id')->pluck('source_id');
        $checkRows = DB::table('fleet_checklist_runs')->where('asset_id', $asset->id)
            ->where(fn ($query) => $query->where('work_order_id', $workOrder->id)->orWhereIn('id', $linkedCheckIds))
            ->orderBy('id')->get(['id', 'check_kind', 'outcome', 'submitted_at',
                'corrects_run_id', 'presented_template_json', 'rule_version_id']);
        // Maintenance's "no issue found — release for use" decision on a check,
        // shown beside its original outcome (which never changes).
        $checkAssessments = $checkRows->isEmpty() || ! \Illuminate\Support\Facades\Schema::hasTable('fleet_maintenance_check_assessments')
            ? collect()
            : DB::table('fleet_maintenance_check_assessments as assessment')
                ->leftJoin('users as assessor', 'assessor.id', '=', 'assessment.assessed_by_user_id')
                ->whereIn('assessment.check_run_id', $checkRows->pluck('id'))
                ->get(['assessment.check_run_id', 'assessment.decision', 'assessment.reason',
                    'assessment.assessed_at', 'assessor.name as assessed_by'])
                ->keyBy('check_run_id');
        $checks = $checkRows->map(fn ($row) => [
                'id' => (int) $row->id, 'kind' => $row->check_kind,
                'outcome' => $row->outcome ?? 'needs_assessment',
                'submitted_at' => $row->submitted_at
                    ? \Illuminate\Support\Carbon::parse($row->submitted_at, 'UTC')->toISOString() : null,
                'corrects_run_id' => $row->corrects_run_id,
                'presented_template' => json_decode((string) $row->presented_template_json, true),
                'rule_version_id' => $row->rule_version_id,
                'assessment' => ($assessment = $checkAssessments->get($row->id)) ? [
                    'decision' => (string) $assessment->decision,
                    'label' => \App\Services\Fleet\VehicleChecksPresenter::NO_ISSUE_LABEL,
                    'reason' => (string) $assessment->reason,
                    'assessed_by' => $assessment->assessed_by,
                    'assessed_at' => \Illuminate\Support\Carbon::parse($assessment->assessed_at, 'UTC')->toISOString(),
                ] : null,
            ]);
        $reports = DB::table('fleet_maintenance_reports as report')
            ->join('users as reporter', 'reporter.id', '=', 'report.submitted_by_user_id')
            ->where('report.work_order_id', $workOrder->id)->orderBy('report.id')
            ->get(['report.id', 'report.title', 'report.description', 'report.submitted_at',
                'report.estimated_start_date', 'report.estimated_end_date',
                'report.observed_at', 'reporter.name as reporter_name', 'report.source_type', 'report.source_id',
                'report.corrects_report_id'])
            ->map(function ($row): array {
                $data = (array) $row;
                $data['submitted_at'] = \Illuminate\Support\Carbon::parse($row->submitted_at, 'UTC')->toISOString();
                $data['observed_at'] = $row->observed_at
                    ? \Illuminate\Support\Carbon::parse($row->observed_at, 'UTC')->toISOString() : null;

                return $data;
            });
        $restrictions = DB::table('fleet_maintenance_restrictions')
            ->where('work_order_id', $workOrder->id)->orderBy('id')
            ->get(['id', 'restriction_kind', 'state', 'created_at', 'released_at']);
        $assetRestrictionIds = DB::table('fleet_maintenance_restrictions')
            ->where('asset_id', $asset->id)->where('state', 'active')->orderBy('id')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $bookingImpacts = ($canManage || $canReview)
            ? DB::table('fleet_maintenance_booking_impacts as impact')
                ->join('fleet_vehicle_bookings as booking', 'booking.id', '=', 'impact.booking_id')
                ->join('users as owner', 'owner.id', '=', 'impact.owner_user_id')
                ->where('impact.work_order_id', $workOrder->id)
                ->where('impact.asset_id', $asset->id)
                ->orderBy('impact.id')
                ->get(['impact.id', 'impact.booking_id', 'impact.followup_state',
                    'impact.source_released_at', 'booking.reference_number', 'booking.status as booking_status',
                    'booking.starts_at', 'booking.ends_at', 'owner.name as owner_name', 'impact.owner_user_id'])
            : collect();
        if (! $canDetails) {
            $reports = collect();
            $checks = $checks->filter(fn ($check) => $check['kind'] === 'retest')->take(-1)
                ->map(fn ($check) => [...$check, 'presented_template' => null])->values();
        }
        $attachments = ($canManage || $canReview)
            ? DB::table('fleet_maintenance_attachments')->where('work_order_id', $workOrder->id)
                ->orderBy('id')->get(['id', 'report_id', 'check_run_id', 'action_id',
                    'original_name', 'mime_type', 'byte_size', 'created_at'])
            : collect();
        $policy = app(MaintenancePolicyService::class);
        $retest = $policy->current((int) $asset->site_id, (string) $asset->category, 'retest');
        $repairPolicy = $policy->current((int) $asset->site_id, (string) $asset->category, 'repair');
        $checkPolicy = $policy->current((int) $asset->site_id, (string) $asset->category, 'check');
        $holdPolicy = $policy->current((int) $asset->site_id, (string) $asset->category, 'hold');
        $templateId = (int) ($retest['rules']['template_id'] ?? 0);
        $template = $templateId ? \App\Models\FleetChecklistTemplate::query()
            ->whereKey($templateId)->first(['id', 'name', 'items']) : null;
        $checkTemplateId = (int) ($checkPolicy['rules']['template_id'] ?? 0);
        $checkTemplate = $checkTemplateId ? \App\Models\FleetChecklistTemplate::query()
            ->whereKey($checkTemplateId)->first(['id', 'name', 'items']) : null;
        $taskInHorizon = $workOrder->due_at && $workOrder->due_at->lessThanOrEqualTo(now()->addDays(7));
        $taskLink = $taskInHorizon && $this->access()->canRead($user)
            ? '/tasks?sources=fleet_maintenance&q='.rawurlencode($workOrder->reference_number ?? 'WO-'.$workOrder->id)
                .(in_array($workOrder->status, ['completed', 'cancelled'], true) ? '&done=1' : '')
            : null;
        $latestRepair = DB::table('fleet_maintenance_actions')->where('work_order_id', $workOrder->id)
            ->where('action_type', 'attest_repair')->orderByDesc('id')->first(['id', 'policy_version_id']);
        $repairEvidenceSaved = $latestRepair && DB::table('fleet_maintenance_attachments')
            ->where('work_order_id', $workOrder->id)->where('action_id', $latestRepair->id)->exists();
        $latestRetest = DB::table('fleet_checklist_runs')->where('work_order_id', $workOrder->id)
            ->where('check_kind', 'retest')->orderByDesc('id')->first(['outcome', 'rule_version_id', 'covered_restriction_ids_json']);
        $retestCoverageCurrent = $latestRetest && $retest
            && (int) $latestRetest->rule_version_id === (int) $retest['id']
            && json_decode((string) $latestRetest->covered_restriction_ids_json, true) === $assetRestrictionIds;

        return Inertia::render('fleet-assets/maintenance/work-orders/show', [
            // Never serialize the model: legacy costs, internal notes and future
            // columns are not part of the work-detail disclosure contract.
            'work_order' => [
                'id' => (int) $workOrder->id,
                'reference_number' => $workOrder->reference_number,
                'title' => $canDetails ? $workOrder->title : 'Maintenance handover',
                'description' => $canDetails ? $workOrder->description : null,
                'status' => $workOrder->status, 'priority' => $workOrder->priority,
                'version' => (int) $workOrder->version,
                'waiting_reason' => $canDetails ? $workOrder->waiting_reason : null,
                'next_action' => $canDetails ? $workOrder->next_action : null,
                'due_at' => $canDetails ? $workOrder->due_at?->toISOString() : null,
                'created_at' => $workOrder->created_at?->toISOString(),
                'asset' => $asset->only(['id', 'name', 'asset_tag', 'category', 'site_id', 'registration_number']),
                'reported_by' => $canDetails ? $workOrder->reportedBy?->only(['id', 'name']) : null,
                'assigned_to' => $workOrder->assignedTo?->only(['id', 'name']),
            ],
            'actions' => $actions,
            'checks' => $checks,
            'reports' => $reports,
            'restrictions' => $restrictions,
            'asset_active_restriction_ids' => $assetRestrictionIds,
            'booking_impacts' => $bookingImpacts,
            'attachments' => $attachments,
            'finance' => $canDetails
                ? app(MaintenanceFinanceService::class)->projection($user, (int) $workOrder->id) : [],
            'release_policy' => $policy->current((int) $asset->site_id, (string) $asset->category, 'release'),
            'repair_policy' => $canManage || $canReview ? $repairPolicy : null,
            'release_readiness' => [
                'repair_attested' => (bool) $latestRepair,
                'repair_evidence_saved' => (bool) $repairEvidenceSaved,
                'repair_rule_current' => (bool) ($latestRepair && $repairPolicy
                    && (int) $latestRepair->policy_version_id === (int) $repairPolicy['id']),
                'retest_outcome' => $latestRetest?->outcome,
                'retest_coverage_current' => (bool) $retestCoverageCurrent,
            ],
            'retest_policy' => $canManage || $canReview ? $retest : null,
            'retest_template' => $canManage || $canReview ? $template : null,
            'check_policy' => $canManage ? $checkPolicy : null,
            'check_template' => $canManage ? $checkTemplate : null,
            'hold_policy' => $canManage ? $holdPolicy : null,
            'task_link' => $taskLink,
            'task_scope_message' => $taskLink ? null : ($workOrder->due_at
                ? 'This work appears in All Tasks only within seven days of its target, subject to your permissions.'
                : 'Set a target date to make this work eligible for All Tasks within seven days.'),
            'current_user_id' => (int) $user->id,
            'can' => ['manage' => $canManage, 'review' => $canReview, 'custody' => $canCustody,
                'details' => $canDetails,
                'calendar' => $user->canDo('sites.viewAny') && $user->canDo('calendar.view'),
                'finance_view' => $user->canDo('finance.ap.view'),
                'finance_link' => $canManage && $user->canDo('finance.ap.view')],
        ]);
    }

    public function update(Request $request, FleetWorkOrder $workOrder)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $data = $request->validate([
            'operation' => ['required', 'string', 'in:start,hold,resume,complete,cancel,update_next_action,note,propose_handover,accept_handover,place_restriction,review_booking_impact,attest_repair,plan_provider,record_provider_confirmation,record_provider_cancellation,record_provider_completion,propose_custody,acknowledge_custody,release'],
            'version' => ['required', 'integer', 'min:0'],
            'request_key' => ['required', 'string', 'min:8', 'max:100'],
            'waiting_reason' => ['nullable', 'string', 'max:64'],
            'next_action' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
            'due_local' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/'],
            'due_offset' => ['nullable', 'string', 'regex:/^[+-]\d{2}:\d{2}$/'],
            'note' => ['nullable', 'string', 'max:5000'],
            'target_user_id' => ['nullable', 'integer'],
            'restriction_kind' => ['nullable', 'string', 'max:32'],
            'source_run_id' => ['nullable', 'integer'],
            'booking_impact_id' => ['nullable', 'integer'],
            'review_note' => ['nullable', 'string', 'max:2000'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'received' => ['nullable', 'boolean'],
            'provider_name' => ['nullable', 'string', 'max:255'],
            'starts_local' => ['nullable', 'string', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/'],
            'ends_local' => ['nullable', 'string', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/'],
            'starts_offset' => ['nullable', 'string', 'regex:/^[+-]\\d{2}:\\d{2}$/'],
            'ends_offset' => ['nullable', 'string', 'regex:/^[+-]\\d{2}:\\d{2}$/'],
            'response_method' => ['nullable', 'string', 'max:100'],
            'provider_reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'service_summary' => ['nullable', 'string', 'max:5000'],
        ]);

        $payload = collect($data)->only(['waiting_reason', 'next_action', 'due_at', 'due_local', 'due_offset', 'note', 'target_user_id',
            'restriction_kind', 'source_run_id', 'booking_impact_id', 'review_note', 'summary', 'received', 'provider_name', 'starts_local', 'ends_local',
            'starts_offset', 'ends_offset',
            'response_method', 'provider_reference', 'reason', 'service_summary'])->all();
        $this->transitionService()->execute($actor, (int) $workOrder->id,
            $data['operation'], (int) $data['version'], $data['request_key'], $payload);

        return back()->with('success', 'Work order updated.');
    }

    public function retest(Request $request, FleetWorkOrder $workOrder)
    {
        return $this->submitWorkCheck($request, $workOrder, 'retest');
    }

    public function check(Request $request, FleetWorkOrder $workOrder)
    {
        return $this->submitWorkCheck($request, $workOrder, 'check');
    }

    private function submitWorkCheck(Request $request, FleetWorkOrder $workOrder, string $kind)
    {
        $actor = $request->user();
        abort_unless($actor && $this->access()->canManage($actor), 403);
        $workOrder = $this->access()->workOrder($actor, (int) $workOrder->id);
        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'rule_version_id' => ['required', 'integer'],
            'answers' => ['required', 'array'],
            'covered_restriction_ids' => [$kind === 'retest' ? 'required' : 'nullable', 'array'],
            'covered_restriction_ids.*' => ['integer', 'distinct'],
            'request_key' => ['required', 'string', 'min:8', 'max:100'],
            'corrects_run_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $run = app(MaintenanceCheckService::class)->submit($actor, [
            ...$data, 'asset_id' => (int) $workOrder->asset_id,
            'work_order_id' => (int) $workOrder->id, 'check_kind' => $kind,
        ]);

        return back()->with('success', $kind === 'retest'
            ? ($run->outcome === 'passed' ? 'Passing retest saved for independent review.'
                : 'Retest saved as '.$run->outcome.'. Release remains blocked.')
            : 'Check saved as '.$run->outcome.'.');
    }

    public function linkBill(Request $request, FleetWorkOrder $workOrder)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $data = $request->validate(['fin_bill_id' => ['required', 'integer']]);
        app(MaintenanceFinanceService::class)->linkBill($actor, (int) $workOrder->id,
            (int) $data['fin_bill_id']);

        return back()->with('success', 'Finance bill linked. Finance retains approval and posting authority.');
    }

    public function bulkAction(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor && $this->access()->canManage($actor), 403);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:complete,in_progress,assign'],
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'integer', 'distinct'],
            'versions' => ['required', 'array'],
            'versions.*' => ['required', 'integer', 'min:0'],
            'request_keys' => ['required', 'array'],
            'request_keys.*' => ['required', 'string', 'min:8', 'max:100'],
            'assigned_to_user_id' => ['required_if:action,assign', 'nullable', 'integer'],
        ]);

        // Acquire locks in the same asset-then-work order as single commands.
        // One outer transaction keeps the batch all-or-nothing.
        $ordered = FleetWorkOrder::query()->whereIn('id', $data['ids'])
            ->get(['id', 'asset_id'])->sortBy([['asset_id', 'asc'], ['id', 'asc']]);
        abort_unless($ordered->count() === count($data['ids']), 404);
        DB::transaction(function () use ($actor, $ordered, $data): void {
            foreach ($ordered as $order) {
                $id = (int) $order->id;
                abort_unless(array_key_exists($id, $data['versions']) && array_key_exists($id, $data['request_keys']), 422);
                $operation = match ($data['action']) {
                    'complete' => 'complete',
                    'in_progress' => 'start',
                    'assign' => 'propose_handover',
                };
                $payload = $operation === 'propose_handover'
                    ? ['target_user_id' => (int) $data['assigned_to_user_id']]
                    : [];
                $this->transitionService()->execute($actor, $id, $operation,
                    (int) $data['versions'][$id], $data['request_keys'][$id], $payload);
            }
        }, 3);

        return back()->with('success', 'Bulk action applied to ' . count($data['ids']) . ' work order(s).');
    }
}
