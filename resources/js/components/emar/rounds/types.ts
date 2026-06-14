/* Shared types for the redesigned eMAR Medication Rounds page (`/emar/rounds`).
 * Shapes mirror EmarController@rounds + GuidedRoundService. */

export type RoundStatus = 'pending' | 'in_progress' | 'partial' | 'completed' | string;

export interface RoundSummary {
    id: number;
    name: string;
    scheduled_time: string; // HH:MM
    window_minutes: number;
    status: RoundStatus;
    round_date: string | null;
    total_medications: number;
    given: number;
    refused: number;
    withheld: number;
    missed: number;
    assignee: string | null;
    assigned_to: number | null;
    started_at: string | null;
    completed_at: string | null;
}

export interface RoundTemplate {
    id: number;
    name: string;
    scheduled_time: string; // HH:MM
    window_minutes: number;
    days_of_week: number[]; // ISO 1-7 (Mon-Sun); [] = every day
    active: boolean;
    site_id: number | null;
    service_context_id: number | null;
    default_assigned_to: number | null;
    default_staff: string | null;
}

export interface RoundItemAdministration {
    id: number;
    status: string;
    reason: string | null;
    administered_at: string | null;
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
    is_controlled: boolean;
    is_high_risk: boolean;
    requires_witness: boolean;
    scheduled_for: string;
    administration: RoundItemAdministration | null;
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
    round: {
        id: number;
        name: string;
        status: RoundStatus;
        scheduled_time: string;
        window_minutes: number;
        round_date: string | null;
    };
    items: RoundItem[];
    progress: RoundProgress;
}

export interface ActivityItem {
    id: number;
    status: string;
    medication_name: string | null;
    staff: string | null;
    time: string | null;
}

export interface StaffOption {
    id: number;
    name: string;
}

/** Round status → display label + tone token suffix. */
export function roundStatusMeta(status: RoundStatus): { label: string; tone: string } {
    switch (status) {
        case 'completed':
            return { label: 'Completed', tone: 'success' };
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
