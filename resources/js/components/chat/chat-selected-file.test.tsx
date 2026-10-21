import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { chatRequest } from './api';
import { ChatAttachmentQueue, chatFileIssue } from './chat-attachment-queue';
import { restoreChatFiles } from './chat-selected-file';
import { ChatUploadCoordinator } from './chat-upload-coordinator';
import { isChatVoiceNote, voiceNoteDuration } from './chat-voice-recording';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
const id = '9071f3af-d342-4d6e-bd78-ae236a1ae19e';
const metadata = {
    id,
    conversation_id: 71,
    state: 'ready',
    name: 'visit.png',
    mime_type: 'image/png',
    size_bytes: 800,
    media_kind: 'file',
    duration_ms: null,
};
const restore = () =>
    restoreChatFiles(11, 71, [id], new AbortController().signal);
describe('restored upload references', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        URL.createObjectURL = vi.fn(() => 'blob:restored-preview');
        URL.revokeObjectURL = vi.fn();
    });
    afterEach(() => vi.unstubAllGlobals());
    it('restores author-scoped metadata without inventing File bytes or accepting arbitrary preview links', async () => {
        vi.mocked(chatRequest).mockResolvedValueOnce({
            ...metadata,
            preview_url: 'https://unrelated.example/private',
        });
        const [file] = await restore();
        expect(file).not.toBeInstanceOf(File);
        expect(file).toMatchObject({
            source: 'server-upload',
            uploadId: id,
            name: 'visit.png',
            size: 800,
            previewUrl: `/chat/api/conversations/71/uploads/${id}/preview?_chat_actor=11`,
        });
        expect(chatRequest).toHaveBeenCalledWith(
            `/chat/api/conversations/71/uploads/${id}`,
            expect.objectContaining({ headers: { 'X-Chat-Actor': '11' } }),
        );
        expect(URL.createObjectURL).not.toHaveBeenCalled();
        expect(chatFileIssue(file, 20)).toBeNull();
    });
    it('rejects mismatched identity, incomplete metadata and invalid ready voice duration', async () => {
        for (const patch of [
            { conversation_id: 72 },
            { id: 'other' },
            { size_bytes: -1 },
            { mime_type: null },
            { media_kind: 'voice_note', duration_ms: 0 },
        ]) {
            vi.mocked(chatRequest).mockResolvedValueOnce({
                ...metadata,
                ...patch,
            });
            await expect(restore()).rejects.toThrow('could not be verified');
        }
    });
    it('retains an unavailable reference for explicit removal instead of silently dropping it', async () => {
        vi.mocked(chatRequest).mockResolvedValueOnce({
            id,
            conversation_id: 71,
            state: 'missing',
        });
        const [file] = await restore();
        expect(file).toMatchObject({
            uploadId: id,
            unavailable: true,
            previewUrl: null,
            downloadUrl: null,
        });
        expect(chatFileIssue(file, 20)).toContain('no longer available');
    });
    it('keeps restored voice identity while a safety check is unfinished and validates a ready voice duration', async () => {
        vi.mocked(chatRequest).mockResolvedValueOnce({
            ...metadata,
            state: 'unavailable',
            name: 'voice.webm',
            mime_type: 'video/webm',
            media_kind: 'voice_note',
            duration_ms: null,
        });
        const [waiting] = await restore();
        expect(isChatVoiceNote(waiting)).toBe(true);
        expect(voiceNoteDuration(waiting)).toBeNull();
        expect(chatFileIssue(waiting, 20)).toBeNull();
        vi.mocked(chatRequest).mockResolvedValueOnce({
            ...metadata,
            name: 'voice.webm',
            mime_type: 'audio/webm',
            media_kind: 'voice_note',
            duration_ms: 42000,
        });
        const [ready] = await restore();
        expect(voiceNoteDuration(ready)).toBe(42000);
    });
    it('checks a restored upload under its original ID and never uploads metadata as a file if the record disappears', async () => {
        vi.mocked(chatRequest).mockResolvedValueOnce(metadata);
        const [file] = await restore();
        const uploads = new ChatUploadCoordinator(
            1,
            71,
            [],
            vi.fn(),
            () => true,
        );
        uploads.reconcile([file]);
        expect(uploads.snapshot()[0]).toMatchObject({
            id,
            state: 'ready',
            attempted: true,
        });
        vi.mocked(chatRequest).mockResolvedValueOnce({
            id,
            conversation_id: 71,
            state: 'missing',
        });
        await uploads.prepare(file);
        expect(uploads.snapshot()[0].error).toContain(
            'original upload is no longer available',
        );
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.every(([, options]) => !options?.method),
        ).toBe(true);
        vi.mocked(chatRequest).mockResolvedValueOnce({
            ...metadata,
            state: 'discarded',
        });
        expect(await uploads.remove(file)).toBe(true);
        expect(vi.mocked(chatRequest).mock.calls[2][0]).toBe(
            `/chat/api/conversations/71/uploads/${id}`,
        );
        expect(vi.mocked(chatRequest).mock.calls[2][1]?.method).toBe('DELETE');
        uploads.dispose();
    });
    it('uses the existing attachment review and protected preview renderer without a local-file placeholder', async () => {
        vi.mocked(chatRequest).mockResolvedValueOnce(metadata);
        const [file] = await restore();
        const fetch = vi.fn().mockResolvedValue(
            new Response(new Uint8Array([137, 80, 78, 71]), {
                headers: { 'Content-Type': 'image/png' },
            }),
        );
        vi.stubGlobal('fetch', fetch);
        render(
            <ChatAttachmentQueue
                open
                onOpenChange={vi.fn()}
                files={[file]}
                captions={['Garden visit']}
                maxFiles={5}
                maxMb={20}
                locked={false}
                sending={false}
                progress={null}
                uploads={[
                    {
                        file,
                        id,
                        state: 'ready',
                        attempted: true,
                        error: '',
                        progress: null,
                    },
                ]}
                onPrepare={vi.fn()}
                onChoose={vi.fn()}
                onRemove={vi.fn()}
                onCaption={vi.fn()}
                onClear={vi.fn()}
                onReturnFocus={vi.fn()}
            />,
        );
        expect(screen.getByLabelText('Caption for visit.png')).toHaveValue(
            'Garden visit',
        );
        expect(screen.getByText('Private upload restored')).toBeInTheDocument();
        expect(URL.createObjectURL).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Preview selected visit.png' }),
        );
        await waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(String(fetch.mock.calls[0][0])).toContain(
            `/chat/api/conversations/71/uploads/${id}/preview?_chat_actor=11`,
        );
        expect(
            screen.getByText('Private upload · Not sent'),
        ).toBeInTheDocument();
    });
});
