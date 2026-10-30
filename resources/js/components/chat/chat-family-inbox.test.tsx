import { Button } from '@/components/ui/button';
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
import {
    ChatFamilyInbox,
    refreshFamilyInbox,
    type FamilyInboxPage,
    type FamilyInboxRow,
} from './chat-family-inbox';
vi.mock('./api', async (original) => ({
    ...(await original<typeof import('./api')>()),
    chatRequest: vi.fn(),
}));
vi.mock('./chat-thread', () => ({
    ChatThread: ({
        conversationId,
        onBack,
        handoffRequest,
    }: {
        conversationId: number;
        onBack: () => void;
        handoffRequest: number;
    }) => (
        <section aria-label="Selected family conversation">
            <p>Conversation {conversationId}</p>
            <p>Handover {handoffRequest}</p>
            <Button onClick={onBack}>Back to conversations</Button>
        </section>
    ),
}));
const row = (id = 71): FamilyInboxRow => ({
    id,
    title: 'Jo Example',
    client: { id: 8, name: 'Jamie Example' },
    status: 'open',
    assigned_to: null,
    owner_name: null,
    callback_count: 0,
    response_expectation: '',
});
const page = (override: Partial<FamilyInboxPage> = {}): FamilyInboxPage => ({
    items: [row()],
    counts: { open: 1, mine: 0, waiting: 0, callbacks: 0, resolved: 0 },
    next_before: null,
    scan_floor: 71,
    older_before: null,
    selected_available: true,
    settings: {
        enabled: true,
        family_enabled: true,
        files_enabled: true,
        max_file_mb: 20,
        max_files: 5,
        response_expectation: '',
    },
    ...override,
});
describe('Approved family inbox', () => {
    beforeEach(() => {
        vi.mocked(chatRequest).mockReset();
    });
    it('binds the queue to its rendered worker and discards a late previous-account page', async () => {
        let finish!: (value: unknown) => void;
        vi.mocked(chatRequest)
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        finish = resolve;
                    }),
            )
            .mockResolvedValue(
                page({ items: [{ ...row(72), title: 'Current relative' }] }),
            );
        const view = render(<ChatFamilyInbox actorId={1} onExit={vi.fn()} />);
        await waitFor(() => expect(finish).toBeDefined());
        const first = vi.mocked(chatRequest).mock.calls[0][1]!;
        expect(new Headers(first.headers).get('X-Chat-Actor')).toBe('1');
        view.rerender(<ChatFamilyInbox actorId={2} onExit={vi.fn()} />);
        await screen.findByText('Current relative');
        expect(first.signal?.aborted).toBe(true);
        expect(
            new Headers(
                vi.mocked(chatRequest).mock.calls.at(-1)![1]?.headers,
            ).get('X-Chat-Actor'),
        ).toBe('2');
        await act(async () => {
            finish(page());
        });
        expect(screen.queryByText('Jo Example')).not.toBeInTheDocument();
        expect(screen.getByText('Current relative')).toBeInTheDocument();
    });

    it('shows authorised counts and keeps selection and queue when returning from a conversation', async () => {
        vi.mocked(chatRequest).mockResolvedValue(page());
        render(<ChatFamilyInbox actorId={1} onExit={() => {}} />);
        const item = await screen.findByRole('button', {
            name: /Jo Example About Jamie Example/,
        });
        expect(
            screen.getByRole('button', { name: 'Needs reply 1' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(
            screen.getByText('No response expectation configured'),
        ).toBeInTheDocument();
        fireEvent.click(item);
        await screen.findByText('Conversation 71');
        fireEvent.click(
            screen.getByRole('button', { name: 'Back to conversations' }),
        );
        expect(item).toHaveFocus();
        fireEvent.click(
            screen.getByRole('button', { name: 'Hand over before shift end' }),
        );
        expect(screen.getByText('Handover 1')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Back to conversations' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Waiting on family 0' }),
        );
        await waitFor(() =>
            expect(screen.getByText('Handover 0')).toBeInTheDocument(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Hand over before shift end' }),
        );
        expect(screen.getByText('Handover 2')).toBeInTheDocument();
    });
    it.each([403, 419])(
        'retains complete rows after transient failure but clears a revoked selected conversation (%s)',
        async (status) => {
            let denied = false,
                offline = false;
            vi.mocked(chatRequest).mockImplementation(async () => {
                if (denied) throw new ChatError('Access changed', status);
                if (offline) throw new Error('Temporarily unavailable');
                return page() as never;
            });
            render(<ChatFamilyInbox actorId={1} onExit={() => {}} />);
            fireEvent.click(
                await screen.findByRole('button', {
                    name: /Jo Example About Jamie Example/,
                }),
            );
            await screen.findByText('Conversation 71');
            offline = true;
            act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
            await screen.findByText('Temporarily unavailable');
            expect(screen.getByText('Conversation 71')).toBeInTheDocument();
            offline = false;
            denied = true;
            act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
            await screen.findByText('Access changed');
            expect(
                screen.queryByText('Conversation 71'),
            ).not.toBeInTheDocument();
            expect(screen.queryByText('Jo Example')).not.toBeInTheDocument();
        },
    );
    it('switches approved filters through the dialog and rejects stale filter responses', async () => {
        let resolve!: (value: FamilyInboxPage) => void;
        vi.mocked(chatRequest)
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            )
            .mockResolvedValue(
                page({
                    items: [],
                    counts: {
                        open: 0,
                        mine: 0,
                        waiting: 0,
                        callbacks: 0,
                        resolved: 0,
                    },
                    scan_floor: null,
                }),
            );
        render(<ChatFamilyInbox actorId={1} onExit={() => {}} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Family inbox filters' }),
        );
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Resolved —',
            }),
        );
        await screen.findByText('No work in this view');
        await act(async () => resolve(page()));
        expect(screen.queryByText('Jo Example')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Resolved 0' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(([path]) => path.includes('filter=resolved')),
        ).toBe(true);
    });
    it('keeps rows while paging and removes selection when access disappears from the inbox snapshot', async () => {
        let available = true;
        vi.mocked(chatRequest).mockImplementation(async (path) =>
            path.includes('before=71')
                ? (page({ items: [row(70)], scan_floor: 70 }) as never)
                : (page({
                      selected_available: available,
                      items: available ? [row()] : [],
                      next_before:
                          available && !path.includes('through=') ? 71 : null,
                      older_before:
                          available && path.includes('through=') ? 71 : null,
                  }) as never),
        );
        render(<ChatFamilyInbox actorId={1} onExit={() => {}} />);
        fireEvent.click(
            await screen.findByRole('button', {
                name: /Jo Example About Jamie Example/,
            }),
        );
        await screen.findByText('Conversation 71');
        available = false;
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await waitFor(() =>
            expect(
                screen.queryByText('Conversation 71'),
            ).not.toBeInTheDocument(),
        );
    });
    it('never publishes a partly refreshed loaded range', async () => {
        const previous = page({ items: [row(10)], scan_floor: 10 });
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(page({ items: [row(40)], next_before: 40 }))
            .mockRejectedValueOnce(new Error('Offline'));
        await expect(
            refreshFamilyInbox(
                '/inbox?filter=open',
                previous,
                new AbortController().signal,
                1,
            ),
        ).rejects.toThrow('Offline');
        expect(previous.items.map((item) => item.id)).toEqual([10]);
    });
    it('preserves server priority across nonnumeric page anchors and passes the snapshot revision', async () => {
        const revision = 'a'.repeat(64);
        const previous = page({ items: [row(90), row(5)], scan_floor: 5 });
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(
                page({
                    revision,
                    items: [row(7), row(2)],
                    next_before: 2,
                    scan_floor: 2,
                }),
            )
            .mockResolvedValueOnce(
                page({
                    revision,
                    items: [row(99), row(5)],
                    scan_floor: 5,
                    older_before: 5,
                }),
            );
        const result = await refreshFamilyInbox(
            '/inbox?filter=open',
            previous,
            new AbortController().signal,
            1,
        );
        expect(result.items.map((item) => item.id)).toEqual([7, 2, 99, 5]);
        for (const [, options] of vi.mocked(chatRequest).mock.calls) {
            expect(new Headers(options?.headers).get('X-Chat-Actor')).toBe('1');
        }
        expect(result.next_before).toBe(5);
        expect(vi.mocked(chatRequest).mock.calls[1][0]).toContain(
            `&before=2&revision=${revision}`,
        );
        expect(vi.mocked(chatRequest).mock.calls[0][0]).toContain('&minimum=2');
    });
    it.each([
        'changed revision',
        'duplicate row',
        'invalid cursor',
        'invalid revision',
    ])('rejects %s without replacing the loaded queue', async (failure) => {
        const revision = 'a'.repeat(64);
        const previous = page({ items: [row(10)], scan_floor: 10 });
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(
                page({
                    revision,
                    items: [row(7)],
                    next_before: 7,
                    scan_floor: 7,
                }),
            )
            .mockResolvedValueOnce(
                page({
                    revision:
                        failure === 'changed revision'
                            ? 'b'.repeat(64)
                            : failure === 'invalid revision'
                              ? 'invalid'
                              : revision,
                    items: [row(failure === 'duplicate row' ? 7 : 90)],
                    next_before: failure === 'invalid cursor' ? 7 : null,
                    scan_floor: 90,
                }),
            );
        await expect(
            refreshFamilyInbox(
                '/inbox?filter=open',
                previous,
                new AbortController().signal,
                1,
            ),
        ).rejects.toThrow();
        expect(previous.items.map((item) => item.id)).toEqual([10]);
        expect(chatRequest).toHaveBeenCalledTimes(2);
    });
    it.each(['changed revision', 'duplicate row', 'server conflict'])(
        'renders server priority and refreshes after load-more returns %s',
        async (failure) => {
            const revision = 'a'.repeat(64);
            let changed = false;
            let refreshFailed = false;
            vi.mocked(chatRequest).mockImplementation(async (path) => {
                const query = new URL(path, 'https://example.test')
                    .searchParams;
                if (query.has('before')) {
                    changed = true;
                    expect(query.get('revision')).toBe(revision);
                    if (failure !== 'server conflict')
                        return page({
                            revision:
                                failure === 'changed revision'
                                    ? 'b'.repeat(64)
                                    : revision,
                            items: [row(failure === 'duplicate row' ? 2 : 99)],
                            scan_floor: failure === 'duplicate row' ? 2 : 99,
                        }) as never;
                    throw new ChatError(
                        'Family work changed. Refresh the queue before loading more.',
                        409,
                    );
                }
                if (changed && !refreshFailed) {
                    refreshFailed = true;
                    throw new ChatError(
                        'Family work changed. Refresh the queue before loading more.',
                        409,
                    );
                }
                const ids = changed ? [99, 2, 71] : [2, 71];
                return page({
                    revision: changed ? 'b'.repeat(64) : revision,
                    items: ids.map(row),
                    scan_floor: ids.at(-1)!,
                    next_before: changed ? null : 71,
                }) as never;
            });
            const { container } = render(
                <ChatFamilyInbox actorId={1} onExit={() => {}} />,
            );
            const order = () =>
                [...container.querySelectorAll('[data-inbox-row]')].map(
                    (item) => Number(item.getAttribute('data-inbox-row')),
                );
            await screen.findByRole('button', { name: 'More conversations' });
            expect(order()).toEqual([2, 71]);
            fireEvent.click(
                screen.getByRole('button', { name: 'More conversations' }),
            );
            const refresh = await screen.findByRole('button', {
                name: 'Refresh queue',
            });
            await waitFor(() => expect(refresh).toBeEnabled());
            expect(order()).toEqual([2, 71]);
            fireEvent.click(refresh);
            await waitFor(() => expect(order()).toEqual([99, 2, 71]));
            expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        },
    );
});
