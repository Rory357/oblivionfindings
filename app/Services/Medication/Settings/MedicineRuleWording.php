<?php

namespace App\Services\Medication\Settings;

use App\Models\MedicationAdminRule;

/**
 * The plain-language wording of a medicine rule (P00 v5 builder), used by
 * Settings › Medicine rules and by the change history, so both say the same
 * thing: "Before saving a dose of {what} at {where}: {needs}."
 */
class MedicineRuleWording
{
    /** Observation token (EnhancedMarService::validateRequiredObservations) => words. */
    public const OBSERVATIONS = [
        'pulse' => 'pulse',
        'blood_glucose' => 'blood sugar (BSL)',
        'blood_pressure' => 'blood pressure',
    ];

    public const ALL_HOUSES = 'All houses';

    public function what(MedicationAdminRule $rule): string
    {
        $value = trim((string) $rule->match_value);

        return match ($rule->match_type) {
            'route' => 'any medicine given by '.mb_strtolower($value),
            'nzulm_code' => 'the product with NZULM code '.$value,
            'controlled' => 'any controlled medicine',
            default => $value,
        };
    }

    public function needs(MedicationAdminRule $rule): string
    {
        $parts = array_values(array_filter([
            $rule->requires_countersign ? 'a second person confirms with their witness PIN' : null,
            ...array_map(
                fn (string $token): string => 'record '.(self::OBSERVATIONS[$token] ?? $token),
                array_values($rule->required_observations ?? []),
            ),
        ]));

        return $parts === [] ? 'choose what it requires' : implode(' and ', $parts);
    }

    public function where(MedicationAdminRule $rule): string
    {
        return $rule->site_id === null ? self::ALL_HOUSES : ($rule->site?->name ?? 'a house');
    }

    public function sentence(MedicationAdminRule $rule): string
    {
        return 'Before saving a dose of '.$this->what($rule).' at '.$this->where($rule).': '.$this->needs($rule).'.';
    }
}
