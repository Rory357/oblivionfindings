/* eslint-disable no-restricted-syntax -- The IT & Provisioning hub mirrors the
 * gold-standard HR hubs: bespoke table rows, hero stat chips and context-menu
 * triggers built from styled native elements. Every colour is a semantic
 * design token. */
import { HrTabs, useHrTab, type HrTabItem } from '@/components/hr/hr-tabs';
import { useLeaveContextMenu } from '@/components/hr/leave-context-menu';
import {
    ItWizard,
    type AssigneeOption,
    type EmployeeOption,
    type ItModal,
    type RequestRow,
    type SlaPolicyGrid,
    type TicketRow,
} from '@/components/it/it-wizards';
import { ItHero } from '@/components/it/it-hero';
import { ItOverview, type OverviewPayload } from '@/components/it/it-overview';
import { SlaChip } from '@/components/it/sla-chip';
import { TicketDrawer } from '@/components/it/ticket-drawer';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    ChevronDown,
    ChevronsUpDown,
    ChevronUp,
    Download,
    Inbox,
    KeyRound,
    Laptop,
    LayoutDashboard,
    Mail,
    MoreHorizontal,
    Play,
    Plus,
    RotateCcw,
    Search,
    Server,
    Ticket,
    Timer,
    UserCog,
    X,
    XCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';
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
        met_30d: number;
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
    ticket_category: string | null;
    sla: string | null;
    view: string | null;
    q: string | null;
    from: string | null;
    to: string | null;
    sort: string | null;
    dir: string | null;
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
    /** Tenant employee profiles for the manual provisioning-request picker. */
    employeeOptions?: EmployeeOption[];
    filters?: Filters;
    /** §F1 Overview board — KPIs + needs-attention lanes (agents only). */
    overview?: OverviewPayload;
    /** Effective SLA grid — present only for admins (the policy editor). */
    slaPolicies?: SlaPolicyGrid | null;
    /** The viewer's own tickets — present for anyone with it.request. */
    myTickets: MyTicketRow[];
    summary: Summary;
    can: { view: boolean; manage: boolean; request: boolean; edit_sla?: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'IT & Provisioning', href: '/it' }];

/** Sentinel — Radix <SelectItem value=""> crashes at runtime. */
const ALL = 'all';
/** Bulk-assign sentinel for "remove the assignee" (empty value is illegal). */
const UNASSIGN = 'unassign';

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

/** Today as `YYYY-MM-DD` for lexical overdue comparison against a due_date. */
const todayISO = () => new Date().toISOString().slice(0, 10);

/** A `YYYY-MM-DD` due date as a compact en-NZ label ("8 Jul"). */
const formatDue = (d: string) =>
    new Date(`${d}T00:00:00`).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });

const REQUEST_STATUSES = ['pending', 'in_progress', 'done', 'cancelled'];
const REQUEST_TYPES = ['account', 'access', 'equipment', 'other'];
const TICKET_STATUSES = ['open', 'in_progress', 'waiting', 'resolved', 'closed'];
const TICKET_PRIORITIES = ['low', 'normal', 'high', 'urgent'];
const TICKET_CATEGORIES = ['hardware', 'account', 'network', 'other'];
const SLA_STATES = ['ok', 'at_risk', 'breached', 'met'];

/** Saved views — server `view` param; counts come from `summary.tickets.views`
 *  keyed identically. Order mirrors the triage funnel (open → attention → done). */
const TICKET_VIEWS: { key: string; label: string }[] = [
    { key: 'all_open', label: 'All open' },
    { key: 'unassigned', label: 'Unassigned' },
    { key: 'mine', label: 'Mine' },
    { key: 'breaching', label: 'Breaching soon' },
    { key: 'breached', label: 'Breached' },
    { key: 'awaiting_reply', label: 'Awaiting reply' },
    { key: 'waiting', label: 'Waiting on requester' },
    { key: 'recently_resolved', label: 'Recently resolved' },
];

/** localStorage key for an agent's default tickets view (§F2). */
const TICKETS_VIEW_KEY = 'it.ticketsView';

const readStoredView = (): string | null => {
    try {
        return localStorage.getItem(TICKETS_VIEW_KEY);
    } catch {
        return null;
    }
};

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function ItIndex({
    requests,
    tickets,
    assignees = [],
    employeeOptions = [],
    filters,
    overview,
    slaPolicies,
    myTickets,
    summary,
    can,
}: Props) {
    const [tab, setTab] = useHrTab(can.view ? 'overview' : 'my-tickets');
    const [modal, setModal] = useState<ItModal | null>(null);
    const [peekId, setPeekId] = useState<number | null>(null);
    const ctx = useLeaveContextMenu();

    /** Row click: quick-peek drawer; Ctrl/⌘-click or double-click: full page. */
    const openTicket = (id: number, e?: React.MouseEvent) => {
        if (e && (e.ctrlKey || e.metaKey)) {
            router.visit(`/it/tickets/${id}`);
            return;
        }
        setPeekId(id);
    };

    const tabItems: HrTabItem[] = [
        ...(can.view
            ? ([
                  {
                      id: 'overview',
                      label: 'Overview',
                      icon: LayoutDashboard,
                      tone: 'primary',
                  },
                  {
                      id: 'tickets',
                      label: 'Tickets',
                      icon: Ticket,
                      tone: 'info',
                      badge: summary.tickets?.open ?? 0,
                  },
                  {
                      id: 'provisioning',
                      label: 'Provisioning',
                      icon: Server,
                      tone: 'primary',
                      badge:
                          (summary.provisioning?.pending ?? 0) +
                          (summary.provisioning?.in_progress ?? 0),
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

    /** Merge a param patch onto the current filters and reload the queue. A
     *  filter change drops the page cursor (not in `filters`) → back to page 1. */
    const navigate = (patch: Record<string, string | undefined>) =>
        router.get(
            '/it',
            {
                ...Object.fromEntries(
                    Object.entries(filters ?? {}).filter(([, v]) => v !== null && v !== ''),
                ),
                ...patch,
                tab,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const applyFilter = (key: keyof Filters, value: string) =>
        navigate({ [key]: value === ALL || value === '' ? undefined : value });

    /** Saved-view chip: remember it as the agent's default, then filter. */
    const applyView = (key: string) => {
        try {
            localStorage.setItem(TICKETS_VIEW_KEY, key);
        } catch {
            /* private mode — persistence is best-effort */
        }
        navigate({ view: key });
    };

    /** Sortable header: first click → desc, re-click toggles asc⇄desc. */
    const applySort = (col: string) => {
        const active = filters?.sort === col;
        const dir = !active ? 'desc' : filters?.dir === 'asc' ? 'desc' : 'asc';
        navigate({ sort: col, dir });
    };

    // Debounced free-text search over reference / title / requester.
    const [search, setSearch] = useState(filters?.q ?? '');
    useEffect(() => {
        if ((filters?.q ?? '') === search) return;
        const timer = setTimeout(() => navigate({ q: search.trim() === '' ? undefined : search }), 350);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // Land an agent on their remembered view when they open Tickets with none set
    // (a hero deep-link carrying ?view= always wins).
    useEffect(() => {
        if (!can.view || tab !== 'tickets' || filters?.view) return;
        const stored = readStoredView();
        if (stored) navigate({ view: stored });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tab]);

    const ticketFiltersActive = Boolean(
        filters?.q ||
            filters?.view ||
            filters?.ticket_status ||
            filters?.ticket_priority ||
            filters?.ticket_category ||
            filters?.sla ||
            filters?.assignee ||
            filters?.from ||
            filters?.to,
    );

    /** Wipe every tickets filter (and the search box) back to the full queue. */
    const clearTicketFilters = () => {
        setSearch('');
        router.get('/it', { tab: 'tickets' }, { preserveState: true, preserveScroll: true, replace: true });
    };

    /* ---------------- bulk selection (§F2 tickets · §H provisioning) ---------------- */
    // Both queues share one per-page selection hook (useRowSelection, below).
    // Only one tab is visible at a time, so the busy flag is shared.

    const ticketSel = useRowSelection((tickets?.data ?? []).map((t) => t.id));
    const reqSel = useRowSelection((requests?.data ?? []).map((r) => r.id));
    const [confirmBulkClose, setConfirmBulkClose] = useState(false);
    const [confirmBulkFulfil, setConfirmBulkFulfil] = useState(false);
    const [bulkBusy, setBulkBusy] = useState(false);

    /** POST a selection to a bulk endpoint; surface the flash, then clear it. */
    const runBulkTo = (
        url: string,
        sel: ReturnType<typeof useRowSelection>,
        payload: Record<string, unknown>,
    ) => {
        if (sel.selected.size === 0) return;
        setBulkBusy(true);
        router.post(
            url,
            { ids: [...sel.selected], ...payload },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const flash = page.props.flash as { error?: string; success?: string } | undefined;
                    if (flash?.error) toast.error(flash.error);
                    else if (flash?.success) toast.success(flash.success);
                    sel.clear();
                },
                onFinish: () => setBulkBusy(false),
            },
        );
    };

    const runBulk = (payload: Record<string, unknown>) => runBulkTo('/it/tickets/bulk', ticketSel, payload);
    const runProvisioningBulk = (payload: Record<string, unknown>) =>
        runBulkTo('/it/provisioning/bulk', reqSel, payload);

    /** CSV export of the provisioning queue, carrying the active filters so the
     *  download matches what the agent is looking at (streamed, agent-only). */
    const provisioningExportUrl = () => {
        const params = new URLSearchParams();
        if (filters?.status) params.set('status', filters.status);
        if (filters?.type) params.set('type', filters.type);
        if (filters?.assignee != null) params.set('assignee', String(filters.assignee));
        const qs = params.toString();
        return `/it/provisioning/export${qs ? `?${qs}` : ''}`;
    };

    // Bulk select is agent-only (it.manage) — the checkbox column and action
    // bar only exist for people who can mutate. Each grid gains a leading
    // 36px checkbox track when it does.
    const ticketGridCols = can.manage
        ? 'grid-cols-[36px_3fr_1.2fr_1.2fr_0.9fr_1fr_1.5fr_0.6fr_44px]'
        : 'grid-cols-[3fr_1.2fr_1.2fr_0.9fr_1fr_1.5fr_0.6fr_44px]';
    const reqGridCols = can.manage
        ? 'grid-cols-[36px_1.8fr_1.8fr_1.2fr_0.8fr_1fr_0.9fr_88px]'
        : 'grid-cols-[1.8fr_1.8fr_1.2fr_0.8fr_1fr_0.9fr_88px]';

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
            // Available on any request — a fulfilled item can still arrive broken.
            {
                kind: 'item' as const,
                label: 'Raise linked ticket',
                icon: Ticket,
                onSelect: () => setModal({ type: 'ticket', provisioning: { id: r.id, item: r.item } }),
            },
            ...(r.linked_ticket
                ? [
                      {
                          kind: 'item' as const,
                          label: `Open ${r.linked_ticket.reference ?? 'linked ticket'}`,
                          icon: Inbox,
                          onSelect: () => setPeekId(r.linked_ticket!.id),
                      },
                  ]
                : []),
            ...(open
                ? ([
                      { kind: 'divider' as const },
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
        const workable =
            t.status === 'open' || t.status === 'in_progress' || t.status === 'waiting';
        return ctx.open([
            {
                kind: 'item' as const,
                label: 'Open',
                icon: Ticket,
                onSelect: () => router.visit(`/it/tickets/${t.id}`),
            },
            {
                kind: 'item' as const,
                label: 'Quick peek',
                icon: Inbox,
                onSelect: () => setPeekId(t.id),
            },
            { kind: 'divider' as const },
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
                          label: 'Resolve…',
                          icon: CheckCircle2,
                          tone: 'success' as const,
                          onSelect: () =>
                              setModal({
                                  type: 'resolve',
                                  ticket: { id: t.id, reference: t.reference, title: t.title },
                              }),
                      },
                  ]
                : []),
            ...(t.status === 'resolved'
                ? [
                      {
                          kind: 'item' as const,
                          label: 'Close ticket',
                          icon: XCircle,
                          onSelect: () => act('post', `/it/tickets/${t.id}/close`),
                      },
                      {
                          kind: 'item' as const,
                          label: 'Reopen',
                          icon: RotateCcw,
                          onSelect: () => act('post', `/it/tickets/${t.id}/reopen`),
                      },
                  ]
                : []),
            ...(t.status === 'closed'
                ? [
                      {
                          kind: 'item' as const,
                          label: 'Reopen',
                          icon: RotateCcw,
                          onSelect: () => act('post', `/it/tickets/${t.id}/reopen`),
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
            <ItWizard
                modal={modal}
                assignees={assignees}
                employeeOptions={employeeOptions}
                slaPolicies={slaPolicies}
                onClose={() => setModal(null)}
            />
            <TicketDrawer ticketId={peekId} onClose={() => setPeekId(null)} />

            <AlertDialog open={confirmBulkClose} onOpenChange={setConfirmBulkClose}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Close {ticketSel.selected.size} ticket{ticketSel.selected.size === 1 ? '' : 's'}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Closed tickets leave the working queue. Requesters can still reopen within seven days.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep open</AlertDialogCancel>
                        <AlertDialogAction onClick={() => runBulk({ action: 'close' })}>
                            Close tickets
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog open={confirmBulkFulfil} onOpenChange={setConfirmBulkFulfil}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Fulfil {reqSel.selected.size} request{reqSel.selected.size === 1 ? '' : 's'}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Each request is marked done and any linked onboarding task is completed. This can’t be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={() => runProvisioningBulk({ action: 'fulfil' })}>
                            Fulfil requests
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <div className="flex flex-col gap-5 p-4 sm:p-6">
                <ItHero
                    summary={summary}
                    can={can}
                    onRaise={() => setModal({ type: 'raise' })}
                    onLog={() => setModal({ type: 'ticket' })}
                />

                <HrTabs value={tab} onChange={setTab} items={tabItems} ariaLabel="IT views" />

                {/* ── Overview (agents) ── */}
                {can.view && tab === 'overview' && overview && summary.tickets && (
                    <ItOverview
                        overview={overview}
                        kpis={{
                            open: summary.tickets.open,
                            unassigned: summary.tickets.unassigned,
                            at_risk: summary.tickets.at_risk,
                            breached: summary.tickets.breached,
                        }}
                        onOpenTicket={(id) => setPeekId(id)}
                    />
                )}

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
                            <div className="ml-auto flex items-center gap-2">
                                <Button asChild size="sm" variant="outline">
                                    <a
                                        href={provisioningExportUrl()}
                                        aria-label="Export the provisioning queue as CSV"
                                    >
                                        <Download className="h-3.5 w-3.5" /> Export CSV
                                    </a>
                                </Button>
                                {can.manage ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setModal({ type: 'new-request' })}
                                    >
                                        <Plus className="h-3.5 w-3.5" /> New request
                                    </Button>
                                ) : null}
                            </div>
                        </div>

                        {/* Bulk action bar — appears when requests are selected */}
                        {can.manage && reqSel.selected.size > 0 ? (
                            <div className="sticky top-2 z-20 flex flex-wrap items-center gap-2 rounded-xl border border-primary/40 bg-primary/10 px-3 py-2 shadow-sm">
                                <span className="text-[12.5px] font-semibold text-foreground">
                                    {reqSel.selected.size} selected
                                </span>
                                <span className="mx-1 h-5 w-px bg-border" aria-hidden />
                                <Select
                                    value=""
                                    onValueChange={(v) =>
                                        runProvisioningBulk({ action: 'assign', assigned_to_user_id: Number(v) })
                                    }
                                >
                                    <SelectTrigger className="h-8 w-[160px]" aria-label="Assign selected requests to">
                                        <SelectValue placeholder="Assign to…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {assignees.map((a) => (
                                            <SelectItem key={a.id} value={String(a.id)}>
                                                {a.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={bulkBusy}
                                    onClick={() => setConfirmBulkFulfil(true)}
                                >
                                    <CheckCircle2 className="h-3.5 w-3.5" /> Fulfil
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="ml-auto"
                                    onClick={() => reqSel.clear()}
                                >
                                    Clear
                                </Button>
                            </div>
                        ) : null}

                        <div className="overflow-hidden rounded-2xl border border-border bg-card">
                            <div className={`grid ${reqGridCols} gap-3 border-b border-border bg-muted px-4.5 py-2.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase`}>
                                {can.manage ? (
                                    <span className="flex items-center">
                                        <Checkbox
                                            checked={
                                                reqSel.allOnPage
                                                    ? true
                                                    : reqSel.someOnPage
                                                      ? 'indeterminate'
                                                      : false
                                            }
                                            onCheckedChange={(v) => reqSel.toggleAll(v === true)}
                                            aria-label="Select all requests on this page"
                                        />
                                    </span>
                                ) : null}
                                <span>Employee</span>
                                <span>Item</span>
                                <span>Assignee</span>
                                <span>Priority</span>
                                <span>Status</span>
                                <span>Due</span>
                                <span />
                            </div>
                            {(requests?.data ?? []).map((r) => {
                                const Icon = typeIcon[r.type] ?? Server;
                                const actionable =
                                    can.manage && (r.status === 'pending' || r.status === 'in_progress');
                                const overdue =
                                    r.due_date != null &&
                                    r.status !== 'done' &&
                                    r.status !== 'cancelled' &&
                                    r.due_date < todayISO();
                                return (
                                    <div
                                        key={r.id}
                                        onContextMenu={can.manage ? requestMenu(r) : undefined}
                                        className={`grid ${reqGridCols} items-center gap-3 border-b border-border/55 px-4.5 py-3 last:border-0 ${reqSel.selected.has(r.id) ? 'bg-primary/5' : overdue ? 'bg-[color:var(--status-critical)]/5' : ''}`}
                                    >
                                        {can.manage ? (
                                            <span className="flex items-center">
                                                <Checkbox
                                                    checked={reqSel.selected.has(r.id)}
                                                    onCheckedChange={(v) => reqSel.toggle(r.id, v === true)}
                                                    aria-label={`Select ${r.item}`}
                                                />
                                            </span>
                                        ) : null}
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
                                                {r.linked_ticket ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => setPeekId(r.linked_ticket!.id)}
                                                        className="mt-0.5 inline-flex items-center gap-1 rounded-md bg-accent px-1.5 py-0.5 text-[10.5px] font-semibold text-primary transition-colors hover:bg-primary/10"
                                                    >
                                                        <Ticket className="h-3 w-3" />
                                                        {r.linked_ticket.reference ?? 'Linked ticket'}
                                                        {r.linked_ticket_count > 1 ? ` +${r.linked_ticket_count - 1}` : ''}
                                                    </button>
                                                ) : null}
                                            </span>
                                        </div>
                                        <span className="truncate text-[12.5px] text-muted-foreground">
                                            {r.assignee?.name ?? 'Unassigned'}
                                        </span>
                                        <span>
                                            <StatusBadge variant={priorityVariant[r.priority] ?? 'neutral'} size="sm">
                                                {label(r.priority)}
                                            </StatusBadge>
                                        </span>
                                        <span className="flex flex-col items-start gap-0.5">
                                            <StatusBadge
                                                variant={requestStatusVariant[r.status] ?? 'neutral'}
                                                size="sm"
                                            >
                                                {label(r.status)}
                                            </StatusBadge>
                                            <span className="text-[10.5px] text-muted-foreground">
                                                {r.status === 'done'
                                                    ? r.fulfilled
                                                        ? `Done ${r.fulfilled}`
                                                        : ''
                                                    : r.created
                                                      ? `Raised ${r.created}`
                                                      : ''}
                                            </span>
                                        </span>
                                        <span
                                            className={
                                                overdue
                                                    ? 'text-[12px] font-semibold text-[color:var(--status-critical)]'
                                                    : 'text-[12px] text-muted-foreground'
                                            }
                                        >
                                            {r.due_date ? formatDue(r.due_date) : '—'}
                                            {overdue ? (
                                                <span className="block text-[10px] font-semibold">Overdue</span>
                                            ) : null}
                                        </span>
                                        <span className="flex items-center justify-end gap-1.5">
                                            {actionable ? (
                                                <button
                                                    type="button"
                                                    onClick={() => setModal({ type: 'fulfil', request: r })}
                                                    className="rounded-lg border border-border px-2.5 py-1.5 text-[12px] font-semibold transition-colors hover:border-primary/50 hover:text-primary"
                                                >
                                                    Fulfil
                                                </button>
                                            ) : null}
                                            {can.manage ? (
                                                <button
                                                    type="button"
                                                    aria-label={`Actions for ${r.item}`}
                                                    onClick={requestMenu(r)}
                                                    className="grid h-7 w-7 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                                >
                                                    <MoreHorizontal className="h-4 w-4" />
                                                </button>
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
                        {/* Saved views — counts from the all-time summary */}
                        <div className="flex flex-wrap items-center gap-1.5">
                            {TICKET_VIEWS.map((v) => {
                                const activeView = filters?.view === v.key;
                                const count = summary.tickets?.views[v.key] ?? 0;
                                return (
                                    <button
                                        key={v.key}
                                        type="button"
                                        aria-pressed={activeView}
                                        onClick={() => applyView(v.key)}
                                        className={
                                            activeView
                                                ? 'inline-flex items-center gap-1.5 rounded-full border border-primary bg-primary px-3 py-1 text-[12px] font-semibold text-primary-foreground'
                                                : 'inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-3 py-1 text-[12px] font-medium text-muted-foreground transition-colors hover:border-primary/50 hover:text-foreground'
                                        }
                                    >
                                        {v.label}
                                        <span
                                            className={
                                                activeView
                                                    ? 'rounded-full bg-white/20 px-1.5 text-[11px] font-bold tabular-nums'
                                                    : 'rounded-full bg-muted px-1.5 text-[11px] font-bold tabular-nums text-muted-foreground'
                                            }
                                        >
                                            {count}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>

                        {/* Toolbar — search + filters */}
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                                <input
                                    type="search"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search reference, title, requester…"
                                    aria-label="Search tickets"
                                    className="h-8 w-[248px] rounded-md border border-border bg-card pr-7 pl-8 text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                                {search ? (
                                    <button
                                        type="button"
                                        onClick={() => setSearch('')}
                                        aria-label="Clear search"
                                        className="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                    >
                                        <X className="h-3.5 w-3.5" />
                                    </button>
                                ) : null}
                            </div>
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
                            <FilterSelect
                                ariaLabel="Filter by category"
                                value={filters?.ticket_category ?? ALL}
                                onChange={(v) => applyFilter('ticket_category', v)}
                                allLabel="All categories"
                                options={TICKET_CATEGORIES}
                            />
                            <FilterSelect
                                ariaLabel="Filter by SLA state"
                                value={filters?.sla ?? ALL}
                                onChange={(v) => applyFilter('sla', v)}
                                allLabel="Any SLA state"
                                options={SLA_STATES}
                            />
                            <AssigneeFilter
                                value={filters?.assignee != null ? String(filters.assignee) : ALL}
                                onChange={(v) => applyFilter('assignee', v)}
                                assignees={assignees}
                            />
                            <DateRange
                                from={filters?.from ?? ''}
                                to={filters?.to ?? ''}
                                onChange={(k, val) => applyFilter(k, val)}
                            />
                            <div className="ml-auto flex items-center gap-2">
                                {can.manage ? (
                                    <Button size="sm" variant="outline" onClick={() => setModal({ type: 'ticket' })}>
                                        <Plus className="h-3.5 w-3.5" /> Log ticket
                                    </Button>
                                ) : null}
                                {can.edit_sla && slaPolicies ? (
                                    <Button size="sm" variant="outline" onClick={() => setModal({ type: 'sla' })}>
                                        <Timer className="h-3.5 w-3.5" /> SLA targets
                                    </Button>
                                ) : null}
                            </div>
                        </div>

                        {/* Bulk action bar — appears when rows are selected */}
                        {can.manage && ticketSel.selected.size > 0 ? (
                            <div className="sticky top-2 z-20 flex flex-wrap items-center gap-2 rounded-xl border border-primary/40 bg-primary/10 px-3 py-2 shadow-sm">
                                <span className="text-[12.5px] font-semibold text-foreground">
                                    {ticketSel.selected.size} selected
                                </span>
                                <span className="mx-1 h-5 w-px bg-border" aria-hidden />
                                <Select
                                    value=""
                                    onValueChange={(v) =>
                                        runBulk({
                                            action: 'assign',
                                            assigned_to_user_id: v === UNASSIGN ? null : Number(v),
                                        })
                                    }
                                >
                                    <SelectTrigger className="h-8 w-[150px]" aria-label="Assign selected tickets to">
                                        <SelectValue placeholder="Assign to…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={UNASSIGN}>Unassign</SelectItem>
                                        {assignees.map((a) => (
                                            <SelectItem key={a.id} value={String(a.id)}>
                                                {a.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Select value="" onValueChange={(v) => runBulk({ action: 'priority', priority: v })}>
                                    <SelectTrigger className="h-8 w-[140px]" aria-label="Set priority for selected">
                                        <SelectValue placeholder="Set priority…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {TICKET_PRIORITIES.map((p) => (
                                            <SelectItem key={p} value={p}>
                                                {label(p)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Select value="" onValueChange={(v) => runBulk({ action: 'status', status: v })}>
                                    <SelectTrigger className="h-8 w-[150px]" aria-label="Set status for selected">
                                        <SelectValue placeholder="Set status…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {['open', 'in_progress', 'waiting'].map((s) => (
                                            <SelectItem key={s} value={s}>
                                                {label(s)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={bulkBusy}
                                    onClick={() => setConfirmBulkClose(true)}
                                >
                                    <XCircle className="h-3.5 w-3.5" /> Close
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="ml-auto"
                                    onClick={() => ticketSel.clear()}
                                >
                                    Clear
                                </Button>
                            </div>
                        ) : null}

                        <div className="overflow-hidden rounded-2xl border border-border bg-card">
                            <div className={`grid ${ticketGridCols} gap-3 border-b border-border bg-muted px-4.5 py-2.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase`}>
                                {can.manage ? (
                                    <span className="flex items-center">
                                        <Checkbox
                                            checked={
                                                ticketSel.allOnPage
                                                    ? true
                                                    : ticketSel.someOnPage
                                                      ? 'indeterminate'
                                                      : false
                                            }
                                            onCheckedChange={(v) => ticketSel.toggleAll(v === true)}
                                            aria-label="Select all tickets on this page"
                                        />
                                    </span>
                                ) : null}
                                <SortHeader label="Ticket" col="reference" filters={filters} onSort={applySort} />
                                <span>Requester</span>
                                <span>Assignee</span>
                                <SortHeader label="Priority" col="priority" filters={filters} onSort={applySort} />
                                <SortHeader label="Status" col="status" filters={filters} onSort={applySort} />
                                <span>SLA</span>
                                <SortHeader label="Age" col="created" filters={filters} onSort={applySort} />
                                <span />
                            </div>
                            {(tickets?.data ?? []).map((t) => (
                                <div
                                    key={t.id}
                                    onContextMenu={can.manage ? ticketMenu(t) : undefined}
                                    onClick={(e) => openTicket(t.id, e)}
                                    onDoubleClick={() => router.visit(`/it/tickets/${t.id}`)}
                                    className={`grid cursor-pointer ${ticketGridCols} items-center gap-3 border-b border-border/55 px-4.5 py-3 transition-colors last:border-0 hover:bg-muted/40 ${ticketSel.selected.has(t.id) ? 'bg-primary/5' : ''}`}
                                >
                                    {can.manage ? (
                                        <span className="flex items-center">
                                            <Checkbox
                                                checked={ticketSel.selected.has(t.id)}
                                                onCheckedChange={(v) => ticketSel.toggle(t.id, v === true)}
                                                onClick={(e) => e.stopPropagation()}
                                                aria-label={`Select ${t.reference ?? t.title}`}
                                            />
                                        </span>
                                    ) : null}
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
                                    <span className="min-w-0">
                                        <SlaChip ticket={t} />
                                    </span>
                                    <span className="text-[12px] text-muted-foreground">{t.age ?? '—'}</span>
                                    <span className="flex justify-end">
                                        {can.manage ? (
                                            <button
                                                type="button"
                                                aria-label={`Actions for ${t.title}`}
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    ticketMenu(t)(e);
                                                }}
                                                className="grid h-7 w-7 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                            >
                                                <MoreHorizontal className="h-4 w-4" />
                                            </button>
                                        ) : null}
                                    </span>
                                </div>
                            ))}
                            {(tickets?.data ?? []).length === 0 ? (
                                ticketFiltersActive ? (
                                    <EmptyState
                                        icon={Ticket}
                                        title="No tickets match"
                                        blurb="Nothing fits these filters. Widen or clear them to see more of the queue."
                                        action={{ label: 'Clear filters', onClick: clearTicketFilters }}
                                    />
                                ) : (
                                    <EmptyState
                                        icon={Ticket}
                                        title="No tickets"
                                        blurb={
                                            can.manage
                                                ? 'Log the first helpdesk ticket with the button above.'
                                                : 'The helpdesk queue is clear.'
                                        }
                                    />
                                )
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
                                className="ml-auto"
                                onClick={() => setModal({ type: 'raise' })}
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
                                    onClick={(e) => openTicket(t.id, e)}
                                    onDoubleClick={() => router.visit(`/it/tickets/${t.id}`)}
                                    className="grid cursor-pointer grid-cols-[3fr_1.3fr_0.9fr_1fr_0.8fr] items-center gap-3 border-b border-border/55 px-4.5 py-3 transition-colors last:border-0 hover:bg-muted/40"
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
                                    <span className="flex flex-col items-start gap-1">
                                        <StatusBadge
                                            variant={
                                                t.status === 'waiting'
                                                    ? 'warning'
                                                    : (ticketStatusVariant[t.status] ?? 'neutral')
                                            }
                                            size="sm"
                                        >
                                            {t.status === 'waiting' ? 'Waiting on you' : label(t.status)}
                                        </StatusBadge>
                                        <StatusDots status={t.status} />
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

/** Per-page row selection for the bulk-action queues (tickets & provisioning).
 *  The selection is per-view: it clears whenever the visible page changes
 *  (filter, sort, page, or a bulk action that reshuffles rows). */
function useRowSelection(pageIds: number[]) {
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const key = pageIds.join(',');
    useEffect(() => {
        setSelected(new Set());
    }, [key]);

    const toggle = (id: number, on: boolean) =>
        setSelected((prev) => {
            const next = new Set(prev);
            if (on) next.add(id);
            else next.delete(id);
            return next;
        });

    const toggleAll = (on: boolean) =>
        setSelected((prev) => {
            const next = new Set(prev);
            pageIds.forEach((id) => (on ? next.add(id) : next.delete(id)));
            return next;
        });

    return {
        selected,
        clear: () => setSelected(new Set()),
        toggle,
        toggleAll,
        allOnPage: pageIds.length > 0 && pageIds.every((id) => selected.has(id)),
        someOnPage: pageIds.some((id) => selected.has(id)),
    };
}

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

/** Created-date range — two native pickers feeding the `from`/`to` params. */
function DateRange({
    from,
    to,
    onChange,
}: {
    from: string;
    to: string;
    onChange: (key: 'from' | 'to', value: string) => void;
}) {
    const base =
        'h-8 rounded-md border border-border bg-card px-2 text-[12.5px] text-muted-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring';
    return (
        <div className="flex items-center gap-1.5">
            <input
                type="date"
                value={from}
                max={to || undefined}
                onChange={(e) => onChange('from', e.target.value)}
                aria-label="Raised from"
                className={base}
            />
            <span className="text-[12px] text-muted-foreground">→</span>
            <input
                type="date"
                value={to}
                min={from || undefined}
                onChange={(e) => onChange('to', e.target.value)}
                aria-label="Raised to"
                className={base}
            />
        </div>
    );
}

/** A sortable column header — chevron shows the active direction. */
function SortHeader({
    label,
    col,
    filters,
    onSort,
}: {
    label: string;
    col: string;
    filters?: Filters;
    onSort: (col: string) => void;
}) {
    const active = filters?.sort === col;
    return (
        <button
            type="button"
            onClick={() => onSort(col)}
            aria-label={`Sort by ${label}`}
            className="flex items-center gap-1 text-left tracking-wide uppercase transition-colors hover:text-foreground focus-visible:text-foreground focus-visible:outline-none"
        >
            {label}
            {active ? (
                filters?.dir === 'asc' ? (
                    <ChevronUp className="h-3 w-3" />
                ) : (
                    <ChevronDown className="h-3 w-3" />
                )
            ) : (
                <ChevronsUpDown className="h-3 w-3 opacity-40" />
            )}
        </button>
    );
}

/** Progress dots for a requester's ticket: raised → working → resolved →
 *  closed. Decorative (aria-hidden) — the StatusBadge text beside it carries
 *  the meaning; `waiting` sits at the working stage with its own flag. */
const DOT_STAGES = ['open', 'in_progress', 'resolved', 'closed'];

function StatusDots({ status }: { status: string }) {
    const reached = status === 'waiting' ? 1 : DOT_STAGES.indexOf(status);
    return (
        <span aria-hidden className="flex items-center gap-1 pl-0.5">
            {DOT_STAGES.map((stage, i) => (
                <span
                    key={stage}
                    className={
                        i <= reached
                            ? 'h-1.5 w-1.5 rounded-full bg-primary'
                            : 'h-1.5 w-1.5 rounded-full bg-border'
                    }
                />
            ))}
        </span>
    );
}

function EmptyState({
    icon: Icon,
    title,
    blurb,
    action,
}: {
    icon: typeof Inbox;
    title: string;
    blurb: string;
    action?: { label: string; onClick: () => void };
}) {
    return (
        <div className="flex flex-col items-center gap-2 px-6 py-14 text-center">
            <span className="grid h-12 w-12 place-items-center rounded-2xl bg-muted text-muted-foreground">
                <Icon className="h-6 w-6" />
            </span>
            <div className="text-[14px] font-bold">{title}</div>
            <p className="max-w-sm text-[12.5px] leading-relaxed text-muted-foreground">{blurb}</p>
            {action ? (
                <Button size="sm" variant="outline" className="mt-1" onClick={action.onClick}>
                    {action.label}
                </Button>
            ) : null}
        </div>
    );
}
