<?php

namespace App\Services;

use App\Models\ClientMedication;
use App\Models\MedicationAdminRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class MedicationRuleService
{
    /**
     * @return array{requires_countersign: bool, required_observations: array<int, string>, matched_rules: array<int, array<string, mixed>>}
     */
    public function requirementsFor(ClientMedication $medication, bool $lockForUpdate = false): array
    {
        $medication->loadMissing('client');

        $siteId = $medication->client?->site_id;
        $rules = ($lockForUpdate
            ? $this->lockRuleSet()
            : MedicationAdminRule::query()->orderBy('id')->get())
            ->filter(fn (MedicationAdminRule $rule): bool => $rule->active
                && ($rule->site_id === null || (int) $rule->site_id === (int) $siteId))
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

    /**
     * Acquire the stable administration-rule publication mutex. Locking the
     * complete primary-key range (including its terminal insert gap under
     * MySQL REPEATABLE READ) serializes new/activated rules with readers that
     * must decide current countersign and observation requirements.
     *
     * @return Collection<int, MedicationAdminRule>
     */
    public function lockRuleSet(): Collection
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Medication administration rules must be locked in the governing transaction.');
        }

        return MedicationAdminRule::query()
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (MedicationAdminRule $rule): int => (int) $rule->id);
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
