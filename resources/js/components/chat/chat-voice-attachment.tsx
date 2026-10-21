import { AudioPreview, audioTime } from '@/components/files/audio-preview';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Play } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ChatError } from './api';
import { chatActorRequest } from './chat-actor-request';
import { chatFileUrl } from './chat-file-url';
import { watchChatUpdates } from './chat-update-watch';
import type { ChatMessage } from './types';

type VoiceMetadata = {
    id: number;
    message_id: number;
    conversation_id: number;
    media_kind: string;
    mime_type: string;
    size_bytes: number;
    duration_ms: number;
};
export function ChatVoiceAttachment(
    props: Parameters<typeof VoiceAttachmentSession>[0],
) {
    return (
        <VoiceAttachmentSession
            key={`${props.actorId}:${props.conversationId}:${props.file.id}`}
            {...props}
        />
    );
}
function VoiceAttachmentSession({
    actorId,
    file,
    messageId,
    conversationId,
}: {
    actorId: number;
    file: ChatMessage['attachments'][number];
    messageId: number;
    conversationId: number;
}) {
    const [requested, setRequested] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const [url, setUrl] = useState<string | null>(null);
    const [checked, setChecked] = useState(false);
    const [error, setError] = useState('');
    useEffect(() => {
        if (!requested) return;
        const controller = new AbortController();
        let active = true;
        let objectUrl: string | null = null;
        const clear = () => {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = null;
            setUrl(null);
            setChecked(false);
        };
        const offline = () => {
            clear();
            setError(
                'The voice message could not be checked. Check your connection and retry.',
            );
        };
        window.addEventListener('offline', offline);
        if (!navigator.onLine) offline();
        const stop = watchChatUpdates(
            async () => {
                try {
                    const metadata = await chatActorRequest<VoiceMetadata>(
                        actorId,
                        `/chat/api/attachments/${file.id}/metadata`,
                        {
                            signal: AbortSignal.any([
                                controller.signal,
                                AbortSignal.timeout(15000),
                            ]),
                        },
                    );
                    if (!active || !navigator.onLine) return false;
                    if (
                        metadata.id !== file.id ||
                        metadata.message_id !== messageId ||
                        metadata.conversation_id !== conversationId ||
                        metadata.media_kind !== 'voice_note' ||
                        metadata.mime_type !== file.mime_type ||
                        metadata.size_bytes !== file.size_bytes ||
                        !['audio/webm', 'audio/ogg', 'audio/mp4'].includes(
                            metadata.mime_type,
                        ) ||
                        !Number.isFinite(metadata.duration_ms) ||
                        metadata.duration_ms < 50 ||
                        metadata.duration_ms > 120500
                    )
                        throw new ChatError(
                            'This voice message no longer matches the conversation.',
                            403,
                        );
                    if (!objectUrl) {
                        const response = await fetch(
                            `/chat/api/attachments/${file.id}/preview`,
                            {
                                credentials: 'same-origin',
                                headers: { 'X-Chat-Actor': String(actorId) },
                                signal: AbortSignal.any([
                                    controller.signal,
                                    AbortSignal.timeout(30000),
                                ]),
                                cache: 'no-store',
                            },
                        );
                        if (
                            !response.ok ||
                            response.headers
                                .get('content-type')
                                ?.split(';')[0] !== metadata.mime_type ||
                            metadata.size_bytes > 20 * 1024 * 1024
                        )
                            throw new Error(
                                'This voice message could not be loaded. Retry or check access.',
                            );
                        const bytes = await response.arrayBuffer();
                        if (!active || !navigator.onLine) return false;
                        if (bytes.byteLength !== metadata.size_bytes)
                            throw new Error(
                                'This voice message is incomplete. Retry loading it.',
                            );
                        objectUrl = URL.createObjectURL(
                            new Blob([bytes], { type: metadata.mime_type }),
                        );
                        setUrl(objectUrl);
                    }
                    setChecked(true);
                    setError('');
                    return true;
                } catch (failure) {
                    if (!active) return false;
                    clear();
                    setError(
                        failure instanceof ChatError &&
                            [401, 403, 404, 410, 419].includes(failure.status)
                            ? 'This voice message is no longer available to you.'
                            : 'The voice message could not be checked. Check your connection and retry.',
                    );
                    return false;
                }
            },
            { interval: () => 5000, whenHidden: true },
        );
        return () => {
            active = false;
            controller.abort();
            stop();
            window.removeEventListener('offline', offline);
            if (objectUrl) URL.revokeObjectURL(objectUrl);
        };
    }, [
        actorId,
        requested,
        attempt,
        file.id,
        file.mime_type,
        file.size_bytes,
        messageId,
        conversationId,
    ]);
    return (
        <Card
            unstyled
            className="mt-2 min-w-0 space-y-2 text-sm"
            aria-label="Voice message"
        >
            {!requested && (
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-[44px] w-full justify-start whitespace-normal"
                    onClick={() => setRequested(true)}
                    aria-label={`Play ${file.name}`}
                >
                    <Play className="size-4" />
                    Voice message · {audioTime((file.duration_ms ?? 0) / 1000)}
                </Button>
            )}
            {requested && !url && !error && (
                <p role="status">Checking voice message…</p>
            )}
            {url && (
                <AudioPreview
                    key={url}
                    url={url}
                    duration={(file.duration_ms ?? 0) / 1000}
                    label={file.name}
                    autoPlay
                />
            )}
            {error && (
                <div className="space-y-2">
                    <p role="alert">{error}</p>
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-[44px]"
                        onClick={() => {
                            setError('');
                            setAttempt((value) => value + 1);
                        }}
                    >
                        Retry voice message
                    </Button>
                </div>
            )}
            {checked && (
                <a
                    className="inline-flex min-h-[44px] items-center text-xs underline"
                    href={chatFileUrl(
                        actorId,
                        `/chat/api/attachments/${file.id}`,
                    )}
                >
                    Download voice message
                </a>
            )}
            {file.caption && (
                <p className="[overflow-wrap:anywhere] whitespace-pre-wrap">
                    {file.caption}
                </p>
            )}
        </Card>
    );
}
