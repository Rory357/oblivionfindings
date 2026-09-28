<?php

namespace App\Services\Medication;

use App\Models\ClientMedication;
use App\Models\MedicationCompetencyAssessment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * NF-03: what a worker's current medication competency assessment allows
 * beyond the pass/fail decision in MedicationAdministratorCompetencyPolicy.
 *
 * The organisation chooses each rule in MedicationSafetyPolicySettings and
 * every rule is off by default:
 *
 *  - restricted assessment: off | block "given" | require a present,
 *    qualified co-signer (the existing witness attestation) who is not
 *    restricted themselves;
 *  - task areas: off | block "given" when the matching area failed |
 *    also when it was not seen. Controlled-drug orders need the
 *    `controlled_drugs` area; orders with an active covert authorisation
 *    need `covert_admin_knowledge`. Insulin is not enforced: orders carry
 *    no insulin classification.
 *
 * Only used for administration ("given"). Rostering eligibility and witness
 * eligibility keep using the shared policy unchanged, and an approved
 * exemption (policy state "exempt") remains the governing decision.
 */
class MedicationCompetencyRestrictionRules
{
    public const AREA_LABELS = [
        'controlled_drugs' => 'Controlled drugs',
        'covert_admin_knowledge' => 'Covert administration',
    ];

    public function __construct(
        private readonly MedicationSafetyPolicySettings $settings,
        private readonly MedicationAdministratorCompetencyPolicy $competencyPolicy,
    ) {}

    /**
     * Non-locking pre-check used to decide, before the witness user is locked,
     * whether this "given" dose needs a co-signer. The locked check in
     * violation() stays authoritative.
     */
    public function requiresCosigner(User $actor, ?int $siteId, CarbonInterface $effectiveAt): bool
    {
        if ($this->settings->restrictedCompetencyMode() !== 'cosigner') {
            return false;
        }

        return (bool) $this->currentAssessment($actor, $siteId, $effectiveAt)?->restricted;
    }

    /**
     * Worker-facing summary for the recording screens (null when no rule
     * applies to them), so the reason is visible before they sign.
     *
     * @return array{requires_cosigner: bool, blocked: bool, message: string}|null
     */
    public function noticeFor(User $actor, ?int $siteId, CarbonInterface $effectiveAt): ?array
    {
        $mode = $this->settings->restrictedCompetencyMode();
        if ($mode === 'off') {
            return null;
        }

        $assessment = $this->currentAssessment($actor, $siteId, $effectiveAt);
        if (! $assessment?->restricted) {
            return null;
        }

        return [
            'requires_cosigner' => $mode === 'cosigner',
            'blocked' => $mode === 'block',
            'message' => $mode === 'cosigner'
                ? 'Your medication competency is restricted'.$this->notesSuffix($assessment).'. A present, qualified co-signer must confirm each dose you sign as given.'
                : 'Your medication competency is restricted'.$this->notesSuffix($assessment).'. You can record refusals and withheld doses, but a competent colleague must give doses.',
        ];
    }

    /**
     * The rule a "given" dose breaks, evaluated under the administration
     * locks after the shared policy allowed the actor.
     *
     * @param  array{state: string, assessment_id: ?int}  $decision  From MedicationAdministratorCompetencyPolicy::evaluate().
     * @return array{state: string, message: string, error_field: string}|null
     */
    public function violation(
        array $decision,
        ClientMedication $medication,
        ?int $siteId,
        CarbonInterface $effectiveAt,
        ?int $confirmedCosignerId,
    ): ?array {
        $restrictedMode = $this->settings->restrictedCompetencyMode();
        $areaMode = $this->settings->competencyAreaEnforcement();
        if ($restrictedMode === 'off' && $areaMode === 'off') {
            return null;
        }

        if (($decision['state'] ?? null) !== 'valid' || empty($decision['assessment_id'])) {
            return null;
        }

        $assessment = MedicationCompetencyAssessment::query()->find($decision['assessment_id']);
        if ($assessment === null) {
            return null;
        }

        if ($assessment->restricted && $restrictedMode === 'block') {
            return [
                'state' => 'restricted',
                'message' => 'You cannot sign this dose as given — your medication competency assessment is marked restricted'
                    .$this->notesSuffix($assessment)
                    .'. Ask a competent colleague to give this dose, or a competency assessor to review the restriction.',
                'error_field' => 'status',
            ];
        }

        if ($assessment->restricted && $restrictedMode === 'cosigner') {
            if ($confirmedCosignerId === null) {
                return [
                    'state' => 'restricted_cosigner_required',
                    'message' => 'Your medication competency assessment is restricted'
                        .$this->notesSuffix($assessment)
                        .', so a present, qualified co-signer must confirm this dose. Choose a co-signer and ask them to enter their password.',
                    'error_field' => 'witnessed_by',
                ];
            }

            $cosigner = User::query()->find($confirmedCosignerId);
            if ($cosigner === null || $this->currentAssessment($cosigner, $siteId, $effectiveAt)?->restricted !== false) {
                return [
                    'state' => 'restricted_cosigner_ineligible',
                    'message' => 'This co-signer cannot confirm the dose — their own medication competency is restricted or not current. Choose another co-signer.',
                    'error_field' => 'witnessed_by',
                ];
            }
        }

        if ($areaMode !== 'off') {
            foreach ($this->areasFor($medication) as $area) {
                $result = $this->areaResult($assessment, $area);
                if ($result === 'failed' || ($result === 'not_seen' && $areaMode === 'failed_or_not_seen')) {
                    return [
                        'state' => 'area_not_passed',
                        'message' => 'You cannot sign this dose as given — your medication competency assessment does not show “'
                            .self::AREA_LABELS[$area].'” as passed ('
                            .($result === 'failed' ? 'not passed' : 'not seen at assessment')
                            .'). Ask a competent colleague to give this dose, or a competency assessor to reassess you.',
                        'error_field' => 'status',
                    ];
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function areasFor(ClientMedication $medication): array
    {
        $areas = [];
        if ($medication->controlled_drug) {
            $areas[] = 'controlled_drugs';
        }

        $covert = $medication->relationLoaded('covertAuthorisation')
            ? $medication->covertAuthorisation
            : $medication->covertAuthorisation()->first();
        if ($covert !== null) {
            $areas[] = 'covert_admin_knowledge';
        }

        return $areas;
    }

    /** passed | failed | not_seen (a legacy null result counts as not seen). */
    private function areaResult(MedicationCompetencyAssessment $assessment, string $area): string
    {
        $notSeen = in_array($area, (array) ($assessment->not_seen_areas ?? []), true);
        $value = $assessment->getAttribute($area);

        if ($value === true && ! $notSeen) {
            return 'passed';
        }

        return $notSeen || $value === null ? 'not_seen' : 'failed';
    }

    private function currentAssessment(User $user, ?int $siteId, CarbonInterface $effectiveAt): ?MedicationCompetencyAssessment
    {
        $decision = $this->competencyPolicy->evaluate($user, $siteId, $effectiveAt);
        if ($decision['state'] !== 'valid' || $decision['assessment_id'] === null) {
            return null;
        }

        return MedicationCompetencyAssessment::query()->find($decision['assessment_id']);
    }

    private function notesSuffix(MedicationCompetencyAssessment $assessment): string
    {
        $notes = trim((string) $assessment->restriction_notes);

        return $notes === '' ? '' : ' ('.Str::limit($notes, 200).')';
    }
}
