import type { StatusVariant } from '@/components/ui/status-badge';
import { toDateInput } from '@/lib/datetime';
import {
    governanceStatus,
    type GovernanceStatusChip,
} from '@/lib/governance-labels';

/** One status chip for an action: "Overdue" wins while the work is still open. */
export function actionChip(
    status: string,
    dueDate: string | null | undefined,
): GovernanceStatusChip {
    if (isActionOverdue(status, dueDate)) {
        return governanceStatus('action_status', 'overdue');
    }
    return governanceStatus('action_status', status);
}

export function actionStatusVariant(status: string): StatusVariant {
    return governanceStatus('action_status', status).variant;
}

export function actionStatusLabel(status: string): string {
    return governanceStatus('action_status', status).label;
}

export function actionPriorityVariant(priority: string): StatusVariant {
    return governanceStatus('priority', priority).variant;
}

export function actionPriorityLabel(priority: string): string {
    return governanceStatus('priority', priority).label;
}

/**
 * "Needed — not yet added" / "Added" / "Not needed" — whether proof has been
 * provided, not just whether it is required.
 */
export function evidenceState(
    required: boolean | undefined,
    count: number | undefined,
): GovernanceStatusChip {
    const files = count ?? 0;
    if (files > 0) {
        return {
            label: files === 1 ? 'Added' : `Added (${files})`,
            variant: 'success',
        };
    }
    if (required) {
        return { label: 'Needed — not yet added', variant: 'warning' };
    }
    return { label: 'Not needed', variant: 'neutral' };
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

/** "3 days overdue" / "Due today" / "5 days left". */
export function dueWording(dueDate: string | null | undefined): string | null {
    const days = daysUntilDue(dueDate);
    if (days === null) return null;
    if (days < 0) {
        const n = Math.abs(days);
        return `${n} day${n === 1 ? '' : 's'} overdue`;
    }
    if (days === 0) return 'Due today';
    return `${days} day${days === 1 ? '' : 's'} left`;
}

/** Evidence the server accepts (ActionItemEvidenceController::ALLOWED_EXTENSIONS). */
export const EVIDENCE_EXTENSIONS = [
    'pdf',
    'doc',
    'docx',
    'xls',
    'xlsx',
    'ppt',
    'pptx',
    'jpg',
    'jpeg',
    'png',
    'gif',
    'webp',
    'csv',
    'txt',
] as const;

export const EVIDENCE_MAX_BYTES = 20 * 1024 * 1024;

/** A plain reason a file can't be uploaded, or null when it's fine. */
export function evidenceFileProblem(file: {
    name: string;
    size: number;
}): string | null {
    const extension = file.name.includes('.')
        ? (file.name.split('.').pop() ?? '').toLowerCase()
        : '';
    if (!(EVIDENCE_EXTENSIONS as readonly string[]).includes(extension)) {
        return `"${file.name}" can't be used. Evidence must be a PDF, a Word, Excel or PowerPoint file, an image (JPG, PNG, GIF or WebP), or a CSV or text file.`;
    }
    if (file.size > EVIDENCE_MAX_BYTES) {
        return `"${file.name}" is bigger than 20 MB. Choose a smaller file.`;
    }
    return null;
}
