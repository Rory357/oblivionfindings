import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import { ChatAudienceReview } from './chat-audience-review';
import type { ChatConversation } from './types';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
const conversation: ChatConversation = {
    id: 71,
    title: 'Care team',
    type: 'family',
    client: { id: 8, name: 'Jamie Example' },
    participants: [{ id: 1, name: 'Jo Example', role: 'family' }],
    latest: null,
    unread: 0,
    muted: false,
    favourite: false,
    can_send: true,
    care_team_cover: true,
    queue_status: 'open',
    assigned_to: null,
    version: 1,
    archived: false,
    audience_review_required: true,
    audience_review_token: 'a'.repeat(64),
};
const callbacks = {
    onClose: vi.fn(),
    onReviewed: vi.fn(),
    onReturnFocus: vi.fn(),
};
describe('reviewed family audience snapshot', () => {
    beforeEach(() => vi.clearAllMocks());
    it('does not silently replace a reviewed audience during refresh and requires an explicit updated review after conflict', async () => {
        const next = {
            ...conversation,
            audience_review_token: 'b'.repeat(64),
            participants: [
                ...conversation.participants,
                { id: 2, name: 'Invited relative', role: 'family' },
            ],
        };
        vi.mocked(chatRequest)
            .mockRejectedValueOnce(new ChatError('The audience changed.', 409))
            .mockResolvedValueOnce({ conversation: next })
            .mockResolvedValueOnce({
                conversation: { ...next, audience_review_required: false },
            });
        const view = render(
            <ChatAudienceReview
                actorId={1}
                conversation={conversation}
                {...callbacks}
            />,
        );
        view.rerender(
            <ChatAudienceReview
                actorId={1}
                conversation={next}
                {...callbacks}
            />,
        );
        expect(screen.queryByText('Invited relative')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Understood · Return to message',
            }),
        );
        await screen.findByRole('button', { name: 'Review updated people' });
        expect(
            JSON.parse(String(vi.mocked(chatRequest).mock.calls[0][1]?.body))
                .audience_token,
        ).toBe('a'.repeat(64));
        fireEvent.click(
            screen.getByRole('button', { name: 'Review updated people' }),
        );
        await screen.findByText('Invited relative');
        expect(callbacks.onReviewed).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Understood · Return to message',
            }),
        );
        await waitFor(() => expect(callbacks.onReviewed).toHaveBeenCalled());
        expect(
            JSON.parse(String(vi.mocked(chatRequest).mock.calls[2][1]?.body))
                .audience_token,
        ).toBe('b'.repeat(64));
    });
    it('keeps an uncertain acknowledgement retry on the same audience and ignores completion after closing', async () => {
        let finish!: (value: unknown) => void;
        vi.mocked(chatRequest)
            .mockRejectedValueOnce(new Error('Connection lost'))
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        finish = resolve;
                    }),
            );
        const view = render(
            <ChatAudienceReview
                actorId={1}
                conversation={conversation}
                {...callbacks}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Understood · Return to message',
            }),
        );
        await screen.findByText(/Connection lost/);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Understood · Return to message',
            }),
        );
        expect(vi.mocked(chatRequest).mock.calls[1][1]?.body).toBe(
            vi.mocked(chatRequest).mock.calls[0][1]?.body,
        );
        view.unmount();
        await act(async () => finish({ conversation }));
        expect(callbacks.onReviewed).not.toHaveBeenCalled();
    });
    it('blocks confirmation when current access is denied', async () => {
        vi.mocked(chatRequest).mockRejectedValue(
            new ChatError('Conversation unavailable.', 403),
        );
        render(
            <ChatAudienceReview
                actorId={1}
                conversation={conversation}
                {...callbacks}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Understood · Return to message',
            }),
        );
        await screen.findByText(/Conversation unavailable/);
        expect(
            screen.getByRole('button', {
                name: 'Understood · Return to message',
            }),
        ).toBeDisabled();
        expect(screen.queryByText('Jo Example')).not.toBeInTheDocument();
    });
});
