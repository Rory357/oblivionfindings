import { readCollectionPage } from './chat-collection-sync';
import type { ResponseClock } from './chat-response-clock';

export type FamilyWorkEvent = {
    id: number;
    action: 'note' | 'claim' | 'assign' | 'handoff' | 'status';
    actor_name: string;
    owner_name: string | null;
    status: string;
    note: string | null;
    created_at: string;
};
export type FamilyWorkPage = {
    version: number;
    status: string;
    assigned_to: number | null;
    owner_name: string | null;
    owners: { id: number; name: string }[];
    can_manage: boolean;
    can_create_followup?: boolean;
    response_expectation: string;
    response_clock?: ResponseClock;
    events: FamilyWorkEvent[];
    next_before: number | null;
    scan_floor: number | null;
    older_before: number | null;
};
export const familyWorkStatus: Record<string, string> = {
    open: 'Needs reply',
    waiting: 'Waiting on family',
    resolved: 'Resolved',
};
export function familyWorkEvents(events: FamilyWorkEvent[]) {
    return [...new Map(events.map((event) => [event.id, event])).values()].sort(
        (a, b) => b.id - a.id,
    );
}
export async function refreshFamilyWork(
    path: string,
    previous: FamilyWorkPage | null,
    signal: AbortSignal,
    actorId: number,
): Promise<FamilyWorkPage> {
    const headers = { 'X-Chat-Actor': String(actorId) };
    const floor = previous?.scan_floor ?? previous?.events.at(-1)?.id;
    if (!floor) return readCollectionPage(path, signal, headers);
    const events: FamilyWorkEvent[] = [];
    let before: number | null = null;
    let current: FamilyWorkPage | null = null;
    let result: FamilyWorkPage;
    do {
        result = await readCollectionPage<FamilyWorkPage>(
            `${path}?through=${floor}${before ? `&before=${before}` : ''}`,
            signal,
            headers,
        );
        current ??= result;
        events.push(...result.events);
        if (
            result.next_before !== null &&
            (result.next_before <= floor ||
                (before !== null && result.next_before >= before))
        )
            throw new Error(
                'Staff history could not be refreshed. Please try again.',
            );
        before = result.next_before;
    } while (before !== null);
    return {
        ...current,
        events: familyWorkEvents(events),
        scan_floor: floor,
        next_before: result.older_before,
    };
}
