import type { WitnessPinStatus } from '@/lib/witness-pin';

export type ControlledAction =
    | 'count'
    | 'movement'
    | 'void'
    | 'resolve'
    | 'loss_report'
    | 'loss_note'
    | 'loss_notify'
    | 'loss_close'
    | 'destruction'
    | 'destruction_receipt'
    | 'destruction_void'
    | 'class_review'
    | 'witness_request'
    | 'witness_answer'
    | 'witness_cancel'
    | 'override_request'
    | 'override_decide'
    | 'override_signoff';
export type ResolutionOutcome =
    | 'recount'
    | 'recording'
    | 'found'
    | 'loss'
    | 'escalate';
export interface ControlledMedicine {
    id: number;
    client_id: number;
    client_name: string;
    site_id: number;
    site_name: string;
    name: string;
    unit: string;
    balance: number | null;
    entry_version: number | null;
    nz_class: 'A' | 'B' | 'C' | null;
    class_review_required: boolean;
    can_record: boolean;
    record_reason?: string | null;
    count: {
        state:
            | 'due'
            | 'overdue'
            | 'counted'
            | 'next'
            | 'not_configured'
            | 'notConfigured';
        title: string;
        due_at: string | null;
        overdue_at: string | null;
        last_at: string | null;
        last_entry_id?: number | null;
    };
}
export interface ControlledWitness {
    id: number;
    name: string;
    eligible: boolean;
    reason: string | null;
    pin_status: WitnessPinStatus;
}
export interface ControlledEntry {
    id: number;
    client_medication_id: number;
    entry_type: string;
    quantity: number | null;
    on_hand_before: number | null;
    on_hand_after: number | null;
    recorded_at: string;
    recorded_by_name: string | null;
    witnessed_by_name: string | null;
    second_witness_name?: string | null;
    notes: string | null;
    voided_at?: string | null;
    void_reason?: string | null;
    voided_by_name?: string | null;
    void_witness_name?: string | null;
    corrects_entry_id?: number | null;
    can_void?: boolean;
}
export interface ControlledDiscrepancy {
    id: number;
    client_medication_id: number;
    status: string;
    expected_balance: number | null;
    actual_balance: number | null;
    first_count?: number | null;
    recount_balance?: number | null;
    reported_at: string;
    reported_by_name: string | null;
    witnessed_by_name: string | null;
    owner_name?: string | null;
    notes: string | null;
    immediate_action_taken?: string | null;
    outcome?: string | null;
    resolution_notes?: string | null;
    resolved_at?: string | null;
    resolved_by_name?: string | null;
    incident_id?: number | null;
    can_resolve: boolean;
    resolve_reason?: string | null;
}
export interface ControlledLossNote {
    id: number;
    notes: string;
    created_at: string;
    created_by_name: string | null;
}
export interface ControlledLoss {
    id: number;
    client_medication_id: number;
    quantity: number;
    status: string;
    discovered_at: string;
    circumstances: string;
    immediate_action_taken: string | null;
    reported_by_name?: string | null;
    witnessed_by_name?: string | null;
    incident_id?: number | null;
    reported_to_police: boolean;
    suspected_theft?: boolean;
    police_reference: string | null;
    police_notified_at?: string | null;
    police_notified_by_name?: string | null;
    reported_to_regulator: boolean;
    regulator_reference?: string | null;
    regulator_notified_at?: string | null;
    regulator_notified_by_name?: string | null;
    resolution_outcome?: string | null;
    resolution_notes?: string | null;
    closed_at?: string | null;
    notes: ControlledLossNote[];
}
export interface ControlledDestruction {
    id: number;
    client_medication_id: number;
    quantity: number;
    reason: string;
    method: 'pharmacy_return' | 'denaturing';
    recorded_at: string;
    recorded_by_name?: string | null;
    witnessed_by_name?: string | null;
    second_witness_name?: string | null;
    notes?: string | null;
    pharmacist_name?: string | null;
    pharmacist_registration?: string | null;
    received_at?: string | null;
    voided_at?: string | null;
    void_reason?: string | null;
    photo?: {
        url: string;
        download_url: string;
        name: string;
        mime_type: string | null;
        size?: number | null;
    } | null;
}
export interface ControlledWitnessRequest {
    id: number;
    site_id: number;
    client_medication_id?: number | null;
    requested_by_name: string;
    requested_by_id?: number;
    witness_id: number;
    witness_name: string;
    status: string;
    reason?: string | null;
    response?: 'on_my_way' | 'cant_come' | null;
    created_at: string;
    can_answer?: boolean;
    can_cancel?: boolean;
}
export interface ControlledOverrideDose {
    administration_id: number;
    client_medication_id: number;
    administered_at: string;
    administered_by_name: string | null;
    quantity?: number | null;
    signed_off_at?: string | null;
    signed_off_by_name?: string | null;
    counted_entry_id?: number | null;
}
export interface ControlledOverride {
    id: number;
    site_id: number;
    site_name: string;
    requested_at: string;
    requested_by_name: string;
    reason: string;
    status: string;
    medicine_ids: number[];
    starts_at?: string | null;
    expires_at?: string | null;
    decided_by_name?: string | null;
    decision_notes?: string | null;
    followup_due_at?: string | null;
    followup_overdue?: boolean;
    can_signoff?: boolean;
    signoff_reason?: string | null;
    doses: ControlledOverrideDose[];
}
export interface ControlledProductPayload {
    filters?: {
        site_id: number | null;
        client_medication_id: number | null;
        client_id: number | null;
        date: string | null;
    };
    people?: { id: number; name: string }[];
    medicines: ControlledMedicine[];
    sites: { id: number; name: string }[];
    witnesses_by_site: Record<string, ControlledWitness[]>;
    entries: ControlledEntry[];
    discrepancies: ControlledDiscrepancy[];
    losses: ControlledLoss[];
    destructions: ControlledDestruction[];
    requests: ControlledWitnessRequest[];
    overrides: ControlledOverride[];
    current_user_id: number;
    current_user_name: string;
    on_site_destruction_allowed: boolean;
    can: {
        view?: boolean;
        record: boolean;
        manage: boolean;
        override: boolean;
        close_loss: boolean;
    };
    cadence: {
        configured: boolean;
        label?: string | null;
        overdue_after_minutes?: number | null;
    };
    as_at?: string;
    history_limit?: number;
    history_has_more?: Partial<
        Record<
            | 'entries'
            | 'discrepancies'
            | 'losses'
            | 'destructions'
            | 'overrides',
            boolean
        >
    >;
    meters?: {
        controlled_attention_count?: number;
        controlled_attention_alert?: boolean;
        total_open_discrepancies: number;
        total_open_losses: number;
        total_awaiting_receipts: number;
        total_class_reviews: number;
        total_overdue_counts: number;
        total_medicines: number;
        total_waiting_overrides?: number;
        total_pending_followups?: number;
        total_overdue_followups?: number;
    };
}
export type ControlledActionValues = Record<
    string,
    string | number | boolean | null | number[] | File
>;
export interface ControlledActionResult {
    message?: string;
    counted_entry_id?: number;
    entry_id?: number;
    target_id?: number;
    discrepancy_id?: number | null;
    payload?: ControlledProductPayload;
}
