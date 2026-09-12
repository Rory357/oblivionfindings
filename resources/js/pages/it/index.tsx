import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
/* eslint-disable no-restricted-syntax -- The IT & Support hub mirrors the
 * gold-standard HR hubs: bespoke table rows, hero stat chips and context-menu
 * triggers built from styled native elements. Every colour is a semantic
 * design token. */
import { type HrTabItem } from '@/components/hr/hr-tabs';
import { useLeaveContextMenu } from '@/components/hr/leave-context-menu';
import { CsatRater } from '@/components/it/csat';
import {
    ItBulkResultPanel,
    readItBulkResult,
    type ItBulkResult,
} from '@/components/it/it-bulk-result';
import { ItHero, type ItHeroSummary } from '@/components/it/it-hero';
import { ItModuleShell } from '@/components/it/it-module-shell';
import { ItOverview, type OverviewPayload } from '@/components/it/it-overview';
import { ItProvisioningList } from '@/components/it/it-provisioning-list';
import { ItReports, REPORT_RANGES } from '@/components/it/it-reports';
import {
    ItServiceCatalogue,
    type CatalogFieldOptions,
    type CatalogItem,
} from '@/components/it/it-service-catalogue';
import { ItTicketList } from '@/components/it/it-ticket-list';
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
import {
    MyProvisioningList,
    type MyProvisioningPage,
} from '@/components/it/my-provisioning-list';
import {
    MyTicketsList,
    type MyTicketRow,
} from '@/components/it/my-tickets-list';
import { ProvisioningCancelDialog } from '@/components/it/provisioning-cancel-dialog';
import { TicketAdvancedFilters } from '@/components/it/ticket-advanced-filters';
import { TicketCloseDialog } from '@/components/it/ticket-close-dialog';
import { TicketDrawer } from '@/components/it/ticket-drawer';
import type { TicketIntakePolicy } from '@/components/it/ticket-intake-fields';
import { useTicketPropertyMutation } from '@/components/it/ticket-property-mutation';
import { TicketReopenDialog } from '@/components/it/ticket-reopen-dialog';
import {
    TicketSavedFilters,
    type SavedTicketFilterRow,
} from '@/components/it/ticket-saved-filters';
import { TicketTriageReasonDialog } from '@/components/it/ticket-triage-reason-dialog';
import {
    ticketViewLabel,
    ticketViewOptions,
} from '@/components/it/ticket-view-options';
import { TicketWaitingDialog } from '@/components/it/ticket-waiting-dialog';
import { WorkflowTemplateDestination } from '@/components/it/workflow-template-destination';
import { compactMenu } from '@/components/lists/entity-menu';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page/page-header';
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
    DialogDescription,
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
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    BarChart3,
    BookMarked,
    BookOpen,
    CheckCircle2,
    Copy,
    Download,
    GitMerge,
    Inbox,
    KeyRound,
    Laptop,
    LayoutDashboard,
    LayoutGrid,
    Link2,
    List,
    Mail,
    MessageSquare,
    MoreHorizontal,
    Pencil,
    Play,
    Plus,
    RotateCcw,
    Send,
    Server,
    Star,
    ThumbsDown,
    ThumbsUp,
    Ticket,
    Timer,
    UserCog,
    XCircle,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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
type Summary = ItHeroSummary;

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
    bulkResult?: ItBulkResult | null;
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
    intakePolicy?: TicketIntakePolicy;
    /** Knowledge-base catalogue for the agent Knowledge tab (§I). */
    kbArticles?: KbRow[];
    kbOptions?: KbOptions;
    filters?: Filters;
    /** User-owned queue filters; their filter JSON never leaves the server. */
    savedTicketFilters?: SavedTicketFilterRow[];
    draftRecovery?: { enabled: boolean };
    conversation_ready?: boolean;
    activeSavedTicketFilterId?: number | null;
    /** §F1 Overview board — KPIs + needs-attention lanes (agents only). */
    overview?: OverviewPayload;
    /** Effective SLA grid — present only for admins (the policy editor). */
    slaPolicies?: SlaPolicyGrid | null;
    /** The application business-hours calendar for the SLA editor (admins). */
    slaCalendar?: SlaCalendar | null;
    /** The viewer's own tickets — present for anyone with it.request. */
    myTickets: MyTicketRow[];
    myProvisioning?: MyProvisioningPage | null;
    /** Permission-safe, published service requests for the catalogue workspace. */
    catalogItems: CatalogItem[];
    catalogFieldOptions?: CatalogFieldOptions;
    /** Published KB articles for a requester's browse tab (§I). */
    kbPublished?: KbPublishedRow[];
    summary: Summary | null;
    can: {
        view: boolean;
        manage: boolean;
        request: boolean;
        edit_sla?: boolean;
        knowledge_author?: boolean;
        knowledge_review?: boolean;
    };
}

interface ProvisioningWorkflowRow {
    id: number;
    lifecycle_type: string;
    status: string;
    effective_at: string | null;
    source_type: string;
    template: string | null;
    template_version: number | null;
    template_provenance: string;
    employee: { id: number; name: string; role: string | null };
    progress: { total: number; completed: number; failed: number };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'IT & Support', href: '/it' },
];

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
const SLA_STATES = ['ok', 'at_risk', 'breached', 'met', 'paused', 'unmeasured'];
const VIEW_TABS = new Set([
    'overview',
    'tickets',
    'provisioning',
    'knowledge',
    'reports',
]);
const REQUEST_TABS = new Set(['catalog', 'my-tickets', 'knowledge']);

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function ItIndex({
    requests,
    bulkResult,
    provisioningWorkflows = [],
    tickets,
    assignees = [],
    employeeOptions = [],
    assetOptions = [],
    siteOptions = [],
    deviceOptions = [],
    serviceOptions = [],
    intakePolicy,
    kbArticles = [],
    kbOptions = { owners: [], sites: [], services: [] },
    filters,
    savedTicketFilters = [],
    draftRecovery = { enabled: false },
    conversation_ready = false,
    activeSavedTicketFilterId = null,
    overview,
    slaPolicies,
    slaCalendar,
    myTickets,
    myProvisioning = null,
    catalogItems = [],
    catalogFieldOptions = { employee: [], user: [], asset: [] },
    kbPublished = [],
    summary,
    can,
}: Props) {
    // Default landing tab (§O): a right-click "Set as default view" persists to
    // localStorage; a `?tab=` deep link always wins over it. Validate the stored
    // id against what this user can actually see before trusting it.
    const availableTicketViews = ticketViewOptions(
        can.manage,
        conversation_ready === true,
    );
    const canAuthorKnowledge = can.knowledge_author === true;
    const canReviewKnowledge = can.knowledge_review === true;
    const showKnowledgeCatalogue =
        can.view || canAuthorKnowledge || canReviewKnowledge;
    const capabilityDefault = can.view
        ? 'overview'
        : can.request
          ? 'my-tickets'
          : 'knowledge';
    const tabIsAllowed = (id: string | null): id is string => {
        if (!id) return false;
        if (id === 'knowledge') return showKnowledgeCatalogue || can.request;
        if (VIEW_TABS.has(id)) return can.view;
        if (REQUEST_TABS.has(id)) return can.request;

        return false;
    };
    const page = usePage();
    const actorId = (page.props.auth as { user?: { id?: number } } | undefined)
        ?.user?.id;
    const defaultKey = `it.defaultTab.${actorId ?? 'anonymous'}`;
    const pageQuery = new URL(page.url, 'http://local.invalid').searchParams;
    const listView = pageQuery.get('list_view') === 'cards' ? 'cards' : 'table';
    const [storedDefault] = useState<string | null>(() => {
        if (typeof window === 'undefined') return null;
        let v: string | null = null;
        try {
            v = window.localStorage.getItem(defaultKey);
        } catch {
            /* Storage may be disabled. */
        }
        return tabIsAllowed(v) ? v : null;
    });
    const [defaultTab, setDefaultTab] = useState<string | null>(storedDefault);
    const requestedTab =
        pageQuery.get('tab') ?? storedDefault ?? capabilityDefault;
    const setTab = (id: string) => navigate({ tab: id });
    const tab = tabIsAllowed(requestedTab) ? requestedTab : capabilityDefault;
    const [modal, setModal] = useState<ItModal | null>(null);
    const [peekId, setPeekId] = useState<number | null>(null);
    const ctx = useLeaveContextMenu();

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
                      badge: summary?.tickets?.open ?? 0,
                  },
                  {
                      id: 'provisioning',
                      label: 'Provisioning',
                      icon: Server,
                      tone: 'primary',
                      badge:
                          (summary?.provisioning?.pending ?? 0) +
                          (summary?.provisioning?.in_progress ?? 0) +
                          (summary?.provisioning?.failed ?? 0),
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
                      label: 'My requests',
                      icon: Inbox,
                      tone: 'success',
                      badge:
                          (summary?.my.total ?? myTickets.length) +
                          (myProvisioning?.total ?? 0),
                  },
                  // Requester-only Knowledge browse — agents get the manage
                  // version in their own (can.view) Knowledge tab above.
                  ...(!showKnowledgeCatalogue
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
        ...(!can.view && showKnowledgeCatalogue
            ? [
                  {
                      id: 'knowledge',
                      label: 'Knowledge',
                      icon: BookOpen,
                      tone: 'primary' as const,
                      badge: kbArticles.length,
                  },
              ]
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
                        try {
                            window.localStorage.setItem(defaultKey, id);
                        } catch {
                            /* Best-effort preference. */
                        }
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

    /** URL state is the history entry; Back restores filters, layout and page. */
    const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const navigate = (
        patch: Record<string, string | undefined>,
        replace = false,
    ) => {
        if (searchTimer.current) clearTimeout(searchTimer.current);
        const query = new URLSearchParams(pageQuery);
        if (!Object.keys(patch).every((key) => key === 'list_view')) {
            query.delete('tickets_page');
            query.delete('requests_page');
            query.delete('my_provisioning_page');
        }
        Object.entries(patch).forEach(([key, value]) =>
            value === undefined || value === ''
                ? query.delete(key)
                : query.set(key, value),
        );
        if (!query.has('tab')) query.set('tab', tab);
        router.get('/it', Object.fromEntries(query), {
            preserveState: true,
            preserveScroll: true,
            replace,
        });
    };

    const applyFilter = (key: keyof Filters, value: string) =>
        navigate({ [key]: value === ALL || value === '' ? undefined : value });

    const applyView = (key: string) => {
        try {
            localStorage.setItem(
                `it.ticketsView.${actorId ?? 'anonymous'}`,
                key,
            );
        } catch {
            /* Best-effort preference. */
        }
        navigate({ view: key });
    };
    const restoredInitialView = useRef(false);
    useEffect(() => {
        if (restoredInitialView.current || !can.view || tab !== 'tickets')
            return;
        restoredInitialView.current = true;
        // Only an otherwise-empty initial queue entry uses the saved default.
        // Back and explicit filters always retain their own history state.
        if (pageQuery.size !== 1 || !pageQuery.has('tab')) return;
        try {
            const stored = localStorage.getItem(
                `it.ticketsView.${actorId ?? 'anonymous'}`,
            );
            if (
                availableTicketViews.some(
                    (view) =>
                        view.key === stored &&
                        (!view.operational || can.manage),
                )
            )
                navigate({ view: stored ?? undefined }, true);
        } catch {
            /* Storage may be disabled. */
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tab, actorId, can.view, can.manage, conversation_ready]);

    const applySavedTicketFilter = (id: number) =>
        router.get(
            '/it',
            { tab: 'tickets', saved_filter: id, list_view: listView },
            // Remount so a saved search term becomes the controlled search
            // input value instead of being overwritten by stale local state.
            { preserveState: false, preserveScroll: true },
        );

    const [search, setSearch] = useState(filters?.q ?? '');
    const myQuery = pageQuery.get('my_q') ?? '';
    const myStatus = pageQuery.get('my_status') ?? ALL;
    const [mySearch, setMySearch] = useState(myQuery);
    useEffect(() => {
        if (searchTimer.current) clearTimeout(searchTimer.current);
        setSearch(filters?.q ?? '');
        setMySearch(myQuery);
        return () => {
            if (searchTimer.current) clearTimeout(searchTimer.current);
        };
    }, [page.url, filters?.q, myQuery]);
    const updateSearch = (value: string, mine = false) => {
        (mine ? setMySearch : setSearch)(value);
        if (searchTimer.current) clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(
            () =>
                navigate(
                    { [mine ? 'my_q' : 'q']: value.trim() || undefined },
                    true,
                ),
            350,
        );
    };
    const filteredMyTickets = myTickets.filter(
        (ticket) =>
            (myStatus === ALL || ticket.status === myStatus) &&
            (!myQuery ||
                [ticket.title, ticket.reference, ticket.description].some(
                    (value) =>
                        value
                            ?.toLocaleLowerCase()
                            .includes(myQuery.toLocaleLowerCase()),
                )),
    );
    const layoutToggle = (
        <PageHeaderViewToggle
            value={listView}
            onChange={(value) => navigate({ list_view: value })}
            ariaLabel="List layout"
            options={[
                { value: 'cards', label: 'Cards', icon: LayoutGrid },
                { value: 'table', label: 'Table', icon: List },
            ]}
        />
    );

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
        try {
            localStorage.removeItem(`it.ticketsView.${actorId ?? 'anonymous'}`);
        } catch {
            /* Best-effort preference. */
        }
        setSearch('');
        router.get(
            '/it',
            { tab: 'tickets', list_view: listView },
            { preserveState: true, preserveScroll: true },
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

    const canManageTicket = (ticket: TicketRow) =>
        can.manage && ticket.can?.manage === true;
    const manageableTickets = (tickets?.data ?? []).filter(canManageTicket);
    const ticketSel = useRowSelection(
        manageableTickets.map((t) => t.id),
        Object.fromEntries(
            manageableTickets.map((t) => [t.id, t.lock_version]),
        ),
    );
    const propertyMutation = useTicketPropertyMutation({
        actorId: actorId ?? null,
        draftsEnabled: draftRecovery.enabled,
    });
    const reqSel = useRowSelection((requests?.data ?? []).map((r) => r.id));
    const [closeSelectedTickets, setCloseSelectedTickets] = useState(false);
    const [closeTicket, setCloseTicket] = useState<TicketRow | null>(null);
    const [reopenTicket, setReopenTicket] = useState<{
        id: number;
        lock_version: number;
        reference: string | null;
        audience: 'agent' | 'requester';
    } | null>(null);
    const [waitingSelectedTickets, setWaitingSelectedTickets] = useState(false);
    const [waitingTicket, setWaitingTicket] = useState<TicketRow | null>(null);
    const [confirmBulkFulfil, setConfirmBulkFulfil] = useState(false);
    const [cancelRequest, setCancelRequest] = useState<RequestRow | null>(null);
    const [bulkBusy, setBulkBusy] = useState(false);
    const [bulkOutcome, setBulkOutcome] = useState<{
        actorId: number | undefined;
        result: ItBulkResult | null;
    }>({ actorId, result: bulkResult ?? null });
    const [bulkError, setBulkError] = useState<string | null>(null);
    const [bulkBlocked, setBulkBlocked] = useState(false);
    const [bulkPlan, setBulkPlan] = useState<{
        actorId: number | undefined;
        ids: number[];
        versions: Record<number, number>;
        payload: Record<string, unknown>;
        description: string;
    } | null>(null);
    const [reviewingBulk, setReviewingBulk] = useState(false);
    const acceptBulkResult = (result: ItBulkResult) => {
        setBulkOutcome({ actorId, result });
        setBulkError(null);
        const selection = result.resource === 'tickets' ? ticketSel : reqSel;
        selection.remove(
            result.items
                .filter((item) =>
                    ['updated', 'unchanged', 'unavailable'].includes(
                        item.status,
                    ),
                )
                .map((item) => item.id),
        );
        setBulkBlocked(result.rejected > 0);
    };
    const reviewBulk = () => {
        setReviewingBulk(true);
        router.reload({
            onSuccess: () => {
                ticketSel.clear();
                reqSel.clear();
                setBulkBlocked(false);
            },
            onFinish: () => setReviewingBulk(false),
        });
    };
    const [confirmKbDelete, setConfirmKbDelete] = useState<KbRow | null>(null);
    const [confirmKbRetire, setConfirmKbRetire] = useState<KbRow | null>(null);
    const [retirementReason, setRetirementReason] = useState('');

    /* ---------------- requester KB browse (§I) ---------------- */
    const [readerArticleId, setReaderArticleId] = useState<number | null>(null);
    const [kbSearch, setKbSearch] = useState('');
    const [kbCategory, setKbCategory] = useState<string>(ALL);
    const [kbStatus, setKbStatus] = useState<string>(ALL);
    // Every rail view carries its own header filter pills (PAGE_HEADER_STYLE_GUIDE.md §6).
    const [overviewPriority, setOverviewPriority] = useState<string>(ALL);
    const [reportDays, setReportDays] = useState(30);
    const [catalogQuery, setCatalogQuery] = useState('');
    const [catalogCategory, setCatalogCategory] = useState<string>(ALL);
    // The server is canonical; this local overlay keeps the open reader in
    // sync while its partial Inertia refresh returns the updated payload.
    const [submittedKbVotes, setSubmittedKbVotes] = useState<
        Record<number, boolean>
    >({});
    const [submittingKbVoteFor, setSubmittingKbVoteFor] = useState<
        number | null
    >(null);
    const ticketMutationAllowed = (id: number) =>
        manageableTickets.some((ticket) => ticket.id === id);
    useEffect(() => {
        const allowed = (id: number) =>
            can.manage &&
            (tickets?.data ?? []).some(
                (ticket) => ticket.id === id && ticket.can?.manage === true,
            );
        if (
            (modal?.type === 'assign-ticket' || modal?.type === 'resolve') &&
            !allowed(modal.ticket.id)
        )
            setModal(null);
        if (closeTicket && !allowed(closeTicket.id)) setCloseTicket(null);
        if (waitingTicket && !allowed(waitingTicket.id)) setWaitingTicket(null);
        if (reopenTicket?.audience === 'agent' && !allowed(reopenTicket.id))
            setReopenTicket(null);
    }, [can.manage, tickets, modal, closeTicket, waitingTicket, reopenTicket]);

    const filteredKb = kbPublished.filter((a) => {
        const q = kbSearch.trim().toLowerCase();
        return (
            (kbCategory === ALL || a.category === kbCategory) &&
            (q === '' ||
                a.title.toLowerCase().includes(q) ||
                (a.body ?? '').toLowerCase().includes(q))
        );
    });
    /** Agent knowledge list, narrowed by the header search + pills. */
    const agentKb = kbArticles.filter((a) => {
        const q = kbSearch.trim().toLowerCase();
        return (
            (kbCategory === ALL || a.category === kbCategory) &&
            (kbStatus === ALL || a.status === kbStatus) &&
            (q === '' ||
                a.title.toLowerCase().includes(q) ||
                (a.body ?? '').toLowerCase().includes(q))
        );
    });
    const currentReaderArticle =
        (showKnowledgeCatalogue ? kbArticles : kbPublished).find(
            (article) => article.id === readerArticleId,
        ) ?? null;
    useEffect(() => {
        if (readerArticleId !== null && currentReaderArticle === null) {
            setReaderArticleId(null);
        }
    }, [readerArticleId, currentReaderArticle]);
    useEffect(() => {
        if (
            modal?.type === 'kb' &&
            (!canAuthorKnowledge ||
                (modal.article &&
                    !kbArticles.some(
                        (a) =>
                            a.id === modal.article?.id &&
                            a.can.author &&
                            a.status === 'draft',
                    )))
        ) {
            setModal(null);
        }
        if (
            confirmKbDelete &&
            (!canAuthorKnowledge ||
                !kbArticles.some(
                    (a) =>
                        a.id === confirmKbDelete.id &&
                        a.can.author &&
                        a.status === 'draft',
                ))
        ) {
            setConfirmKbDelete(null);
        }
        if (
            confirmKbRetire &&
            (!canReviewKnowledge ||
                !kbArticles.some(
                    (a) =>
                        a.id === confirmKbRetire.id &&
                        a.can.review &&
                        a.status === 'published',
                ))
        ) {
            setConfirmKbRetire(null);
            setRetirementReason('');
        }
    }, [
        canAuthorKnowledge,
        canReviewKnowledge,
        kbArticles,
        modal,
        confirmKbDelete,
        confirmKbRetire,
    ]);
    const readerVote = currentReaderArticle
        ? (submittedKbVotes[currentReaderArticle.id] ??
          ('user_vote' in currentReaderArticle
              ? currentReaderArticle.user_vote
              : null))
        : null;

    /** Open the reader and count the read (server guards publication and access). */
    const openArticle = (a: KbPublishedRow) => {
        if (!kbPublished.some((article) => article.id === a.id)) return;
        setReaderArticleId(a.id);
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

    const voteHelpful = (a: Pick<KbPublishedRow, 'id'>, helpful: boolean) => {
        const serverArticle = kbPublished.find(
            (article) => article.id === a.id,
        );
        if (
            !serverArticle ||
            submittingKbVoteFor !== null ||
            (submittedKbVotes[a.id] ?? serverArticle.user_vote) !== null
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

    const runBulkTo = (
        url: string,
        sel: ReturnType<typeof useRowSelection>,
        payload: Record<string, unknown>,
        selectedIds = [...sel.selected],
    ) => {
        if (selectedIds.length === 0 || bulkBusy) return;
        setBulkBusy(true);
        setBulkError(null);
        let responded = false;
        router.post(
            url,
            { ids: selectedIds, ...payload },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (nextPage) => {
                    responded = true;
                    const result = readItBulkResult(
                        nextPage.props.bulkResult,
                        url.includes('/tickets/') ? 'tickets' : 'provisioning',
                    );
                    if (!result) {
                        const flash = nextPage.props.flash as
                            | { error?: string }
                            | undefined;
                        setBulkError(
                            flash?.error ??
                                'The response did not confirm each selected record. Review the current list before trying again.',
                        );
                        setBulkBlocked(true);
                        return;
                    }
                    acceptBulkResult(result);
                    if (result.rejected === 0) setBulkPlan(null);
                    else
                        setBulkError(
                            'Some changes were not applied. Your explanation is retained here. Cancel this draft, review the current list and select the remaining records before retrying.',
                        );
                },
                onError: (errors) => {
                    responded = true;
                    setBulkError(
                        Object.values(errors).join(' ') ||
                            'The change could not be saved. Review the validation messages and try again.',
                    );
                },
                onFinish: () => {
                    setBulkBusy(false);
                    if (!responded) {
                        setBulkError(
                            'The connection ended before the outcome was confirmed. Review the current list before retrying.',
                        );
                        setBulkBlocked(true);
                    }
                },
            },
        );
    };
    const runBulk = (payload: Record<string, unknown>) => {
        if (payload.action === 'assign' || payload.action === 'priority') {
            setBulkError(null);
            setBulkBlocked(false);
            setBulkPlan({
                actorId,
                ids: [...ticketSel.selected],
                versions: { ...ticketSel.versions },
                payload,
                description:
                    payload.action === 'priority'
                        ? `Priority: ${label(String(payload.priority))}`
                        : payload.assigned_to_user_id === null
                          ? 'Remove the individual technician assignment'
                          : `Assign technician: ${assignees.find((person) => person.id === payload.assigned_to_user_id)?.name ?? 'Selected technician'}`,
            });
        } else
            runBulkTo('/it/tickets/bulk', ticketSel, {
                ...payload,
                expected_versions: ticketSel.versions,
            });
    };
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
        if (
            !confirmKbRetire ||
            !canReviewKnowledge ||
            !kbArticles.some(
                (a) =>
                    a.id === confirmKbRetire.id &&
                    a.can.review &&
                    a.status === 'published',
            )
        )
            return;
        if (retirementReason.trim() === '') {
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
        const canAuthor = canAuthorKnowledge && a.can.author;
        const canReview = canReviewKnowledge && a.can.review;
        const lifecycleActions =
            a.status === 'draft' && canAuthor
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
                        ...(canReview
                            ? [
                                  {
                                      kind: 'item' as const,
                                      label: 'Approve & publish',
                                      icon: CheckCircle2,
                                      tone: 'success' as const,
                                      onSelect: () =>
                                          act('post', `/it/kb/${a.id}/publish`),
                                  },
                              ]
                            : []),
                        ...(canAuthor
                            ? [
                                  {
                                      kind: 'item' as const,
                                      label: 'Return to draft',
                                      icon: RotateCcw,
                                      onSelect: () =>
                                          act('post', `/it/kb/${a.id}/restore`),
                                  },
                              ]
                            : []),
                    ]
                  : a.status === 'published' && canReview
                    ? [
                          {
                              kind: 'item' as const,
                              label: 'Retire article',
                              icon: Archive,
                              onSelect: () => setConfirmKbRetire(a),
                          },
                      ]
                    : a.status === 'retired' && canAuthor
                      ? [
                            {
                                kind: 'item' as const,
                                label: 'Restore as draft',
                                icon: RotateCcw,
                                onSelect: () =>
                                    act('post', `/it/kb/${a.id}/restore`),
                            },
                        ]
                      : [];

        const deleteDraft =
            a.status === 'draft' && canAuthor
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
            ...(a.status === 'draft' && canAuthor
                ? [
                      {
                          kind: 'item' as const,
                          label: 'Edit',
                          icon: Pencil,
                          onSelect: () => setModal({ type: 'kb', article: a }),
                      },
                  ]
                : []),
            ...lifecycleActions,
            ...deleteDraft,
        ]);
    };

    /* ---------------- row context menus ---------------- */

    const requestActions = (r: RequestRow) => {
        const open =
            can.manage &&
            (r.status === 'pending' ||
                r.status === 'in_progress' ||
                r.status === 'failed');
        return compactMenu([
            // Available on any request — a fulfilled item can still arrive broken.
            {
                label: 'Copy request summary',
                icon: Copy,
                onClick: () =>
                    copyText(
                        `${r.employee.name} · ${r.item}`,
                        'Request summary',
                    ),
            },
            can.manage && {
                label: 'Raise linked ticket',
                icon: Ticket,
                onClick: () =>
                    setModal({
                        type: 'ticket',
                        provisioning: { id: r.id, item: r.item },
                    }),
            },
            ...(r.linked_ticket
                ? [
                      {
                          label: `Open ${r.linked_ticket.reference ?? 'linked ticket'}`,
                          icon: Inbox,
                          onClick: () => setPeekId(r.linked_ticket!.id),
                      },
                      {
                          label: 'Copy link',
                          icon: Link2,
                          onClick: () =>
                              copyText(
                                  `${window.location.origin}/it/tickets/${r.linked_ticket!.id}`,
                                  'Link',
                              ),
                      },
                  ]
                : []),
            ...(open
                ? ([
                      { separator: true },
                      {
                          label: 'Fulfil…',
                          icon: CheckCircle2,

                          onClick: () =>
                              setModal({ type: 'fulfil', request: r }),
                      },
                      ...(r.approval_required &&
                      r.approval_status !== 'approved'
                          ? [
                                {
                                    label: 'Approve step',
                                    icon: UserCog,
                                    onClick: () =>
                                        act(
                                            'post',
                                            `/it/provisioning/${r.id}/approve`,
                                        ),
                                },
                            ]
                          : []),
                      {
                          label: r.assignee ? 'Reassign…' : 'Assign…',
                          icon: UserCog,
                          onClick: () =>
                              setModal({ type: 'assign-request', request: r }),
                      },
                      {
                          label: 'Record failure…',
                          icon: XCircle,
                          danger: true,
                          onClick: () =>
                              setModal({ type: 'fail-request', request: r }),
                      },
                      { separator: true },
                      {
                          label: 'Cancel request',
                          icon: XCircle,
                          danger: true,
                          onClick: () => setCancelRequest(r),
                      },
                  ] as const)
                : []),
        ]);
    };

    const ticketActions = (t: TicketRow) => {
        const canManageRow = canManageTicket(t);
        const workable =
            canManageRow &&
            (t.status === 'open' ||
                t.status === 'in_progress' ||
                t.status === 'waiting');
        return compactMenu([
            {
                label: 'Open',
                icon: Ticket,
                onClick: () => router.visit(`/it/tickets/${t.id}`),
            },
            {
                label: 'Quick peek',
                icon: Inbox,
                onClick: () => setPeekId(t.id),
            },
            { separator: true },
            ...(workable
                ? [
                      ...(t.status === 'open'
                          ? [
                                {
                                    label: 'Start work',
                                    icon: Play,
                                    onClick: () =>
                                        propertyMutation.submit(
                                            t.id,
                                            t.lock_version,
                                            {
                                                status: 'in_progress',
                                            },
                                        ),
                                },
                            ]
                          : []),
                      {
                          label: t.assignee ? 'Reassign…' : 'Assign…',
                          icon: UserCog,
                          onClick: () =>
                              setModal({ type: 'assign-ticket', ticket: t }),
                      },
                      {
                          label:
                              t.status === 'waiting'
                                  ? 'Edit waiting details…'
                                  : 'Set waiting…',
                          icon: Timer,
                          onClick: () => setWaitingTicket(t),
                      },
                      { separator: true },
                      {
                          label: 'Resolve…',
                          icon: CheckCircle2,

                          onClick: () =>
                              setModal({
                                  type: 'resolve',
                                  ticket: {
                                      id: t.id,
                                      lock_version: t.lock_version,
                                      reference: t.reference,
                                      title: t.title,
                                  },
                              }),
                      },
                  ]
                : []),
            ...(canManageRow && t.status === 'resolved'
                ? [
                      {
                          label: 'Close ticket…',
                          icon: XCircle,
                          onClick: () => setCloseTicket(t),
                      },
                      {
                          label: 'Reopen…',
                          icon: RotateCcw,
                          onClick: () =>
                              setReopenTicket({
                                  id: t.id,
                                  reference: t.reference,
                                  lock_version: t.lock_version,
                                  audience: 'agent',
                              }),
                      },
                  ]
                : []),
            ...(canManageRow && t.status === 'closed'
                ? [
                      {
                          label: 'Reopen…',
                          icon: RotateCcw,
                          onClick: () =>
                              setReopenTicket({
                                  id: t.id,
                                  reference: t.reference,
                                  lock_version: t.lock_version,
                                  audience: 'agent',
                              }),
                      },
                  ]
                : []),
            { separator: true },
            {
                label: 'Copy reference',
                icon: Copy,
                onClick: () =>
                    copyText(t.reference, t.reference ?? 'Reference'),
            },
            {
                label: 'Copy link',
                icon: Link2,
                onClick: () =>
                    copyText(
                        `${window.location.origin}/it/tickets/${t.id}`,
                        'Link',
                    ),
            },
        ]);
    };

    /** My-tickets row menu (requester-facing, §O). */
    const myTicketActions = (t: MyTicketRow) =>
        compactMenu([
            {
                label: 'Open',
                icon: Ticket,
                onClick: () => router.visit(`/it/tickets/${t.id}`),
            },
            t.can_reply === true && {
                label: 'Reply',
                icon: MessageSquare,
                onClick: () => router.visit(`/it/tickets/${t.id}`),
            },
            ...(t.can_reopen === true
                ? ([
                      {
                          label: 'Reopen…',
                          icon: RotateCcw,
                          onClick: () =>
                              setReopenTicket({
                                  id: t.id,
                                  reference: t.reference,
                                  lock_version: t.lock_version,
                                  audience: 'requester',
                              }),
                      },
                  ] as const)
                : []),
            { separator: true },
            {
                label: 'Copy reference',
                icon: Copy,
                onClick: () =>
                    copyText(t.reference, t.reference ?? 'Reference'),
            },
        ]);

    /* ---------------- render ---------------- */

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="IT & Support" />
            {propertyMutation.recovery}
            <TicketTriageReasonDialog
                open={
                    bulkPlan !== null &&
                    bulkPlan.actorId === actorId &&
                    can.manage &&
                    bulkPlan.ids.every(ticketMutationAllowed)
                }
                descriptions={
                    bulkPlan
                        ? [
                              {
                                  label: 'Selected tickets',
                                  value: String(bulkPlan.ids.length),
                              },
                              { label: 'Change', value: bulkPlan.description },
                          ]
                        : []
                }
                processing={bulkBusy}
                blocked={bulkBlocked}
                error={bulkError}
                onCancel={() => {
                    setBulkPlan(null);
                    setBulkError(null);
                }}
                onConfirm={(reason) => {
                    if (
                        !bulkPlan ||
                        bulkPlan.actorId !== actorId ||
                        !can.manage ||
                        !bulkPlan.ids.every(ticketMutationAllowed)
                    )
                        return;
                    runBulkTo(
                        '/it/tickets/bulk',
                        ticketSel,
                        {
                            ...bulkPlan.payload,
                            expected_versions: bulkPlan.versions,
                            [bulkPlan.payload.action === 'priority'
                                ? 'priority_reason'
                                : 'routing_reason']: reason,
                        },
                        bulkPlan.ids,
                    );
                }}
            />
            {ctx.element}
            <ItWizard
                modal={
                    modal?.type === 'kb'
                        ? canAuthorKnowledge &&
                          (!modal.article ||
                              kbArticles.some(
                                  (a) =>
                                      a.id === modal.article?.id &&
                                      a.can.author &&
                                      a.status === 'draft',
                              ))
                            ? modal
                            : null
                        : (modal?.type === 'assign-ticket' ||
                                modal?.type === 'resolve') &&
                            !ticketMutationAllowed(modal.ticket.id)
                          ? null
                          : modal
                }
                assignees={assignees}
                employeeOptions={employeeOptions}
                assetOptions={assetOptions}
                siteOptions={siteOptions}
                deviceOptions={deviceOptions}
                serviceOptions={serviceOptions}
                intakePolicy={intakePolicy}
                slaPolicies={slaPolicies}
                slaCalendar={slaCalendar}
                kbSuggestions={kbPublished}
                kbOptions={kbOptions}
                onOpenArticle={(id) => {
                    const a = kbPublished.find((x) => x.id === id);
                    if (a) {
                        openArticle(a);
                    }
                }}
                onDraftKb={
                    canAuthorKnowledge
                        ? (draft) => setModal({ type: 'kb', draft })
                        : undefined
                }
                onClose={() => setModal(null)}
            />
            <TicketDrawer ticketId={peekId} onClose={() => setPeekId(null)} />

            {/* KB reader (requester browse) */}
            <Dialog
                open={currentReaderArticle !== null}
                onOpenChange={(open) => !open && setReaderArticleId(null)}
            >
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{currentReaderArticle?.title}</DialogTitle>
                        <DialogDescription>
                            {showKnowledgeCatalogue
                                ? 'Read the current article content and lifecycle status before taking a permitted action.'
                                : 'Read this published support article, then record whether it helped resolve the request.'}
                        </DialogDescription>
                    </DialogHeader>
                    {currentReaderArticle ? (
                        <div className="space-y-4">
                            <StatusBadge variant="info" size="sm">
                                {label(currentReaderArticle.category)}
                            </StatusBadge>
                            {'status' in currentReaderArticle && (
                                <StatusBadge variant="neutral" size="sm">
                                    {label(currentReaderArticle.status)}
                                </StatusBadge>
                            )}
                            {currentReaderArticle.related_service ? (
                                <p className="text-[12px] text-muted-foreground">
                                    Service:{' '}
                                    <span className="font-semibold text-foreground">
                                        {currentReaderArticle.related_service}
                                    </span>
                                </p>
                            ) : null}
                            <div className="max-h-[50vh] overflow-y-auto rounded-xl border border-border bg-muted/30 p-4">
                                <KbPreview
                                    body={currentReaderArticle.body ?? ''}
                                />
                            </div>
                            {!showKnowledgeCatalogue && (
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
                                            {readerVote
                                                ? 'Helpful'
                                                : 'Not helpful'}
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
                                                    currentReaderArticle.id
                                                }
                                                onClick={() =>
                                                    voteHelpful(
                                                        currentReaderArticle,
                                                        true,
                                                    )
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
                                                    currentReaderArticle.id
                                                }
                                                onClick={() =>
                                                    voteHelpful(
                                                        currentReaderArticle,
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
                            )}
                        </div>
                    ) : null}
                </DialogContent>
            </Dialog>

            <TicketCloseDialog
                open={closeSelectedTickets && ticketSel.selected.size > 0}
                onOpenChange={setCloseSelectedTickets}
                scope="bulk"
                ticketIds={[...ticketSel.selected]}
                expectedVersions={ticketSel.versions}
                onBulkResult={acceptBulkResult}
            />
            <TicketCloseDialog
                open={
                    closeTicket !== null &&
                    ticketMutationAllowed(closeTicket.id)
                }
                onOpenChange={(open) => !open && setCloseTicket(null)}
                scope="single"
                ticketIds={closeTicket ? [closeTicket.id] : []}
                expectedVersions={
                    closeTicket
                        ? { [closeTicket.id]: closeTicket.lock_version }
                        : {}
                }
                ticketReference={closeTicket?.reference}
            />
            <TicketReopenDialog
                open={
                    reopenTicket !== null &&
                    (reopenTicket.audience === 'requester' ||
                        ticketMutationAllowed(reopenTicket.id))
                }
                onOpenChange={(open) => !open && setReopenTicket(null)}
                ticketId={reopenTicket?.id ?? null}
                expectedVersion={reopenTicket?.lock_version ?? null}
                ticketReference={reopenTicket?.reference}
                audience={reopenTicket?.audience ?? 'agent'}
            />
            <TicketWaitingDialog
                open={waitingSelectedTickets && ticketSel.selected.size > 0}
                onOpenChange={setWaitingSelectedTickets}
                scope="bulk"
                ticketIds={[...ticketSel.selected]}
                expectedVersions={ticketSel.versions}
                onBulkResult={acceptBulkResult}
            />
            <TicketWaitingDialog
                open={
                    waitingTicket !== null &&
                    ticketMutationAllowed(waitingTicket.id)
                }
                onOpenChange={(open) => !open && setWaitingTicket(null)}
                scope="single"
                ticketIds={waitingTicket ? [waitingTicket.id] : []}
                expectedVersions={
                    waitingTicket
                        ? { [waitingTicket.id]: waitingTicket.lock_version }
                        : {}
                }
                ticketReference={waitingTicket?.reference}
                current={
                    waitingTicket?.status === 'waiting'
                        ? {
                              party: waitingTicket.waiting_party ?? 'other',
                              reason: waitingTicket.waiting_reason ?? null,
                              next_action: waitingTicket.next_action ?? null,
                              since: waitingTicket.waiting_since ?? null,
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
                article={canAuthorKnowledge ? confirmKbDelete : null}
                open={
                    confirmKbDelete !== null &&
                    canAuthorKnowledge &&
                    kbArticles.some(
                        (a) =>
                            a.id === confirmKbDelete.id &&
                            a.can.author &&
                            a.status === 'draft',
                    )
                }
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
                open={
                    confirmKbRetire !== null &&
                    canReviewKnowledge &&
                    kbArticles.some(
                        (a) =>
                            a.id === confirmKbRetire.id &&
                            a.can.review &&
                            a.status === 'published',
                    )
                }
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
                <div className="flex flex-col gap-5">
                    <ItHero
                        summary={summary}
                        can={can}
                        onRaise={() => setModal({ type: 'raise' })}
                        onLog={() => setModal({ type: 'ticket' })}
                        actions={
                            can.view &&
                            tab === 'tickets' &&
                            can.edit_sla &&
                            slaPolicies ? (
                                <PageHeaderGlassButton
                                    onClick={() => setModal({ type: 'sla' })}
                                >
                                    SLA policies
                                </PageHeaderGlassButton>
                            ) : undefined
                        }
                        search={
                            can.view && tab === 'tickets' ? (
                                <PageHeaderSearch
                                    value={search}
                                    onChange={(value) => updateSearch(value)}
                                    placeholder="Search reference, title, requester…"
                                />
                            ) : can.request && tab === 'my-tickets' ? (
                                <PageHeaderSearch
                                    value={mySearch}
                                    onChange={(value) =>
                                        updateSearch(value, true)
                                    }
                                    placeholder="Search your requests…"
                                />
                            ) : tab === 'knowledge' ? (
                                <PageHeaderSearch
                                    value={kbSearch}
                                    onChange={setKbSearch}
                                    placeholder="Search the knowledge base…"
                                />
                            ) : can.request && tab === 'catalog' ? (
                                <PageHeaderSearch
                                    value={catalogQuery}
                                    onChange={setCatalogQuery}
                                    placeholder="Search requests, systems, needs…"
                                />
                            ) : undefined
                        }
                        filters={
                            can.view && tab === 'tickets' ? (
                                <>
                                    {layoutToggle}
                                    <PageHeaderFilterSelect
                                        label="Sort"
                                        value={filters?.sort ?? ALL}
                                        allValue={ALL}
                                        options={[
                                            {
                                                value: 'reference',
                                                label: 'Reference',
                                            },
                                            {
                                                value: 'created',
                                                label: 'Raised',
                                            },
                                            {
                                                value: 'updated',
                                                label: 'Updated',
                                            },
                                            {
                                                value: 'priority',
                                                label: 'Priority',
                                            },
                                            {
                                                value: 'status',
                                                label: 'Status',
                                            },
                                        ]}
                                        onChange={(value) =>
                                            navigate({
                                                sort:
                                                    value === ALL
                                                        ? undefined
                                                        : value,
                                                dir:
                                                    value === ALL
                                                        ? undefined
                                                        : (filters?.dir ??
                                                          'desc'),
                                            })
                                        }
                                    />
                                    {filters?.sort && (
                                        <PageHeaderFilterButton
                                            onClick={() =>
                                                navigate({
                                                    dir:
                                                        filters?.dir === 'asc'
                                                            ? 'desc'
                                                            : 'asc',
                                                })
                                            }
                                        >
                                            {filters?.dir === 'asc'
                                                ? 'Ascending'
                                                : 'Descending'}
                                        </PageHeaderFilterButton>
                                    )}

                                    <PageHeaderFilterSelect
                                        label="Queue totals"
                                        value={filters?.view ?? ALL}
                                        allValue={ALL}
                                        options={availableTicketViews.map(
                                            (v) => ({
                                                value: v.key,
                                                label: ticketViewLabel(
                                                    v,
                                                    summary?.tickets?.views?.[
                                                        v.key
                                                    ],
                                                ),
                                            }),
                                        )}
                                        onChange={(v) =>
                                            v === ALL
                                                ? clearTicketFilters()
                                                : applyView(v)
                                        }
                                    />
                                    <PageHeaderFilterSelect
                                        label="Status"
                                        value={filters?.ticket_status ?? ALL}
                                        allValue={ALL}
                                        options={TICKET_STATUSES.map((v) => ({
                                            value: v,
                                            label: label(v),
                                        }))}
                                        onChange={(v) =>
                                            applyFilter('ticket_status', v)
                                        }
                                    />
                                    <PageHeaderFilterSelect
                                        label="Priority"
                                        value={filters?.ticket_priority ?? ALL}
                                        allValue={ALL}
                                        options={TICKET_PRIORITIES.map((v) => ({
                                            value: v,
                                            label: label(v),
                                        }))}
                                        onChange={(v) =>
                                            applyFilter('ticket_priority', v)
                                        }
                                    />
                                    <PageHeaderFilterSelect
                                        label="Category"
                                        value={filters?.ticket_category ?? ALL}
                                        allValue={ALL}
                                        options={TICKET_CATEGORIES.map((v) => ({
                                            value: v,
                                            label: label(v),
                                        }))}
                                        onChange={(v) =>
                                            applyFilter('ticket_category', v)
                                        }
                                    />
                                    <PageHeaderFilterSelect
                                        label="SLA"
                                        value={filters?.sla ?? ALL}
                                        allValue={ALL}
                                        options={SLA_STATES.map((v) => ({
                                            value: v,
                                            label: label(v),
                                        }))}
                                        onChange={(v) => applyFilter('sla', v)}
                                    />
                                    <PageHeaderFilterSelect
                                        label="Site"
                                        value={
                                            filters?.site_id != null
                                                ? String(filters.site_id)
                                                : ALL
                                        }
                                        allValue={ALL}
                                        options={siteOptions.map((v) => ({
                                            value: String(v.id),
                                            label: v.name,
                                        }))}
                                        onChange={(v) =>
                                            applyFilter('site_id', v)
                                        }
                                    />
                                    <PageHeaderFilterSelect
                                        label="Assignee"
                                        value={
                                            filters?.assignee != null
                                                ? String(filters.assignee)
                                                : ALL
                                        }
                                        allValue={ALL}
                                        options={assignees.map((v) => ({
                                            value: String(v.id),
                                            label: v.name,
                                        }))}
                                        onChange={(v) =>
                                            applyFilter('assignee', v)
                                        }
                                    />
                                    <Popover>
                                        <PopoverTrigger asChild>
                                            <PageHeaderFilterButton>
                                                More filters
                                            </PageHeaderFilterButton>
                                        </PopoverTrigger>
                                        <PopoverContent
                                            align="end"
                                            className="w-[min(92vw,480px)] space-y-3"
                                        >
                                            <DateRange
                                                from={filters?.from ?? ''}
                                                to={filters?.to ?? ''}
                                                onChange={(k, val) =>
                                                    applyFilter(k, val)
                                                }
                                            />
                                            <TicketAdvancedFilters
                                                values={{
                                                    source:
                                                        filters?.source ?? null,
                                                    workType:
                                                        filters?.work_type ??
                                                        null,
                                                    service:
                                                        filters?.service ??
                                                        null,
                                                    age: filters?.age ?? null,
                                                    missing:
                                                        filters?.missing ??
                                                        null,
                                                    reopened:
                                                        filters?.reopened ??
                                                        false,
                                                    firstContact:
                                                        filters?.first_contact ??
                                                        false,
                                                    openOnly:
                                                        filters?.open_only ??
                                                        false,
                                                    deviceLinked:
                                                        filters?.device_linked ??
                                                        false,
                                                    resolvedFrom:
                                                        filters?.resolved_from ??
                                                        null,
                                                    resolvedTo:
                                                        filters?.resolved_to ??
                                                        null,
                                                }}
                                                services={serviceOptions}
                                                onChange={(key, value) =>
                                                    navigate({ [key]: value })
                                                }
                                                onClear={
                                                    clearAdvancedTicketFilters
                                                }
                                            />

                                            <TicketSavedFilters
                                                filters={savedTicketFilters}
                                                activeId={
                                                    activeSavedTicketFilterId
                                                }
                                                currentFilters={
                                                    currentTicketFilters
                                                }
                                                canSave={ticketFiltersActive}
                                                onApply={applySavedTicketFilter}
                                            />
                                        </PopoverContent>
                                    </Popover>
                                </>
                            ) : can.view && tab === 'provisioning' ? (
                                <>
                                    {layoutToggle}
                                    <PageHeaderFilterSelect
                                        label="Status"
                                        value={filters?.status ?? ALL}
                                        allValue={ALL}
                                        options={REQUEST_STATUSES.map((v) => ({
                                            value: v,
                                            label: label(v),
                                        }))}
                                        onChange={(v) =>
                                            applyFilter('status', v)
                                        }
                                    />
                                    <PageHeaderFilterSelect
                                        label="Type"
                                        value={filters?.type ?? ALL}
                                        allValue={ALL}
                                        options={REQUEST_TYPES.map((v) => ({
                                            value: v,
                                            label: label(v),
                                        }))}
                                        onChange={(v) => applyFilter('type', v)}
                                    />
                                    <PageHeaderFilterSelect
                                        label="Assignee"
                                        value={
                                            filters?.assignee != null
                                                ? String(filters.assignee)
                                                : ALL
                                        }
                                        allValue={ALL}
                                        options={assignees.map((v) => ({
                                            value: String(v.id),
                                            label: v.name,
                                        }))}
                                        onChange={(v) =>
                                            applyFilter('assignee', v)
                                        }
                                    />
                                </>
                            ) : can.view && tab === 'overview' ? (
                                <PageHeaderFilterSelect
                                    label="Priority"
                                    value={overviewPriority}
                                    allValue={ALL}
                                    options={TICKET_PRIORITIES.map((v) => ({
                                        value: v,
                                        label: label(v),
                                    }))}
                                    onChange={setOverviewPriority}
                                />
                            ) : showKnowledgeCatalogue &&
                              tab === 'knowledge' ? (
                                <>
                                    <PageHeaderFilterSelect
                                        label="Category"
                                        value={kbCategory}
                                        allValue={ALL}
                                        options={TICKET_CATEGORIES.map((v) => ({
                                            value: v,
                                            label: label(v),
                                        }))}
                                        onChange={setKbCategory}
                                    />
                                    <PageHeaderFilterSelect
                                        label="Status"
                                        value={kbStatus}
                                        allValue={ALL}
                                        options={[
                                            ...new Set(
                                                kbArticles.map((a) => a.status),
                                            ),
                                        ].map((v) => ({
                                            value: v,
                                            label: label(v),
                                        }))}
                                        onChange={setKbStatus}
                                    />
                                </>
                            ) : can.view && tab === 'reports' ? (
                                <PageHeaderFilterSelect
                                    label="30 days"
                                    value={String(reportDays)}
                                    allValue="30"
                                    options={REPORT_RANGES.map((r) => ({
                                        value: String(r.days),
                                        label: r.label,
                                    }))}
                                    onChange={(v) => setReportDays(Number(v))}
                                />
                            ) : can.request && tab === 'catalog' ? (
                                <PageHeaderFilterSelect
                                    label="Category"
                                    value={catalogCategory}
                                    allValue={ALL}
                                    options={[
                                        ...new Set(
                                            catalogItems.map((i) => i.category),
                                        ),
                                    ].map((v) => ({
                                        value: v,
                                        label: label(v),
                                    }))}
                                    onChange={setCatalogCategory}
                                />
                            ) : can.request && tab === 'my-tickets' ? (
                                <>
                                    {layoutToggle}
                                    <PageHeaderFilterSelect
                                        label="Status"
                                        value={myStatus}
                                        allValue={ALL}
                                        options={[
                                            ...new Set([
                                                ...myTickets.map(
                                                    (t) => t.status,
                                                ),
                                                ...(myProvisioning?.total
                                                    ? [
                                                          'pending',
                                                          'in_progress',
                                                          'failed',
                                                          'done',
                                                          'cancelled',
                                                      ]
                                                    : []),
                                            ]),
                                        ].map((v) => ({
                                            value: v,
                                            label: label(v),
                                        }))}
                                        onChange={(value) =>
                                            navigate({
                                                my_status:
                                                    value === ALL
                                                        ? undefined
                                                        : value,
                                            })
                                        }
                                    />
                                </>
                            ) : !showKnowledgeCatalogue &&
                              can.request &&
                              tab === 'knowledge' ? (
                                <PageHeaderFilterSelect
                                    label="Category"
                                    value={kbCategory}
                                    allValue={ALL}
                                    options={TICKET_CATEGORIES.map((v) => ({
                                        value: v,
                                        label: label(v),
                                    }))}
                                    onChange={setKbCategory}
                                />
                            ) : undefined
                        }
                        rail={
                            <PageHeaderRail
                                value={tab}
                                onSelect={setTab}
                                items={tabItems.map((item) => ({
                                    key: item.id,
                                    label: item.label,
                                    icon: item.icon,
                                    count:
                                        typeof item.badge === 'number'
                                            ? item.badge
                                            : undefined,
                                }))}
                                ariaLabel="IT views"
                                onItemContextMenu={tabMenu}
                                decorations={tabDecorations}
                            />
                        }
                    />
                    {can.view &&
                        (tab === 'tickets' || tab === 'provisioning') && (
                            <ItBulkResultPanel
                                result={
                                    bulkOutcome.actorId === actorId &&
                                    bulkOutcome.result?.resource ===
                                        (tab === 'tickets'
                                            ? 'tickets'
                                            : 'provisioning')
                                        ? bulkOutcome.result
                                        : null
                                }
                                error={bulkError}
                                onReview={reviewBulk}
                                reviewing={reviewingBulk}
                            />
                        )}
                    {/* ── Overview (agents) ── */}
                    {can.view &&
                        tab === 'overview' &&
                        overview &&
                        summary?.tickets && (
                            <ItOverview
                                overview={overview}
                                priority={
                                    overviewPriority === ALL
                                        ? null
                                        : overviewPriority
                                }
                                onOpenTicket={(id) => setPeekId(id)}
                            />
                        )}

                    {/* ── Reports (agents, §L) ── */}
                    {can.view && tab === 'reports' && (
                        <ItReports days={reportDays} />
                    )}

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
                                                                <p className="text-xs text-muted-foreground">
                                                                    {workflow.template_version
                                                                        ? `Template v${workflow.template_version}${workflow.template_provenance === 'legacy_current' ? ' · captured legacy configuration' : ''}`
                                                                        : 'Historical template version not recorded'}
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
                                        disabled={bulkBusy || bulkBlocked}
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

                            <ListCaption
                                title="Provisioning steps"
                                caption={`${requests?.data.length ?? 0} of ${requests?.total ?? 0} shown`}
                                right={
                                    can.manage &&
                                    (requests?.data.length ?? 0) > 0 ? (
                                        <label className="flex min-h-11 items-center gap-2 text-sm">
                                            <Checkbox
                                                aria-label="Select all provisioning steps on this page"
                                                disabled={bulkBusy}
                                                checked={
                                                    reqSel.allOnPage
                                                        ? true
                                                        : reqSel.someOnPage
                                                          ? 'indeterminate'
                                                          : false
                                                }
                                                onCheckedChange={(checked) =>
                                                    reqSel.toggleAll(
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            Select this page
                                        </label>
                                    ) : undefined
                                }
                            />
                            <ItProvisioningList
                                rows={requests?.data ?? []}
                                view={listView}
                                actionsFor={requestActions}
                                selected={reqSel.selected}
                                onSelect={(row, checked) =>
                                    reqSel.toggle(row.id, checked)
                                }
                                canManage={can.manage}
                                busy={bulkBusy}
                                today={todayISO()}
                                emptyState={
                                    <EmptyState
                                        icon={Inbox}
                                        title="No provisioning steps match"
                                        blurb="Change the filters to see other permitted work. Matching HR events and manual requests create steps here."
                                    />
                                }
                            />
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
                                        disabled={bulkBusy || bulkBlocked}
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
                                        disabled={bulkBusy || bulkBlocked}
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
                                        disabled={bulkBusy || bulkBlocked}
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

                            <ListCaption
                                title="Tickets"
                                caption={`${tickets?.data.length ?? 0} of ${tickets?.total ?? 0} shown · filtered results`}
                                right={
                                    can.manage &&
                                    manageableTickets.length > 0 ? (
                                        <label className="flex min-h-11 items-center gap-2 text-sm">
                                            <Checkbox
                                                aria-label="Select all manageable tickets on this page"
                                                disabled={bulkBusy}
                                                checked={
                                                    ticketSel.allOnPage
                                                        ? true
                                                        : ticketSel.someOnPage
                                                          ? 'indeterminate'
                                                          : false
                                                }
                                                onCheckedChange={(checked) =>
                                                    ticketSel.toggleAll(
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            Select this page
                                        </label>
                                    ) : undefined
                                }
                            />
                            <ItTicketList
                                conversationReady={conversation_ready === true}
                                rows={tickets?.data ?? []}
                                view={listView}
                                actionsFor={ticketActions}
                                onPeek={(ticket) => openTicket(ticket.id)}
                                canSelect={canManageTicket}
                                selected={ticketSel.selected}
                                onSelect={(ticket, checked) =>
                                    ticketSel.toggle(ticket.id, checked)
                                }
                                busy={bulkBusy}
                                emptyState={
                                    ticketFiltersActive ? (
                                        <EmptyState
                                            icon={Ticket}
                                            title="No tickets match"
                                            blurb="Widen or clear the filters to see more of the queue."
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
                                                    ? 'Log a helpdesk ticket with the button above.'
                                                    : 'There are no tickets in your permitted queue.'
                                            }
                                        />
                                    )
                                }
                            />
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
                            query={catalogQuery}
                            category={
                                catalogCategory === ALL ? null : catalogCategory
                            }
                        />
                    ) : null}

                    {/* ── My tickets (everyone with it.request) ── */}
                    {can.request && tab === 'my-tickets' && (
                        <>
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="text-[12.5px] text-muted-foreground">
                                    Requests you raised or that were raised for
                                    you.
                                </p>
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
                                                            expectedVersion={
                                                                t.lock_version
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                            ))}
                                    </div>
                                </div>
                            ) : null}

                            <ListCaption
                                title="Helpdesk requests"
                                caption={`${filteredMyTickets.length} of ${myTickets.length} shown`}
                            />
                            <MyTicketsList
                                conversationReady={conversation_ready === true}
                                tickets={filteredMyTickets}
                                view={listView}
                                actionsFor={myTicketActions}
                                emptyState={
                                    <EmptyState
                                        icon={Inbox}
                                        title={
                                            myQuery || myStatus !== ALL
                                                ? 'No requests match'
                                                : 'No requests yet'
                                        }
                                        blurb={
                                            myQuery || myStatus !== ALL
                                                ? 'Change the search or status filter to see your other requests.'
                                                : 'Raise a request to ask IT for help and track its progress here.'
                                        }
                                    />
                                }
                            />
                            {myProvisioning ? (
                                <MyProvisioningList
                                    page={myProvisioning}
                                    view={listView}
                                />
                            ) : null}
                        </>
                    )}

                    {/* ── Knowledge base (agents) ── */}
                    {showKnowledgeCatalogue && tab === 'knowledge' && (
                        <>
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="text-[12.5px] text-muted-foreground">
                                    Articles that deflect repeat tickets —
                                    publish the fixes people keep asking for.
                                </p>
                                {canAuthorKnowledge ? (
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
                                {agentKb.map((a) => (
                                    <div
                                        key={a.id}
                                        onContextMenu={
                                            (canAuthorKnowledge &&
                                                a.can.author) ||
                                            (canReviewKnowledge && a.can.review)
                                                ? kbMenu(a)
                                                : undefined
                                        }
                                        className="grid min-w-[920px] grid-cols-[2.5fr_1.15fr_1.6fr_1.1fr_1.1fr_44px] items-center gap-3 border-b border-border/55 px-4.5 py-3 transition-colors last:border-0 hover:bg-muted/40"
                                    >
                                        <div className="flex min-w-0 items-center gap-2">
                                            <span className="grid h-7 w-7 flex-none place-items-center rounded-lg bg-accent text-primary">
                                                <BookOpen className="h-3.5 w-3.5" />
                                            </span>
                                            <span className="min-w-0">
                                                <Button
                                                    variant="link"
                                                    className="h-auto max-w-full justify-start truncate p-0 text-[13px] font-semibold"
                                                    onClick={() =>
                                                        setReaderArticleId(a.id)
                                                    }
                                                >
                                                    {a.title}
                                                </Button>
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
                                            {(canAuthorKnowledge &&
                                                a.can.author &&
                                                a.status !== 'published') ||
                                            (canReviewKnowledge &&
                                                a.can.review &&
                                                [
                                                    'in_review',
                                                    'published',
                                                ].includes(a.status)) ? (
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
                                            canAuthorKnowledge
                                                ? 'Write the first fix people keep asking for — it deflects the ticket every time after.'
                                                : 'The knowledge base is empty.'
                                        }
                                        action={
                                            canAuthorKnowledge
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
                    {!showKnowledgeCatalogue &&
                        can.request &&
                        tab === 'knowledge' && (
                            <>
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
                                                        .replace(
                                                            /[#>*\-\n]+/g,
                                                            ' ',
                                                        )
                                                        .trim()}
                                                </span>
                                                <span className="mt-3 flex items-center gap-2 text-[11.5px] text-muted-foreground">
                                                    <StatusBadge
                                                        variant="info"
                                                        size="sm"
                                                    >
                                                        {label(a.category)}
                                                    </StatusBadge>
                                                    {a.helpful_percent !=
                                                    null ? (
                                                        <span>
                                                            {a.helpful_percent}%
                                                            helpful
                                                        </span>
                                                    ) : null}
                                                </span>
                                                {a.related_service ? (
                                                    <span className="mt-2 text-[11.5px] text-muted-foreground">
                                                        Service:{' '}
                                                        {a.related_service}
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
function useRowSelection(
    pageIds: number[],
    pageVersions: Record<number, number> = {},
) {
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [versions, setVersions] = useState<Record<number, number>>({});
    const key = pageIds.join(',');
    useEffect(() => {
        setSelected(new Set());
        setVersions({});
    }, [key]);

    const toggle = (id: number, on: boolean) => {
        if (!pageIds.includes(id)) return;
        setVersions((previous) => {
            const next = { ...previous };
            if (on && pageVersions[id] !== undefined)
                next[id] = previous[id] ?? pageVersions[id];
            else if (!on) delete next[id];
            return next;
        });
        setSelected((prev) => {
            const next = new Set(prev);
            if (on && next.size < 50) next.add(id);
            else next.delete(id);
            return next;
        });
    };

    const toggleAll = (on: boolean) => {
        setVersions((previous) =>
            on
                ? Object.fromEntries(
                      pageIds
                          .filter((id) => pageVersions[id] !== undefined)
                          .map((id) => [id, previous[id] ?? pageVersions[id]]),
                  )
                : {},
        );
        setSelected((prev) => {
            const next = new Set(prev);
            pageIds.forEach((id) => {
                if (on && next.size < 50) next.add(id);
                else if (!on) next.delete(id);
            });
            return next;
        });
    };

    return {
        selected: new Set([...selected].filter((id) => pageIds.includes(id))),
        versions: Object.fromEntries(
            Object.entries(versions).filter(([id]) =>
                pageIds.includes(Number(id)),
            ),
        ),
        clear: () => {
            setSelected(new Set());
            setVersions({});
        },
        remove: (ids: number[]) => {
            setSelected(
                (previous) =>
                    new Set([...previous].filter((id) => !ids.includes(id))),
            );
            setVersions((previous) =>
                Object.fromEntries(
                    Object.entries(previous).filter(
                        ([id]) => !ids.includes(Number(id)),
                    ),
                ),
            );
        },
        toggle,
        toggleAll,
        allOnPage:
            pageIds.length > 0 && pageIds.every((id) => selected.has(id)),
        someOnPage: pageIds.some((id) => selected.has(id)),
    };
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
