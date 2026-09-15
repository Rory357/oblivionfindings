/**
 * Shared pieces for the Governance compliance pages: requirement status
 * chips and wording (vocabulary.md — "requirement", never "obligation").
 *
 * Statuses come from the server, worked out from each due date (NZ):
 * due soon = due in the next 30 days, not overdue, not cancelled.
 */
import type { StatusVariant } from '@/components/ui/status-badge';
import { governanceStatus } from '@/lib/governance-labels';

export const OBLIGATION_STATUS_FILTERS = [
    { value: 'all', label: 'All statuses' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'due_soon', label: 'Due in 30 days' },
    { value: 'not_due', label: 'Not due yet' },
    { value: 'on_time', label: 'On time' },
    { value: 'complete', label: 'Done' },
    { value: 'cancelled', label: 'Cancelled' },
];

export function obligationStatusVariant(status: string): StatusVariant {
    return governanceStatus('compliance_status', status).variant;
}

export function obligationStatusLabel(status: string): string {
    return status === 'due_soon'
        ? 'Due in 30 days'
        : governanceStatus('compliance_status', status).label;
}

/** Whole days from today (Auckland calendar date) to a YYYY-MM-DD due date. */
export function daysUntil(dueDate: string | null | undefined, today: string): number | null {
    if (!dueDate) return null;
    const due = Date.parse(`${dueDate.slice(0, 10)}T00:00:00Z`);
    const now = Date.parse(`${today}T00:00:00Z`);
    if (Number.isNaN(due) || Number.isNaN(now)) return null;
    return Math.round((due - now) / 86_400_000);
}

/** "Due today" · "Due tomorrow" · "Due in 12 days" · "1 day overdue". */
export function dueLabel(days: number | null): string {
    if (days === null) return 'No due date';
    if (days < 0) {
        const late = Math.abs(days);
        return `${late} ${late === 1 ? 'day' : 'days'} overdue`;
    }
    if (days === 0) return 'Due today';
    if (days === 1) return 'Due tomorrow';
    return `Due in ${days} days`;
}

export function plural(count: number, one: string, many = `${one}s`): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** "On time: 6 of 8" — done or not yet overdue, out of all but cancelled. */
export function onTimeSentence(onTime: number, counted: number): string {
    return `On time: ${onTime} of ${counted}`;
}
