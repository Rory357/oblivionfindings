import {
    CalendarX,
    Clock,
    Layers,
    RefreshCw,
    Users,
    type LucideIcon,
} from 'lucide-react';

/**
 * Unified model for the Conflict Queue triage workspace.
 *
 * The backend `conflicts` controller is unchanged — every `QueueItem` is derived
 * client-side from the page `Props` by `build-queue.ts`. This keeps the queue,
 * the hero counts and the filter strip in sync from a single source of truth.
 */

export type ConflictType =
    | 'staff_overlap'
    | 'client_overlap'
    | 'leave_clash'
    | 'tight_turnaround'
    | 'coverage_gap'
    | 'open_shift'
    | 'replacement';

export type Severity = 'critical' | 'warning' | 'info';

export type QueueActionTone = 'primary' | 'default' | 'subtle';

export type ContextTone = 'crit' | 'warn' | 'info';

export interface QueueShift {
    id: number;
    client: string | null;
    staff: string | null;
    location: string | null;
    serviceContext: string | null;
    shiftType: string | null;
    status: string;
    startsAt: string | null;
    endsAt: string | null;
    seriesId: number | null;
}

export interface QueueAction {
    key: string;
    label: string;
    tone: QueueActionTone;
    /** Toast confirmation shown after the action resolves. */
    done: string;
}

export interface QueueContext {
    tone: ContextTone;
    icon: LucideIcon;
    text: string;
}

export interface QueueItem {
    id: string;
    type: ConflictType;
    severity: Severity;
    /** Counts toward the hero's "blocking" tally (the conflicts that stop a publish). */
    blocking: boolean;
    who: string;
    summary: string;
    context?: QueueContext;
    shifts: QueueShift[];
    recommended: string;
    actions: QueueAction[];
    /** Ids + raw rows the resolve handler needs (shift ids, the source coverage gap, etc.). */
    payload: Record<string, unknown>;
}

export interface TypeMeta {
    label: string;
    short: string;
    severity: Severity;
    icon: LucideIcon;
    blocking: boolean;
}

/** Chip / group / sort order. */
export const TYPE_ORDER: ConflictType[] = [
    'staff_overlap',
    'client_overlap',
    'leave_clash',
    'tight_turnaround',
    'coverage_gap',
    'open_shift',
    'replacement',
];

export const SEVERITY_RANK: Record<Severity, number> = {
    critical: 0,
    warning: 1,
    info: 2,
};

export const TYPE_META: Record<ConflictType, TypeMeta> = {
    staff_overlap: {
        label: 'Staff overlap',
        short: 'Staff overlaps',
        severity: 'critical',
        icon: Users,
        blocking: true,
    },
    client_overlap: {
        label: 'Client overlap',
        short: 'Client overlaps',
        severity: 'warning',
        icon: Users,
        blocking: true,
    },
    leave_clash: {
        label: 'Leave clash',
        short: 'Leave clashes',
        severity: 'critical',
        icon: CalendarX,
        blocking: true,
    },
    tight_turnaround: {
        label: 'Tight turnaround',
        short: 'Tight turnarounds',
        severity: 'info',
        icon: Clock,
        blocking: true,
    },
    coverage_gap: {
        label: 'Coverage gap',
        short: 'Coverage gaps',
        severity: 'warning',
        icon: Layers,
        blocking: false,
    },
    open_shift: {
        label: 'Open shift',
        short: 'Open shifts',
        severity: 'warning',
        icon: CalendarX,
        blocking: false,
    },
    replacement: {
        label: 'Replacement',
        short: 'Replacements',
        severity: 'info',
        icon: RefreshCw,
        blocking: false,
    },
};

/**
 * Per-type action sets — labels, order and toast strings are copied verbatim from
 * the design prototype (`conflict-data.jsx` `ACTIONS`). Primary action first.
 */
export const ACTIONS: Record<ConflictType, QueueAction[]> = {
    staff_overlap: [
        {
            key: 'reassign',
            label: 'Reassign a shift',
            tone: 'primary',
            done: 'Reassigned — overlap cleared',
        },
        {
            key: 'open',
            label: 'Unassign & make open',
            tone: 'default',
            done: 'Shift unassigned & opened',
        },
        {
            key: 'keep',
            label: 'Keep both (acknowledge)',
            tone: 'subtle',
            done: 'Acknowledged — both shifts kept',
        },
    ],
    client_overlap: [
        {
            key: 'ratio',
            label: 'Adjust staffing ratio',
            tone: 'primary',
            done: 'Ratio adjusted',
        },
        {
            key: 'edit',
            label: 'Edit a shift',
            tone: 'default',
            done: 'Shift edited',
        },
        {
            key: 'keep',
            label: 'Keep both (acknowledge)',
            tone: 'subtle',
            done: 'Acknowledged — both shifts kept',
        },
    ],
    leave_clash: [
        {
            key: 'reassign',
            label: 'Reassign shift',
            tone: 'primary',
            done: 'Reassigned off leave',
        },
        {
            key: 'cancel',
            label: 'Cancel approved leave',
            tone: 'default',
            done: 'Leave cancelled · shift retained',
        },
        {
            key: 'keep',
            label: 'Keep (acknowledge)',
            tone: 'subtle',
            done: 'Acknowledged',
        },
    ],
    tight_turnaround: [
        {
            key: 'reassign',
            label: 'Reassign second shift',
            tone: 'primary',
            done: 'Second shift reassigned',
        },
        {
            key: 'retime',
            label: 'Adjust shift times',
            tone: 'default',
            done: 'Times adjusted',
        },
        {
            key: 'accept',
            label: 'Accept risk',
            tone: 'subtle',
            done: 'Risk accepted',
        },
    ],
    coverage_gap: [
        {
            key: 'fill',
            label: 'Fill open shift',
            tone: 'primary',
            done: 'Open shift filled',
        },
        {
            key: 'create',
            label: 'Create cover shift',
            tone: 'default',
            done: 'Cover shift created',
        },
        {
            key: 'ack',
            label: 'Acknowledge',
            tone: 'subtle',
            done: 'Acknowledged',
        },
        {
            key: 'dismiss',
            label: 'Dismiss',
            tone: 'subtle',
            done: 'Gap dismissed',
        },
    ],
    open_shift: [
        {
            key: 'assign',
            label: 'Assign staff',
            tone: 'primary',
            done: 'Staff assigned',
        },
        {
            key: 'broadcast',
            label: 'Broadcast to eligible',
            tone: 'default',
            done: 'Broadcast sent',
        },
        {
            key: 'leave',
            label: 'Leave open',
            tone: 'subtle',
            done: 'Left open',
        },
    ],
    replacement: [
        {
            key: 'approve',
            label: 'Approve claim',
            tone: 'primary',
            done: 'Claim approved',
        },
        {
            key: 'reject',
            label: 'Reject claim',
            tone: 'default',
            done: 'Claim rejected',
        },
        {
            key: 'board',
            label: 'Open job board',
            tone: 'subtle',
            done: 'Sent to job board',
        },
    ],
};

export const SEVERITY_BADGE_LABEL: Record<Severity, string> = {
    critical: 'Resolve now',
    warning: 'Needs attention',
    info: 'Review',
};
