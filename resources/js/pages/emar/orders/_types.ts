export type Prescription = {
    name: string;
    dosage: string;
    dose_amount: string | number | null;
    dose_unit: string | null;
    frequency: string;
    frequency_code: string | null;
    dose_times: string[];
    is_prn: boolean;
    route: string;
    form: string | null;
    instructions: string | null;
    indication: string;
    prn_reason: string | null;
    max_per_day: number | string | null;
    min_hours_between_doses: number | string | null;
    start_date: string;
    end_date: string | null;
    prescriber: string | null;
    pharmacy: string | null;
    controlled_drug: boolean;
    high_risk: boolean;
    witness_required: boolean;
};
export type Source = {
    type: string;
    prescriber?: string;
    received_at?: string;
    description?: string;
    read_back_witness_id?: number;
    read_back_at?: string;
    allergy_matches?: { allergen: string }[];
};
export type Revision = {
    id: number;
    status: string;
    entered_by: number | null;
    read_back_witness_id: number | null;
    checked_by: number | null;
    checked_at: string | null;
    lone_reason: string | null;
    second_due_at: string | null;
    second_checked_at: string | null;
    written_due_at: string | null;
    written_confirmation: { method: string; received_at: string } | null;
    allergy_confirmation: {
        prescriber: string;
        method: string;
        confirmed_at: string;
        instruction: string;
    } | null;
    rejection_reason: string | null;
    created_at: string;
    version: {
        version_number: number;
        name: string;
        dosage: string;
        prescription_payload: Prescription;
        source_evidence: Source;
        change_reason: string;
    };
    enterer: { name: string } | null;
    checker: { name: string } | null;
    witness: { name: string } | null;
    files?: { id: number; file_name: string; purpose: string }[];
};
export type Order = {
    id: number;
    client_id: number;
    site_id: number;
    person: string;
    name: string;
    dosage: string;
    frequency: string;
    dose_times: string[];
    is_prn: boolean;
    controlled: boolean;
    version: number;
    state: string;
    approval_status: string;
    end_date: string | null;
    ceased_reason: string | null;
    ceased_at: string | null;
    pending: Revision | null;
    current: Revision | null;
    can_manage: boolean;
    can_verify: boolean;
    blocked_reason: string | null;
};
export type AllergyInspection = {
    recorded: {
        allergen: string;
        severity: string | null;
        reaction: string | null;
        source: string;
    }[];
    matches: { allergen: string; severity: string | null }[];
    class_matching: string;
};
export type Detail = {
    summary: Order;
    order: Prescription & {
        id: number;
        client_id: number;
        version: number;
        state: string;
        approval_status: string;
    };
    revisions: Revision[];
    actions: {
        id: number;
        action: string;
        occurred_at: string;
        actor_id: number;
        evidence: Record<string, unknown>;
    }[];
    allergies: AllergyInspection;
    last_dose: {
        id: number;
        given_at: string;
        dose_given: string;
        by: string | null;
    } | null;
    stock: {
        id: number;
        on_hand: string;
        unit: string;
        batch_number: string | null;
        expiry_date: string | null;
    }[];
};
export type ClientChoice = {
    id: number;
    name: string;
    site_id: number;
    can_enter: boolean;
};
export type ReconciliationItem = {
    id: number;
    client_medication_id: number | null;
    medication_order_revision_id: number | null;
    medicine_name: string;
    controlled: boolean;
    decision: string | null;
    notes: string | null;
    next_dose_at: string | null;
    applied_at: string | null;
    prescriber_query_resolved_at: string | null;
    last_dose_evidence: {
        source: string;
        given_at?: string;
        dose_given?: string;
        administration_id?: number;
        external?: { source: string; given_at: string; dose_given: string };
    };
};
export type RespiteStayChoice = {
    id: number;
    client_id: number;
    status: string;
    actual_start: string | null;
    actual_end: string | null;
};
export type ReviewHandoff = {
    id: number;
    client_id: number;
    client_medication_id: number | null;
    outcome: string;
    name_snapshot: string;
    recommendation: string;
    entered: boolean;
};
export type Reconciliation = {
    id: number;
    client_id: number;
    client: { first_name: string; last_name: string };
    reason: string;
    sources: string;
    status: string;
    signed_off_at: string | null;
    items: ReconciliationItem[];
    restricted_medicines: boolean;
    can_manage: boolean;
    can_apply: boolean;
    can_sign_off: boolean;
    support_reassessment_required: boolean;
};
