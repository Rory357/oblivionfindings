import { router } from '@inertiajs/react';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { useImperativeHandle, type Ref } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ChatError,
    chatRequest,
    savePendingChatCreation,
    setChatDraftActor,
} from './api';
import { ChatFamilyHome, type FamilyHomeData } from './chat-family-home';

const actions = vi.hoisted(() => ({ call: vi.fn(), invite: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
    Link: ({ children, href, ...props }: React.ComponentProps<'a'>) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));
vi.mock('./api', async (original) => ({
    ...(await original<typeof import('./api')>()),
    chatRequest: vi.fn(),
}));
vi.mock('./chat-calling', () => ({
    ChatCallButton: ({
        ref,
        conversation,
    }: {
        ref: Ref<{ open: () => boolean }>;
        conversation: { id: number };
    }) => {
        useImperativeHandle(ref, () => ({
            open: () => {
                actions.call(conversation.id);
                return true;
            },
        }));
        return null;
    },
}));
vi.mock('./chat-people-invite', () => ({
    ChatPeopleInvite: ({
        ref,
        conversation,
    }: {
        ref: Ref<{ open: () => void }>;
        conversation: { id: number };
    }) => {
        useImperativeHandle(ref, () => ({
            open: () => actions.invite(conversation.id),
        }));
        return null;
    },
}));
vi.mock('./chat-callbacks', () => ({
    ChatCallbacks: ({ conversationId }: { conversationId: number }) => (
        <div role="dialog">Shared callbacks for {conversationId}</div>
    ),
}));
vi.mock('./chat-urgent-contact', () => ({
    ChatUrgentHelp: () => <button>Need urgent help?</button>,
}));
const home: FamilyHomeData = {
    actor_id: 1,
    client_id: 8,
    clients: [
        { id: 8, name: 'Jamie Example' },
        { id: 9, name: 'Sam Example' },
    ],
    enabled: true,
    care_team: { conversation_id: 71, calling_enabled: true, available: true },
    response: {
        state: 'unconfigured',
        working_minutes: null,
        timezone: null,
        due_at: null,
    },
    response_expectation: '',
    contacts: [
        {
            id: 2,
            name: 'Mere Example',
            role: 'Key worker',
            available: false,
            conversation_id: 82,
        },
    ],
    conversations: [
        { id: 71, title: 'Care team' },
        { id: 82, title: 'Mere Example' },
    ],
    callbacks: [
        {
            id: 501,
            conversation_id: 71,
            status: 'requested',
            preferred_time: 'After lunch',
            agreed_time: null,
        },
    ],
};
const metadata = {
    actor_id: 1,
    client_id: 8,
    conversation: {
        id: 71,
        title: 'Care team',
        type: 'family',
        client: { id: 8, name: 'Jamie Example' },
        version: 1,
        can_send: true,
        can_call: true,
        can_manage_people: true,
    },
};
const props = { actorId: 1, client: { id: 8, name: 'Jamie Example' } };

describe('approved family contact home', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(chatRequest).mockReset();
        setChatDraftActor(null);
        setChatDraftActor(1);
        vi.mocked(chatRequest).mockImplementation(
            async (path) => (path.endsWith('/home') ? home : metadata) as never,
        );
    });
    it('shows explicit off duty without hiding authorised cover or blocking a message', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            ...home,
            contacts: home.contacts.map((person) => ({
                ...person,
                off_duty: true,
            })),
        } as never);
        render(<ChatFamilyHome {...props} />);
        expect(await screen.findByText(/Key worker · Off duty/)).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Message Mere Example' }),
        ).toBeEnabled();
        expect(
            screen.getByText(
                /Named-worker messages can also be answered by authorised/,
            ),
        ).toBeVisible();
    });
    it('opens the existing private care-team and named contact conversations and switches only linked clients', async () => {
        render(<ChatFamilyHome {...props} />);
        await screen.findByRole('button', { name: 'Message Mere Example' });
        expect(
            screen.getByText(/Response time has not been configured/),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Message care team' }),
        );
        expect(router.visit).toHaveBeenLastCalledWith(
            '/chat?client=8&conversation=71',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Message Mere Example' }),
        );
        expect(router.visit).toHaveBeenLastCalledWith(
            '/chat?client=8&conversation=82',
        );
        fireEvent.keyDown(screen.getByRole('combobox', { name: 'About' }), {
            key: 'Enter',
        });
        fireEvent.click(screen.getByRole('option', { name: 'Sam Example' }));
        expect(router.visit).toHaveBeenLastCalledWith(
            '/portal/clients/9/dashboard',
        );
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/clients/8/home',
            expect.objectContaining({ headers: { 'X-Chat-Actor': '1' } }),
        );
    });
    it('checks the current conversation before opening shared call and invitation review', async () => {
        render(<ChatFamilyHome {...props} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Call care team' }),
        );
        await waitFor(() => expect(actions.call).toHaveBeenCalledWith(71));
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/clients/8/home/conversations/71',
            expect.objectContaining({ headers: { 'X-Chat-Actor': '1' } }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Invite approved relative' }),
        );
        await waitFor(() => expect(actions.invite).toHaveBeenCalledWith(71));
    });
    it('uses the shared callback workflow for both existing requests and unavailable teams', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            ...home,
            care_team: { ...home.care_team, available: false },
        } as never);
        render(<ChatFamilyHome {...props} />);
        fireEvent.click(
            await screen.findByRole('button', { name: /Callback · Requested/ }),
        );
        expect(screen.getByRole('dialog')).toHaveTextContent(
            'Shared callbacks for 71',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Request callback' }),
        );
        expect(screen.getAllByRole('dialog')).toHaveLength(1);
        expect(
            screen.queryByRole('button', { name: 'Call care team' }),
        ).not.toBeInTheDocument();
    });
    it('reviews a fixed private contact before creating it, then opens call setup without placing a call', async () => {
        vi.mocked(chatRequest).mockImplementation(async (path, options) => {
            if (path.endsWith('/home'))
                return {
                    ...home,
                    care_team: { ...home.care_team, conversation_id: null },
                } as never;
            if (options?.method === 'POST')
                return { conversation: { id: 71 } } as never;
            return metadata as never;
        });
        render(<ChatFamilyHome {...props} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Call care team' }),
        );
        expect(
            screen.getByRole('combobox', { name: 'Care team or named worker' }),
        ).toBeDisabled();
        expect(
            screen.queryByRole('textbox', {
                name: 'Conversation name (optional)',
            }),
        ).not.toBeInTheDocument();
        expect(actions.call).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        await waitFor(() => expect(actions.call).toHaveBeenCalledWith(71));
        const post = vi
            .mocked(chatRequest)
            .mock.calls.find(([, options]) => options?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body))).toMatchObject({
            client_id: 8,
            participant_ids: [],
            reuse_contact: true,
            title: null,
        });
    });
    it('recovers a different pending conversation without launching the newer requested call', async () => {
        const body = JSON.stringify({
            mutation_id: 'prior-request',
            conversation_kind: 'family',
            client_id: 8,
            participant_ids: [2],
            title: 'Earlier topic',
        });
        savePendingChatCreation('1:8:creation', {
            body,
            people: home.contacts,
        });
        vi.mocked(chatRequest).mockImplementation(async (path, options) => {
            if (options?.method === 'POST')
                return { conversation: { id: 82 } } as never;
            return (
                path.endsWith('/home')
                    ? {
                          ...home,
                          care_team: {
                              ...home.care_team,
                              conversation_id: null,
                          },
                      }
                    : metadata
            ) as never;
        });
        render(<ChatFamilyHome {...props} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Call care team' }),
        );
        expect(
            screen.getByRole('combobox', { name: 'Care team or named worker' }),
        ).toHaveTextContent('Mere Example');
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same request' }),
        );
        await waitFor(() =>
            expect(router.visit).toHaveBeenCalledWith(
                '/chat?client=8&conversation=82',
            ),
        );
        expect(actions.call).not.toHaveBeenCalled();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.find(
                    ([, options]) => options?.method === 'POST',
                )?.[1]?.body,
        ).toBe(body);
    });
    it('ignores an earlier client response after switching clients', async () => {
        let resolve!: (value: unknown) => void;
        vi.mocked(chatRequest)
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }) as never,
            )
            .mockResolvedValue({
                ...home,
                client_id: 9,
                contacts: [],
                callbacks: [],
                conversations: [],
            } as never);
        const view = render(<ChatFamilyHome {...props} />);
        view.rerender(
            <ChatFamilyHome
                actorId={1}
                client={{ id: 9, name: 'Sam Example' }}
            />,
        );
        await screen.findByText(
            'No named contacts are currently available here. You can message the care team.',
        );
        await act(async () => resolve(home));
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'Stay in touch about Sam',
        );
        expect(
            screen.queryByRole('button', { name: 'Message Mere Example' }),
        ).not.toBeInTheDocument();
    });
    it('clears contacts and disables shortcuts when later access is revoked', async () => {
        let revoked = false;
        vi.mocked(chatRequest).mockImplementation(async () => {
            if (revoked) throw new ChatError('Access revoked', 403);
            return home as never;
        });
        render(<ChatFamilyHome {...props} />);
        await screen.findByRole('button', { name: 'Message Mere Example' });
        revoked = true;
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await screen.findByRole('alert');
        expect(
            screen.queryByRole('button', { name: 'Message Mere Example' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Message care team' }),
        ).toBeDisabled();
    });
    it('rejects mismatched action metadata without opening a call or invitation', async () => {
        vi.mocked(chatRequest).mockImplementation(
            async (path) =>
                (path.endsWith('/home')
                    ? home
                    : { ...metadata, actor_id: 2 }) as never,
        );
        render(<ChatFamilyHome {...props} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Call care team' }),
        );
        await screen.findByRole('alert');
        expect(actions.call).not.toHaveBeenCalled();
        expect(actions.invite).not.toHaveBeenCalled();
    });
});
