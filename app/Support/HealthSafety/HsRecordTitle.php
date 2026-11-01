<?php

namespace App\Support\HealthSafety;

/**
 * Decision 15 (Stephan, 1 Nov 2026, G4-5…G4-10): a restraint and a workplace injury use the same plain title on their
 * record, their safety review and every list. The record's title is made by the same builder as the safety review's
 * (HsReviewTitle), so the two can never drift:
 *
 *  - a restraint: "Restraint — Aroha Kingi" (the restraint type, e.g. "physical", sits in the subline, not the title);
 *  - an injury: the injury in plain words and the worker, "Cut left index finger — Mere Tane".
 *
 * Only for people who can already read the record (the record page, its register rows). A safety review shows the same
 * words only when its viewer can read the source (HsReviewTitle's own rule).
 */
final class HsRecordTitle
{
    /** Plain words for each stored injury type ("laceration" reads "Cut"). */
    private const INJURY_WORDS = [
        'strain' => 'Strain', 'laceration' => 'Cut', 'fracture' => 'Fracture', 'burn' => 'Burn', 'contusion' => 'Bruise',
        'concussion' => 'Concussion', 'repetitive_strain' => 'Repetitive strain', 'chemical_exposure' => 'Chemical exposure',
        'biological_exposure' => 'Biological exposure', 'needle_stick' => 'Needle-stick injury',
        'slip_trip_fall' => 'Slip, trip or fall', 'manual_handling' => 'Manual handling injury',
        'psychological' => 'Psychological harm', 'illness' => 'Work illness', 'other' => 'Injury',
    ];

    /** Types that read naturally with the body part straight after them ("Cut left index finger"). */
    private const WITH_BODY_PART = ['strain', 'laceration', 'fracture', 'burn', 'contusion'];

    public static function restraint(?string $client, ?string $site): string
    {
        return HsReviewTitle::make('restraint', true, null, $client, null, $site);
    }

    public static function injury(?string $injuryType, ?string $bodyPart, ?string $worker, ?string $site): string
    {
        return HsReviewTitle::make('injury', true, self::injuryWords($injuryType, $bodyPart), null, $worker, $site);
    }

    /** "Cut left index finger": the source's own words for an injury, as HsReviewTitle's $sourceWords. */
    public static function injuryWords(?string $injuryType, ?string $bodyPart): string
    {
        $type = (string) $injuryType;
        $words = self::INJURY_WORDS[$type] ?? 'Injury';
        $body = trim(preg_replace('/\s+/u', ' ', (string) $bodyPart) ?? '');
        if ($body !== '' && in_array($type, self::WITH_BODY_PART, true)) {
            return $words.' '.mb_strtolower(mb_substr($body, 0, 1)).mb_substr($body, 1);
        }

        return $words;
    }
}
