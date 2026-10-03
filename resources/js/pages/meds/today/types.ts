import type { MyEligibilityData } from '@/pages/emar/eligibility/_my-eligibility';
/* Shared types for the worker-facing Meds Today board (`/meds/today`).
 * Shapes mirror the Inertia props served by Emar/WorkerMedsController. */
import type { WitnessPinStatus } from '@/lib/witness-pin';

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
    | 'missed'
    /** Waiting for the order check: the order's change awaits verification. */
    | 'pending_check'
    /** Away (checked in at respite at another Site): shown with its reason, never due or overdue. */
    | 'away';

/**
 * A dose waiting for the order check is shown but never overdue, and can't
 * be recorded until the order is checked (the server refuses it too).
 */
export function awaitsOrderCheck(row: { status: DoseStatus }): boolean {
    return row.status === 'pending_check';
}

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
    /** P01 recording contract facts (Meds today state lines). */
    dose_given?: string | null;
    amount_mode?: 'as_ordered' | 'less' | 'more' | null;
    late_reason?: string | null;
    second_person_kind?: 'witness' | 'rule' | 'cosigner' | 'amount' | null;
    second_person_status?: 'verified' | 'not_confirmed' | null;
    reoffer_of_id?: number | null;
    review_reason_key?: string | null;
}

/**
 * What recording a dose needs and allows for this worker, as row keys
 * (DoseRecordingRequirements::forBoard) — the same answer the dialog reads.
 */
export interface BoardRequirements {
    block_all: string | null;
    block_given: string | null;
    competency:
        | 'current'
        | 'expired'
        | 'not_current'
        | 'restricted'
        | 'cosigner'
        | 'area'
        | null;
    second_person: 'witness' | 'rule' | 'cosigner' | null;
    witness_available: boolean;
    allergy_match: boolean;
    not_simple: string[];
    window: 'notdue' | 'due' | 'late' | null;
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
    /** Canonical projection state; list status also includes the due-soon horizon. */
    state?: string;
    due_soon?: boolean;
    time: string;
    round_label: string;
    status: DoseStatus;
    /** Why the person is away, e.g. "Respite at another house (since Mon 15 Jun, 7:00 am)"; set when status is away. */
    away_reason?: string | null;
    recorded: RecordedInfo | null;
    /** Null when this worker may not open the resident's MAR (not assigned, no clocked-in covering shift). */
    mar_url: string | null;
    /** The dose's own window (P01 C3 state lines). */
    window_opens_at?: string;
    window_ends_at?: string;
    /** Open doses only: what recording needs and allows for this worker. */
    req?: BoardRequirements | null;
    /** A refused dose's open follow-up (from refusal_follow_ups). */
    follow_up?: { owner: string | null; due_time: string | null } | null;
}

/** An open refusal follow-up (P01: owned by whoever recorded the refusal). */
export interface RefusalFollowUp {
    id: number;
    refusal_id: number;
    client_id: number;
    preferred: string;
    medication_id: number;
    medication_name: string | null;
    is_controlled: boolean;
    scheduled_for: string | null;
    refused_time: string | null;
    due_at: string | null;
    due_time: string | null;
    overdue: boolean;
    owner: string | null;
    escalated: boolean;
}

/** Meds today › Activity: one recorded dose (server-paginated, 10 a page). */
export interface ActivityRow {
    id: number;
    client_id: number;
    preferred: string;
    surname: string | null;
    photo_url: string | null;
    at: string;
    time: string;
    day: string | null;
    medication_name: string | null;
    is_controlled: boolean;
    status: string;
    outcome: string;
    by: string | null;
    detail: string | null;
}

export interface ActivityPage {
    data: ActivityRow[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
}

/** An as-needed dose recorded today (Meds today › As-needed). */
export interface PrnRecorded {
    id: number;
    client_id: number;
    medication_name: string | null;
    status: string;
    time: string | null;
    dose_given: string | null;
    reason: string | null;
    by: string | null;
    check_at: string | null;
    effect_recorded: boolean;
}

export interface OnCallContact {
    configured: boolean;
    name: string | null;
    phone: string | null;
    warning: string | null;
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
    /** Medication allergy register + health profile allergy labels. */
    allergies: string[];
    /**
     * Whether `allergies` could be read. An empty list is "none recorded",
     * never a confirmed "no known allergies".
     */
    allergy_status?: 'recorded' | 'none_recorded' | 'unavailable';
}

/**
 * The organisation's restricted-competency rule as it applies to the signed-in
 * worker (NF-03). Null/absent when no rule applies. The server enforces it
 * when a dose is signed; this only shows the reason up front.
 */
export interface CompetencyNotice {
    requires_cosigner: boolean;
    blocked: boolean;
    message: string;
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
    /** When to check whether it helped: the time the recorder chose (P01). */
    check_due_at?: string | null;
    check_at: string | null;
    by?: string | null;
    given_label?: string | null;
    effect_check_due_at?: string | null;
    overdue?: boolean;
    owner_id?: number | null;
    owner_name?: string | null;
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
    /** PIN-1: people without a usable witness PIN are listed but can't be chosen. */
    witness_pin?: WitnessPinStatus;
}

export interface NotGivenReasonOption {
    value: string;
    label: string;
    requires_detail: boolean;
}

export interface MedsTodayProps {
    guidedRound?: import('@/components/emar/rounds/types').GuidedRound | null;
    /** P11: the worker's own medication eligibility (the "My eligibility" meter). */
    my_eligibility?: MyEligibilityData | null;
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
        competency_notice?: CompetencyNotice | null;
        /** PIN-1: the viewer's own witness PIN status (prompt to set one). */
        witness_pin?: WitnessPinStatus;
    };
    board_can: {
        export_round?: boolean;
        view_emar: boolean;
        view_audit: boolean;
        record_administration: boolean;
        record_controlled: boolean;
        view_controlled: boolean;
        manage_stock: boolean;
    };
    has_shift_context: boolean;
    /** People on the worker's shift whose medicines show once they're clocked in to it. */
    people_after_clock_in?: number;
    /** Controlled doses this day not listed for this reader (EM-12). */
    hidden_controlled_doses?: number;
    /** …and how many of those are overdue (counted by the badge). */
    hidden_controlled_overdue?: number;
    concealed_schedule?: {
        total: number;
        overdue: number;
        due_now?: number;
        open?: number;
        waiting?: number;
        due_so_far?: number;
        recorded_so_far?: number;
    };
    /** P01 C3 (approved Meds today). */
    clocked_in?: boolean;
    house_label?: string | null;
    on_call?: OnCallContact | null;
    /** Due or late doses of people you may open who aren't on your shift. */
    off_shift?: ScheduleRow[];
    refusal_follow_ups?: RefusalFollowUp[];
    prn_recorded_today?: PrnRecorded[];
    activity_page?: ActivityPage;
    board_extra_can?: { report_error: boolean; view_handovers: boolean };
    /** People whose medication record (MAR) this worker may open. */
    mar_client_ids?: number[];
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
