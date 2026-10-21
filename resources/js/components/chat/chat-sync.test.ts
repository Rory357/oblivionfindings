import { beforeEach, describe, expect, it, vi } from 'vitest';
import { chatRequest } from './api';
import { applyChatChanges, readChatChanges } from './chat-sync';
import type { ChatChanges, ChatHistory, ChatMessage } from './types';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
const message = (
    id: number,
    extra: Partial<ChatMessage> = {},
): ChatMessage => ({
    id,
    sender_id: 2,
    sender_name: 'Rohan',
    mine: false,
    content: `Message ${id}`,
    deleted: false,
    pinned: false,
    created_at: '2026-10-16T01:00:00Z',
    edited_at: null,
    reply_to_id: null,
    mutation_id: null,
    attachments: [],
    legacy_attachments: [],
    reactions: [],
    ...extra,
});
const history: ChatHistory = {
    conversation: {
        id: 71,
        title: 'Rohan',
        type: 'direct',
        client: null,
        participants: [],
        latest: null,
        unread: 0,
        muted: false,
        favourite: false,
        can_send: true,
        care_team_cover: false,
        queue_status: 'open',
        assigned_to: null,
        version: 1,
        archived: false,
    },
    messages: [message(300), message(301)],
    has_more: true,
    sync_cursor: 'before',
};
const change = (extra: Partial<ChatChanges> = {}): ChatChanges => ({
    conversation: history.conversation,
    messages: [],
    new_message_ids: [],
    sync_cursor: 'after',
    has_more_changes: false,
    ...extra,
});

describe('ordered Chat recovery', () => {
    beforeEach(() => vi.clearAllMocks());
    it('recovers more than a latest-message page and deduplicates repeated projections in cursor order', async () => {
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(
                change({
                    messages: Array.from({ length: 100 }, (_, i) =>
                        message(302 + i),
                    ),
                    new_message_ids: Array.from(
                        { length: 100 },
                        (_, i) => 302 + i,
                    ),
                    sync_cursor: 'page-two',
                    has_more_changes: true,
                }),
            )
            .mockResolvedValueOnce(
                change({
                    messages: [message(302, { pinned: true }), message(402)],
                    new_message_ids: [402],
                }),
            );
        const result = await readChatChanges(1, 71, 'before');
        expect(
            JSON.parse(String(vi.mocked(chatRequest).mock.calls[1][1]?.body)),
        ).toEqual({ cursor: 'page-two' });
        const updated = applyChatChanges(history, result, false);
        expect(updated.messages).toHaveLength(103);
        expect(updated.messages.map((item) => item.id)).toEqual(
            Array.from({ length: 103 }, (_, i) => 300 + i),
        );
        expect(updated.messages[2].pinned).toBe(true);
        expect(updated.sync_cursor).toBe('after');
        expect(updated.has_more).toBe(true);
    });
    it('keeps an older reading window while refreshing removed originals and their visible quotes', () => {
        const old = {
            ...history,
            messages: [
                message(300),
                message(301, {
                    reply_to_id: 300,
                    reply: {
                        id: 300,
                        sender_name: 'Rohan',
                        content: 'Old care detail',
                        deleted: false,
                    },
                }),
            ],
        };
        const updates = change({
            messages: [
                message(100, { pinned: true }),
                message(300, { deleted: true, content: '' }),
                message(500),
            ],
            new_message_ids: [500],
        });
        const context = applyChatChanges(old, updates, true);
        expect(context.messages.map((item) => item.id)).toEqual([300, 301]);
        expect(context.messages[0].content).toBe('');
        expect(context.messages[1].reply).toMatchObject({
            content: '',
            deleted: true,
        });
        expect(
            applyChatChanges(old, updates, false).messages.map(
                (item) => item.id,
            ),
        ).toEqual([300, 301, 500]);
    });
    it('refreshes a loaded reply when its original text changes outside the reading window', () => {
        const previous = {
            ...history,
            messages: [
                message(400, {
                    reply: {
                        id: 300,
                        sender_name: 'Rohan',
                        content: 'Old quote',
                        deleted: false,
                    },
                }),
            ],
        };
        const next = applyChatChanges(
            previous,
            change({
                messages: [
                    message(300, {
                        content: 'Corrected 🙂 quote',
                        edited_at: '2026-10-16T01:01:00Z',
                    }),
                ],
            }),
            true,
        );
        expect(next.messages).toHaveLength(1);
        expect(next.messages[0].reply?.content).toBe('Corrected 🙂 quote');
    });
    it('bounds recovery passes and advances across pages containing no visible changes', async () => {
        let page = 0;
        vi.mocked(chatRequest).mockImplementation(
            async () =>
                change({
                    sync_cursor: `page-${++page}`,
                    has_more_changes: true,
                }) as never,
        );
        const result = await readChatChanges(1, 71, 'before');
        expect(chatRequest).toHaveBeenCalledTimes(5);
        for (const [, options] of vi.mocked(chatRequest).mock.calls) {
            expect(new Headers(options?.headers).get('X-Chat-Actor')).toBe('1');
        }
        expect(result).toMatchObject({
            sync_cursor: 'page-5',
            has_more_changes: true,
            messages: [],
        });
        expect(
            JSON.parse(String(vi.mocked(chatRequest).mock.calls[4][1]?.body)),
        ).toEqual({ cursor: 'page-4' });
    });
    it('does not acknowledge an incomplete pass when a later page fails', async () => {
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(
                change({
                    sync_cursor: 'next',
                    has_more_changes: true,
                    messages: [message(302)],
                    new_message_ids: [302],
                }),
            )
            .mockRejectedValueOnce(new Error('Connection interrupted'));
        await expect(
            readChatChanges(1, 71, history.sync_cursor!),
        ).rejects.toThrow('Connection interrupted');
        expect(history.sync_cursor).toBe('before');
        expect(history.messages).toHaveLength(2);
    });
});
