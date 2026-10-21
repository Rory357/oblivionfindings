import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest, setChatDraftActor } from './api';
import { ChatWorkspace } from './chat-workspace';
import type { ChatConversation } from './types';

const account = vi.hoisted(() => ({ id: 1 }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: account } } }),
    Link: () => null,
}));
vi.mock('./chat-calling', () => ({ useChatCalling: () => null }));
vi.mock('./chat-collection', () => ({ ChatCollection: () => null }));
vi.mock('./chat-thread', () => ({
    ChatThread: ({
        conversationId,
        initialMessage,
    }: {
        conversationId: number;
        initialMessage: number | null;
    }) => (
        <div data-testid="selected-thread">
            {conversationId}:{initialMessage ?? 'latest'}
        </div>
    ),
}));
vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));

const base: ChatConversation = {
    id: 71,
    title: 'Mentioned thread',
    type: 'direct',
    client: null,
    participants: [],
    latest: null,
    latest_mention_id: 301,
    unread: 1,
    muted: false,
    favourite: false,
    can_send: true,
    care_team_cover: false,
    queue_status: 'open',
    assigned_to: null,
    version: 1,
    archived: false,
};
const data = {
    conversations: [
        base,
        { ...base, id: 72, title: 'Ordinary thread', latest_mention_id: null },
        { ...base, id: 73, title: 'Archived thread', archived: true },
        {
            ...base,
            id: 74,
            title: 'Personally archived thread',
            archived_for_me: true,
        },
    ],
    people: [],
    settings: {
        enabled: true,
        family_enabled: true,
        files_enabled: true,
        max_file_mb: 20,
        max_files: 5,
        response_expectation: '',
    },
};

describe('approved inbox filters', () => {
    beforeEach(() => {
        account.id = 1;
        vi.clearAllMocks();
        setChatDraftActor(null);
        vi.mocked(chatRequest).mockResolvedValue(data);
    });
    it.each([false, true])(
        'opens the existing missed-call dialog for an authorised notice, family=%s',
        async (family) => {
            vi.mocked(chatRequest).mockImplementation(async (url) =>
                url.includes('/calls?')
                    ? { calls: [], next_cursor: null }
                    : data,
            );
            render(
                <ChatWorkspace
                    family={family}
                    client={family ? { id: 8, name: 'Client' } : null}
                    initialConversation={71}
                    initialCallHistory="missed"
                />,
            );
            await screen.findByText('No missed calls.');
            expect(screen.getByRole('dialog')).toHaveAccessibleName(
                'Call history',
            );
            expect(
                screen.getByRole('button', { name: 'Missed' }),
            ).toHaveAttribute('aria-pressed', 'true');
            expect(
                vi
                    .mocked(chatRequest)
                    .mock.calls.some(
                        ([url]) =>
                            url ===
                            '/chat/api/conversations/71/calls?filter=missed',
                    ),
            ).toBe(true);
        },
    );

    it('does not open a notice history for a conversation absent from the current authorised list', async () => {
        render(
            <ChatWorkspace
                initialConversation={900}
                initialCallHistory="missed"
            />,
        );
        await screen.findByRole('button', { name: /Mentioned thread/ });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(([url]) => url.includes('/calls?')),
        ).toBe(false);
    });
    it.each(['account', 'client'])(
        'clears the old list and selection immediately on %s replacement and ignores late responses',
        async (context) => {
            const firstClient = { id: 8, name: 'First client' };
            let finishOld!: (value: unknown) => void;
            let finishNew!: (value: unknown) => void;
            const view = render(<ChatWorkspace family client={firstClient} />);
            fireEvent.click(
                await screen.findByRole('button', { name: /Mentioned thread/ }),
            );
            expect(screen.getByTestId('selected-thread')).toBeInTheDocument();
            vi.mocked(chatRequest).mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        finishOld = resolve;
                    }),
            );
            fireEvent(window, new Event('online'));
            await waitFor(() => expect(finishOld).toBeDefined());
            const oldOptions = vi.mocked(chatRequest).mock.calls.at(-1)![1]!;
            vi.mocked(chatRequest).mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        finishNew = resolve;
                    }),
            );
            if (context === 'account') account.id = 2;
            view.rerender(
                <ChatWorkspace
                    family
                    client={
                        context === 'client'
                            ? { id: 9, name: 'Second client' }
                            : firstClient
                    }
                />,
            );
            expect(
                screen.queryByTestId('selected-thread'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: /Mentioned thread/ }),
            ).not.toBeInTheDocument();
            expect(oldOptions.signal?.aborted).toBe(true);
            await waitFor(() => expect(finishNew).toBeDefined());
            const [path, options] = vi.mocked(chatRequest).mock.calls.at(-1)!;
            expect(path).toBe(
                `/chat/api/conversations?client=${context === 'client' ? 9 : 8}`,
            );
            expect(new Headers(options?.headers).get('X-Chat-Actor')).toBe(
                context === 'account' ? '2' : '1',
            );
            await act(async () => {
                finishNew({
                    ...data,
                    conversations: [{ ...base, title: 'New context thread' }],
                });
            });
            await screen.findByRole('button', { name: /New context thread/ });
            await act(async () => {
                finishOld(data);
            });
            expect(
                screen.queryByRole('button', { name: /Mentioned thread/ }),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('button', { name: /New context thread/ }),
            ).toBeInTheDocument();
        },
    );

    it.each([403, 419])(
        'clears private list and selected thread when refresh is denied (%s)',
        async (status) => {
            render(<ChatWorkspace />);
            fireEvent.click(
                await screen.findByRole('button', { name: /Mentioned thread/ }),
            );
            vi.mocked(chatRequest).mockRejectedValue(
                new ChatError('Refresh your session', status),
            );
            fireEvent(window, new Event('online'));
            await screen.findByText('Refresh your session');
            expect(
                screen.queryByTestId('selected-thread'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: /Mentioned thread/ }),
            ).not.toBeInTheDocument();
        },
    );
    it('reuses a named family contact from a dashboard shortcut without constraining a later new topic', async () => {
        vi.mocked(chatRequest).mockImplementation(async (_path, options) => {
            if (options?.method === 'POST')
                return { conversation: { id: 82 } } as never;
            return {
                ...data,
                people: [{ id: 2, name: 'Mere Example' }],
            } as never;
        });
        render(
            <ChatWorkspace
                family
                client={{ id: 8, name: 'Client Example' }}
                initialPerson={2}
            />,
        );
        await screen.findByRole('heading', { name: 'Confirm your contact' });
        expect(
            screen.getByRole('combobox', { name: 'Care team or named worker' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('combobox', { name: 'Care team or named worker' }),
        ).toHaveTextContent('Mere Example');
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        await waitFor(() =>
            expect(screen.getByTestId('selected-thread')).toHaveTextContent(
                '82:latest',
            ),
        );
        const request = vi
            .mocked(chatRequest)
            .mock.calls.find(([, options]) => options?.method === 'POST');
        expect(JSON.parse(request?.[1]?.body as string)).toMatchObject({
            conversation_kind: 'family',
            client_id: 8,
            participant_ids: [2],
            reuse_contact: true,
            title: null,
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Start a conversation' }),
        );
        expect(
            screen.getByRole('combobox', { name: 'Care team or named worker' }),
        ).toBeEnabled();
        expect(
            screen.getByRole('combobox', { name: 'Care team or named worker' }),
        ).toHaveTextContent('Care team inbox');
        expect(
            screen.getByRole('textbox', {
                name: 'Conversation name (optional)',
            }),
        ).toBeVisible();
    });
    it('opens the message selected by an authorised notification link and follows a second link', async () => {
        const view = render(
            <ChatWorkspace initialConversation={71} initialMessage={301} />,
        );
        await waitFor(() =>
            expect(screen.getByTestId('selected-thread')).toHaveTextContent(
                '71:301',
            ),
        );
        view.rerender(
            <ChatWorkspace initialConversation={72} initialMessage={402} />,
        );
        await waitFor(() =>
            expect(screen.getByTestId('selected-thread')).toHaveTextContent(
                '72:402',
            ),
        );
    });
    it('opens the actual mention and keeps archived conversations out of the normal inbox', async () => {
        render(<ChatWorkspace />);
        await screen.findByRole('button', { name: /Mentioned thread/ });
        expect(
            screen.queryByRole('button', { name: /Archived thread/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: /Personally archived thread/,
            }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Mentions' }));
        expect(
            screen.queryByRole('button', { name: /Ordinary thread/ }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /Mentioned thread/ }),
        );
        expect(screen.getByTestId('selected-thread')).toHaveTextContent(
            '71:301',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Archived' }));
        expect(
            screen.getByRole('button', { name: /Archived thread/ }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /Personally archived thread/ }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Mentioned thread/ }),
        ).not.toBeInTheDocument();
    });
    it('explains when all conversations are archived and provides a way to find them', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            ...data,
            conversations: [data.conversations[3]],
        });
        render(<ChatWorkspace />);
        await screen.findByText(
            'Your archived conversations are still available.',
        );
        fireEvent.click(screen.getByRole('button', { name: 'View archived' }));
        expect(
            screen.getByRole('button', { name: /Personally archived thread/ }),
        ).toBeInTheDocument();
    });
    it('keeps the staff-only Mentions filter out of family navigation', async () => {
        render(
            <ChatWorkspace family client={{ id: 8, name: 'Client Example' }} />,
        );
        await screen.findByRole('button', { name: /Mentioned thread/ });
        expect(
            screen.queryByRole('button', { name: 'Mentions' }),
        ).not.toBeInTheDocument();
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/conversations?client=8',
            expect.objectContaining({
                headers: expect.any(Headers),
                signal: expect.any(AbortSignal),
            }),
        );
    });
    it('requires a private group name and limits selection to 19 other people', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            ...data,
            people: Array.from({ length: 20 }, (_, index) => ({
                id: index + 2,
                name: `Colleague ${index + 1}`,
            })),
        });
        render(<ChatWorkspace />);
        await screen.findByRole('button', { name: /Mentioned thread/ });
        fireEvent.click(
            screen.getByRole('button', { name: 'Start a conversation' }),
        );
        fireEvent.click(screen.getByRole('radio', { name: 'Colleague 1' }));
        expect(screen.getByRole('button', { name: 'Continue' })).toBeEnabled();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Create a private group instead',
            }),
        );
        fireEvent.click(screen.getByRole('checkbox', { name: 'Colleague 2' }));
        expect(
            screen.getByRole('button', { name: 'Create group' }),
        ).toBeDisabled();
        fireEvent.change(screen.getByRole('textbox', { name: 'Group name' }), {
            target: { value: 'Afternoon care team' },
        });
        for (let index = 3; index <= 19; index++)
            fireEvent.click(
                screen.getByRole('checkbox', { name: `Colleague ${index}` }),
            );
        expect(
            screen.getByRole('checkbox', { name: 'Colleague 20' }),
        ).toBeDisabled();
        expect(screen.getByText(/20 of 20 people/)).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Create group' }),
        ).toBeEnabled();
    });
});
