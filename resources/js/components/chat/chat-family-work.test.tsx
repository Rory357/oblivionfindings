import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest, setChatDraftActor } from './api';
import { ChatFamilyWork } from './chat-family-work';
import {
    refreshFamilyWork,
    type FamilyWorkPage,
} from './chat-family-work-sync';

vi.mock('./api', async (original) => ({
    ...(await original<typeof import('./api')>()),
    chatRequest: vi.fn(),
}));
const page = (override: Partial<FamilyWorkPage> = {}): FamilyWorkPage => ({
    version: 1,
    status: 'open',
    assigned_to: null,
    owner_name: null,
    owners: [
        { id: 1, name: 'Alex' },
        { id: 2, name: 'Rohan' },
    ],
    can_manage: true,
    response_expectation: '',
    events: [],
    next_before: null,
    scan_floor: null,
    older_before: null,
    ...override,
});
describe('Family ownership and staff coordination', () => {
    beforeEach(() => {
        vi.mocked(chatRequest).mockReset();
        setChatDraftActor(null);
    });
    it('requires explicit ownership confirmation and preserves a draft during a concurrent claim', async () => {
        let current = page();
        vi.mocked(chatRequest).mockImplementation(async (_path, options) => {
            if (options?.method === 'PATCH') {
                current = page({
                    version: 2,
                    assigned_to: 2,
                    owner_name: 'Rohan',
                });
                throw new ChatError(
                    'Another colleague claimed this conversation.',
                    409,
                );
            }
            return current as never;
        });
        render(
            <ChatFamilyWork
                actorId={1}
                conversationId={71}
                clientName="Jamie"
            />,
        );
        await screen.findByText('No owner assigned');
        fireEvent.click(screen.getByRole('button', { name: 'Claim' }));
        fireEvent.change(screen.getByLabelText('Staff handover note'), {
            target: { value: 'Confirm Friday visit' },
        });
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.every(([, options]) => !options?.method),
        ).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Confirm owner' }));
        await screen.findByText('Owned by Rohan');
        expect(screen.getByLabelText('Staff handover note')).toHaveValue(
            'Confirm Friday visit',
        );
        expect(
            screen.getByRole('button', { name: 'Confirm owner' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Use current owner' }),
        );
        expect(screen.getByLabelText('Next owner')).toHaveTextContent('Rohan');
        expect(
            screen.getByRole('button', { name: 'Confirm owner' }),
        ).toBeEnabled();
    });
    it('retries the exact uncertain handover after closing and remounting', async () => {
        const bodies: string[] = [];
        let current = page({ assigned_to: 1, owner_name: 'Alex' });
        vi.mocked(chatRequest).mockImplementation(async (_path, options) => {
            if (options?.method === 'PATCH') {
                bodies.push(String(options.body));
                current = page({
                    version: 2,
                    assigned_to: 2,
                    owner_name: 'Rohan',
                });
                if (bodies.length === 1) throw new Error('Lost response');
                return undefined as never;
            }
            return current as never;
        });
        const view = render(
            <ChatFamilyWork
                actorId={1}
                conversationId={71}
                clientName="Jamie"
            />,
        );
        await screen.findByText('Owned by Alex');
        fireEvent.click(screen.getByRole('button', { name: 'Hand over' }));
        fireEvent.keyDown(screen.getByLabelText('Next owner'), {
            key: 'Enter',
        });
        fireEvent.click(screen.getByRole('option', { name: 'Rohan' }));
        fireEvent.change(screen.getByLabelText('Staff handover note'), {
            target: { value: 'Please respond tomorrow' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save handover' }));
        await screen.findByRole('button', { name: 'Retry saved update' });
        view.unmount();
        render(
            <ChatFamilyWork
                actorId={1}
                conversationId={71}
                clientName="Jamie"
            />,
        );
        await screen.findByText('Owned by Rohan');
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry saved update' }),
        );
        await waitFor(() => expect(bodies).toHaveLength(2));
        expect(bodies[0]).toBe(bodies[1]);
        expect(JSON.parse(bodies[0])).toMatchObject({
            action: 'handoff',
            assigned_to: 2,
            version: 1,
            note: 'Please respond tomorrow',
        });
        await waitFor(() =>
            expect(
                screen.queryByRole('button', { name: 'Retry saved update' }),
            ).not.toBeInTheDocument(),
        );
    });
    it.each([403, 419])(
        'keeps staff notes private in wording and clears the panel on access removal (%s)',
        async (status) => {
            let revoked = false;
            vi.mocked(chatRequest).mockImplementation(async () => {
                if (revoked) throw new ChatError('Access changed', status);
                return page({
                    events: [
                        {
                            id: 7,
                            action: 'note',
                            actor_name: 'Alex',
                            owner_name: null,
                            status: 'open',
                            note: 'Private arrangements',
                            created_at: '2026-10-16T00:00:00Z',
                        },
                    ],
                    scan_floor: 7,
                }) as never;
            });
            render(
                <ChatFamilyWork
                    actorId={1}
                    conversationId={71}
                    clientName="Jamie"
                />,
            );
            await screen.findByText('No owner assigned');
            fireEvent.click(
                screen.getByRole('button', { name: 'Staff coordination' }),
            );
            expect(
                screen.getByText(/These notes do not appear in family chat/),
            ).toBeInTheDocument();
            expect(
                screen.getByText('Private arrangements'),
            ).toBeInTheDocument();
            fireEvent.change(screen.getByLabelText('Staff coordination note'), {
                target: { value: 'Unsent draft' },
            });
            revoked = true;
            act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
            await screen.findByText('Access changed');
            expect(
                screen.queryByText('Private arrangements'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByLabelText('Staff coordination note'),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('button', { name: 'Add staff note' }),
            ).toBeDisabled();
        },
    );
    it('returns focus to the private composer after a confirmed note and keeps status separate', async () => {
        const bodies: string[] = [];
        vi.mocked(chatRequest).mockImplementation(async (_path, options) => {
            if (options?.method === 'PATCH') {
                bodies.push(String(options.body));
                return undefined as never;
            }
            return page({ status: 'waiting' }) as never;
        });
        render(
            <ChatFamilyWork
                actorId={1}
                conversationId={71}
                clientName="Jamie"
            />,
        );
        await screen.findByText('No owner assigned');
        fireEvent.click(
            screen.getByRole('button', { name: 'Staff coordination' }),
        );
        fireEvent.change(screen.getByLabelText('Staff coordination note'), {
            target: { value: 'Please check the visit' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add staff note' }));
        await waitFor(() =>
            expect(
                screen.getByLabelText('Staff coordination note'),
            ).toHaveFocus(),
        );
        expect(screen.getByLabelText('Staff coordination note')).toHaveValue(
            '',
        );
        expect(JSON.parse(bodies[0])).toMatchObject({
            action: 'note',
            note: 'Please check the visit',
        });
        expect(JSON.parse(bodies[0])).not.toHaveProperty('status');
    });

    it('does not accept a late response from the previous account', async () => {
        let resolve!: (value: FamilyWorkPage) => void;
        vi.mocked(chatRequest)
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            )
            .mockResolvedValue(
                page({ owner_name: 'Current owner', assigned_to: 2 }),
            );
        const view = render(
            <ChatFamilyWork
                actorId={1}
                conversationId={71}
                clientName="Jamie"
            />,
        );
        view.rerender(
            <ChatFamilyWork
                actorId={2}
                conversationId={72}
                clientName="Other client"
            />,
        );
        await screen.findByText('Owned by Current owner');
        await act(async () =>
            resolve(page({ owner_name: 'Old private owner', assigned_to: 1 })),
        );
        expect(screen.queryByText(/Old private owner/)).not.toBeInTheDocument();
    });
    it('refreshes the whole loaded history atomically and rejects a non-progressing cursor', async () => {
        const event = (id: number) => ({
            id,
            action: 'note' as const,
            actor_name: 'Alex',
            owner_name: null,
            status: 'open',
            note: `Note ${id}`,
            created_at: '2026-10-16T00:00:00Z',
        });
        const previous = page({ events: [event(10)], scan_floor: 10 });
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(
                page({ events: [event(40)], next_before: 40, scan_floor: 40 }),
            )
            .mockRejectedValueOnce(new Error('Offline'));
        await expect(
            refreshFamilyWork(
                '/work',
                previous,
                new AbortController().signal,
                1,
            ),
        ).rejects.toThrow('Offline');
        expect(previous.events.map((row) => row.id)).toEqual([10]);
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(
                page({ events: [event(40)], next_before: 40 }),
            )
            .mockResolvedValueOnce(page({ events: [], next_before: 40 }));
        await expect(
            refreshFamilyWork(
                '/work',
                previous,
                new AbortController().signal,
                1,
            ),
        ).rejects.toThrow('Staff history could not be refreshed');
        for (const [, options] of vi.mocked(chatRequest).mock.calls) {
            expect(new Headers(options?.headers).get('X-Chat-Actor')).toBe('1');
        }
    });
});
