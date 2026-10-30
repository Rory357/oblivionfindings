import { readCollectionPage } from './chat-collection-sync';

export type ChatCallback = {
    id: number;
    requested_by: number;
    assigned_to: number | null;
    owner_name: string | null;
    status: string;
    preferred_time: string | null;
    agreed_time: string | null;
    request_note: string | null;
    attempts: number;
    version: number;
    created_at: string;
    attempt_history: { id: number; created_at: string }[];
    eligible_owner_ids: number[];
    can_manage: boolean;
    can_cancel: boolean;
};
export type ChatCallbackPage = {
    callbacks: ChatCallback[];
    current_callback: ChatCallback | null;
    owners: { id: number; name: string }[];
    can_request: boolean;
    next_before: number | null;
    scan_floor: number | null;
    older_before?: number | null;
};
export const callbackActive = (row: ChatCallback) =>
    ['requested', 'assigned', 'scheduled', 'attempted'].includes(row.status);
export const callbackStatus: Record<string, string> = {
    requested: 'Requested',
    assigned: 'Assigned',
    scheduled: 'Scheduled',
    attempted: 'Attempted',
    completed: 'Completed',
    unreachable: 'Unable to contact',
    cancelled: 'Cancelled',
};
export const callbackRows = (rows: ChatCallback[]) =>
    Array.from(new Map(rows.map((row) => [row.id, row])).values()).sort(
        (a, b) => b.id - a.id,
    );
export async function refreshCallbacks(
    path: string,
    previous: ChatCallbackPage | null,
    signal: AbortSignal,
    actorId: number,
): Promise<ChatCallbackPage> {
    const headers = { 'X-Chat-Actor': String(actorId) };
    const floor = previous?.scan_floor ?? previous?.callbacks.at(-1)?.id;
    if (!floor)
        return readCollectionPage<ChatCallbackPage>(path, signal, headers);
    let before: number | null = null;
    let page: ChatCallbackPage;
    const rows: ChatCallback[] = [];
    do {
        page = await readCollectionPage<ChatCallbackPage>(
            `${path}?through=${floor}${before ? `&before=${before}` : ''}`,
            signal,
            headers,
        );
        rows.push(...page.callbacks);
        if (
            page.next_before !== null &&
            (page.next_before <= floor ||
                (before !== null && page.next_before >= before))
        )
            throw new Error('The callback list changed. Please try again.');
        before = page.next_before;
    } while (before !== null);
    return {
        ...page,
        callbacks: callbackRows(rows),
        scan_floor: floor,
        next_before: page.older_before ?? null,
    };
}
