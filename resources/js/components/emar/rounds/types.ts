/* Shared types for the redesigned eMAR Medication Rounds page (`/emar/rounds`).
 * Shapes mirror EmarController@rounds + GuidedRoundService. */
import type { WitnessPinStatus } from '@/lib/witness-pin';

export type RoundStatus =
    | 'pending'
    | 'in_progress'
    | 'partial'
    | 'completed'
    | string;

/** One scheduled dose in a round — powers the Chart matrix and audit timeline. */
export interface RoundCell {
    resident_id: number;
    resident_name: string;
    site_id: number | null;
    site_name: string | null;
    medication_id: number;
    medication_name: string;
    dose: string | null;
    route: string | null;
    is_controlled: boolean;
    is_high_risk: boolean;
    requires_witness: boolean;
    requires_blood_glucose: boolean;
    requires_pulse: boolean;
    scheduled_for: string;
    status: string; // given | refused | withheld | missed | due | overdue | pending_check
    witnessed_by: string | null;
    blood_glucose_level: number | null;
    pulse_bpm: number | null;
    reason: string | null;
    reason_code: string | null;
    administered_at: string | null;
    administered_by: string | null;
}

export interface RoundSummary {
    id: number;
    name: string;
    scheduled_time: string; // HH:MM
    window_minutes: number;
    status: RoundStatus;
    round_date: string | null;
    site_id: number | null;
    site_name: string | null;
    template_name: string | null;
    total_medications: number;
    given: number;
    refused: number;
    withheld: number;
    missed: number;
    assignee: string | null;
    assigned_to: number | null;
    created_at: string | null;
    started_at: string | null;
    started_by: string | null;
    completed_at: string | null;
    completed_by: string | null;
    can_complete: boolean;
    cells: RoundCell[];
}

export interface Resident {
    id: number;
    name: string;
    site_id: number | null;
    site_name: string | null;
}

export interface RoundItemAdministration {
    id: number;
    status: string;
    reason: string | null;
    reason_code: string | null;
    administered_at: string | null;
    administered_by: string | null;
    witnessed_by: string | null;
    blood_glucose_level: number | null;
    pulse_bpm: number | null;
}

export interface RoundItem {
    client_id: number;
    client_name: string;
    client_photo_url: string | null;
    medication_id: number;
    medication_name: string;
    dose: string | null;
    route: string | null;
    form: string | null;
    instructions: string | null;
    site_id: number | null;
    site_name: string | null;
    is_controlled: boolean;
    is_high_risk: boolean;
    requires_witness: boolean;
    requires_blood_glucose: boolean;
    requires_pulse: boolean;
    scheduled_for: string;
    /** The record's outcome, else due | upcoming | overdue | pending_check (as Meds today). */
    dose_state?: string;
    administration: RoundItemAdministration | null;
}

/** A cell or record status that is a recorded outcome (anything else is still to do). */
export function isRecordedStatus(status: string): boolean {
    return ['given', 'refused', 'withheld', 'held', 'missed'].includes(status);
}

/** Waiting for the order check: shown, but not recordable until the order is checked. */
export function isWaitingForCheck(item: RoundItem): boolean {
    return !item.administration && item.dose_state === 'pending_check';
}

/** A dose the worker can record next: not recorded, not waiting for the order check. */
export function isRecordable(item: RoundItem): boolean {
    return !item.administration && !isWaitingForCheck(item);
}

export interface RoundProgress {
    total: number;
    completed: number;
    pending: number;
    given: number;
    refused: number;
    held: number;
    next_index: number | null;
    percent: number;
}

export interface GuidedRound {
    can_record: boolean;
    can_start: boolean;
    can_complete: boolean;
    round: {
        id: number;
        name: string;
        status: RoundStatus;
        scheduled_time: string;
        window_minutes: number;
        round_date: string | null;
        template_name?: string | null;
        assignee?: string | null;
        created_at?: string | null;
        started_at?: string | null;
        started_by?: string | null;
        completed_at?: string | null;
        completed_by?: string | null;
    };
    items: RoundItem[];
    progress: RoundProgress;
}

export interface ActivityItem {
    id: number;
    status: string;
    medication_id: number | null;
    medication_name: string | null;
    dose: string | null;
    resident_id: number | null;
    resident_name: string | null;
    site_id: number | null;
    site_name: string | null;
    round_id: number | null;
    round_name: string | null;
    staff: string | null;
    witnessed_by: string | null;
    blood_glucose_level: number | null;
    pulse_bpm: number | null;
    reason: string | null;
    reason_code: string | null;
    scheduled_for: string | null;
    administered_at: string | null;
    time: string | null;
}

export interface StaffOption {
    id: number;
    name: string;
    /** PIN-1: witness PIN status; unusable PINs are listed but can't be chosen. */
    witness_pin?: WitnessPinStatus;
}

/** Live tallies derived from a round's cells (mirrors the design prototype). */
export interface RoundCounts {
    given: number;
    refused: number;
    held: number;
    missed: number;
    due: number;
    total: number;
    recorded: number;
    pct: number;
}

export function roundCounts(cells: RoundCell[]): RoundCounts {
    let given = 0,
        refused = 0,
        held = 0,
        missed = 0,
        due = 0;
    for (const c of cells) {
        switch (c.status) {
            case 'given':
                given++;
                break;
            case 'refused':
                refused++;
                break;
            case 'withheld':
            case 'held':
                held++;
                break;
            case 'missed':
                missed++;
                break;
            default:
                due++;
        }
    }
    const total = cells.length;
    const recorded = given + refused + held + missed;
    return {
        given,
        refused,
        held,
        missed,
        due,
        total,
        recorded,
        pct: total ? Math.round((recorded / total) * 100) : 0,
    };
}

/** Round status → display label + semantic tone suffix. */
export function roundStatusMeta(status: RoundStatus): {
    label: string;
    tone: string;
} {
    switch (status) {
        case 'completed':
            return { label: 'Complete', tone: 'success' };
        case 'in_progress':
            return { label: 'In progress', tone: 'info' };
        case 'partial':
            return { label: 'Partial', tone: 'warning' };
        default:
            return { label: 'Pending', tone: 'neutral' };
    }
}

export function roundActionLabel(status: RoundStatus): string {
    if (status === 'completed') return 'Review round';
    if (status === 'in_progress' || status === 'partial') return 'Resume round';
    return 'Start round';
}

/** Dose status → label + semantic tone (given=success, refused/held=warning, missed=critical, due=muted). */
export function doseStatusMeta(status: string): {
    label: string;
    tone: string;
} {
    switch (status) {
        case 'given':
            return { label: 'Given', tone: 'success' };
        case 'refused':
            return { label: 'Refused', tone: 'warning' };
        case 'withheld':
        case 'held':
            return { label: 'Held', tone: 'warning' };
        case 'missed':
            return { label: 'Missed (recorded)', tone: 'critical' };
        case 'overdue':
            return { label: 'Overdue', tone: 'critical' };
        case 'pending_check':
            return { label: 'Waiting for the order check', tone: 'info' };
        default:
            return { label: 'Due', tone: 'muted' };
    }
}
