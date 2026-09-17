export interface WorkPerson {
    id: number;
    name: string;
    email?: string | null;
    work_phone?: string | null;
    busy?: boolean | null;
}
export interface WorkPeriod {
    starts_at: string;
    ends_at: string;
    break_minutes: number;
    work_type: 'remote' | 'onsite' | 'travel';
    after_hours: boolean;
}
export interface WorkNote {
    periods?: WorkPeriod[];
    technician_user_id?: number;
    technician_name?: string;
    booking_id?: number | null;
    require_approval?: boolean;
    approver_user_id?: number | null;
    approver_name?: string;
    status?: string;
    follow_up?: {
        owner_user_id: number;
        owner_name?: string;
        due_at: string;
        action: string;
        waiting_party: string;
        reason: string;
    };
    recipient_user_ids?: number[];
    resolution_code?: string;
    resolution_summary?: string;
    resolution_verification?: string;
    timer?: { started: number | null; periods: WorkPeriod[] };
}
export interface WorkEntry extends WorkPeriod {
    id: number;
    minutes: number;
    technician_user_id: number;
    technician_name: string;
    recorded_by: number;
    recorded_by_name: string;
    comment_id: number;
    booking_id: number | null;
    approval_status: string;
    approver_user_id: number | null;
    approver_name: string | null;
    hourly_rate_cents: number | null;
}
export interface WorkBooking {
    id: number;
    technician_user_id: number;
    technician_name: string;
    starts_at: string;
    ends_at: string;
    status: string;
    details: { brief: string; location?: string };
    recorded_by_name: string;
}
export interface WorkCost {
    id: number;
    kind: string;
    incurred_on: string;
    quantity_hundredths: number;
    unit_cost_cents: number;
    total_cents: number;
    approval_status: string;
    approver_user_id: number | null;
    approver_name: string | null;
    recorded_by: number;
    recorded_by_name: string;
    details: { description: string; reference?: string };
}
export interface WorkRevision {
    id: number;
    record_type: string;
    record_id: number;
    action: string;
    actor_name: string;
    created_at: string;
    evidence: { reason: string; before: unknown; after: unknown };
}
export interface TicketWork {
    ready: boolean;
    timezone?: string;
    details?: Record<
        string,
        string | number | boolean | null | WorkNote['follow_up']
    >;
    requester?: WorkPerson | null;
    affected_user?: WorkPerson | null;
    alternate_contact?: WorkPerson | null;
    follow_up_owner?: string | null;
    entries?: WorkEntry[];
    bookings?: WorkBooking[];
    costs?: WorkCost[];
    revisions?: WorkRevision[];
    recipients?: WorkPerson[];
    totals?: {
        minutes: number;
        after_hours_minutes: number;
        cost_cents: number;
        labour_cents: number;
        unpriced_minutes: number;
    };
}

export const PRIORITY_LABELS: Record<string, string> = {
    urgent: 'P1 · Critical',
    high: 'P2 · High',
    normal: 'P3 · Medium',
    low: 'P4 · Low',
};

export function readWorkNote(value: string): WorkNote {
    try {
        const parsed = JSON.parse(value);
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
            ? parsed
            : {};
    } catch {
        return {};
    }
}

/** datetime-local uses the worker zone, even on a travelling technician's laptop. */
export function workLocal(value: string | number = Date.now()): string {
    if (
        typeof value === 'string' &&
        /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)
    )
        return value;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Pacific/Auckland',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);
    const part = (name: string) => parts.find((p) => p.type === name)?.value;
    return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
}

export function newWorkPeriod(): WorkPeriod {
    return {
        starts_at: workLocal(),
        ends_at: '',
        break_minutes: 0,
        work_type: 'remote',
        after_hours: false,
    };
}
