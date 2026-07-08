/* Quick-peek drawer (§E) — fast triage without leaving the queue. Fetches
 * the SAME it.tickets.show payload the detail page uses (axios/JSON branch,
 * identical policy + internal-note stripping) and renders the shared
 * TicketThread with a condensed read-only rail. Actions beyond replying
 * live on the full page — one click away. */
import {
    TicketThread,
    type ThreadAttachment,
    type ThreadComment,
    type ThreadEvent,
    type ThreadKbHint,
} from '@/components/it/ticket-thread';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { ExternalLink } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

interface PeekPayload {
    ticket: {
        id: number;
        reference: string | null;
        title: string;
        description: string | null;
        priority: string;
        status: string;
        requester: { id: number | null; name: string; role: string | null };
        assignee: { id: number; name: string } | null;
        watchers: { id: number; name: string }[];
        attachments: ThreadAttachment[];
        created_human: string | null;
    };
    comments: ThreadComment[];
    events: ThreadEvent[];
    kbSuggestions?: ThreadKbHint[];
    can: { internal: boolean };
}

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
    const [data, setData] = useState<PeekPayload | null>(null);
    const [loading, setLoading] = useState(false);

    const fetchTicket = useCallback(() => {
        if (ticketId === null) return;
        setLoading(true);
        axios
            .get<PeekPayload>(`/it/tickets/${ticketId}`, {
                headers: { Accept: 'application/json' },
            })
            .then((res) => setData(res.data))
            .catch(() => {
                toast.error('Could not load that ticket.');
                onClose();
            })
            .finally(() => setLoading(false));
    }, [ticketId, onClose]);

    useEffect(() => {
        setData(null);
        fetchTicket();
    }, [fetchTicket]);

    const t = data?.ticket;

    return (
        <Sheet open={ticketId !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent side="right" className="flex w-full flex-col gap-3 overflow-y-auto sm:max-w-xl">
                <SheetHeader className="space-y-1.5 pr-8">
                    <div className="flex flex-wrap items-center gap-2">
                        {t?.reference ? (
                            <span className="font-mono text-[12.5px] font-bold tracking-wide text-muted-foreground">
                                {t.reference}
                            </span>
                        ) : null}
                        {t ? (
                            <>
                                <StatusBadge variant={statusVariant[t.status] ?? 'neutral'} size="sm">
                                    {t.status === 'waiting' ? 'Waiting on requester' : label(t.status)}
                                </StatusBadge>
                                <StatusBadge variant={priorityVariant[t.priority] ?? 'neutral'} size="sm">
                                    {label(t.priority)}
                                </StatusBadge>
                            </>
                        ) : null}
                        {ticketId !== null ? (
                            <Button
                                size="sm"
                                variant="outline"
                                className="ml-auto"
                                onClick={() => router.visit(`/it/tickets/${ticketId}`)}
                            >
                                <ExternalLink className="h-3.5 w-3.5" /> Open full page
                            </Button>
                        ) : null}
                    </div>
                    <SheetTitle className="text-left text-[17px] leading-snug">
                        {t?.title ?? 'Loading ticket…'}
                    </SheetTitle>
                    <SheetDescription className="text-left">
                        {t
                            ? `${t.requester.name}${t.requester.role ? ` · ${t.requester.role}` : ''}` +
                              `${t.created_human ? ` · raised ${t.created_human}` : ''}` +
                              ` · ${t.assignee ? `with ${t.assignee.name}` : 'with IT for triage'}` +
                              `${t.watchers.length ? ` · ${t.watchers.length} watching` : ''}`
                            : 'Fetching the conversation…'}
                    </SheetDescription>
                </SheetHeader>

                {loading && !data ? (
                    <div className="flex flex-col gap-2" aria-hidden>
                        {[0, 1, 2].map((i) => (
                            <div key={i} className="h-16 animate-pulse rounded-xl bg-muted motion-reduce:animate-none" />
                        ))}
                    </div>
                ) : null}

                {data && t ? (
                    <TicketThread
                        ticketId={t.id}
                        requesterName={t.requester.name}
                        description={t.description}
                        ticketAttachments={t.attachments}
                        comments={data.comments}
                        events={data.events}
                        canInternal={data.can.internal}
                        kbSuggestions={data.kbSuggestions}
                        compact
                        onPosted={fetchTicket}
                    />
                ) : null}
            </SheetContent>
        </Sheet>
    );
}
