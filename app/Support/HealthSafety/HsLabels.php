<?php

namespace App\Support\HealthSafety;

/**
 * Health and safety plain-language labels for server-made text (flash messages, exports, notifications).
 *
 * Wording source of truth: docs/health-safety-build/vocabulary.md (approved 29 Oct 2026). The client mirror is
 * resources/js/lib/health-safety/hs-labels.ts. Both must change together: tests/fixtures/health-safety/labels.json is
 * the shared contract, and tests/Unit/HealthSafety/HsLabelsTest.php and hs-labels.test.ts each check their map
 * equals it. (The fixture and this file use plain apostrophes; the screens use typographic ones.)
 */
final class HsLabels
{
    public const LABELS = [
        'severity' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'],
        'injury_severity' => ['minor' => 'Minor', 'moderate' => 'Moderate', 'serious' => 'Serious', 'critical' => 'Critical'],
        'risk_level' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'extreme' => 'Extreme'],
        'likelihood' => ['rare' => 'Rare', 'unlikely' => 'Unlikely', 'possible' => 'Possible', 'likely' => 'Likely', 'almost_certain' => 'Almost certain'],
        'action_priority' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Urgent'],
        'action_status' => ['open' => 'To do', 'in_progress' => 'In progress', 'completed' => 'Done — waiting to be checked', 'verified' => 'Checked', 'closed' => 'Closed'],
        'action_type' => ['corrective' => 'Fix the cause', 'preventive' => 'Stop it happening', 'improvement' => 'Improvement'],
        'review_category' => [
            'incident' => 'Incident', 'near_miss' => 'Near miss', 'hazard' => 'Hazard', 'injury' => 'Injury',
            'exposure' => 'Chemical exposure', 'restraint' => 'Restraint', 'safeguarding' => 'Safeguarding',
            'vehicle_incident' => 'Vehicle incident', 'drill_failure' => 'Drill problem',
            'inspection_failure' => 'Inspection problem', 'equipment_fault' => 'Equipment fault',
        ],
        'review_status' => ['open' => 'Open', 'investigating' => 'Being investigated', 'corrective_action' => 'Actions under way', 'monitoring' => 'Checking it worked', 'closed' => 'Closed'],
        'investigation_status' => ['draft' => 'Draft', 'in_progress' => 'In progress', 'findings_recorded' => 'Findings written', 'under_review' => 'With reviewer', 'reviewed' => 'Reviewed', 'completed' => 'Done'],
        'investigation_type' => ['standard' => 'Standard', 'full' => 'Full', 'worksafe_directed' => 'Asked for by WorkSafe'],
        'restraint_type' => ['physical' => 'Physical', 'chemical' => 'Chemical', 'mechanical' => 'Mechanical', 'seclusion' => 'Seclusion', 'environmental' => 'Environmental'],
        'plan_status' => ['draft' => 'Draft', 'active' => 'In use', 'under_review' => 'Being reviewed', 'archived' => 'Archived'],
        'election_method' => ['elected' => 'Elected', 'appointed' => 'Appointed', 'volunteered' => 'Volunteered'],
        'record_state' => [
            'intact' => 'OK', 'recorded' => 'OK', 'readable' => 'OK', 'admitted' => 'OK', 'verified' => 'OK',
            'legacy_unknown' => "Older record — some details weren't kept",
            'approval_unknown' => "Older record — some details weren't kept",
            'currency_unknown' => "Older record — some details weren't kept",
            'association_unconfirmed' => "Older record — some details weren't kept",
            'repair_required' => 'Needs fixing — ask an administrator',
            'restricted' => "Hidden — you don't have access",
            'original_context_restricted' => "Hidden — you don't have access",
            'unavailable' => 'Not available',
        ],
    ];

    /** The approved words for a stored value; unknown values are made readable, missing ones read "Not set". */
    public static function label(string $domain, string|int|null $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return $domain === 'record_state' ? 'Not available' : 'Not set';
        }
        $key = strtolower(trim((string) $value));

        return self::LABELS[$domain][$key] ?? self::humanise($value);
    }

    /** "some_new_value" → "Some new value"; camelCase and dashes too. */
    public static function humanise(string|int|null $value): string
    {
        if ($value === null) {
            return '';
        }
        $text = preg_replace('/([\p{Ll}\p{N}])(\p{Lu})/u', '$1 $2', trim((string) $value)) ?? '';
        $text = mb_strtolower(trim(preg_replace('/[_.\-\s]+/u', ' ', $text) ?? ''));

        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
