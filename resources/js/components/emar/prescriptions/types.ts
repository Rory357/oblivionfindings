/* Shared types for the redesigned eMAR Prescriptions & Orders page. Mirrors
 * EmarController@prescriptions. */

export interface PrescriptionOrder {
    id: number;
    client_id: number;
    client_name: string;
    client_room: string | null;
    client_site: string | null;
    client_medication_id: number | null;
    order_type: string; // new | change | cease | verbal | telephone
    status: string; // pending | confirmed | dispensed | cancelled | expired
    prescriber_name: string | null;
    prescriber_registration: string | null;
    prescriber_type: string | null;
    medication_name: string | null;
    dose: string | null;
    route: string | null;
    frequency: string | null;
    indication: string | null;
    instructions: string | null;
    order_date: string | null;
    effective_date: string | null;
    expiry_date: string | null;
    requires_countersign: boolean;
    countersigned_at: string | null;
    countersigned_by_name: string | null;
    countersign_method: string | null;
    countersign_due_at: string | null;
    read_back_confirmed: boolean;
    received_by_name: string | null;
    dispensed_at: string | null;
    dispensed_by_name: string | null;
    pharmacy_name: string | null;
    batch_number: string | null;
    batch_expiry: string | null;
}

export interface CovertAuth {
    id: number;
    client_id: number;
    client_name: string;
    medication_name: string | null;
    authorised_by_name: string | null;
    authorised_by_registration: string | null;
    clinical_justification: string | null;
    legal_basis: string | null;
    administration_method: string | null;
    pharmacist_advice: string | null;
    authorised_date: string | null;
    review_date: string | null;
    recorded_by_name: string | null;
    review_overdue: boolean;
}

export interface ClientOption {
    id: number;
    first_name: string;
    last_name: string;
    site_name?: string | null;
}
export interface MedOption {
    id: number;
    name: string;
    client_id: number;
}
export interface StaffOption {
    id: number;
    name: string;
}

export function orderStatusTone(status: string): string {
    switch (status) {
        case 'dispensed':
            return 'bg-status-success-bg text-status-success';
        case 'confirmed':
            return 'bg-status-info-bg text-status-info';
        case 'expired':
            return 'bg-status-critical-bg text-status-critical';
        case 'cancelled':
            return 'bg-muted text-muted-foreground';
        default:
            return 'bg-status-warning-bg text-status-warning'; // pending
    }
}

export function needsCountersign(o: PrescriptionOrder): boolean {
    return o.requires_countersign && !o.countersigned_at;
}

/** Hours remaining (negative = overdue) for the 24h countersign window. */
export function countersignHoursLeft(o: PrescriptionOrder): number | null {
    if (!o.countersign_due_at) return null;
    const due = new Date(o.countersign_due_at).getTime();
    if (Number.isNaN(due)) return null;
    return Math.round((due - Date.now()) / (1000 * 60 * 60));
}
