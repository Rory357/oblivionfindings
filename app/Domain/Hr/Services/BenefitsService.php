<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrBenefitEnrollment;
use App\Domain\Hr\Models\HrBenefitPlan;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Notifications\BenefitEnrolledNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BenefitsService
{
    public function __construct(private readonly HrPerformanceAccessService $access) {}

    /**
     * Enroll an employee in a benefit plan.
     */
    public function enrollEmployee(
        HrEmployeeProfile $profile,
        HrBenefitPlan $plan,
        array $data,
        User $actor,
    ): HrBenefitEnrollment {
        $enrollment = DB::transaction(function () use ($profile, $plan, $data, $actor): HrBenefitEnrollment {
            $this->access->currentStaff($actor, $actor);
            $profile = $this->access
                ->applyCurrentProfileScope(HrEmployeeProfile::query(), $actor)
                ->lockForUpdate()
                ->findOrFail($profile->getKey());
            $plan = HrBenefitPlan::query()
                ->active()
                ->lockForUpdate()
                ->findOrFail($plan->getKey());

            if (HrBenefitEnrollment::query()
                ->where('employee_profile_id', $profile->id)
                ->where('benefit_plan_id', $plan->id)
                ->lockForUpdate()
                ->exists()
            ) {
                throw ValidationException::withMessages([
                    'benefit_plan_id' => 'This employee already has an enrollment for the selected plan.',
                ]);
            }

            $enrollment = HrBenefitEnrollment::create([
                'employee_profile_id' => $profile->id,
                'benefit_plan_id' => $plan->id,
                'enrollment_date' => $data['enrollment_date'] ?? now()->toDateString(),
                'status' => 'active',
                'employee_contribution_rate' => $data['employee_contribution_rate'] ?? 0,
                'employer_contribution_rate' => $data['employer_contribution_rate'] ?? $plan->employer_contribution_rate,
                'notes' => $data['notes'] ?? null,
            ]);

            $enrollment->setRelation('benefitPlan', $plan);
            $enrollment->setRelation('employeeProfile', $profile);

            // KiwiSaver → payroll sync (see syncKiwiSaverToProfile).
            $this->syncKiwiSaverToProfile($enrollment);

            return $enrollment;
        }, attempts: 1);

        $this->notifyEnrollmentChange($enrollment);

        return $enrollment;
    }

    /**
     * Update KiwiSaver contribution rate for an enrollment.
     */
    public function updateKiwiSaverRate(
        HrBenefitEnrollment $enrollment,
        float $employeeRate,
        ?float $employerRate,
        User $actor,
    ): HrBenefitEnrollment {
        return $this->updateEnrollment($enrollment, [
            'employee_contribution_rate' => $employeeRate,
            ...($employerRate !== null ? ['employer_contribution_rate' => $employerRate] : []),
        ], $actor);
    }

    public function updateEnrollment(
        HrBenefitEnrollment $enrollment,
        array $data,
        User $actor,
    ): HrBenefitEnrollment {
        [$updated, $material] = DB::transaction(function () use ($enrollment, $data, $actor): array {
            $this->access->currentStaff($actor, $actor);
            $locked = $this->access
                ->applyBenefitEnrollmentScope(HrBenefitEnrollment::query(), $actor)
                ->lockForUpdate()
                ->findOrFail($enrollment->getKey());
            $this->access
                ->applyCurrentProfileScope(HrEmployeeProfile::query(), $actor)
                ->lockForUpdate()
                ->findOrFail($locked->employee_profile_id);

            $locked->update($data);
            $material = $locked->wasChanged([
                'status',
                'employee_contribution_rate',
                'employer_contribution_rate',
            ]);
            $locked = $locked->fresh(['benefitPlan', 'employeeProfile']);
            $this->syncKiwiSaverToProfile($locked);

            return [$locked, $material];
        }, attempts: 1);

        if ($material) {
            $this->notifyEnrollmentChange($updated);
        }

        return $updated;
    }

    /**
     * Keep the employee profile's kiwisaver_rate in lockstep with a KiwiSaver
     * benefit enrolment.
     *
     * Payroll never reads hr_benefit_enrollments — the payroll export and
     * payslip calculations read HrEmployeeProfile.kiwisaver_rate, so an
     * enrolment that isn't mirrored there silently never deducts. Active
     * enrolment → profile rate matches the employee contribution rate;
     * opted-out → profile rate 0. Non-KiwiSaver plans are a no-op.
     */
    public function syncKiwiSaverToProfile(HrBenefitEnrollment $enrollment): void
    {
        $plan = $enrollment->benefitPlan ?? $enrollment->benefitPlan()->first();
        if (! $plan || $plan->type !== 'kiwisaver') {
            return;
        }

        $profile = $enrollment->employeeProfile ?? $enrollment->employeeProfile()->first();
        if (! $profile) {
            return;
        }

        if ($enrollment->status === 'opted_out') {
            $rate = 0.0;
        } elseif ($enrollment->status === 'active' && $enrollment->employee_contribution_rate !== null) {
            $rate = (float) $enrollment->employee_contribution_rate;
        } else {
            // Suspended/terminated (or an active enrolment with no rate yet):
            // leave the profile rate as-is rather than guessing.
            return;
        }

        if ((float) ($profile->kiwisaver_rate ?? -1) === $rate) {
            return;
        }

        // kiwisaver_rate lives on the (encrypted-at-rest) profile — plain
        // assignment; the model cast handles storage.
        $profile->update(['kiwisaver_rate' => $rate]);

        Log::info('KiwiSaver rate synced from benefit enrolment to employee profile (payroll SSOT).', [
            'enrollment_id' => $enrollment->id,
            'employee_profile_id' => $profile->id,
            'status' => $enrollment->status,
            'kiwisaver_rate' => $rate,
        ]);
    }

    /**
     * Best-effort enrolment confirmation (mail + database) to the covered
     * employee — used on creation and on material updates (rate/status).
     */
    public function notifyEnrollmentChange(HrBenefitEnrollment $enrollment): void
    {
        try {
            $enrollment->loadMissing(['benefitPlan', 'employeeProfile.user']);
            $employee = $enrollment->employeeProfile?->user;

            $employee?->notify(new BenefitEnrolledNotification($enrollment));
        } catch (\Throwable $exception) {
            Log::warning('Failed to send benefit enrolment notification', [
                'enrollment_id' => $enrollment->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Get the viewer's Site-visible enrollment summary, grouped by plan type.
     */
    public function getEnrollmentSummary(User $viewer): array
    {
        $enrollments = $this->access
            ->applyBenefitEnrollmentScope(HrBenefitEnrollment::query(), $viewer)
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

    /** @return Builder<HrBenefitEnrollment> */
    public function visibleEnrollments(User $viewer): Builder
    {
        return $this->access->applyBenefitEnrollmentScope(HrBenefitEnrollment::query(), $viewer);
    }
}
