import {
    FilePreviewDialog,
    type PreviewFile,
} from '@/components/files/file-preview-dialog';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { FileText, ImageIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ChatError, chatDraftKey, readPublicationMemory } from './api';
import { chatActorRequest } from './chat-actor-request';
import { ChatAttachmentPublication } from './chat-attachment-publication';
import { chatFileUrl } from './chat-file-url';
import { watchChatUpdates } from './chat-update-watch';
import { ChatVoiceAttachment } from './chat-voice-attachment';
import type { ChatMessage } from './types';

type Attachment = ChatMessage['attachments'][number];
type Metadata = {
    id: number;
    message_id: number;
    conversation_id: number;
    name: string;
    mime_type: string;
    size_bytes: number;
    sender_name: string;
    created_at: string;
    audience_label: string;
    preview_url: string | null;
    download_url: string;
    can_publish?: boolean;
    caption?: string | null;
    media_kind?: 'file' | 'voice_note';
    duration_ms?: number | null;
};

export function ChatAttachment({
    file,
    messageId,
    conversationId,
    actorId,
}: {
    file: Attachment;
    messageId: number;
    conversationId: number;
    actorId: number;
}) {
    const [open, setOpen] = useState(false);
    const trigger = useRef<HTMLButtonElement>(null);
    if (file.media_kind === 'voice_note')
        return (
            <ChatVoiceAttachment
                actorId={actorId}
                key={`${actorId}:${conversationId}:${file.id}`}
                file={file}
                messageId={messageId}
                conversationId={conversationId}
            />
        );
    const Icon = file.mime_type.startsWith('image/') ? ImageIcon : FileText;
    return (
        <>
            <Button
                variant="outline"
                ref={trigger}
                type="button"
                onClick={() => setOpen(true)}
                className="mt-2 flex h-auto min-h-[44px] w-full items-center justify-start gap-2 rounded-lg border bg-background p-3 text-left text-sm whitespace-normal underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-primary"
                aria-label={`Open ${file.name}`}
            >
                <Icon className="size-5 shrink-0" />
                <span className="min-w-0 break-all">
                    {file.name}
                    <span className="block text-xs text-muted-foreground">
                        {Math.ceil(file.size_bytes / 1024)} KB · Open file
                    </span>
                    {file.caption && (
                        <span className="mt-1 block text-sm whitespace-pre-wrap">
                            {file.caption}
                        </span>
                    )}
                </span>
            </Button>
            {open && (
                <ChatAttachmentPreview
                    key={`${actorId}:${conversationId}:${file.id}`}
                    file={file}
                    actorId={actorId}
                    messageId={messageId}
                    conversationId={conversationId}
                    onClose={() => setOpen(false)}
                    onReturnFocus={() => trigger.current?.focus()}
                />
            )}
        </>
    );
}

export function ChatAttachmentPreview(
    props: Parameters<typeof AttachmentPreviewSession>[0],
) {
    return (
        <AttachmentPreviewSession
            key={`${props.actorId}:${props.conversationId}:${props.file.id}`}
            {...props}
        />
    );
}
function AttachmentPreviewSession({
    file,
    actorId,
    messageId,
    conversationId,
    onClose,
    onReturnFocus,
}: {
    file: Attachment;
    actorId: number;
    messageId: number;
    conversationId: number;
    onClose: () => void;
    onReturnFocus: () => void;
}) {
    const memoryKey = `${chatDraftKey(actorId, null, conversationId)}:attachment:${file.id}`;
    const [publishing, setPublishing] = useState(
        () => !!readPublicationMemory(memoryKey).pending,
    );
    const transferringFocus = useRef(false);
    const [metadata, setMetadata] = useState<Metadata | null>(null);
    const [error, setError] = useState('');
    const [checking, setChecking] = useState(true);
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        let alive = true;
        setChecking(true);
        const offline = () => {
            setMetadata(null);
            setChecking(false);
            setError(
                'The attachment could not be checked. Check your connection and retry.',
            );
        };
        window.addEventListener('offline', offline);
        if (!navigator.onLine) offline();
        const stop = watchChatUpdates(
            async () => {
                try {
                    const next = await chatActorRequest<Metadata>(
                        actorId,
                        `/chat/api/attachments/${file.id}/metadata`,
                        {
                            signal: AbortSignal.any([
                                controller.signal,
                                AbortSignal.timeout(15000),
                            ]),
                        },
                    );
                    if (!alive || !navigator.onLine) return false;
                    if (
                        next.id !== file.id ||
                        next.message_id !== messageId ||
                        next.conversation_id !== conversationId
                    )
                        throw new ChatError(
                            'This attachment no longer belongs to this message.',
                            403,
                        );
                    setMetadata(next);
                    setError('');
                    return true;
                } catch (failure) {
                    if (!alive) return false;
                    setMetadata(null); // Unmount decoded bytes when current access cannot be checked.
                    setError(
                        failure instanceof ChatError &&
                            [401, 403, 404, 410, 419].includes(failure.status)
                            ? 'This attachment is no longer available to you. Close the viewer or check access again.'
                            : 'The attachment could not be checked. Check your connection and retry.',
                    );
                    return false;
                } finally {
                    if (alive) setChecking(false);
                }
            },
            {
                interval: () => 5000,
                whenHidden: true,
                events: ['oblivion:chat-refresh', 'focus'],
            },
        );
        return () => {
            alive = false;
            controller.abort();
            stop();
            window.removeEventListener('offline', offline);
        };
    }, [actorId, file.id, messageId, conversationId, attempt]);
    const preview: PreviewFile = metadata
        ? {
              id: metadata.id,
              name: metadata.name,
              mime: metadata.mime_type,
              bytes: metadata.size_bytes,
              durationMs: metadata.duration_ms,
              source: `Shared by ${metadata.sender_name} · ${formatDateTime(metadata.created_at)} · ${metadata.audience_label}`,
              previewUrl: metadata.preview_url
                  ? chatFileUrl(
                        actorId,
                        `/chat/api/attachments/${file.id}/preview`,
                    )
                  : null,
              downloadUrl: chatFileUrl(
                  actorId,
                  `/chat/api/attachments/${file.id}`,
              ),
              unavailableReason: metadata.preview_url
                  ? null
                  : 'This file format cannot be previewed here. Download the original to open it in a compatible application.',
          }
        : {
              id: file.id,
              name: error ? 'Attachment unavailable' : 'Opening attachment',
              previewUrl: null,
              downloadUrl: null,
              source: 'Available only with current conversation access',
              unavailableReason: checking
                  ? 'Checking attachment access…'
                  : error,
          };
    if (publishing)
        return (
            <ChatAttachmentPublication
                actorId={actorId}
                memoryKey={memoryKey}
                attachmentId={file.id}
                onClose={onClose}
                onReturnFocus={onReturnFocus}
            />
        );
    return (
        <FilePreviewDialog
            compact
            file={preview}
            onClose={onClose}
            onReturnFocus={() => {
                if (!transferringFocus.current) onReturnFocus();
            }}
            headerActions={
                metadata ? (
                    <>
                        {metadata.caption && (
                            <details className="mt-2 text-sm">
                                <summary className="flex min-h-[44px] cursor-pointer items-center font-medium">
                                    Caption
                                </summary>
                                <p className="max-h-24 overflow-y-auto [overflow-wrap:anywhere] whitespace-pre-wrap">
                                    {metadata.caption}
                                </p>
                            </details>
                        )}
                        {metadata.can_publish && (
                            <Button
                                variant="outline"
                                className="mt-3 h-auto whitespace-normal"
                                onClick={() => {
                                    transferringFocus.current = true;
                                    setPublishing(true);
                                }}
                            >
                                Add to client documents or photos
                            </Button>
                        )}
                    </>
                ) : undefined
            }
            actions={
                error ? (
                    <Button
                        variant="outline"
                        className="whitespace-normal"
                        disabled={checking}
                        onClick={() => setAttempt((value) => value + 1)}
                    >
                        {checking ? 'Checking…' : 'Check access again'}
                    </Button>
                ) : undefined
            }
        />
    );
}
