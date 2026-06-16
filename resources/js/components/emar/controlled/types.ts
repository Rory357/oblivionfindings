/* Shared types for the redesigned eMAR Controlled Drugs page. Mirrors
 * EmarController@controlled. */

export interface CdMedication {
    id: number;
    name: string;
    form?: string | null;
    strength?: string | null;
    controlled_drug: boolean;
    client_id: number;
    client_name: string;
    /** Server-computed reconciliation state (always current, never day-scoped). */
    last_balance_check_at?: string | null;
    days_since_check?: number | null;
    overdue_check?: boolean;
    /** CD schedule (2/3/4) — currently always null (no column). TODO(G-F). */
    schedule?: number | null;
    stock: {
        on_hand: number | string | null;
        unit: string | null;
        last_counted_at: string | null;
        expiry_date?: string | null;
        batch_number?: string | null;
        reorder_level?: number | string | null;
    } | null;
}

export interface CdEntry {
    id: number;
    client_id: number;
    client_name: string;
    medication_name: string | null;
    controlled_drug?: boolean;
    entry_type: string;
    quantity: number | string | null;
    unit: string | null;
    on_hand_before: number | string | null;
    on_hand_after: number | string | null;
    batch_number: string | null;
    expiry_date?: string | null;
    notes: string | null;
    recorded_at: string | null;
    recorded_by_name: string | null;
    witnessed_by_name: string | null;
}

export interface CdDiscrepancy {
    id: number;
    client: { id: number; first_name: string; last_name: string } | null;
    medication: { id: number; name: string } | null;
    difference: number | string | null;
    on_hand_before?: number | string | null;
    on_hand_after?: number | string | null;
    reason: string | null;
    notes: string | null;
    status: string;
    reported_at: string | null;
    reported_by_name?: string | null;
    witnessed_by_name?: string | null;
    resolved_at?: string | null;
    resolved_by_name?: string | null;
    resolution_notes?: string | null;
    incident_id?: number | null;
    incident_title?: string | null;
    attachments: unknown[];
}

export interface CdDestruction {
    id: number;
    client_id?: number;
    client_name: string;
    medication_name: string | null;
    quantity: number | string | null;
    unit: string | null;
    reason: string | null;
    disposal_method: string | null;
    destroyed_at: string | null;
    destroyed_by_name: string | null;
    witness_name: string | null;
    witness_2_name?: string | null;
    authorised_by_name?: string | null;
    notes: string | null;
}

export interface CdLossReport {
    id: number;
    client: { id: number; first_name: string; last_name: string } | null;
    medication_name: string | null;
    quantity_lost: number | string | null;
    unit: string | null;
    circumstances: string | null;
    accountable_officer_name?: string | null;
    reported_to_police: boolean;
    police_reference: string | null;
    reported_to_pharmacy: boolean;
    pharmacy_name: string | null;
    reported_to_regulator?: boolean;
    regulator_name?: string | null;
    regulator_reference?: string | null;
    regulator_notified_at?: string | null;
    discovered_at: string | null;
    discovered_by_name?: string | null;
    investigation_status: string;
    investigation_notes: string | null;
    resolution_outcome: string | null;
    incident_id?: number | null;
    incident_title?: string | null;
    attachments: unknown[];
}

export interface ClientOption {
    id: number;
    first_name: string;
    last_name: string;
}
export interface StaffOption {
    id: number;
    name: string;
}

export const ENTRY_TYPES: { value: string; label: string }[] = [
    { value: 'receipt', label: 'Receipt (stock in)' },
    { value: 'administration', label: 'Administration' },
    { value: 'disposal', label: 'Disposal' },
    { value: 'transfer_in', label: 'Transfer in' },
    { value: 'transfer_out', label: 'Transfer out' },
    { value: 'adjustment', label: 'Adjustment' },
];

/** Whether a movement adds to (+1), removes from (−1), or doesn't constrain (0) the balance. */
export function entryDirection(type: string): 1 | -1 | 0 {
    if (type === 'receipt' || type === 'transfer_in') return 1;
    if (type === 'administration' || type === 'disposal' || type === 'transfer_out') return -1;
    return 0;
}

export function statusTone(status: string): string {
    const s = status.toLowerCase();
    if (s === 'open' || s === 'reported') return 'bg-status-critical-bg text-status-critical';
    if (s === 'under_review' || s === 'investigating') return 'bg-status-warning-bg text-status-warning';
    return 'bg-muted text-muted-foreground'; // closed / resolved
}
