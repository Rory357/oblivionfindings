<?php

namespace App\Services\HealthSafety;

/**
 * Classifies a health & safety event against the WorkSafe NZ notifiable-event threshold
 * (Health and Safety at Work Act 2015, subpart 3, ss.23–25).
 *
 * A notifiable event is the death of a person (s.25), a notifiable injury or illness
 * (s.23 — includes admission to hospital as an in-patient), or a notifiable incident
 * (s.24 — an unplanned event exposing a person to a serious risk to health or safety).
 *
 * The dashboard's incident wizard uses the same determination to drive its WorkSafe
 * notifiable-event check step; this server-side classifier is the single source of truth
 * so `is_notifiable` is never simply trusted from the client. Pure logic — no I/O.
 */
class NotifiableEventClassifier
{
    /** HSWA notifiable-event categories. */
    public const CATEGORY_DEATH = 'death';
    public const CATEGORY_INJURY_OR_ILLNESS = 'notifiable_injury_or_illness';
    public const CATEGORY_INCIDENT = 'notifiable_incident';

    /** Degree-of-harm vocabulary (as captured by the incident wizard). */
    public const HARM_NONE = 'none';
    public const HARM_FIRST_AID = 'first_aid';
    public const HARM_MEDICAL = 'medical';
    public const HARM_HOSPITALISATION = 'hospitalisation';
    public const HARM_DEATH = 'death';

    /** Severity that meets the notifiable-incident threshold on its own. */
    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Classify an event from its degree of harm and severity.
     *
     * Notifiable when harm is hospitalisation or death, OR severity is critical — mapping
     * to the three HSWA categories. (Mirrors the prototype's determination; precedence is
     * death → injury/illness → incident.)
     *
     * @return array{notifiable: bool, category: string|null, authority: string, reason: string}
     */
    public function classify(?string $harm, ?string $severity): array
    {
        $harm = $this->normalise($harm);
        $severity = $this->normalise($severity);

        if ($harm === self::HARM_DEATH) {
            return $this->result(
                true,
                self::CATEGORY_DEATH,
                'A death arising from work is a notifiable event under HSWA 2015 s.25 — notify WorkSafe NZ as soon as possible.',
            );
        }

        if ($harm === self::HARM_HOSPITALISATION) {
            return $this->result(
                true,
                self::CATEGORY_INJURY_OR_ILLNESS,
                'Admission to hospital as an in-patient is a notifiable injury or illness under HSWA 2015 s.23 — notify WorkSafe NZ as soon as possible.',
            );
        }

        if ($severity === self::SEVERITY_CRITICAL) {
            return $this->result(
                true,
                self::CATEGORY_INCIDENT,
                'A critical-severity event meets the notifiable-incident threshold under HSWA 2015 s.24 — notify WorkSafe NZ as soon as possible.',
            );
        }

        return $this->result(
            false,
            null,
            'Below the HSWA notifiable threshold — recorded for your records and kept for at least 5 years.',
        );
    }

    /** Convenience boolean. */
    public function isNotifiable(?string $harm, ?string $severity): bool
    {
        return $this->classify($harm, $severity)['notifiable'];
    }

    private function normalise(?string $value): ?string
    {
        return $value !== null ? strtolower(trim($value)) : null;
    }

    /**
     * @return array{notifiable: bool, category: string|null, authority: string, reason: string}
     */
    private function result(bool $notifiable, ?string $category, string $reason): array
    {
        return [
            'notifiable' => $notifiable,
            'category' => $category,
            'authority' => 'worksafe',
            'reason' => $reason,
        ];
    }
}
