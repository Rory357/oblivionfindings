import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { useEffect, useRef, useState } from 'react';
import { chatRequest, conversationPath, isChatAccessDenied } from './api';
import type { ChatConversation } from './types';

type Invitation = {
    id: string;
    user_id: number | null;
    name: string;
    status: string;
    shared_count: number;
    created_at: string;
    expires_at: string;
    can_cancel: boolean;
    can_remove: boolean;
    can_reinvite: boolean;
};
type Page = {
    invitations: Invitation[];
    version: number;
    next_before: string | null;
};
const labels: Record<string, string> = {
    pending: 'Pending',
    accepted: 'Accepted',
    declined: 'Declined',
    cancelled: 'Cancelled',
    expired: 'Expired',
    removed: 'Access removed',
    awaiting_family: 'Waiting for family agreement',
    refused: "Family didn't agree",
};
const tones: Record<string, StatusVariant> = {
    pending: 'warning',
    awaiting_family: 'warning',
    accepted: 'success',
};

function ChatInvitationHistorySession({
    actorId,
    conversation,
    onChanged,
    onReinvite,
}: {
    actorId: number;
    conversation: ChatConversation;
    onChanged: () => void;
    onReinvite: (personId: number) => void;
}) {
    const [open, setOpen] = useState(false);
    const [page, setPage] = useState<Page | null>(null);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [remove, setRemove] = useState<Invitation | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    const active = useRef(true);
    const request = useRef<AbortController | null>(null);
    useEffect(() => {
        active.current = true;
        return () => {
            active.current = false;
            request.current?.abort();
        };
    }, []);
    if (conversation.type !== 'family' || !conversation.can_manage_people)
        return null;

    async function load(older = false) {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        setLoading(true);
        setError('');
        setRemove(null);
        setConfirmed(false);
        if (!older) setPage(null);
        try {
            const result = await chatRequest<Page>(
                `${conversationPath(conversation.id)}/invitations${older && page?.next_before ? `?before=${page.next_before}` : ''}`,
                {
                    signal: controller.signal,
                    headers: { 'X-Chat-Actor': String(actorId) },
                },
            );
            if (controller.signal.aborted || !active.current) return;
            if (older && result.version !== page?.version) {
                setError(
                    'The conversation changed. Refresh invitations before continuing.',
                );
                return;
            }
            setPage((current) =>
                older && current
                    ? {
                          ...result,
                          invitations: [
                              ...current.invitations,
                              ...result.invitations,
                          ],
                      }
                    : result,
            );
        } catch (failure) {
            if (!controller.signal.aborted && active.current) {
                if (isChatAccessDenied(failure)) setPage(null);
                setError((failure as Error).message);
            }
        } finally {
            if (!controller.signal.aborted && active.current) setLoading(false);
        }
    }
    async function change(item: Invitation, action: 'cancel' | 'remove') {
        if (busy || !page) return;
        setBusy(true);
        setError('');
        try {
            await chatRequest(
                action === 'cancel'
                    ? `/chat/api/invitations/${item.id}`
                    : `${conversationPath(conversation.id)}/management`,
                {
                    method: action === 'cancel' ? 'POST' : 'PATCH',
                    headers: { 'X-Chat-Actor': String(actorId) },
                    body: JSON.stringify(
                        action === 'cancel'
                            ? { action }
                            : {
                                  action,
                                  user_id: item.user_id,
                                  version: page.version,
                              },
                    ),
                },
            );
            if (!active.current) return;
            onChanged();
            await load();
        } catch (failure) {
            if (active.current) {
                if (isChatAccessDenied(failure)) {
                    setPage(null);
                    setRemove(null);
                    setConfirmed(false);
                }
                setError(
                    `${(failure as Error).message} Refresh to check the current result before taking another action.`,
                );
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
                    void load();
                }}
            >
                Family invitations
            </Button>
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!busy) {
                        setOpen(value);
                        if (!value) request.current?.abort();
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
                        <DialogTitle>Family invitations</DialogTitle>
                        <DialogDescription>
                            About {conversation.client?.name}. Separate
                            conversations stay separate until an approved
                            invitation is accepted.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="min-h-0 space-y-4 overflow-x-hidden overflow-y-auto px-6 pb-6">
                        {error && (
                            <div
                                role="alert"
                                className="space-y-2 text-sm text-status-critical"
                            >
                                <p>{error}</p>
                                <Button
                                    variant="outline"
                                    className="min-h-11"
                                    disabled={busy || loading}
                                    onClick={() => void load()}
                                >
                                    Refresh invitations
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
                        {!loading &&
                            !error &&
                            page?.invitations.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No invitations have been sent.
                                </p>
                            )}
                        {page?.invitations.map((item) => (
                            <article
                                key={item.id}
                                className="space-y-3 rounded-xl border p-4"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h3 className="font-medium break-words">
                                        {item.name}
                                    </h3>
                                    <StatusBadge
                                        variant={
                                            tones[item.status] ?? 'neutral'
                                        }
                                    >
                                        {labels[item.status] ?? 'Unavailable'}
                                    </StatusBadge>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Sent {formatDateTime(item.created_at)}
                                    {item.status === 'pending' ||
                                    item.status === 'awaiting_family'
                                        ? ` · Expires ${formatDateTime(item.expires_at)}`
                                        : ''}
                                </p>
                                <p className="text-sm">
                                    {item.shared_count
                                        ? `From joining, plus ${item.shared_count} reviewed earlier ${item.shared_count === 1 ? 'message' : 'messages'}.`
                                        : 'From joining onward. Earlier messages stay private.'}
                                </p>
                                {item.status === 'awaiting_family' && (
                                    <p className="text-xs text-muted-foreground">
                                        A relative already in this conversation
                                        must agree before they can join.
                                    </p>
                                )}
                                {item.status === 'removed' && (
                                    <p className="text-xs text-muted-foreground">
                                        Conversation access has ended. Existing
                                        messages are retained.
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    {item.can_cancel && (
                                        <Button
                                            variant="outline"
                                            className="min-h-11"
                                            disabled={
                                                busy || loading || !!error
                                            }
                                            onClick={() =>
                                                void change(item, 'cancel')
                                            }
                                        >
                                            Cancel invitation
                                        </Button>
                                    )}
                                    {item.can_remove && (
                                        <Button
                                            variant="outline"
                                            className="min-h-11"
                                            disabled={
                                                busy || loading || !!error
                                            }
                                            onClick={() => {
                                                setRemove(item);
                                                setConfirmed(false);
                                            }}
                                        >
                                            Remove future access
                                        </Button>
                                    )}
                                    {item.can_reinvite &&
                                        item.user_id !== null && (
                                            <Button
                                                variant="outline"
                                                className="min-h-11"
                                                disabled={
                                                    busy || loading || !!error
                                                }
                                                onClick={() => {
                                                    setOpen(false);
                                                    request.current?.abort();
                                                    onReinvite(item.user_id!);
                                                }}
                                            >
                                                Review a new invitation
                                            </Button>
                                        )}
                                </div>
                                {remove?.id === item.id && (
                                    <div className="space-y-3 rounded-lg bg-muted p-3">
                                        <p className="text-sm">
                                            Remove {item.name} from this
                                            conversation? They will lose access
                                            to its messages, files and calls.
                                            Their family portal relationship and
                                            other conversations stay unchanged.
                                        </p>
                                        <label className="flex min-h-11 items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={confirmed}
                                                disabled={busy || !!error}
                                                onCheckedChange={(checked) =>
                                                    setConfirmed(
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            I have reviewed this removal.
                                        </label>
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                variant="outline"
                                                className="min-h-11"
                                                disabled={busy}
                                                onClick={() => setRemove(null)}
                                            >
                                                Keep access
                                            </Button>
                                            <Button
                                                variant="destructive"
                                                className="min-h-11"
                                                disabled={
                                                    !confirmed ||
                                                    busy ||
                                                    loading ||
                                                    !!error
                                                }
                                                onClick={() =>
                                                    void change(item, 'remove')
                                                }
                                            >
                                                Confirm removal
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </article>
                        ))}
                        {page?.next_before && (
                            <Button
                                variant="outline"
                                className="min-h-11 w-full"
                                disabled={busy || loading || !!error}
                                onClick={() => void load(true)}
                            >
                                Load earlier invitations
                            </Button>
                        )}
                        {busy && (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                Saving…
                            </p>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

export function ChatInvitationHistory(
    props: Parameters<typeof ChatInvitationHistorySession>[0],
) {
    return (
        <ChatInvitationHistorySession
            key={`${props.actorId}:${props.conversation.id}`}
            {...props}
        />
    );
}
