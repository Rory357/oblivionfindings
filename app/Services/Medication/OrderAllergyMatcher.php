<?php

namespace App\Services\Medication;

use App\Models\Client;
use Illuminate\Support\Facades\DB;

/** Name matching plus attributable, maintained class mappings. No clinical list in code. */
final class OrderAllergyMatcher
{
    public function __construct(private readonly ClientAllergyRecordService $records) {}

    public function inspect(Client $client, string $medicine): array
    {
        $entries = $this->records->forClient($client);
        $rules = DB::table('medication_allergy_class_rules')
            ->where('reviewed_at', '<=', now())->where('expires_at', '>', now())->get();
        $name = self::normalise($medicine);
        $matches = [];
        foreach ($entries as $entry) {
            $allergen = self::normalise($entry['allergen']);
            $direct = self::containsTerm($name, $allergen);
            $rule = $rules->first(fn ($rule) => self::normalise($rule->allergen) === $allergen
                && self::containsTerm($name, self::normalise($rule->medicine)));
            if ($direct || $rule !== null) {
                $matches[] = [
                    'allergen' => $entry['allergen'], 'source' => $entry['source'],
                    'severity' => $entry['severity'], 'reaction' => $entry['reaction'],
                    'match_source' => $direct ? 'medicine_name' : $rule->source,
                    'source_version' => $direct ? null : $rule->source_version,
                ];
            }
        }

        return [
            'recorded' => array_map(fn ($entry) => array_diff_key($entry, ['allergy' => true]), $entries),
            'matches' => $matches,
            'class_matching' => $rules->isEmpty() ? 'not_configured' : 'configured',
            // Confirmation is tied to the exact current matches. A new allergy
            // or changed source requires a fresh prescriber confirmation.
            'match_sha256' => hash('sha256', json_encode($matches, JSON_THROW_ON_ERROR)),
        ];
    }

    public static function normalise(string $value): string
    {
        return trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($value)) ?? '');
    }

    private static function containsTerm(string $medicine, string $term): bool
    {
        return $term !== '' && str_contains(' '.$medicine.' ', ' '.$term.' ');
    }
}
