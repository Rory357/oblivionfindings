import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Loader2, Pencil, Send, Smile, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    ChatError,
    readChatThreadMemory,
    saveChatThreadMemory,
    type ChatEditDraft,
} from './api';
import { chatActorRequest } from './chat-actor-request';
import { ChatMentionInput } from './chat-mention-input';
import type { ChatHistory, ChatMessage, ChatPerson } from './types';

export function ChatMessageEditor({
    actorId,
    memoryKey,
    conversationId,
    initial,
    people,
    canSend,
    onClose,
    onSaved,
}: {
    actorId: number;
    memoryKey: string;
    conversationId: number;
    initial: ChatEditDraft;
    people: ChatPerson[];
    canSend: boolean;
    onClose: () => void;
    onSaved: (message: ChatMessage) => void;
}) {
    const [draft, setDraft] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(
        initial.pendingBody
            ? 'Your previous edit has not been confirmed. Retry checks the same edit reference.'
            : '',
    );
    const [conflict, setConflict] = useState(false);
    const [latest, setLatest] = useState<ChatMessage | null>(null);
    const [denied, setDenied] = useState(false);
    const alive = useRef(true);
    const form = useRef<HTMLFormElement>(null);
    useEffect(() => {
        alive.current = true;
        form.current?.querySelector('textarea')?.focus();
        return () => {
            alive.current = false;
        };
    }, []);
    const remember = (value: ChatEditDraft) => {
        setDraft(value);
        saveChatThreadMemory(memoryKey, { editing: value });
    };
    const close = () => {
        saveChatThreadMemory(memoryKey, { editing: null });
        onClose();
    };
    async function save() {
        if (busy || conflict || denied || (!canSend && !draft.pendingBody))
            return;
        const body =
            draft.pendingBody ??
            JSON.stringify({
                mutation_id: crypto.randomUUID(),
                version: draft.version,
                content: draft.content,
                mentions: draft.mentions.map(({ user_id, start, length }) => ({
                    user_id,
                    start,
                    length,
                })),
            });
        remember({ ...draft, pendingBody: body });
        setBusy(true);
        setError('');
        try {
            const result = await chatActorRequest<{ message: ChatMessage }>(
                actorId,
                `/chat/api/messages/${draft.messageId}`,
                { method: 'PATCH', body },
            );
            if (readChatThreadMemory(memoryKey).editing?.pendingBody === body)
                saveChatThreadMemory(memoryKey, { editing: null });
            if (alive.current) onSaved(result.message);
        } catch (failure) {
            if (!alive.current) return;
            setError((failure as Error).message);
            if (
                failure instanceof ChatError &&
                failure.code === 'chat_edit_conflict'
            ) {
                remember({ ...draft, pendingBody: null });
                setConflict(true);
            } else if (
                failure instanceof ChatError &&
                [401, 403, 404, 409, 422].includes(failure.status)
            ) {
                remember({ ...draft, pendingBody: null });
                if (failure.status !== 422) setDenied(true);
            }
        } finally {
            if (alive.current) setBusy(false);
        }
    }
    async function reviewLatest() {
        if (busy) return;
        setBusy(true);
        try {
            const result = await chatActorRequest<ChatHistory>(
                actorId,
                `/chat/api/conversations/${conversationId}/messages?around=${draft.messageId}`,
            );
            if (!alive.current) return;
            const message = result.messages.find(
                (m) => m.id === draft.messageId && !m.deleted,
            );
            if (!message?.can_edit) {
                setDenied(true);
                setError(
                    message?.change_unavailable_reason ??
                        'This message can no longer be edited.',
                );
                return;
            }
            setLatest(message);
        } catch (failure) {
            if (alive.current) setError((failure as Error).message);
        } finally {
            if (alive.current) setBusy(false);
        }
    }
    return (
        <form
            ref={form}
            onSubmit={(event) => {
                event.preventDefault();
                void save();
            }}
            className="max-h-[65dvh] min-w-0 space-y-2 overflow-y-auto"
            aria-label="Edit your message"
        >
            <div className="flex items-center gap-2 rounded-lg border-l-2 border-primary bg-muted/40 p-2 text-xs">
                <Pencil className="size-4" />
                <div className="min-w-0 flex-1">
                    <strong>Editing your message</strong>
                    <p>Your separate draft, reply and files are kept.</p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-11"
                    aria-label="Cancel edit"
                    disabled={busy || !!draft.pendingBody}
                    onClick={close}
                >
                    <X className="size-4" />
                </Button>
            </div>
            {error && (
                <p
                    role="alert"
                    className="rounded-lg bg-status-critical-bg p-3 text-sm text-status-critical"
                >
                    {error}
                </p>
            )}
            {draft.pendingBody && !busy && (
                <p className="text-xs text-muted-foreground">
                    Retry checks the original edit. Finish checking before
                    changing or cancelling it.
                </p>
            )}
            {conflict && !denied && (
                <div className="space-y-2 rounded-lg border p-3 text-sm">
                    {latest ? (
                        <>
                            <strong>Latest saved message</strong>
                            <p className="max-h-32 overflow-y-auto break-words whitespace-pre-wrap">
                                {latest.content || 'Attachment without text'}
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                onClick={() => {
                                    remember({
                                        ...draft,
                                        version: latest.edit_version ?? 0,
                                        pendingBody: null,
                                    });
                                    setConflict(false);
                                    setLatest(null);
                                    setError('');
                                }}
                            >
                                Keep my edit for review
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                Review your text below, then choose Save edit.
                                Nothing is saved automatically.
                            </p>
                        </>
                    ) : (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            disabled={busy}
                            onClick={() => void reviewLatest()}
                        >
                            Review latest message
                        </Button>
                    )}
                </div>
            )}
            <Card className="flex flex-col gap-2 rounded-xl border bg-background p-2 shadow-none focus-within:ring-2 focus-within:ring-primary/30">
                <ChatMentionInput
                    value={draft.content}
                    mentions={draft.mentions}
                    people={people}
                    disabled={busy || !!draft.pendingBody || denied || !canSend}
                    onChange={(content, mentions) =>
                        remember({ ...draft, content, mentions })
                    }
                    onSend={() => void save()}
                />
                <div className="flex items-center gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-11"
                        aria-label="Insert emoji"
                        disabled={
                            busy ||
                            !!draft.pendingBody ||
                            denied ||
                            !canSend ||
                            draft.content.length > 9997
                        }
                        onClick={() =>
                            remember({
                                ...draft,
                                content: draft.content + ' 🙂',
                            })
                        }
                    >
                        <Smile className="size-5" />
                    </Button>
                    <Button
                        type="submit"
                        className="ml-auto min-h-11 px-4"
                        disabled={
                            busy ||
                            conflict ||
                            denied ||
                            (!canSend && !draft.pendingBody)
                        }
                    >
                        {busy ? (
                            <Loader2 className="size-4 animate-spin" />
                        ) : (
                            <Send className="size-4" />
                        )}
                        {busy
                            ? 'Saving…'
                            : draft.pendingBody
                              ? 'Retry edit'
                              : 'Save edit'}
                    </Button>
                </div>
            </Card>
            <p className="text-[11px] text-muted-foreground">
                Only message text changes. Earlier text is kept in restricted
                audit history.
            </p>
        </form>
    );
}
