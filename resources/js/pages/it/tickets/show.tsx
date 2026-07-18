/* The ticket workspace (/it/tickets/{ticket}) — §E. Left: the shared
 * TicketThread (conversation ⇄ activity timeline + composer). Right: the
 * properties rail where agents triage (status / priority / category /
 * assignee / watchers / linked asset) — every control PATCHes a real route
 * and toasts. Requesters get a read-only rail for their own ticket;
 * internal notes never reach their payload (server-side strip). */
import { CsatRater, CsatStars } from '@/components/it/csat';
import { ItModuleShell } from '@/components/it/it-module-shell';
import { MergeTicketDialog, ResolveTicketDialog, type MergeTarget } from '@/components/it/it-wizards';
import { SlaChip } from '@/components/it/sla-chip';
import {
    TicketLinkedContext,
    type TicketLinkedAlert,
    type TicketLinkedChange,
    type TicketLinkedDevice,
    type TicketLinkedMajorIncident,
    type TicketLinkedProblem,
} from '@/components/it/ticket-linked-context';
import {
    TicketThread,
    type ThreadAttachment,
    type ThreadComment,
    type ThreadEvent,
    type ThreadKbHint,
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
    GitMerge,
    Link2,
    Mail,
    RotateCcw,
    Server,
    ShieldCheck,
    Ticket as TicketIcon,
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
    csat: { score: number; comment: string | null; submitted_at: string | null } | null;
    created_at: string | null;
    created_human: string | null;
    updated_at: string | null;
    resolved_at: string | null;
    monitoring_recovered_at: string | null;
    closed_at: string | null;
    merged_into: { id: number; reference: string | null; title: string } | null;
    requires_approval: boolean;
    approval: {
        id: number;
        status: string;
        requested_by_name: string | null;
        approver_name: string | null;
        reason: string | null;
        requested_at: string | null;
        decided_at: string | null;
    } | null;
}

interface Props {
    ticket: TicketPayload;
    comments: ThreadComment[];
    events: ThreadEvent[];
    assignees: { id: number; name: string }[];
    assetOptions: { id: number; name: string; tag: string | null }[];
    kbSuggestions: ThreadKbHint[];
    mergeTargets: MergeTarget[];
    linked_context: {
        devices: TicketLinkedDevice[];
        alerts: TicketLinkedAlert[];
        changes: TicketLinkedChange[];
        problems: TicketLinkedProblem[];
        major_incidents: TicketLinkedMajorIncident[];
    };
    can: {
        manage: boolean;
        view: boolean;
        internal: boolean;
        reopen: boolean;
        watching: boolean;
        rate: boolean;
        merge: boolean;
        requestApproval: boolean;
        decideApproval: boolean;
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
    kbSuggestions,
    mergeTargets,
    linked_context,
    can,
}: Props) {
    const page = usePage<{ auth?: { user?: { id?: number } } }>();
    const myId = page.props.auth?.user?.id ?? null;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'IT & Support', href: '/it' },
        { title: ticket.reference ?? `Ticket ${ticket.id}`, href: `/it/tickets/${ticket.id}` },
    ];

    const [subcategory, setSubcategory] = useState(ticket.subcategory ?? '');
    const [resolving, setResolving] = useState(false);
    const [merging, setMerging] = useState(false);

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

    const decideApproval = (decision: 'approve' | 'reject') => {
        if (!ticket.approval) return;
        router.post(`/it/approvals/${ticket.approval.id}/decide`, { decision }, {
            preserveScroll: true,
            onSuccess: () => toast.success(decision === 'approve' ? 'Approval granted.' : 'Approval rejected.'),
        });
    };

    const copyReference = () => {
        if (!ticket.reference) return;
        void navigator.clipboard.writeText(ticket.reference).then(() => {
            toast.success(`${ticket.reference} copied.`);
        });
    };

    const isWorking = WORKING_STATUSES.includes(ticket.status);

    const approvalBadge = ((): { label: string; variant: StatusVariant } => {
        switch (ticket.approval?.status) {
            case 'approved':
                return { label: 'Approved', variant: 'success' };
            case 'rejected':
                return { label: 'Rejected', variant: 'critical' };
            case 'pending':
                return { label: 'Awaiting approval', variant: 'warning' };
            default:
                return { label: 'Approval needed', variant: 'warning' };
        }
    })();

    const approvalMeta = ((): string => {
        const a = ticket.approval;
        if (!a) return 'A manager must approve this before it can be resolved.';
        if (a.status === 'pending') {
            return `Requested by ${a.requested_by_name ?? 'an agent'}${a.requested_at ? ` · ${absolute(a.requested_at)}` : ''}.`;
        }
        const verb = a.status === 'approved' ? 'Approved' : 'Rejected';
        return `${verb} by ${a.approver_name ?? 'a manager'}${a.decided_at ? ` · ${absolute(a.decided_at)}` : ''}${a.reason ? ` — ${a.reason}` : ''}.`;
    })();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${ticket.reference ?? 'Ticket'} — ${ticket.title}`} />
            {resolving ? (
                <ResolveTicketDialog
                    ticket={{ id: ticket.id, reference: ticket.reference, title: ticket.title }}
                    onClose={() => setResolving(false)}
                />
            ) : null}
            {merging ? (
                <MergeTicketDialog
                    ticket={{ id: ticket.id, reference: ticket.reference, title: ticket.title }}
                    targets={mergeTargets}
                    onClose={() => setMerging(false)}
                />
            ) : null}

            <ItModuleShell>
            <div className="flex flex-col gap-5 p-4 sm:p-6">
                {ticket.merged_into ? (
                    <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-border bg-muted/40 px-4 py-3 text-[13px]">
                        <GitMerge className="h-4 w-4 flex-none text-muted-foreground" />
                        <span className="font-medium">This ticket was merged into</span>
                        <Link
                            href={`/it/tickets/${ticket.merged_into.id}`}
                            className="font-mono font-semibold text-primary hover:underline"
                        >
                            {ticket.merged_into.reference ?? `#${ticket.merged_into.id}`}
                        </Link>
                        <span className="min-w-0 truncate text-muted-foreground">
                            — {ticket.merged_into.title}
                        </span>
                    </div>
                ) : null}
                {ticket.requires_approval ? (
                    <div className="rounded-2xl border border-border bg-muted/30 px-4 py-3.5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                <ShieldCheck className="h-4 w-4 flex-none text-muted-foreground" />
                                <span className="text-[13px] font-semibold">Manager approval</span>
                                <StatusBadge variant={approvalBadge.variant} size="sm">
                                    {approvalBadge.label}
                                </StatusBadge>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {can.requestApproval ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => act(`/it/tickets/${ticket.id}/approvals`, 'Approval requested.')}
                                    >
                                        Request approval
                                    </Button>
                                ) : null}
                                {can.decideApproval ? (
                                    <>
                                        <Button size="sm" onClick={() => decideApproval('approve')}>
                                            <Check className="h-3.5 w-3.5" /> Approve
                                        </Button>
                                        <Button size="sm" variant="outline" onClick={() => decideApproval('reject')}>
                                            <XCircle className="h-3.5 w-3.5" /> Reject
                                        </Button>
                                    </>
                                ) : null}
                            </div>
                        </div>
                        <p className="mt-1.5 text-[12px] text-muted-foreground">{approvalMeta}</p>
                    </div>
                ) : null}
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
                                        aria-label={`Copy reference ${ticket.reference}`}
                                        className="inline-flex items-center gap-1.5 rounded-lg border border-white/20 bg-white/10 px-2 py-0.5 font-mono text-[13px] font-bold tracking-wide text-white/90 hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:outline-none"
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
                                {/* Live SLA countdown — ticks once a minute, tone from the
                                    server verdict, hidden once met/settled. */}
                                <SlaChip ticket={ticket} />
                                {ticket.source === 'email' ? (
                                    <span className="inline-flex items-center gap-1 rounded-lg border border-white/20 bg-white/10 px-2 py-0.5 text-[11px] font-semibold text-white/85">
                                        <Mail className="h-3 w-3" /> via email
                                    </span>
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
                            {can.merge ? (
                                <Button
                                    size="sm"
                                    className="bg-white/15 text-primary-foreground hover:bg-white/25"
                                    onClick={() => setMerging(true)}
                                >
                                    <GitMerge className="h-3.5 w-3.5" /> Merge…
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
                        kbSuggestions={kbSuggestions}
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

                        <TicketLinkedContext
                            recoveredAt={ticket.monitoring_recovered_at}
                            devices={linked_context.devices}
                            alerts={linked_context.alerts}
                            changes={linked_context.changes}
                            problems={linked_context.problems}
                            majorIncidents={linked_context.major_incidents}
                        />

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

                        {/* CSAT (§K) — the requester rates the fix; the score reads
                            back to everyone (agents see it, never edit it). */}
                        {can.rate ? (
                            <RailField label={ticket.csat ? 'Your rating' : 'Rate the fix'}>
                                <CsatRater
                                    ticketId={ticket.id}
                                    score={ticket.csat?.score ?? null}
                                    comment={ticket.csat?.comment ?? ''}
                                />
                            </RailField>
                        ) : ticket.csat ? (
                            <RailField label="Satisfaction">
                                <div className="flex flex-col gap-1">
                                    <CsatStars score={ticket.csat.score} />
                                    {ticket.csat.comment ? (
                                        <p className="text-[12px] text-muted-foreground">
                                            “{ticket.csat.comment}”
                                        </p>
                                    ) : null}
                                </div>
                            </RailField>
                        ) : null}

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
                                className="rounded text-[12px] font-semibold text-primary hover:underline focus-visible:underline focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                            >
                                Copy link to this ticket
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            </ItModuleShell>
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
