import type { StreamItem } from './stream-grouping';

export function workIsDone(item: StreamItem): boolean {
    return item.kind === 'task'
        ? item.data.is_completed
        : ['given', 'refused', 'withheld'].includes(item.data.status);
}

export function workDueAt(item: StreamItem): number {
    const value = item.data.scheduled_for;
    const at = value ? Date.parse(value) : NaN;
    return Number.isFinite(at) ? at : Infinity;
}

/** Order by the actual occurrence, including dates across midnight. */
export function groupWork(items: StreamItem[], now: number) {
    const followedUp = items.filter(
        (item) =>
            item.kind === 'task' &&
            !item.data.is_completed &&
            item.data.follow_through === 'accepted_help',
    );
    const priority = (item: StreamItem) =>
        item.kind === 'med' && workDueAt(item) <= now ? 0 : 1;
    const pending = items
        .filter((item) => !workIsDone(item) && !followedUp.includes(item))
        .sort(
            (a, b) => priority(a) - priority(b) || workDueAt(a) - workDueAt(b),
        );
    return {
        followedUp,
        due: pending.filter((item) => workDueAt(item) <= now),
        later: pending.filter(
            (item) => workDueAt(item) > now && Number.isFinite(workDueAt(item)),
        ),
        anytime: pending.filter((item) => !Number.isFinite(workDueAt(item))),
        completed: items.filter(workIsDone),
    };
}
