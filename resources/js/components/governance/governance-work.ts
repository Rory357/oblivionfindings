/**
 * My work wording shared by Governance Home and the My work page, so each
 * kind of work has exactly one name on both (vocabulary.md).
 */
import type { StatusVariant } from '@/components/ui/status-badge';

export type WorkKind = 'vote' | 'read' | 'act' | 'know';

export const WORK_KIND_LABELS: Record<WorkKind, string> = {
    vote: 'Vote',
    read: 'Read',
    act: 'Do',
    know: 'For your information',
};

export function workKindLabel(kind: string | null | undefined): string {
    return WORK_KIND_LABELS[(kind ?? '') as WorkKind] ?? WORK_KIND_LABELS.act;
}

/**
 * The status chip for a work item. Only due state gets a warning colour
 * (overdue, due soon, blocked); to-do and coming-up items stay neutral.
 */
export function workStatusChip(status: string | null | undefined): {
    label: string;
    variant: StatusVariant;
} {
    switch (status) {
        case 'overdue':
            return { label: 'Overdue', variant: 'critical' };
        case 'due_soon':
            return { label: 'Due soon', variant: 'warning' };
        case 'blocked':
            return { label: 'Blocked', variant: 'critical' };
        case 'completed':
            return { label: 'Done', variant: 'success' };
        case 'upcoming':
            return { label: 'Coming up', variant: 'neutral' };
        default:
            return { label: 'To do', variant: 'neutral' };
    }
}

/** True for statuses whose chip is worth showing beside a due date. */
export function isDueStateStatus(status: string | null | undefined): boolean {
    return status === 'overdue' || status === 'due_soon' || status === 'blocked';
}

const SOURCE_NAMES: Record<string, string> = {
    resolutions: 'votes',
    board_packs: 'board packs',
    action_items: 'actions',
    policies: 'policies',
    meetings: 'meetings',
};

/**
 * "We couldn't load your board packs and actions right now, so this list may
 * be missing items. Try again in a few minutes." — or null when everything
 * loaded.
 */
export function unavailableWorkMessage(
    availability: Record<string, string> | null | undefined,
): string | null {
    const names = Object.entries(availability ?? {})
        .filter(([, state]) => state === 'unavailable')
        .map(([key]) => SOURCE_NAMES[key] ?? key.replace(/_/g, ' '));

    if (names.length === 0) return null;

    const list =
        names.length === 1
            ? names[0]
            : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;

    return `We couldn't load your ${list} right now, so this list may be missing items. Try again in a few minutes.`;
}

/** Receipt heading: a vote "is recorded"; everything else has a record of completion. */
export function receiptTitle(kind: string | null | undefined): string {
    return kind === 'vote' ? 'Your vote is recorded' : 'Record of completion';
}
