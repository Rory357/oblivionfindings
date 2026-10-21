import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTime } from '@/lib/datetime';
import { Mail } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { ChatError, chatRequest, isChatAccessDenied } from './api';

type Invitation = {
    id: string;
    conversation_id: number;
    title: string;
    from: string;
    version: number;
    history_policy: string;
    shared_count: number;
    members: string[];
    expires_at?: string;
};
// A relative's agreement is needed before another person joins a private
// family conversation they are already in.
type ConsentRequest = {
    id: string;
    conversation_id: number;
    title: string;
    person: string;
    from: string;
    history_policy: string;
    shared_count: number;
    expires_at?: string;
};
type Response =
    | { kind: 'invitation'; item: Invitation; action: 'accept' | 'decline' }
    | { kind: 'consent'; item: ConsentRequest; action: 'consent' | 'refuse' };
function sharedHistory(count: number) {
    return count
        ? `, plus ${count} explicitly shared earlier ${count === 1 ? 'message and its' : 'messages and their'} attachments.`
        : '. Earlier messages remain private.';
}
function ChatInvitationsSession({
    actorId,
    clientId,
    clientName,
    onAccepted,
}: {
    actorId: number;
    clientId: number;
    clientName: string;
    onAccepted: (conversation: number) => void;
}) {
    const headingId = useId();
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState<Invitation[]>([]);
    const [consents, setConsents] = useState<ConsentRequest[]>([]);
    const [consentsLoaded, setConsentsLoaded] = useState(false);
    const [next, setNext] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [pendingResponse, setPendingResponse] = useState<Response | null>(
        null,
    );
    const controller = useRef<AbortController | null>(null);
    const active = useRef(true);
    useEffect(() => {
        active.current = true;
        return () => {
            active.current = false;
            controller.current?.abort();
        };
    }, []);
    async function load(older = false) {
        controller.current?.abort();
        const request = new AbortController();
        controller.current = request;
        setLoading(true);
        setError('');
        if (!older) {
            setItems([]);
            setConsents([]);
            setNext(null);
        }
        try {
            const result = await chatRequest<{
                invitations: Invitation[];
                consent_requests?: ConsentRequest[];
                next_before: string | null;
            }>(
                `/chat/api/invitations?client=${clientId}${older && next ? `&before=${next}` : ''}`,
                {
                    signal: request.signal,
                    headers: { 'X-Chat-Actor': String(actorId) },
                },
            );
            if (request.signal.aborted || !active.current) return;
            setItems((current) =>
                older
                    ? [...current, ...result.invitations]
                    : result.invitations,
            );
            if (!older) {
                setConsents(result.consent_requests ?? []);
                setConsentsLoaded(true);
            }
            setNext(result.next_before);
        } catch (failure) {
            if (!request.signal.aborted && active.current) {
                if (isChatAccessDenied(failure)) {
                    setItems([]);
                    setConsents([]);
                    setNext(null);
                    setPendingResponse(null);
                }
                setError((failure as Error).message);
            }
        } finally {
            if (!request.signal.aborted && active.current) setLoading(false);
        }
    }
    async function respond(response: Response) {
        const { item, action } = response;
        setBusy(true);
        setError('');
        try {
            await chatRequest(`/chat/api/invitations/${item.id}`, {
                method: 'POST',
                headers: { 'X-Chat-Actor': String(actorId) },
                body: JSON.stringify(
                    response.kind === 'invitation'
                        ? { action, version: response.item.version }
                        : { action },
                ),
            });
            if (!active.current) return;
            setPendingResponse(null);
            if (response.kind === 'consent') {
                setConsents((current) =>
                    current.filter((entry) => entry.id !== item.id),
                );
                return;
            }
            setItems((current) =>
                current.filter((entry) => entry.id !== item.id),
            );
            if (action === 'accept') {
                setOpen(false);
                onAccepted(item.conversation_id);
            }
        } catch (failure) {
            if (active.current) {
                if (isChatAccessDenied(failure)) {
                    setItems([]);
                    setConsents([]);
                    setNext(null);
                }
                // Already decided elsewhere: it no longer waits on this person.
                if (
                    response.kind === 'consent' &&
                    failure instanceof ChatError &&
                    failure.status === 409
                ) {
                    setConsents((current) =>
                        current.filter((entry) => entry.id !== item.id),
                    );
                }
                const definitive =
                    failure instanceof ChatError &&
                    failure.status >= 400 &&
                    failure.status < 500;
                setPendingResponse(definitive ? null : response);
                setError((failure as Error).message);
            }
        } finally {
            if (active.current) setBusy(false);
        }
    }
    return (
        <>
            <Button
                variant="outline"
                className="min-h-11 justify-start"
                onClick={() => {
                    setOpen(true);
                    if (!pendingResponse) void load();
                }}
            >
                <Mail className="size-4" />
                Invitations
                {consentsLoaded && consents.length > 0 && (
                    <span
                        className="ml-auto rounded-full bg-primary-fill px-2 text-xs font-semibold text-primary-fill-foreground"
                        aria-label={`${consents.length} waiting for your agreement`}
                    >
                        {consents.length}
                    </span>
                )}
            </Button>
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!busy) {
                        setOpen(value);
                        if (!value) controller.current?.abort();
                    }
                }}
            >
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    className="flex max-h-[85dvh] flex-col overflow-hidden p-0"
                >
                    <DialogHeader className="shrink-0 px-6 pt-6 pr-12">
                        <DialogTitle>Conversation invitations</DialogTitle>
                        <DialogDescription>
                            About {clientName}. Review who is included and what
                            history will be shared before joining.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="min-h-0 space-y-4 overflow-x-hidden overflow-y-auto px-6 pb-6">
                        {error && (
                            <div
                                role="alert"
                                className="space-y-2 text-sm text-status-critical"
                            >
                                <p>{error}</p>
                                {pendingResponse && (
                                    <p>
                                        Your response is kept. Retry to confirm
                                        whether it was saved.
                                    </p>
                                )}
                                <Button
                                    variant="outline"
                                    className="min-h-11"
                                    disabled={busy || loading}
                                    onClick={() =>
                                        void (pendingResponse
                                            ? respond(pendingResponse)
                                            : load())
                                    }
                                >
                                    {pendingResponse
                                        ? 'Retry response'
                                        : 'Refresh invitations'}
                                </Button>
                            </div>
                        )}
                        {loading && (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                Loading invitations…
                            </p>
                        )}
                        {consents.length > 0 && (
                            <section
                                aria-labelledby={`${headingId}-consent`}
                                className="space-y-3"
                            >
                                <h3
                                    id={`${headingId}-consent`}
                                    className="text-section-title"
                                >
                                    Waiting for your agreement
                                </h3>
                                {consents.map((request) => (
                                    <article
                                        key={request.id}
                                        className="space-y-3 rounded-xl border bg-muted p-4"
                                    >
                                        <p className="text-sm break-words">
                                            {request.from} would like to add{' '}
                                            {request.person} to “{request.title}
                                            ”.
                                        </p>
                                        {request.expires_at && (
                                            <p className="text-xs text-muted-foreground">
                                                Expires{' '}
                                                {formatDateTime(
                                                    request.expires_at,
                                                )}
                                            </p>
                                        )}
                                        <p className="text-sm">
                                            {request.person} would see new
                                            messages from when they join
                                            {sharedHistory(
                                                request.shared_count,
                                            )}
                                        </p>
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                variant="outline"
                                                className="min-h-11"
                                                disabled={
                                                    busy || loading || !!error
                                                }
                                                onClick={() =>
                                                    void respond({
                                                        kind: 'consent',
                                                        item: request,
                                                        action: 'refuse',
                                                    })
                                                }
                                            >
                                                Don&apos;t agree
                                            </Button>
                                            <Button
                                                className="min-h-11"
                                                disabled={
                                                    busy || loading || !!error
                                                }
                                                onClick={() =>
                                                    void respond({
                                                        kind: 'consent',
                                                        item: request,
                                                        action: 'consent',
                                                    })
                                                }
                                            >
                                                Agree
                                            </Button>
                                        </div>
                                    </article>
                                ))}
                            </section>
                        )}
                        {!loading &&
                            !error &&
                            !items.length &&
                            !consents.length && (
                                <p className="text-sm text-muted-foreground">
                                    No pending invitations for this client.
                                </p>
                            )}
                        {items.map((item) => (
                            <article
                                key={item.id}
                                className="space-y-3 rounded-xl border p-4"
                            >
                                <h3 className="font-medium break-words">
                                    {item.title}
                                </h3>
                                <p className="text-sm">
                                    Invited by {item.from}
                                </p>
                                {item.expires_at && (
                                    <p className="text-xs text-muted-foreground">
                                        Expires{' '}
                                        {formatDateTime(item.expires_at)}
                                    </p>
                                )}
                                <p className="text-sm text-muted-foreground">
                                    Members: {item.members.join(', ')}
                                </p>
                                <p className="text-sm">
                                    New messages from when you join
                                    {sharedHistory(item.shared_count)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Authorised care-team cover can also read and
                                    respond under their own name.
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        className="min-h-11"
                                        disabled={busy || loading || !!error}
                                        onClick={() =>
                                            void respond({
                                                kind: 'invitation',
                                                item,
                                                action: 'decline',
                                            })
                                        }
                                    >
                                        Decline
                                    </Button>
                                    <Button
                                        className="min-h-11"
                                        disabled={busy || loading || !!error}
                                        onClick={() =>
                                            void respond({
                                                kind: 'invitation',
                                                item,
                                                action: 'accept',
                                            })
                                        }
                                    >
                                        Accept invitation
                                    </Button>
                                </div>
                            </article>
                        ))}
                        {next && (
                            <Button
                                variant="outline"
                                className="min-h-11"
                                disabled={loading || busy || !!error}
                                onClick={() => void load(true)}
                            >
                                Load more invitations
                            </Button>
                        )}
                        {busy && (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                Saving your response…
                            </p>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

export function ChatInvitations(
    props: Parameters<typeof ChatInvitationsSession>[0],
) {
    return (
        <ChatInvitationsSession
            key={`${props.actorId}:${props.clientId}`}
            {...props}
        />
    );
}
