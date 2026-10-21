import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { chatRequest } from './api';
import { ChatInvitationHistory } from './chat-invitation-history';
import type { ChatConversation } from './types';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
const conversation = {
    id: 71,
    type: 'family',
    client: { id: 8, name: 'Jamie Example' },
    can_manage_people: true,
    version: 4,
} as ChatConversation;
const item = {
    id: 'invitation-1',
    user_id: 3,
    name: 'Nina Example',
    status: 'accepted',
    shared_count: 0,
    created_at: '2026-10-16T00:00:00Z',
    expires_at: '2026-10-23T00:00:00Z',
    can_remove: true,
    can_cancel: false,
    can_reinvite: false,
};

describe('family invitation lifecycle', () => {
    beforeEach(() => vi.clearAllMocks());
    it('requires removal review and sends the version that was actually reviewed', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            invitations: [item],
            version: 4,
            next_before: null,
        } as never);
        const changed = vi.fn();
        const view = render(
            <ChatInvitationHistory
                actorId={1}
                conversation={conversation}
                onChanged={changed}
                onReinvite={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Family invitations' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Remove future access' }),
        );
        expect(
            screen.getByRole('button', { name: 'Confirm removal' }),
        ).toBeDisabled();
        expect(
            screen.getByText(
                /Their family portal relationship and other conversations stay unchanged/,
            ),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'I have reviewed this removal.',
            }),
        );
        view.rerender(
            <ChatInvitationHistory
                actorId={1}
                conversation={{ ...conversation, version: 9 }}
                onChanged={changed}
                onReinvite={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm removal' }),
        );
        await waitFor(() => expect(changed).toHaveBeenCalledOnce());
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/conversations/71/management',
            {
                method: 'PATCH',
                headers: { 'X-Chat-Actor': '1' },
                body: JSON.stringify({
                    action: 'remove',
                    user_id: 3,
                    version: 4,
                }),
            },
        );
    });
    it('opens a fresh review for an expired invitation without copying its selected history', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            invitations: [
                {
                    ...item,
                    status: 'expired',
                    can_remove: false,
                    can_reinvite: true,
                    shared_count: 4,
                },
            ],
            version: 4,
            next_before: null,
        } as never);
        const reinvite = vi.fn();
        render(
            <ChatInvitationHistory
                actorId={1}
                conversation={conversation}
                onChanged={vi.fn()}
                onReinvite={reinvite}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Family invitations' }),
        );
        await screen.findByText('Expired');
        fireEvent.click(
            screen.getByRole('button', { name: 'Review a new invitation' }),
        );
        expect(reinvite).toHaveBeenCalledWith(3);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.every(([, options]) => !options?.method),
        ).toBe(true);
    });
    it('blocks actions if later pages belong to a changed audience', async () => {
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({
                invitations: [item],
                version: 4,
                next_before: item.id,
            } as never)
            .mockResolvedValueOnce({
                invitations: [{ ...item, id: 'other' }],
                version: 5,
                next_before: null,
            } as never);
        render(
            <ChatInvitationHistory
                actorId={1}
                conversation={conversation}
                onChanged={vi.fn()}
                onReinvite={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Family invitations' }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Load earlier invitations',
            }),
        );
        await screen.findByText(
            'The conversation changed. Refresh invitations before continuing.',
        );
        expect(
            screen.getByRole('button', { name: 'Remove future access' }),
        ).toBeDisabled();
        expect(screen.getAllByText('Nina Example')).toHaveLength(1);
    });
    it('shows invitations waiting on, or refused by, a relative already in the conversation', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            invitations: [
                {
                    ...item,
                    id: 'invitation-2',
                    name: 'Aria Example',
                    status: 'awaiting_family',
                    can_remove: false,
                    can_cancel: true,
                },
                {
                    ...item,
                    id: 'invitation-3',
                    name: 'Moana Example',
                    status: 'refused',
                    can_remove: false,
                    can_reinvite: true,
                },
            ],
            version: 4,
            next_before: null,
        } as never);
        render(
            <ChatInvitationHistory
                actorId={1}
                conversation={conversation}
                onChanged={vi.fn()}
                onReinvite={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Family invitations' }),
        );
        const waiting = await screen.findByText('Waiting for family agreement');
        expect(waiting.className).toMatch(/status-warning/);
        expect(
            screen.getByText(
                'A relative already in this conversation must agree before they can join.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText(/· Expires/)).toBeInTheDocument();
        const refused = screen.getByText("Family didn't agree");
        expect(refused.className).toMatch(/bg-muted/);
        expect(
            screen.getByRole('button', { name: 'Cancel invitation' }),
        ).toBeInTheDocument();
    });
});
