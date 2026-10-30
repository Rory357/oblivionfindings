import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import { ChatCollection } from './chat-collection';
import type { ChatMessage } from './types';

const history = {
    messages: [
        {
            id: 301,
            sender_id: 1,
            sender_name: 'Rohan',
            mine: false,
            content: 'Message',
            deleted: false,
            pinned: true,
            created_at: '2026-10-15T00:00:00Z',
            edited_at: null,
            reply_to_id: null,
            mutation_id: null,
            attachments: [],
            legacy_attachments: [],
            reactions: [],
        } satisfies ChatMessage,
    ],
};

vi.mock('./api', async (original) => ({
    ...(await original<typeof import('./api')>()),
    chatRequest: vi.fn(),
}));

describe('Shared message collections', () => {
    beforeEach(() => {
        vi.mocked(chatRequest).mockReset();
    });

    const item = (id: number, content: string) => ({
        conversation_id: 71,
        title: 'Care team',
        message: { ...history.messages[0], id, content },
    });

    it('opens the exact family media message with readable voice and unavailable legacy labels', async () => {
        const media = {
            ...item(301, 'Private family caption'),
            message: {
                ...history.messages[0],
                attachments: [
                    {
                        id: 44,
                        name: 'recording.webm',
                        mime_type: 'audio/webm',
                        size_bytes: 1200,
                        media_kind: 'voice_note' as const,
                        duration_ms: 32500,
                        url: '/protected/44',
                    },
                    {
                        id: 45,
                        name: 'Visit photo.jpg',
                        mime_type: 'image/jpeg',
                        size_bytes: 2400,
                        url: '/protected/45',
                    },
                ],
                legacy_attachments: [{ name: 'Earlier plan.pdf', url: null }],
            },
        };
        vi.mocked(chatRequest).mockResolvedValue({
            items: [media],
            next_before: null,
        } as never);
        const select = vi.fn();
        render(
            <ChatCollection
                actorId={1}
                kind="media"
                conversationId={71}
                clientId={8}
                onSelect={select}
            />,
        );
        const trigger = screen.getByRole('button', { name: 'Shared media' });
        fireEvent.click(trigger);
        const row = await screen.findByRole('button', {
            name: /Voice note · 0:32, Visit photo.jpg, Earlier plan.pdf \(unavailable\)/,
        });
        expect(screen.queryByText('recording.webm')).not.toBeInTheDocument();
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/collections?kind=media&conversation=71&client=8',
            expect.anything(),
        );
        fireEvent.click(row);
        expect(select).toHaveBeenCalledExactlyOnceWith(media);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        // Browsing the library does not fetch private file bytes or play audio.
        expect(chatRequest).toHaveBeenCalledTimes(1);
    });

    it('describes an empty media library without asking users to save or pin files', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            items: [],
            next_before: null,
        } as never);
        render(
            <ChatCollection
                actorId={1}
                kind="media"
                conversationId={71}
                onSelect={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Shared media' }));
        await screen.findByText(
            'Photos, files and voice notes shared in this conversation will appear here.',
        );
        expect(
            screen.queryByText(/Use a message’s actions/),
        ).not.toBeInTheDocument();
    });

    it('refreshes every loaded page, removes unavailable entries and keeps focused older items', async () => {
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({
                items: [item(301, 'Previous text'), item(200, 'Removed item')],
                next_before: 200,
                scan_floor: 200,
            } as never)
            .mockResolvedValueOnce({
                items: [item(100, 'Older retained item')],
                next_before: null,
                scan_floor: 100,
            } as never)
            .mockResolvedValueOnce({
                items: [
                    item(500, 'New saved item'),
                    item(301, 'Corrected text'),
                ],
                next_before: 301,
                older_before: 100,
            } as never)
            .mockResolvedValueOnce({
                items: [item(100, 'Older retained item')],
                next_before: null,
                older_before: 100,
            } as never);
        render(
            <ChatCollection
                actorId={1}
                kind="saved"
                clientId={8}
                onSelect={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Saved messages' }));
        await screen.findByText('Previous text');
        fireEvent.click(
            screen.getByRole('button', { name: 'Load older items' }),
        );
        const older = await screen.findByRole('button', {
            name: /Older retained item/,
        });
        older.focus();
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await screen.findByText('Corrected text');
        expect(screen.getByText('New saved item')).toBeInTheDocument();
        expect(screen.queryByText('Removed item')).not.toBeInTheDocument();
        expect(screen.queryByText('Previous text')).not.toBeInTheDocument();
        expect(older).toHaveFocus();
        expect(
            screen.getByRole('button', { name: 'Load older items' }),
        ).toBeEnabled();
        expect(chatRequest).toHaveBeenLastCalledWith(
            '/chat/api/collections?kind=saved&client=8&through=100&before=301',
            expect.anything(),
        );
    });

    it('keeps the loaded list after a partial refresh fails and retries the range, not older paging', async () => {
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({
                items: [item(100, 'Kept while disconnected')],
                next_before: 100,
                scan_floor: 100,
            } as never)
            .mockResolvedValueOnce({
                items: [item(500, 'Incomplete replacement')],
                next_before: 300,
                older_before: 100,
            } as never)
            .mockRejectedValueOnce(new ChatError('Connection interrupted', 503))
            .mockResolvedValueOnce({
                items: [item(100, 'Recovered current text')],
                next_before: null,
                older_before: 100,
            } as never);
        render(<ChatCollection actorId={1} kind="saved" onSelect={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Saved messages' }));
        await screen.findByText('Kept while disconnected');
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await screen.findByRole('alert');
        expect(screen.getByText('Kept while disconnected')).toBeInTheDocument();
        expect(
            screen.queryByText('Incomplete replacement'),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        await screen.findByText('Recovered current text');
        expect(chatRequest).toHaveBeenLastCalledWith(
            '/chat/api/collections?kind=saved&through=100',
            expect.anything(),
        );
    });

    it('clears a visible collection if a later refresh page loses access', async () => {
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({
                items: [item(100, 'Previously authorised')],
                next_before: null,
                scan_floor: 100,
            } as never)
            .mockResolvedValueOnce({
                items: [item(500, 'Unconfirmed replacement')],
                next_before: 300,
            } as never)
            .mockRejectedValueOnce(new ChatError('Access changed', 403));
        render(
            <ChatCollection
                actorId={1}
                kind="pinned"
                conversationId={71}
                onSelect={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Pinned messages' }),
        );
        await screen.findByText('Previously authorised');
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await screen.findByRole('alert');
        expect(
            screen.queryByText('Previously authorised'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Unconfirmed replacement'),
        ).not.toBeInTheDocument();
    });

    it('ignores a previous account response even when the collection path stays the same', async () => {
        let finish!: (value: unknown) => void;
        vi.mocked(chatRequest)
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        finish = resolve;
                    }) as never,
            )
            .mockResolvedValueOnce({
                items: [item(200, 'Current account')],
                next_before: null,
                scan_floor: 200,
            } as never);
        const view = render(
            <ChatCollection actorId={1} kind="saved" onSelect={vi.fn()} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Saved messages' }));
        view.rerender(
            <ChatCollection actorId={2} kind="saved" onSelect={vi.fn()} />,
        );
        await screen.findByText('Current account');
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.map(([, options]) =>
                    new Headers(options?.headers).get('X-Chat-Actor'),
                ),
        ).toEqual(['1', '2']);
        await act(async () =>
            finish({
                items: [item(100, 'Previous account')],
                next_before: null,
            }),
        );
        expect(screen.queryByText('Previous account')).not.toBeInTheDocument();
    });

    it('returns keyboard focus to a surviving item when the focused entry is removed', async () => {
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({
                items: [
                    item(200, 'Removed focused entry'),
                    item(100, 'Surviving entry'),
                ],
                next_before: null,
                scan_floor: 100,
            } as never)
            .mockResolvedValueOnce({
                items: [item(100, 'Surviving entry')],
                next_before: null,
                older_before: null,
            } as never);
        render(<ChatCollection actorId={1} kind="saved" onSelect={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Saved messages' }));
        (
            await screen.findByRole('button', { name: /Removed focused entry/ })
        ).focus();
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await waitFor(() =>
            expect(
                screen.queryByText('Removed focused entry'),
            ).not.toBeInTheDocument(),
        );
        expect(
            screen.getByRole('button', { name: /Surviving entry/ }),
        ).toHaveFocus();
    });

    it('queues a refresh received during older paging and includes the newly loaded range', async () => {
        let finishOlder!: (value: unknown) => void;
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({
                items: [item(300, 'Initial entry')],
                next_before: 300,
                scan_floor: 300,
            } as never)
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        finishOlder = resolve;
                    }) as never,
            )
            .mockResolvedValueOnce({
                items: [
                    item(300, 'Updated entry'),
                    item(100, 'Newly loaded older entry'),
                ],
                next_before: null,
                older_before: null,
            } as never);
        render(<ChatCollection actorId={1} kind="saved" onSelect={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Saved messages' }));
        await screen.findByText('Initial entry');
        fireEvent.click(
            screen.getByRole('button', { name: 'Load older items' }),
        );
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        // Let the coalesced signal reach the in-flight paging operation.
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 300));
        });
        expect(chatRequest).toHaveBeenCalledTimes(2);
        await act(async () =>
            finishOlder({
                items: [item(100, 'Newly loaded older entry')],
                next_before: null,
                scan_floor: 100,
            }),
        );
        await screen.findByText('Updated entry');
        expect(
            screen.getByText('Newly loaded older entry'),
        ).toBeInTheDocument();
        expect(chatRequest).toHaveBeenLastCalledWith(
            '/chat/api/collections?kind=saved&through=100',
            expect.anything(),
        );
    });

    it('keeps the client scope on pagination and opens the original message', async () => {
        const select = vi.fn();
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({
                items: [
                    {
                        conversation_id: 71,
                        title: 'Care team',
                        message: {
                            ...history.messages[0],
                            content: 'First saved message',
                        },
                    },
                ],
                next_before: 301,
            } as never)
            .mockResolvedValueOnce({
                items: [
                    {
                        conversation_id: 72,
                        title: 'Named worker',
                        message: {
                            ...history.messages[0],
                            id: 100,
                            content: 'Older saved message',
                        },
                    },
                ],
                next_before: null,
            } as never);
        render(
            <ChatCollection
                actorId={1}
                kind="saved"
                clientId={12}
                onSelect={select}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Saved messages' }));
        await screen.findByText('First saved message');
        fireEvent.click(
            screen.getByRole('button', { name: 'Load older items' }),
        );
        await screen.findByText('Older saved message');
        expect(chatRequest).toHaveBeenLastCalledWith(
            '/chat/api/collections?kind=saved&client=12&before=301',
            expect.anything(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: /Older saved message/ }),
        );
        expect(select).toHaveBeenCalledWith(
            expect.objectContaining({
                conversation_id: 72,
                message: expect.objectContaining({ id: 100 }),
            }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    it('discards stale results after changing conversation and redacts on revoked access', async () => {
        let resolve!: (value: unknown) => void;
        vi.mocked(chatRequest)
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }) as never,
            )
            .mockResolvedValueOnce({
                items: [
                    {
                        conversation_id: 72,
                        title: 'Current',
                        message: {
                            ...history.messages[0],
                            content: 'Current file',
                        },
                    },
                ],
                next_before: 301,
            } as never)
            .mockRejectedValueOnce(new ChatError('Access changed', 403));
        const view = render(
            <ChatCollection
                actorId={1}
                kind="pinned"
                conversationId={71}
                onSelect={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Pinned messages' }),
        );
        view.rerender(
            <ChatCollection
                actorId={1}
                kind="pinned"
                conversationId={72}
                onSelect={vi.fn()}
            />,
        );
        await screen.findByText('Current file');
        await act(async () =>
            resolve({
                items: [
                    {
                        conversation_id: 71,
                        title: 'Old',
                        message: {
                            ...history.messages[0],
                            content: 'Stale private text',
                        },
                    },
                ],
                next_before: null,
            }),
        );
        expect(
            screen.queryByText('Stale private text'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Load older items' }),
        );
        await screen.findByRole('alert');
        expect(screen.queryByText('Current file')).not.toBeInTheDocument();
    });

    it('allows continuing past a page containing only unavailable saved items', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            items: [],
            next_before: 90,
        } as never);
        render(<ChatCollection actorId={1} kind="saved" onSelect={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Saved messages' }));
        await screen.findByText(/Continue to older items/);
        expect(
            screen.getByRole('button', { name: 'Load older items' }),
        ).toBeEnabled();
    });

    it('ignores an older-page response when the client changes during loading', async () => {
        let resolve!: (value: unknown) => void;
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({ items: [], next_before: 301 } as never)
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }) as never,
            )
            .mockResolvedValueOnce({ items: [], next_before: null } as never);
        const view = render(
            <ChatCollection
                actorId={1}
                kind="saved"
                clientId={12}
                onSelect={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Saved messages' }));
        await screen.findByRole('button', { name: 'Load older items' });
        fireEvent.click(
            screen.getByRole('button', { name: 'Load older items' }),
        );
        view.rerender(
            <ChatCollection
                actorId={1}
                kind="saved"
                clientId={13}
                onSelect={vi.fn()}
            />,
        );
        await screen.findByText(/No items here yet/);
        await act(async () =>
            resolve({
                items: [
                    {
                        conversation_id: 71,
                        title: 'Previous client',
                        message: {
                            ...history.messages[0],
                            content: 'Previous client private text',
                        },
                    },
                ],
                next_before: null,
            }),
        );
        expect(
            screen.queryByText('Previous client private text'),
        ).not.toBeInTheDocument();
    });
    it('clears the collection when the session expires during refresh', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            items: [item(301, 'Private saved text')],
            next_before: null,
        });
        render(<ChatCollection actorId={1} kind="saved" onSelect={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Saved messages' }));
        await screen.findByText('Private saved text');
        vi.mocked(chatRequest).mockRejectedValue(
            new ChatError('Session expired', 419),
        );
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Session expired',
        );
        expect(
            screen.queryByText('Private saved text'),
        ).not.toBeInTheDocument();
    });
});
