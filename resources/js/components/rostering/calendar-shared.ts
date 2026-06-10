import type { GridConflictPeer, GridShiftStatus } from './week-grid-pane';

/**
 * Shared shapes + status vocabulary for the Rostering Calendar tab (month
 * grid pane + day detail dialog). Kept separate so the pane and the dialog
 * can both import them without a circular dependency.
 */

/** One shift, mapped from the operations.rostering.calendar.events payload.
 *  Structurally compatible with GridShift so the shared context-menu action
 *  vocabulary (buildShiftActions) and the page-level action handlers work
 *  unchanged. */
export type CalendarShift = {
    id: number;
    status: GridShiftStatus;
    starts_at: string; // ISO-8601
    ends_at: string; // ISO-8601
    client: string | null;
    staff?: string | null;
    conflict?: boolean;
    conflictPeers?: GridConflictPeer[];
    timesheet_id?: number | null;
    href?: string;
    // Calendar extras
    dateKey: string; // YYYY-MM-DD (local) of the start
    start: string; // HH:mm
    end: string; // HH:mm
    durationH: number;
    clientId: number | null;
    siteId: number | null;
    siteName: string | null;
    context: string | null;
    shiftType: string;
    staffId: number | null;
    recurring: boolean;
    replacement: boolean;
    tasksTotal: number;
    tasksDone: number;
    incidents: number;
    isRespite: boolean;
};

/** A site-coverage gap window (event_type=coverage_gap in the events feed). */
export type CalendarGap = {
    key: string;
    dateKey: string;
    startsAt: string;
    endsAt: string;
    siteId: number | null;
    siteName: string | null;
    ruleId: number | null;
    ruleName: string | null;
    windowLabel: string | null;
    missingStaff: number;
    requiredStaff: number | null;
    assignedStaff: number | null;
    preferredClientId: number | null;
    recommendedFillAction: string | null;
    roleShortages: Array<{ key: string; label?: string | null; missing?: number }>;
};

export type CalendarStatusMeta = {
    key: 'open' | 'live' | 'scheduled' | 'completed' | 'cancelled' | 'draft' | 'replacement';
    label: string;
    accent: string;
    tint: string;
    dashed?: boolean;
    live?: boolean;
    muted?: boolean;
};

/** Status → visual meta (accent bar, dot, pill). Mirrors the planner's
 *  STATUS_CTX_TONE but adds the dashed/live/muted treatments the calendar
 *  chips and day-dialog rows need. */
export function calendarStatusMeta(s: {
    status: GridShiftStatus;
    replacement?: boolean;
}): CalendarStatusMeta {
    switch (s.status) {
        case 'open':
            return {
                key: 'open',
                label: 'Open',
                accent: 'var(--status-critical)',
                tint: 'var(--status-critical-bg)',
                dashed: true,
            };
        case 'in_progress':
            return {
                key: 'live',
                label: 'In progress',
                accent: 'var(--live)',
                tint: 'var(--live-bg)',
                live: true,
            };
        case 'completed':
            return {
                key: 'completed',
                label: 'Completed',
                accent: 'var(--status-success)',
                tint: 'var(--status-success-bg)',
            };
        case 'cancelled':
            return {
                key: 'cancelled',
                label: 'Cancelled',
                accent: 'var(--status-neutral)',
                tint: 'var(--muted)',
                muted: true,
            };
        case 'draft':
            return {
                key: 'draft',
                label: 'Draft',
                accent: 'var(--muted-foreground)',
                tint: 'var(--muted)',
                dashed: true,
            };
        default:
            if (s.replacement) {
                return {
                    key: 'replacement',
                    label: 'Replacement',
                    accent: 'var(--status-warning)',
                    tint: 'var(--status-warning-bg)',
                };
            }
            return {
                key: 'scheduled',
                label: 'Scheduled',
                accent: 'var(--primary)',
                tint: 'color-mix(in oklch, var(--primary) 10%, var(--card))',
            };
    }
}

export function ymdKey(d: Date): string {
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mm}-${dd}`;
}

export function parseDateKey(key: string): Date {
    return new Date(`${key}T00:00:00`);
}

export function fmtHM(iso: string): string {
    return new Date(iso).toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

/** "8h" / "7.5h" — trims the trailing .0 */
export function fmtHours(h: number): string {
    const rounded = Math.round(h * 10) / 10;
    return `${Number.isInteger(rounded) ? rounded : rounded.toFixed(1)}h`;
}

export function calInitials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .map((w) => w[0]!)
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

/** Stable name → hue hash, same algorithm as the planner panes. */
export function calHashHue(name: string): number {
    let h = 0;
    for (let i = 0; i < name.length; i++) {
        h = (h * 31 + name.charCodeAt(i)) % 360;
    }
    return h;
}

export const CAL_MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

export const CAL_WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** 6 ISO weeks (Mon-first) covering the given month. */
export function monthMatrix(year: number, month: number): Date[][] {
    const first = new Date(year, month, 1);
    const offset = (first.getDay() + 6) % 7; // Mon=0
    const start = new Date(year, month, 1 - offset);
    const weeks: Date[][] = [];
    for (let w = 0; w < 6; w++) {
        const row: Date[] = [];
        for (let d = 0; d < 7; d++) {
            row.push(
                new Date(
                    start.getFullYear(),
                    start.getMonth(),
                    start.getDate() + w * 7 + d,
                ),
            );
        }
        weeks.push(row);
    }
    return weeks;
}
