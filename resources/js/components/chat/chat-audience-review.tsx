import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ShieldCheck, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ChatError, conversationPath } from './api';
import { chatActorRequest } from './chat-actor-request';
import type { ChatConversation, ChatHistory } from './types';

export function ChatAudienceReview({
    actorId,
    conversation,
    onClose,
    onReviewed,
    onReturnFocus,
}: {
    actorId: number;
    conversation: ChatConversation;
    onClose: () => void;
    onReviewed: (conversation: ChatConversation) => void;
    onReturnFocus: () => void;
}) {
    // Keep the displayed audience fixed until the sender explicitly refreshes it.
    const [snapshot, setSnapshot] = useState(conversation);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [stale, setStale] = useState(false);
    const [denied, setDenied] = useState(false);
    const live = useRef(true);
    useEffect(() => {
        live.current = true;
        return () => {
            live.current = false;
        };
    }, []);
    async function review(refresh = false) {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const result = refresh
                ? await chatActorRequest<ChatHistory>(
                      actorId,
                      `${conversationPath(snapshot.id)}/messages`,
                  )
                : await chatActorRequest<{ conversation: ChatConversation }>(
                      actorId,
                      `${conversationPath(snapshot.id)}/audience-review`,
                      {
                          method: 'POST',
                          body: JSON.stringify({
                              audience_token: snapshot.audience_review_token,
                              understood: true,
                          }),
                      },
                  );
            if (!live.current) return;
            if (refresh) {
                setSnapshot(result.conversation);
                setStale(false);
            } else {
                onReviewed(result.conversation);
                onClose();
            }
        } catch (failure) {
            if (!live.current) return;
            setError((failure as Error).message);
            if (failure instanceof ChatError) {
                if (failure.status === 409) setStale(true);
                if ([401, 403, 404].includes(failure.status)) setDenied(true);
            }
        } finally {
            if (live.current) setBusy(false);
        }
    }
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 480px)',
                    width: 'min(92vw, 480px)',
                }}
                className="flex max-h-[85dvh] flex-col gap-0 overflow-hidden p-0"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    onReturnFocus();
                }}
            >
                <DialogHeader className="shrink-0 border-b p-5 pr-12">
                    <DialogTitle>
                        {stale
                            ? 'Review the updated audience'
                            : 'Before your first message'}
                    </DialogTitle>
                    <DialogDescription>
                        Confirm who can see this conversation. Your message
                        stays in the composer.
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 space-y-4 overflow-y-auto p-5">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            About {snapshot.client?.name}
                        </p>
                        <h3 className="mt-1 font-semibold">{snapshot.title}</h3>
                    </div>
                    {!denied && (
                        <ul
                            aria-label="Conversation members"
                            className="divide-y rounded-xl border"
                        >
                            {snapshot.participants.map((person) => (
                                <li
                                    key={person.id}
                                    className="flex items-center gap-3 p-3"
                                >
                                    <span
                                        aria-hidden
                                        className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-medium text-primary"
                                    >
                                        {person.name
                                            .split(/\s+/)
                                            .map((word) => word[0])
                                            .slice(0, 2)
                                            .join('')}
                                    </span>
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium break-words">
                                            {person.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {person.id === snapshot.assigned_to
                                                ? 'Named worker'
                                                : person.role === 'family'
                                                  ? 'Family member'
                                                  : 'Conversation member'}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                    <div className="flex gap-3 rounded-xl bg-muted p-3 text-sm leading-relaxed">
                        <ShieldCheck
                            aria-hidden
                            className="mt-0.5 size-4 shrink-0"
                        />
                        <p>
                            {snapshot.care_team_cover
                                ? 'Authorised care-team cover can also read and respond, including messages to a named worker.'
                                : 'The people listed here can read and respond.'}
                        </p>
                    </div>
                    <p className="flex gap-3 text-sm text-muted-foreground">
                        <Users aria-hidden className="mt-0.5 size-4 shrink-0" />
                        Other relatives are not included automatically. Approved
                        relatives must be invited explicitly.
                    </p>
                    {error && (
                        <p
                            role="alert"
                            className="rounded-lg border p-3 text-sm"
                        >
                            {error} Your message has not been sent.
                        </p>
                    )}
                </div>
                <DialogFooter className="shrink-0 border-t p-4">
                    <Button
                        className="min-h-11 whitespace-normal"
                        variant="outline"
                        onClick={onClose}
                    >
                        Back
                    </Button>
                    <Button
                        className="min-h-11 whitespace-normal"
                        disabled={
                            busy || denied || !snapshot.audience_review_token
                        }
                        onClick={() => void review(stale)}
                    >
                        {busy
                            ? 'Checking…'
                            : stale
                              ? 'Review updated people'
                              : 'Understood · Return to message'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
