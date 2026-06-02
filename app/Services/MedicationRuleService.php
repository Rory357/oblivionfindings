<?php

namespace App\Services;

use App\Models\ClientMedication;
use App\Models\MedicationAdminRule;
use Illuminate\Support\Str;

class MedicationRuleService
{
    /**
     * @return array{requires_countersign: bool, required_observations: array<int, string>, matched_rules: array<int, array<string, mixed>>}
     */
    public function requirementsFor(ClientMedication $medication): array
    {
        $medication->loadMissing('client');

        $siteId = $medication->client?->site_id;
        $rules = MedicationAdminRule::query()
            ->where('active', true)
            ->where(function ($query) use ($siteId) {
                $query->whereNull('site_id');

                if ($siteId) {
                    $query->orWhere('site_id', $siteId);
                }
            })
            ->get()
            ->filter(fn (MedicationAdminRule $rule) => $this->matches($rule, $medication))
            ->values();

        return [
            'requires_countersign' => $rules->contains(fn (MedicationAdminRule $rule) => $rule->requires_countersign),
            'required_observations' => $rules
                ->flatMap(fn (MedicationAdminRule $rule) => $rule->required_observations ?? [])
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'matched_rules' => $rules
                ->map(fn (MedicationAdminRule $rule) => [
                    'id' => $rule->id,
                    'match_type' => $rule->match_type,
                    'match_value' => $rule->match_value,
                    'requires_countersign' => $rule->requires_countersign,
                    'required_observations' => $rule->required_observations ?? [],
                ])
                ->all(),
        ];
    }

    private function matches(MedicationAdminRule $rule, ClientMedication $medication): bool
    {
        $needle = Str::lower(trim((string) $rule->match_value));

        if ($needle === '') {
            return false;
        }

        return match ($rule->match_type) {
            'medicine_name' => Str::contains(Str::lower((string) $medication->name), $needle),
            'route' => Str::contains(Str::lower((string) $medication->route), $needle),
            'nzulm_code' => Str::lower((string) ($medication->nzulm_code ?? '')) === $needle,
            default => false,
        };
    }
}
