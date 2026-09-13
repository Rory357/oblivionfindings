/**
 * Shared pieces for the Governance compliance pages: the obligation status
 * token mapping and labels.
 */
import type { StatusVariant } from '@/components/ui/status-badge';

export const OBLIGATION_STATUS_FILTERS = [
    { value: 'all', label: 'All statuses' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'due_soon', label: 'Due soon' },
    { value: 'not_due', label: 'Not due' },
    { value: 'complete', label: 'Complete' },
    { value: 'cancelled', label: 'Cancelled' },
];

export function obligationStatusVariant(status: string): StatusVariant {
    switch (status) {
        case 'overdue':
            return 'critical';
        case 'due_soon':
            return 'warning';
        case 'complete':
            return 'success';
        default:
            return 'neutral';
    }
}

export function obligationStatusLabel(status: string): string {
    return (
        OBLIGATION_STATUS_FILTERS.find((s) => s.value === status)?.label ??
        status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
    );
}

export function priorityVariant(priority: string | null | undefined): StatusVariant {
    switch (priority) {
        case 'critical':
            return 'critical';
        case 'high':
            return 'warning';
        case 'low':
            return 'neutral';
        default:
            return 'info';
    }
}

/** Whole days from today (Auckland calendar date) to a YYYY-MM-DD due date. */
export function daysUntil(dueDate: string | null | undefined, today: string): number | null {
    if (!dueDate) return null;
    const due = Date.parse(`${dueDate.slice(0, 10)}T00:00:00Z`);
    const now = Date.parse(`${today}T00:00:00Z`);
    if (Number.isNaN(due) || Number.isNaN(now)) return null;
    return Math.round((due - now) / 86_400_000);
}

export function dueLabel(days: number | null): string {
    if (days === null) return 'No due date';
    if (days < 0) return `${Math.abs(days)} ${Math.abs(days) === 1 ? 'day' : 'days'} overdue`;
    if (days === 0) return 'Due today';
    if (days === 1) return 'Due tomorrow';
    return `${days} days remaining`;
}
