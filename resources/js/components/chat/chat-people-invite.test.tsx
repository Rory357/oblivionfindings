import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import { ChatPeopleInvite } from './chat-people-invite';
import type { ChatConversation, ChatMessage } from './types';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
vi.mock('sonner', () => ({ toast: { success: vi.fn() } }));
const conversation: ChatConversation = {
    id: 71,
    title: 'Afternoon team',
    type: 'group',
    client: null,
    participants: [],
    latest: null,
    unread: 0,
    muted: false,
    favourite: false,
    can_send: true,
    can_manage_people: true,
    care_team_cover: false,
    queue_status: 'open',
    assigned_to: null,
    version: 4,
    archived: false,
};
const message: ChatMessage = {
    id: 301,
    sender_id: 1,
    sender_name: 'Alex',
    mine: true,
    content: 'Reviewed message',
    deleted: false,
    pinned: false,
    created_at: '2026-10-16T00:00:00Z',
    edited_at: null,
    reply_to_id: null,
    mutation_id: null,
    attachments: [
        {
            id: 8,
            name: 'reviewed.pdf',
            mime_type: 'application/pdf',
            size_bytes: 10,
            url: '/private',
        },
    ],
    legacy_attachments: [],
    reactions: [],
};
function chooseSelectedHistory() {
    fireEvent.keyDown(
        screen.getByRole('combobox', { name: 'History access' }),
        { key: 'Enter' },
    );
    fireEvent.click(
        screen.getByRole('option', {
            name: 'Share selected earlier messages after review',
        }),
    );
}
describe('reviewed invitation history', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (options?.method === 'POST') return { pending: false } as never;
            if (url.endsWith('/people'))
                return {
                    people: [
                        { id: 2, name: 'Rohan' },
                        { id: 3, name: 'Mere' },
                    ],
                    pending: [],
                } as never;
            return {
                messages: [message],
                has_more: false,
                conversation,
            } as never;
        });
    });
    it('defaults to joining onward and sends selected people without earlier history', async () => {
        render(
            <ChatPeopleInvite
                actorId={1}
                conversation={conversation}
                onChanged={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Invite people' }));
        fireEvent.click(await screen.findByRole('checkbox', { name: 'Rohan' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Mere' }));
        expect(
            screen.getByRole('combobox', { name: 'History access' }),
        ).toHaveTextContent('From joining onward');
        fireEvent.click(screen.getByRole('button', { name: 'Add members' }));
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        const sent = vi
            .mocked(chatRequest)
            .mock.calls.find(([, options]) => options?.method === 'POST');
        expect(JSON.parse(String(sent?.[1]?.body))).toMatchObject({
            user_ids: [2, 3],
            history_policy: 'from_joining',
            message_ids: [],
            version: 4,
        });
        expect(sent?.[1]?.headers).toEqual({ 'X-Chat-Actor': '1' });
    });
    it('uses the server remaining capacity instead of allowing another full batch', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            people: [
                { id: 2, name: 'Rohan' },
                { id: 3, name: 'Mere' },
            ],
            pending: [],
            max_additional: 1,
        } as never);
        render(
            <ChatPeopleInvite
                actorId={1}
                conversation={conversation}
                onChanged={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Invite people' }));
        fireEvent.click(await screen.findByRole('checkbox', { name: 'Rohan' }));
        expect(screen.getByRole('checkbox', { name: 'Mere' })).toBeDisabled();
        expect(screen.getByText(/1 place remaining/)).toBeInTheDocument();
    });
    it('requires message and attachment review and preserves the exact invitation after an uncertain response', async () => {
        const bodies: string[] = [];
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (options?.method === 'POST') {
                bodies.push(String(options.body));
                if (bodies.length === 1)
                    throw new Error('Connection interrupted');
                return { pending: true } as never;
            }
            if (url.endsWith('/people'))
                return {
                    people: [{ id: 2, name: 'Rohan' }],
                    pending: [],
                } as never;
            return {
                messages: [message],
                has_more: false,
                conversation,
            } as never;
        });
        const view = render(
            <ChatPeopleInvite
                actorId={1}
                conversation={{ ...conversation, type: 'family' }}
                onChanged={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Invite approved relatives' }),
        );
        fireEvent.click(await screen.findByRole('checkbox', { name: 'Rohan' }));
        chooseSelectedHistory();
        await screen.findByText('Attachment: reviewed.pdf');
        expect(
            screen.getByRole('button', { name: 'Send invitation' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('checkbox', { name: /Share message from Alex/ }),
        );
        fireEvent.click(
            screen.getByRole('checkbox', { name: /I have reviewed/ }),
        );
        view.rerender(
            <ChatPeopleInvite
                actorId={1}
                conversation={{ ...conversation, type: 'family', version: 7 }}
                onChanged={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Send invitation' }),
        );
        await screen.findByText(/Your reviewed invitation is kept/);
        expect(
            screen.getByRole('combobox', { name: 'History access' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry invitation' }),
        );
        await waitFor(() => expect(bodies).toHaveLength(2));
        expect(bodies[1]).toBe(bodies[0]);
        expect(JSON.parse(bodies[0])).toMatchObject({
            version: 4,
            history_policy: 'selected',
            message_ids: [301],
            history_confirmed: true,
        });
    });

    it('retries failed people and older-history reads without losing the reviewed selection', async () => {
        let peopleReads = 0;
        let olderReads = 0;
        vi.mocked(chatRequest).mockImplementation(async (url) => {
            if (url.endsWith('/people')) {
                if (++peopleReads === 1) throw new Error('People unavailable');
                return {
                    people: [{ id: 2, name: 'Rohan' }],
                    pending: [],
                } as never;
            }
            if (url.includes('?before=301')) {
                if (++olderReads === 1) throw new Error('History unavailable');
                return {
                    messages: [
                        { ...message, id: 300, content: 'Earlier message' },
                    ],
                    has_more: false,
                    conversation,
                } as never;
            }
            return {
                messages: [message],
                has_more: true,
                conversation,
            } as never;
        });
        render(
            <ChatPeopleInvite
                actorId={1}
                conversation={conversation}
                onChanged={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Invite people' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Retry loading people' }),
        );
        fireEvent.click(await screen.findByRole('checkbox', { name: 'Rohan' }));
        chooseSelectedHistory();
        fireEvent.click(
            await screen.findByRole('checkbox', {
                name: /Share message from Alex: Reviewed/,
            }),
        );
        fireEvent.click(
            screen.getByRole('checkbox', { name: /I have reviewed/ }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Load earlier messages' }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Retry loading messages',
            }),
        );
        await screen.findByText('Earlier message');
        expect(
            screen.getByRole('checkbox', {
                name: /Share message from Alex: Reviewed/,
            }),
        ).toBeChecked();
        expect(
            screen.getByRole('checkbox', { name: /I have reviewed/ }),
        ).toBeChecked();
        expect(screen.getByRole('checkbox', { name: 'Rohan' })).toBeChecked();
        expect(olderReads).toBe(2);
    });
    it('removes the reviewed audience and history when the rendered account is rejected', async () => {
        const completed = vi.fn();
        const refresh = vi.fn();
        render(
            <ChatPeopleInvite
                actorId={1}
                conversation={conversation}
                onChanged={completed}
                onRefresh={refresh}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Invite people' }));
        fireEvent.click(await screen.findByRole('checkbox', { name: 'Rohan' }));
        chooseSelectedHistory();
        fireEvent.click(
            await screen.findByRole('checkbox', {
                name: /Share message from Alex: Reviewed/,
            }),
        );
        fireEvent.click(
            screen.getByRole('checkbox', { name: /I have reviewed/ }),
        );
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/conversations/71/people/history',
            expect.objectContaining({ headers: { 'X-Chat-Actor': '1' } }),
        );
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new ChatError('Your account changed.', 403, 'chat_actor_changed'),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add members' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Your account changed.',
        );
        expect(screen.getByRole('alert')).not.toHaveTextContent(
            'Close and reopen',
        );
        expect(
            screen.getByText('Contact access could not be confirmed.'),
        ).toBeVisible();
        expect(screen.queryByText('Reviewed message')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('checkbox', { name: 'Rohan' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Add members' }),
        ).toBeDisabled();
        expect(completed).not.toHaveBeenCalled();
        expect(refresh).toHaveBeenCalledOnce();
    });
    it('tells staff that a relative already in a family conversation must agree first', async () => {
        const family: ChatConversation = {
            ...conversation,
            type: 'family',
            client: { id: 8, name: 'Jamie Example' },
            participants: [
                { id: 1, name: 'Alex', role: 'admin' },
                { id: 9, name: 'Moana', role: 'family' },
            ],
        };
        const view = render(
            <ChatPeopleInvite
                actorId={1}
                conversation={family}
                onChanged={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Invite approved relatives' }),
        );
        fireEvent.click(await screen.findByRole('checkbox', { name: 'Rohan' }));
        expect(
            screen.getByText(
                'A relative already in this conversation must agree before Rohan can join.',
            ),
        ).toBeInTheDocument();
        view.unmount();

        render(
            <ChatPeopleInvite
                actorId={9}
                conversation={family}
                onChanged={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Invite approved relatives' }),
        );
        fireEvent.click(await screen.findByRole('checkbox', { name: 'Rohan' }));
        expect(screen.queryByText(/must agree before/)).not.toBeInTheDocument();
    });
});
