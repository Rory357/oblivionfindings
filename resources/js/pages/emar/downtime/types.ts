export type Site = { id: number; name: string };
export type Downtime = {
    id: number;
    site: string;
    description: string;
    started_at: string;
    ended_at: string;
    finished_at: string | null;
};
export type Snapshot = {
    person: string;
    medicine: string;
    dosage: string;
    route: string | null;
    controlled: boolean;
    second_person_required: boolean;
    observation_keys: string[];
    dose_amount?: number | null;
    dose_unit?: string | null;
    observation_choices?: {
        key: string;
        label: string;
        unit: string | null;
        fields: string[];
    }[];
};
export type Dose = {
    id: number;
    client_medication_id: number;
    scheduled_for: string;
    snapshot: Snapshot;
    entry_id: number | null;
    resolution: { reason: string; resolved_by: string } | null;
    resolution_choices: {
        kind: 'clinical' | 'paper';
        id: number;
        label: string;
        href: string | null;
        actual_at: string | null;
    }[];
};
export type PrnOrder = {
    id: number;
    person: string;
    medicine: string;
    dosage: string;
    second_person_required?: boolean;
    observation_keys?: string[];
    dose_amount?: number | null;
    dose_unit?: string | null;
    observation_choices?: Snapshot['observation_choices'];
};
export type Staff = { id: number; name: string };
export type PaperClinicalFacts = {
    quantity_given: string;
    amount_mode: '' | 'as_ordered' | 'less' | 'more';
    amount_reason: string;
    late_reason: string;
    prn_reason: string;
    effect_check_due_at: string;
    more_severity: string;
    more_immediate_action: string;
};
export type PaperStockFacts = {
    stock_id: number | null;
    unit: string;
    quantity_removed: string;
    quantity_wasted: string;
    waste_reason: string;
    lines: { lot_id: number; quantity: string; quantity_wasted: string }[];
};
export type RecoveryStock = {
    stock_id: number;
    unit: string;
    lots: {
        id: number;
        batch_number: string | null;
        expiry_date: string | null;
        state: string;
        quantity_remaining: number;
        revision: number;
    }[];
    unavailable?: string | null;
    clinical_unavailable?: string | null;
    can_record_settlement: boolean;
    reviewed_counts: {
        id: number;
        label: string;
        lines: { lot_id: number; revision: number; closing_quantity: number }[];
    }[];
};
export type Conflict = { kind: string; message: string };
export type Reconciliation = {
    state:
        | 'giver_to_confirm'
        | 'witness_to_confirm'
        | 'ready_to_reconcile'
        | 'entered_from_paper';
    conflicts: Conflict[];
    unavailable: string | null;
    can_reconcile: boolean;
    preview_token: string;
    stock_evidence?: RecoveryStock | null;
    missing_evidence?: string[];
    missing_actual_fields?: { key: string; label: string }[];
    stock_settlement?: {
        id?: number;
        request_uuid?: string;
        evidence: {
            settlement: 'deduct_now' | 'covered_by_count';
            closing_count_id?: number | null;
            lines: {
                lot_id: number;
                revision: number;
                closing_quantity: number;
            }[];
        };
        reviewed_by: { id: number; name: string };
        reviewed_at: string;
        fingerprint: string;
    } | null;
    stock_settlement_history?: NonNullable<
        Reconciliation['stock_settlement']
    >[];
    recovery_authorization?: {
        reviewer_id: number | null;
        reviewer_name: string | null;
        reviewed_at: string | null;
        reason: string | null;
        can_authorize: boolean;
        unavailable: string | null;
    } | null;
};
export type PaperEntry = {
    id: number;
    snapshot: Snapshot;
    outcome: string;
    given_at: string;
    entered_at: string;
    given_by: string;
    entered_by: string;
    witness: string | null;
    dose_on_paper: string | null;
    notes: string | null;
    can_confirm_giver: boolean;
    can_confirm_witness: boolean;
    reconciliation: Reconciliation;
    clinical_facts?: PaperClinicalFacts | null;
    stock_evidence?: PaperStockFacts | null;
};
export type CollectionPreview = {
    preview_token: string;
    conflicts: Conflict[];
    errors: string[];
    can_submit: boolean;
    giver_confirmation_needed: boolean;
    second_person_required: boolean;
    notice: string;
};
export type PackPreview = {
    site: Site;
    nz_date: string;
    purpose: string;
    controlled_notice: string | null;
    first_page: {
        name: string;
        scheduled: {
            id: number;
            ordered_time: string;
            medicine: string;
            dosage: string;
            second_person_required: boolean;
            readings: string[];
        }[];
    } | null;
};
