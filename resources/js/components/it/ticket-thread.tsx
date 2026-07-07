/* Shared conversation surface for the ticket workspace — used by the
 * detail page and (item 6c) the quick-peek drawer. Renders the thread
 * (public replies + agent-only internal notes, already stripped from
 * requester payloads server-side), an Activity timeline lane fed by
 * it_ticket_events, and the composer (Reply ⇄ Internal note, Ctrl+Enter). */
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import {
    Activity,
    Flag,
    Lock,
    MessageSquare,
    Send,
    UserCog,
    Eye,
    EyeOff,
    RotateCcw,
    CheckCircle2,
    PlusCircle,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export interface ThreadComment {
    id: number;
    body: string;
    is_internal: boolean;
    author: { id: number | null; name: string; is_requester: boolean };
    at: string | null;
    at_human: string | null;
}

export interface ThreadEvent {
    id: number;
    type: string;
    payload: Record<string, unknown> | null;
    actor: string | null;
    at: string | null;
    at_human: string | null;
}

const label = (raw: string) =>
    raw.replace(/[_-]/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

/** Human sentence for an activity row. */
function eventLine(e: ThreadEvent): string {
    const p = e.payload ?? {};
    switch (e.type) {
        case 'created':
            return 'raised the ticket';
        case 'assigned':
            return p.to ? 'assigned the ticket' : 'unassigned the ticket';
        case 'status_changed':
            return `moved ${label(String(p.from ?? '?'))} → ${label(String(p.to ?? '?'))}`;
        case 'priority_changed':
            return `set priority ${label(String(p.from ?? '?'))} → ${label(String(p.to ?? '?'))}`;
        case 'watcher_added':
            return 'started watching';
        case 'watcher_removed':
            return 'stopped watching';
        case 'reopened':
            return 'reopened the ticket';
        case 'resolved':
            return 'resolved the ticket';
        case 'closed':
            return 'closed the ticket';
        default:
            return label(e.type);
    }
}

function eventIcon(type: string) {
    switch (type) {
        case 'created':
            return PlusCircle;
        case 'assigned':
            return UserCog;
        case 'status_changed':
            return RotateCcw;
        case 'priority_changed':
            return Flag;
        case 'watcher_added':
            return Eye;
        case 'watcher_removed':
            return EyeOff;
        case 'resolved':
        case 'closed':
            return CheckCircle2;
        default:
            return Activity;
    }
}

export function TicketThread({
    ticketId,
    requesterName,
    description,
    comments,
    events,
    canInternal,
    compact = false,
    onPosted,
}: {
    ticketId: number;
    requesterName: string;
    description: string | null;
    comments: ThreadComment[];
    events: ThreadEvent[];
    canInternal: boolean;
    compact?: boolean;
    /** Drawer hosts pass a refetch — their snapshot doesn't refresh via Inertia props. */
    onPosted?: () => void;
}) {
    const [lane, setLane] = useState<'conversation' | 'activity'>('conversation');
    const form = useForm({ body: '', is_internal: false });

    const send = () => {
        if (!form.data.body.trim()) return;
        form.post(`/it/tickets/${ticketId}/comments`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(form.data.is_internal ? 'Internal note added.' : 'Reply sent.');
                form.reset('body');
                onPosted?.();
            },
        });
    };

    return (
        <div className="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-border bg-card">
            {/* Lane toggle */}
            <div className="flex items-center gap-1 border-b border-border bg-muted px-3 py-2">
                {(
                    [
                        { id: 'conversation', l: 'Conversation', icon: MessageSquare },
                        { id: 'activity', l: 'Activity', icon: Activity },
                    ] as const
                ).map((o) => {
                    const Icon = o.icon;
                    const active = lane === o.id;
                    return (
                        // eslint-disable-next-line no-restricted-syntax -- segmented lane toggle, not button chrome
                        <button
                            key={o.id}
                            type="button"
                            aria-pressed={active}
                            onClick={() => setLane(o.id)}
                            className={
                                active
                                    ? 'inline-flex items-center gap-1.5 rounded-lg bg-card px-3 py-1.5 text-[12.5px] font-semibold shadow-sm'
                                    : 'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12.5px] font-semibold text-muted-foreground hover:text-foreground'
                            }
                        >
                            <Icon className="h-3.5 w-3.5" />
                            {o.l}
                            {o.id === 'activity' ? (
                                <span className="rounded-full bg-muted px-1.5 text-[10.5px] font-bold">
                                    {events.length}
                                </span>
                            ) : null}
                        </button>
                    );
                })}
            </div>

            {lane === 'conversation' ? (
                <>
                    <div
                        className={
                            compact
                                ? 'flex max-h-[46vh] flex-col gap-3 overflow-y-auto px-4 py-4'
                                : 'flex flex-col gap-3 px-4.5 py-4'
                        }
                    >
                        {description ? (
                            <div className="rounded-xl border border-border/60 bg-muted/40 px-3.5 py-2.5">
                                <div className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                                    {requesterName} — original report
                                </div>
                                <p className="mt-1 text-[13px] whitespace-pre-wrap">{description}</p>
                            </div>
                        ) : null}

                        {comments.map((c) => (
                            <div
                                key={c.id}
                                className={
                                    c.is_internal
                                        ? 'rounded-xl border border-border/60 bg-accent/50 px-3.5 py-2.5'
                                        : 'rounded-xl border border-border/60 bg-card px-3.5 py-2.5'
                                }
                            >
                                <div className="flex flex-wrap items-center gap-2 text-[11px] font-semibold text-muted-foreground">
                                    <span className="text-foreground">{c.author.name}</span>
                                    <span>· {c.author.is_requester ? 'requester' : 'IT'}</span>
                                    {c.is_internal ? (
                                        <StatusBadge variant="warning" size="sm">
                                            <Lock className="mr-1 h-3 w-3" /> Internal
                                        </StatusBadge>
                                    ) : null}
                                    <span className="ml-auto">{c.at_human}</span>
                                </div>
                                <p className="mt-1 text-[13px] whitespace-pre-wrap">{c.body}</p>
                            </div>
                        ))}

                        {comments.length === 0 && !description ? (
                            <p className="py-6 text-center text-[12.5px] text-muted-foreground">
                                No messages yet — start the conversation below.
                            </p>
                        ) : null}
                    </div>

                    {/* Composer */}
                    <div className="border-t border-border px-4.5 py-3.5">
                        {canInternal ? (
                            <div className="mb-2 inline-flex gap-1 rounded-lg bg-muted p-1">
                                {[
                                    { v: false, l: 'Reply' },
                                    { v: true, l: 'Internal note' },
                                ].map((o) => (
                                    // eslint-disable-next-line no-restricted-syntax -- segmented-control option, not button chrome
                                    <button
                                        key={o.l}
                                        type="button"
                                        aria-pressed={form.data.is_internal === o.v}
                                        onClick={() => form.setData('is_internal', o.v)}
                                        className={
                                            form.data.is_internal === o.v
                                                ? 'rounded-md bg-card px-3 py-1 text-[12.5px] font-semibold shadow-sm'
                                                : 'rounded-md px-3 py-1 text-[12.5px] font-semibold text-muted-foreground hover:text-foreground'
                                        }
                                    >
                                        {o.l}
                                    </button>
                                ))}
                            </div>
                        ) : null}
                        <Textarea
                            value={form.data.body}
                            onChange={(e) => form.setData('body', e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) send();
                            }}
                            placeholder={
                                form.data.is_internal
                                    ? 'Add an internal note — the requester never sees these…'
                                    : 'Write a reply — the requester is emailed a heads-up…'
                            }
                            rows={compact ? 2 : 3}
                        />
                        <div className="mt-2 flex items-center justify-between">
                            <span className="text-[11.5px] text-muted-foreground">Ctrl+Enter to send</span>
                            <Button
                                size="sm"
                                onClick={send}
                                disabled={form.processing || !form.data.body.trim()}
                            >
                                <Send className="h-3.5 w-3.5" />
                                {form.data.is_internal ? 'Add note' : 'Send reply'}
                            </Button>
                        </div>
                    </div>
                </>
            ) : (
                <div
                    className={
                        compact
                            ? 'flex max-h-[58vh] flex-col overflow-y-auto px-4 py-3'
                            : 'flex flex-col px-4.5 py-3'
                    }
                >
                    {events.length === 0 ? (
                        <p className="py-6 text-center text-[12.5px] text-muted-foreground">
                            Nothing on the trail yet.
                        </p>
                    ) : (
                        events.map((e) => {
                            const Icon = eventIcon(e.type);
                            return (
                                <div
                                    key={e.id}
                                    className="flex items-start gap-2.5 border-b border-border/40 py-2.5 last:border-0"
                                >
                                    <span className="mt-0.5 grid h-6 w-6 flex-none place-items-center rounded-lg bg-muted text-muted-foreground">
                                        <Icon className="h-3.5 w-3.5" />
                                    </span>
                                    <div className="min-w-0 text-[12.5px]">
                                        <span className="font-semibold">{e.actor ?? 'System'}</span>{' '}
                                        <span className="text-muted-foreground">{eventLine(e)}</span>
                                        <span className="ml-2 text-[11px] text-muted-foreground">
                                            {e.at_human}
                                        </span>
                                    </div>
                                </div>
                            );
                        })
                    )}
                </div>
            )}
        </div>
    );
}
