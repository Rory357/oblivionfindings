import type { DoseTarget } from '@/components/emar/record-dose/types';
export type FollowupRef = { id: number; name: string };
export type LegacyEffectCheck = {
    source_key: string;
    administration_id: number;
    client: FollowupRef;
    site: FollowupRef;
    medication: FollowupRef;
    owner: FollowupRef | null;
    due_at: string | null;
    given_at: string | null;
    can_prepare: boolean;
    prepare_url?: string;
    url?: string;
    record_url: string;
};
export type LegacyEffectChecks = {
    total: number;
    overdue: number;
    unscheduled: number;
    filtered_total: number;
    data: LegacyEffectCheck[];
    has_more: boolean;
};
export type OutstandingMedicationWorkData = {
    followups?: MedicationFollowup[];
    legacy_effect_checks?: LegacyEffectChecks;
    followup_counts?: {
        open: number;
        effect: number;
        overdue: number;
        unscheduled: number;
    };
};
export type MedicationFollowup = {
    id: number;
    type: string;
    label: string;
    client: FollowupRef;
    site: { id: number; name: string | null };
    medication: FollowupRef | null;
    administration_id: number | null;
    reoffer_target?: DoseTarget | null;
    owner: FollowupRef | null;
    original_owner: FollowupRef | null;
    due_at: string | null;
    completed_at: string | null;
    state: string;
    revision: number;
    context: Record<string, unknown> | null;
    lead: boolean;
    source_owned: boolean;
    source_url: string | null;
    can_complete: boolean;
    can_reassign: boolean;
    shift_end: string | null;
    why: string | null;
    record_url: string | null;
    url: string;
    history?: {
        id: number;
        action: string;
        at: string;
        by: string | null;
        data: Record<string, unknown>;
    }[];
    candidates?: FollowupRef[];
    refusal_assessment_required?: boolean;
    refusal_count?: number;
    refusal_threshold?: number;
    refusal_days?: number;
};

/** Only source services supply navigation; never open another origin or script URL. */
export function followupSourceUrl(row: MedicationFollowup): string | null {
    const value = row.source_url;
    return value?.startsWith('/') &&
        !value.startsWith('//') &&
        !Array.from(value).some(
            (character) =>
                character === '\\' ||
                character.charCodeAt(0) <= 32 ||
                character.charCodeAt(0) === 127,
        )
        ? value
        : null;
}
