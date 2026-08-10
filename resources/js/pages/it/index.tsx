/* eslint-disable no-restricted-syntax -- The IT & Support hub mirrors the
 * gold-standard HR hubs: bespoke table rows, hero stat chips and context-menu
 * triggers built from styled native elements. Every colour is a semantic
 * design token. */
import { HrTabs, useHrTab, type HrTabItem } from '@/components/hr/hr-tabs';
import { useLeaveContextMenu } from '@/components/hr/leave-context-menu';
import { CsatRater, CsatStars } from '@/components/it/csat';
import { ItHero } from '@/components/it/it-hero';
import { ItModuleShell } from '@/components/it/it-module-shell';
import { ItOverview, type OverviewPayload } from '@/components/it/it-overview';
import { ItReports } from '@/components/it/it-reports';
import {
    ItServiceCatalogue,
    type CatalogFieldOptions,
    type CatalogItem,
} from '@/components/it/it-service-catalogue';
import {
    ItWizard,
    KbPreview,
    type AssetOption,
    type AssigneeOption,
    type DeviceOption,
    type EmployeeOption,
    type ItModal,
    type KbOptions,
    type KbRow,
    type RequestRow,
    type ServiceOption,
    type SiteOption,
    type SlaCalendar,
    type SlaPolicyGrid,
    type TicketRow,
} from '@/components/it/it-wizards';
import { KnowledgeDraftDeleteDialog } from '@/components/it/knowledge-draft-delete-dialog';
import { ProvisioningCancelDialog } from '@/components/it/provisioning-cancel-dialog';
import { SlaChip } from '@/components/it/sla-chip';
import { TicketAdvancedFilters } from '@/components/it/ticket-advanced-filters';
import { TicketCloseDialog } from '@/components/it/ticket-close-dialog';
import { TicketDrawer } from '@/components/it/ticket-drawer';
import { TicketReopenDialog } from '@/components/it/ticket-reopen-dialog';
import { TicketRoutingSummary } from '@/components/it/ticket-routing-summary';
import {
    TicketSavedFilters,
    type SavedTicketFilterRow,
} from '@/components/it/ticket-saved-filters';
import {
    TicketWaitingDialog,
    waitingStatusLabel,
} from '@/components/it/ticket-waiting-dialog';
import { WorkflowTemplateDestination } from '@/components/it/workflow-template-destination';
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
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { fireConfetti } from '@/lib/confetti';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    Archive,
    BarChart3,
    BookMarked,
    BookOpen,
    CheckCircle2,
    ChevronDown,
    ChevronsUpDown,
    ChevronUp,
    Copy,
    Download,
    GitMerge,
    Inbox,
    KeyRound,
    Laptop,
    LayoutDashboard,
    Link2,
    Mail,
    MessageSquare,
    MoreHorizontal,
    Pencil,
    Play,
    Plus,
    RotateCcw,
    Search,
    Send,
    Server,
    Star,
    ThumbsDown,
    ThumbsUp,
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
        failed: number;
        done_30d: number;
        overdue: number;
        pending_over_7d: number;
    };
}

interface Filters {
    status: string | null;
    type: string | null;
    assignee: number | null;
    site_id: number | null;
    ticket_status: string | null;
    ticket_priority: string | null;
    ticket_category: string | null;
    source: string | null;
    work_type: string | null;
    service: number | null;
    age: string | null;
    missing: string | null;
    reopened: boolean;
    first_contact: boolean;
    open_only: boolean;
    device_linked: boolean;
    resolved_from: string | null;
    resolved_to: string | null;
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
    waiting_party: 'requester' | 'other' | null;
    assignee: string | null;
    age: string | null;
    resolved: string | null;
    /** §K CSAT: a resolved ticket invites a rating; the given score shows back. */
    can_rate: boolean;
    csat_score: number | null;
}

/** A published KB article as browsed by a requester (§I). */
interface KbPublishedRow {
    id: number;
    title: string;
    category: string;
    body: string | null;
    views: number;
    helpful_yes: number;
    helpful_no: number;
    helpful_percent: number | null;
    user_vote: boolean | null;
    related_service: string | null;
}

interface Props {
    /** Agent-only props — absent from self-service (requester) payloads. */
    requests?: Paginated<RequestRow> | null;
    provisioningWorkflows?: ProvisioningWorkflowRow[];
    tickets?: Paginated<TicketRow> | null;
    assignees?: AssigneeOption[];
    /** Access-approved employee profiles for the manual provisioning-request picker. */
    employeeOptions?: EmployeeOption[];
    /** Active assets register entries for the Log & triage asset-link picker. */
    assetOptions?: AssetOption[];
    /** Active approved Sites for explicit Log & triage scope. */
    siteOptions?: SiteOption[];
    /** Visible canonical Security & Devices records for affected-device links. */
    deviceOptions?: DeviceOption[];
    /** Active catalogue services for ticket classification and queue filtering. */
    serviceOptions?: ServiceOption[];
    /** Knowledge-base catalogue for the agent Knowledge tab (§I). */
    kbArticles?: KbRow[];
    kbOptions?: KbOptions;
    filters?: Filters;
    /** User-owned queue filters; their filter JSON never leaves the server. */
    savedTicketFilters?: SavedTicketFilterRow[];
    activeSavedTicketFilterId?: number | null;
    /** §F1 Overview board — KPIs + needs-attention lanes (agents only). */
    overview?: OverviewPayload;
    /** Effective SLA grid — present only for admins (the policy editor). */
    slaPolicies?: SlaPolicyGrid | null;
    /** The application business-hours calendar for the SLA editor (admins). */
    slaCalendar?: SlaCalendar | null;
    /** The viewer's own tickets — present for anyone with it.request. */
    myTickets: MyTicketRow[];
    /** Permission-safe, published service requests for the catalogue workspace. */
    catalogItems: CatalogItem[];
    catalogFieldOptions?: CatalogFieldOptions;
    /** Published KB articles for a requester's browse tab (§I). */
    kbPublished?: KbPublishedRow[];
    summary: Summary;
    can: {
        view: boolean;
        manage: boolean;
        request: boolean;
        edit_sla?: boolean;
    };
}

interface ProvisioningWorkflowRow {
    id: number;
    lifecycle_type: string;
    status: string;
    effective_at: string | null;
    source_type: string;
    template: string | null;
    employee: { id: number; name: string; role: string | null };
    progress: { total: number; completed: number; failed: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'IT & Support', href: '/it' }];

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
    failed: 'critical',
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
    new Date(`${d}T00:00:00`).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });

const formatDateTime = (value: string | null) =>
    value
        ? new Date(value).toLocaleDateString('en-NZ', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : 'Not set';

const REQUEST_STATUSES = [
    'pending',
    'in_progress',
    'failed',
    'done',
    'cancelled',
];
const REQUEST_TYPES = ['account', 'access', 'equipment', 'other'];
const TICKET_STATUSES = [
    'open',
    'in_progress',
    'waiting',
    'resolved',
    'closed',
];
const TICKET_PRIORITIES = ['low', 'normal', 'high', 'urgent'];
const TICKET_CATEGORIES = ['hardware', 'account', 'network', 'other'];
const SLA_STATES = ['ok', 'at_risk', 'breached', 'met'];
const VIEW_TABS = new Set([
    'overview',
    'tickets',
    'provisioning',
    'knowledge',
    'reports',
]);
const REQUEST_TABS = new Set(['catalog', 'my-tickets', 'knowledge']);

/** Predefined views — server `view` param; counts come from `summary.tickets.views`
 *  keyed identically. Order mirrors the triage funnel (open → attention → done). */
const TICKET_VIEWS: { key: string; label: string }[] = [
    { key: 'all_open', label: 'All open' },
    { key: 'unassigned', label: 'Unassigned' },
    { key: 'mine', label: 'Mine' },
    { key: 'owned_by_me', label: 'Owned by me' },
    { key: 'my_team', label: "My team's work" },
    { key: 'breaching', label: 'Breaching soon' },
    { key: 'breached', label: 'Breached' },
    { key: 'awaiting_reply', label: 'Awaiting reply' },
    { key: 'waiting', label: 'All waiting work' },
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
    provisioningWorkflows = [],
    tickets,
    assignees = [],
    employeeOptions = [],
    assetOptions = [],
    siteOptions = [],
    deviceOptions = [],
    serviceOptions = [],
    kbArticles = [],
    kbOptions = { owners: [], sites: [], services: [] },
    filters,
    savedTicketFilters = [],
    activeSavedTicketFilterId = null,
    overview,
    slaPolicies,
    slaCalendar,
    myTickets,
    catalogItems = [],
    catalogFieldOptions = { employee: [], user: [], asset: [] },
    kbPublished = [],
    summary,
    can,
}: Props) {
    // Default landing tab (§O): a right-click "Set as default view" persists to
    // localStorage; a `?tab=` deep link always wins over it. Validate the stored
    // id against what this user can actually see before trusting it.
    const capabilityDefault = can.view ? 'overview' : 'my-tickets';
    const tabIsAllowed = (id: string | null): id is string => {
        if (!id) return false;
        if (id === 'knowledge') return can.view || can.request;
        if (VIEW_TABS.has(id)) return can.view;
        if (REQUEST_TABS.has(id)) return can.request;

        return false;
    };
    const [storedDefault] = useState<string | null>(() => {
        if (typeof window === 'undefined') return null;
        const v = window.localStorage.getItem('it.defaultTab');
        return tabIsAllowed(v) ? v : null;
    });
    const [defaultTab, setDefaultTab] = useState<string | null>(storedDefault);
    const [requestedTab, setTab] = useHrTab(storedDefault ?? capabilityDefault);
    const tab = tabIsAllowed(requestedTab) ? requestedTab : capabilityDefault;
    const [modal, setModal] = useState<ItModal | null>(null);
    const [peekId, setPeekId] = useState<number | null>(null);
    const ctx = useLeaveContextMenu();

    useEffect(() => {
        if (requestedTab !== tab) setTab(tab);
    }, [requestedTab, setTab, tab]);

    useEffect(() => {
        if (
            !can.edit_sla ||
            tab !== 'tickets' ||
            typeof window === 'undefined'
        ) {
            return;
        }

        const url = new URL(window.location.href);
        if (url.searchParams.get('action') !== 'sla') return;

        setModal({ type: 'sla' });
        url.searchParams.delete('action');
        window.history.replaceState(window.history.state, '', url.toString());
    }, [can.edit_sla, tab]);

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
                          (summary.provisioning?.in_progress ?? 0) +
                          (summary.provisioning?.failed ?? 0),
                  },
                  {
                      id: 'knowledge',
                      label: 'Knowledge',
                      icon: BookOpen,
                      tone: 'primary',
                      badge: kbArticles.length,
                  },
                  {
                      id: 'reports',
                      label: 'Reports',
                      icon: BarChart3,
                      tone: 'primary',
                  },
              ] as HrTabItem[])
            : []),
        ...(can.request
            ? ([
                  {
                      id: 'catalog',
                      label: 'Service catalogue',
                      icon: BookMarked,
                      tone: 'primary',
                      badge: catalogItems.length,
                  },
                  {
                      id: 'my-tickets',
                      label: 'My tickets',
                      icon: Inbox,
                      tone: 'success',
                      badge: summary.my.waiting,
                  },
                  // Requester-only Knowledge browse — agents get the manage
                  // version in their own (can.view) Knowledge tab above.
                  ...(!can.view
                      ? [
                            {
                                id: 'knowledge',
                                label: 'Knowledge',
                                icon: BookOpen,
                                tone: 'primary' as const,
                            },
                        ]
                      : []),
              ] as HrTabItem[])
            : []),
    ];

    /** Tab-strip right-click (§O): pin the default landing view for next time. */
    const tabMenu = (id: string, e: React.MouseEvent) =>
        ctx.open([
            {
                kind: 'item' as const,
                label:
                    defaultTab === id
                        ? 'Default view (current)'
                        : 'Set as default view',
                icon: Star,
                onSelect: () => {
                    if (typeof window !== 'undefined')
                        window.localStorage.setItem('it.defaultTab', id);
                    setDefaultTab(id);
                    const name = tabItems.find((t) => t.id === id)?.label ?? id;
                    toast.success(`${name} is now your default view.`);
                },
            },
            {
                kind: 'item' as const,
                label: 'Open',
                icon: LayoutDashboard,
                onSelect: () => setTab(id),
            },
        ])(e);

    /** A gold star marks the pinned default tab. */
    const tabDecorations = defaultTab
        ? {
              [defaultTab]: (
                  <Star
                      className="h-3 w-3"
                      style={{
                          color: 'var(--status-warning)',
                          fill: 'var(--status-warning)',
                      }}
                      aria-hidden
                  />
              ),
          }
        : undefined;

    /** Merge a param patch onto the current filters and reload the queue. A
     *  filter change drops the page cursor (not in `filters`) → back to page 1. */
    const navigate = (patch: Record<string, string | undefined>) =>
        router.get(
            '/it',
            {
                ...Object.fromEntries(
                    Object.entries(filters ?? {}).filter(
                        ([, v]) => v !== null && v !== '',
                    ),
                ),
                ...patch,
                tab,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const applyFilter = (key: keyof Filters, value: string) =>
        navigate({ [key]: value === ALL || value === '' ? undefined : value });

    /** Predefined-view chip: remember it as the agent's default, then filter. */
    const applyView = (key: string) => {
        try {
            localStorage.setItem(TICKETS_VIEW_KEY, key);
        } catch {
            /* private mode — persistence is best-effort */
        }
        navigate({ view: key });
    };

    const applySavedTicketFilter = (id: number) =>
        router.get(
            '/it',
            { tab: 'tickets', saved_filter: id },
            // Remount so a saved search term becomes the controlled search
            // input value instead of being overwritten by stale local state.
            { preserveState: false, preserveScroll: true, replace: true },
        );

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
        const timer = setTimeout(
            () => navigate({ q: search.trim() === '' ? undefined : search }),
            350,
        );
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // Land an agent on their remembered view when they open Tickets with none set
    // (a hero deep-link carrying ?view= always wins).
    useEffect(() => {
        if (
            !can.view ||
            tab !== 'tickets' ||
            filters?.view ||
            activeSavedTicketFilterId !== null
        )
            return;
        const stored = readStoredView();
        if (stored) navigate({ view: stored });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tab]);

    // Delight (§S): celebrate the moment an agent clears the breach queue —
    // when the breached count goes from >0 to 0 across a reload. sessionStorage
    // remembers the last-seen count so it fires once, not on every render.
    const breachedCount = can.view ? (summary.tickets?.breached ?? 0) : 0;
    useEffect(() => {
        if (!can.view || typeof window === 'undefined') return;
        const prev = Number(
            window.sessionStorage.getItem('it.lastBreached') ?? '-1',
        );
        if (prev > 0 && breachedCount === 0) {
            fireConfetti();
            toast.success('Breach queue cleared — every SLA back on track.');
        }
        window.sessionStorage.setItem('it.lastBreached', String(breachedCount));
    }, [can.view, breachedCount]);

    const ticketFiltersActive = Boolean(
        search.trim() ||
        filters?.view ||
        filters?.ticket_status ||
        filters?.ticket_priority ||
        filters?.ticket_category ||
        filters?.sla ||
        filters?.assignee ||
        filters?.site_id ||
        filters?.from ||
        filters?.to ||
        filters?.source ||
        filters?.work_type ||
        filters?.service ||
        filters?.age ||
        filters?.missing ||
        filters?.reopened ||
        filters?.first_contact ||
        filters?.open_only ||
        filters?.device_linked ||
        filters?.resolved_from ||
        filters?.resolved_to,
    );

    const currentTicketFilters = Object.fromEntries(
        Object.entries({
            view: filters?.view,
            q: search.trim() || null,
            ticket_status: filters?.ticket_status,
            ticket_priority: filters?.ticket_priority,
            ticket_category: filters?.ticket_category,
            sla: filters?.sla,
            assignee: filters?.assignee,
            site_id: filters?.site_id,
            from: filters?.from,
            to: filters?.to,
            source: filters?.source,
            work_type: filters?.work_type,
            service: filters?.service,
            age: filters?.age,
            missing: filters?.missing,
            reopened: filters?.reopened,
            first_contact: filters?.first_contact,
            open_only: filters?.open_only,
            device_linked: filters?.device_linked,
            resolved_from: filters?.resolved_from,
            resolved_to: filters?.resolved_to,
            sort: filters?.sort,
            dir: filters?.dir,
        }).filter(
            ([, value]) => value !== null && value !== '' && value !== false,
        ),
    ) as Record<string, string | number | boolean>;

    /** Wipe every tickets filter (and the search box) back to the full queue. */
    const clearTicketFilters = () => {
        setSearch('');
        router.get(
            '/it',
            { tab: 'tickets' },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const clearAdvancedTicketFilters = () =>
        navigate({
            source: undefined,
            work_type: undefined,
            service: undefined,
            age: undefined,
            missing: undefined,
            reopened: undefined,
            first_contact: undefined,
            open_only: undefined,
            device_linked: undefined,
            resolved_from: undefined,
            resolved_to: undefined,
        });

    /* ---------------- bulk selection (§F2 tickets · §H provisioning) ---------------- */
    // Both queues share one per-page selection hook (useRowSelection, below).
    // Only one tab is visible at a time, so the busy flag is shared.

    const ticketSel = useRowSelection((tickets?.data ?? []).map((t) => t.id));
    const reqSel = useRowSelection((requests?.data ?? []).map((r) => r.id));
    const [closeSelectedTickets, setCloseSelectedTickets] = useState(false);
    const [closeTicket, setCloseTicket] = useState<TicketRow | null>(null);
    const [reopenTicket, setReopenTicket] = useState<{
        id: number;
        reference: string | null;
        audience: 'agent' | 'requester';
    } | null>(null);
    const [waitingSelectedTickets, setWaitingSelectedTickets] = useState(false);
    const [waitingTicket, setWaitingTicket] = useState<TicketRow | null>(null);
    const [confirmBulkFulfil, setConfirmBulkFulfil] = useState(false);
    const [cancelRequest, setCancelRequest] = useState<RequestRow | null>(null);
    const [bulkBusy, setBulkBusy] = useState(false);
    const [confirmKbDelete, setConfirmKbDelete] = useState<KbRow | null>(null);
    const [confirmKbRetire, setConfirmKbRetire] = useState<KbRow | null>(null);
    const [retirementReason, setRetirementReason] = useState('');

    /* ---------------- requester KB browse (§I) ---------------- */
    const [readerArticle, setReaderArticle] = useState<KbPublishedRow | null>(
        null,
    );
    const [kbSearch, setKbSearch] = useState('');
    const [kbCategory, setKbCategory] = useState<string>(ALL);
    // The server is canonical; this local overlay keeps the open reader in
    // sync while its partial Inertia refresh returns the updated payload.
    const [submittedKbVotes, setSubmittedKbVotes] = useState<
        Record<number, boolean>
    >({});
    const [submittingKbVoteFor, setSubmittingKbVoteFor] = useState<
        number | null
    >(null);

    const filteredKb = kbPublished.filter((a) => {
        const q = kbSearch.trim().toLowerCase();
        return (
            (kbCategory === ALL || a.category === kbCategory) &&
            (q === '' ||
                a.title.toLowerCase().includes(q) ||
                (a.body ?? '').toLowerCase().includes(q))
        );
    });
    const currentReaderArticle = readerArticle
        ? (kbPublished.find((article) => article.id === readerArticle.id) ??
          readerArticle)
        : null;
    const readerVote = currentReaderArticle
        ? (submittedKbVotes[currentReaderArticle.id] ??
          currentReaderArticle.user_vote)
        : null;

    /** Open the reader and count the read (server guards publication and access). */
    const openArticle = (a: KbPublishedRow) => {
        setReaderArticle(a);
        router.post(
            `/it/kb/${a.id}/view`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['kbPublished'],
            },
        );
    };

    const voteHelpful = (a: KbPublishedRow, helpful: boolean) => {
        const serverArticle = kbPublished.find(
            (article) => article.id === a.id,
        );
        if (
            submittingKbVoteFor !== null ||
            (submittedKbVotes[a.id] ??
                serverArticle?.user_vote ??
                a.user_vote) !== null
        )
            return;
        setSubmittingKbVoteFor(a.id);
        router.post(
            `/it/kb/${a.id}/helpful`,
            { helpful },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['kbPublished'],
                onSuccess: (page) => {
                    const flash = page.props.flash as
                        | { success?: string }
                        | undefined;
                    if (flash?.success) toast.success(flash.success);
                    const canonicalVote = (
                        page.props.kbPublished as KbPublishedRow[] | undefined
                    )?.find((article) => article.id === a.id)?.user_vote;
                    if (typeof canonicalVote === 'boolean') {
                        setSubmittedKbVotes((current) => ({
                            ...current,
                            [a.id]: canonicalVote,
                        }));
                    }
                },
                onFinish: () => setSubmittingKbVoteFor(null),
            },
        );
    };

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
                    const flash = page.props.flash as
                        | { error?: string; success?: string }
                        | undefined;
                    if (flash?.error) toast.error(flash.error);
                    else if (flash?.success) toast.success(flash.success);
                    sel.clear();
                },
                onFinish: () => setBulkBusy(false),
            },
        );
    };

    const runBulk = (payload: Record<string, unknown>) =>
        runBulkTo('/it/tickets/bulk', ticketSel, payload);
    const runProvisioningBulk = (payload: Record<string, unknown>) =>
        runBulkTo('/it/provisioning/bulk', reqSel, payload);

    /** CSV export of the provisioning queue, carrying the active filters so the
     *  download matches what the agent is looking at (streamed, agent-only). */
    const provisioningExportUrl = () => {
        const params = new URLSearchParams();
        if (filters?.status) params.set('status', filters.status);
        if (filters?.type) params.set('type', filters.type);
        if (filters?.assignee != null)
            params.set('assignee', String(filters.assignee));
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
    const act = (
        method: 'post' | 'patch',
        url: string,
        data: Record<string, string> = {},
    ) => {
        router[method](url, data, {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as
                    | { error?: string; success?: string }
                    | undefined;
                if (flash?.error) toast.error(flash.error);
                else if (flash?.success) toast.success(flash.success);
            },
        });
    };

    /** Copy a reference or link to the clipboard and toast it (§O). */
    const copyText = (text: string | null, what: string) => {
        if (!text) return;
        void navigator.clipboard
            .writeText(text)
            .then(() => toast.success(`${what} copied.`));
    };

    const runKbRetire = () => {
        if (!confirmKbRetire || retirementReason.trim() === '') {
            toast.error('Add a reason so the retirement remains auditable.');
            return;
        }
        act('post', `/it/kb/${confirmKbRetire.id}/retire`, {
            reason: retirementReason.trim(),
        });
        setConfirmKbRetire(null);
        setRetirementReason('');
    };

    const kbMenu = (a: KbRow) => {
        const lifecycleActions =
            a.status === 'draft'
                ? [
                      {
                          kind: 'item' as const,
                          label: 'Send for review',
                          icon: Send,
                          onSelect: () =>
                              act('post', `/it/kb/${a.id}/submit-review`),
                      },
                  ]
                : a.status === 'in_review'
                  ? [
                        {
                            kind: 'item' as const,
                            label: 'Approve & publish',
                            icon: CheckCircle2,
                            tone: 'success' as const,
                            onSelect: () =>
                                act('post', `/it/kb/${a.id}/publish`),
                        },
                        {
                            kind: 'item' as const,
                            label: 'Return to draft',
                            icon: RotateCcw,
                            onSelect: () =>
                                act('post', `/it/kb/${a.id}/restore`),
                        },
                    ]
                  : a.status === 'published'
                    ? [
                          {
                              kind: 'item' as const,
                              label: 'Retire article',
                              icon: Archive,
                              onSelect: () => setConfirmKbRetire(a),
                          },
                      ]
                    : [
                          {
                              kind: 'item' as const,
                              label: 'Restore as draft',
                              icon: RotateCcw,
                              onSelect: () =>
                                  act('post', `/it/kb/${a.id}/restore`),
                          },
                      ];

        const deleteDraft =
            a.status === 'draft'
                ? [
                      { kind: 'divider' as const },
                      {
                          kind: 'item' as const,
                          label: 'Delete draft',
                          icon: XCircle,
                          tone: 'critical' as const,
                          onSelect: () => setConfirmKbDelete(a),
                      },
                  ]
                : [];

        return ctx.open([
            {
                kind: 'item' as const,
                label: 'Edit',
                icon: Pencil,
                onSelect: () => setModal({ type: 'kb', article: a }),
            },
            ...lifecycleActions,
            ...deleteDraft,
        ]);
    };

    /* ---------------- row context menus ---------------- */

    const requestMenu = (r: RequestRow) => {
        const open =
            r.status === 'pending' ||
            r.status === 'in_progress' ||
            r.status === 'failed';
        return ctx.open([
            // Available on any request — a fulfilled item can still arrive broken.
            {
                kind: 'item' as const,
                label: 'Raise linked ticket',
                icon: Ticket,
                onSelect: () =>
                    setModal({
                        type: 'ticket',
                        provisioning: { id: r.id, item: r.item },
                    }),
            },
            ...(r.linked_ticket
                ? [
                      {
                          kind: 'item' as const,
                          label: `Open ${r.linked_ticket.reference ?? 'linked ticket'}`,
                          icon: Inbox,
                          onSelect: () => setPeekId(r.linked_ticket!.id),
                      },
                      {
                          kind: 'item' as const,
                          label: 'Copy link',
                          icon: Link2,
                          onSelect: () =>
                              copyText(
                                  `${window.location.origin}/it/tickets/${r.linked_ticket!.id}`,
                                  'Link',
                              ),
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
                          onSelect: () =>
                              setModal({ type: 'fulfil', request: r }),
                      },
                      ...(r.approval_required &&
                      r.approval_status !== 'approved'
                          ? [
                                {
                                    kind: 'item' as const,
                                    label: 'Approve step',
                                    icon: UserCog,
                                    onSelect: () =>
                                        act(
                                            'post',
                                            `/it/provisioning/${r.id}/approve`,
                                        ),
                                },
                            ]
                          : []),
                      {
                          kind: 'item' as const,
                          label: r.assignee ? 'Reassign…' : 'Assign…',
                          icon: UserCog,
                          onSelect: () =>
                              setModal({ type: 'assign-request', request: r }),
                      },
                      {
                          kind: 'item' as const,
                          label: 'Record failure…',
                          icon: XCircle,
                          tone: 'critical' as const,
                          onSelect: () =>
                              setModal({ type: 'fail-request', request: r }),
                      },
                      { kind: 'divider' as const },
                      {
                          kind: 'item' as const,
                          label: 'Cancel request',
                          icon: XCircle,
                          tone: 'critical' as const,
                          onSelect: () => setCancelRequest(r),
                      },
                  ] as const)
                : []),
        ]);
    };

    const ticketMenu = (t: TicketRow) => {
        const workable =
            t.status === 'open' ||
            t.status === 'in_progress' ||
            t.status === 'waiting';
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
                                    onSelect: () =>
                                        act('patch', `/it/tickets/${t.id}`, {
                                            status: 'in_progress',
                                        }),
                                },
                            ]
                          : []),
                      {
                          kind: 'item' as const,
                          label: t.assignee ? 'Reassign…' : 'Assign…',
                          icon: UserCog,
                          onSelect: () =>
                              setModal({ type: 'assign-ticket', ticket: t }),
                      },
                      {
                          kind: 'item' as const,
                          label:
                              t.status === 'waiting'
                                  ? 'Edit waiting details…'
                                  : 'Set waiting…',
                          icon: Timer,
                          onSelect: () => setWaitingTicket(t),
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
                                  ticket: {
                                      id: t.id,
                                      reference: t.reference,
                                      title: t.title,
                                  },
                              }),
                      },
                  ]
                : []),
            ...(t.status === 'resolved'
                ? [
                      {
                          kind: 'item' as const,
                          label: 'Close ticket…',
                          icon: XCircle,
                          onSelect: () => setCloseTicket(t),
                      },
                      {
                          kind: 'item' as const,
                          label: 'Reopen…',
                          icon: RotateCcw,
                          onSelect: () =>
                              setReopenTicket({
                                  id: t.id,
                                  reference: t.reference,
                                  audience: 'agent',
                              }),
                      },
                  ]
                : []),
            ...(t.status === 'closed'
                ? [
                      {
                          kind: 'item' as const,
                          label: 'Reopen…',
                          icon: RotateCcw,
                          onSelect: () =>
                              setReopenTicket({
                                  id: t.id,
                                  reference: t.reference,
                                  audience: 'agent',
                              }),
                      },
                  ]
                : []),
            { kind: 'divider' as const },
            {
                kind: 'item' as const,
                label: 'Copy reference',
                icon: Copy,
                onSelect: () =>
                    copyText(t.reference, t.reference ?? 'Reference'),
            },
            {
                kind: 'item' as const,
                label: 'Copy link',
                icon: Link2,
                onSelect: () =>
                    copyText(
                        `${window.location.origin}/it/tickets/${t.id}`,
                        'Link',
                    ),
            },
        ]);
    };

    /** My-tickets row menu (requester-facing, §O). */
    const myTicketMenu = (t: MyTicketRow) =>
        ctx.open([
            {
                kind: 'item' as const,
                label: 'Open',
                icon: Ticket,
                onSelect: () => router.visit(`/it/tickets/${t.id}`),
            },
            {
                kind: 'item' as const,
                label: 'Reply',
                icon: MessageSquare,
                onSelect: () => router.visit(`/it/tickets/${t.id}`),
            },
            ...(t.status === 'resolved'
                ? ([
                      {
                          kind: 'item' as const,
                          label: 'Reopen…',
                          icon: RotateCcw,
                          onSelect: () =>
                              setReopenTicket({
                                  id: t.id,
                                  reference: t.reference,
                                  audience: 'requester',
                              }),
                      },
                  ] as const)
                : []),
            { kind: 'divider' as const },
            {
                kind: 'item' as const,
                label: 'Copy reference',
                icon: Copy,
                onSelect: () =>
                    copyText(t.reference, t.reference ?? 'Reference'),
            },
        ]);

    /* ---------------- render ---------------- */

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="IT & Support" />
            {ctx.element}
            <ItWizard
                modal={modal}
                assignees={assignees}
                employeeOptions={employeeOptions}
                assetOptions={assetOptions}
                siteOptions={siteOptions}
                deviceOptions={deviceOptions}
                serviceOptions={serviceOptions}
                slaPolicies={slaPolicies}
                slaCalendar={slaCalendar}
                kbSuggestions={kbPublished}
                kbOptions={kbOptions}
                onOpenArticle={(id) => {
                    const a = kbPublished.find((x) => x.id === id);
                    if (a) {
                        setModal(null);
                        openArticle(a);
                    }
                }}
                onDraftKb={(draft) => setModal({ type: 'kb', draft })}
                onClose={() => setModal(null)}
            />
            <TicketDrawer ticketId={peekId} onClose={() => setPeekId(null)} />

            {/* KB reader (requester browse) */}
            <Dialog
                open={readerArticle !== null}
                onOpenChange={(open) => !open && setReaderArticle(null)}
            >
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{readerArticle?.title}</DialogTitle>
                    </DialogHeader>
                    {readerArticle ? (
                        <div className="space-y-4">
                            <StatusBadge variant="info" size="sm">
                                {label(readerArticle.category)}
                            </StatusBadge>
                            {readerArticle.related_service ? (
                                <p className="text-[12px] text-muted-foreground">
                                    Service:{' '}
                                    <span className="font-semibold text-foreground">
                                        {readerArticle.related_service}
                                    </span>
                                </p>
                            ) : null}
                            <div className="max-h-[50vh] overflow-y-auto rounded-xl border border-border bg-muted/30 p-4">
                                <KbPreview body={readerArticle.body ?? ''} />
                            </div>
                            <div className="flex flex-wrap items-center gap-2 border-t border-border pt-3">
                                <span className="text-[13px] font-medium">
                                    Was this helpful?
                                </span>
                                {readerVote !== null ? (
                                    <span className="inline-flex items-center gap-1.5 text-[13px] text-muted-foreground">
                                        {readerVote ? (
                                            <ThumbsUp className="h-4 w-4" />
                                        ) : (
                                            <ThumbsDown className="h-4 w-4" />
                                        )}
                                        Feedback recorded:{' '}
                                        {readerVote ? 'Helpful' : 'Not helpful'}
                                        .
                                    </span>
                                ) : (
                                    <>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="min-h-11"
                                            disabled={
                                                submittingKbVoteFor ===
                                                readerArticle.id
                                            }
                                            onClick={() =>
                                                voteHelpful(readerArticle, true)
                                            }
                                        >
                                            <ThumbsUp className="h-3.5 w-3.5" />{' '}
                                            Yes
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="min-h-11"
                                            disabled={
                                                submittingKbVoteFor ===
                                                readerArticle.id
                                            }
                                            onClick={() =>
                                                voteHelpful(
                                                    readerArticle,
                                                    false,
                                                )
                                            }
                                        >
                                            <ThumbsDown className="h-3.5 w-3.5" />{' '}
                                            No
                                        </Button>
                                    </>
                                )}
                            </div>
                        </div>
                    ) : null}
                </DialogContent>
            </Dialog>

            <TicketCloseDialog
                open={closeSelectedTickets}
                onOpenChange={setCloseSelectedTickets}
                scope="bulk"
                ticketIds={[...ticketSel.selected]}
                onCompleted={() => ticketSel.clear()}
            />
            <TicketCloseDialog
                open={closeTicket !== null}
                onOpenChange={(open) => !open && setCloseTicket(null)}
                scope="single"
                ticketIds={closeTicket ? [closeTicket.id] : []}
                ticketReference={closeTicket?.reference}
            />
            <TicketReopenDialog
                open={reopenTicket !== null}
                onOpenChange={(open) => !open && setReopenTicket(null)}
                ticketId={reopenTicket?.id ?? null}
                ticketReference={reopenTicket?.reference}
                audience={reopenTicket?.audience ?? 'agent'}
            />
            <TicketWaitingDialog
                open={waitingSelectedTickets}
                onOpenChange={setWaitingSelectedTickets}
                scope="bulk"
                ticketIds={[...ticketSel.selected]}
                onCompleted={() => ticketSel.clear()}
            />
            <TicketWaitingDialog
                open={waitingTicket !== null}
                onOpenChange={(open) => !open && setWaitingTicket(null)}
                scope="single"
                ticketIds={waitingTicket ? [waitingTicket.id] : []}
                ticketReference={waitingTicket?.reference}
                current={
                    waitingTicket?.status === 'waiting'
                        ? {
                              party: waitingTicket.waiting_party ?? 'other',
                              reason: waitingTicket.waiting_reason,
                              next_action: waitingTicket.next_action,
                              since: waitingTicket.waiting_since,
                              since_human: null,
                          }
                        : null
                }
            />

            <ProvisioningCancelDialog
                request={cancelRequest}
                open={cancelRequest !== null}
                onOpenChange={(open) => !open && setCancelRequest(null)}
            />

            <KnowledgeDraftDeleteDialog
                article={confirmKbDelete}
                open={confirmKbDelete !== null}
                onOpenChange={(open) => !open && setConfirmKbDelete(null)}
            />

            <AlertDialog
                open={confirmBulkFulfil}
                onOpenChange={setConfirmBulkFulfil}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Fulfil {reqSel.selected.size} request
                            {reqSel.selected.size === 1 ? '' : 's'}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Each request is marked done and any linked
                            onboarding task is completed. This can’t be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() =>
                                runProvisioningBulk({ action: 'fulfil' })
                            }
                        >
                            Fulfil requests
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <Dialog
                open={confirmKbRetire !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setConfirmKbRetire(null);
                        setRetirementReason('');
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Retire “{confirmKbRetire?.title}”?
                        </DialogTitle>
                    </DialogHeader>
                    <p className="text-sm text-muted-foreground">
                        Staff will no longer find this article. Record why it is
                        being retired so the knowledge history stays auditable.
                    </p>
                    <Textarea
                        aria-label="Retirement reason"
                        value={retirementReason}
                        onChange={(event) =>
                            setRetirementReason(event.target.value)
                        }
                        placeholder="What replaced this article, or why is it no longer valid?"
                        maxLength={2000}
                    />
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            onClick={() => {
                                setConfirmKbRetire(null);
                                setRetirementReason('');
                            }}
                        >
                            Keep published
                        </Button>
                        <Button onClick={runKbRetire}>Retire article</Button>
                    </div>
                </DialogContent>
            </Dialog>

            <ItModuleShell>
                <div className="flex flex-col gap-5 p-4 sm:p-6">
                    <ItHero
                        summary={summary}
                        can={can}
                        onRaise={() => setModal({ type: 'raise' })}
                        onLog={() => setModal({ type: 'ticket' })}
                    />

                    <HrTabs
                        value={tab}
                        onChange={setTab}
                        items={tabItems}
                        ariaLabel="IT views"
                        onItemContextMenu={tabMenu}
                        decorations={tabDecorations}
                    />

                    {/* ── Overview (agents) ── */}
                    {can.view &&
                        tab === 'overview' &&
                        overview &&
                        summary.tickets && (
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

                    {/* ── Reports (agents, §L) ── */}
                    {can.view && tab === 'reports' && <ItReports />}

                    {/* ── Provisioning queue (agents) ── */}
                    {can.view && tab === 'provisioning' && (
                        <>
                            <section
                                className="rounded-2xl border border-border bg-card p-4 sm:p-5"
                                aria-labelledby="jml-workflows-heading"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <span className="grid h-9 w-9 place-items-center rounded-xl bg-primary/10 text-primary">
                                                <GitMerge className="h-4 w-4" />
                                            </span>
                                            <div>
                                                <h2
                                                    id="jml-workflows-heading"
                                                    className="text-sm font-bold text-foreground"
                                                >
                                                    Joiner, mover & leaver
                                                    workflows
                                                </h2>
                                                <p className="text-xs text-muted-foreground">
                                                    HR starts the lifecycle
                                                    event; IT fulfils only the
                                                    minimum operational steps
                                                    shown here.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <WorkflowTemplateDestination
                                        canManage={can.manage}
                                    />
                                </div>
                                {provisioningWorkflows.length > 0 ? (
                                    <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                        {provisioningWorkflows.map(
                                            (workflow) => {
                                                const progress =
                                                    workflow.progress.total > 0
                                                        ? Math.round(
                                                              (workflow.progress
                                                                  .completed /
                                                                  workflow
                                                                      .progress
                                                                      .total) *
                                                                  100,
                                                          )
                                                        : 0;
                                                return (
                                                    <article
                                                        key={workflow.id}
                                                        className="rounded-xl border border-border bg-background p-3.5"
                                                    >
                                                        <div className="flex items-start justify-between gap-2">
                                                            <div className="min-w-0">
                                                                <p className="truncate text-[13px] font-bold text-foreground">
                                                                    {
                                                                        workflow
                                                                            .employee
                                                                            .name
                                                                    }
                                                                </p>
                                                                <p className="truncate text-[11.5px] text-muted-foreground">
                                                                    {workflow
                                                                        .employee
                                                                        .role ??
                                                                        workflow.template ??
                                                                        'IT workflow'}
                                                                </p>
                                                            </div>
                                                            <StatusBadge
                                                                variant={
                                                                    workflow.status ===
                                                                    'completed'
                                                                        ? 'success'
                                                                        : workflow.status ===
                                                                            'partially_failed'
                                                                          ? 'critical'
                                                                          : 'info'
                                                                }
                                                                size="sm"
                                                            >
                                                                {label(
                                                                    workflow.lifecycle_type,
                                                                )}
                                                            </StatusBadge>
                                                        </div>
                                                        <div
                                                            className="mt-3 h-1.5 overflow-hidden rounded-full bg-muted"
                                                            aria-label={`${progress}% complete`}
                                                            aria-valuemin={0}
                                                            aria-valuemax={100}
                                                            aria-valuenow={
                                                                progress
                                                            }
                                                            role="progressbar"
                                                        >
                                                            <div
                                                                className="h-full rounded-full bg-primary"
                                                                style={{
                                                                    width: `${progress}%`,
                                                                }}
                                                            />
                                                        </div>
                                                        <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-[11px] text-muted-foreground">
                                                            <span>
                                                                {
                                                                    workflow
                                                                        .progress
                                                                        .completed
                                                                }{' '}
                                                                of{' '}
                                                                {
                                                                    workflow
                                                                        .progress
                                                                        .total
                                                                }{' '}
                                                                complete
                                                            </span>
                                                            <span>
                                                                {formatDateTime(
                                                                    workflow.effective_at,
                                                                )}
                                                            </span>
                                                        </div>
                                                        {workflow.progress
                                                            .failed > 0 ? (
                                                            <p className="mt-2 text-[11px] font-semibold text-[color:var(--status-critical)]">
                                                                {
                                                                    workflow
                                                                        .progress
                                                                        .failed
                                                                }{' '}
                                                                step
                                                                {workflow
                                                                    .progress
                                                                    .failed ===
                                                                1
                                                                    ? ''
                                                                    : 's'}{' '}
                                                                need recovery
                                                            </p>
                                                        ) : null}
                                                    </article>
                                                );
                                            },
                                        )}
                                    </div>
                                ) : (
                                    <div className="mt-4 rounded-xl border border-dashed border-border px-4 py-5 text-center text-xs text-muted-foreground">
                                        No lifecycle workflows yet. Create
                                        templates in Setup; matching HR
                                        onboarding, role/site changes, and
                                        offboarding events will appear
                                        automatically.
                                    </div>
                                )}
                                <p className="mt-3 text-[11px] text-muted-foreground">
                                    Asset custody remains in Assets, device
                                    assignments in Security & Devices, and
                                    employee identity in HR. This queue
                                    coordinates those canonical records without
                                    copying them.
                                </p>
                            </section>
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
                                    value={
                                        filters?.assignee != null
                                            ? String(filters.assignee)
                                            : ALL
                                    }
                                    onChange={(v) => applyFilter('assignee', v)}
                                    assignees={assignees}
                                />
                                <div className="ml-auto flex items-center gap-2">
                                    <Button asChild size="sm" variant="outline">
                                        <a
                                            href={provisioningExportUrl()}
                                            aria-label="Export the provisioning queue as CSV"
                                        >
                                            <Download className="h-3.5 w-3.5" />{' '}
                                            Export CSV
                                        </a>
                                    </Button>
                                    {can.manage ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setModal({
                                                    type: 'new-request',
                                                })
                                            }
                                        >
                                            <Plus className="h-3.5 w-3.5" /> New
                                            request
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
                                    <span
                                        className="mx-1 h-5 w-px bg-border"
                                        aria-hidden
                                    />
                                    <Select
                                        value=""
                                        onValueChange={(v) =>
                                            runProvisioningBulk({
                                                action: 'assign',
                                                assigned_to_user_id: Number(v),
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            className="h-8 w-[160px]"
                                            aria-label="Assign selected requests to"
                                        >
                                            <SelectValue placeholder="Assign to…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {assignees.map((a) => (
                                                <SelectItem
                                                    key={a.id}
                                                    value={String(a.id)}
                                                >
                                                    {a.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={bulkBusy}
                                        onClick={() =>
                                            setConfirmBulkFulfil(true)
                                        }
                                    >
                                        <CheckCircle2 className="h-3.5 w-3.5" />{' '}
                                        Fulfil
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
                                <div
                                    className={`grid ${reqGridCols} gap-3 border-b border-border bg-muted px-4.5 py-2.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase`}
                                >
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
                                                onCheckedChange={(v) =>
                                                    reqSel.toggleAll(v === true)
                                                }
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
                                        can.manage &&
                                        (r.status === 'pending' ||
                                            r.status === 'in_progress' ||
                                            r.status === 'failed');
                                    const overdue =
                                        r.due_date != null &&
                                        r.status !== 'done' &&
                                        r.status !== 'cancelled' &&
                                        r.due_date < todayISO();
                                    return (
                                        <div
                                            key={r.id}
                                            onContextMenu={
                                                can.manage
                                                    ? requestMenu(r)
                                                    : undefined
                                            }
                                            className={`grid ${reqGridCols} items-center gap-3 border-b border-border/55 px-4.5 py-3 last:border-0 ${reqSel.selected.has(r.id) ? 'bg-primary/5' : overdue ? 'bg-[color:var(--status-critical)]/5' : ''}`}
                                        >
                                            {can.manage ? (
                                                <span className="flex items-center">
                                                    <Checkbox
                                                        checked={reqSel.selected.has(
                                                            r.id,
                                                        )}
                                                        onCheckedChange={(v) =>
                                                            reqSel.toggle(
                                                                r.id,
                                                                v === true,
                                                            )
                                                        }
                                                        aria-label={`Select ${r.item}`}
                                                    />
                                                </span>
                                            ) : null}
                                            <div className="min-w-0">
                                                <div className="truncate text-[13.5px] font-semibold">
                                                    {r.employee.name}
                                                </div>
                                                <div className="truncate text-[11.5px] text-muted-foreground">
                                                    {r.workflow
                                                        ? `${label(r.workflow.lifecycle_type)} workflow${r.employee.role ? ` · ${r.employee.role}` : ''}`
                                                        : r.from_onboarding
                                                          ? `Onboarding${r.employee.role ? ` · ${r.employee.role}` : ''}`
                                                          : (r.employee.role ??
                                                            '—')}
                                                </div>
                                            </div>
                                            <div className="flex min-w-0 items-center gap-2">
                                                <span className="grid h-7 w-7 flex-none place-items-center rounded-lg bg-accent text-primary">
                                                    <Icon className="h-3.5 w-3.5" />
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block truncate text-[13px]">
                                                        {r.item}
                                                    </span>
                                                    {r.workflow ? (
                                                        <span className="block truncate text-[10.5px] text-muted-foreground">
                                                            Stage {r.stage ?? 1}
                                                            {r.action
                                                                ? ` · ${label(r.action)}`
                                                                : ''}
                                                            {r.approval_required
                                                                ? ` · ${r.approval_status === 'approved' ? 'Approved' : 'Approval needed'}`
                                                                : ''}
                                                            {r.evidence_required
                                                                ? ' · Evidence needed'
                                                                : ''}
                                                        </span>
                                                    ) : null}
                                                    {r.external_ref ? (
                                                        <span className="block truncate text-[11px] text-muted-foreground">
                                                            Ref:{' '}
                                                            {r.external_ref}
                                                        </span>
                                                    ) : null}
                                                    {r.linked_ticket ? (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setPeekId(
                                                                    r
                                                                        .linked_ticket!
                                                                        .id,
                                                                )
                                                            }
                                                            className="mt-0.5 inline-flex items-center gap-1 rounded-md bg-accent px-1.5 py-0.5 text-[10.5px] font-semibold text-primary transition-colors hover:bg-primary/10"
                                                        >
                                                            <Ticket className="h-3 w-3" />
                                                            {r.linked_ticket
                                                                .reference ??
                                                                'Linked ticket'}
                                                            {r.linked_ticket_count >
                                                            1
                                                                ? ` +${r.linked_ticket_count - 1}`
                                                                : ''}
                                                        </button>
                                                    ) : null}
                                                </span>
                                            </div>
                                            <span className="truncate text-[12.5px] text-muted-foreground">
                                                {r.assignee?.name ??
                                                    r.responsible_team?.name ??
                                                    'Unassigned'}
                                            </span>
                                            <span>
                                                <StatusBadge
                                                    variant={
                                                        priorityVariant[
                                                            r.priority
                                                        ] ?? 'neutral'
                                                    }
                                                    size="sm"
                                                >
                                                    {label(r.priority)}
                                                </StatusBadge>
                                            </span>
                                            <span className="flex flex-col items-start gap-0.5">
                                                <StatusBadge
                                                    variant={
                                                        requestStatusVariant[
                                                            r.status
                                                        ] ?? 'neutral'
                                                    }
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
                                                {r.failure_reason ? (
                                                    <span
                                                        className="max-w-[150px] truncate text-[10.5px] text-[color:var(--status-critical)]"
                                                        title={r.failure_reason}
                                                    >
                                                        {r.failure_reason}
                                                    </span>
                                                ) : null}
                                            </span>
                                            <span
                                                className={
                                                    overdue
                                                        ? 'text-[12px] font-semibold text-[color:var(--status-critical)]'
                                                        : 'text-[12px] text-muted-foreground'
                                                }
                                            >
                                                {r.due_date
                                                    ? formatDue(r.due_date)
                                                    : '—'}
                                                {overdue ? (
                                                    <span className="block text-[10px] font-semibold">
                                                        Overdue
                                                    </span>
                                                ) : null}
                                            </span>
                                            <span className="flex items-center justify-end gap-1.5">
                                                {actionable ? (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            r.approval_required &&
                                                            r.approval_status !==
                                                                'approved'
                                                                ? act(
                                                                      'post',
                                                                      `/it/provisioning/${r.id}/approve`,
                                                                  )
                                                                : setModal({
                                                                      type: 'fulfil',
                                                                      request:
                                                                          r,
                                                                  })
                                                        }
                                                        className="rounded-lg border border-border px-2.5 py-1.5 text-[12px] font-semibold transition-colors hover:border-primary/50 hover:text-primary"
                                                    >
                                                        {r.approval_required &&
                                                        r.approval_status !==
                                                            'approved'
                                                            ? 'Approve'
                                                            : 'Fulfil'}
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
                                        blurb="Matching HR joiner, mover and leaver events create ordered IT steps here automatically. Manual requests remain available when needed."
                                    />
                                ) : null}
                            </div>
                            {requests ? (
                                <LaravelPagination
                                    links={requests.links}
                                    lastPage={requests.last_page}
                                />
                            ) : null}
                        </>
                    )}

                    {/* ── Ticket queue (agents) ── */}
                    {can.view && tab === 'tickets' && (
                        <>
                            {/* Canonical queue views — counts from the all-time summary. */}
                            <div
                                className="flex flex-wrap items-center gap-1.5"
                                aria-label="Predefined ticket views"
                            >
                                {TICKET_VIEWS.map((v) => {
                                    const activeView = filters?.view === v.key;
                                    const count =
                                        summary.tickets?.views[v.key] ?? 0;
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
                                                        : 'rounded-full bg-muted px-1.5 text-[11px] font-bold text-muted-foreground tabular-nums'
                                                }
                                            >
                                                {count}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>

                            <TicketSavedFilters
                                filters={savedTicketFilters}
                                activeId={activeSavedTicketFilterId}
                                currentFilters={currentTicketFilters}
                                canSave={ticketFiltersActive}
                                onApply={applySavedTicketFilter}
                            />

                            {/* Toolbar — search + filters */}
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="relative">
                                    <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                                    <input
                                        type="search"
                                        value={search}
                                        onChange={(e) =>
                                            setSearch(e.target.value)
                                        }
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
                                    onChange={(v) =>
                                        applyFilter('ticket_status', v)
                                    }
                                    allLabel="All statuses"
                                    options={TICKET_STATUSES}
                                />
                                <FilterSelect
                                    ariaLabel="Filter by priority"
                                    value={filters?.ticket_priority ?? ALL}
                                    onChange={(v) =>
                                        applyFilter('ticket_priority', v)
                                    }
                                    allLabel="All priorities"
                                    options={TICKET_PRIORITIES}
                                />
                                <FilterSelect
                                    ariaLabel="Filter by category"
                                    value={filters?.ticket_category ?? ALL}
                                    onChange={(v) =>
                                        applyFilter('ticket_category', v)
                                    }
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
                                <SiteFilter
                                    value={
                                        filters?.site_id != null
                                            ? String(filters.site_id)
                                            : ALL
                                    }
                                    onChange={(v) => applyFilter('site_id', v)}
                                    sites={siteOptions}
                                />
                                <AssigneeFilter
                                    value={
                                        filters?.assignee != null
                                            ? String(filters.assignee)
                                            : ALL
                                    }
                                    onChange={(v) => applyFilter('assignee', v)}
                                    assignees={assignees}
                                />
                                <DateRange
                                    from={filters?.from ?? ''}
                                    to={filters?.to ?? ''}
                                    onChange={(k, val) => applyFilter(k, val)}
                                />
                                <TicketAdvancedFilters
                                    values={{
                                        source: filters?.source ?? null,
                                        workType: filters?.work_type ?? null,
                                        service: filters?.service ?? null,
                                        age: filters?.age ?? null,
                                        missing: filters?.missing ?? null,
                                        reopened: filters?.reopened ?? false,
                                        firstContact:
                                            filters?.first_contact ?? false,
                                        openOnly: filters?.open_only ?? false,
                                        deviceLinked:
                                            filters?.device_linked ?? false,
                                        resolvedFrom:
                                            filters?.resolved_from ?? null,
                                        resolvedTo:
                                            filters?.resolved_to ?? null,
                                    }}
                                    services={serviceOptions}
                                    onChange={(key, value) =>
                                        navigate({ [key]: value })
                                    }
                                    onClear={clearAdvancedTicketFilters}
                                />
                                <div className="ml-auto flex items-center gap-2">
                                    {can.manage ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setModal({ type: 'ticket' })
                                            }
                                        >
                                            <Plus className="h-3.5 w-3.5" /> Log
                                            ticket
                                        </Button>
                                    ) : null}
                                    {can.edit_sla && slaPolicies ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setModal({ type: 'sla' })
                                            }
                                        >
                                            <Timer className="h-3.5 w-3.5" />{' '}
                                            SLA targets
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
                                    <span
                                        className="mx-1 h-5 w-px bg-border"
                                        aria-hidden
                                    />
                                    <Select
                                        value=""
                                        onValueChange={(v) =>
                                            runBulk({
                                                action: 'assign',
                                                assigned_to_user_id:
                                                    v === UNASSIGN
                                                        ? null
                                                        : Number(v),
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            className="h-8 w-[150px]"
                                            aria-label="Assign selected tickets to"
                                        >
                                            <SelectValue placeholder="Assign to…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={UNASSIGN}>
                                                Unassign
                                            </SelectItem>
                                            {assignees.map((a) => (
                                                <SelectItem
                                                    key={a.id}
                                                    value={String(a.id)}
                                                >
                                                    {a.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Select
                                        value=""
                                        onValueChange={(v) =>
                                            runBulk({
                                                action: 'priority',
                                                priority: v,
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            className="h-8 w-[140px]"
                                            aria-label="Set priority for selected"
                                        >
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
                                    <Select
                                        value=""
                                        onValueChange={(v) => {
                                            if (v === 'waiting') {
                                                setWaitingSelectedTickets(true);
                                                return;
                                            }
                                            runBulk({
                                                action: 'status',
                                                status: v,
                                            });
                                        }}
                                    >
                                        <SelectTrigger
                                            className="h-8 w-[150px]"
                                            aria-label="Set status for selected"
                                        >
                                            <SelectValue placeholder="Set status…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {[
                                                'open',
                                                'in_progress',
                                                'waiting',
                                            ].map((s) => (
                                                <SelectItem key={s} value={s}>
                                                    {label(s)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="min-h-11"
                                        disabled={bulkBusy}
                                        onClick={() =>
                                            setCloseSelectedTickets(true)
                                        }
                                    >
                                        <XCircle className="h-3.5 w-3.5" />{' '}
                                        Close
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
                                <div
                                    className={`grid ${ticketGridCols} gap-3 border-b border-border bg-muted px-4.5 py-2.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase`}
                                >
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
                                                onCheckedChange={(v) =>
                                                    ticketSel.toggleAll(
                                                        v === true,
                                                    )
                                                }
                                                aria-label="Select all tickets on this page"
                                            />
                                        </span>
                                    ) : null}
                                    <SortHeader
                                        label="Ticket"
                                        col="reference"
                                        filters={filters}
                                        onSort={applySort}
                                    />
                                    <span>Requester</span>
                                    <span>Ownership</span>
                                    <SortHeader
                                        label="Priority"
                                        col="priority"
                                        filters={filters}
                                        onSort={applySort}
                                    />
                                    <SortHeader
                                        label="Status"
                                        col="status"
                                        filters={filters}
                                        onSort={applySort}
                                    />
                                    <span>SLA</span>
                                    <SortHeader
                                        label="Age"
                                        col="created"
                                        filters={filters}
                                        onSort={applySort}
                                    />
                                    <span />
                                </div>
                                {(tickets?.data ?? []).map((t) => (
                                    <div
                                        key={t.id}
                                        onContextMenu={
                                            can.manage
                                                ? ticketMenu(t)
                                                : undefined
                                        }
                                        onClick={(e) => openTicket(t.id, e)}
                                        onDoubleClick={() =>
                                            router.visit(`/it/tickets/${t.id}`)
                                        }
                                        className={`grid cursor-pointer ${ticketGridCols} items-center gap-3 border-b border-border/55 px-4.5 py-3 transition-colors last:border-0 hover:bg-muted/40 ${ticketSel.selected.has(t.id) ? 'bg-primary/5' : ''}`}
                                    >
                                        {can.manage ? (
                                            <span className="flex items-center">
                                                <Checkbox
                                                    checked={ticketSel.selected.has(
                                                        t.id,
                                                    )}
                                                    onCheckedChange={(v) =>
                                                        ticketSel.toggle(
                                                            t.id,
                                                            v === true,
                                                        )
                                                    }
                                                    onClick={(e) =>
                                                        e.stopPropagation()
                                                    }
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
                                                    {t.reference
                                                        ? `${t.reference} · `
                                                        : ''}
                                                    {label(t.work_type)}
                                                    {t.service
                                                        ? ` · ${t.service.name}`
                                                        : ''}
                                                    {` · ${label(t.category)}`}
                                                    {t.description
                                                        ? ` · ${t.description}`
                                                        : ''}
                                                </span>
                                            </span>
                                        </div>
                                        <span className="truncate text-[12.5px] text-muted-foreground">
                                            {t.requester}
                                        </span>
                                        <span className="min-w-0 text-[12.5px] text-muted-foreground">
                                            <span className="block truncate">
                                                {t.assignee?.name ??
                                                    'Unassigned'}
                                            </span>
                                            <TicketRoutingSummary
                                                routing={t.routing}
                                                compact
                                            />
                                        </span>
                                        <span>
                                            <StatusBadge
                                                variant={
                                                    priorityVariant[
                                                        t.priority
                                                    ] ?? 'neutral'
                                                }
                                                size="sm"
                                            >
                                                {label(t.priority)}
                                            </StatusBadge>
                                        </span>
                                        <span>
                                            <StatusBadge
                                                variant={
                                                    ticketStatusVariant[
                                                        t.status
                                                    ] ?? 'neutral'
                                                }
                                                size="sm"
                                            >
                                                {t.status === 'waiting'
                                                    ? waitingStatusLabel(
                                                          t.waiting_party,
                                                      )
                                                    : label(t.status)}
                                            </StatusBadge>
                                        </span>
                                        <span className="min-w-0">
                                            <SlaChip ticket={t} />
                                        </span>
                                        <span className="text-[12px] text-muted-foreground">
                                            {t.age ?? '—'}
                                        </span>
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
                                            action={{
                                                label: 'Clear filters',
                                                onClick: clearTicketFilters,
                                            }}
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
                                <LaravelPagination
                                    links={tickets.links}
                                    lastPage={tickets.last_page}
                                />
                            ) : null}
                        </>
                    )}

                    {/* ── Service catalogue (everyone with it.request) ── */}
                    {can.request && tab === 'catalog' ? (
                        <ItServiceCatalogue
                            items={catalogItems}
                            fieldOptions={catalogFieldOptions}
                        />
                    ) : null}

                    {/* ── My tickets (everyone with it.request) ── */}
                    {can.request && tab === 'my-tickets' && (
                        <>
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="text-[12.5px] text-muted-foreground">
                                    Tickets you’ve raised — IT sees new ones
                                    instantly.
                                </p>
                                <Button
                                    size="sm"
                                    className="ml-auto"
                                    onClick={() => setModal({ type: 'raise' })}
                                >
                                    <Plus className="h-3.5 w-3.5" /> Raise a
                                    ticket
                                </Button>
                            </div>

                            {/* CSAT prompt (§K) — a nudge to rate freshly resolved tickets;
                            it empties as each is rated (confetti on a perfect five). */}
                            {myTickets.some(
                                (t) => t.can_rate && t.csat_score == null,
                            ) ? (
                                <div className="rounded-2xl border border-primary/20 bg-primary/5 px-4.5 py-4">
                                    <div className="flex items-center gap-2.5">
                                        <span className="grid h-8 w-8 flex-none place-items-center rounded-lg bg-primary/10 text-primary">
                                            <Star className="h-4 w-4" />
                                        </span>
                                        <div className="min-w-0">
                                            <h3 className="text-[14px] leading-tight font-bold">
                                                How did IT do?
                                            </h3>
                                            <p className="text-[12px] text-muted-foreground">
                                                Rate your resolved tickets — it
                                                takes a moment and helps IT
                                                improve.
                                            </p>
                                        </div>
                                    </div>
                                    <div className="mt-3 flex flex-col gap-2.5">
                                        {myTickets
                                            .filter(
                                                (t) =>
                                                    t.can_rate &&
                                                    t.csat_score == null,
                                            )
                                            .map((t) => (
                                                <div
                                                    key={t.id}
                                                    className="rounded-xl border border-border/60 bg-card px-3.5 py-3"
                                                >
                                                    <div className="flex flex-wrap items-baseline gap-x-2">
                                                        <span className="text-[13px] font-semibold">
                                                            {t.title}
                                                        </span>
                                                        <span className="text-[11px] text-muted-foreground">
                                                            {t.reference ?? ''}
                                                            {t.resolved
                                                                ? ` · resolved ${t.resolved}`
                                                                : ''}
                                                        </span>
                                                    </div>
                                                    <div className="mt-2">
                                                        <CsatRater
                                                            ticketId={t.id}
                                                        />
                                                    </div>
                                                </div>
                                            ))}
                                    </div>
                                </div>
                            ) : null}

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
                                        onDoubleClick={() =>
                                            router.visit(`/it/tickets/${t.id}`)
                                        }
                                        onContextMenu={myTicketMenu(t)}
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
                                                    {t.reference
                                                        ? `${t.reference} · `
                                                        : ''}
                                                    {label(t.category)}
                                                    {t.description
                                                        ? ` · ${t.description}`
                                                        : ''}
                                                </span>
                                            </span>
                                        </div>
                                        <span className="truncate text-[12.5px] text-muted-foreground">
                                            {t.assignee ?? 'With IT for triage'}
                                        </span>
                                        <span>
                                            <StatusBadge
                                                variant={
                                                    priorityVariant[
                                                        t.priority
                                                    ] ?? 'neutral'
                                                }
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
                                                        : (ticketStatusVariant[
                                                              t.status
                                                          ] ?? 'neutral')
                                                }
                                                size="sm"
                                            >
                                                {t.status === 'waiting'
                                                    ? waitingStatusLabel(
                                                          t.waiting_party,
                                                          true,
                                                      )
                                                    : label(t.status)}
                                            </StatusBadge>
                                            <StatusDots status={t.status} />
                                            {t.csat_score != null ? (
                                                <span className="inline-flex items-center gap-1 text-[10.5px] text-muted-foreground">
                                                    You rated{' '}
                                                    <CsatStars
                                                        score={t.csat_score}
                                                        size="h-3 w-3"
                                                    />
                                                </span>
                                            ) : null}
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

                    {/* ── Knowledge base (agents) ── */}
                    {can.view && tab === 'knowledge' && (
                        <>
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="text-[12.5px] text-muted-foreground">
                                    Articles that deflect repeat tickets —
                                    publish the fixes people keep asking for.
                                </p>
                                {can.manage ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="ml-auto"
                                        onClick={() => setModal({ type: 'kb' })}
                                    >
                                        <BookOpen className="h-3.5 w-3.5" /> New
                                        KB article
                                    </Button>
                                ) : null}
                            </div>

                            <div className="overflow-x-auto rounded-2xl border border-border bg-card">
                                <div className="grid min-w-[920px] grid-cols-[2.5fr_1.15fr_1.6fr_1.1fr_1.1fr_44px] gap-3 border-b border-border bg-muted px-4.5 py-2.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                                    <span>Title</span>
                                    <span>Lifecycle</span>
                                    <span>Ownership</span>
                                    <span>Impact</span>
                                    <span>Review</span>
                                    <span />
                                </div>
                                {kbArticles.map((a) => (
                                    <div
                                        key={a.id}
                                        onContextMenu={
                                            can.manage ? kbMenu(a) : undefined
                                        }
                                        className="grid min-w-[920px] grid-cols-[2.5fr_1.15fr_1.6fr_1.1fr_1.1fr_44px] items-center gap-3 border-b border-border/55 px-4.5 py-3 transition-colors last:border-0 hover:bg-muted/40"
                                    >
                                        <div className="flex min-w-0 items-center gap-2">
                                            <span className="grid h-7 w-7 flex-none place-items-center rounded-lg bg-accent text-primary">
                                                <BookOpen className="h-3.5 w-3.5" />
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block truncate text-[13px] font-semibold">
                                                    {a.title}
                                                </span>
                                                {a.author ? (
                                                    <span className="block truncate text-[11px] text-muted-foreground">
                                                        by {a.author}
                                                    </span>
                                                ) : null}
                                            </span>
                                        </div>
                                        <span className="space-y-1">
                                            <StatusBadge
                                                variant={
                                                    a.status === 'published'
                                                        ? 'success'
                                                        : a.status ===
                                                            'in_review'
                                                          ? 'warning'
                                                          : 'neutral'
                                                }
                                                size="sm"
                                            >
                                                {label(a.status)}
                                            </StatusBadge>
                                            <span className="block text-[11px] text-muted-foreground">
                                                {label(a.audience)}
                                            </span>
                                        </span>
                                        <span className="min-w-0 text-[12px]">
                                            <span className="block truncate font-semibold">
                                                {a.owner ?? 'Owner not set'}
                                            </span>
                                            <span className="block truncate text-[11px] text-muted-foreground">
                                                {a.related_service ??
                                                    label(a.category)}
                                            </span>
                                        </span>
                                        <span className="text-[12px] text-muted-foreground">
                                            <span className="block tabular-nums">
                                                {a.views} views ·{' '}
                                                {a.deflections} deflections
                                            </span>
                                            <span className="block text-[11px]">
                                                {a.helpful_percent != null
                                                    ? `${a.helpful_percent}% helpful`
                                                    : 'No helpfulness score'}
                                            </span>
                                        </span>
                                        <span className="text-[12px] text-muted-foreground">
                                            <span className="block">
                                                {a.review_due_at ??
                                                    'No review due'}
                                            </span>
                                            <span className="block text-[11px]">
                                                Updated {a.updated ?? '—'}
                                            </span>
                                        </span>
                                        <span className="flex justify-end">
                                            {can.manage ? (
                                                <button
                                                    type="button"
                                                    aria-label={`Actions for ${a.title}`}
                                                    onClick={kbMenu(a)}
                                                    className="grid h-7 w-7 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                                >
                                                    <MoreHorizontal className="h-4 w-4" />
                                                </button>
                                            ) : null}
                                        </span>
                                    </div>
                                ))}
                                {kbArticles.length === 0 ? (
                                    <EmptyState
                                        icon={BookOpen}
                                        title="No articles yet"
                                        blurb={
                                            can.manage
                                                ? 'Write the first fix people keep asking for — it deflects the ticket every time after.'
                                                : 'The knowledge base is empty.'
                                        }
                                        action={
                                            can.manage
                                                ? {
                                                      label: 'New KB article',
                                                      onClick: () =>
                                                          setModal({
                                                              type: 'kb',
                                                          }),
                                                  }
                                                : undefined
                                        }
                                    />
                                ) : null}
                            </div>
                        </>
                    )}

                    {/* ── Knowledge browse (requesters) ── */}
                    {!can.view && can.request && tab === 'knowledge' && (
                        <>
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="relative">
                                    <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                                    <input
                                        type="search"
                                        value={kbSearch}
                                        onChange={(e) =>
                                            setKbSearch(e.target.value)
                                        }
                                        placeholder="Search the knowledge base…"
                                        aria-label="Search articles"
                                        className="h-8 w-[260px] rounded-md border border-border bg-card pr-3 pl-8 text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    />
                                </div>
                                <FilterSelect
                                    ariaLabel="Filter by category"
                                    value={kbCategory}
                                    onChange={setKbCategory}
                                    allLabel="All categories"
                                    options={TICKET_CATEGORIES}
                                />
                            </div>

                            {filteredKb.length === 0 ? (
                                <div className="overflow-hidden rounded-2xl border border-border bg-card">
                                    <EmptyState
                                        icon={BookOpen}
                                        title={
                                            kbPublished.length === 0
                                                ? 'No articles yet'
                                                : 'No matches'
                                        }
                                        blurb={
                                            kbPublished.length === 0
                                                ? 'IT will publish fixes here — check back, or raise a ticket and they’ll sort it.'
                                                : 'Nothing matches your search. Try a different word or category.'
                                        }
                                    />
                                </div>
                            ) : (
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {filteredKb.map((a) => (
                                        <button
                                            key={a.id}
                                            type="button"
                                            onClick={() => openArticle(a)}
                                            className="flex flex-col rounded-2xl border border-border bg-card p-4 text-left transition-colors hover:border-primary/50 hover:bg-muted/40"
                                        >
                                            <span className="grid h-8 w-8 place-items-center rounded-lg bg-accent text-primary">
                                                <BookOpen className="h-4 w-4" />
                                            </span>
                                            <span className="mt-2 text-[14px] font-semibold">
                                                {a.title}
                                            </span>
                                            <span className="mt-1 line-clamp-2 text-[12.5px] text-muted-foreground">
                                                {(a.body ?? '')
                                                    .replace(/[#>*\-\n]+/g, ' ')
                                                    .trim()}
                                            </span>
                                            <span className="mt-3 flex items-center gap-2 text-[11.5px] text-muted-foreground">
                                                <StatusBadge
                                                    variant="info"
                                                    size="sm"
                                                >
                                                    {label(a.category)}
                                                </StatusBadge>
                                                {a.helpful_percent != null ? (
                                                    <span>
                                                        {a.helpful_percent}%
                                                        helpful
                                                    </span>
                                                ) : null}
                                            </span>
                                            {a.related_service ? (
                                                <span className="mt-2 text-[11.5px] text-muted-foreground">
                                                    Service: {a.related_service}
                                                </span>
                                            ) : null}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </>
                    )}
                </div>
            </ItModuleShell>
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
        allOnPage:
            pageIds.length > 0 && pageIds.every((id) => selected.has(id)),
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
            <SelectTrigger
                className="h-8 w-[180px]"
                aria-label="Filter by assignee"
            >
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

function SiteFilter({
    value,
    onChange,
    sites,
}: {
    value: string;
    onChange: (v: string) => void;
    sites: SiteOption[];
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger
                className="h-8 w-[180px]"
                aria-label="Filter by Site"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>All accessible Sites</SelectItem>
                {sites.map((site) => (
                    <SelectItem key={site.id} value={String(site.id)}>
                        {site.name}
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
            <p className="max-w-sm text-[12.5px] leading-relaxed text-muted-foreground">
                {blurb}
            </p>
            {action ? (
                <Button
                    size="sm"
                    variant="outline"
                    className="mt-1"
                    onClick={action.onClick}
                >
                    {action.label}
                </Button>
            ) : null}
        </div>
    );
}
