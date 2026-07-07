/* The ticket workspace (/it/tickets/{ticket}) — v0: header band, the
 * conversation thread (internal notes tinted + chipped, already stripped
 * from requester payloads server-side) and a working composer. The full §E
 * workspace (properties rail, timeline lane, drawer) lands with gap-doc
 * item 6b — everything rendered here is live functionality, no stubs. */
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Lock, MessageSquare, Send, Ticket as TicketIcon } from 'lucide-react';
import { toast } from 'sonner';

interface CommentRow {
    id: number;
    body: string;
    is_internal: boolean;
    author: { id: number | null; name: string; is_requester: boolean };
    at: string | null;
    at_human: string | null;
}

interface EventRow {
    id: number;
    type: string;
    payload: Record<string, unknown> | null;
    actor: string | null;
    at: string | null;
    at_human: string | null;
}

interface TicketPayload {
    id: number;
    reference: string | null;
    title: string;
    description: string | null;
    category: string;
    subcategory: string | null;
    priority: string;
    status: string;
    source: string;
    sla_state: string;
    requester: { id: number | null; name: string; role: string | null };
    assignee: { id: number; name: string } | null;
    watchers: { id: number; name: string }[];
    asset: { id: number; name: string; tag: string | null } | null;
    provisioning_request: { id: number; item: string; status: string } | null;
    created_human: string | null;
}

interface Props {
    ticket: TicketPayload;
    comments: CommentRow[];
    events: EventRow[];
    assignees: { id: number; name: string }[];
    can: {
        manage: boolean;
        view: boolean;
        internal: boolean;
        reopen: boolean;
        watching: boolean;
    };
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

export default function ItTicketShow({ ticket, comments, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'IT & Provisioning', href: '/it' },
        { title: ticket.reference ?? `Ticket ${ticket.id}`, href: `/it/tickets/${ticket.id}` },
    ];

    const form = useForm({ body: '', is_internal: false });

    const send = () => {
        if (!form.data.body.trim()) return;
        form.post(`/it/tickets/${ticket.id}/comments`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(form.data.is_internal ? 'Internal note added.' : 'Reply sent.');
                form.reset();
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${ticket.reference ?? 'Ticket'} — ${ticket.title}`} />

            <div className="flex flex-col gap-5 p-4 sm:p-6">
                {/* Header band */}
                <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 px-9 py-7 text-primary-foreground">
                    <Link
                        href="/it"
                        className="inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-white/75 hover:text-white"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" /> Back to IT &amp; Provisioning
                    </Link>
                    <div className="mt-3 flex flex-wrap items-center gap-3">
                        <span className="grid h-[46px] w-[46px] flex-none place-items-center rounded-2xl border border-white/20 bg-white/15">
                            <TicketIcon className="h-5 w-5" />
                        </span>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-[13px] font-bold tracking-wide text-white/85">
                                    {ticket.reference}
                                </span>
                                <StatusBadge variant={statusVariant[ticket.status] ?? 'neutral'} size="sm">
                                    {label(ticket.status)}
                                </StatusBadge>
                                <StatusBadge variant={priorityVariant[ticket.priority] ?? 'neutral'} size="sm">
                                    {label(ticket.priority)}
                                </StatusBadge>
                            </div>
                            <h1 className="mt-1 truncate text-[22px] leading-tight font-bold tracking-tight">
                                {ticket.title}
                            </h1>
                            <p className="mt-0.5 text-[12.5px] font-medium text-white/70">
                                Raised by {ticket.requester.name}
                                {ticket.requester.role ? ` · ${ticket.requester.role}` : ''}
                                {ticket.created_human ? ` · ${ticket.created_human}` : ''}
                                {ticket.assignee ? ` · with ${ticket.assignee.name}` : ' · with IT for triage'}
                            </p>
                        </div>
                    </div>
                </div>

                {/* Conversation */}
                <div className="overflow-hidden rounded-2xl border border-border bg-card">
                    <div className="flex items-center gap-2 border-b border-border bg-muted px-4.5 py-2.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                        <MessageSquare className="h-3.5 w-3.5" /> Conversation
                    </div>

                    <div className="flex flex-col gap-3 px-4.5 py-4">
                        {ticket.description ? (
                            <div className="rounded-xl border border-border/60 bg-muted/40 px-3.5 py-2.5">
                                <div className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                                    {ticket.requester.name} — original report
                                </div>
                                <p className="mt-1 text-[13px] whitespace-pre-wrap">{ticket.description}</p>
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
                                    {c.author.is_requester ? <span>· requester</span> : <span>· IT</span>}
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

                        {comments.length === 0 && !ticket.description ? (
                            <p className="py-6 text-center text-[12.5px] text-muted-foreground">
                                No messages yet — start the conversation below.
                            </p>
                        ) : null}
                    </div>

                    {/* Composer */}
                    <div className="border-t border-border px-4.5 py-3.5">
                        {can.internal ? (
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
                            rows={3}
                        />
                        <div className="mt-2 flex items-center justify-between">
                            <span className="text-[11.5px] text-muted-foreground">Ctrl+Enter to send</span>
                            <Button size="sm" onClick={send} disabled={form.processing || !form.data.body.trim()}>
                                <Send className="h-3.5 w-3.5" />
                                {form.data.is_internal ? 'Add note' : 'Send reply'}
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
