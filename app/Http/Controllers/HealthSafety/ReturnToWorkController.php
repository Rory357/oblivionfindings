<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class ReturnToWorkController extends Controller
{
    /**
     * List active injuries and RTW plans.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.view'), 403);

        $tenantId = $user->tenant_id;
        $filters = $request->only(['site_id', 'status', 'severity', 'search']);

        $query = \DB::table('hs_workplace_injuries')
            ->where('hs_workplace_injuries.tenant_id', $tenantId)
            ->when(!empty($filters['site_id']), fn ($q) => $q->where('hs_workplace_injuries.site_id', $filters['site_id']))
            ->when(!empty($filters['status']), fn ($q) => $q->where('hs_workplace_injuries.status', $filters['status']))
            ->when(!empty($filters['severity']), fn ($q) => $q->where('hs_workplace_injuries.severity', $filters['severity']))
            ->when(!empty($filters['search']), function ($q) use ($filters) {
                $search = $filters['search'];
                $q->where(function ($q2) use ($search) {
                    $q2->where('hs_workplace_injuries.description', 'like', "%{$search}%")
                       ->orWhereExists(function ($sub) use ($search) {
                           $sub->select(\DB::raw(1))
                               ->from('users')
                               ->whereColumn('users.id', 'hs_workplace_injuries.user_id')
                               ->where('users.name', 'like', "%{$search}%");
                       });
                });
            });

        $injuries = (clone $query)
            ->leftJoin('users', 'hs_workplace_injuries.user_id', '=', 'users.id')
            ->leftJoin('sites', 'hs_workplace_injuries.site_id', '=', 'sites.id')
            ->select(
                'hs_workplace_injuries.*',
                'users.name as user_name',
                'sites.name as site_name'
            )
            ->orderByDesc('hs_workplace_injuries.injury_date')
            ->paginate(25)
            ->withQueryString();

        // Stats
        $activeInjuries = \DB::table('hs_workplace_injuries')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['reported', 'under_treatment', 'return_to_work'])
            ->count();

        $activeRtwPlans = \DB::table('hs_return_to_work_plans')
            ->join('hs_workplace_injuries', 'hs_return_to_work_plans.injury_id', '=', 'hs_workplace_injuries.id')
            ->where('hs_workplace_injuries.tenant_id', $tenantId)
            ->whereIn('hs_return_to_work_plans.status', ['active', 'in_progress'])
            ->count();

        $thirtyDaysAgo = now()->subDays(30);
        $totalLostDays30d = \DB::table('hs_workplace_injuries')
            ->where('tenant_id', $tenantId)
            ->where('injury_date', '>=', $thirtyDaysAgo)
            ->sum('lost_time_days');

        $pendingAssessments = \DB::table('hs_capacity_assessments')
            ->join('hs_workplace_injuries', 'hs_capacity_assessments.injury_id', '=', 'hs_workplace_injuries.id')
            ->where('hs_workplace_injuries.tenant_id', $tenantId)
            ->where('hs_capacity_assessments.status', 'pending')
            ->count();

        $sites = \DB::table('sites')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $staff = \DB::table('users')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('health-safety/injuries/index', [
            'injuries' => $injuries,
            'stats' => [
                'active_injuries' => $activeInjuries,
                'active_rtw_plans' => $activeRtwPlans,
                'total_lost_days_30d' => (int) $totalLostDays30d,
                'pending_assessments' => $pendingAssessments,
            ],
            'sites' => $sites,
            'staff' => $staff,
            'filters' => $filters,
        ]);
    }

    /**
     * Show the create form for a workplace injury.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $staff = \DB::table('users')
            ->where('tenant_id', $user->tenant_id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        $sites = \DB::table('sites')
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('health-safety/injuries/create', [
            'staff' => $staff,
            'sites' => $sites,
        ]);
    }

    /**
     * Store a new workplace injury.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'site_id' => ['required', 'exists:sites,id'],
            'injury_date' => ['required', 'date'],
            'injury_type' => ['required', 'string', 'in:strain,laceration,fracture,burn,contusion,concussion,repetitive_strain,chemical_exposure,biological_exposure,psychological,other'],
            'body_part_affected' => ['required', 'string', 'max:255'],
            'severity' => ['required', 'string', 'in:minor,moderate,serious,critical'],
            'description' => ['required', 'string', 'max:5000'],
            'medical_treatment_type' => ['required', 'string', 'in:none,first_aid,gp_visit,hospital,specialist,ongoing'],
            'incident_location' => ['nullable', 'string', 'max:255'],
            'witness_names' => ['nullable', 'string', 'max:500'],
            'immediate_actions_taken' => ['nullable', 'string', 'max:2000'],
            'reported_to_worksafe' => ['boolean'],
            'acc_claim_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        \DB::table('hs_workplace_injuries')->insert(array_merge($validated, [
            'tenant_id' => $user->tenant_id,
            'status' => 'reported',
            'lost_time_days' => 0,
            'reported_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return redirect()->route('health-safety.injuries.index')
            ->with('success', 'Workplace injury recorded successfully.');
    }

    /**
     * Show a workplace injury with RTW plans, modified duties, and capacity assessments.
     */
    public function show(Request $request, int $injury)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.view'), 403);

        $record = \DB::table('hs_workplace_injuries')
            ->leftJoin('users', 'hs_workplace_injuries.user_id', '=', 'users.id')
            ->leftJoin('sites', 'hs_workplace_injuries.site_id', '=', 'sites.id')
            ->where('hs_workplace_injuries.id', $injury)
            ->where('hs_workplace_injuries.tenant_id', $user->tenant_id)
            ->select(
                'hs_workplace_injuries.*',
                'users.name as user_name',
                'sites.name as site_name'
            )
            ->firstOrFail();

        $rtwPlans = \DB::table('hs_return_to_work_plans')
            ->where('injury_id', $injury)
            ->orderByDesc('created_at')
            ->get();

        $modifiedDuties = \DB::table('hs_modified_duties')
            ->join('hs_return_to_work_plans', 'hs_modified_duties.rtw_plan_id', '=', 'hs_return_to_work_plans.id')
            ->where('hs_return_to_work_plans.injury_id', $injury)
            ->select('hs_modified_duties.*')
            ->orderByDesc('hs_modified_duties.start_date')
            ->get();

        $capacityAssessments = \DB::table('hs_capacity_assessments')
            ->leftJoin('users', 'hs_capacity_assessments.assessor_id', '=', 'users.id')
            ->where('hs_capacity_assessments.injury_id', $injury)
            ->select('hs_capacity_assessments.*', 'users.name as assessor_name')
            ->orderByDesc('hs_capacity_assessments.assessment_date')
            ->get();

        $staff = \DB::table('users')
            ->where('tenant_id', $user->tenant_id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('health-safety/injuries/show', [
            'injury' => $record,
            'rtwPlans' => $rtwPlans,
            'modifiedDuties' => $modifiedDuties,
            'capacityAssessments' => $capacityAssessments,
            'staff' => $staff,
        ]);
    }

    /**
     * Update a workplace injury (status, lost_time_days, return dates, ACC claim).
     */
    public function update(Request $request, int $injury)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $record = \DB::table('hs_workplace_injuries')
            ->where('id', $injury)
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:reported,under_treatment,return_to_work,recovered,closed'],
            'lost_time_days' => ['sometimes', 'integer', 'min:0'],
            'return_to_work_date' => ['sometimes', 'nullable', 'date'],
            'full_duties_date' => ['sometimes', 'nullable', 'date'],
            'acc_claim_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'acc_claim_status' => ['sometimes', 'nullable', 'string', 'in:pending,accepted,declined'],
            'reported_to_worksafe' => ['sometimes', 'boolean'],
            'worksafe_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        \DB::table('hs_workplace_injuries')
            ->where('id', $injury)
            ->update(array_merge($validated, [
                'updated_at' => now(),
            ]));

        return redirect()->back()->with('success', 'Injury record updated successfully.');
    }

    /**
     * Create a Return to Work plan for an injury.
     */
    public function storeRtwPlan(Request $request, int $injury)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $record = \DB::table('hs_workplace_injuries')
            ->where('id', $injury)
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'plan_start_date' => ['required', 'date'],
            'expected_end_date' => ['nullable', 'date', 'after_or_equal:plan_start_date'],
            'goals' => ['required', 'array', 'min:1'],
            'goals.*' => ['string', 'max:500'],
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.start_date' => ['required', 'date'],
            'stages.*.end_date' => ['nullable', 'date'],
            'stages.*.hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'stages.*.duties_description' => ['nullable', 'string', 'max:1000'],
            'medical_clearance_required' => ['boolean'],
            'gp_name' => ['nullable', 'string', 'max:255'],
            'gp_contact' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['goals'] = json_encode($validated['goals']);
        $validated['stages'] = json_encode($validated['stages']);

        \DB::table('hs_return_to_work_plans')->insert(array_merge($validated, [
            'injury_id' => $injury,
            'status' => 'active',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return redirect()->back()->with('success', 'Return to Work plan created successfully.');
    }

    /**
     * Update a Return to Work plan.
     */
    public function updateRtwPlan(Request $request, int $rtwPlan)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $record = \DB::table('hs_return_to_work_plans')
            ->join('hs_workplace_injuries', 'hs_return_to_work_plans.injury_id', '=', 'hs_workplace_injuries.id')
            ->where('hs_return_to_work_plans.id', $rtwPlan)
            ->where('hs_workplace_injuries.tenant_id', $user->tenant_id)
            ->select('hs_return_to_work_plans.*')
            ->firstOrFail();

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,in_progress,completed,cancelled'],
            'plan_start_date' => ['sometimes', 'date'],
            'expected_end_date' => ['sometimes', 'nullable', 'date'],
            'actual_end_date' => ['sometimes', 'nullable', 'date'],
            'goals' => ['sometimes', 'array', 'min:1'],
            'goals.*' => ['string', 'max:500'],
            'stages' => ['sometimes', 'array', 'min:1'],
            'stages.*.name' => ['required_with:stages', 'string', 'max:255'],
            'stages.*.start_date' => ['required_with:stages', 'date'],
            'stages.*.end_date' => ['nullable', 'date'],
            'stages.*.hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'stages.*.duties_description' => ['nullable', 'string', 'max:1000'],
            'outcome_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if (isset($validated['goals'])) {
            $validated['goals'] = json_encode($validated['goals']);
        }
        if (isset($validated['stages'])) {
            $validated['stages'] = json_encode($validated['stages']);
        }

        \DB::table('hs_return_to_work_plans')
            ->where('id', $rtwPlan)
            ->update(array_merge($validated, [
                'updated_at' => now(),
            ]));

        return redirect()->back()->with('success', 'Return to Work plan updated successfully.');
    }

    /**
     * Record a capacity assessment for an injury.
     */
    public function storeCapacityAssessment(Request $request, int $injury)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $record = \DB::table('hs_workplace_injuries')
            ->where('id', $injury)
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'assessment_date' => ['required', 'date'],
            'assessor_id' => ['nullable', 'exists:users,id'],
            'assessor_type' => ['required', 'string', 'in:gp,specialist,physiotherapist,occupational_therapist,employer'],
            'capacity_level' => ['required', 'string', 'in:full,partial,none'],
            'restrictions' => ['nullable', 'array'],
            'restrictions.*' => ['string', 'max:255'],
            'recommended_hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'next_review_date' => ['nullable', 'date', 'after:assessment_date'],
            'findings' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($validated['restrictions'])) {
            $validated['restrictions'] = json_encode($validated['restrictions']);
        }

        \DB::table('hs_capacity_assessments')->insert(array_merge($validated, [
            'injury_id' => $injury,
            'status' => 'completed',
            'recorded_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return redirect()->back()->with('success', 'Capacity assessment recorded successfully.');
    }

    /**
     * Add a modified duty record to a RTW plan.
     */
    public function storeModifiedDuty(Request $request, int $rtwPlan)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $record = \DB::table('hs_return_to_work_plans')
            ->join('hs_workplace_injuries', 'hs_return_to_work_plans.injury_id', '=', 'hs_workplace_injuries.id')
            ->where('hs_return_to_work_plans.id', $rtwPlan)
            ->where('hs_workplace_injuries.tenant_id', $user->tenant_id)
            ->select('hs_return_to_work_plans.*')
            ->firstOrFail();

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'duty_description' => ['required', 'string', 'max:2000'],
            'hours_per_week' => ['required', 'numeric', 'min:0', 'max:60'],
            'restrictions' => ['nullable', 'array'],
            'restrictions.*' => ['string', 'max:255'],
            'supervisor_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($validated['restrictions'])) {
            $validated['restrictions'] = json_encode($validated['restrictions']);
        }

        \DB::table('hs_modified_duties')->insert(array_merge($validated, [
            'rtw_plan_id' => $rtwPlan,
            'status' => 'active',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return redirect()->back()->with('success', 'Modified duty record added successfully.');
    }
}
