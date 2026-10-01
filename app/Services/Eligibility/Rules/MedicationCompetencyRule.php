<?php

namespace App\Services\Eligibility\Rules;

use App\Models\Shift;
use App\Models\User;
use App\Services\CoverageRoleService;
use App\Services\Medication\CompetencyPolicySettings;
use App\Services\Medication\MedicationAdministratorCompetencyPolicy;

/**
 * When a shift requires the 'med_competent' coverage role, validates that the
 * staff member satisfies the same fail-closed medication-administrator policy
 * enforced when an eMAR administration is persisted.
 */
class MedicationCompetencyRule implements EligibilityRuleInterface
{
    public function __construct(
        protected CoverageRoleService $coverageRoles,
        protected MedicationAdministratorCompetencyPolicy $competencyPolicy,
    ) {}

    public function evaluate(Shift $shift, User $user): array
    {
        // Only relevant when the shift requires medication-competent cover.
        if (! collect($this->coverageRoles->rolesForShift($shift))->contains('med_competent')) {
            return self::pass();
        }

        $siteId = $shift->site_id ?: $shift->client?->site_id;
        $effectiveAt = $shift->ends_at ?: $shift->starts_at ?: now();
        $decision = $this->competencyPolicy->evaluate(
            $user,
            $siteId ? (int) $siteId : null,
            $effectiveAt,
        );

        if (! $decision['allowed']) {
            return [
                'rule' => 'medication_competency',
                'passed' => false,
                'severity' => 'block',
                'overrideable' => false,
                'message' => $decision['message'],
                'competency_state' => $decision['state'],
            ];
        }

        $validUntil = $decision['valid_until'];
        $daysUntilExpiry = $effectiveAt->copy()->startOfDay()
            ->diffInDays($validUntil->copy()->startOfDay());
        // Renewal is due this many days before the end date (Settings › Staff & PINs).
        $warningDays = app(CompetencyPolicySettings::class)->renewalReminderDays();

        if ($daysUntilExpiry <= $warningDays) {
            $subject = $decision['state'] === 'exempt'
                ? 'Medication competency exemption'
                : 'Medication competency';

            return [
                'rule' => 'medication_competency',
                'passed' => false,
                'severity' => 'warning',
                'overrideable' => true,
                'message' => "{$subject} expires on {$validUntil->format('j M Y')} (within ".$warningDays.' '.($warningDays === 1 ? 'day' : 'days').').',
                'competency_state' => $decision['state'],
                'exemption_id' => $decision['exemption_id'],
            ];
        }

        return array_merge(self::pass(), [
            'competency_state' => $decision['state'],
            'exemption_id' => $decision['exemption_id'],
        ]);
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
