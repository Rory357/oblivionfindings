export const MODES = [
    'self_managed',
    'prompted',
    'assisted',
    'staff_given',
] as const;
export type SupportMode = (typeof MODES)[number];
export const SUPPORT = {
    self_managed: {
        label: 'Self-managed',
        description: 'The person manages it',
        recorded: 'Listed for information · nothing to record',
    },
    prompted: {
        label: 'Prompt',
        description: 'Staff remind, the person takes it',
        recorded: 'Taken with prompting',
    },
    assisted: {
        label: 'Assist',
        description: 'Staff help, the person takes it',
        recorded: 'Taken with assistance',
    },
    staff_given: {
        label: 'Administer',
        description: 'Staff give the medicine',
        recorded: 'Given',
    },
} satisfies Record<
    SupportMode,
    { label: string; description: string; recorded: string }
>;
export const SCORES = [
    {
        key: 'cognitive_capacity',
        label: 'Understanding and memory',
        help: 'Remembers what each medicine is for and when to take it',
    },
    {
        key: 'physical_dexterity',
        label: 'Hands and grip',
        help: 'Can open packs, press out tablets, use an inhaler or pen',
    },
    {
        key: 'vision_ability',
        label: 'Eyesight',
        help: 'Can read the label and tell medicines apart',
    },
    {
        key: 'swallowing_ability',
        label: 'Swallowing',
        help: 'Can swallow tablets or capsules safely',
    },
    {
        key: 'understanding_score',
        label: 'Knows the routine',
        help: 'Knows the times and what to do if a dose is missed',
    },
] as const;
export const CHECKS = [
    { key: 'can_identify_medications', label: 'Knows which medicine is which' },
    { key: 'can_read_labels', label: 'Can read and follow the label' },
    { key: 'can_open_packaging', label: 'Can open the packaging' },
    { key: 'can_manage_timing', label: 'Takes it at the right times' },
    { key: 'can_store_safely', label: 'Keeps it stored safely' },
    { key: 'willing_to_self_admin', label: 'Wants to do it and is engaged' },
] as const;
export type Assessment = Record<(typeof SCORES)[number]['key'], number | null> &
    Record<(typeof CHECKS)[number]['key'], boolean> & {
        id: number;
        outcome: string;
        supersedes_id: number | null;
        wishes_to_self_administer: boolean;
        people_involved: string[];
        storage_location: string | null;
        safe_storage_notes: string | null;
        reassessment_interval_months: number | null;
        assessment_date: string;
        reassessment_date: string | null;
        reassessment_trigger: string | null;
        assessor_notes: string | null;
        risk_factors: string | null;
        support_needed: string | null;
        support_adjustments: string[];
    };
export type Medicine = {
    id: number;
    name: string;
    dosage: string | null;
    controlled: boolean;
    mode: SupportMode;
    requested_mode: SupportMode | null;
    agreement_needed: boolean;
    is_prn: boolean;
};
export type Agreement = {
    id: number;
    agreed_by_role: 'person' | 'guardian' | 'epoa';
    agreed_by_name: string;
    method: 'signed' | 'verbal';
    witness_id: number | null;
    ordering_responsibility: string;
    person_responsibilities: string;
    staff_responsibilities: string;
    storage_notes: string | null;
    created_at: string;
    attachment_url: string | null;
};
export type SupportPlan = {
    client_id: number;
    client_name: string;
    site_id: number;
    site_name: string | null;
    state: 'none' | 'unknown' | 'current' | 'soon' | 'overdue' | 'reassess';
    cap: SupportMode;
    assessment: Assessment | null;
    medicines: Medicine[];
    concealed_count: number;
    agreement: Agreement | null;
    agreement_needed: boolean;
    reviews: { id: number; trigger: string; reason: string; due_at: string }[];
    can_assess: boolean;
    can_set_controlled: boolean;
    can_record_consent: boolean;
    url: string;
};
export type SupportHistory = Pick<
    Assessment,
    | 'id'
    | 'assessment_date'
    | 'reassessment_date'
    | 'outcome'
    | 'people_involved'
    | 'assessor_notes'
> &
    Record<(typeof SCORES)[number]['key'], number | null> &
    Record<(typeof CHECKS)[number]['key'], boolean> & {
        assessor_name: string | null;
    };
export type Change = {
    id: number;
    client_medication_id: number;
    mode: SupportMode;
    previous_mode: SupportMode | null;
    reason: string;
    notes: string | null;
    occurred_at: string;
    effective_at: string;
    recorded_by: string | null;
};
export const PLAN_STATES = {
    none: { label: 'No assessment', variant: 'warning' },
    unknown: { label: 'Review date unknown', variant: 'warning' },
    current: { label: 'Up to date', variant: 'success' },
    soon: { label: 'Reassess soon', variant: 'info' },
    overdue: { label: 'Review date passed', variant: 'warning' },
    reassess: { label: 'Reassess now', variant: 'critical' },
} as const;
export function assessmentCap(
    wishes: boolean,
    willing: boolean,
    total: number,
): SupportMode {
    if (!wishes || !willing) return 'staff_given';
    return total >= 21
        ? 'self_managed'
        : total >= 16
          ? 'prompted'
          : total >= 11
            ? 'assisted'
            : 'staff_given';
}
export const rank = (mode: SupportMode) => MODES.indexOf(mode);
export const allowedModes = (cap: SupportMode, controlled: boolean) =>
    MODES.filter((m) => rank(m) >= Math.max(rank(cap), controlled ? 2 : 0));
