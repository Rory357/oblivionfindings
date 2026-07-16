/* Shared types + helpers for the All Tasks queue (page + drawer).
 * Mirrors app/Services/Tasks/TaskItem::toArray(). */
import type { StatusVariant } from '@/components/ui/status-badge';
import { formatDate } from '@/lib/datetime';

export type NamedRef = { id: number; name: string };

/** One audit row in the drawer's Activity timeline. */
export interface TimelineEntry {
    id: number;
    action: string;
    user: string | null;
    at: string | null;
}

/** Shape of the GET /tasks/detail JSON payload (permission-scoped). */
export interface TaskDetail {
    item: TaskItem;
    timeline: TimelineEntry[];
    canAssign: boolean;
    watchers: NamedRef[];
    /** True when the follower list is withheld (need-to-know restricted row). */
    watchersHidden?: boolean;
    isWatching: boolean;
    canSplit: boolean;
}

export type TaskBucket = 'open' | 'in_progress' | 'done';

export type TaskSeverity = 'critical' | 'high' | 'medium' | 'low' | 'info';

export interface IncidentJourney {
    key: string;
    source: string;
    occurred_at: string | null;
    references: {
        control_room: string | null;
        incident: string | null;
        health_safety: string | null;
    };
    person: NamedRef | null;
    site: NamedRef | null;
}

export interface TaskItem {
    id: string;
    source: string;
    sourceLabel: string;
    ref: string | null;
    title: string;
    status: string;
    bucket: TaskBucket;
    severity: TaskSeverity;
    assignee: NamedRef | null;
    client: NamedRef | null;
    site: NamedRef | null;
    dueAt: string | null;
    createdAt: string | null;
    link: string | null;
    type: string | null;
    description: string | null;
    journey?: IncidentJourney | null;
    sourceContext?: string | null;
    actionLabel?: string;
    displayState?: string | null;
    actionHelp?: string | null;
    overdue: boolean;
}

export const SEVERITY_VARIANT: Record<TaskSeverity, StatusVariant> = {
    critical: 'critical',
    high: 'warning',
    medium: 'warning',
    low: 'info',
    info: 'neutral',
};

export function humanise(raw: string): string {
    const label = raw.replace(/[_-]/g, ' ');
    return label.charAt(0).toUpperCase() + label.slice(1);
}

export function taskStateLabel(
    item: Pick<TaskItem, 'status' | 'displayState'>,
) {
    return item.displayState ?? humanise(item.status);
}

/** Relative due label + tone class. Overdue rows read critical. */
export function dueInfo(item: TaskItem): { label: string; className: string } {
    if (!item.dueAt) return { label: '—', className: 'text-muted-foreground' };
    const days = Math.ceil(
        (new Date(item.dueAt).getTime() - Date.now()) / 86_400_000,
    );
    if (item.overdue) {
        return {
            label: days >= 0 ? 'Overdue' : `${Math.abs(days)}d overdue`,
            className: 'font-semibold text-status-critical',
        };
    }
    if (days <= 0)
        return {
            label: 'Due today',
            className: 'font-semibold text-status-warning',
        };
    if (days <= 7)
        return { label: `Due in ${days}d`, className: 'text-status-warning' };
    return {
        label: formatDate(item.dueAt),
        className: 'text-muted-foreground',
    };
}

/** Composite queue ids are "{source}-{modelId}"; the numeric tail addresses
 *  the record in /tasks/detail and /tasks/{source}/{id}/assign. */
export function taskNumericId(item: TaskItem): string {
    return item.id.slice(item.id.lastIndexOf('-') + 1);
}

/** The word for the child record a split produces, keyed by owning module.
 *  The backend gates the affordance via `canSplit`; this only labels it, so an
 *  unknown source falls back to the neutral "follow-up". */
const CHILD_LABELS: Record<string, string> = {
    incident: 'follow-up',
    incidents: 'follow-up',
    safeguarding: 'action',
    hazard: 'corrective action',
    hazards: 'corrective action',
    injury: 'follow-up',
    injuries: 'follow-up',
};

export function childLabelFor(source: string): string {
    return CHILD_LABELS[source] ?? 'follow-up';
}
