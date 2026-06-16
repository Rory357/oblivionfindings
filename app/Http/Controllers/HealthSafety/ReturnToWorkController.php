<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\ModifiedDuty;
use App\Models\ReturnToWorkPlan;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkCapacityAssessment;
use App\Models\WorkplaceInjury;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReturnToWorkController extends Controller
{
    /**
     * List active injuries and RTW plans.
     */
    public function index(Request $request): \Inertia\Response
    {
        $filters = $request->only(['q', 'site_id', 'status', 'severity']);

        $injuries = WorkplaceInjury::with(['user:id,name', 'site:id,name'])
            ->when(!empty($filters['site_id']), fn ($q) => $q->where('site_id', $filters['site_id']))
            ->when(!empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['severity']), fn ($q) => $q->where('severity', $filters['severity']))
            ->when(!empty($filters['q']), function ($q) use ($filters) {
                $search = $filters['q'];
                $q->where(function ($q2) use ($search) {
                    $q2->where('description', 'like', "%{$search}%")
                       ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('injury_date')
            ->paginate(25)
            ->withQueryString();

        // Stats
        $activeInjuries = WorkplaceInjury::whereIn('status', ['reported', 'under_treatment', 'return_to_work'])->count();

        $activeRtwPlans = ReturnToWorkPlan::whereIn('status', ['active', 'in_progress'])->count();

        $thirtyDaysAgo = now()->subDays(30);
        $totalLostDays30d = (int) WorkplaceInjury::where('injury_date', '>=', $thirtyDaysAgo)
            ->sum('lost_time_days');

        $pendingAssessments = WorkCapacityAssessment::where('capacity_status', 'requires_review')->count();

        return Inertia::render('health-safety/injuries/index', [
            'injuries' => $injuries,
            'stats' => [
                'active_injuries' => $activeInjuries,
                'active_rtw_plans' => $activeRtwPlans,
                'lost_days_30d' => $totalLostDays30d,
                'pending_assessments' => $pendingAssessments,
            ],
            'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'staff' => User::select('id', 'name')->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    /**
     * Show the create form for a workplace injury.
     */
    public function create(): \Inertia\Response
    {
        return Inertia::render('health-safety/injuries/create', [
            'staff' => User::select('id', 'name')->orderBy('name')->get(),
            'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a new workplace injury.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'site_id' => ['required', 'exists:sites,id'],
            'injury_date' => ['required', 'date'],
            'injury_type' => ['required', 'string', 'in:strain,laceration,fracture,burn,contusion,concussion,repetitive_strain,chemical_exposure,biological_exposure,needle_stick,slip_trip_fall,manual_handling,psychological,illness,other'],
            'body_part_affected' => ['required', 'string', 'max:255'],
            'severity' => ['required', 'string', 'in:minor,moderate,serious,critical'],
            'description' => ['required', 'string', 'max:5000'],
            'medical_treatment_type' => ['required', 'string', 'in:none,first_aid,gp_visit,hospital,emergency_department,hospitalisation,specialist,ongoing'],
            'immediate_treatment' => ['nullable', 'string', 'max:2000'],
            'worksafe_notifiable' => ['boolean'],
            'acc_claim_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        WorkplaceInjury::create(array_merge($validated, [
            'status' => 'reported',
            'lost_time_days' => 0,
            'created_by' => $request->user()->id,
        ]));

        if ($request->boolean('stay')) {
            return back()->with('success', 'Workplace injury recorded successfully.');
        }

        return redirect()->route('health-safety.injuries.index')
            ->with('success', 'Workplace injury recorded successfully.');
    }

    /**
     * Show a workplace injury with RTW plans, modified duties, and capacity assessments.
     */
    public function show(WorkplaceInjury $injury): \Inertia\Response
    {
        $injury->load(['user:id,name', 'site:id,name']);

        $rtwPlans = $injury->returnToWorkPlans()
            ->with(['worker:id,name', 'manager:id,name'])
            ->orderByDesc('created_at')
            ->get();

        $modifiedDuties = ModifiedDuty::whereIn(
                'return_to_work_plan_id',
                $injury->returnToWorkPlans()->pluck('id')
            )
            ->with('user:id,name')
            ->orderByDesc('start_date')
            ->get();

        $capacityAssessments = $injury->capacityAssessments()
            ->with('user:id,name')
            ->orderByDesc('assessment_date')
            ->get();

        $injuryData = $injury->toArray();
        $injuryData['rtw_plans'] = $rtwPlans;
        $injuryData['modified_duties'] = $modifiedDuties;
        $injuryData['capacity_assessments'] = $capacityAssessments;

        return Inertia::render('health-safety/injuries/show', [
            'injury' => $injuryData,
            'staff' => User::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Update a workplace injury (status, lost_time_days, return dates, ACC claim).
     */
    public function update(Request $request, WorkplaceInjury $injury): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:reported,under_treatment,return_to_work,recovered,closed'],
            'lost_time_days' => ['sometimes', 'integer', 'min:0'],
            'expected_return_date' => ['sometimes', 'nullable', 'date'],
            'actual_return_date' => ['sometimes', 'nullable', 'date'],
            'acc_claim_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'acc_claim_lodged' => ['sometimes', 'boolean'],
            'worksafe_notifiable' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $injury->update($validated);

        return redirect()->back()->with('success', 'Injury record updated successfully.');
    }

    /**
     * Create a Return to Work plan for an injury.
     */
    public function storeRtwPlan(Request $request, WorkplaceInjury $injury): RedirectResponse
    {
        $validated = $request->validate([
            'plan_start_date' => ['required', 'date'],
            'plan_end_date' => ['nullable', 'date', 'after_or_equal:plan_start_date'],
            'goals' => ['required', 'array', 'min:1'],
            'goals.*' => ['string', 'max:500'],
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.start_date' => ['required', 'date'],
            'stages.*.end_date' => ['nullable', 'date'],
            'stages.*.hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'stages.*.duties_description' => ['nullable', 'string', 'max:1000'],
            'medical_clearance_notes' => ['nullable', 'string', 'max:2000'],
            'medical_clearance_provider' => ['nullable', 'string', 'max:255'],
            'worker_id' => ['nullable', 'exists:users,id'],
            'manager_id' => ['nullable', 'exists:users,id'],
        ]);

        $injury->returnToWorkPlans()->create(array_merge($validated, [
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]));

        return redirect()->back()->with('success', 'Return to Work plan created successfully.');
    }

    /**
     * Update a Return to Work plan.
     */
    public function updateRtwPlan(Request $request, ReturnToWorkPlan $rtwPlan): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,in_progress,completed,cancelled'],
            'plan_start_date' => ['sometimes', 'date'],
            'plan_end_date' => ['sometimes', 'nullable', 'date'],
            'goals' => ['sometimes', 'array', 'min:1'],
            'goals.*' => ['string', 'max:500'],
            'stages' => ['sometimes', 'array', 'min:1'],
            'stages.*.name' => ['required_with:stages', 'string', 'max:255'],
            'stages.*.start_date' => ['required_with:stages', 'date'],
            'stages.*.end_date' => ['nullable', 'date'],
            'stages.*.hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'stages.*.duties_description' => ['nullable', 'string', 'max:1000'],
            'review_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $rtwPlan->update($validated);

        return redirect()->back()->with('success', 'Return to Work plan updated successfully.');
    }

    /**
     * Record a capacity assessment for an injury.
     */
    public function storeCapacityAssessment(Request $request, WorkplaceInjury $injury): RedirectResponse
    {
        $validated = $request->validate([
            'assessment_date' => ['required', 'date'],
            'user_id' => ['nullable', 'exists:users,id'],
            'assessor_name' => ['nullable', 'string', 'max:255'],
            'assessor_type' => ['required', 'string', 'in:gp,specialist,physiotherapist,occupational_therapist,employer'],
            'capacity_status' => ['required', 'string', 'in:fit_for_full_duties,fit_for_modified_duties,unfit_for_work,requires_review'],
            'restrictions' => ['nullable', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
            'next_assessment_date' => ['nullable', 'date', 'after:assessment_date'],
            'assessment_summary' => ['nullable', 'string', 'max:5000'],
        ]);

        $injury->capacityAssessments()->create(array_merge($validated, [
            'created_by' => $request->user()->id,
        ]));

        return redirect()->back()->with('success', 'Capacity assessment recorded successfully.');
    }

    /**
     * Add a modified duty record to a RTW plan.
     */
    public function storeModifiedDuty(Request $request, ReturnToWorkPlan $rtwPlan): RedirectResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'modified_duties_description' => ['required', 'string', 'max:2000'],
            'hours_per_day' => ['required', 'numeric', 'min:0', 'max:24'],
            'restrictions' => ['nullable', 'string', 'max:2000'],
            'accommodations' => ['nullable', 'string', 'max:2000'],
            'user_id' => ['nullable', 'exists:users,id'],
        ]);

        $rtwPlan->modifiedDuties()->create(array_merge($validated, [
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]));

        return redirect()->back()->with('success', 'Modified duty record added successfully.');
    }
}
