import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ChatError,
    chatDraftKey,
    chatRequest,
    readChatThreadMemory,
    saveChatThreadMemory,
} from './api';
import { ChatMessageRemoval } from './chat-message-removal';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
describe('reviewed message removal', () => {
    let memoryKey: string;
    beforeEach(() => {
        vi.clearAllMocks();
        chatDraftKey(99, null, 71);
        memoryKey = chatDraftKey(1, null, 71);
        saveChatThreadMemory(memoryKey, {
            removing: {
                messageId: 301,
                version: 0,
                content: 'Original wording',
            },
        });
    });
    const dialog = (onRemoved = vi.fn(), onClose = vi.fn(), canSend = true) => (
        <ChatMessageRemoval
            actorId={1}
            memoryKey={memoryKey}
            conversationId={71}
            initial={readChatThreadMemory(memoryKey).removing!}
            canSend={canSend}
            onRemoved={onRemoved}
            onClose={onClose}
            onReturnFocus={vi.fn()}
        />
    );

    it('retains the exact removal after a lost response and remount, even when new changes are disabled', async () => {
        vi.mocked(chatRequest)
            .mockRejectedValueOnce(new Error('Connection interrupted'))
            .mockResolvedValueOnce(undefined);
        const removed = vi.fn();
        const closed = vi.fn();
        const view = render(dialog(removed, closed));
        fireEvent.click(screen.getByRole('button', { name: 'Remove message' }));
        await screen.findByText('Connection interrupted');
        const body = vi.mocked(chatRequest).mock.calls[0][1]?.body;
        expect(
            screen.getByRole('button', { name: 'Keep message' }),
        ).toBeDisabled();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(closed).not.toHaveBeenCalled();
        view.unmount();
        render(dialog(removed, closed, false));
        fireEvent.click(screen.getByRole('button', { name: 'Retry removal' }));
        await waitFor(() => expect(removed).toHaveBeenCalledOnce());
        expect(vi.mocked(chatRequest).mock.calls[1][1]?.body).toBe(body);
        expect(readChatThreadMemory(memoryKey).removing).toBeNull();
    });

    it('requires loading and explicitly confirming the latest version after a conflict', async () => {
        vi.mocked(chatRequest)
            .mockRejectedValueOnce(
                new ChatError(
                    'Changed in another window',
                    409,
                    'chat_remove_conflict',
                ),
            )
            .mockRejectedValueOnce(new Error('Refresh unavailable'))
            .mockResolvedValueOnce({
                messages: [
                    {
                        id: 301,
                        content: 'Corrected wording',
                        edit_version: 2,
                        can_remove: true,
                    },
                ],
            })
            .mockResolvedValueOnce(undefined);
        const removed = vi.fn();
        render(dialog(removed));
        fireEvent.click(screen.getByRole('button', { name: 'Remove message' }));
        await screen.findByText('Changed in another window');
        expect(
            screen.getByRole('button', { name: 'Remove message' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review latest message' }),
        );
        await screen.findByText('Refresh unavailable');
        expect(
            screen.getByRole('button', { name: 'Remove message' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review latest message' }),
        );
        await screen.findByText('Corrected wording');
        expect(removed).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Remove message' }));
        await waitFor(() => expect(removed).toHaveBeenCalledOnce());
        const first = JSON.parse(
            String(vi.mocked(chatRequest).mock.calls[0][1]?.body),
        );
        const last = JSON.parse(
            String(vi.mocked(chatRequest).mock.calls[3][1]?.body),
        );
        expect(last.version).toBe(2);
        expect(last.mutation_id).not.toBe(first.mutation_id);
    });

    it('redacts the preview after revoked access and allows closing the refused request', async () => {
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new ChatError('Access removed', 403),
        );
        const closed = vi.fn();
        render(dialog(vi.fn(), closed));
        fireEvent.click(screen.getByRole('button', { name: 'Remove message' }));
        await screen.findByText('Access removed');
        expect(screen.queryByText('Original wording')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Retry removal' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Close review' }));
        expect(closed).toHaveBeenCalledOnce();
        expect(readChatThreadMemory(memoryKey).removing).toBeNull();
    });

    it('does not repeat a request while pending or apply an old account response', async () => {
        let finish!: () => void;
        vi.mocked(chatRequest).mockReturnValueOnce(
            new Promise<void>((resolve) => {
                finish = resolve;
            }),
        );
        const removed = vi.fn();
        render(dialog(removed));
        fireEvent.click(screen.getByRole('button', { name: 'Remove message' }));
        expect(
            screen.getByRole('button', { name: 'Checking…' }),
        ).toBeDisabled();
        expect(chatRequest).toHaveBeenCalledOnce();
        chatDraftKey(2, null, 71);
        finish();
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Retry removal' }),
            ).toBeEnabled(),
        );
        expect(removed).not.toHaveBeenCalled();
        expect(readChatThreadMemory(memoryKey).removing).toBeUndefined();
    });
});
