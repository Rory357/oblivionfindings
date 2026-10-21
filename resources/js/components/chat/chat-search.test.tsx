import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import { ChatSearch } from './chat-search';
import {
    refreshChatSearch,
    type ChatSearchItem,
    type ChatSearchPage,
} from './chat-search-sync';

vi.mock('./api', async (original) => ({
    ...(await original<typeof import('./api')>()),
    chatRequest: vi.fn(),
}));
const result = (id: number, content: string): ChatSearchItem => ({
    id,
    kind: 'message',
    conversation: { id: 71, title: 'Care team', type: 'family' },
    message: {
        id,
        content,
        sender_id: 2,
        sender_name: 'Rohan',
        mine: false,
        deleted: false,
        pinned: false,
        created_at: '2026-10-15T01:00:00Z',
        edited_at: null,
        reply_to_id: null,
        mutation_id: null,
        attachments: [],
        legacy_attachments: [],
        reactions: [],
    },
});
const page = (
    items = [result(301, 'Afternoon visit')],
    next: number | null = null,
): ChatSearchPage => ({
    items,
    next_before: next,
    scan_floor: items.at(-1)?.id ?? null,
    options: {
        people: [{ id: 2, name: 'Rohan' }],
        conversations: [{ id: 71, title: 'Care team' }],
    },
});
function choose(label: string, option: string) {
    fireEvent.keyDown(screen.getByLabelText(label), { key: 'Enter' });
    fireEvent.click(screen.getByRole('option', { name: option }));
}
// Opens a filter and proves the server-provided option is no longer offered.
async function expectFilterOptionCleared(label: string, option: string) {
    fireEvent.keyDown(screen.getByLabelText(label), { key: 'Enter' });
    const listbox = await screen.findByRole('listbox');
    expect(
        within(listbox).queryByRole('option', { name: option }),
    ).not.toBeInTheDocument();
    fireEvent.keyDown(listbox, { key: 'Escape' });
    await waitFor(() =>
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument(),
    );
}
const props = {
    actorId: 1,
    clientId: 8,
    active: true,
    onBack: vi.fn(),
    onSelect: vi.fn(),
};
describe('Search Chat', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(chatRequest).mockReset().mockResolvedValue(page());
    });

    it('uses the approved five filters and opens the exact result within client context', async () => {
        render(<ChatSearch {...props} />);
        await screen.findByText('Afternoon visit');
        fireEvent.change(screen.getByLabelText('Search'), {
            target: { value: 'visit' },
        });
        choose('From', 'Rohan');
        choose('Date', 'Today');
        choose('Conversation', 'Care team');
        await waitFor(() =>
            expect(chatRequest).toHaveBeenLastCalledWith(
                '/chat/api/search?kind=messages&q=visit&client=8&conversation=71&sender=2&date=today',
                expect.anything(),
            ),
        );
        fireEvent.click(await screen.findByText('Afternoon visit'));
        expect(props.onSelect).toHaveBeenCalledWith(
            result(301, 'Afternoon visit'),
        );
        choose('Find', 'People');
        expect(screen.getByLabelText('From')).toBeDisabled();
        expect(screen.getByLabelText('Date')).toBeDisabled();
        await waitFor(() =>
            expect(chatRequest).toHaveBeenLastCalledWith(
                '/chat/api/search?kind=people&q=visit&client=8&conversation=71',
                expect.anything(),
            ),
        );
    });

    it('retains filters and restores result focus after visiting a conversation', async () => {
        const view = render(<ChatSearch {...props} />);
        await screen.findByText('Afternoon visit');
        fireEvent.change(screen.getByLabelText('Search'), {
            target: { value: 'visit' },
        });
        fireEvent.click(await screen.findByText('Afternoon visit'));
        view.rerender(<ChatSearch {...props} active={false} />);
        view.rerender(<ChatSearch {...props} />);
        const row = (await screen.findByText('Afternoon visit')).closest(
            'button',
        );
        expect(screen.getByLabelText('Search')).toHaveValue('visit');
        await waitFor(() => expect(row).toHaveFocus());
    });

    it('discards late results for an earlier query', async () => {
        let resolve!: (value: ChatSearchPage) => void;
        vi.mocked(chatRequest).mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        render(<ChatSearch {...props} />);
        await waitFor(() => expect(chatRequest).toHaveBeenCalledTimes(1));
        fireEvent.change(screen.getByLabelText('Search'), {
            target: { value: 'visit' },
        });
        await screen.findByText('Afternoon visit');
        await act(async () => resolve(page([result(300, 'Stale result')])));
        expect(screen.queryByText('Stale result')).not.toBeInTheDocument();
    });

    it('refreshes the whole loaded range and clears results and filter metadata on denial', async () => {
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(page([result(301, 'First result')], 301))
            .mockResolvedValueOnce(page([result(200, 'Older result')]))
            .mockResolvedValueOnce({
                ...page([result(301, 'Corrected result')]),
                older_before: null,
            })
            .mockRejectedValueOnce(new ChatError('Access removed', 403));
        render(<ChatSearch {...props} />);
        await screen.findByText('First result');
        fireEvent.click(
            screen.getByRole('button', { name: 'Load more results' }),
        );
        await screen.findByText('Older result');
        act(() =>
            window.dispatchEvent(new Event('oblivion:chat-search-refresh')),
        );
        await screen.findByText('Corrected result');
        expect(chatRequest).toHaveBeenLastCalledWith(
            expect.stringContaining('through=200'),
            expect.anything(),
        );
        expect(screen.queryByText('Older result')).not.toBeInTheDocument();
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await screen.findByText('Access removed');
        expect(screen.queryByText('Corrected result')).not.toBeInTheDocument();
        await expectFilterOptionCleared('From', 'Rohan');
    });

    it('retains results after transient failure and retries failed older paging', async () => {
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(page([result(301, 'First result')], 301))
            .mockRejectedValueOnce(new ChatError('Try again', 503))
            .mockResolvedValueOnce(page([result(200, 'Older result')]));
        render(<ChatSearch {...props} />);
        await screen.findByText('First result');
        fireEvent.click(
            screen.getByRole('button', { name: 'Load more results' }),
        );
        await screen.findByText('Try again');
        expect(screen.getByText('First result')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Retry search' }));
        await screen.findByText('Older result');
        expect(chatRequest).toHaveBeenLastCalledWith(
            expect.stringContaining('&before=301'),
            expect.anything(),
        );
    });

    it('moves focus to the search heading if the focused result becomes unavailable', async () => {
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(page())
            .mockResolvedValueOnce(page([]));
        render(<ChatSearch {...props} />);
        const row = (await screen.findByText('Afternoon visit')).closest(
            'button',
        )!;
        row.focus();
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await screen.findByText('No matches');
        expect(
            screen.getByRole('heading', { name: 'Search Chat' }),
        ).toHaveFocus();
    });

    it('queues a refresh during older paging and refreshes the expanded range', async () => {
        let resolve!: (value: ChatSearchPage) => void;
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(page([result(301, 'First result')], 301))
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            )
            .mockResolvedValueOnce(
                page([
                    result(301, 'Corrected result'),
                    result(200, 'Older result'),
                ]),
            );
        render(<ChatSearch {...props} />);
        await screen.findByText('First result');
        fireEvent.click(
            screen.getByRole('button', { name: 'Load more results' }),
        );
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await act(async () => {
            await new Promise((done) => setTimeout(done, 300));
        });
        await act(async () => resolve(page([result(200, 'Older result')])));
        await screen.findByText('Corrected result');
        expect(chatRequest).toHaveBeenLastCalledWith(
            expect.stringContaining('through=200'),
            expect.anything(),
        );
        expect(screen.getByText('Older result')).toBeInTheDocument();
    });

    it('does not publish a partial refresh when a later range page fails', async () => {
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(page([result(400, 'New result')], 400))
            .mockRejectedValueOnce(new ChatError('Access removed', 403));
        await expect(
            refreshChatSearch(
                '/chat/api/search?kind=messages',
                page([result(200, 'Old result')]),
                new AbortController().signal,
                1,
            ),
        ).rejects.toThrow('Access removed');
        expect(chatRequest).toHaveBeenLastCalledWith(
            '/chat/api/search?kind=messages&through=200&before=400',
            expect.anything(),
        );
    });
    it.each([
        { actorId: 2, clientId: 8 },
        { actorId: 1, clientId: 9 },
    ])(
        'clears account/client filters and ignores the previous contexts late result',
        async (next) => {
            let finish!: (value: unknown) => void;
            vi.mocked(chatRequest)
                .mockResolvedValueOnce(page())
                .mockImplementationOnce(
                    () =>
                        new Promise((resolve) => {
                            finish = resolve;
                        }) as never,
                )
                .mockResolvedValue(
                    page([result(401, 'Current context result')]),
                );
            const mounted = render(<ChatSearch {...props} />);
            await screen.findByText('Afternoon visit');
            fireEvent.change(screen.getByLabelText('Search'), {
                target: { value: 'Previous private query' },
            });
            await waitFor(() => expect(chatRequest).toHaveBeenCalledTimes(2));
            mounted.rerender(<ChatSearch {...props} {...next} />);
            expect(screen.getByLabelText('Search')).toHaveValue('');
            expect(
                screen.queryByText('Afternoon visit'),
            ).not.toBeInTheDocument();
            await screen.findByText('Current context result');
            const [, options] = vi.mocked(chatRequest).mock.calls.at(-1)!;
            expect(new Headers(options?.headers).get('X-Chat-Actor')).toBe(
                String(next.actorId),
            );
            await act(async () =>
                finish(page([result(302, 'Previous context private result')])),
            );
            expect(
                screen.queryByText('Previous context private result'),
            ).not.toBeInTheDocument();
        },
    );
    it('removes search text and filter results returned by the server after session expiry', async () => {
        render(<ChatSearch {...props} />);
        await screen.findByText('Afternoon visit');
        vi.mocked(chatRequest).mockRejectedValue(
            new ChatError('Session expired', 419),
        );
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await screen.findByText('Session expired');
        expect(screen.queryByText('Afternoon visit')).not.toBeInTheDocument();
        await expectFilterOptionCleared('From', 'Rohan');
        await expectFilterOptionCleared('Conversation', 'Care team');
    });
});
