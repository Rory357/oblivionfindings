import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    chatRequest,
    type ChatRequestOptions,
    type ChatUploadDraft,
} from './api';
import { ChatUploadCoordinator } from './chat-upload-coordinator';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
const file = new File(['content'], 'visit.txt', { type: 'text/plain' });
function setup(saved: ChatUploadDraft[] = [], permitted = () => true) {
    const persist = vi.fn();
    const coordinator = new ChatUploadCoordinator(
        1,
        71,
        saved,
        persist,
        permitted,
    );
    coordinator.reconcile([file]);
    const id = coordinator.snapshot()[0].id;
    const result = (state: string) => ({
        id,
        conversation_id: 71,
        state,
        size_bytes: file.size,
    });
    return { coordinator, persist, id, result };
}
describe('private staged upload recovery', () => {
    beforeEach(() => vi.resetAllMocks());
    it('looks up the stable reference before uploading and reports actual progress without sending a message', async () => {
        const { coordinator, result } = setup();
        const observed: string[] = [];
        coordinator.subscribe(() =>
            observed.push(coordinator.snapshot()[0].state),
        );
        vi.mocked(chatRequest).mockImplementation(async (_url, options) => {
            if (!options?.method) return result('missing');
            expect((options.body as FormData).get('file')).toBe(file);
            options.onUploadProgress?.({ loaded: 4, total: 10 });
            expect(coordinator.snapshot()[0].progress).toEqual({
                loaded: 4,
                total: 10,
            });
            options.onUploadProgress?.({ loaded: 10, total: 10 });
            return result('ready');
        });
        await coordinator.prepare(file);
        expect(observed).toEqual([
            'checking',
            'uploading',
            'uploading',
            'scanning',
            'ready',
        ]);
        expect(chatRequest).toHaveBeenCalledTimes(2);
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.map(([, options]) =>
                    new Headers(options?.headers).get('X-Chat-Actor'),
                ),
        ).toEqual(['1', '1']);
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.every(([url]) => url.includes('/uploads/')),
        ).toBe(true);
    });
    it.each(['failed', 'wrong reference', 'wrong conversation', 'wrong size'])(
        'does not retransmit after a %s lookup',
        async (kind) => {
            const { coordinator, result } = setup();
            vi.mocked(chatRequest).mockImplementation(async () => {
                if (kind === 'failed')
                    throw new Error('Connection interrupted');
                return {
                    ...result('ready'),
                    ...(kind === 'wrong reference'
                        ? { id: 'other' }
                        : kind === 'wrong conversation'
                          ? { conversation_id: 72 }
                          : { size_bytes: 1000 }),
                };
            });
            await coordinator.prepare(file);
            expect(chatRequest).toHaveBeenCalledTimes(1);
            expect(coordinator.snapshot()[0].state).toBe('unknown');
        },
    );
    it('checks an uncertain upload on return and accepts its ready result without uploading again', async () => {
        const { coordinator, result, id } = setup();
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(result('missing'))
            .mockRejectedValueOnce(new Error('Lost response'));
        await coordinator.prepare(file);
        const restored = setup(coordinator.snapshot());
        expect(restored.id).toBe(id);
        vi.mocked(chatRequest).mockClear().mockResolvedValue(result('ready'));
        await restored.coordinator.prepare(file);
        expect(chatRequest).toHaveBeenCalledTimes(1);
        expect(restored.coordinator.snapshot()[0].state).toBe('ready');
    });
    it('retries an unavailable scanner without retransmitting the file', async () => {
        const { coordinator, result, id } = setup();
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({
                ...result('unavailable'),
                retry_available: true,
            })
            .mockResolvedValueOnce(result('ready'));
        await coordinator.prepare(file);
        expect(chatRequest).toHaveBeenLastCalledWith(
            `/chat/api/conversations/71/uploads/${id}/retry`,
            expect.objectContaining({ method: 'POST' }),
        );
        expect(vi.mocked(chatRequest).mock.calls[1][1]?.body).toBeUndefined();
        expect(coordinator.snapshot()[0].state).toBe('ready');
    });
    it('keeps a blocked file out of preparation until explicitly removed', async () => {
        const { coordinator, result } = setup();
        vi.mocked(chatRequest).mockResolvedValue(result('blocked'));
        await coordinator.prepare(file);
        await coordinator.prepare(file);
        expect(chatRequest).toHaveBeenCalledTimes(1);
        expect(coordinator.snapshot()[0].error).toMatch(/Blocked/);
    });
    it('cancels an in-flight upload and ignores a late clean result after confirmed removal', async () => {
        const { coordinator, result } = setup();
        let finish!: (result: unknown) => void;
        let uploadOptions: ChatRequestOptions | undefined;
        vi.mocked(chatRequest).mockImplementation(async (_url, options) => {
            if (!options?.method) return result('missing');
            if (options.method === 'DELETE') return result('discarded');
            uploadOptions = options;
            return new Promise((resolve) => {
                finish = resolve;
            });
        });
        const preparing = coordinator.prepare(file);
        await vi.waitFor(() => expect(uploadOptions).toBeDefined());
        expect(await coordinator.remove(file)).toBe(true);
        expect(uploadOptions?.signal?.aborted).toBe(true);
        finish(result('ready'));
        await preparing;
        expect(coordinator.snapshot()[0].state).toBe('discarded');
    });
    it('retains an uncertain removal and retries the same deletion without uploading', async () => {
        const { coordinator, result, id } = setup();
        vi.mocked(chatRequest).mockResolvedValueOnce(result('ready'));
        await coordinator.prepare(file);
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new Error('Removal response lost'),
        );
        expect(await coordinator.remove(file)).toBe(false);
        expect(coordinator.snapshot()[0].state).toBe('remove_failed');
        const count = vi.mocked(chatRequest).mock.calls.length;
        await coordinator.prepare(file);
        expect(chatRequest).toHaveBeenCalledTimes(count);
        vi.mocked(chatRequest).mockResolvedValueOnce(result('discarded'));
        expect(await coordinator.remove(file)).toBe(true);
        expect(chatRequest).toHaveBeenLastCalledWith(
            `/chat/api/conversations/71/uploads/${id}`,
            expect.objectContaining({ method: 'DELETE' }),
        );
    });
    it('removes an unattempted local selection without a server request', async () => {
        const { coordinator } = setup();
        expect(await coordinator.remove(file)).toBe(true);
        expect(chatRequest).not.toHaveBeenCalled();
    });
    it('does not upload when permission changed after selection', async () => {
        const { coordinator, result } = setup([], () => false);
        vi.mocked(chatRequest).mockResolvedValue(result('missing'));
        await coordinator.prepare(file);
        expect(chatRequest).toHaveBeenCalledTimes(1);
        expect(coordinator.snapshot()[0].state).toBe('unknown');
    });
    it('aborts abandoned work, preserves its reference and ignores late completion', async () => {
        const { coordinator, result, persist, id } = setup();
        let finish!: (value: unknown) => void;
        let signal: AbortSignal | undefined;
        vi.mocked(chatRequest).mockImplementation(async (_url, options) => {
            signal = options?.signal ?? undefined;
            return new Promise((resolve) => {
                finish = resolve;
            });
        });
        const preparing = coordinator.prepare(file);
        coordinator.dispose();
        expect(signal?.aborted).toBe(true);
        expect(persist).toHaveBeenLastCalledWith([
            expect.objectContaining({ id, state: 'unknown' }),
        ]);
        const calls = persist.mock.calls.length;
        coordinator.activate();
        finish(result('ready'));
        await preparing;
        expect(persist).toHaveBeenCalledTimes(calls);
        expect(coordinator.snapshot()[0].state).toBe('unknown');
    });
});
