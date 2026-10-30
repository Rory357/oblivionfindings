import { act, renderHook, waitFor } from '@testing-library/react';
import { StrictMode, type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import {
    chatDraftKey,
    chatRequest,
    readChatThreadMemory,
    saveChatThreadMemory,
    type ChatUploadDraft,
} from './api';
import { useChatUploads } from './use-chat-uploads';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
const file = new File(['visit'], 'visit.txt', { type: 'text/plain' });
const permitted = () => true;
beforeEach(() => {
    vi.resetAllMocks();
    chatDraftKey(99, null, 71);
    chatDraftKey(1, null, 71);
});
it('keeps the remembered reference until files restore and supports Strict Mode cleanup', async () => {
    const key = chatDraftKey(1, null, 71);
    const id = crypto.randomUUID();
    const saved: ChatUploadDraft = {
        file,
        id,
        state: 'uploading',
        attempted: true,
        error: '',
        progress: null,
    };
    saveChatThreadMemory(key, {
        files: [file],
        fileCaptions: ['Retained caption'],
        uploads: [saved],
    });
    const hook = renderHook(
        ({ files, restored }) =>
            useChatUploads(1, key, 71, files, restored, permitted),
        {
            initialProps: { files: [] as File[], restored: false },
            wrapper: ({ children }: { children: ReactNode }) => (
                <StrictMode>{children}</StrictMode>
            ),
        },
    );
    expect(readChatThreadMemory(key).uploads?.[0].id).toBe(id);
    hook.rerender({ files: [file], restored: true });
    expect(hook.result.current.rows[0]).toMatchObject({ id, state: 'unknown' });
    vi.mocked(chatRequest).mockResolvedValue({
        id,
        conversation_id: 71,
        state: 'ready',
        size_bytes: file.size,
    });
    await act(() => hook.result.current.prepare(file));
    await waitFor(() =>
        expect(hook.result.current.rows[0].state).toBe('ready'),
    );
    expect(readChatThreadMemory(key).fileCaptions).toEqual([
        'Retained caption',
    ]);
    hook.unmount();
    const restored = renderHook(() =>
        useChatUploads(1, key, 71, [file], true, permitted),
    );
    expect(restored.result.current.rows[0]).toMatchObject({
        id,
        state: 'ready',
    });
    expect(chatRequest).toHaveBeenCalledTimes(1);
});
it('does not apply an old account result or restore its files into the new account', async () => {
    const key = chatDraftKey(1, null, 71);
    let finish!: (value: unknown) => void;
    let signal: AbortSignal | undefined;
    vi.mocked(chatRequest).mockImplementation(async (_url, options) => {
        signal = options?.signal ?? undefined;
        return new Promise((resolve) => {
            finish = resolve;
        });
    });
    const hook = renderHook(
        ({ actorId, key, files }) =>
            useChatUploads(actorId, key, 71, files, true, permitted),
        { initialProps: { actorId: 1, key, files: [file] } },
    );
    const id = hook.result.current.rows[0].id;
    let request!: Promise<void>;
    act(() => {
        request = hook.result.current.prepare(file);
    });
    const nextKey = chatDraftKey(2, null, 71);
    hook.rerender({ actorId: 2, key: nextKey, files: [] });
    expect(signal?.aborted).toBe(true);
    await act(async () => {
        finish({
            id,
            conversation_id: 71,
            state: 'ready',
            size_bytes: file.size,
        });
        await request;
    });
    expect(hook.result.current.rows).toEqual([]);
    expect(readChatThreadMemory(nextKey).uploads ?? []).toEqual([]);
    expect(readChatThreadMemory(key).uploads ?? []).toEqual([]);
});
