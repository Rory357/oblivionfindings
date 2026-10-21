import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import { ChatInvitations } from './chat-invitations';
vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
const invitation = {
    id: 'invite-1',
    conversation_id: 71,
    title: 'Care team',
    from: 'Jo',
    version: 4,
    history_policy: 'selected',
    shared_count: 1,
    members: ['Jo', 'Rohan'],
};
describe('family invitation acceptance', () => {
    beforeEach(() => vi.clearAllMocks());
    it('reviews audience and selected history before accepting within the current client', async () => {
        vi.mocked(chatRequest).mockImplementation(async (_url, options) =>
            options?.method === 'POST'
                ? (undefined as never)
                : ({ invitations: [invitation], next_before: null } as never),
        );
        const accepted = vi.fn();
        render(
            <ChatInvitations
                actorId={1}
                clientId={8}
                clientName="Jamie Example"
                onAccepted={accepted}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Invitations' }));
        await screen.findByText('Members: Jo, Rohan');
        expect(
            screen.getByText(/1 explicitly shared earlier message/),
        ).toBeInTheDocument();
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/invitations?client=8',
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Accept invitation' }),
        );
        await waitFor(() => expect(accepted).toHaveBeenCalledWith(71));
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/invitations/invite-1',
            expect.objectContaining({
                body: JSON.stringify({ action: 'accept', version: 4 }),
            }),
        );
    });
    it('requires a fresh audience review after a conflict and does not open the conversation', async () => {
        vi.mocked(chatRequest).mockImplementation(async (_url, options) => {
            if (options?.method === 'POST')
                throw new ChatError('Conversation changed.', 409);
            return { invitations: [invitation], next_before: null } as never;
        });
        const accepted = vi.fn();
        render(
            <ChatInvitations
                actorId={1}
                clientId={8}
                clientName="Jamie Example"
                onAccepted={accepted}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Invitations' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Accept invitation' }),
        );
        await screen.findByRole('alert');
        expect(accepted).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Accept invitation' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Refresh invitations' }),
        ).toBeEnabled();
    });

    it('keeps an uncertain acceptance across closing and retries the exact reviewed response', async () => {
        const bodies: string[] = [];
        vi.mocked(chatRequest).mockImplementation(async (_url, options) => {
            if (options?.method === 'POST') {
                bodies.push(String(options.body));
                if (bodies.length === 1)
                    throw new Error('Connection interrupted');
                return undefined as never;
            }
            return { invitations: [invitation], next_before: null } as never;
        });
        const accepted = vi.fn();
        render(
            <ChatInvitations
                actorId={1}
                clientId={8}
                clientName="Jamie Example"
                onAccepted={accepted}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Invitations' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Accept invitation' }),
        );
        await screen.findByRole('button', { name: 'Retry response' });
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        fireEvent.click(screen.getByRole('button', { name: 'Invitations' }));
        expect(screen.getByRole('button', { name: 'Decline' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Retry response' }));
        await waitFor(() => expect(accepted).toHaveBeenCalledWith(71));
        expect(bodies).toEqual([
            JSON.stringify({ action: 'accept', version: 4 }),
            JSON.stringify({ action: 'accept', version: 4 }),
        ]);
    });
    it('ignores a late acceptance after an account change without opening the old conversation', async () => {
        let finish!: (value: undefined) => void;
        vi.mocked(chatRequest).mockImplementation(async (_url, options) => {
            if (options?.method === 'POST')
                return new Promise((resolve) => {
                    finish = resolve;
                });
            return { invitations: [invitation], next_before: null } as never;
        });
        const accepted = vi.fn();
        const view = render(
            <ChatInvitations
                actorId={1}
                clientId={8}
                clientName="Jamie Example"
                onAccepted={accepted}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Invitations' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Accept invitation' }),
        );
        expect(chatRequest).toHaveBeenLastCalledWith(
            '/chat/api/invitations/invite-1',
            expect.objectContaining({ headers: { 'X-Chat-Actor': '1' } }),
        );
        view.rerender(
            <ChatInvitations
                actorId={2}
                clientId={8}
                clientName="Jamie Example"
                onAccepted={accepted}
            />,
        );
        await act(async () => finish(undefined));
        expect(accepted).not.toHaveBeenCalled();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Invitations' }));
        await screen.findByText('Members: Jo, Rohan');
        expect(chatRequest).toHaveBeenLastCalledWith(
            '/chat/api/invitations?client=8',
            expect.objectContaining({ headers: { 'X-Chat-Actor': '2' } }),
        );
    });
    describe('relative agreement before someone joins', () => {
        const consent = {
            id: 'invite-9',
            conversation_id: 71,
            title: 'Mum and us',
            person: 'Aria',
            from: 'Sam (care team)',
            history_policy: 'selected',
            shared_count: 2,
        };
        function renderInvitations() {
            render(
                <ChatInvitations
                    actorId={1}
                    clientId={8}
                    clientName="Jamie Example"
                    onAccepted={vi.fn()}
                />,
            );
            fireEvent.click(
                screen.getByRole('button', { name: /^Invitations/ }),
            );
        }
        it('asks a relative already in the conversation and records agreement without a version', async () => {
            const bodies: string[] = [];
            vi.mocked(chatRequest).mockImplementation(async (_url, options) => {
                if (options?.method === 'POST') {
                    bodies.push(String(options.body));
                    return undefined as never;
                }
                return {
                    invitations: [],
                    consent_requests: [consent],
                    next_before: null,
                } as never;
            });
            renderInvitations();
            await screen.findByText('Waiting for your agreement');
            expect(
                screen.getByText(
                    'Sam (care team) would like to add Aria to “Mum and us”.',
                ),
            ).toBeInTheDocument();
            expect(
                screen.getByText(/2 explicitly shared earlier messages/),
            ).toBeInTheDocument();
            expect(
                screen.queryByText('No pending invitations for this client.'),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('button', {
                    name: /waiting for your agreement/,
                    hidden: true,
                }),
            ).toHaveTextContent('1');
            fireEvent.click(screen.getByRole('button', { name: 'Agree' }));
            await waitFor(() =>
                expect(
                    screen.queryByText('Waiting for your agreement'),
                ).not.toBeInTheDocument(),
            );
            expect(bodies).toEqual([JSON.stringify({ action: 'consent' })]);
            expect(chatRequest).toHaveBeenCalledWith(
                '/chat/api/invitations/invite-9',
                expect.objectContaining({ method: 'POST' }),
            );
        });
        it('lets the relative refuse with a neutral button and retries an uncertain answer exactly', async () => {
            const bodies: string[] = [];
            vi.mocked(chatRequest).mockImplementation(async (_url, options) => {
                if (options?.method === 'POST') {
                    bodies.push(String(options.body));
                    if (bodies.length === 1)
                        throw new Error('Connection interrupted');
                    return undefined as never;
                }
                return {
                    invitations: [],
                    consent_requests: [consent],
                    next_before: null,
                } as never;
            });
            renderInvitations();
            const refuse = await screen.findByRole('button', {
                name: "Don't agree",
            });
            expect(refuse.className).not.toMatch(/bg-destructive/);
            fireEvent.click(refuse);
            fireEvent.click(
                await screen.findByRole('button', { name: 'Retry response' }),
            );
            await waitFor(() =>
                expect(
                    screen.queryByText('Waiting for your agreement'),
                ).not.toBeInTheDocument(),
            );
            expect(bodies).toEqual([
                JSON.stringify({ action: 'refuse' }),
                JSON.stringify({ action: 'refuse' }),
            ]);
        });
        it('drops a request someone else already settled', async () => {
            vi.mocked(chatRequest).mockImplementation(async (_url, options) => {
                if (options?.method === 'POST')
                    throw new ChatError('Already decided.', 409);
                return {
                    invitations: [],
                    consent_requests: [consent],
                    next_before: null,
                } as never;
            });
            renderInvitations();
            fireEvent.click(
                await screen.findByRole('button', { name: 'Agree' }),
            );
            await screen.findByRole('alert');
            expect(
                screen.queryByText('Waiting for your agreement'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Retry response' }),
            ).not.toBeInTheDocument();
        });
    });
});
