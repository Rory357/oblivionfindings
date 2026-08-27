/* Shared types for the worker-facing Meds Today board (`/meds/today`).
 * Shapes mirror the Inertia props served by Emar/WorkerMedsController. */

export interface RoundInfo {
    id: number;
    name: string;
    status: 'pending' | 'in_progress' | 'completed' | string;
    scheduled_time: string | null;
    total: number;
    completed: number;
    percent: number;
    url: string;
}

export interface ActiveRound extends RoundInfo {
    given: number;
}

export type DoseStatus =
    | 'overdue'
    | 'due'
    | 'upcoming'
    | 'given'
    | 'refused'
    | 'withheld'
    | 'missed';

export interface RecordedInfo {
    id: number;
    status: string;
    administered_at: string | null;
    time: string | null;
    by: string | null;
    witness: string | null;
    reason: string | null;
    reason_label: string | null;
    notes: string | null;
}

export interface ScheduleRow {
    key: string;
    client_id: number;
    client_name: string;
    medication_id: number;
    medication_name: string;
    dose: string | null;
    route: string | null;
    is_controlled: boolean;
    requires_witness: boolean;
    scheduled_for: string;
    time: string;
    round_label: string;
    status: DoseStatus;
    recorded: RecordedInfo | null;
    mar_url: string;
}

export interface ClientInfo {
    id: number;
    name: string;
    preferred: string | null;
    nhi: string | null;
    dob: string | null;
    age: number | null;
    site_id: number | null;
    site_name: string | null;
    allergies: string[];
}

export interface SiteInfo {
    id: number;
    name: string;
}

/** One given PRN dose in the near-limit drill-down timeline (eMAR PRN page). */
export interface PrnDose {
    id: number;
    time: string | null;
    date_label: string | null;
    dose: string | null;
    given_by: string | null;
    effectiveness: string | null;
    effectiveness_label: string | null;
}

/** Over-limit incident reference attached to an over-limit PRN med (eMAR). */
export interface PrnOverLimitIncident {
    id: number;
    status: string | null;
    occurred_label: string | null;
    url: string;
}

export interface PrnMedication {
    id: number;
    client_id: number;
    client_name: string;
    name: string;
    dose: string | null;
    route: string | null;
    form: string | null;
    instructions: string | null;
    prn_reason: string | null;
    max_per_day: number | null;
    given_last_24h: number;
    remaining_today: number | null;
    near_limit: boolean;
    over_limit: boolean;
    is_controlled: boolean;
    requires_witness: boolean;
    min_hours_between: number | null;
    last_given_at: string | null;
    last_given_label: string | null;
    next_allowed_at: string | null;
    next_allowed_label: string | null;
    interval_blocked: boolean;
    /** eMAR PRN near-limit drill-down enrichment (absent on the worker board). */
    today_doses?: PrnDose[];
    over_limit_incident?: PrnOverLimitIncident | null;
}

export interface PrnFollowUp {
    administration_id: number;
    client_id: number;
    medication_name: string | null;
    is_controlled?: boolean;
    dose_given: string | null;
    given_at: string | null;
    given_time: string | null;
    check_at: string | null;
}

export interface StockAlert {
    id: number;
    type: 'stock_low' | 'expiring_soon' | 'expired' | string;
    tone: 'crit' | 'warn';
    label: string;
    detail: string;
    is_controlled: boolean;
}

export interface ActivityItem {
    id: number;
    occurred_at: string | null;
    time: string | null;
    icon: 'check' | 'refused' | 'cd' | 'prn' | string;
    text: string;
    by: string;
}

export interface WitnessOption {
    id: number;
    name: string;
}

export interface NotGivenReasonOption {
    value: string;
    label: string;
    requires_detail: boolean;
}

export interface MedsTodayProps {
    today: string;
    date: string;
    date_label: string;
    is_today: boolean;
    server_now: string;
    now_label: string;
    stats: {
        meds_due: number;
        meds_overdue: number;
        due_now: number;
        due_later: number;
        upcoming_rounds: number;
    };
    active_round: ActiveRound | null;
    upcoming_rounds: RoundInfo[];
    rounds: RoundInfo[];
    schedule: ScheduleRow[];
    clients: ClientInfo[];
    sites: SiteInfo[];
    prn_medications: PrnMedication[];
    prn_follow_ups: PrnFollowUp[];
    stock_alerts: StockAlert[];
    activity: ActivityItem[];
    witnesses: WitnessOption[];
    not_given_reasons: NotGivenReasonOption[];
    shift_label: string | null;
    board_user: {
        first_name: string;
        name: string;
        role_label: string | null;
        med_competent: boolean;
        controlled_record: boolean;
        cd_witness: boolean;
    };
    board_can: {
        view_emar: boolean;
        view_audit: boolean;
        record_administration: boolean;
        record_controlled: boolean;
        view_controlled: boolean;
        manage_stock: boolean;
    };
    has_shift_context: boolean;
}

/** Stable per-client hue for avatar chips (golden-angle spread). */
export function clientHue(id: number): number {
    return Math.round((id * 137.508) % 360);
}

export function clientInitials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]!.toUpperCase())
        .join('');
}
