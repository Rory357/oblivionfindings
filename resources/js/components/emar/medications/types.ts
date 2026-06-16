/* Shared types for the redesigned eMAR Medications Database (`/emar/medications`).
 * Shapes mirror EmarController@medications. */

export interface MedStock {
    on_hand: number | string | null;
    unit: string | null;
    low: boolean;
}

export interface MedRow {
    id: number;
    client_id: number;
    client_name: string;
    name: string;
    brand_name: string | null;
    dosage: string | null;
    dose_unit: string | null;
    frequency: string | null;
    route: string | null;
    form: string | null;
    instructions: string | null;
    indication: string | null;
    prescriber: string | null;
    is_prn: boolean;
    prn_reason: string | null;
    max_per_day: string | number | null;
    min_hours_between_doses: number | string | null;
    controlled_drug: boolean;
    high_risk: boolean;
    witness_required: boolean;
    state: string; // active | paused | ceased
    approval_status: string | null; // verified | pending_verification | rejected
    rejection_reason: string | null;
    pharmac_therapeutic_group: string | null;
    start_date: string | null;
    stock: MedStock | null;
    interaction_severity: string | null;
    // Verification / lifecycle audit (from EmarController@medications).
    created_by_name: string | null;
    created_at: string | null;
    verified_by_name: string | null;
    verified_at: string | null;
    ceased_by_name: string | null;
    ceased_at: string | null;
    ceased_reason: string | null;
    review_date: string | null;
}

/** One stock-affecting event in the detail-modal history (lazy-loaded). */
export interface MedStockMovement {
    type: 'administration' | 'count';
    at: string | null;
    status: string | null;
    label: string;
    by: string | null;
    note: string | null;
}

/** One real per-client interaction record (lazy-loaded). */
export interface MedInteractionDetail {
    other: string;
    severity: string | null;
    severity_label: string;
    description: string | null;
    clinical_effects: string | null;
    management: string | null;
}

/** Payload of GET /emar/medications/{id}/detail. */
export interface MedDetailPayload {
    movements: MedStockMovement[];
    interactions: MedInteractionDetail[];
}

export interface ClientOption {
    id: number;
    first_name: string;
    last_name: string;
}

export interface MedCan {
    record?: boolean;
    verify_orders?: boolean;
    manage_interactions?: boolean;
    export_reports?: boolean;
}

export const MED_TABS = [
    { id: 'all', label: 'All', tone: 'primary' as const },
    { id: 'active', label: 'Active', tone: 'success' as const },
    { id: 'prn', label: 'PRN', tone: 'primary' as const },
    { id: 'controlled', label: 'Controlled', tone: 'critical' as const },
    { id: 'high_risk', label: 'High-risk', tone: 'warning' as const },
    { id: 'awaiting', label: 'Awaiting', tone: 'warning' as const },
];

export function matchesTab(med: MedRow, tab: string): boolean {
    switch (tab) {
        case 'active':
            return med.state === 'active';
        case 'prn':
            return med.is_prn;
        case 'controlled':
            return med.controlled_drug;
        case 'high_risk':
            return med.high_risk;
        case 'awaiting':
            return med.approval_status === 'pending_verification';
        default:
            return true;
    }
}

/** A simple client-side dose-time preview from a free-text frequency. The real
 *  schedule is computed server-side (DoseSchedulingService) on save. */
export function previewDoseTimes(frequency: string, isPrn: boolean): string[] | null {
    if (isPrn) return null;
    const f = frequency.toLowerCase();
    if (/\b(stat|once only|prn|as needed|as required)\b/.test(f)) return null;
    if (/four times|qid|4x/.test(f)) return ['08:00', '12:00', '16:00', '20:00'];
    if (/three times|tds|tid|3x/.test(f)) return ['08:00', '13:00', '18:00'];
    if (/twice|bd|bid|2x/.test(f)) return ['08:00', '20:00'];
    if (/once|daily|od|mane|1x/.test(f)) return ['08:00'];
    if (/night|nocte|bedtime/.test(f)) return ['22:00'];
    return null;
}
