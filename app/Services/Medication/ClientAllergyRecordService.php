<?php

namespace App\Services\Medication;

use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\MedicationAllergy;
use Illuminate\Support\Collection;

/**
 * One read of a person's recorded allergies from both places staff record
 * them (EM-07):
 *
 *  - the medication allergy register (`medication_allergies`, with severity
 *    and reaction; written through the medications API), and
 *  - the health profile (`client_medical_profiles.allergies`, option keys or
 *    free text, no severity; written by ClientMedicalController::updateProfile).
 *
 * Which record is canonical is an open organisational decision (D5), so both
 * are read. An empty result means "nothing recorded" — never "no known
 * allergies" — and callers must say so.
 */
class ClientAllergyRecordService
{
    public const SOURCE_REGISTER = 'medication_register';

    public const SOURCE_PROFILE = 'health_profile';

    /**
     * Every recorded allergy for the person, register entries first. A
     * profile entry already present in the register (same allergen text) is
     * not repeated.
     *
     * @return list<array{allergen: string, severity: ?string, reaction: ?string, source: string, allergy: MedicationAllergy}>
     */
    public function forClient(Client $client): array
    {
        $register = MedicationAllergy::query()
            ->where('client_id', $client->id)
            ->get();
        $profile = ClientMedicalProfile::query()
            ->where('client_id', $client->id)
            ->first(['id', 'client_id', 'allergies']);

        return $this->combine($client->id, $register, $profile);
    }

    /**
     * Allergy labels per person for display, keyed by client id.
     *
     * @param  array<int, int>  $clientIds
     * @return array<int, list<string>>
     */
    public function labelsForClients(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $register = MedicationAllergy::query()
            ->whereIn('client_id', $clientIds)
            ->get()
            ->groupBy('client_id');
        $profiles = ClientMedicalProfile::query()
            ->whereIn('client_id', $clientIds)
            ->get(['id', 'client_id', 'allergies'])
            ->keyBy('client_id');

        $labels = [];
        foreach ($clientIds as $clientId) {
            $labels[(int) $clientId] = array_map(
                fn (array $entry): string => $entry['allergen'],
                $this->combine(
                    (int) $clientId,
                    $register->get($clientId, collect()),
                    $profiles->get($clientId),
                ),
            );
        }

        return $labels;
    }

    /**
     * @param  Collection<int, MedicationAllergy>  $register
     * @return list<array{allergen: string, severity: ?string, reaction: ?string, source: string, allergy: MedicationAllergy}>
     */
    private function combine(int $clientId, Collection $register, ?ClientMedicalProfile $profile): array
    {
        $entries = [];
        $seen = [];

        foreach ($register as $allergy) {
            $allergen = trim((string) $allergy->allergen);
            if ($allergen === '') {
                continue;
            }

            $seen[mb_strtolower($allergen)] = true;
            $entries[] = [
                'allergen' => $allergen,
                'severity' => $allergy->severity,
                'reaction' => $allergy->reaction,
                'source' => self::SOURCE_REGISTER,
                'allergy' => $allergy,
            ];
        }

        foreach ($this->profileAllergens($profile) as $allergen) {
            if (isset($seen[mb_strtolower($allergen)])) {
                continue;
            }

            $seen[mb_strtolower($allergen)] = true;
            // Never persisted: carries the allergen into the register's
            // matcher so both sources use the same drug-class rules.
            $entries[] = [
                'allergen' => $allergen,
                'severity' => null,
                'reaction' => null,
                'source' => self::SOURCE_PROFILE,
                'allergy' => new MedicationAllergy([
                    'client_id' => $clientId,
                    'allergen' => $allergen,
                ]),
            ];
        }

        return $entries;
    }

    /** @return list<string> Option keys become their labels; free text is kept. */
    private function profileAllergens(?ClientMedicalProfile $profile): array
    {
        $values = $profile?->allergies;
        if (! is_array($values)) {
            return [];
        }

        $labels = collect(ClientMedicalProfile::ALLERGEN_OPTIONS)->pluck('label', 'value');

        return collect($values)
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => (string) ($labels[trim($value)] ?? trim($value)))
            ->unique(fn (string $label): string => mb_strtolower($label))
            ->values()
            ->all();
    }
}
