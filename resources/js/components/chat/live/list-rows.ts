import type { LiveEvent } from './events';
import type { SyncReason } from './sync';

const MAX_ROWS = 5;

/**
 * The conversations a run should refresh row by row, or null to fetch the whole list:
 * list-level changes, polls, catch-ups and bursts touching many conversations.
 */
export function namedRows(
    reason: SyncReason,
): { ids: number[]; raised: number[] } | null {
    if (reason.kind !== 'event' || reason.events.length === 0) return null;
    const changed = reason.events.filter(
        (
            event,
        ): event is Extract<LiveEvent, { type: 'conversation.changed' }> =>
            event.type === 'conversation.changed',
    );
    if (changed.length !== reason.events.length) return null;
    const ids = [...new Set(changed.map((event) => event.conversation_id))];
    if (ids.length > MAX_ROWS) return null;
    const raised = [
        ...new Set(
            changed
                .filter((event) => event.kinds.includes('message'))
                .map((event) => event.conversation_id),
        ),
    ];
    return { ids, raised };
}

/**
 * Put freshly fetched rows into the list. A missing row is removed (no longer visible); a new
 * message moves its conversation to the top, as the server orders the list. Returns null when a
 * row is new to this list, so the caller fetches the whole list instead.
 */
export function mergeConversationRows<T extends { id: number }>(
    list: readonly T[],
    ids: readonly number[],
    rows: readonly T[],
    raised: readonly number[],
): T[] | null {
    const fetched = new Map(rows.map((row) => [row.id, row]));
    let next = [...list];
    for (const id of ids) {
        const row = fetched.get(id);
        const index = next.findIndex((entry) => entry.id === id);
        if (!row) {
            if (index >= 0) next.splice(index, 1);
            continue;
        }
        if (index < 0) return null;
        next[index] = row;
    }
    for (const id of [...raised].reverse()) {
        const index = next.findIndex((entry) => entry.id === id);
        if (index > 0)
            next = [
                next[index],
                ...next.slice(0, index),
                ...next.slice(index + 1),
            ];
    }
    return next;
}
