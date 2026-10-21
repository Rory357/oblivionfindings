import { readCollectionPage } from './chat-collection-sync';
import type { ChatMessage } from './types';

export type ChatSearchItem = {
    id: number;
    kind: 'message' | 'file' | 'person' | 'conversation';
    name?: string;
    conversation: { id: number; title: string; type: string };
    message?: ChatMessage;
};
export type ChatSearchPage = {
    items: ChatSearchItem[];
    next_before: number | null;
    scan_floor: number | null;
    older_before?: number | null;
    options: {
        people: { id: number; name: string }[];
        conversations: { id: number; title: string }[];
    };
};
export function searchItems(items: ChatSearchItem[]) {
    return Array.from(
        new Map(
            items
                .filter((item) => !item.message?.deleted)
                .map((item) => [item.id, item]),
        ).values(),
    ).sort((a, b) => b.id - a.id);
}

/** Replace the entire loaded range atomically, including matches removed by edits. */
export async function refreshChatSearch(
    path: string,
    previous: ChatSearchPage | null,
    signal: AbortSignal,
    actorId: number,
): Promise<ChatSearchPage> {
    const headers = { 'X-Chat-Actor': String(actorId) };
    const floor = previous?.scan_floor ?? previous?.items.at(-1)?.id;
    if (!floor)
        return readCollectionPage<ChatSearchPage>(path, signal, headers);
    const items: ChatSearchItem[] = [];
    let before: number | null = null;
    let page: ChatSearchPage;
    do {
        page = await readCollectionPage<ChatSearchPage>(
            `${path}&through=${floor}${before ? `&before=${before}` : ''}`,
            signal,
            headers,
        );
        items.push(...page.items);
        if (
            page.next_before !== null &&
            (page.next_before <= floor ||
                (before !== null && page.next_before >= before))
        )
            throw new Error(
                'Search changed while refreshing. Please try again.',
            );
        before = page.next_before;
    } while (before !== null);
    return {
        ...page,
        items: searchItems(items),
        next_before: page.older_before ?? null,
        scan_floor: floor,
    };
}
