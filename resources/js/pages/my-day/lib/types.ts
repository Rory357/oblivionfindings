/* -------------------------------------------------------------------------- */
/*  TypeScript types for the /my-day Inertia payload                          */
/* -------------------------------------------------------------------------- */
/*
 * Mirrors `MyTasksController::__invoke` + the resources it builds. Keep this
 * in sync when the server payload changes.
 */

import type { Category, RunDetail } from '@/components/checklists/types';

export interface MyDayResident {
    id: number;
    first_name: string;
    name: string;
    initials: string;
    /** Stable hue 0–360 (see app/Support/ResidentHue.php). */
    hue: number;
    photo_url: string | null;
    care_note_preview?: string | null;
    task_count?: number;
    med_count?: number;
}

export interface MyDayActiveSite {
    id: number;
    name: string;
    type: string;
    address: string;
    href: string;
    residents: MyDayResident[];
}

export interface MyDayShiftClient {
    id: number;
    name: string;
    first_name?: string;
    photo_url: string | null;
}

export interface MyDayShiftTask {
    id: number;
    label: string;
    scheduled_time?: string | null;
    scheduled_for?: string | null;
    is_completed: boolean;
    completed_at: string | null;
    /** Resident this task belongs to (derived from shift.client_id for now). */
    client_id?: number;
}

export interface MyDayShift {
    id: number;
    starts_at: string;
    ends_at: string;
    actual_starts_at: string | null;
    actual_ends_at: string | null;
    status: string;
    status_state?: string;
    location: string | null;
    service_type: string | null;
    client: MyDayShiftClient;
    tasks: MyDayShiftTask[];
    task_progress: number;
    is_today: boolean;
    site?: { id: number; name: string; address: string; type: string } | null;
}

export interface MyDayMedDue {
    /**
     * Per-occurrence id: `"{medication_id}:{scheduled_for}"`. Unique per dose
     * slot so two doses of the same medication in the window (e.g. Paracetamol
     * 09:00 + 13:00) get distinct React keys. Not the ClientMedication id —
     * use `medication_id` to address the action endpoints.
     */
    id: string;
    /** ClientMedication id — the route-model-bound target for administer/refuse/snooze. */
    medication_id: number;
    client_id: number;
    client_name: string;
    medication_name: string;
    dose: string;
    route?: string;
    scheduled_for: string;
    status: 'overdue' | 'due' | 'upcoming' | 'given' | 'refused' | 'withheld';
    flag?: string | null;
    emar_url: string;
}

export type TimesheetAllocationMethod =
    | 'single'
    | 'residential_house'
    | 'equal_split'
    | 'manual'
    | 'time_segmented';

export interface MyDayTimesheetClientAllocation {
    /** Existing DB row id when persisted; null for synthesised single-row reads. */
    id: number | null;
    client_id: number;
    hours: number;
    allocation_method: TimesheetAllocationMethod;
    starts_at: string | null;
    ends_at: string | null;
    notes: string | null;
    sort_order: number;
}

export interface MyDayTimesheetClientCandidate {
    id: number;
    name: string;
    /** True when this is the timesheet's primary client (shift.client_id). */
    is_primary: boolean;
}

export interface MyDayTimesheet {
    id: number;
    work_date: string;
    work_date_iso: string | null;
    client_name: string | null;
    client_id: number | null;
    hours: number;
    status: 'draft' | 'submitted' | 'returned';
    return_notes: string | null;
    starts_at: string | null;
    ends_at: string | null;
    break_minutes: number;
    mileage_km: number | null;
    notes: string | null;
    is_residential_billable?: boolean;
    can_edit_inline?: boolean;
    needs?: string | null;
    /**
     * Materialised per-client breakdown of the timesheet's total hours. The
     * controller synthesises a single-row representation for legacy data, so
     * the array is always at least one element long.
     */
    client_allocations: MyDayTimesheetClientAllocation[];
    /** Default allocation method tile to highlight when the popup opens. */
    allocation_method: TimesheetAllocationMethod;
    /** Eligible clients the worker can attribute time to on this timesheet. */
    clients_candidates: MyDayTimesheetClientCandidate[];
}

export interface MyDayIncident {
    id: number;
    title: string;
    /** Truncated free-text summary shown under the title in the Needs You digest. */
    description?: string | null;
    client_name: string | null;
    client_id?: number | null;
    severity: string;
    status: string;
    occurred_at: string;
    url: string;
    requires_followup: boolean;
}

export interface MyDayTaskFollowup {
    id: string;
    type: 'alert' | 'incident' | 'followup' | 'note_followup';
    title: string;
    /** Truncated free-text summary shown under the title in the Needs You digest. */
    description?: string | null;
    priority: 'critical' | 'high' | 'medium' | 'low';
    status: string;
    source_url: string;
    due_at: string | null;
    created_at: string;
    meta: {
        source?: string;
        client_name?: string;
        client_id?: number;
        sla_status?: 'on_track' | 'at_risk' | 'breached';
        asset_name?: string;
        alert_id?: number;
        can_ack?: boolean;
        can_snooze?: boolean;
    };
}

export interface MyDayStats {
    shifts_today: number;
    meds_due: number;
    meds_overdue: number;
    tasks_open: number;
    timesheets_pending: number;
    incidents_open: number;
    cr_alerts: number;
    notifications_unread: number;
}

/**
 * Per-row shape from `clock.open_session.end_of_shift_blockers`. Matches the
 * `EndOfShiftBlocker` type that `EndOfShiftChecklist` consumes — kept here so
 * the My Day page doesn't have to import the checklist's type to express the
 * payload it receives.
 */
export interface MyDayEndOfShiftBlocker {
    key: string;
    label: string;
    detail: string;
    count: number;
    action_url: string | null;
    blocking: boolean;
}

export interface MyDayClockSessionTask {
    id: number;
    label: string;
    scheduled_time?: string | null;
    scheduled_for?: string | null;
    is_completed: boolean;
    completed_at?: string | null;
}

export interface MyDayClockSession {
    id: number;
    shift_id: number | null;
    clock_in_at?: string | null;
    started_at?: string;
    clocked_minutes?: number;
    is_on_break?: boolean;
    break_minutes?: number;
    break_count?: number;
    break_started_at?: string | null;
    client_name?: string | null;
    client_photo_url?: string | null;
    shift_starts_at?: string | null;
    shift_ends_at?: string | null;
    location?: string | null;
    service_type?: string | null;
    tasks?: MyDayClockSessionTask[];
    task_progress?: number;
    handover_submitted?: boolean;
    end_of_shift_blockers?: MyDayEndOfShiftBlocker[];
    end_of_shift_ready?: boolean;
    can_force_clinical_blockers?: boolean;
    quick_action_urls?: {
        incident?: string;
        emar?: string;
        escalate?: string;
    };
}

export interface MyDayClockState {
    can_clock: boolean;
    open_session: MyDayClockSession | null;
}

export interface MyDayHandover {
    id?: number;
    from?: { name: string; initials: string; hue: number; role?: string };
    summary?: string;
    flags?: { tone: 'warn' | 'info'; label: string }[];
    unread?: boolean;
    recorded_at?: string;
}

export interface MyDayHandoverReadPayload {
    id: number;
    handover_notes: string | null;
    client_mood: string | null;
    medications_due: Array<Record<string, unknown>>;
    incidents_to_note: Array<Record<string, unknown>>;
    follow_up_items: Array<Record<string, unknown>>;
    submitted_at: string | null;
    outgoing_staff_name: string | null;
    outgoing_shift_ends_at: string | null;
    client_name: string | null;
}

export interface MyDayHrTask {
    id: number;
    kind: 'signature' | 'attestation';
    title: string;
    due: string;
    href?: string;
}

export interface MyDayNotification {
    id: string;
    title: string;
    at: string;
    tone?: 'primary' | 'info' | 'muted';
}

export interface MyDayActiveRound {
    id: number;
    name: string;
    status: 'pending' | 'in_progress' | string;
    scheduled_time?: string | null;
    given: number;
    total: number;
    completed: number;
    percent: number;
    url: string;
}

/**
 * The signed-in worker's live Lone Worker Safety session — the data behind the
 * My Day "You're being monitored — check in" card. Present only when the worker
 * is currently being monitored. Mirrors
 * `MyTasksController::getActiveLoneWorkerSession()`.
 */
export interface MyDayLoneWorkerSession {
    id: number;
    status: 'active' | 'overdue' | 'emergency';
    started_at: string | null;
    expected_end_at: string | null;
    last_check_in_at: string | null;
    check_in_interval_minutes: number;
    /** When the next check-in is due ((last_check_in_at ?? started_at) + interval). */
    next_check_in_at: string | null;
    is_check_in_overdue: boolean;
    activity_description: string | null;
    site: { id: number; name: string } | null;
    client: { id: number; name: string } | null;
}

/**
 * One open first-aid follow-up assigned to the signed-in worker — the data
 * behind the My Day "First-aid follow-ups assigned to me" card. Read-only;
 * `url` deep-links to the First Aid Register's record modal. Mirrors
 * `MyTasksController::getFirstAidFollowups()`.
 */
export interface MyDayFirstAidFollowup {
    id: number;
    notes: string | null;
    due_at: string | null;
    is_overdue: boolean;
    record_id: number;
    treated_person_name: string | null;
    site_name: string | null;
    url: string;
}

/**
 * One of the signed-in worker's own active PPE allocations needing attention —
 * the data behind the My Day "Your PPE needs attention" card. Mirrors
 * `MyTasksController::getMyPpe()`.
 */
export interface MyDayPpe {
    id: number;
    type_name: string;
    category: string | null;
    serial_number: string | null;
    site: string | null;
    allocated_at: string | null;
    acknowledged: boolean;
    fit_test_required: boolean;
    fit_test_completed: boolean;
}

/**
 * One row of the My Day "My tasks" card — an open work item from the
 * company-wide /tasks aggregator assigned to the signed-in user. Mirrors
 * `MyTasksController::getMyAggregatedTasks()`.
 */
export interface MyDayAggregatedTask {
    /** Aggregator id, e.g. "followup-42" — unique across sources. */
    id: string;
    /** Ticket number, e.g. "INC-2026-0042". */
    ref: string | null;
    title: string;
    severity: 'critical' | 'high' | 'medium' | 'low' | 'info';
    dueAt: string | null;
    overdue: boolean;
    /** Deep link back to the owning module's record. */
    link: string | null;
    /** Owning module, e.g. "Client Incidents". */
    sourceLabel: string;
}

/** The My Day "My tasks" card payload — first 8 items + uncapped count. */
export interface MyDayMyTasks {
    total: number;
    items: MyDayAggregatedTask[];
}

export interface MyDayPreShiftBriefing {
    id: number;
    starts_at: string;
    ends_at: string;
    location?: string | null;
    /**
     * Mirrors `MyShiftResource::fromShift()`'s client snapshot — `name` is the
     * full "First Last" string. Initials + hue are derived client-side.
     */
    client: {
        id: number;
        name: string;
        photo_url?: string | null;
    };
    minutes_until_start?: number | null;
    /** Optional pre-rendered bullets. */
    bullets?: string[];
    /** Free-text shift notes (the controller copies `shift.notes` here). */
    what_to_know?: string | null;
    incoming_handover?: MyDayHandoverReadPayload | null;
}

export interface ShiftChecklistRun {
    id: number;
    status: 'scheduled' | 'in_progress';
    can_run: boolean;
    scheduled_date: string | null;
    is_overdue: boolean;
    pct: number;
    template: {
        id: number;
        name: string;
        frequency?: string | null;
        category?: string | null;
    } | null;
}

export interface MyDayChecklistConfig {
    categories: Category[];
    frequencyLabels: Record<string, string>;
    typeLabels: Record<string, string>;
    today: string;
    can: {
        view: boolean;
        run: boolean;
    };
}

export interface MyDayPageProps {
    today: string;
    today_iso?: string;
    shifts: MyDayShift[];
    medications_due: MyDayMedDue[];
    timesheets: MyDayTimesheet[];
    incidents: MyDayIncident[];
    tasks: MyDayTaskFollowup[];
    stats: MyDayStats;
    pending_claims_count?: number;
    clock?: MyDayClockState;
    active_shift?: (MyDayShift & { site?: MyDayActiveSite | null }) | null;
    active_round?: MyDayActiveRound | null;
    active_lone_worker_session?: MyDayLoneWorkerSession | null;
    first_aid_followups?: MyDayFirstAidFollowup[];
    my_ppe?: MyDayPpe[];
    myTasks?: MyDayMyTasks;
    shiftChecklists?: ShiftChecklistRun[];
    checklistConfig?: MyDayChecklistConfig;
    runDetail?: RunDetail | null;
    next_shift_briefing?: MyDayPreShiftBriefing | null;
    previous_shift?: MyDayShift | null;
    handover?: MyDayHandover | null;
    hr_tasks?: MyDayHrTask[];
    notifications?: MyDayNotification[];
    labels?: Record<string, string>;
}

/** Worker identity (current user). Read from the shared Inertia auth prop. */
export interface MyDayWorker {
    id: number;
    first_name: string;
    last_name: string;
    initials: string;
    hue: number;
    role?: string;
}
