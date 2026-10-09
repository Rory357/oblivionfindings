<?php

namespace App\Services\Medication;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * EA-052 (Lane C3): the one allergy matcher, used by the order check (P04,
 * OrderAllergyMatcher) and the dose check (P01, MedicationAllergy). Before,
 * each had its own rules, so a severe class match (penicillin → amoxicillin)
 * passed the order check with no prescriber confirmation and then blocked
 * every dose at the bedside, with no way back to "Prescriber must confirm".
 *
 * A medicine matches a recorded allergy when:
 *  - the names match (either contains the other, or the allergen is a whole
 *    term in the medicine name) — the dose check's long-standing rule;
 *  - the allergy names a built-in drug class and the medicine is in it (the
 *    class list the dose check has always used, kept unchanged so no dose
 *    check is loosened); or
 *  - a reviewed, unexpired maintained class rule maps them
 *    (medication_allergy_class_rules).
 *
 * Every match is shown to the person checking the order and needs the
 * prescriber's confirmation before the order can be checked.
 */
final class AllergyMatching
{
    public const SOURCE_NAME = 'medicine_name';

    public const SOURCE_BUILT_IN = 'built_in_class';

    public const BUILT_IN_VERSION = 'emar-built-in-1';

    /** Allergy class (as written in the allergen) => medicines in that class. */
    public const BUILT_IN_CLASSES = [
        'penicillin' => ['amoxicillin', 'ampicillin', 'flucloxacillin', 'phenoxymethylpenicillin'],
        'sulfa' => ['sulfamethoxazole', 'co-trimoxazole', 'sulfadiazine'],
        'aspirin' => ['salicylate', 'acetylsalicylic acid'],
        'nsaid' => ['ibuprofen', 'naproxen', 'diclofenac', 'celecoxib'],
    ];

    /** Reviewed, unexpired maintained class rules. */
    public static function activeRules(): Collection
    {
        return DB::table('medication_allergy_class_rules')
            ->where('reviewed_at', '<=', now())->where('expires_at', '>', now())->get();
    }

    /**
     * How a medicine matches an allergen, or null.
     *
     * @return array{match_source: string, source_version: ?string}|null
     */
    public static function match(string $allergen, string $medicine, ?Collection $rules = null): ?array
    {
        $rawAllergen = mb_strtolower(trim($allergen));
        $rawMedicine = mb_strtolower(trim($medicine));
        // An incomplete historic entry is kept for review, never a match for every medicine.
        if ($rawAllergen === '' || $rawMedicine === '') {
            return null;
        }
        $name = OrderAllergyMatcher::normalise($medicine);
        $normalAllergen = OrderAllergyMatcher::normalise($allergen);
        if (str_contains($rawMedicine, $rawAllergen) || str_contains($rawAllergen, $rawMedicine)
            || self::containsTerm($name, $normalAllergen)) {
            return ['match_source' => self::SOURCE_NAME, 'source_version' => null];
        }
        foreach (self::BUILT_IN_CLASSES as $class => $medicines) {
            if (str_contains($rawAllergen, $class)) {
                foreach ($medicines as $member) {
                    if (str_contains($rawMedicine, $member)) {
                        return ['match_source' => self::SOURCE_BUILT_IN, 'source_version' => self::BUILT_IN_VERSION];
                    }
                }
            }
        }
        $rule = ($rules ?? self::activeRules())->first(fn ($rule) => OrderAllergyMatcher::normalise((string) $rule->allergen) === $normalAllergen
            && self::containsTerm($name, OrderAllergyMatcher::normalise((string) $rule->medicine)));

        return $rule === null ? null : ['match_source' => (string) $rule->source, 'source_version' => (string) $rule->source_version];
    }

    private static function containsTerm(string $medicine, string $term): bool
    {
        return $term !== '' && str_contains(' '.$medicine.' ', ' '.$term.' ');
    }
}
