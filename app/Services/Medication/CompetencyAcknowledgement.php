<?php

namespace App\Services\Medication;

use App\Models\MedicationCompetencyAssessment;
use App\Models\User;

/**
 * The worker's own medication competency assessment waiting for their
 * acknowledgement (eMAR P11 Acknowledge). An assessment counts for giving and
 * witnessing only once the assessed person has acknowledged it from their own
 * login, after the assessor declared it (MedicationCompetencyAssessment::
 * isPassed). Meds today shows it now; Staff eligibility › My eligibility
 * (chunk 6) becomes its permanent home.
 */
class CompetencyAcknowledgement
{
    /** The 12 areas, in the order and words the assessment form uses. */
    /** The 12 areas, worded as the register words them (StaffEligibilityRegister::AREAS). */
    public const AREAS = [
        'medication_knowledge' => 'Medication knowledge',
        'five_rights' => 'The five rights',
        'safety_checks' => 'Safety checks',
        'documentation' => 'Documentation',
        'controlled_drugs' => 'Controlled drugs',
        'prn_assessment' => 'As-needed (PRN) assessment',
        'insulin_competent' => 'Insulin',
        'inhaler_competent' => 'Inhaler technique',
        'topical_competent' => 'Topical medicines',
        'covert_admin_knowledge' => 'Covert administration',
        'error_reporting' => 'Error reporting',
        'allergy_awareness' => 'Allergy awareness',
    ];

    public function __construct(
        private readonly MedicationAdministratorCompetencyPolicy $policy,
    ) {}

    /**
     * The newest declared assessment of this person, when it passed, is still
     * in date and they haven't acknowledged it yet; otherwise null.
     *
     * @return array<string, mixed>|null
     */
    public function pendingFor(User $user): ?array
    {
        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $today = now($timezone)->toDateString();
        $latest = MedicationCompetencyAssessment::query()
            ->with('assessor:id,name')
            ->where('user_id', $user->id)
            ->whereNotNull('assessor_declared_at')
            ->where('assessor_id', '!=', $user->id)
            ->orderByDesc('assessment_date')
            ->orderByDesc('id')
            ->first();
        if ($latest === null
            || $latest->status !== 'passed'
            || $latest->staff_acknowledged_at !== null
            || ($latest->expiry_date !== null && $latest->expiry_date->toDateString() < $today)) {
            return null;
        }

        $notSeen = collect($latest->not_seen_areas ?? [])->map(fn (mixed $key): string => (string) $key)->all();
        $area = fn (bool $passed, bool $seen): array => collect(self::AREAS)
            ->filter(fn (string $label, string $key): bool => in_array($key, $notSeen, true) !== $seen && (bool) $latest->{$key} === $passed)
            ->values()
            ->all();
        $decision = $this->policy->evaluate($user, null, now());

        return [
            'id' => (int) $latest->id,
            'assessed_on' => $latest->assessment_date?->toDateString(),
            'assessor' => $latest->assessor?->name,
            'ends_on' => $latest->expiry_date?->toDateString(),
            'passed_areas' => collect(array_keys(self::AREAS))->filter(fn (string $key): bool => (bool) $latest->{$key})->count(),
            'not_passed' => $area(false, true),
            'not_assessed' => collect(self::AREAS)->only($notSeen)->values()->all(),
            'restriction' => $latest->restricted ? (trim((string) $latest->restriction_notes) ?: 'Restricted') : null,
            'to_work_on' => trim((string) ($latest->areas_for_improvement ?: $latest->action_plan)) ?: null,
            // Can they already record doses as given (another assessment or an exemption)?
            'can_give_now' => (bool) $decision['allowed'],
        ];
    }
}
