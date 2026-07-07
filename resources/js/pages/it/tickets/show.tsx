/* The ticket workspace (/it/tickets/{ticket}) — §E. Left: the shared
 * TicketThread (conversation ⇄ activity timeline + composer). Right: the
 * properties rail where agents triage (status / priority / category /
 * assignee / watchers / linked asset) — every control PATCHes a real route
 * and toasts. Requesters get a read-only rail for their own ticket;
 * internal notes never reach their payload (server-side strip). */
import { ResolveTicketDialog } from '@/components/it/it-wizards';
import {
    TicketThread,
    type ThreadAttachment,
    type ThreadComment,
    type ThreadEvent,
} from '@/components/it/ticket-thread';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    CheckCircle2,
    Copy,
    Eye,
    EyeOff,
    Link2,
    RotateCcw,
    Server,
    Ticket as TicketIcon,
    Timer,
    UserPlus,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

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
    first_response_due_at: string | null;
    resolution_due_at: string | null;
    first_responded_at: string | null;
    requester: { id: number | null; name: string; role: string | null };
    assignee: { id: number; name: string } | null;
    watchers: { id: number; name: string }[];
    asset: { id: number; name: string; tag: string | null } | null;
    provisioning_request: { id: number; item: string; status: string } | null;
    attachments: ThreadAttachment[];
    created_at: string | null;
    created_human: string | null;
    updated_at: string | null;
    resolved_at: string | null;
    closed_at: string | null;
}

interface Props {
    ticket: TicketPayload;
    comments: ThreadComment[];
    events: ThreadEvent[];
    assignees: { id: number; name: string }[];
    assetOptions: { id: number; name: string; tag: string | null }[];
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

/** Sentinel — Radix <SelectItem value=""> crashes at runtime. */
const NONE = 'none';

const WORKING_STATUSES = ['open', 'in_progress', 'waiting'];

const label = (raw: string) =>
    raw.replace(/[_-]/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

const NZ_DATETIME = new Intl.DateTimeFormat('en-NZ', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Pacific/Auckland',
});

const absolute = (iso: string | null) => (iso ? NZ_DATETIME.format(new Date(iso)) : '—');

export default function ItTicketShow({
    ticket,
    comments,
    events,
    assignees,
    assetOptions,
    can,
}: Props) {
    const page = usePage<{ auth?: { user?: { id?: number } } }>();
    const myId = page.props.auth?.user?.id ?? null;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'IT & Provisioning', href: '/it' },
        { title: ticket.reference ?? `Ticket ${ticket.id}`, href: `/it/tickets/${ticket.id}` },
    ];

    const [subcategory, setSubcategory] = useState(ticket.subcategory ?? '');
    const [resolving, setResolving] = useState(false);

    /** PATCH a triage field and toast the outcome. */
    const patch = (data: Record<string, string | number | null>, doneMsg = 'Ticket updated.') =>
        router.patch(`/it/tickets/${ticket.id}`, data, {
            preserveScroll: true,
            onSuccess: (p) => {
                const flash = p.props.flash as { error?: string } | undefined;
                if (flash?.error) toast.error(flash.error);
                else toast.success(doneMsg);
            },
        });

    const act = (url: string, doneMsg: string) =>
        router.post(url, {}, {
            preserveScroll: true,
            onSuccess: () => toast.success(doneMsg),
        });

    const copyReference = () => {
        if (!ticket.reference) return;
        void navigator.clipboard.writeText(ticket.reference).then(() => {
            toast.success(`${ticket.reference} copied.`);
        });
    };

    const isWorking = WORKING_STATUSES.includes(ticket.status);

    // SLA chip — hidden until due dates are stamped (SLA engine, item 8) or
    // once the target is met. Always text + icon, never colour alone.
    const slaDue = ticket.first_responded_at ? ticket.resolution_due_at : ticket.first_response_due_at;
    const slaLabel = ticket.first_responded_at ? 'resolution due' : 'response due';
    const showSla = isWorking && slaDue !== null && ticket.sla_state !== 'met';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${ticket.reference ?? 'Ticket'} — ${ticket.title}`} />
            {resolving ? (
                <ResolveTicketDialog
                    ticket={{ id: ticket.id, reference: ticket.reference, title: ticket.title }}
                    onClose={() => setResolving(false)}
                />
            ) : null}

            <div className="flex flex-col gap-5 p-4 sm:p-6">
                {/* Compact header band */}
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
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                {ticket.reference ? (
                                    // eslint-disable-next-line no-restricted-syntax -- copy-on-click reference chip
                                    <button
                                        type="button"
                                        onClick={copyReference}
                                        title="Copy reference"
                                        className="inline-flex items-center gap-1.5 rounded-lg border border-white/20 bg-white/10 px-2 py-0.5 font-mono text-[13px] font-bold tracking-wide text-white/90 hover:bg-white/20"
                                    >
                                        {ticket.reference}
                                        <Copy className="h-3 w-3" />
                                    </button>
                                ) : null}
                                <StatusBadge variant={statusVariant[ticket.status] ?? 'neutral'} size="sm">
                                    {label(ticket.status)}
                                </StatusBadge>
                                <StatusBadge variant={priorityVariant[ticket.priority] ?? 'neutral'} size="sm">
                                    {label(ticket.priority)}
                                </StatusBadge>
                                {showSla ? (
                                    <StatusBadge
                                        variant={
                                            ticket.sla_state === 'breached'
                                                ? 'critical'
                                                : ticket.sla_state === 'at_risk'
                                                  ? 'warning'
                                                  : 'info'
                                        }
                                        size="sm"
                                    >
                                        <Timer className="mr-1 h-3 w-3" />
                                        {slaLabel} {absolute(slaDue)}
                                    </StatusBadge>
                                ) : null}
                            </div>
                            <h1 className="mt-1 truncate text-[22px] leading-tight font-bold tracking-tight">
                                {ticket.title}
                            </h1>
                            <p className="mt-0.5 text-[12.5px] font-medium text-white/70">
                                Raised by {ticket.requester.name}
                                {ticket.requester.role ? ` · ${ticket.requester.role}` : ''}
                                {ticket.created_human ? ` · ${ticket.created_human}` : ''}
                            </p>
                        </div>
                        <div className="flex flex-none flex-wrap items-center gap-2">
                            {can.manage && isWorking ? (
                                <Button
                                    size="sm"
                                    className="bg-white/15 text-primary-foreground hover:bg-white/25"
                                    onClick={() => setResolving(true)}
                                >
                                    <CheckCircle2 className="h-3.5 w-3.5" /> Resolve…
                                </Button>
                            ) : null}
                            {can.manage && ticket.status === 'resolved' ? (
                                <Button
                                    size="sm"
                                    className="bg-white/15 text-primary-foreground hover:bg-white/25"
                                    onClick={() => act(`/it/tickets/${ticket.id}/close`, 'Ticket closed.')}
                                >
                                    <XCircle className="h-3.5 w-3.5" /> Close
                                </Button>
                            ) : null}
                            {can.reopen && (ticket.status === 'resolved' || ticket.status === 'closed') ? (
                                <Button
                                    size="sm"
                                    className="bg-white/15 text-primary-foreground hover:bg-white/25"
                                    onClick={() => act(`/it/tickets/${ticket.id}/reopen`, 'Ticket reopened.')}
                                >
                                    <RotateCcw className="h-3.5 w-3.5" /> Reopen
                                </Button>
                            ) : null}
                            {can.manage ? (
                                <>
                                    {ticket.assignee?.id !== myId && isWorking ? (
                                        <Button
                                            size="sm"
                                            className="bg-white/15 text-primary-foreground hover:bg-white/25"
                                            onClick={() =>
                                                myId !== null &&
                                                patch({ assigned_to_user_id: myId }, 'Assigned to you.')
                                            }
                                        >
                                            <UserPlus className="h-3.5 w-3.5" /> Assign to me
                                        </Button>
                                    ) : null}
                                    <Button
                                        size="sm"
                                        className="bg-white/15 text-primary-foreground hover:bg-white/25"
                                        onClick={() =>
                                            act(
                                                `/it/tickets/${ticket.id}/${can.watching ? 'unwatch' : 'watch'}`,
                                                can.watching ? 'Stopped watching.' : 'Watching this ticket.',
                                            )
                                        }
                                    >
                                        {can.watching ? (
                                            <>
                                                <EyeOff className="h-3.5 w-3.5" /> Unwatch
                                            </>
                                        ) : (
                                            <>
                                                <Eye className="h-3.5 w-3.5" /> Watch
                                            </>
                                        )}
                                    </Button>
                                </>
                            ) : null}
                        </div>
                    </div>
                </div>

                {/* Workspace: thread left, properties rail right */}
                <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <TicketThread
                        ticketId={ticket.id}
                        requesterName={ticket.requester.name}
                        description={ticket.description}
                        ticketAttachments={ticket.attachments}
                        comments={comments}
                        events={events}
                        canInternal={can.internal}
                    />

                    {/* Properties rail */}
                    <div className="flex flex-col gap-3 overflow-hidden rounded-2xl border border-border bg-card px-4 py-4">
                        <div className="text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                            Properties
                        </div>

                        {can.manage && isWorking ? (
                            <>
                                <RailField label="Status">
                                    <Select
                                        value={ticket.status}
                                        onValueChange={(v) => patch({ status: v }, `Status set to ${label(v)}.`)}
                                    >
                                        <SelectTrigger className="h-8" aria-label="Status">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {WORKING_STATUSES.map((s) => (
                                                <SelectItem key={s} value={s}>
                                                    {s === 'waiting' ? 'Waiting on requester' : label(s)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </RailField>
                                <RailField label="Priority">
                                    <Select
                                        value={ticket.priority}
                                        onValueChange={(v) => patch({ priority: v }, `Priority set to ${label(v)}.`)}
                                    >
                                        <SelectTrigger className="h-8" aria-label="Priority">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {['low', 'normal', 'high', 'urgent'].map((p) => (
                                                <SelectItem key={p} value={p}>
                                                    {label(p)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </RailField>
                                <RailField label="Category">
                                    <Select
                                        value={ticket.category}
                                        onValueChange={(v) => patch({ category: v }, `Category set to ${label(v)}.`)}
                                    >
                                        <SelectTrigger className="h-8" aria-label="Category">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {['hardware', 'account', 'network', 'other'].map((c) => (
                                                <SelectItem key={c} value={c}>
                                                    {label(c)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </RailField>
                                <RailField label="Subcategory">
                                    <div className="flex gap-1.5">
                                        <Input
                                            value={subcategory}
                                            onChange={(e) => setSubcategory(e.target.value)}
                                            placeholder="e.g. laptop, VPN…"
                                            className="h-8"
                                            maxLength={255}
                                        />
                                        {subcategory !== (ticket.subcategory ?? '') ? (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                aria-label="Save subcategory"
                                                onClick={() =>
                                                    patch(
                                                        { subcategory: subcategory.trim() || null },
                                                        'Subcategory saved.',
                                                    )
                                                }
                                            >
                                                <Check className="h-3.5 w-3.5" />
                                            </Button>
                                        ) : null}
                                    </div>
                                </RailField>
                                <RailField label="Assignee">
                                    <Select
                                        value={ticket.assignee ? String(ticket.assignee.id) : NONE}
                                        onValueChange={(v) =>
                                            patch(
                                                { assigned_to_user_id: v === NONE ? null : Number(v) },
                                                'Assignee updated.',
                                            )
                                        }
                                    >
                                        <SelectTrigger className="h-8" aria-label="Assignee">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE}>Unassigned</SelectItem>
                                            {assignees.map((a) => (
                                                <SelectItem key={a.id} value={String(a.id)}>
                                                    {a.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </RailField>
                                <RailField label="Linked asset">
                                    <Select
                                        value={ticket.asset ? String(ticket.asset.id) : NONE}
                                        onValueChange={(v) =>
                                            patch({ asset_id: v === NONE ? null : Number(v) }, 'Asset link updated.')
                                        }
                                    >
                                        <SelectTrigger className="h-8" aria-label="Linked asset">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE}>No linked asset</SelectItem>
                                            {assetOptions.map((a) => (
                                                <SelectItem key={a.id} value={String(a.id)}>
                                                    {a.name}
                                                    {a.tag ? ` (${a.tag})` : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </RailField>
                            </>
                        ) : (
                            <>
                                <RailRow k="Status" v={label(ticket.status)} />
                                <RailRow k="Priority" v={label(ticket.priority)} />
                                <RailRow
                                    k="Category"
                                    v={`${label(ticket.category)}${ticket.subcategory ? ` · ${ticket.subcategory}` : ''}`}
                                />
                                <RailRow k="Assignee" v={ticket.assignee?.name ?? 'With IT for triage'} />
                                {ticket.asset ? (
                                    <RailRow
                                        k="Linked asset"
                                        v={`${ticket.asset.name}${ticket.asset.tag ? ` (${ticket.asset.tag})` : ''}`}
                                    />
                                ) : null}
                            </>
                        )}

                        {ticket.provisioning_request ? (
                            <RailField label="Provisioning request">
                                <div className="flex items-center gap-2 rounded-lg border border-border/60 bg-muted/40 px-2.5 py-1.5 text-[12.5px]">
                                    <Server className="h-3.5 w-3.5 flex-none text-muted-foreground" />
                                    <span className="min-w-0 truncate">
                                        {ticket.provisioning_request.item}
                                    </span>
                                    <StatusBadge variant="neutral" size="sm">
                                        {label(ticket.provisioning_request.status)}
                                    </StatusBadge>
                                </div>
                            </RailField>
                        ) : null}

                        {/* Watchers */}
                        <RailField label={`Watchers (${ticket.watchers.length})`}>
                            {ticket.watchers.length ? (
                                <div className="flex flex-wrap gap-1.5">
                                    {ticket.watchers.map((w) => (
                                        <span
                                            key={w.id}
                                            className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-muted/50 px-2 py-0.5 text-[11.5px] font-semibold"
                                        >
                                            <Eye className="h-3 w-3 text-muted-foreground" /> {w.name}
                                        </span>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-[12px] text-muted-foreground">Nobody watching yet.</p>
                            )}
                        </RailField>

                        {/* People + stamps */}
                        <div className="mt-1 border-t border-border/60 pt-3">
                            <RailRow
                                k="Requester"
                                v={`${ticket.requester.name}${ticket.requester.role ? ` · ${ticket.requester.role}` : ''}`}
                            />
                            <RailRow k="Source" v={label(ticket.source)} />
                            <RailRow k="Raised" v={absolute(ticket.created_at)} />
                            <RailRow k="Updated" v={absolute(ticket.updated_at)} />
                            {ticket.resolved_at ? <RailRow k="Resolved" v={absolute(ticket.resolved_at)} /> : null}
                            {ticket.closed_at ? <RailRow k="Closed" v={absolute(ticket.closed_at)} /> : null}
                        </div>

                        <div className="flex items-center gap-1.5 border-t border-border/60 pt-3">
                            <Link2 className="h-3.5 w-3.5 text-muted-foreground" />
                            {/* eslint-disable-next-line no-restricted-syntax -- text-link copy affordance */}
                            <button
                                type="button"
                                onClick={() => {
                                    void navigator.clipboard
                                        .writeText(window.location.href)
                                        .then(() => toast.success('Link copied.'));
                                }}
                                className="text-[12px] font-semibold text-primary hover:underline"
                            >
                                Copy link to this ticket
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

function RailField({ label: l, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-1">
            <span className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase">{l}</span>
            {children}
        </div>
    );
}

function RailRow({ k, v }: { k: string; v: string }) {
    return (
        <div className="flex items-baseline justify-between gap-2 py-1">
            <span className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase">{k}</span>
            <span className="min-w-0 truncate text-right text-[12.5px] font-medium">{v}</span>
        </div>
    );
}
