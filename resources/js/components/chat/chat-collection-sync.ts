import { chatRequest } from './api';
import type { ChatMessage } from './types';

export type ChatCollectionItem = {
    conversation_id: number;
    title: string;
    message: ChatMessage;
};

export type ChatCollectionPage = {
    items: ChatCollectionItem[];
    next_before: number | null;
    scan_floor?: number | null;
    older_before?: number | null;
};

export function collectionItems(items: ChatCollectionItem[]) {
    return Array.from(
        new Map(
            items
                .filter((item) => !item.message.deleted)
                .map((item) => [item.message.id, item]),
        ).values(),
    ).sort((left, right) => right.message.id - left.message.id);
}

export async function readCollectionPage<T = ChatCollectionPage>(
    path: string,
    signal: AbortSignal,
    headers?: HeadersInit,
) {
    const controller = new AbortController();
    const abort = () => controller.abort();
    signal.addEventListener('abort', abort, { once: true });
    if (signal.aborted) abort();
    const timer = setTimeout(abort, 10000);
    try {
        const result = await chatRequest<T>(path, {
            signal: controller.signal,
            ...(headers ? { headers } : {}),
        });
        if (controller.signal.aborted)
            throw new Error(
                'The list could not be refreshed. Please try again.',
            );
        return result;
    } catch (error) {
        if (controller.signal.aborted && !signal.aborted)
            throw new Error(
                'The list took too long to respond. Please try again.',
            );
        throw error;
    } finally {
        clearTimeout(timer);
        signal.removeEventListener('abort', abort);
    }
}

/** Publish one replacement only after every page in the loaded range succeeds. */
export async function refreshCollection(
    path: string,
    previous: ChatCollectionPage | null,
    signal: AbortSignal,
    actorId: number,
): Promise<ChatCollectionPage> {
    const headers = { 'X-Chat-Actor': String(actorId) };
    const floor =
        previous?.scan_floor ??
        previous?.next_before ??
        previous?.items.at(-1)?.message.id;
    if (!floor) {
        const result = await readCollectionPage(path, signal, headers);
        return { ...result, items: collectionItems(result.items) };
    }
    const items: ChatCollectionItem[] = [];
    let before: number | null = null;
    let result: ChatCollectionPage;
    do {
        result = await readCollectionPage(
            `${path}&through=${floor}${before ? `&before=${before}` : ''}`,
            signal,
            headers,
        );
        items.push(...result.items);
        if (
            result.next_before !== null &&
            (result.next_before <= floor ||
                (before !== null && result.next_before >= before))
        )
            throw new Error(
                'The list changed while refreshing. Please try again.',
            );
        before = result.next_before;
    } while (before !== null);
    return {
        items: collectionItems(items),
        next_before: result.older_before ?? null,
        scan_floor: floor,
    };
}
