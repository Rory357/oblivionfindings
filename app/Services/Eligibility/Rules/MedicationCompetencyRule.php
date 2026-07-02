<?php

namespace App\Services\Eligibility\Rules;

use App\Models\MedicationCompetencyAssessment;
use App\Models\Shift;
use App\Models\User;
use App\Services\CoverageRoleService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * When a shift requires the 'med_competent' coverage role, validates that the
 * staff member's medication competency assessment is current — not failed and
 * not expired before the shift starts.
 *
 * Complements CoverageRoleService, whose med_competent check passes on the
 * `medications.administer.record` permission alone (permission ≠ current
 * competency). This rule adds the temporal expiry dimension the roster
 * otherwise ignores, so a worker with a lapsed assessment is flagged before
 * they're assigned a medication shift.
 */
class MedicationCompetencyRule implements EligibilityRuleInterface
{
    protected const EXPIRY_WARNING_DAYS = 30;

    public function __construct(
        protected CoverageRoleService $coverageRoles,
    ) {}

    public function evaluate(Shift $shift, User $user): array
    {
        // Only relevant when the shift requires medication-competent cover.
        if (! collect($this->coverageRoles->rolesForShift($shift))->contains('med_competent')) {
            return self::pass();
        }

        $latest = $user->relationLoaded('medicationCompetencyAssessments')
            ? $user->medicationCompetencyAssessments
                ->sortByDesc(fn ($a) => [$a->assessment_date, $a->id])
                ->first()
            : MedicationCompetencyAssessment::query()
                ->where('user_id', $user->id)
                ->orderByDesc('assessment_date')
                ->orderByDesc('id')
                ->first();

        // No assessment on file — the coverage-role check covers the hard block;
        // surface an explicit informational message for the roster card.
        if (! $latest) {
            return [
                'rule' => 'medication_competency',
                'passed' => false,
                'severity' => 'warning',
                'overrideable' => true,
                'message' => 'No medication competency assessment on file.',
            ];
        }

        if ($latest->status !== 'passed') {
            return [
                'rule' => 'medication_competency',
                'passed' => false,
                'severity' => 'block',
                'overrideable' => false,
                'message' => 'Medication competency is recorded as "'.str_replace('_', ' ', (string) $latest->status).'".',
            ];
        }

        if (! $latest->expiry_date) {
            return self::pass();
        }

        $expiry = $latest->expiry_date instanceof CarbonInterface
            ? $latest->expiry_date
            : Carbon::parse($latest->expiry_date);

        $shiftStart = $shift->starts_at instanceof CarbonInterface
            ? $shift->starts_at
            : Carbon::parse($shift->starts_at);

        if ($expiry->lt($shiftStart)) {
            return [
                'rule' => 'medication_competency',
                'passed' => false,
                'severity' => 'block',
                'overrideable' => false,
                'message' => "Medication competency expired on {$expiry->format('j M Y')}.",
            ];
        }

        // Whole days from the shift start to the (not-yet-passed) expiry.
        // Computed off the day boundaries so the sign is unambiguous.
        $daysUntilExpiry = $shiftStart->copy()->startOfDay()->diffInDays($expiry->copy()->startOfDay());

        if ($daysUntilExpiry <= self::EXPIRY_WARNING_DAYS) {
            return [
                'rule' => 'medication_competency',
                'passed' => false,
                'severity' => 'warning',
                'overrideable' => true,
                'message' => "Medication competency expires on {$expiry->format('j M Y')} (within ".self::EXPIRY_WARNING_DAYS.' days).',
            ];
        }

        return self::pass();
    }

    /**
     * @return array{rule: string, passed: true, severity: 'block', overrideable: false, message: null}
     */
    protected static function pass(): array
    {
        return [
            'rule' => 'medication_competency',
            'passed' => true,
            'severity' => 'block',
            'overrideable' => false,
            'message' => null,
        ];
    }
}
