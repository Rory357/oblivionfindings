import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import {
    readCollectionPage,
    refreshCollection,
    type ChatCollectionItem,
} from './chat-collection-sync';

vi.mock('./api', async (original) => ({
    ...(await original<typeof import('./api')>()),
    chatRequest: vi.fn(),
}));
const item = (id: number, content = `Item ${id}`) =>
    ({
        conversation_id: 71,
        title: 'Care team',
        message: { id, content, deleted: false },
    }) as ChatCollectionItem;

describe('collection range refresh', () => {
    beforeEach(() => {
        vi.mocked(chatRequest).mockReset();
    });
    afterEach(() => vi.useRealTimers());

    it('reads the complete loaded range and keeps the cursor for older additions', async () => {
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({
                items: [item(500), item(400, 'Edited')],
                next_before: 400,
                older_before: 100,
            } as never)
            .mockResolvedValueOnce({
                items: [],
                next_before: 250,
                older_before: 100,
            } as never)
            .mockResolvedValueOnce({
                items: [item(100)],
                next_before: null,
                older_before: 100,
            } as never);
        const result = await refreshCollection(
            '/chat/api/collections?kind=saved&client=8',
            {
                items: [item(400), item(300, 'Now removed'), item(100)],
                next_before: 100,
                scan_floor: 100,
            },
            new AbortController().signal,
            1,
        );
        expect(
            result.items.map(({ message }) => [message.id, message.content]),
        ).toEqual([
            [500, 'Item 500'],
            [400, 'Edited'],
            [100, 'Item 100'],
        ]);
        expect(result.next_before).toBe(100);
        expect(result.scan_floor).toBe(100);
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.every(
                    ([, options]) =>
                        new Headers(options?.headers).get('X-Chat-Actor') ===
                        '1',
                ),
        ).toBe(true);
        expect(vi.mocked(chatRequest).mock.calls.map(([path]) => path)).toEqual(
            [
                '/chat/api/collections?kind=saved&client=8&through=100',
                '/chat/api/collections?kind=saved&client=8&through=100&before=400',
                '/chat/api/collections?kind=saved&client=8&through=100&before=250',
            ],
        );
    });

    it('rejects an incomplete replacement if a later page fails or loses access', async () => {
        const previous = {
            items: [item(100)],
            next_before: null,
            scan_floor: 100,
        };
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({
                items: [item(300)],
                next_before: 200,
            } as never)
            .mockRejectedValueOnce(new ChatError('Access changed', 403));
        await expect(
            refreshCollection(
                '/chat/api/collections?kind=pinned&conversation=71',
                previous,
                new AbortController().signal,
                1,
            ),
        ).rejects.toMatchObject({ status: 403 });
        expect(previous.items[0].message.id).toBe(100);
    });

    it('rejects repeated cursors rather than requesting indefinitely', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            items: [],
            next_before: 200,
        } as never);
        await expect(
            refreshCollection(
                '/chat/api/collections?kind=saved',
                { items: [item(100)], next_before: null, scan_floor: 100 },
                new AbortController().signal,
                1,
            ),
        ).rejects.toThrow('The list changed');
        expect(chatRequest).toHaveBeenCalledTimes(2);
    });

    it('times out a stalled read so a subsequent refresh can recover', async () => {
        vi.useFakeTimers();
        vi.mocked(chatRequest).mockImplementationOnce(
            (_path, options) =>
                new Promise((_resolve, reject) => {
                    options?.signal?.addEventListener('abort', () =>
                        reject(new DOMException('Aborted', 'AbortError')),
                    );
                }) as never,
        );
        const read = readCollectionPage(
            '/chat/api/collections?kind=saved',
            new AbortController().signal,
            { 'X-Chat-Actor': '1' },
        );
        const rejected = expect(read).rejects.toThrow('too long');
        await vi.advanceTimersByTimeAsync(10000);
        await rejected;
        vi.mocked(chatRequest).mockResolvedValueOnce({
            items: [],
            next_before: null,
        } as never);
        await expect(
            readCollectionPage(
                '/chat/api/collections?kind=saved',
                new AbortController().signal,
                { 'X-Chat-Actor': '1' },
            ),
        ).resolves.toMatchObject({ items: [] });
    });
});
