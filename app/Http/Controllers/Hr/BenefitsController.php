<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrBenefitEnrollment;
use App\Domain\Hr\Models\HrBenefitPlan;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\BenefitsService;
use App\Domain\Hr\Services\CompensationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BenefitsController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        protected BenefitsService $benefitsService,
        protected CompensationService $compensationService,
    ) {}

    /**
     * Enrollment overview.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.benefits.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $enrollments = HrBenefitEnrollment::query()
            ->forTenant($tenantId)
            ->with(['employeeProfile.user:id,name', 'benefitPlan'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('plan_id'), fn ($q, $planId) => $q->where('benefit_plan_id', $planId))
            ->orderByDesc('enrollment_date')
            ->paginate(20)
            ->withQueryString();

        $plans = HrBenefitPlan::forTenant($tenantId)->active()->get(['id', 'name', 'type']);

        $employees = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('user:id,name')
            ->orderBy('user_id')
            ->get(['id', 'user_id', 'position_title']);

        $summary = $this->benefitsService->getEnrollmentSummary($tenantId);

        return Inertia::render('hr/compensation/benefits/index', [
            'enrollments' => $enrollments,
            'plans' => $plans,
            'employees' => $employees,
            'summary' => $summary,
            'filters' => [
                'status' => $request->query('status'),
                'plan_id' => $request->query('plan_id'),
            ],
            'stats' => $this->compensationService->heroStats($tenantId, $user),
            'tabCounts' => $this->compensationService->tabCounts($tenantId),
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
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.benefits.view'), 403);

        $plans = HrBenefitPlan::query()
            ->forTenant($this->resolveHrTenantIdForUser($user))
            ->withCount(['enrollments' => fn ($q) => $q->where('status', 'active')])
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
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.benefits.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:kiwisaver,health_insurance,life_insurance,other'],
            'provider' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'employer_contribution_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        HrBenefitPlan::create([
            'tenant_id' => $this->resolveHrTenantIdForUser($user),
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Benefit plan created.');
    }

    /**
     * Enroll an employee in a benefit plan.
     */
    public function enroll(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.benefits.manage'), 403);

        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'benefit_plan_id' => ['required', 'integer', 'exists:hr_benefit_plans,id'],
            'enrollment_date' => ['required', 'date'],
            'employee_contribution_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'employer_contribution_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $profile = HrEmployeeProfile::findOrFail($data['employee_profile_id']);
        $plan = HrBenefitPlan::findOrFail($data['benefit_plan_id']);

        $this->benefitsService->enrollEmployee($profile, $plan, $data);

        return redirect()->back()->with('success', 'Employee enrolled in benefit plan.');
    }

    /**
     * Update an enrollment (status, contribution rates, etc.).
     */
    public function updateEnrollment(Request $request, HrBenefitEnrollment $enrollment)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.benefits.manage'), 403);

        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $enrollment->tenant_id);

        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,opted_out,suspended,terminated'],
            'employee_contribution_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'employer_contribution_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'opt_out_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $enrollment->update($data);

        return redirect()->back()->with('success', 'Enrollment updated.');
    }

    /**
     * Activate or deactivate a benefit plan. Deactivating closes the plan to NEW
     * enrollments (it drops out of the enroll dropdown); existing enrollments
     * reference the plan by id and are unaffected.
     */
    public function updatePlan(Request $request, HrBenefitPlan $plan)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.benefits.manage'), 403);

        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $plan->tenant_id);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $plan->update($data);

        return redirect()->back()->with(
            'success',
            $data['is_active'] ? 'Benefit plan activated.' : 'Benefit plan deactivated.',
        );
    }
}
