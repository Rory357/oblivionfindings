<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrBenefitEnrollment;
use App\Domain\Hr\Models\HrBenefitPlan;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\BenefitsService;
use App\Domain\Hr\Services\CompensationService;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BenefitsController extends Controller
{
    public function __construct(
        protected BenefitsService $benefitsService,
        protected CompensationService $compensationService,
        private readonly HrPerformanceAccessService $access,
    ) {}

    /**
     * Enrollment overview.
     */
    public function index(Request $request)
    {
        $user = $this->viewer($request);

        $enrollments = $this->access
            ->applyBenefitEnrollmentScope(HrBenefitEnrollment::query(), $user)
            ->with(['employeeProfile.user:id,name', 'benefitPlan'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('plan_id'), fn ($q, $planId) => $q->where('benefit_plan_id', $planId))
            ->orderByDesc('enrollment_date')
            ->paginate(20)
            ->withQueryString();

        // employer_contribution_rate drives the enroll wizard's employer-default
        // prefill + cost preview.
        $plans = HrBenefitPlan::query()->active()
            ->get(['id', 'name', 'type', 'employer_contribution_rate']);

        $employees = $this->access
            ->applyCurrentProfileScope(HrEmployeeProfile::query(), $user)
            ->with('user:id,name')
            ->orderBy('user_id')
            ->get(['id', 'user_id', 'position_title']);

        // profileId → annual salary (decrypted in PHP) so the wizard can show a
        // live $/yr contribution cost preview. Manager-only (it exposes pay).
        $annualSalaryByProfileId = $user->canDo('hr.benefits.manage')
            && $user->canDo('hr.compensation.view')
            ? $this->access
                ->applyCurrentProfileScope(HrEmployeeProfile::query(), $user)
                ->get(['id', 'annual_salary'])
                ->mapWithKeys(fn ($p) => [$p->id => $p->annual_salary !== null ? (float) $p->annual_salary : null])
                ->all()
            : [];

        $summary = $this->benefitsService->getEnrollmentSummary($user);

        return Inertia::render('hr/compensation/benefits/index', [
            'enrollments' => $enrollments,
            'plans' => $plans,
            'employees' => $employees,
            'annualSalaryByProfileId' => $annualSalaryByProfileId,
            'summary' => $summary,
            'filters' => [
                'status' => $request->query('status'),
                'plan_id' => $request->query('plan_id'),
            ],
            'stats' => $this->compensationService->heroStatsFor($user),
            'tabCounts' => $this->compensationService->tabCountsFor($user),
            'can' => [
                'manage' => $user->canDo('hr.benefits.manage'),
            ],
        ]);
    }

    /**
     * Plan list/management.
     */
    public function plans(Request $request)
    {
        $user = $this->viewer($request);

        $plans = HrBenefitPlan::query()
            ->withCount(['enrollments' => fn ($query) => $this->access
                ->applyBenefitEnrollmentScope($query, $user)
                ->active()])
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/compensation/benefits/plans', [
            'plans' => $plans,
            'filters' => [
                'type' => $request->query('type'),
            ],
            'planTypes' => [
                ['value' => 'kiwisaver', 'label' => 'KiwiSaver'],
                ['value' => 'health_insurance', 'label' => 'Health Insurance'],
                ['value' => 'life_insurance', 'label' => 'Life Insurance'],
                ['value' => 'other', 'label' => 'Other'],
            ],
            'can' => [
                'manage' => $user->canDo('hr.benefits.manage'),
            ],
        ]);
    }

    /**
     * Store a new benefit plan.
     */
    public function storePlan(Request $request)
    {
        $this->manager($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('hr_benefit_plans', 'name')],
            'type' => ['required', 'string', 'in:kiwisaver,health_insurance,life_insurance,other'],
            'provider' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'employer_contribution_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        HrBenefitPlan::create($data);

        return redirect()->back()->with('success', 'Benefit plan created.');
    }

    /**
     * Enroll an employee in a benefit plan.
     */
    public function enroll(Request $request)
    {
        $user = $this->manager($request);

        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer'],
            'benefit_plan_id' => ['required', 'integer'],
            'enrollment_date' => ['required', 'date'],
            'employee_contribution_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'employer_contribution_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $profile = $this->access
            ->applyCurrentProfileScope(HrEmployeeProfile::query(), $user)
            ->findOrFail($data['employee_profile_id']);
        $plan = HrBenefitPlan::query()->active()->findOrFail($data['benefit_plan_id']);

        $this->benefitsService->enrollEmployee($profile, $plan, $data, $user);

        return redirect()->back()->with('success', 'Employee enrolled in benefit plan.');
    }

    /**
     * Update an enrollment (status, contribution rates, etc.).
     */
    public function updateEnrollment(Request $request, HrBenefitEnrollment $enrollment)
    {
        $user = $this->manager($request);
        $enrollment = $this->access->benefitEnrollment($user, $enrollment);

        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,opted_out,suspended,terminated'],
            'employee_contribution_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'employer_contribution_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'opt_out_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->benefitsService->updateEnrollment($enrollment, $data, $user);

        return redirect()->back()->with('success', 'Enrollment updated.');
    }

    /**
     * Activate or deactivate a benefit plan. Deactivating closes the plan to NEW
     * enrollments (it drops out of the enroll dropdown); existing enrollments
     * reference the plan by id and are unaffected.
     */
    public function updatePlan(Request $request, HrBenefitPlan $plan)
    {
        $this->manager($request);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($plan, $data): void {
            HrBenefitPlan::query()
                ->lockForUpdate()
                ->findOrFail($plan->getKey())
                ->update($data);
        }, attempts: 1);

        return redirect()->back()->with(
            'success',
            $data['is_active'] ? 'Benefit plan activated.' : 'Benefit plan deactivated.',
        );
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.benefits.view'), 403);

        return $this->access->currentStaff($user, $user);
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.benefits.manage'), 403);

        return $this->access->currentStaff($user, $user);
    }
}
