import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useEffect, useRef, useState } from 'react';
import {
    ChatError,
    readChatThreadMemory,
    saveChatThreadMemory,
    type ChatRemovalDraft,
} from './api';
import { chatActorRequest } from './chat-actor-request';
import type { ChatHistory } from './types';

export function ChatMessageRemoval({
    actorId,
    memoryKey,
    conversationId,
    initial,
    canSend,
    onClose,
    onRemoved,
    onReturnFocus,
}: {
    actorId: number;
    memoryKey: string;
    conversationId: number;
    initial: ChatRemovalDraft;
    canSend: boolean;
    onClose: () => void;
    onRemoved: () => void;
    onReturnFocus: () => void;
}) {
    const [draft, setDraft] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(
        initial.pendingBody
            ? 'The previous removal has not been confirmed. Retry checks the same request.'
            : '',
    );
    const [conflict, setConflict] = useState(false);
    const [denied, setDenied] = useState(false);
    const alive = useRef(true);
    const inFlight = useRef(false);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const remember = (next: ChatRemovalDraft) => {
        setDraft(next);
        saveChatThreadMemory(memoryKey, { removing: next });
    };
    const close = () => {
        if (inFlight.current || draft.pendingBody) return;
        saveChatThreadMemory(memoryKey, { removing: null });
        onClose();
    };
    async function remove() {
        if (
            readChatThreadMemory(memoryKey).removing?.messageId !==
            draft.messageId
        )
            return;
        if (
            inFlight.current ||
            conflict ||
            denied ||
            (!canSend && !draft.pendingBody)
        )
            return;
        const body =
            draft.pendingBody ??
            JSON.stringify({
                action: 'delete',
                mutation_id: crypto.randomUUID(),
                version: draft.version,
            });
        remember({ ...draft, pendingBody: body });
        inFlight.current = true;
        setBusy(true);
        setError('');
        try {
            await chatActorRequest(
                actorId,
                `/chat/api/messages/${draft.messageId}/action`,
                {
                    method: 'POST',
                    body,
                    signal: AbortSignal.timeout(15000),
                },
            );
            if (readChatThreadMemory(memoryKey).removing?.pendingBody !== body)
                return;
            saveChatThreadMemory(memoryKey, { removing: null });
            if (alive.current) onRemoved();
        } catch (failure) {
            if (
                !alive.current ||
                readChatThreadMemory(memoryKey).removing?.pendingBody !== body
            )
                return;
            setError((failure as Error).message);
            if (
                failure instanceof ChatError &&
                failure.code === 'chat_remove_conflict'
            ) {
                remember({ ...draft, pendingBody: null });
                setConflict(true);
            } else if (
                failure instanceof ChatError &&
                [401, 403, 404, 409, 422].includes(failure.status)
            ) {
                remember({ ...draft, content: '', pendingBody: null });
                setDenied(true);
            }
        } finally {
            inFlight.current = false;
            if (alive.current) setBusy(false);
        }
    }
    async function reviewLatest() {
        if (
            readChatThreadMemory(memoryKey).removing?.messageId !==
            draft.messageId
        )
            return;
        if (inFlight.current) return;
        inFlight.current = true;
        setBusy(true);
        setError('');
        try {
            const result = await chatActorRequest<ChatHistory>(
                actorId,
                `/chat/api/conversations/${conversationId}/messages?around=${draft.messageId}`,
                { signal: AbortSignal.timeout(15000) },
            );
            if (
                !alive.current ||
                readChatThreadMemory(memoryKey).removing?.messageId !==
                    draft.messageId
            )
                return;
            const latest = result.messages.find(
                (message) => message.id === draft.messageId,
            );
            if (!latest || latest.deleted || !latest.can_remove) {
                remember({ ...draft, content: '', pendingBody: null });
                setDenied(true);
                setError(
                    latest?.deleted
                        ? 'This message has already been removed.'
                        : latest?.change_unavailable_reason ||
                              'This message can no longer be removed.',
                );
                return;
            }
            remember({
                messageId: latest.id,
                version: latest.edit_version ?? 0,
                content: latest.content,
            });
            setConflict(false);
        } catch (failure) {
            if (
                !alive.current ||
                readChatThreadMemory(memoryKey).removing?.messageId !==
                    draft.messageId
            )
                return;
            setError((failure as Error).message);
            if (
                failure instanceof ChatError &&
                [401, 403, 404].includes(failure.status)
            ) {
                remember({ ...draft, content: '', pendingBody: null });
                setDenied(true);
            }
        } finally {
            inFlight.current = false;
            if (alive.current) setBusy(false);
        }
    }
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) close();
            }}
        >
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 480px)',
                    width: 'min(92vw, 480px)',
                }}
                showCloseButton={!busy && !draft.pendingBody}
                className="flex max-h-[85dvh] flex-col overflow-hidden"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    onReturnFocus();
                }}
            >
                <DialogHeader>
                    <DialogTitle>Remove your message?</DialogTitle>
                    <DialogDescription>
                        A removed-message marker will remain in the
                        conversation.
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 space-y-3 overflow-y-auto text-sm">
                    {!denied && (
                        <p className="rounded-xl bg-muted/40 p-3 break-words whitespace-pre-wrap">
                            {draft.content || 'Message with attachments'}
                        </p>
                    )}
                    {error && (
                        <p role="alert" className="text-status-critical">
                            {error}
                        </p>
                    )}
                    {draft.pendingBody && !busy && (
                        <p className="text-muted-foreground">
                            The result is uncertain. Retry checks the original
                            request before you close this review.
                        </p>
                    )}
                    {conflict && !denied && (
                        <Button
                            variant="outline"
                            className="min-h-[44px] w-full whitespace-normal"
                            disabled={busy}
                            onClick={() => void reviewLatest()}
                        >
                            Review latest message
                        </Button>
                    )}
                    {!canSend && !draft.pendingBody && !denied && (
                        <p className="text-muted-foreground">
                            This conversation is currently read-only.
                        </p>
                    )}
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        className="min-h-[44px]"
                        disabled={busy || !!draft.pendingBody}
                        onClick={close}
                    >
                        {denied ? 'Close review' : 'Keep message'}
                    </Button>
                    {!denied && (
                        <Button
                            variant="destructive"
                            className="min-h-[44px]"
                            disabled={
                                busy ||
                                conflict ||
                                (!canSend && !draft.pendingBody)
                            }
                            onClick={() => void remove()}
                        >
                            {busy
                                ? 'Checking…'
                                : draft.pendingBody
                                  ? 'Retry removal'
                                  : 'Remove message'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
