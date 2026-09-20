<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Models\FleetVehicleBooking;
use App\Services\Fleet\MaintenanceAccessService;
use App\Services\Fleet\MaintenanceCheckService;
use App\Services\Fleet\VehicleBookingAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InspectionController extends Controller
{
    /**
     * List all vehicle inspections (checklist runs whose template name contains 'inspection').
     */
    public function index(Request $request)
    {
        $canManage = $this->canManageMaintenance($request);
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteIds = app(MaintenanceAccessService::class)->approvedSiteIds($actor);

        $query = FleetChecklistRun::query()
            ->whereHas('asset', fn ($q) => $q->whereNotNull('site_id')->whereIn('site_id', $siteIds))
            ->with(['template:id,name,type', 'asset:id,name,category,registration_number', 'user:id,name'])
            ->whereHas('template', function ($q) {
                $q->where('name', 'like', '%inspection%')
                    ->orWhere('type', 'inspection');
            });

        // Filters
        if ($request->filled('vehicle_id')) {
            $query->where('asset_id', $request->input('vehicle_id'));
        }

        if ($request->filled('result')) {
            $query->where('outcome', match ($request->input('result')) {
                'pass' => 'passed',
                'fail' => 'failed',
                'needs_assessment' => 'needs_assessment',
                default => 'unmatched',
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('completed_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('completed_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $inspections = $query
            ->latest('completed_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'type' => $this->resolveInspectionType($r),
                'asset' => $r->asset ? [
                    'id' => $r->asset->id,
                    'name' => $r->asset->name,
                    'registration_number' => $r->asset->registration_number ?? null,
                ] : null,
                'user' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
                'passed' => $r->passed,
                'outcome' => $r->outcome ?? 'needs_assessment',
                'notes' => $r->notes,
                'odometer' => $r->responses['_metadata']['odometer'] ?? $r->responses['odometer'] ?? null,
                'overall_condition' => $r->responses['_metadata']['overall_condition'] ?? $r->responses['overall_condition'] ?? null,
                'completed_at' => optional($r->completed_at)->toISOString(),
                'created_at' => optional($r->created_at)->toISOString(),
            ])
            ->values();

        $inspectionTemplates = FleetChecklistTemplate::query()
            ->where('type', 'inspection')->where('is_active', true)
            ->get(['id', 'name', 'items'])->keyBy('id');
        $fallbackTemplate = $inspectionTemplates->first();
        $policyService = app(\App\Services\Fleet\MaintenancePolicyService::class);
        $vehicles = Asset::query()
            ->whereNotNull('site_id')->whereIn('site_id', $siteIds)
            ->where('category', 'vehicle')
            ->orderBy('name')
            ->get(['id', 'name', 'registration_number', 'site_id', 'category'])
            ->map(function ($asset) use ($policyService, $inspectionTemplates, $fallbackTemplate) {
                $policy = $policyService->current((int) $asset->site_id, (string) $asset->category, 'check');
                $approvedTemplate = $inspectionTemplates->get((int) ($policy['rules']['template_id'] ?? 0));
                $template = $approvedTemplate ?? $fallbackTemplate;

                return [
                    'id' => $asset->id,
                    'name' => $asset->name,
                    'registration_number' => $asset->registration_number ?? null,
                    'template_id' => $template?->id,
                    'template_name' => $template?->name,
                    'template_items' => $template?->items ?? [],
                    'rule_version_id' => $approvedTemplate ? $policy['id'] : null,
                    'policy_questions' => $approvedTemplate ? ($policy['rules']['questions'] ?? null) : null,
                ];
            })
            ->values();

        // Hero band stats — efficient COUNTs over the last 30 days (all inspections,
        // independent of the current filters)
        $since30 = now()->subDays(30);
        $statsBase = fn () => FleetChecklistRun::query()
            ->whereHas('asset', fn ($q) => $q->whereNotNull('site_id')->whereIn('site_id', $siteIds))
            ->whereHas('template', function ($q) {
                $q->where('name', 'like', '%inspection%')
                    ->orWhere('type', 'inspection');
            })
            ->where('created_at', '>=', $since30);
        $runs30 = $statsBase()->count();
        $failed30 = $statsBase()->where('outcome', 'failed')->count();
        $passed30 = $statsBase()->where('outcome', 'passed')->count();
        $stats = [
            'runs_30d' => $runs30,
            'failed_30d' => $failed30,
            'needs_assessment_30d' => $runs30 - $failed30 - $passed30,
            'pass_rate' => $passed30 + $failed30 > 0 ? (int) round(($passed30 / ($passed30 + $failed30)) * 100) : null,
        ];

        // Create-wizard prefill (modal lives on this page — ?new=1 shim). If a booking is
        // referenced, surface its latest pre-trip run for the post-trip comparison panel.
        $preTrip = null;
        $booking = null;
        if ($request->filled('booking_id')) {
            $booking = app(VehicleBookingAccessService::class)->booking($actor, (int) $request->input('booking_id'));
            if ($booking) {
                $booking->load('asset:id,name,registration_number');
                $preTrip = FleetChecklistRun::query()
                    ->where('asset_id', $booking->asset_id)
                    ->whereHas('template', fn ($q) => $q->where('type', 'inspection'))
                    ->where(fn ($q) => $q->whereJsonContains('responses->_metadata->inspection_type', 'pre-trip')
                        ->orWhereJsonContains('responses->inspection_type', 'pre-trip'))
                    ->where('completed_at', '>=', $booking->checked_out_at ?? $booking->starts_at)
                    ->latest('completed_at')
                    ->first();
            }
        }

        return Inertia::render('fleet-assets/inspections/index', [
            'inspections' => $inspections,
            'vehicles' => $vehicles,
            'work_orders' => $canManage ? \App\Models\FleetWorkOrder::query()
                ->whereHas('asset', fn ($q) => $q->whereIn('site_id', $siteIds)->where('category', 'vehicle'))
                ->whereNotIn('status', ['cancelled'])->latest('id')->limit(100)
                ->get(['id', 'asset_id', 'reference_number', 'title'])
                ->map(fn ($order) => [
                    'id' => $order->id, 'asset_id' => $order->asset_id,
                    'reference_number' => $order->reference_number, 'title' => $order->title,
                    'attachments' => \Illuminate\Support\Facades\DB::table('fleet_maintenance_attachments')
                        ->where('work_order_id', $order->id)->orderBy('id')->get(['id', 'original_name']),
                ]) : collect(),
            'filters' => $request->only(['search', 'vehicle_id', 'result', 'date_from', 'date_to']),
            'stats' => $stats,
            'preselected_asset_id' => $request->input('asset_id') ?? $booking?->asset_id,
            'preselected_type' => $request->input('type', 'pre-trip'),
            'booking_id' => $request->input('booking_id'),
            'booking' => $booking ? [
                'id' => $booking->id,
                'asset_id' => $booking->asset_id,
                'purpose' => $booking->purpose,
            ] : null,
            'pre_trip_results' => $preTrip ? [
                'id' => $preTrip->id,
                'passed' => $preTrip->passed,
                'odometer' => $preTrip->responses['_metadata']['odometer'] ?? $preTrip->responses['odometer'] ?? null,
                'overall_condition' => $preTrip->responses['_metadata']['overall_condition'] ?? $preTrip->responses['overall_condition'] ?? null,
                'completed_at' => optional($preTrip->completed_at)->toISOString(),
                'checklist' => collect($preTrip->responses ?? [])
                    ->except(['_metadata', 'odometer', 'overall_condition', 'inspection_type'])
                    ->map(fn ($answer) => is_array($answer) ? \Illuminate\Support\Arr::except($answer, ['evidence_file']) : $answer)
                    ->toArray(),
            ] : null,
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    /** Legacy full-page create — the wizard now lives on the index as a modal (?new=1 shim). */
    public function create(Request $request)
    {
        return redirect()->to('/fleet-assets/inspections?' . http_build_query(array_filter([
            'new' => 1,
            'asset_id' => $request->input('asset_id'),
            'type' => $request->input('type'),
            'booking_id' => $request->input('booking_id'),
        ])));
    }

    /**
     * Store a new inspection as a FleetChecklistRun.
     */
    public function store(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor && app(MaintenanceAccessService::class)->canManage($actor), 403);
        $data = $request->validate([
            'asset_id' => ['required', 'integer'],
            'template_id' => ['required', 'integer'],
            'rule_version_id' => ['nullable', 'integer'],
            'request_key' => ['required', 'string', 'min:8', 'max:100'],
            'inspection_type' => ['required', 'string', 'in:pre-trip,post-trip'],
            'odometer' => ['nullable', 'numeric', 'min:0'],
            'overall_condition' => ['required', 'string', 'in:good,fair,poor'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'checklist' => ['required', 'array'],
            'checklist.*.result' => ['required', 'string', 'max:100'],
            'checklist.*.notes' => ['nullable', 'string', 'max:1000'],
            'checklist.*.evidence_attachment_id' => ['nullable', 'integer', 'min:1'],
            'booking_id' => ['nullable', 'integer'],
            'fuel_level_return' => ['nullable', 'string'],
            'items_left' => ['nullable', 'string', 'max:1000'],
            'new_damage' => ['nullable', 'string', 'max:2000'],
            'work_order_id' => ['nullable', 'integer'],
            'corrects_run_id' => ['nullable', 'integer'],
            'observed_at' => ['nullable', 'date'],
        ]);

        $template = FleetChecklistTemplate::query()->whereKey($data['template_id'])
            ->where('type', 'inspection')->firstOrFail();
        if (isset($data['booking_id'])) {
            $booking = app(VehicleBookingAccessService::class)->booking($actor, (int) $data['booking_id']);
            abort_unless($booking && (int) $booking->asset_id === (int) $data['asset_id'], 404);
        }

        $run = app(MaintenanceCheckService::class)->submit($actor, [
            'template_id' => $template->id,
            'asset_id' => $data['asset_id'],
            'check_kind' => 'inspection',
            'answers' => $data['checklist'],
            'request_key' => $data['request_key'],
            'rule_version_id' => $data['rule_version_id'] ?? null,
            'work_order_id' => $data['work_order_id'] ?? null,
            'corrects_run_id' => $data['corrects_run_id'] ?? null,
            'observed_at' => $data['observed_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'metadata' => collect($data)->only(['inspection_type', 'odometer', 'overall_condition', 'booking_id',
                'fuel_level_return', 'items_left', 'new_damage'])->all(),
        ]);

        return redirect()
            ->route('fleet-assets.inspections.show', $run)
            ->with('success', $run->outcome === 'needs_assessment'
                ? 'Inspection recorded. Approved rules are needed before it can be marked passed.'
                : 'Inspection recorded as '.$run->outcome.'.');
    }

    /**
     * Show a completed inspection.
     */
    public function show(Request $request, FleetChecklistRun $run)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $asset = app(MaintenanceAccessService::class)->asset($actor, (int) $run->asset_id);
        abort_unless((int) $asset->id === (int) $run->asset_id, 404);
        $run->load(['template:id,name,type', 'asset:id,name,category,registration_number', 'user:id,name']);

        $responses = $run->responses ?? [];
        $answerOutcomes = [];
        // Display the assessment from the submitted rule, never today's template.
        // Original response values remain separate and unchanged.
        foreach ($run->rule_snapshot_json['questions'] ?? [] as $question) {
            $id = $question['id'];
            $answer = $responses[$id] ?? null;
            if (isset($question['when'])) {
                $condition = $responses[$question['when']['question_id']]['result'] ?? null;
                if ($condition === null) { $answerOutcomes[$id] = 'needs_assessment'; continue; }
                if ($condition !== $question['when']['equals']) {
                    $answerOutcomes[$id] = $answer === null || ($answer['result'] ?? null) === 'na'
                        ? 'not_applicable' : 'needs_assessment';
                    continue;
                }
                unset($question['when']);
            }
            $outcome = app(\App\Services\Fleet\MaintenancePolicyService::class)->checkOutcome(
                ['rules' => ['questions' => [$question]]], $answer === null ? [] : [$id => $answer]);
            $answerOutcomes[$id] = $outcome === 'passed' && ($answer['result'] ?? null) === 'na'
                ? 'not_applicable' : $outcome;
        }
        $canEvidence = app(MaintenanceAccessService::class)->canManage($actor)
            || app(MaintenanceAccessService::class)->canReview($actor, $asset);
        foreach ($responses as $key => &$answer) {
            if (! is_array($answer) || ! isset($answer['evidence_file'])) continue;
            $file = $answer['evidence_file'];
            unset($answer['evidence_file']);
            if ($canEvidence) $answer['evidence'] = [
                'name' => $file['original_name'],
                'url' => route('fleet-assets.checklists.evidence', ['run' => $run->id, 'question' => $key], false),
            ];
        }
        unset($answer);
        $metadata = $responses['_metadata'] ?? $responses;

        return Inertia::render('fleet-assets/inspections/show', [
            'can_report' => app(MaintenanceAccessService::class)->canReport($actor),
            'inspection' => [
                'id' => $run->id,
                'type' => $metadata['inspection_type'] ?? $this->resolveInspectionType($run),
                'asset' => $run->asset ? [
                    'id' => $run->asset->id,
                    'name' => $run->asset->name,
                    'category' => $run->asset->category,
                    'registration_number' => $run->asset->registration_number ?? null,
                ] : null,
                'user' => $run->user ? ['id' => $run->user->id, 'name' => $run->user->name] : null,
                'passed' => $run->passed,
                'outcome' => $run->outcome ?? 'needs_assessment',
                'presented_template' => $run->presented_template_json,
                'answer_outcomes' => $answerOutcomes,
                'notes' => $run->notes,
                'odometer' => $metadata['odometer'] ?? null,
                'overall_condition' => $metadata['overall_condition'] ?? null,
                'responses' => collect($responses)
                    ->except(['_metadata', 'odometer', 'overall_condition', 'inspection_type'])
                    ->toArray(),
                'completed_at' => optional($run->completed_at)->toISOString(),
                'created_at' => optional($run->created_at)->toISOString(),
            ],
        ]);
    }

    /**
     * Resolve inspection type from the run's responses or template.
     */
    private function resolveInspectionType(FleetChecklistRun $run): string
    {
        return $run->responses['_metadata']['inspection_type'] ?? $run->responses['inspection_type']
            ?? ($run->template?->name ? (str_contains(strtolower($run->template->name), 'pre') ? 'pre-trip' : 'inspection') : 'inspection');
    }

    private function canManageMaintenance(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user?->canDo('fleet.manage') || $user?->canDo('fleet.maintenance.manage'));
    }
}
