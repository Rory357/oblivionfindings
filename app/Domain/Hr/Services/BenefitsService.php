<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrBenefitEnrollment;
use App\Domain\Hr\Models\HrBenefitPlan;
use App\Domain\Hr\Models\HrEmployeeProfile;
use Illuminate\Support\Facades\DB;

class BenefitsService
{
    /**
     * Enroll an employee in a benefit plan.
     */
    public function enrollEmployee(HrEmployeeProfile $profile, HrBenefitPlan $plan, array $data): HrBenefitEnrollment
    {
        return DB::transaction(function () use ($profile, $plan, $data) {
            return HrBenefitEnrollment::create([
                'tenant_id' => $profile->tenant_id,
                'employee_profile_id' => $profile->id,
                'benefit_plan_id' => $plan->id,
                'enrollment_date' => $data['enrollment_date'] ?? now()->toDateString(),
                'status' => 'active',
                'employee_contribution_rate' => $data['employee_contribution_rate'] ?? 0,
                'employer_contribution_rate' => $data['employer_contribution_rate'] ?? $plan->employer_contribution_rate,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /**
     * Update KiwiSaver contribution rate for an enrollment.
     */
    public function updateKiwiSaverRate(HrBenefitEnrollment $enrollment, float $employeeRate, ?float $employerRate = null): HrBenefitEnrollment
    {
        return DB::transaction(function () use ($enrollment, $employeeRate, $employerRate) {
            $update = ['employee_contribution_rate' => $employeeRate];

            if ($employerRate !== null) {
                $update['employer_contribution_rate'] = $employerRate;
            }

            $enrollment->update($update);

            return $enrollment->fresh();
        });
    }

    /**
     * Get enrollment summary for a tenant, grouped by plan type.
     */
    public function getEnrollmentSummary(?int $tenantId): array
    {
        $enrollments = HrBenefitEnrollment::forTenant($tenantId)
            ->active()
            ->with(['benefitPlan', 'employeeProfile.user:id,name'])
            ->get();

        $byPlanType = $enrollments->groupBy(fn ($e) => $e->benefitPlan->type);

        $summary = [];
        foreach ($byPlanType as $type => $group) {
            $summary[$type] = [
                'total_enrolled' => $group->count(),
                'plans' => $group->groupBy('benefit_plan_id')->map(fn ($planGroup) => [
                    'plan_name' => $planGroup->first()->benefitPlan->name,
                    'enrolled_count' => $planGroup->count(),
                    'avg_employee_rate' => round($planGroup->avg('employee_contribution_rate'), 2),
                    'avg_employer_rate' => round($planGroup->avg('employer_contribution_rate'), 2),
                ])->values(),
            ];
        }

        return $summary;
    }
}
