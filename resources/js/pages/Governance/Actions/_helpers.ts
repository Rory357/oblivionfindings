import type { StatusVariant } from '@/components/ui/status-badge';
import { toDateInput } from '@/lib/datetime';

export function actionStatusVariant(status: string): StatusVariant {
    switch (status) {
        case 'complete':
            return 'success';
        case 'blocked':
            return 'critical';
        case 'in_progress':
            return 'info';
        default:
            return 'neutral';
    }
}

export function actionStatusLabel(status: string): string {
    if (status === 'complete') return 'Completed';
    if (status === 'in_progress') return 'In progress';
    return status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');
}

export function actionPriorityVariant(priority: string): StatusVariant {
    switch (priority) {
        case 'critical':
            return 'critical';
        case 'high':
            return 'warning';
        case 'medium':
            return 'info';
        default:
            return 'neutral';
    }
}

export function actionPriorityLabel(priority: string): string {
    return priority.charAt(0).toUpperCase() + priority.slice(1);
}

/** Due dates are calendar dates (YYYY-MM-DD, sometimes with a time part). */
export function dueDateOnly(value: string | null | undefined): string | null {
    return value ? value.slice(0, 10) : null;
}

/** Mirrors ActionItem::scopeOverdue — past due and still open or in progress. */
export function isActionOverdue(
    status: string,
    dueDate: string | null | undefined,
): boolean {
    const due = dueDateOnly(dueDate);
    if (!due || !['open', 'in_progress'].includes(status)) return false;
    return due < toDateInput(new Date());
}

/** Whole days between today (Auckland) and a due date; negative when overdue. */
export function daysUntilDue(
    dueDate: string | null | undefined,
): number | null {
    const due = dueDateOnly(dueDate);
    if (!due) return null;
    const today = toDateInput(new Date());
    const ms =
        Date.parse(`${due}T00:00:00Z`) - Date.parse(`${today}T00:00:00Z`);
    return Number.isNaN(ms) ? null : Math.round(ms / 86_400_000);
}
