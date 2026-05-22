/* -------------------------------------------------------------------------- */
/*  TypeScript types for the /my-day Inertia payload                          */
/* -------------------------------------------------------------------------- */
/*
 * Mirrors `MyTasksController::__invoke` + the resources it builds. Keep this
 * in sync when the server payload changes.
 */

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
    id: number;
    client_id: number;
    client_name: string;
    medication_name: string;
    dose: string;
    route?: string;
    scheduled_for: string;
    status: 'overdue' | 'due' | 'upcoming' | 'given' | 'refused';
    flag?: string | null;
    emar_url: string;
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
    can_edit_inline?: boolean;
    needs?: string | null;
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

export interface MyDayClockSession {
    id?: number;
    shift_id?: number;
    started_at?: string;
    clocked_minutes?: number;
    on_break?: boolean;
}

export interface MyDayClockState {
    can_clock: boolean;
    open_session: MyDayClockSession | null;
    active_shift: MyDayShift | null;
    eligible_shifts?: MyDayShift[];
    eligible_shift_count: number;
}

export interface MyDayHandover {
    id?: number;
    from?: { name: string; initials: string; hue: number; role?: string };
    summary?: string;
    flags?: { tone: 'warn' | 'info'; label: string }[];
    unread?: boolean;
    recorded_at?: string;
}

export interface MyDayHrTask {
    id: number;
    kind: 'signature' | 'attestation';
    title: string;
    due: string;
    href?: string;
}

export interface MyDayNotification {
    id: number;
    title: string;
    at: string;
    tone?: 'primary' | 'info' | 'muted';
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
    incoming_handover?: { summary?: string } | null;
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
    pending_claims_count: number;
    leave: { balances: { type: string; remaining_hours: number; total_hours: number }[]; pending_requests: number };
    is_manager: boolean;
    clock?: MyDayClockState;
    active_shift?: (MyDayShift & { site?: MyDayActiveSite | null }) | null;
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
