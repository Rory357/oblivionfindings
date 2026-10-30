<?php

namespace App\Services\Medication;

use App\Models\Client;

/**
 * The order check's view of a person's recorded allergies. Matching is
 * AllergyMatching — the same rules the dose check uses (EA-052, Lane C3).
 */
final class OrderAllergyMatcher
{
    public function __construct(private readonly ClientAllergyRecordService $records) {}

    public function inspect(Client $client, string $medicine): array
    {
        $entries = $this->records->forClient($client);
        $rules = AllergyMatching::activeRules();
        $matches = [];
        foreach ($entries as $entry) {
            $match = AllergyMatching::match((string) $entry['allergen'], $medicine, $rules);
            if ($match !== null) {
                $matches[] = [
                    'allergen' => $entry['allergen'], 'source' => $entry['source'],
                    'severity' => $entry['severity'], 'reaction' => $entry['reaction'],
                    ...$match,
                ];
            }
        }

        return [
            'recorded' => array_map(fn ($entry) => array_diff_key($entry, ['allergy' => true]), $entries),
            'matches' => $matches,
            // The built-in classes always apply; reviewed maintained rules add to them.
            'class_matching' => $rules->isEmpty() ? 'built_in' : 'configured',
            // Confirmation is tied to the exact current matches. A new allergy
            // or changed source requires a fresh prescriber confirmation.
            'match_sha256' => hash('sha256', json_encode($matches, JSON_THROW_ON_ERROR)),
        ];
    }

    public static function normalise(string $value): string
    {
        return trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($value)) ?? '');
    }
}
