<?php

namespace App\Services\HealthSafety;

/**
 * Preliminary WorkSafe NZ notifiable-event decision support.
 *
 * This class deliberately returns a tri-state recommendation. It does not make
 * the accountable decision: an authorised H&S actor must still record a signed
 * decision on the canonical HsEvent, with rationale and ruleset provenance.
 */
class NotifiableEventClassifier
{
    public const STATUS_NOTIFIABLE = 'notifiable';

    public const STATUS_NOT_NOTIFIABLE = 'not_notifiable';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    /** HSWA notifiable-event categories. */
    public const CATEGORY_DEATH = 'death';

    public const CATEGORY_INJURY_OR_ILLNESS = 'notifiable_injury_or_illness';

    public const CATEGORY_INCIDENT = 'notifiable_incident';

    /** Degree-of-harm vocabulary retained for existing source integrations. */
    public const HARM_NONE = 'none';

    public const HARM_FIRST_AID = 'first_aid';

    public const HARM_MEDICAL = 'medical';

    public const HARM_HOSPITALISATION = 'hospitalisation';

    public const HARM_DEATH = 'death';

    public const SEVERITY_CRITICAL = 'critical';

    /** Explicit negative/uncertain choices used by the full decision tree. */
    public const ANSWER_NONE = 'none';

    public const ANSWER_UNSURE = 'unsure';

    /** WorkSafe's specified serious injury/illness matrix (HSWA s.23). */
    public const INJURY_AMPUTATION = 'amputation_requiring_immediate_treatment';

    public const INJURY_SERIOUS_HEAD = 'serious_head_injury_requiring_immediate_treatment';

    public const INJURY_SERIOUS_EYE = 'serious_eye_injury_requiring_immediate_treatment';

    public const INJURY_SERIOUS_BURN = 'serious_burn_requiring_immediate_treatment';

    public const INJURY_DEGLOVING_OR_SCALPING = 'degloving_or_scalping_requiring_immediate_treatment';

    public const INJURY_SPINAL = 'spinal_injury_requiring_immediate_treatment';

    public const INJURY_LOSS_OF_BODILY_FUNCTION = 'loss_of_bodily_function_requiring_immediate_treatment';

    public const INJURY_SERIOUS_LACERATION = 'serious_laceration_requiring_immediate_treatment';

    public const ILLNESS_SUBSTANCE_EXPOSURE = 'substance_exposure_requiring_treatment_within_48_hours';

    public const ILLNESS_SERIOUS_WORK_INFECTION = 'serious_work_related_infection';

    public const INJURY_OR_ILLNESS_DECLARED_BY_REGULATION = 'declared_by_regulation';

    public const SPECIFIED_INJURY_OR_ILLNESS = [
        self::INJURY_AMPUTATION,
        self::INJURY_SERIOUS_HEAD,
        self::INJURY_SERIOUS_EYE,
        self::INJURY_SERIOUS_BURN,
        self::INJURY_DEGLOVING_OR_SCALPING,
        self::INJURY_SPINAL,
        self::INJURY_LOSS_OF_BODILY_FUNCTION,
        self::INJURY_SERIOUS_LACERATION,
        self::ILLNESS_SUBSTANCE_EXPOSURE,
        self::ILLNESS_SERIOUS_WORK_INFECTION,
        self::INJURY_OR_ILLNESS_DECLARED_BY_REGULATION,
    ];

    public const SPECIFIED_INJURY_OR_ILLNESS_LABELS = [
        'Amputation requiring immediate treatment beyond first aid',
        'Serious head injury requiring immediate treatment beyond first aid',
        'Serious eye injury requiring immediate treatment beyond first aid',
        'Serious burn requiring immediate treatment beyond first aid',
        'Degloving or scalping requiring immediate treatment beyond first aid',
        'Spinal injury requiring immediate treatment beyond first aid',
        'Loss of a bodily function requiring immediate treatment beyond first aid',
        'Serious laceration requiring immediate treatment beyond first aid',
        'Substance exposure requiring, or usually requiring, medical treatment within 48 hours',
        'Serious infection to which carrying out work was a significant contributing factor',
        'Injury or illness declared notifiable by an applicable regulation',
    ];

    /** WorkSafe's dangerous-incident matrix (HSWA s.24). */
    public const INCIDENT_SUBSTANCE_ESCAPE = 'substance_escaping_spilling_or_leaking';

    public const INCIDENT_EXPLOSION_OR_FIRE = 'implosion_explosion_or_fire';

    public const INCIDENT_GAS_OR_STEAM_ESCAPE = 'gas_or_steam_escaping';

    public const INCIDENT_PRESSURISED_SUBSTANCE_ESCAPE = 'pressurised_substance_escaping';

    public const INCIDENT_LETHAL_ELECTRIC_SHOCK = 'potentially_lethal_electric_shock';

    public const INCIDENT_FALL_OR_RELEASE_FROM_HEIGHT = 'fall_or_release_from_height';

    public const INCIDENT_AUTHORISED_PLANT_FAILURE = 'authorised_plant_damage_collapse_overturn_or_failure';

    public const INCIDENT_STRUCTURE_COLLAPSE = 'structure_collapse_or_partial_collapse';

    public const INCIDENT_EXCAVATION_FAILURE = 'excavation_or_shoring_collapse_or_failure';

    public const INCIDENT_UNDERGROUND_INRUSH = 'underground_inrush_of_water_mud_or_gas';

    public const INCIDENT_VENTILATION_INTERRUPTION = 'underground_main_ventilation_interruption';

    public const INCIDENT_VESSEL_EVENT = 'vessel_collision_capsize_or_inrush';

    public const INCIDENT_DECLARED_BY_REGULATION = 'declared_by_regulation';

    public const DANGEROUS_INCIDENTS = [
        self::INCIDENT_SUBSTANCE_ESCAPE,
        self::INCIDENT_EXPLOSION_OR_FIRE,
        self::INCIDENT_GAS_OR_STEAM_ESCAPE,
        self::INCIDENT_PRESSURISED_SUBSTANCE_ESCAPE,
        self::INCIDENT_LETHAL_ELECTRIC_SHOCK,
        self::INCIDENT_FALL_OR_RELEASE_FROM_HEIGHT,
        self::INCIDENT_AUTHORISED_PLANT_FAILURE,
        self::INCIDENT_STRUCTURE_COLLAPSE,
        self::INCIDENT_EXCAVATION_FAILURE,
        self::INCIDENT_UNDERGROUND_INRUSH,
        self::INCIDENT_VENTILATION_INTERRUPTION,
        self::INCIDENT_VESSEL_EVENT,
        self::INCIDENT_DECLARED_BY_REGULATION,
    ];

    public const DANGEROUS_INCIDENT_LABELS = [
        'A substance escaping, spilling or leaking',
        'An implosion, explosion or fire',
        'Gas or steam escaping',
        'A pressurised substance escaping',
        'Electric shock capable of causing a lethal shock',
        'Plant, a substance or another thing falling or being released from height',
        'Damage, collapse, overturning, failure or malfunction of plant that requires authorisation',
        'Collapse or partial collapse of a structure',
        'Collapse or failure of an excavation or its supporting shoring',
        'Inrush of water, mud or gas in an underground excavation or tunnel',
        'Interruption of the main ventilation system in an underground excavation or tunnel',
        'Vessel collision, capsize or inrush of water into a vessel',
        'Incident declared notifiable by an applicable regulation',
    ];

    /**
     * Versioned content contract approved for this native decision-support seam.
     *
     * SOURCE_EFFECTIVE_DATE is the commencement date of HSWA 2015. The source
     * was re-checked on SOURCE_REVIEWED_DATE; the published 2027 amendments must
     * trigger a new ruleset rather than silently changing historical decisions.
     */
    public const DECISION_TREE_VERSION = 'worksafe-hswa-ss23-25-v1';

    public const SOURCE_EFFECTIVE_DATE = '2016-04-04';

    public const SOURCE_REVIEWED_DATE = '2026-08-23';

    public const NEXT_MANDATORY_REVIEW_DATE = '2027-04-01';

    public const SOURCE_URL = 'https://www.worksafe.govt.nz/notifications/what-events-need-to-be-notified/';

    public const CONTENT_OWNER = 'Health & Safety / Legal & Compliance / Product';

    /**
     * Full decision tree. Every negative conclusion requires explicit negative
     * answers; omissions, unrecognised values and "unsure" remain needs-review.
     *
     * @param  array{
     *     work_related?: bool|null,
     *     death?: bool|null,
     *     hospital_admission?: bool|null,
     *     specified_injury_or_illness?: string|null,
     *     dangerous_incident?: string|null,
     *     regulation_reference?: string|null,
     *     unplanned_or_uncontrolled?: bool|null,
     *     serious_risk_from_immediate_or_imminent_exposure?: bool|null
     * }  $answers
     * @return array{
     *     status: string,
     *     notifiable: bool|null,
     *     needs_review: bool,
     *     category: string|null,
     *     authority: string,
     *     reason: string,
     *     decision_tree_version: string,
     *     source_effective_date: string,
     *     source_reviewed_date: string,
     *     content_owner: string
     * }
     */
    public function assess(array $answers): array
    {
        $workRelated = $this->normaliseBoolean($answers['work_related'] ?? null);

        if ($workRelated === false) {
            return $this->result(
                self::STATUS_NOT_NOTIFIABLE,
                false,
                null,
                'The event is recorded as unrelated to the conduct of work, so it is outside the HSWA notifiable-event threshold.',
            );
        }

        if ($workRelated !== true) {
            return $this->needsReview(
                'Work-relatedness is not established. A qualified H&S reviewer must decide whether the event arose from the conduct of work.',
            );
        }

        $death = $this->normaliseBoolean($answers['death'] ?? null);
        if ($death === true) {
            return $this->result(
                self::STATUS_NOTIFIABLE,
                true,
                self::CATEGORY_DEATH,
                'A work-related death meets the notifiable-event threshold under HSWA 2015 s.25.',
            );
        }
        if ($death !== false) {
            return $this->needsReview('Whether the event involved a death is not established.');
        }

        $hospitalAdmission = $this->normaliseBoolean($answers['hospital_admission'] ?? null);
        if ($hospitalAdmission === true) {
            return $this->result(
                self::STATUS_NOTIFIABLE,
                true,
                self::CATEGORY_INJURY_OR_ILLNESS,
                'A work-related injury or illness requiring immediate in-patient hospital admission meets HSWA 2015 s.23.',
            );
        }
        if ($hospitalAdmission !== false) {
            return $this->needsReview('Whether immediate in-patient hospital admission was required is not established.');
        }

        $injuryOrIllness = $this->normaliseChoice($answers['specified_injury_or_illness'] ?? null);
        if ($injuryOrIllness === self::INJURY_OR_ILLNESS_DECLARED_BY_REGULATION
            && ! $this->hasRegulationReference($answers)
        ) {
            return $this->needsReview(
                'The applicable regulation declaring this injury or illness notifiable is not identified.',
            );
        }
        if (in_array($injuryOrIllness, self::SPECIFIED_INJURY_OR_ILLNESS, true)) {
            return $this->result(
                self::STATUS_NOTIFIABLE,
                true,
                self::CATEGORY_INJURY_OR_ILLNESS,
                'The selected work-related injury or illness is in the specified HSWA s.23 notification matrix.',
            );
        }
        if ($injuryOrIllness !== self::ANSWER_NONE) {
            return $this->needsReview('The specified injury or illness threshold is incomplete or uncertain.');
        }

        $dangerousIncident = $this->normaliseChoice($answers['dangerous_incident'] ?? null);
        if (in_array($dangerousIncident, self::DANGEROUS_INCIDENTS, true)) {
            if ($dangerousIncident === self::INCIDENT_DECLARED_BY_REGULATION
                && ! $this->hasRegulationReference($answers)
            ) {
                return $this->needsReview(
                    'The applicable regulation declaring this incident notifiable is not identified.',
                );
            }

            $unplanned = $this->normaliseBoolean($answers['unplanned_or_uncontrolled'] ?? null);
            $seriousRisk = $this->normaliseBoolean(
                $answers['serious_risk_from_immediate_or_imminent_exposure'] ?? null,
            );

            if ($unplanned === true && $seriousRisk === true) {
                return $this->result(
                    self::STATUS_NOTIFIABLE,
                    true,
                    self::CATEGORY_INCIDENT,
                    'The selected unplanned or uncontrolled work-related incident exposed a person to serious risk from immediate or imminent exposure under HSWA 2015 s.24.',
                );
            }

            if ($unplanned === false || $seriousRisk === false) {
                return $this->result(
                    self::STATUS_NOT_NOTIFIABLE,
                    false,
                    null,
                    'All injury and illness branches are negative, and the selected incident does not meet both the unplanned-or-uncontrolled and serious-risk requirements.',
                );
            }

            return $this->needsReview(
                'The dangerous-incident type is identified, but its control state or serious-risk exposure is uncertain.',
            );
        }

        if ($dangerousIncident !== self::ANSWER_NONE) {
            return $this->needsReview('The dangerous-incident threshold is incomplete or uncertain.');
        }

        return $this->result(
            self::STATUS_NOT_NOTIFIABLE,
            false,
            null,
            'Explicit answers exclude work-related death, every specified injury or illness, and every qualifying dangerous incident in this ruleset.',
        );
    }

    /**
     * Compatibility seam for source records that only capture harm and severity.
     *
     * Exact death/hospital-admission triggers still escalate. Severity alone and
     * every reduced negative path are needs-review, never a definitive decision.
     *
     * @return array<string, bool|string|null>
     */
    public function classify(?string $harm, ?string $severity): array
    {
        $harm = $this->normaliseChoice($harm);
        $severity = $this->normaliseChoice($severity);

        if ($harm === self::HARM_DEATH) {
            return $this->assess([
                'work_related' => true,
                'death' => true,
            ]);
        }

        if ($harm === self::HARM_HOSPITALISATION) {
            return $this->assess([
                'work_related' => true,
                'death' => false,
                'hospital_admission' => true,
            ]);
        }

        if ($severity === self::SEVERITY_CRITICAL) {
            return $this->needsReview(
                'Generic critical severity does not establish a specified injury, illness or dangerous incident. Complete the WorkSafe decision tree.',
            );
        }

        return $this->needsReview(
            'The reduced harm and severity inputs cannot exclude the specified injury, illness or dangerous-incident thresholds.',
        );
    }

    /**
     * Compatibility boolean: true only for a positive statutory trigger.
     * False can mean needs-review; callers making a final decision must use the
     * tri-state classify()/assess() result.
     */
    public function isNotifiable(?string $harm, ?string $severity): bool
    {
        return $this->classify($harm, $severity)['notifiable'] === true;
    }

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return [
            'version' => self::DECISION_TREE_VERSION,
            'source_effective_date' => self::SOURCE_EFFECTIVE_DATE,
            'source_reviewed_date' => self::SOURCE_REVIEWED_DATE,
            'next_mandatory_review_date' => self::NEXT_MANDATORY_REVIEW_DATE,
            'source_url' => self::SOURCE_URL,
            'content_owner' => self::CONTENT_OWNER,
            'specified_injury_or_illness' => self::SPECIFIED_INJURY_OR_ILLNESS,
            'specified_injury_or_illness_labels' => self::SPECIFIED_INJURY_OR_ILLNESS_LABELS,
            'dangerous_incidents' => self::DANGEROUS_INCIDENTS,
            'dangerous_incident_labels' => self::DANGEROUS_INCIDENT_LABELS,
        ];
    }

    /** @return array<string, bool|string|null> */
    private function needsReview(string $reason): array
    {
        return $this->result(self::STATUS_NEEDS_REVIEW, null, null, $reason);
    }

    /** @return array<string, bool|string|null> */
    private function result(string $status, ?bool $notifiable, ?string $category, string $reason): array
    {
        return [
            'status' => $status,
            'notifiable' => $notifiable,
            'needs_review' => $status === self::STATUS_NEEDS_REVIEW,
            'category' => $category,
            'authority' => 'worksafe',
            'reason' => $reason,
            'decision_tree_version' => self::DECISION_TREE_VERSION,
            'source_effective_date' => self::SOURCE_EFFECTIVE_DATE,
            'source_reviewed_date' => self::SOURCE_REVIEWED_DATE,
            'content_owner' => self::CONTENT_OWNER,
        ];
    }

    private function normaliseBoolean(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private function normaliseChoice(mixed $value): ?string
    {
        return is_string($value) ? strtolower(trim($value)) : null;
    }

    /** @param array<string, mixed> $answers */
    private function hasRegulationReference(array $answers): bool
    {
        return is_string($answers['regulation_reference'] ?? null)
            && trim($answers['regulation_reference']) !== '';
    }
}
