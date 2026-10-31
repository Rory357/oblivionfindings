/**
 * Health and safety plain-language labels — the ONE place H&S stored values become the words people read.
 *
 * Wording source of truth: docs/health-safety-build/vocabulary.md (approved 29 Oct 2026).
 *
 * ⚠️ Server mirror: `App\Support\HealthSafety\HsLabels::LABELS` holds the same domain → value → label maps for
 * server-made text. Both must change together: `tests/fixtures/health-safety/labels.json` is the shared contract, and
 * `hs-labels.test.ts` and `tests/Unit/HealthSafety/HsLabelsTest.php` each check their map equals it.
 *
 * Rules the helpers keep:
 *   - sentence case, NZ English;
 *   - an unknown value never shows snake_case: it is made readable ("some_new_value" → "Some new value");
 *   - a missing value reads "Not set" (labels) or "Not available" (record states), never a blank or a 0.
 */
import type { StatusVariant } from '@/components/ui/status-badge';

export const HS_LABELS = {
    severity: { low: 'Low', medium: 'Medium', high: 'High', critical: 'Critical' },
    injury_severity: {
        minor: 'Minor',
        moderate: 'Moderate',
        serious: 'Serious',
        critical: 'Critical',
    },
    risk_level: { low: 'Low', medium: 'Medium', high: 'High', extreme: 'Extreme' },
    likelihood: {
        rare: 'Rare',
        unlikely: 'Unlikely',
        possible: 'Possible',
        likely: 'Likely',
        almost_certain: 'Almost certain',
    },
    action_priority: { low: 'Low', medium: 'Medium', high: 'High', critical: 'Urgent' },
    action_status: {
        open: 'To do',
        in_progress: 'In progress',
        completed: 'Done — waiting to be checked',
        verified: 'Checked',
        closed: 'Closed',
    },
    action_type: {
        corrective: 'Fix the cause',
        preventive: 'Stop it happening',
        improvement: 'Improvement',
    },
    review_category: {
        incident: 'Incident',
        near_miss: 'Near miss',
        hazard: 'Hazard',
        injury: 'Injury',
        exposure: 'Chemical exposure',
        restraint: 'Restraint',
        safeguarding: 'Safeguarding',
        vehicle_incident: 'Vehicle incident',
        drill_failure: 'Drill problem',
        inspection_failure: 'Inspection problem',
        equipment_fault: 'Equipment fault',
    },
    review_status: {
        open: 'Open',
        investigating: 'Being investigated',
        corrective_action: 'Actions under way',
        monitoring: 'Checking it worked',
        closed: 'Closed',
    },
    investigation_status: {
        draft: 'Draft',
        in_progress: 'In progress',
        findings_recorded: 'Findings written',
        under_review: 'With reviewer',
        reviewed: 'Reviewed',
        completed: 'Done',
    },
    investigation_type: {
        standard: 'Standard',
        full: 'Full',
        worksafe_directed: 'Asked for by WorkSafe',
    },
    restraint_type: {
        physical: 'Physical',
        chemical: 'Chemical',
        mechanical: 'Mechanical',
        seclusion: 'Seclusion',
        environmental: 'Environmental',
    },
    plan_status: {
        draft: 'Draft',
        active: 'In use',
        under_review: 'Being reviewed',
        archived: 'Archived',
    },
    election_method: {
        elected: 'Elected',
        appointed: 'Appointed',
        volunteered: 'Volunteered',
    },
    record_state: {
        intact: 'OK',
        recorded: 'OK',
        readable: 'OK',
        admitted: 'OK',
        verified: 'OK',
        legacy_unknown: 'Older record — some details weren’t kept',
        approval_unknown: 'Older record — some details weren’t kept',
        currency_unknown: 'Older record — some details weren’t kept',
        association_unconfirmed: 'Older record — some details weren’t kept',
        repair_required: 'Needs fixing — ask an administrator',
        restricted: 'Hidden — you don’t have access',
        original_context_restricted: 'Hidden — you don’t have access',
        unavailable: 'Not available',
    },
} as const;

export type HsLabelDomain = keyof typeof HS_LABELS;
type Maybe<T> = T | null | undefined;

/** "some_new_value" → "Some new value"; camelCase and dashes too. */
export function humaniseHsValue(value: Maybe<string | number>): string {
    if (value === null || value === undefined) return '';
    const text = String(value)
        .trim()
        .replace(/([\p{Ll}\p{N}])(\p{Lu})/gu, '$1 $2')
        .replace(/[_.\-\s]+/g, ' ')
        .trim()
        .toLowerCase();
    return text ? text.charAt(0).toUpperCase() + text.slice(1) : '';
}

/** The approved words for a stored value; unknown values are made readable, missing ones read "Not set". */
export function hsLabel(domain: HsLabelDomain, value: Maybe<string | number>): string {
    if (value === null || value === undefined || String(value).trim() === '') {
        return domain === 'record_state' ? 'Not available' : 'Not set';
    }
    const map = HS_LABELS[domain] as Record<string, string>;
    const key = String(value).trim().toLowerCase();
    return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : humaniseHsValue(value);
}

/** Badge colours, one per scale (DESIGN: StatusBadge for every status and severity). */
const VARIANTS: Partial<Record<HsLabelDomain, Record<string, StatusVariant>>> = {
    severity: { low: 'neutral', medium: 'warning', high: 'critical', critical: 'critical' },
    injury_severity: { minor: 'neutral', moderate: 'warning', serious: 'critical', critical: 'critical' },
    risk_level: { low: 'success', medium: 'warning', high: 'critical', extreme: 'critical' },
    action_priority: { low: 'neutral', medium: 'info', high: 'warning', critical: 'critical' },
    action_status: {
        open: 'neutral',
        in_progress: 'info',
        completed: 'warning',
        verified: 'success',
        closed: 'neutral',
    },
    review_status: {
        open: 'info',
        investigating: 'warning',
        corrective_action: 'warning',
        monitoring: 'info',
        closed: 'neutral',
    },
    plan_status: { draft: 'neutral', active: 'success', under_review: 'warning', archived: 'neutral' },
    record_state: {
        legacy_unknown: 'warning',
        approval_unknown: 'warning',
        currency_unknown: 'warning',
        association_unconfirmed: 'warning',
        repair_required: 'critical',
        restricted: 'neutral',
        original_context_restricted: 'neutral',
        unavailable: 'neutral',
    },
};

/** The StatusBadge variant for a value (neutral when the scale has no colour for it). */
export function hsVariant(domain: HsLabelDomain, value: Maybe<string>): StatusVariant {
    const key = String(value ?? '').trim().toLowerCase();
    return VARIANTS[domain]?.[key] ?? 'neutral';
}

/** `{ label, variant }` ready for `<StatusBadge variant={…}>{label}</StatusBadge>`. */
export function hsBadge(domain: HsLabelDomain, value: Maybe<string>): { label: string; variant: StatusVariant } {
    return { label: hsLabel(domain, value), variant: hsVariant(domain, value) };
}
