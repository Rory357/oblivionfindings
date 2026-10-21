import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ChatError,
    chatDraftKey,
    chatRequest,
    readChatThreadMemory,
    saveChatThreadMemory,
} from './api';
import { ChatMessageEditor } from './chat-message-editor';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
const initial = {
    messageId: 301,
    version: 0,
    content: 'My original',
    mentions: [],
};
const saved = {
    id: 301,
    content: 'My correction',
    edit_version: 1,
    can_edit: true,
};
describe('shared message editing', () => {
    let memoryKey: string;
    beforeEach(() => {
        vi.clearAllMocks();
        chatDraftKey(99, null, 71);
        memoryKey = chatDraftKey(1, null, 71);
        saveChatThreadMemory(memoryKey, { editing: initial });
    });
    const editor = (onSaved = vi.fn(), onClose = vi.fn()) => (
        <ChatMessageEditor
            actorId={1}
            memoryKey={memoryKey}
            conversationId={71}
            initial={readChatThreadMemory(memoryKey).editing!}
            people={[]}
            canSend
            onSaved={onSaved}
            onClose={onClose}
        />
    );
    it('saves through the existing composer and retains the exact request after an uncertain result', async () => {
        vi.mocked(chatRequest)
            .mockRejectedValueOnce(new Error('Connection interrupted'))
            .mockResolvedValueOnce({ message: saved });
        const onSaved = vi.fn();
        render(editor(onSaved));
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'My correction' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save edit' }));
        await screen.findByText('Connection interrupted');
        expect(screen.getByRole('textbox')).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Cancel edit' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Retry edit' }));
        await waitFor(() => expect(onSaved).toHaveBeenCalledWith(saved));
        expect(chatRequest).toHaveBeenCalledTimes(2);
        expect(vi.mocked(chatRequest).mock.calls[0][1]?.body).toBe(
            vi.mocked(chatRequest).mock.calls[1][1]?.body,
        );
        expect(readChatThreadMemory(memoryKey).editing).toBeNull();
    });
    it('requires reviewing a conflicting version before explicitly saving the retained edit', async () => {
        vi.mocked(chatRequest)
            .mockRejectedValueOnce(
                new ChatError(
                    'Changed in another window',
                    409,
                    'chat_edit_conflict',
                ),
            )
            .mockResolvedValueOnce({
                messages: [
                    {
                        ...saved,
                        content: 'Other window correction',
                        edit_version: 2,
                    },
                ],
            })
            .mockResolvedValueOnce({ message: saved });
        const onSaved = vi.fn();
        render(editor(onSaved));
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Keep my correction' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save edit' }));
        await screen.findByText('Changed in another window');
        expect(
            screen.getByRole('button', { name: 'Save edit' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review latest message' }),
        );
        await screen.findByText('Other window correction');
        fireEvent.click(
            screen.getByRole('button', { name: 'Keep my edit for review' }),
        );
        expect(chatRequest).toHaveBeenCalledTimes(2);
        expect(screen.getByRole('textbox')).toHaveValue('Keep my correction');
        fireEvent.click(screen.getByRole('button', { name: 'Save edit' }));
        await waitFor(() => expect(onSaved).toHaveBeenCalled());
        const oldBody = JSON.parse(
            String(vi.mocked(chatRequest).mock.calls[0][1]?.body),
        );
        const nextBody = JSON.parse(
            String(vi.mocked(chatRequest).mock.calls[2][1]?.body),
        );
        expect(nextBody).toMatchObject({
            version: 2,
            content: 'Keep my correction',
        });
        expect(nextBody.mutation_id).not.toBe(oldBody.mutation_id);
    });
    it('stops editing on revoked access and lets the user return to the separate draft', async () => {
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new ChatError('Access ended', 403),
        );
        const close = vi.fn();
        render(editor(vi.fn(), close));
        fireEvent.click(screen.getByRole('button', { name: 'Save edit' }));
        await screen.findByText('Access ended');
        expect(
            screen.getByRole('button', { name: 'Save edit' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel edit' }));
        expect(close).toHaveBeenCalledOnce();
    });
    it('can check an uncertain edit after the conversation becomes read-only', async () => {
        const pending = {
            ...initial,
            pendingBody: JSON.stringify({
                mutation_id: 'retained-reference',
                version: 0,
                content: initial.content,
                mentions: [],
            }),
        };
        saveChatThreadMemory(memoryKey, { editing: pending });
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new ChatError('Chat is read-only', 409),
        );
        render(
            <ChatMessageEditor
                actorId={1}
                memoryKey={memoryKey}
                conversationId={71}
                initial={pending}
                people={[]}
                canSend={false}
                onSaved={vi.fn()}
                onClose={vi.fn()}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Retry edit' }),
        ).toBeEnabled();
        fireEvent.click(screen.getByRole('button', { name: 'Retry edit' }));
        await screen.findByText('Chat is read-only');
        expect(
            screen.getByRole('button', { name: 'Cancel edit' }),
        ).toBeEnabled();
    });
    it('does not erase a newer edit or invoke the old screen after a late success', async () => {
        let finish!: (value: unknown) => void;
        vi.mocked(chatRequest).mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const onSaved = vi.fn();
        const view = render(editor(onSaved));
        fireEvent.click(screen.getByRole('button', { name: 'Save edit' }));
        view.unmount();
        saveChatThreadMemory(memoryKey, {
            editing: { ...initial, content: 'Newer edit' },
        });
        finish({ message: saved });
        await waitFor(() =>
            expect(readChatThreadMemory(memoryKey).editing?.content).toBe(
                'Newer edit',
            ),
        );
        expect(onSaved).not.toHaveBeenCalled();
    });
});
