// Shared types + token-mapped metadata for the HR Timekeeping surface.
import { avatarHueStyle } from '@/components/rostering/avatar-hue';
import type { StatusVariant } from '@/components/ui/status-badge';

export interface Disturbance {
    start: string;
    end: string;
    minutes?: number;
}

export interface TimeEntry {
    id: number;
    user_name: string;
    user_id: number;
    initials: string;
    site_name: string | null;
    entry_date: string;
    clock_in: string;
    clock_in_short: string;
    clock_out: string | null;
    clock_out_short: string | null;
    break_minutes: number;
    total_hours: number | null;
    entry_type: string;
    status: string;
    pay_type: string;
    is_sleepover: boolean;
    is_on_call: boolean;
    is_public_holiday: boolean;
    sleepover_disturbances: Disturbance[];
    break_compliance_met: boolean | null;
    mileage_km: number | null;
    notes: string | null;
    project_code: string | null;
    cost_centre: string | null;
    approved_by: string | null;
    amended_by: number | null;
    amendment_reason: string | null;
    amendment_count: number;
    client_name: string | null;
    shift: { id: number; starts_at: string | null; ends_at: string | null } | null;
}

export interface TimesheetRow {
    id: number;
    source: 'operations';
    user_name: string;
    user_id: number;
    period_start: string;
    period_end: string;
    work_date: string | null;
    client_name: string | null;
    status: string;
    total_hours: number | null;
    submitted_at: string | null;
    approved_by: string | null;
    approved_at: string | null;
    module_url: string;
    edit_url: string;
}

export interface ApprovalTimesheet {
    id: number;
    user_name: string;
    period_start: string;
    period_end: string;
    total_hours: number | null;
    submitted_at: string | null;
    hours_waiting: number;
    module_url: string;
}

export interface OnNowItem {
    id: number;
    user_id: number;
    name: string;
    initials: string;
    meta: string;
    since: string;
    clock_in: string;
    entry_date: string;
    elapsed_minutes: number;
    pay_type: string;
    is_sleepover: boolean;
}

export interface ExceptionItem {
    id: string;
    kind: string;
    severity: 'critical' | 'warning' | 'info';
    title: string;
    detail: string;
    badge: string;
    entry_id?: number;
    user_id?: number;
    user_name?: string;
    clock_in?: string;
    entry_date?: string;
    action: 'correct' | 'edit' | 'view_entries';
}

export interface WeeklyDay {
    date: string;
    day: string;
    hours: number;
}

export interface RecentActivityItem {
    id: number;
    user_name: string;
    action: string;
    time: string;
    on_behalf: boolean;
}

export interface TimeReport {
    week_start: string;
    week_end: string;
    kpis: {
        total_hours: number;
        overtime_hours: number;
        break_fails: number;
        mileage_km: number;
    };
    by_site: Array<{ name: string; hours: number }>;
    by_staff: Array<{ user_id: number; name: string; hours: number; overtime: number }>;
}

export interface KpiStats {
    clocked_in_now: number;
    team_hours_week: number;
    awaiting_approval: number;
    exceptions_count: number;
    overtime_hours: number;
    avg_hours_per_day: number;
}

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

export interface TimeCan {
    manage?: boolean;
    approveTeam?: boolean;
    approveAny?: boolean;
    editEntry?: boolean;
    clockOnBehalf?: boolean;
}

export interface NamedOption {
    id: number;
    name: string;
}

export interface TimeFilters {
    status?: string;
    pay_type?: string;
    site_id?: string;
    q?: string;
    tab?: string;
    scope?: string;
}

/* ------------------------------------------------------------------ */
/*  Token-mapped metadata                                              */
/* ------------------------------------------------------------------ */

/** Stable avatar tint from a user id (mirrors rostering avatar chips). */
export function avatarStyle(userId: number) {
    return avatarHueStyle((userId * 47) % 360);
}

export const PAY_TYPE_LABEL: Record<string, string> = {
    standard: 'Ordinary',
    sleepover: 'Sleepover',
    on_call: 'On-call',
    public_holiday: 'Public holiday',
    night: 'Night',
    weekend: 'Weekend',
    evening: 'Evening',
};

export const PAY_TYPE_OPTIONS = [
    { value: 'standard', label: 'Ordinary' },
    { value: 'sleepover', label: 'Sleepover' },
    { value: 'on_call', label: 'On-call' },
    { value: 'public_holiday', label: 'Public holiday' },
    { value: 'night', label: 'Night' },
    { value: 'weekend', label: 'Weekend' },
    { value: 'evening', label: 'Evening' },
];

export function payTypeLabel(value: string): string {
    return PAY_TYPE_LABEL[value] ?? value;
}

/** Map an entry status to a StatusBadge variant. */
export function statusVariant(status: string): StatusVariant {
    switch (status) {
        case 'approved':
            return 'success';
        case 'submitted':
        case 'returned':
            return 'warning';
        case 'rejected':
        case 'voided':
            return 'critical';
        case 'active':
            return 'info';
        default:
            return 'neutral';
    }
}

export function statusLabel(status: string): string {
    const map: Record<string, string> = {
        active: 'Active',
        submitted: 'Submitted',
        approved: 'Approved',
        rejected: 'Rejected',
        returned: 'Returned',
        voided: 'Voided',
        draft: 'Draft',
    };
    return map[status] ?? status;
}

/** "3h 12m" from minutes. */
export function formatElapsed(minutes: number): string {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    if (h <= 0) return `${m}m`;
    return `${h}h ${String(m).padStart(2, '0')}m`;
}
