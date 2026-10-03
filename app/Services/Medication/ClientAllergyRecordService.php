<?php

namespace App\Services\Medication;

use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\MedicationAllergy;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One read of a person's recorded allergies from both places staff record
 * them (EM-07):
 *
 *  - the medication allergy register (`medication_allergies`, with severity
 *    and reaction; written through the medications API), and
 *  - the health profile (`client_medical_profiles.allergies`, option keys or
 *    free text, no severity; written by ClientMedicalController::updateProfile).
 *
 * The health profile is canonical. Legacy entries remain readable until
 * copied without deleting their source. Only a lead's recorded review can
 * establish a no-known-allergies status.
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
            ->first();

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
            ->get()
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
        $copied = [];

        foreach ($profile?->allergy_records ?? [] as $record) {
            foreach ($record['source_register_ids'] ?? [] as $id) $copied[(int) $id] = true;
            if (! empty($record['removed_at'])) continue;
            $entries[] = [...$record, 'source' => self::SOURCE_PROFILE, 'allergy' => new MedicationAllergy(['client_id' => $clientId, 'allergen' => $record['allergen'] ?? '', 'severity' => $record['severity'] ?? null, 'reaction' => $record['reaction'] ?? null])];
        }

        foreach ($register as $allergy) {
            if (isset($copied[(int) $allergy->id])) continue;
            $entries[] = [
                'key' => 'register-'.$allergy->id,
                'allergen' => trim((string) $allergy->allergen),
                'severity' => $allergy->severity,
                'reaction' => $allergy->reaction,
                'notes' => $allergy->notes,
                'identified_date' => $allergy->identified_date?->toDateString(),
                'identified_by' => $allergy->identified_by,
                'source' => self::SOURCE_REGISTER,
                'allergy' => $allergy,
            ];
        }

        foreach ($profile?->allergies_canonical_at ? [] : $this->profileAllergens($profile) as $index => $allergen) {
            // Never persisted: carries the allergen into the register's
            // matcher so both sources use the same drug-class rules.
            $entries[] = [
                'key' => 'profile-'.$index,
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

        // Only exact normalized content is deduplicated. The same allergen
        // with another reaction, severity or note remains a separate entry.
        return collect($entries)->unique(fn ($entry) => $this->entryKey($entry))->values()->all();
    }

    public function entryKey(array $entry): string
    {
        $content = [];
        foreach (['allergen', 'severity', 'reaction', 'notes', 'identified_date', 'identified_by', 'removed_at'] as $field) {
            $content[$field] = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) ($entry[$field] ?? ''))));
        }
        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
    }

    /** Caller holds the client lock in its domain transaction. Source rows are never changed. */
    public function copyLegacy(Client $client): ClientMedicalProfile
    {
        $profile = ClientMedicalProfile::query()->where('client_id', $client->id)->lockForUpdate()->first() ?? new ClientMedicalProfile(['client_id' => $client->id]);
        $records = $profile->allergy_records ?? [];
        $copied = [];
        foreach ($records as $record) foreach ($record['source_register_ids'] ?? [] as $id) $copied[(int) $id] = true;
        $incoming = [];
        foreach (MedicationAllergy::withTrashed()->where('client_id', $client->id)->orderBy('id')->lockForUpdate()->get() as $source) {
            if (isset($copied[(int) $source->id])) continue;
            $incoming[] = ['key' => 'register-'.$source->id, 'allergen' => $source->allergen, 'severity' => $source->severity, 'reaction' => $source->reaction, 'notes' => $source->notes, 'identified_date' => $source->identified_date?->toDateString(), 'identified_by' => $source->identified_by, 'source_recorded_by' => $source->recorded_by, 'source_register_ids' => [$source->id], 'source_evidence' => [$source->getAttributes()], 'removed_at' => $source->deleted_at?->toIso8601String()];
        }
        if (! $profile->allergies_canonical_at) {
            foreach ($this->profileAllergens($profile) as $index => $label) $incoming[] = ['key' => 'profile-'.$index, 'allergen' => $label, 'severity' => null, 'reaction' => null, 'notes' => null, 'source_profile_values' => $profile->allergies, 'source_register_ids' => [], 'removed_at' => null];
        }
        foreach ($incoming as $entry) {
            $matching = array_search($this->entryKey($entry), array_map(fn ($record) => $this->entryKey($record), $records), true);
            if ($matching === false) {
                $records[] = ['key' => (string) Str::uuid(), ...$entry];
            } else {
                $records[$matching]['source_register_ids'] = array_values(array_unique([...($records[$matching]['source_register_ids'] ?? []), ...$entry['source_register_ids']]));
                $records[$matching]['source_evidence'] = [...($records[$matching]['source_evidence'] ?? []), ...($entry['source_evidence'] ?? [])];
                if (array_key_exists('source_profile_values', $entry)) $records[$matching]['source_profile_values'] = $entry['source_profile_values'];
            }
        }
        $profile->allergy_records = $records;
        if ($incoming !== [] || ! $profile->allergies_canonical_at) $this->clearReview($profile);
        $profile->allergies_canonical_at ??= now();
        // Compatibility labels are a projection, never a second clinical list.
        $profile->allergies = collect($records)->filter(fn ($entry) => empty($entry['removed_at']))->pluck('allergen')->unique()->values()->all();
        if (! $profile->exists || $profile->isDirty()) $profile->saveOrFail();
        return $profile;
    }

    public function summary(Client $client): array
    {
        $entries = $this->forClient($client);
        $profile = ClientMedicalProfile::query()->where('client_id', $client->id)->first();
        $reviewed = $profile?->allergies_reviewed_at && hash_equals((string) $profile->allergies_review_digest, $this->digest($entries));
        return [
            'status' => $entries ? 'recorded' : ($reviewed && $profile->allergies_review_status === 'no_known' ? 'no_known' : 'none'),
            'entries' => array_map(fn ($entry) => array_intersect_key($entry, array_flip(['key', 'allergen', 'severity', 'reaction', 'notes', 'identified_date', 'identified_by', 'source'])), $entries),
            'reviewed' => $reviewed ? ['at' => $profile->allergies_reviewed_at->toIso8601String(), 'by' => User::query()->whereKey($profile->allergies_reviewed_by)->value('name'), 'how' => $profile->allergies_review_method] : null,
            'digest' => $this->digest($entries),
        ];
    }

    public function digest(array $entries): string
    {
        $keys = array_map(fn ($entry) => $this->entryKey($entry), $entries);
        sort($keys, SORT_STRING);
        return hash('sha256', json_encode($keys, JSON_THROW_ON_ERROR));
    }

    public function clearReview(ClientMedicalProfile $profile): void
    {
        $profile->forceFill(['allergies_reviewed_at' => null, 'allergies_reviewed_by' => null, 'allergies_review_status' => null, 'allergies_review_method' => null, 'allergies_review_digest' => null]);
    }

    /** Old profile editors cannot overwrite the richer canonical list. */
    public function guardLegacyEdit(Client $client, mixed $labels): void
    {
        $profile = ClientMedicalProfile::query()->where('client_id', $client->id)->first();
        if (! $profile?->allergies_canonical_at) return;
        $normalize = fn ($values) => collect(is_array($values) ? $values : (filled($values) ? [$values] : []))->map(fn ($value) => mb_strtolower(trim((string) $value)))->sort()->values()->all();
        if ($normalize($labels) !== $normalize($profile->allergies)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['allergies' => 'Edit allergies in the health profile’s Allergy record so reactions and review history are retained.']);
        }
    }

    /** @return list<string> Option keys become their labels; free text is kept. */
    private function profileAllergens(?ClientMedicalProfile $profile): array
    {
        $values = $profile?->allergies;
        if (is_string($values)) $values = [$values];
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
