/* Quick-peek drawer (§E) — fast triage without leaving the queue. Fetches
 * the SAME it.tickets.show payload the detail page uses (axios/JSON branch,
 * identical policy + internal-note stripping) and renders the shared
 * TicketThread with a condensed read-only rail. Actions beyond replying
 * live on the full page — one click away. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { TicketRoutingSummary } from '@/components/it/ticket-routing-summary';
import {
    TicketThread,
    type ThreadDraftState,
} from '@/components/it/ticket-thread';
import { waitingStatusLabel } from '@/components/it/ticket-waiting-dialog';
import { TicketWatchers } from '@/components/it/ticket-watchers';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    useItTicketPeek,
    type TicketPeekPayload,
} from '@/hooks/use-it-ticket-peek';
import { useTicketWatcherCommand } from '@/hooks/use-ticket-watcher-command';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

const statusVariant: Record<string, StatusVariant> = {
    open: 'warning',
    in_progress: 'info',
    waiting: 'warning',
    resolved: 'success',
    closed: 'neutral',
};

const priorityVariant: Record<string, StatusVariant> = {
    urgent: 'critical',
    high: 'critical',
    normal: 'info',
    low: 'neutral',
};

const label = (raw: string) =>
    raw.replace(/[_-]/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

export function TicketDrawer({
    ticketId,
    onClose,
}: {
    ticketId: number | null;
    onClose: () => void;
}) {
    const actorId = usePage<SharedData>().props.auth.user?.id;
    const {
        data,
        loading,
        error: readError,
        access,
        scope,
        refresh,
    } = useItTicketPeek(ticketId, actorId);
    const error = access ?? readError;
    const [authorizedThread, setAuthorizedThread] = useState<{
        scope: string;
        data: TicketPeekPayload;
    } | null>(null);
    useEffect(() => {
        if (data && !access) setAuthorizedThread({ scope, data });
    }, [data, access, scope]);
    const threadData =
        data ??
        (authorizedThread?.scope === scope ? authorizedThread.data : null);
    const onCloseRef = useRef(onClose);
    const errorRef = useRef<HTMLDivElement>(null);
    const approvedNavigation = useRef(false);
    const [draft, setDraft] = useState<ThreadDraftState & { scope: string }>({
        scope,
        dirty: false,
        busy: false,
    });
    const [leave, setLeave] = useState<{
        scope: string;
        kind: 'close' | 'page' | 'navigate';
        run: () => void;
    } | null>(null);
    const currentDraft =
        threadData &&
        draft.scope === scope &&
        access !== 'access' &&
        access !== 'actor'
            ? draft
            : { dirty: false, busy: false };
    const needsDecision = currentDraft.dirty || currentDraft.busy;
    const onDraftStateChange = useCallback(
        (state: ThreadDraftState) => {
            setDraft((current) =>
                current.scope === scope &&
                current.dirty === state.dirty &&
                current.busy === state.busy
                    ? current
                    : { ...state, scope },
            );
        },
        [scope],
    );
    useEffect(() => {
        onCloseRef.current = onClose;
    }, [onClose]);
    useEffect(() => {
        if (error) errorRef.current?.focus();
        if (error === 'access' || error === 'actor') setLeave(null);
    }, [error]);
    useEffect(() => {
        if (!needsDecision) return;
        const beforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', beforeUnload);
        const stop = router.on('before', (event) => {
            if (
                event.detail.visit.method !== 'get' ||
                approvedNavigation.current
            )
                return;
            event.preventDefault();
            const visit = event.detail.visit;
            setLeave({
                scope,
                kind: 'navigate',
                run: () => router.visit(visit.url, visit),
            });
        });
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            stop();
        };
    }, [needsDecision, scope]);
    const requestLeave = (kind: 'close' | 'page') => {
        const run =
            kind === 'close'
                ? () => onCloseRef.current()
                : () => router.visit(`/it/tickets/${ticketId}`);
        if (needsDecision) setLeave({ scope, kind, run });
        else run();
    };
    const pendingLeave = leave?.scope === scope && needsDecision ? leave : null;

    const t = data?.ticket;
    const watcherCommand = useTicketWatcherCommand({
        actorId,
        ticketId: ticketId ?? 0,
        version: t?.lock_version ?? 0,
        canManage: data?.can.manageWatchers === true && !error,
        authorizationReady:
            (!!data && !error) || error === 'access' || error === 'actor',
        watchers: t?.watchers ?? [],
        options: data?.watcherOptions ?? [],
        onCommitted: refresh,
    });

    return (
        <>
            <Sheet
                open={ticketId !== null}
                onOpenChange={(open) => !open && requestLeave('close')}
            >
                <SheetContent
                    side="right"
                    className="flex w-full flex-col gap-3 overflow-y-auto sm:max-w-xl"
                >
                    <SheetHeader className="space-y-1.5 pr-8">
                        <div className="flex flex-wrap items-center gap-2">
                            {t?.reference ? (
                                <span className="font-mono text-[12.5px] font-bold tracking-wide text-muted-foreground">
                                    {t.reference}
                                </span>
                            ) : null}
                            {t ? (
                                <>
                                    <StatusBadge
                                        variant={
                                            statusVariant[t.status] ?? 'neutral'
                                        }
                                        size="sm"
                                    >
                                        {t.status === 'waiting'
                                            ? waitingStatusLabel(
                                                  t.waiting?.party,
                                                  !data?.can.internal,
                                              )
                                            : label(t.status)}
                                    </StatusBadge>
                                    <StatusBadge
                                        variant={
                                            priorityVariant[t.priority] ??
                                            'neutral'
                                        }
                                        size="sm"
                                    >
                                        {label(t.priority)}
                                    </StatusBadge>
                                </>
                            ) : null}
                            {ticketId !== null ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="ml-auto"
                                    onClick={() => requestLeave('page')}
                                >
                                    <ExternalLink className="h-3.5 w-3.5" />{' '}
                                    Open full page
                                </Button>
                            ) : null}
                        </div>
                        <SheetTitle className="text-left text-[17px] leading-snug">
                            {t?.title ??
                                (loading
                                    ? 'Loading ticket…'
                                    : 'Ticket unavailable')}
                        </SheetTitle>
                        <SheetDescription className="text-left">
                            {t
                                ? `${t.requester.name}${t.requester.role ? ` · ${t.requester.role}` : ''}` +
                                  `${t.created_human ? ` · raised ${t.created_human}` : ''}` +
                                  ` · ${t.assignee ? `with ${t.assignee.name}` : 'with IT for triage'}` +
                                  `${t.watchers.length ? ` · ${t.watchers.length} watching` : ''}`
                                : loading
                                  ? 'Fetching the conversation…'
                                  : 'The conversation is not displayed.'}
                        </SheetDescription>
                    </SheetHeader>

                    {error ? (
                        <Alert
                            ref={errorRef}
                            tabIndex={-1}
                            className="mx-4 w-auto"
                        >
                            <AlertTitle>
                                {error === 'session'
                                    ? 'Sign in to continue'
                                    : error === 'actor'
                                      ? 'Your signed-in account changed'
                                      : error === 'access'
                                        ? 'Ticket access unavailable'
                                        : data
                                          ? 'Conversation refresh failed'
                                          : 'Could not load this ticket'}
                            </AlertTitle>
                            <AlertDescription>
                                <p>
                                    {error === 'session'
                                        ? 'Your session expired. Sign in with the same account, then try again.'
                                        : error === 'actor'
                                          ? 'Reload the page to use your current account. The previous conversation and draft are concealed.'
                                          : error === 'access'
                                            ? 'This ticket is no longer available to your current account. Its conversation and draft are concealed.'
                                            : data
                                              ? 'Your entered work is retained. Refresh the conversation before relying on its latest status.'
                                              : 'Check your connection and try again.'}
                                </p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {error === 'session' ? (
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <a
                                                href="/login"
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                Sign in
                                            </a>
                                        </Button>
                                    ) : null}
                                    {error === 'actor' ? (
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <a href={`/it/tickets/${ticketId}`}>
                                                Reload page
                                            </a>
                                        </Button>
                                    ) : (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={refresh}
                                            disabled={loading}
                                        >
                                            Try again
                                        </Button>
                                    )}
                                </div>
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    {t?.routing ? (
                        <div className="rounded-xl border border-border/60 bg-muted/30 px-3 py-2">
                            <TicketRoutingSummary routing={t.routing} compact />
                        </div>
                    ) : null}

                    {data && !error ? (
                        <div className="rounded-xl border border-border p-3">
                            <h2 className="mb-3 text-sm font-semibold">
                                People & feedback
                            </h2>
                            <TicketWatchers
                                command={watcherCommand}
                                actorId={actorId}
                            />
                        </div>
                    ) : null}

                    {loading && !data ? (
                        <div
                            className="flex flex-col gap-3 px-4"
                            role="status"
                            aria-label="Loading ticket conversation"
                        >
                            <span className="sr-only">
                                Loading ticket conversation
                            </span>
                            {[0, 1, 2].map((i) => (
                                <Skeleton
                                    key={i}
                                    className="h-16 rounded-xl motion-reduce:animate-none"
                                />
                            ))}
                        </div>
                    ) : null}

                    {threadData ? (
                        <TicketThread
                            key={`${scope}:${threadData.can.internal ? 'agent' : 'public'}:${threadData.can.comment ? 'reply' : 'read'}`}
                            ticketId={threadData.ticket.id}
                            actorId={actorId}
                            expectedVersion={threadData.ticket.lock_version}
                            conversationReady={threadData.conversation_ready}
                            draftsEnabled={
                                threadData.draftRecovery?.enabled ?? false
                            }
                            requesterName={threadData.ticket.requester.name}
                            description={threadData.ticket.description}
                            ticketAttachments={threadData.ticket.attachments}
                            comments={threadData.comments}
                            events={threadData.events}
                            canInternal={threadData.can.internal}
                            canReply={threadData.can.comment}
                            accessState={access}
                            replyUnavailableReason={
                                threadData.replyUnavailableReason
                            }
                            kbSuggestions={threadData.kbSuggestions}
                            compact
                            onPosted={refresh}
                            refreshingDelivery={loading}
                            onDraftStateChange={onDraftStateChange}
                        />
                    ) : null}
                </SheetContent>
            </Sheet>
            <ConfirmDialog
                open={pendingLeave !== null}
                onClose={() => setLeave(null)}
                onConfirm={() => {
                    if (!pendingLeave) return;
                    approvedNavigation.current = true;
                    pendingLeave.run();
                    approvedNavigation.current = false;
                }}
                title={
                    currentDraft.busy
                        ? 'Leave while the reply is saving?'
                        : 'Discard the entered reply?'
                }
                description={
                    currentDraft.busy
                        ? 'The request may still finish after you leave. Closing this draft does not cancel or undo a submitted reply. Check the ticket before sending again.'
                        : 'The text and selected files in this drawer will be discarded. Cancel to keep editing here.'
                }
                confirmText={
                    currentDraft.busy
                        ? 'Leave and check later'
                        : pendingLeave?.kind === 'page'
                          ? 'Discard and open full page'
                          : pendingLeave?.kind === 'navigate'
                            ? 'Discard and continue'
                            : 'Discard and close'
                }
            />
        </>
    );
}
