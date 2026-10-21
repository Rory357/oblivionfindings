import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import { ChatManagement } from './chat-management';
import type { ChatConversation } from './types';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
const conversation: ChatConversation = {
    id: 71,
    title: 'Afternoon team',
    type: 'group',
    client: null,
    participants: [
        { id: 1, name: 'Alex' },
        { id: 2, name: 'Rohan' },
    ],
    latest: null,
    unread: 0,
    muted: false,
    favourite: false,
    can_send: true,
    care_team_cover: false,
    queue_status: 'open',
    assigned_to: null,
    version: 4,
    archived: false,
    management: {
        owner_ids: [1],
        can_rename: true,
        can_remove: true,
        can_transfer: true,
        can_leave: true,
        must_transfer_to_leave: true,
        eligible_owner_ids: [2],
    },
};
describe('approved conversation management', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(chatRequest).mockResolvedValue(undefined);
    });
    it('archives only the current user and returns to the inbox', async () => {
        const changed = vi.fn(),
            exit = vi.fn();
        render(
            <ChatManagement
                conversation={conversation}
                actorId={1}
                onChanged={changed}
                onExit={exit}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Manage conversation' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Archive for me' }));
        await waitFor(() => expect(exit).toHaveBeenCalledOnce());
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/conversations/71/preferences',
            expect.objectContaining({
                body: JSON.stringify({ is_archived_for_user: true }),
            }),
        );
        expect(changed).toHaveBeenCalledOnce();
    });
    it('keeps the reviewed version during a background refresh and requires review after a conflict', async () => {
        vi.mocked(chatRequest).mockRejectedValue(
            new ChatError('Conversation changed.', 409),
        );
        const changed = vi.fn();
        const view = render(
            <ChatManagement
                conversation={conversation}
                actorId={1}
                onChanged={changed}
                onExit={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Manage conversation' }),
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Conversation name' }),
            { target: { value: 'New name' } },
        );
        view.rerender(
            <ChatManagement
                conversation={{ ...conversation, version: 5 }}
                actorId={1}
                onChanged={changed}
                onExit={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save name' }));
        await screen.findByRole('alert');
        expect(
            JSON.parse(String(vi.mocked(chatRequest).mock.calls[0][1]?.body)),
        ).toEqual({ action: 'rename', title: 'New name', version: 4 });
        expect(
            screen.getByRole('button', { name: 'Save name' }),
        ).toBeDisabled();
        expect(changed).toHaveBeenCalledOnce();
        fireEvent.click(screen.getByText('Close', { selector: 'button' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Manage conversation' }),
        );
        expect(
            screen.getByRole('textbox', { name: 'Conversation name' }),
        ).toHaveValue('Afternoon team');
    });
    it('requires an eligible replacement for the last owner and sends transfer and leave together', async () => {
        const exit = vi.fn();
        render(
            <ChatManagement
                conversation={conversation}
                actorId={1}
                onChanged={vi.fn()}
                onExit={exit}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Manage conversation' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Membership and ownership' }),
        );
        expect(
            screen.getByRole('button', { name: 'Leave conversation' }),
        ).toBeDisabled();
        fireEvent.keyDown(screen.getByRole('combobox', { name: 'New owner' }), {
            key: 'Enter',
        });
        fireEvent.click(screen.getByRole('option', { name: 'Rohan' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Transfer and leave' }),
        );
        await waitFor(() => expect(exit).toHaveBeenCalledOnce());
        expect(
            JSON.parse(String(vi.mocked(chatRequest).mock.calls[0][1]?.body)),
        ).toEqual({ action: 'leave', user_id: 2, version: 4 });
    });
    it('offers personal family preferences without staff ownership controls', () => {
        render(
            <ChatManagement
                conversation={{
                    ...conversation,
                    type: 'family',
                    management: undefined,
                }}
                actorId={1}
                onChanged={vi.fn()}
                onExit={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Manage conversation' }),
        );
        expect(
            screen.getByRole('button', { name: 'Mark unread' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Membership and ownership' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('textbox', { name: 'Conversation name' }),
        ).not.toBeInTheDocument();
    });
});
