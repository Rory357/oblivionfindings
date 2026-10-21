import {
    ChatError,
    chatRequest,
    conversationPath,
    type ChatUploadDraft,
} from './api';
import { chatFileUrl } from './chat-file-url';

export type ChatServerFile = {
    source: 'server-upload';
    uploadId: string;
    name: string;
    type: string;
    size: number;
    durationMs: number | null;
    mediaKind: 'file' | 'voice_note';
    state: ChatUploadDraft['state'];
    unavailable: boolean;
    previewUrl: string | null;
    downloadUrl: string | null;
};
export type ChatSelectedFile = File | ChatServerFile;
export const isServerChatFile = (
    file: ChatSelectedFile,
): file is ChatServerFile =>
    'source' in file && file.source === 'server-upload';

/** Restore metadata and an authorised server reference, never invented local file bytes. */
export async function restoreChatFiles(
    actor: number,
    conversation: number,
    ids: string[],
    signal: AbortSignal,
): Promise<ChatServerFile[]> {
    return Promise.all(
        ids.map(async (id) => {
            const path = `${conversationPath(conversation)}/uploads/${encodeURIComponent(id)}`;
            const value = await chatRequest<{
                id: string;
                conversation_id: number;
                state: string;
                name?: string | null;
                mime_type?: string | null;
                size_bytes?: number;
                media_kind?: string;
                duration_ms?: number | null;
            }>(path, {
                headers: { 'X-Chat-Actor': String(actor) },
                signal: AbortSignal.any([signal, AbortSignal.timeout(15000)]),
            });
            if (
                !value ||
                value.id !== id ||
                value.conversation_id !== conversation ||
                ![
                    'missing',
                    'uploaded',
                    'scanning',
                    'ready',
                    'blocked',
                    'unavailable',
                    'changed',
                    'discarded',
                    'consumed',
                ].includes(value.state)
            )
                throw new ChatError(
                    'An attachment could not be verified. Retry restoring your draft.',
                    0,
                );
            const missing = ['missing', 'discarded', 'consumed'].includes(
                value.state,
            );
            if (
                !missing &&
                (typeof value.name !== 'string' ||
                    !value.name ||
                    typeof value.mime_type !== 'string' ||
                    !Number.isSafeInteger(value.size_bytes) ||
                    value.size_bytes! <= 0 ||
                    !['file', 'voice_note'].includes(value.media_kind ?? '') ||
                    (value.state === 'ready' &&
                        value.media_kind === 'voice_note' &&
                        (!Number.isSafeInteger(value.duration_ms) ||
                            value.duration_ms! < 50 ||
                            value.duration_ms! > 120500 ||
                            !['audio/webm', 'audio/ogg', 'audio/mp4'].includes(
                                value.mime_type,
                            ))))
            )
                throw new ChatError(
                    'Attachment details could not be verified. Retry restoring your draft.',
                    0,
                );
            const ready = value.state === 'ready';
            const previewable = [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'application/pdf',
                'audio/webm',
                'audio/ogg',
                'audio/mp4',
            ].includes(value.mime_type ?? '');
            return {
                source: 'server-upload',
                uploadId: id,
                name: missing ? 'Unavailable attachment' : value.name!,
                type: value.mime_type ?? 'application/octet-stream',
                size: value.size_bytes ?? 0,
                durationMs:
                    value.media_kind === 'voice_note'
                        ? (value.duration_ms ?? null)
                        : null,
                mediaKind:
                    value.media_kind === 'voice_note' ? 'voice_note' : 'file',
                state: (['missing', 'uploaded'].includes(value.state)
                    ? 'unknown'
                    : value.state) as ChatUploadDraft['state'],
                unavailable: missing,
                previewUrl:
                    ready && previewable
                        ? chatFileUrl(actor, `${path}/preview`)
                        : null,
                downloadUrl: ready
                    ? chatFileUrl(actor, `${path}/download`)
                    : null,
            };
        }),
    );
}
