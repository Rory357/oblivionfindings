import {
    CalendarClock,
    CalendarX,
    Clock,
    Layers,
    RefreshCw,
    Users,
    type LucideIcon,
} from 'lucide-react';
export type ConflictType =
    | 'staff_overlap'
    | 'client_overlap'
    | 'leave_clash'
    | 'tight_turnaround'
    | 'coverage_gap'
    | 'open_shift'
    | 'replacement'
    | 'recurring_alignment';
export type Severity = 'critical' | 'warning' | 'info';
export type QueueActionTone = 'primary' | 'default' | 'subtle';
export type ContextTone = 'crit' | 'warn' | 'info';
export interface QueueShift {
    id: number;
    userId?: number | null;
    clientId?: number | null;
    siteId?: number | null;
    workerTimezone?: string;
    urls?: {
        shift: string | null;
        client: string | null;
        roster: string | null;
    };
    can?: { view_shift: boolean; view_client: boolean; view_roster: boolean };
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
    href?: string;
}
export interface QueueContext {
    tone: ContextTone;
    icon: LucideIcon;
    text: string;
}
export interface QueueEntity {
    id: number;
    name: string;
}
/** Advisory scheduling findings; publication runs its own checks. */
export interface QueueItem {
    id: string;
    type: ConflictType;
    severity: Severity;
    who: string;
    summary: string;
    context?: QueueContext;
    shifts: QueueShift[];
    staff: QueueEntity[];
    sites: QueueEntity[];
    recommended: string;
    actions: QueueAction[];
    payload: Record<string, unknown>;
}
export interface TypeMeta {
    label: string;
    short: string;
    severity: Severity;
    icon: LucideIcon;
}
export const TYPE_ORDER: ConflictType[] = [
    'staff_overlap',
    'client_overlap',
    'leave_clash',
    'tight_turnaround',
    'coverage_gap',
    'open_shift',
    'replacement',
    'recurring_alignment',
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
    },
    client_overlap: {
        label: 'Client overlap',
        short: 'Client overlaps',
        severity: 'warning',
        icon: Users,
    },
    leave_clash: {
        label: 'Time-off clash',
        short: 'Time-off clashes',
        severity: 'critical',
        icon: CalendarX,
    },
    tight_turnaround: {
        label: 'Tight turnaround',
        short: 'Tight turnarounds',
        severity: 'info',
        icon: Clock,
    },
    coverage_gap: {
        label: 'Coverage gap',
        short: 'Coverage gaps',
        severity: 'warning',
        icon: Layers,
    },
    open_shift: {
        label: 'Open shift',
        short: 'Open shifts',
        severity: 'warning',
        icon: CalendarX,
    },
    replacement: {
        label: 'Replacement',
        short: 'Replacements',
        severity: 'info',
        icon: RefreshCw,
    },
    recurring_alignment: {
        label: 'Recurring cover',
        short: 'Recurring cover',
        severity: 'info',
        icon: CalendarClock,
    },
};
export const SEVERITY_BADGE_LABEL: Record<Severity, string> = {
    critical: 'Review first',
    warning: 'Needs attention',
    info: 'Review',
};
