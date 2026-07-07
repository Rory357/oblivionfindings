/* eslint-disable no-restricted-syntax -- The IT & Provisioning hub mirrors the
 * gold-standard HR hubs: bespoke table rows, hero stat chips and context-menu
 * triggers built from styled native elements. Every colour is a semantic
 * design token. */
import { HrTabs, useHrTab, type HrTabItem } from '@/components/hr/hr-tabs';
import { useLeaveContextMenu } from '@/components/hr/leave-context-menu';
import {
    ItWizard,
    type AssigneeOption,
    type ItModal,
    type RequestRow,
    type TicketRow,
} from '@/components/it/it-wizards';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
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
import { Head, router } from '@inertiajs/react';
import {
    CheckCircle2,
    Inbox,
    KeyRound,
    Laptop,
    Mail,
    MoreHorizontal,
    Play,
    Plus,
    RotateCcw,
    Server,
    Ticket,
    UserCog,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

/* ------------------------------------------------------------------ */
/*  Props & constants                                                  */
/* ------------------------------------------------------------------ */

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

/** Laravel LengthAwarePaginator as serialised into Inertia props. */
interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
}

/** Server summary — all-time counts feeding hero chips and tab badges. */
interface Summary {
    my: { open: number; waiting: number; resolved_30d: number };
    tickets?: {
        open: number;
        unassigned: number;
        urgent_unassigned: number;
        urgent_open: number;
        at_risk: number;
        breached: number;
        awaiting_reply: number;
        waiting: number;
        resolved_30d: number;
        by_status: Record<string, number>;
        views: Record<string, number>;
    };
    provisioning?: {
        pending: number;
        in_progress: number;
        done_30d: number;
        overdue: number;
        pending_over_7d: number;
    };
}

interface Filters {
    status: string | null;
    type: string | null;
    assignee: number | null;
    ticket_status: string | null;
    ticket_priority: string | null;
    view: string | null;
}

interface MyTicketRow {
    id: number;
    reference: string | null;
    title: string;
    description: string | null;
    category: string;
    priority: string;
    status: string;
    assignee: string | null;
    age: string | null;
    resolved: string | null;
}

interface Props {
    /** Agent-only props — absent from self-service (requester) payloads. */
    requests?: Paginated<RequestRow> | null;
    tickets?: Paginated<TicketRow> | null;
    assignees?: AssigneeOption[];
    filters?: Filters;
    /** The viewer's own tickets — present for anyone with it.request. */
    myTickets: MyTicketRow[];
    summary: Summary;
    can: { view: boolean; manage: boolean; request: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'IT & Provisioning', href: '/it' }];

/** Sentinel — Radix <SelectItem value=""> crashes at runtime. */
const ALL = 'all';

const typeIcon: Record<string, typeof Mail> = {
    account: Mail,
    access: KeyRound,
    equipment: Laptop,
};

const requestStatusVariant: Record<string, StatusVariant> = {
    pending: 'warning',
    in_progress: 'info',
    done: 'success',
    cancelled: 'neutral',
};

const ticketStatusVariant: Record<string, StatusVariant> = {
    open: 'warning',
    in_progress: 'info',
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

const REQUEST_STATUSES = ['pending', 'in_progress', 'done', 'cancelled'];
const REQUEST_TYPES = ['account', 'access', 'equipment', 'other'];
const TICKET_STATUSES = ['open', 'in_progress', 'resolved', 'closed'];
const TICKET_PRIORITIES = ['low', 'normal', 'high', 'urgent'];

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function ItIndex({
    requests,
    tickets,
    assignees = [],
    filters,
    myTickets,
    summary,
    can,
}: Props) {
    const [tab, setTab] = useHrTab(can.view ? 'provisioning' : 'my-tickets');
    const [modal, setModal] = useState<ItModal | null>(null);
    const ctx = useLeaveContextMenu();

    const tabItems: HrTabItem[] = [
        ...(can.view
            ? ([
                  {
                      id: 'provisioning',
                      label: 'Provisioning',
                      icon: Server,
                      tone: 'primary',
                      badge:
                          (summary.provisioning?.pending ?? 0) +
                          (summary.provisioning?.in_progress ?? 0),
                  },
                  {
                      id: 'tickets',
                      label: 'Tickets',
                      icon: Ticket,
                      tone: 'info',
                      badge: summary.tickets?.open ?? 0,
                  },
              ] as HrTabItem[])
            : []),
        ...(can.request
            ? ([
                  {
                      id: 'my-tickets',
                      label: 'My tickets',
                      icon: Inbox,
                      tone: 'success',
                      badge: summary.my.waiting,
                  },
              ] as HrTabItem[])
            : []),
    ];

    const applyFilter = (key: keyof Filters, value: string) =>
        router.get(
            '/it',
            {
                ...Object.fromEntries(
                    Object.entries(filters ?? {}).filter(([, v]) => v !== null && v !== ''),
                ),
                [key]: value === ALL ? undefined : value,
                tab,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    /** Direct row action — surfaces the redirect flash as a toast. */
    const act = (method: 'post' | 'patch', url: string, data: Record<string, string> = {}) => {
        router[method](url, data, {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as { error?: string; success?: string } | undefined;
                if (flash?.error) toast.error(flash.error);
                else if (flash?.success) toast.success(flash.success);
            },
        });
    };

    /* ---------------- row context menus ---------------- */

    const requestMenu = (r: RequestRow) => {
        const open = r.status === 'pending' || r.status === 'in_progress';
        return ctx.open([
            ...(open
                ? ([
                      {
                          kind: 'item' as const,
                          label: 'Fulfil…',
                          icon: CheckCircle2,
                          tone: 'success' as const,
                          onSelect: () => setModal({ type: 'fulfil', request: r }),
                      },
                      {
                          kind: 'item' as const,
                          label: r.assignee ? 'Reassign…' : 'Assign…',
                          icon: UserCog,
                          onSelect: () => setModal({ type: 'assign-request', request: r }),
                      },
                      { kind: 'divider' as const },
                      {
                          kind: 'item' as const,
                          label: 'Cancel request',
                          icon: XCircle,
                          tone: 'critical' as const,
                          onSelect: () => act('post', `/it/provisioning/${r.id}/cancel`),
                      },
                  ] as const)
                : []),
        ]);
    };

    const ticketMenu = (t: TicketRow) => {
        const workable = t.status === 'open' || t.status === 'in_progress';
        return ctx.open([
            ...(workable
                ? [
                      ...(t.status === 'open'
                          ? [
                                {
                                    kind: 'item' as const,
                                    label: 'Start work',
                                    icon: Play,
                                    onSelect: () => act('patch', `/it/tickets/${t.id}`, { status: 'in_progress' }),
                                },
                            ]
                          : []),
                      {
                          kind: 'item' as const,
                          label: t.assignee ? 'Reassign…' : 'Assign…',
                          icon: UserCog,
                          onSelect: () => setModal({ type: 'assign-ticket', ticket: t }),
                      },
                      { kind: 'divider' as const },
                      {
                          kind: 'item' as const,
                          label: 'Resolve',
                          icon: CheckCircle2,
                          tone: 'success' as const,
                          onSelect: () => act('post', `/it/tickets/${t.id}/resolve`),
                      },
                  ]
                : []),
            ...(t.status === 'resolved'
                ? [
                      {
                          kind: 'item' as const,
                          label: 'Close ticket',
                          icon: XCircle,
                          onSelect: () => act('patch', `/it/tickets/${t.id}`, { status: 'closed' }),
                      },
                      {
                          kind: 'item' as const,
                          label: 'Reopen',
                          icon: RotateCcw,
                          onSelect: () => act('patch', `/it/tickets/${t.id}`, { status: 'open' }),
                      },
                  ]
                : []),
            ...(t.status === 'closed'
                ? [
                      {
                          kind: 'item' as const,
                          label: 'Reopen',
                          icon: RotateCcw,
                          onSelect: () => act('patch', `/it/tickets/${t.id}`, { status: 'open' }),
                      },
                  ]
                : []),
        ]);
    };

    /* ---------------- render ---------------- */

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="IT & Provisioning" />
            {ctx.element}
            <ItWizard modal={modal} assignees={assignees} onClose={() => setModal(null)} />

            <div className="flex flex-col gap-5 p-4 sm:p-6">
                {/* Hero */}
                <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 px-9 py-8 text-primary-foreground">
                    <div className="flex flex-wrap items-center justify-between gap-5">
                        <div className="flex items-center gap-4">
                            <span className="grid h-[54px] w-[54px] flex-none place-items-center rounded-2xl border border-white/20 bg-white/15">
                                <Server className="h-6 w-6" />
                            </span>
                            <div>
                                <h1 className="text-[28px] leading-[1.05] font-bold tracking-tight">
                                    IT &amp; Provisioning
                                </h1>
                                <p className="mt-1 text-[13px] font-medium text-white/75">
                                    Account, access &amp; equipment requests — and the IT helpdesk queue.
                                </p>
                            </div>
                        </div>
                        {can.manage || can.request ? (
                            <Button
                                onClick={() => setModal({ type: 'ticket' })}
                                className="bg-white/15 text-primary-foreground hover:bg-white/25"
                            >
                                <Plus className="h-4 w-4" />{' '}
                                {can.manage ? 'Log ticket' : 'Raise a ticket'}
                            </Button>
                        ) : null}
                    </div>
                    <div className="mt-6 flex flex-wrap gap-2.5">
                        {(can.view && summary.tickets && summary.provisioning
                            ? [
                                  { label: 'Pending requests', value: summary.provisioning.pending },
                                  { label: 'In progress', value: summary.provisioning.in_progress },
                                  { label: 'Fulfilled · 30d', value: summary.provisioning.done_30d },
                                  { label: 'Open tickets', value: summary.tickets.open },
                                  { label: 'Unassigned', value: summary.tickets.unassigned },
                                  { label: 'Urgent open', value: summary.tickets.urgent_open },
                              ]
                            : [
                                  { label: 'My open tickets', value: summary.my.open },
                                  { label: 'Waiting on me', value: summary.my.waiting },
                                  { label: 'Resolved · 30d', value: summary.my.resolved_30d },
                              ]
                        ).map((s) => (
                            <div
                                key={s.label}
                                className="rounded-xl border border-white/15 bg-white/10 px-3.5 py-2"
                            >
                                <div className="text-[20px] leading-none font-bold">{s.value}</div>
                                <div className="mt-1 text-[11px] font-semibold tracking-wide text-white/70 uppercase">
                                    {s.label}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <HrTabs value={tab} onChange={setTab} items={tabItems} ariaLabel="IT views" />

                {/* ── Provisioning queue (agents) ── */}
                {can.view && tab === 'provisioning' && (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <FilterSelect
                                ariaLabel="Filter by status"
                                value={filters?.status ?? ALL}
                                onChange={(v) => applyFilter('status', v)}
                                allLabel="All statuses"
                                options={REQUEST_STATUSES}
                            />
                            <FilterSelect
                                ariaLabel="Filter by type"
                                value={filters?.type ?? ALL}
                                onChange={(v) => applyFilter('type', v)}
                                allLabel="All types"
                                options={REQUEST_TYPES}
                            />
                            <AssigneeFilter
                                value={filters?.assignee != null ? String(filters.assignee) : ALL}
                                onChange={(v) => applyFilter('assignee', v)}
                                assignees={assignees}
                            />
                        </div>

                        <div className="overflow-hidden rounded-2xl border border-border bg-card">
                            <div className="grid grid-cols-[2fr_2fr_1.5fr_1fr_0.8fr_100px] gap-3 border-b border-border bg-muted px-4.5 py-2.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                                <span>Employee</span>
                                <span>Item</span>
                                <span>Assignee</span>
                                <span>Status</span>
                                <span>Raised</span>
                                <span />
                            </div>
                            {(requests?.data ?? []).map((r) => {
                                const Icon = typeIcon[r.type] ?? Server;
                                const actionable =
                                    can.manage && (r.status === 'pending' || r.status === 'in_progress');
                                return (
                                    <div
                                        key={r.id}
                                        onContextMenu={actionable ? requestMenu(r) : undefined}
                                        className="grid grid-cols-[2fr_2fr_1.5fr_1fr_0.8fr_100px] items-center gap-3 border-b border-border/55 px-4.5 py-3 last:border-0"
                                    >
                                        <div className="min-w-0">
                                            <div className="truncate text-[13.5px] font-semibold">
                                                {r.employee.name}
                                            </div>
                                            <div className="truncate text-[11.5px] text-muted-foreground">
                                                {r.from_onboarding
                                                    ? `Onboarding${r.employee.role ? ` · ${r.employee.role}` : ''}`
                                                    : (r.employee.role ?? '—')}
                                            </div>
                                        </div>
                                        <div className="flex min-w-0 items-center gap-2">
                                            <span className="grid h-7 w-7 flex-none place-items-center rounded-lg bg-accent text-primary">
                                                <Icon className="h-3.5 w-3.5" />
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block truncate text-[13px]">{r.item}</span>
                                                {r.external_ref ? (
                                                    <span className="block truncate text-[11px] text-muted-foreground">
                                                        Ref: {r.external_ref}
                                                    </span>
                                                ) : null}
                                            </span>
                                        </div>
                                        <span className="truncate text-[12.5px] text-muted-foreground">
                                            {r.assignee?.name ?? 'Unassigned'}
                                        </span>
                                        <span>
                                            <StatusBadge
                                                variant={requestStatusVariant[r.status] ?? 'neutral'}
                                                size="sm"
                                            >
                                                {label(r.status)}
                                            </StatusBadge>
                                        </span>
                                        <span className="text-[12px] text-muted-foreground">
                                            {r.status === 'done' ? (r.fulfilled ?? r.created ?? '—') : (r.created ?? '—')}
                                        </span>
                                        <span className="flex items-center justify-end gap-1.5">
                                            {actionable ? (
                                                <>
                                                    <button
                                                        type="button"
                                                        onClick={() => setModal({ type: 'fulfil', request: r })}
                                                        className="rounded-lg border border-border px-2.5 py-1.5 text-[12px] font-semibold transition-colors hover:border-primary/50 hover:text-primary"
                                                    >
                                                        Fulfil
                                                    </button>
                                                    <button
                                                        type="button"
                                                        aria-label={`Actions for ${r.item}`}
                                                        onClick={requestMenu(r)}
                                                        className="grid h-7 w-7 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                                    >
                                                        <MoreHorizontal className="h-4 w-4" />
                                                    </button>
                                                </>
                                            ) : null}
                                        </span>
                                    </div>
                                );
                            })}
                            {(requests?.data ?? []).length === 0 ? (
                                <EmptyState
                                    icon={Inbox}
                                    title="No provisioning requests"
                                    blurb="Onboarding IT tasks (accounts & access) land here automatically when a checklist is generated."
                                />
                            ) : null}
                        </div>
                        {requests ? (
                            <LaravelPagination links={requests.links} lastPage={requests.last_page} />
                        ) : null}
                    </>
                )}

                {/* ── Ticket queue (agents) ── */}
                {can.view && tab === 'tickets' && (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <FilterSelect
                                ariaLabel="Filter by ticket status"
                                value={filters?.ticket_status ?? ALL}
                                onChange={(v) => applyFilter('ticket_status', v)}
                                allLabel="All statuses"
                                options={TICKET_STATUSES}
                            />
                            <FilterSelect
                                ariaLabel="Filter by priority"
                                value={filters?.ticket_priority ?? ALL}
                                onChange={(v) => applyFilter('ticket_priority', v)}
                                allLabel="All priorities"
                                options={TICKET_PRIORITIES}
                            />
                            <AssigneeFilter
                                value={filters?.assignee != null ? String(filters.assignee) : ALL}
                                onChange={(v) => applyFilter('assignee', v)}
                                assignees={assignees}
                            />
                            {can.manage ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="ml-auto"
                                    onClick={() => setModal({ type: 'ticket' })}
                                >
                                    <Plus className="h-3.5 w-3.5" /> Log ticket
                                </Button>
                            ) : null}
                        </div>

                        <div className="overflow-hidden rounded-2xl border border-border bg-card">
                            <div className="grid grid-cols-[3fr_1.3fr_1.3fr_0.9fr_1fr_0.7fr_44px] gap-3 border-b border-border bg-muted px-4.5 py-2.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                                <span>Ticket</span>
                                <span>Requester</span>
                                <span>Assignee</span>
                                <span>Priority</span>
                                <span>Status</span>
                                <span>Age</span>
                                <span />
                            </div>
                            {(tickets?.data ?? []).map((t) => (
                                <div
                                    key={t.id}
                                    onContextMenu={can.manage ? ticketMenu(t) : undefined}
                                    className="grid grid-cols-[3fr_1.3fr_1.3fr_0.9fr_1fr_0.7fr_44px] items-center gap-3 border-b border-border/55 px-4.5 py-3 last:border-0"
                                >
                                    <div className="flex min-w-0 items-center gap-2">
                                        <span className="grid h-7 w-7 flex-none place-items-center rounded-lg bg-accent text-primary">
                                            <Ticket className="h-3.5 w-3.5" />
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block truncate text-[13px] font-semibold">
                                                {t.title}
                                            </span>
                                            <span className="block truncate text-[11px] text-muted-foreground">
                                                {t.reference ? `${t.reference} · ` : ''}
                                                {label(t.category)}
                                                {t.description ? ` · ${t.description}` : ''}
                                            </span>
                                        </span>
                                    </div>
                                    <span className="truncate text-[12.5px] text-muted-foreground">
                                        {t.requester}
                                    </span>
                                    <span className="truncate text-[12.5px] text-muted-foreground">
                                        {t.assignee?.name ?? 'Unassigned'}
                                    </span>
                                    <span>
                                        <StatusBadge variant={priorityVariant[t.priority] ?? 'neutral'} size="sm">
                                            {label(t.priority)}
                                        </StatusBadge>
                                    </span>
                                    <span>
                                        <StatusBadge variant={ticketStatusVariant[t.status] ?? 'neutral'} size="sm">
                                            {label(t.status)}
                                        </StatusBadge>
                                    </span>
                                    <span className="text-[12px] text-muted-foreground">{t.age ?? '—'}</span>
                                    <span className="flex justify-end">
                                        {can.manage ? (
                                            <button
                                                type="button"
                                                aria-label={`Actions for ${t.title}`}
                                                onClick={ticketMenu(t)}
                                                className="grid h-7 w-7 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                            >
                                                <MoreHorizontal className="h-4 w-4" />
                                            </button>
                                        ) : null}
                                    </span>
                                </div>
                            ))}
                            {(tickets?.data ?? []).length === 0 ? (
                                <EmptyState
                                    icon={Ticket}
                                    title="No tickets"
                                    blurb={
                                        can.manage
                                            ? 'Log the first helpdesk ticket with the button above.'
                                            : 'The helpdesk queue is clear.'
                                    }
                                />
                            ) : null}
                        </div>
                        {tickets ? (
                            <LaravelPagination links={tickets.links} lastPage={tickets.last_page} />
                        ) : null}
                    </>
                )}

                {/* ── My tickets (everyone with it.request) ── */}
                {can.request && tab === 'my-tickets' && (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="text-[12.5px] text-muted-foreground">
                                Tickets you’ve raised — IT sees new ones instantly.
                            </p>
                            <Button
                                size="sm"
                                variant="outline"
                                className="ml-auto"
                                onClick={() => setModal({ type: 'ticket' })}
                            >
                                <Plus className="h-3.5 w-3.5" /> Raise a ticket
                            </Button>
                        </div>

                        <div className="overflow-hidden rounded-2xl border border-border bg-card">
                            <div className="grid grid-cols-[3fr_1.3fr_0.9fr_1fr_0.8fr] gap-3 border-b border-border bg-muted px-4.5 py-2.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                                <span>Ticket</span>
                                <span>Assignee</span>
                                <span>Priority</span>
                                <span>Status</span>
                                <span>Raised</span>
                            </div>
                            {myTickets.map((t) => (
                                <div
                                    key={t.id}
                                    className="grid grid-cols-[3fr_1.3fr_0.9fr_1fr_0.8fr] items-center gap-3 border-b border-border/55 px-4.5 py-3 last:border-0"
                                >
                                    <div className="flex min-w-0 items-center gap-2">
                                        <span className="grid h-7 w-7 flex-none place-items-center rounded-lg bg-accent text-primary">
                                            <Ticket className="h-3.5 w-3.5" />
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block truncate text-[13px] font-semibold">
                                                {t.title}
                                            </span>
                                            <span className="block truncate text-[11px] text-muted-foreground">
                                                {t.reference ? `${t.reference} · ` : ''}
                                                {label(t.category)}
                                                {t.description ? ` · ${t.description}` : ''}
                                            </span>
                                        </span>
                                    </div>
                                    <span className="truncate text-[12.5px] text-muted-foreground">
                                        {t.assignee ?? 'With IT for triage'}
                                    </span>
                                    <span>
                                        <StatusBadge
                                            variant={priorityVariant[t.priority] ?? 'neutral'}
                                            size="sm"
                                        >
                                            {label(t.priority)}
                                        </StatusBadge>
                                    </span>
                                    <span>
                                        <StatusBadge
                                            variant={ticketStatusVariant[t.status] ?? 'neutral'}
                                            size="sm"
                                        >
                                            {label(t.status)}
                                        </StatusBadge>
                                    </span>
                                    <span className="text-[12px] text-muted-foreground">
                                        {t.age ?? '—'}
                                    </span>
                                </div>
                            ))}
                            {myTickets.length === 0 ? (
                                <EmptyState
                                    icon={Inbox}
                                    title="No tickets yet"
                                    blurb="Broken phone? Locked out? Raise it here — IT sees it instantly and you can track progress on this tab."
                                />
                            ) : null}
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Bits                                                               */
/* ------------------------------------------------------------------ */

function FilterSelect({
    ariaLabel,
    value,
    onChange,
    allLabel,
    options,
}: {
    ariaLabel: string;
    value: string;
    onChange: (v: string) => void;
    allLabel: string;
    options: string[];
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger className="h-8 w-[160px]" aria-label={ariaLabel}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{allLabel}</SelectItem>
                {options.map((o) => (
                    <SelectItem key={o} value={o}>
                        {label(o)}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function AssigneeFilter({
    value,
    onChange,
    assignees,
}: {
    value: string;
    onChange: (v: string) => void;
    assignees: AssigneeOption[];
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger className="h-8 w-[180px]" aria-label="Filter by assignee">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>All assignees</SelectItem>
                {assignees.map((a) => (
                    <SelectItem key={a.id} value={String(a.id)}>
                        {a.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function EmptyState({
    icon: Icon,
    title,
    blurb,
}: {
    icon: typeof Inbox;
    title: string;
    blurb: string;
}) {
    return (
        <div className="flex flex-col items-center gap-2 px-6 py-14 text-center">
            <span className="grid h-12 w-12 place-items-center rounded-2xl bg-muted text-muted-foreground">
                <Icon className="h-6 w-6" />
            </span>
            <div className="text-[14px] font-bold">{title}</div>
            <p className="max-w-sm text-[12.5px] leading-relaxed text-muted-foreground">{blurb}</p>
        </div>
    );
}
