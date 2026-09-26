import {
    FLEET_WORKSPACES,
    canDiscoverFleetNavigation,
    fleetPrimaryLinkActive,
    fleetPrimaryLinks,
    visibleFleetGroups,
    type FleetNavigationPermissions,
} from '@/lib/fleet-navigation';
import { Button } from '@/components/ui/button';
import {
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAppSidebarState } from '@/hooks/use-app-sidebar-state';
import { useStableValue } from '@/hooks/use-stable-value';
import { cn, resolveUrl } from '@/lib/utils';
import {
    FINANCE_SECTIONS,
    financeHubContainsUrl,
    isFinanceHubHref,
    visibleSectionTabs as visibleFinanceSectionTabs,
} from '@/lib/finance-sections';
import {
    GOVERNANCE_SECTION_GROUP_LABELS,
    GOVERNANCE_SECTIONS,
    governanceHubContainsUrl,
    visibleSectionTabs,
} from '@/lib/governance-sections';
import { vendorRegisterTab } from '@/lib/vendor-navigation';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertOctagon,
    AlertTriangle,
    Banknote,
    BarChart3,
    Bell,
    BookOpen,
    Briefcase,
    Building2,
    CalendarClock,
    CalendarDays,
    Car,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clipboard,
    ClipboardCheck,
    ClipboardList,
    Clock,
    DollarSign,
    FileText,
    FlaskConical,
    GitBranch,
    GraduationCap,
    HardHat,
    Heart,
    HeartPulse,
    Home,
    Landmark,
    LayoutDashboard,
    LayoutGrid,
    ListChecks,
    Map,
    MapPin,
    Megaphone,
    MessageSquare,
    MessageSquareText,
    Package,
    PersonStanding,
    PieChart,
    Pill,
    Radio,
    Receipt,
    Route,
    Server,
    Settings,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Siren,
    Smartphone,
    Stethoscope,
    Target,
    Timer,
    Trash2,
    Truck,
    UserCheck,
    Users,
    Utensils,
    Wrench,
    X,
    type LucideIcon,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { buildSecurityDevicesNavigationGroups } from './security-devices/security-devices-navigation';

/* Event Horizon ink rail (APP_SHELL_STYLE_GUIDE.md §3). Sits below the
 * 58px command header; the two share the sidebar tokens so they read as one
 * continuous chrome. Row anatomy: 16px lucide icon + label; active rows take
 * the white-lift accent fill with a brand-tinted icon; sub-items are
 * indented text-only (the group header carries the icon). */
const SHELL_HEADER_HEIGHT_CLASS = 'top-[58px] h-[calc(100svh-58px)]';

const SIDEBAR_OPCN_CLASS = 'size-4 shrink-0';
const SIDEBAR_ITEM_BASE =
    'relative flex min-h-9 w-full items-center rounded-lg text-sm transition-colors outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring';
const SIDEBAR_ITEM_ACTIVE =
    'bg-sidebar-accent font-medium text-sidebar-accent-foreground';
const SIDEBAR_ITEM_INACTIVE =
    'text-sidebar-foreground hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground';
const SIDEBAR_ICON_ACTIVE = 'text-sidebar-primary';
const SIDEBAR_ICON_INACTIVE = 'text-sidebar-foreground/60';

function CountPill({
    count,
    collapsed = false,
    testId,
}: {
    count: number;
    collapsed?: boolean;
    testId?: string;
}) {
    if (!count || count <= 0) return null;
    return (
        <span
            data-test={testId}
            className={cn(
                'flex h-5 min-w-5 items-center justify-center rounded-full bg-status-critical px-1 text-[10px] leading-none font-bold text-white',
                collapsed ? 'absolute top-0.5 right-0.5' : 'ml-auto',
            )}
        >
            {count > 9 ? '9+' : count}
        </span>
    );
}

// The shell is not a persistent Inertia layout, so the rail remounts on
// every visit and its scroll offset would snap to the top on each click.
// Remember the offset across mounts (and reloads) so the link the user
// just clicked stays where it was.
const SIDEBAR_SCROLL_STORAGE_KEY = 'oblivionfindings:sidebar-scroll';
let sidebarScrollTop = 0;

function readStoredScrollTop(): number {
    if (sidebarScrollTop > 0) return sidebarScrollTop;
    try {
        const raw = window.sessionStorage.getItem(SIDEBAR_SCROLL_STORAGE_KEY);
        const parsed = raw === null ? 0 : Number(raw);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    } catch {
        return 0;
    }
}

function persistScrollTop(value: number) {
    sidebarScrollTop = value;
    try {
        window.sessionStorage.setItem(
            SIDEBAR_SCROLL_STORAGE_KEY,
            String(Math.max(0, Math.round(value))),
        );
    } catch {
        // sessionStorage unavailable (private mode): in-memory copy suffices.
    }
}

// Per-user persistence for which module groups are unfolded (same local
// convention as use-app-sidebar-state's whole-sidebar flag).
const SIDEBAR_GROUPS_STORAGE_KEY = 'oblivionfindings:sidebar-groups';

function readStoredGroupIds(): string[] {
    if (typeof window === 'undefined') return [];
    try {
        const raw = window.localStorage.getItem(SIDEBAR_GROUPS_STORAGE_KEY);
        const parsed = raw ? JSON.parse(raw) : null;
        return Array.isArray(parsed)
            ? parsed.filter(
                  (value): value is string => typeof value === 'string',
              )
            : [];
    } catch {
        return [];
    }
}

function persistGroupIds(ids: string[]) {
    if (typeof window === 'undefined') return;
    try {
        window.localStorage.setItem(
            SIDEBAR_GROUPS_STORAGE_KEY,
            JSON.stringify(ids),
        );
    } catch {
        // Storage unavailable (private mode) — the fold state just won't stick.
    }
}

function SidebarItemIcon({
    icon: Icon,
    className,
}: {
    icon: LucideIcon;
    className?: string;
}) {
    return (
        <Icon
            aria-hidden="true"
            className={cn(SIDEBAR_OPCN_CLASS, className)}
        />
    );
}

// ── Types ──────────────────────────────────────────────────────────────────

type PortalClient = {
    id: number;
    name: string;
    avatar?: string | null;
    relation?: string | null;
};

type PageProps = {
    auth: {
        user: null | {
            id: number;
            name: string;
            email: string;
            avatar?: string;
            role?: string | null;
        };
        can?: any;
        portalClients?: PortalClient[] | null;
    };
    labels?: Record<string, string>;
    branding?: { name?: string; logoUrl?: string | null };
    name?: string;
};

interface IconNavItem {
    id: string;
    icon: LucideIcon;
    label: string;
    href?: string;
    subPanel?: boolean;
    dividerAfter?: boolean;
    badge?: number;
}

export interface SubPanelGroup {
    label: string;
    items: NavItem[];
}

/** Flatten module groups into one ordered link list. Keys stay scoped to
 *  the source group so a link that appears under two captions stays unique. */
export function flattenSidebarGroups(
    groups: SubPanelGroup[],
): Array<{ key: string; item: NavItem }> {
    return groups.flatMap((group) =>
        (group.items ?? []).map((item) => ({
            key: `${group.label}:${resolveUrl(item.href)}`,
            item,
        })),
    );
}

export function filterVisibleSidebarGroups(
    groups: Array<SubPanelGroup | null | undefined>,
): SubPanelGroup[] {
    return groups.filter(
        (group): group is SubPanelGroup =>
            group !== null &&
            group !== undefined &&
            Array.isArray(group.items) &&
            group.items.length > 0,
    );
}

// ── URL matching (reused from nav-main) ────────────────────────────────────

function normalizePath(url: string): string {
    const path = url.split('?')[0] ?? '/';
    const trimmed = path.replace(/\/+$/, '');
    return trimmed.length > 0 ? trimmed : '/';
}

function matchScore(currentUrl: string, itemHref: NavItem['href']): number {
    const current = resolveUrl(currentUrl);
    const item = resolveUrl(itemHref);

    const currentParts = current.split('?');
    const itemParts = item.split('?');
    const currentPath = currentParts[0] ?? '/';
    const currentQuery = currentParts[1] ?? '';
    const itemPath = itemParts[0] ?? '/';
    const itemQuery = itemParts[1] ?? '';

    const normalizedCurrentPath = normalizePath(currentPath);
    const normalizedItemPath = normalizePath(itemPath);

    if (normalizedItemPath === '/vendors') {
        const selected = vendorRegisterTab(current);
        return selected ? 3000 + item.length : -1;
    }

    // A Governance hub entry stays lit on every register in its rail.
    if (governanceHubContainsUrl(normalizedItemPath, normalizedCurrentPath)) {
        return 2000 + item.length;
    }

    // A Finance hub entry is lit by its own hub ONLY. Overview lives at
    // /finance, a prefix of every other finance URL, so falling through to the
    // generic prefix rule below would light it on top of the real hub on every
    // finance page.
    if (isFinanceHubHref(normalizedItemPath)) {
        return financeHubContainsUrl(normalizedItemPath, normalizedCurrentPath)
            ? 2000 + item.length
            : -1;
    }

    const fleetActive = fleetPrimaryLinkActive(
        normalizedCurrentPath,
        normalizedItemPath,
    );
    if (fleetActive !== undefined) return fleetActive ? 2000 + item.length : -1;

    if (itemQuery.length > 0) {
        return normalizedCurrentPath === normalizedItemPath &&
            currentQuery === itemQuery
            ? 3000 + item.length
            : -1;
    }

    if (normalizedCurrentPath === normalizedItemPath) {
        return 2000 + item.length;
    }

    if (normalizedCurrentPath.startsWith(`${normalizedItemPath}/`)) {
        return 1000 + item.length;
    }

    return -1;
}

const WORKFORCE_ROUTE_PREFIXES = [
    '/operations/shifts',
    '/operations/job-board',
    '/operations/rostering',
    '/operations/handovers',
    '/operations/shift-notes',
    '/operations/timesheets',
    '/attendance',
];

function isWorkforceUrl(url: string): boolean {
    const currentPath = normalizePath(resolveUrl(url));

    return WORKFORCE_ROUTE_PREFIXES.some(
        (prefix) =>
            currentPath === prefix || currentPath.startsWith(`${prefix}/`),
    );
}

export function isIconActive(
    currentUrl: string,
    item: IconNavItem,
    subPanelGroups?: SubPanelGroup[],
): boolean {
    if (item.href) {
        return matchScore(currentUrl, item.href) > 0;
    }
    if (item.id === 'sites' && vendorRegisterTab(currentUrl)) return false;
    if (item.id === 'operations' && isWorkforceUrl(currentUrl)) {
        return false;
    }
    // For sub-panel items, check if any child is active
    if (item.subPanel && subPanelGroups) {
        return subPanelGroups.some((group) =>
            (group?.items ?? []).some(
                (sub) => matchScore(currentUrl, sub.href) > 0,
            ),
        );
    }
    return false;
}

export function isSubItemActive(currentUrl: string, href: NavItem['href']): boolean {
    if (resolveUrl(href) === '/it') {
        const path = normalizePath(resolveUrl(currentUrl));
        return path === '/it' || path.startsWith('/it/tickets/');
    }
    return matchScore(currentUrl, href) > 0;
}

// ── Build icon nav items ───────────────────────────────────────────────────

function buildPortalNavItems(
    portalClients?: PortalClient[] | null,
    unreadMessageCount?: number,
): IconNavItem[] {
    const clients = portalClients ?? [];
    const [client] = clients;

    if (client) {
        const cid = client.id;
        return [
            {
                id: 'dashboard',
                icon: LayoutGrid,
                label: 'Dashboard',
                href: `/portal/clients/${cid}/dashboard`,
            },
            {
                id: 'timeline',
                icon: Clock,
                label: 'Timeline',
                href: `/portal/clients/${cid}/timeline`,
            },
            {
                id: 'calendar',
                icon: CalendarDays,
                label: 'Calendar & Visits',
                href: `/portal/clients/${cid}/calendar`,
            },
            {
                id: 'family-notes',
                icon: CalendarDays,
                label: 'Notes & To-Dos',
                href: `/portal/clients/${cid}/family-notes`,
            },
            {
                id: 'messages',
                icon: MessageSquareText,
                label: 'Messages',
                href: `/portal/clients/${cid}/messages`,
                dividerAfter: true,
                badge: unreadMessageCount || undefined,
            },
            {
                id: 'health',
                icon: Heart,
                label: 'Health & Care',
                href: `/portal/clients/${cid}/health`,
            },
            {
                id: 'location',
                icon: MapPin,
                label: 'Location',
                href: `/portal/clients/${cid}/location`,
            },
            {
                id: 'documents',
                icon: FileText,
                label: 'Documents',
                href: `/portal/clients/${cid}/documents`,
            },
            {
                id: 'photos',
                icon: Clipboard,
                label: 'Photo Gallery',
                href: `/portal/clients/${cid}/photos`,
                dividerAfter: true,
            },
            {
                id: 'notifications',
                icon: Bell,
                label: 'Notifications',
                href: '/portal/notifications',
            },
            {
                id: 'preferences',
                icon: Settings,
                label: 'Preferences',
                href: '/portal/preferences',
            },
        ];
    }
    // Multi-client: minimal nav, they pick a client from the home page
    return [
        { id: 'home', icon: LayoutGrid, label: 'Home', href: '/portal' },
        {
            id: 'notifications',
            icon: Bell,
            label: 'Notifications',
            href: '/portal/notifications',
        },
        {
            id: 'preferences',
            icon: Settings,
            label: 'Preferences',
            href: '/portal/preferences',
        },
    ];
}

function canAccessShiftHandovers(can?: any): boolean {
    return (
        !!can?.handovers?.viewAny ||
        !!can?.shifts?.viewAny ||
        !!can?.shifts?.manageAny ||
        !!can?.shifts?.viewAssigned ||
        !!can?.shifts?.update ||
        !!can?.handovers?.create
    );
}

function buildIconNavItems({
    role,
    can,
    portalClients,
    unreadMessageCount,
}: {
    role?: string | null;
    can?: any;
    portalClients?: PortalClient[] | null;
    unreadMessageCount?: number;
}): IconNavItem[] {
    // Portal users (family members / clients) get a dedicated sidebar
    if (role === 'next_of_kin' || role === 'client') {
        return buildPortalNavItems(portalClients, unreadMessageCount);
    }

    // PR 3 — `/my-day` is the single canonical frontline home. Staff users
    // should see ONE clear home destination; managers and HR admins keep the
    // traditional `/dashboard`. Dashboard routing itself redirects staff to
    // `/my-day`, so even a stale link stays safe.
    const isManager = !!can?.shifts?.manageAny || !!can?.timesheets?.manageAny;
    const isHrAdmin = !!can?.hr?.analytics?.view && !can?.shifts?.manageAny;
    const showDashboardHome = isManager || isHrAdmin;

    const items: IconNavItem[] = [
        {
            id: 'my-day',
            icon: CheckCircle2,
            label: 'My Day',
            href: '/my-day',
        },
        ...(showDashboardHome
            ? [
                  {
                      id: 'dashboard',
                      icon: LayoutGrid,
                      label: 'Overview',
                      href: '/dashboard',
                  } as IconNavItem,
              ]
            : []),
        {
            id: 'my-calendar',
            icon: CalendarDays,
            // "My Calendar" (personal shifts), to disambiguate from the
            // Rostering Calendar tab and the Site Calendar.
            label: 'My Calendar',
            href: '/my-calendar',
            dividerAfter: !can?.tasks?.view,
        },
        // Company-wide work-item dashboard — every open incident, corrective
        // action, alert and follow-up across all modules, ticket-numbered.
        ...(can?.tasks?.view
            ? [
                  {
                      id: 'all-tasks',
                      icon: ListChecks,
                      label: 'All Tasks',
                      href: '/tasks',
                      badge: can?.tasks?.badge,
                      dividerAfter: true,
                  } as IconNavItem,
              ]
            : []),
    ];

    // Sites & Locations
    if (can?.sites?.viewAny) {
        items.push({
            id: 'sites',
            icon: Building2,
            label: 'Sites & Locations',
            subPanel: true,
        });
    }

    // Operations holds client, funding, communication, and admin tools. Core
    // shift, handover, roster, and time surfaces live in Workforce so
    // schedulers have one focused place for shift navigation.
    const hasOps =
        !!can?.operations?.dashboard ||
        !!can?.clients?.viewAny ||
        !!can?.clients?.viewAssigned ||
        !!can?.onboarding?.viewAny ||
        !!can?.onboarding?.view ||
        !!can?.progress_notes?.viewAny ||
        !!can?.progress_notes?.create ||
        !!can?.progress_notes?.review ||
        !!can?.care_plans?.viewAny ||
        !!can?.service_agreements?.viewAny ||
        !!can?.client_funds?.manage ||
        !!can?.client_funds?.approve ||
        !!can?.funding?.viewAny ||
        !!can?.mileage?.viewAny ||
        !!can?.mileage?.viewOwn ||
        !!can?.messages?.viewAny ||
        !!can?.custom_forms?.viewAny ||
        !!can?.care_note_templates?.viewAny ||
        !!can?.evv?.viewAny ||
        !!can?.family_portal?.viewAny ||
        !!can?.family_portal?.manage ||
        !!can?.qualifications?.viewAny ||
        !!can?.operations?.reports?.view ||
        !!can?.reports?.viewAny ||
        !!can?.timeline?.viewAny ||
        !!can?.summaries?.viewAny ||
        !!can?.summaries?.generate;
    if (hasOps) {
        items.push({
            id: 'operations',
            icon: Users,
            label: 'Operations',
            subPanel: true,
        });
    }

    const hasWorkforce =
        canAccessShiftHandovers(can) ||
        !!can?.job_board?.viewAny ||
        !!can?.job_board?.claim ||
        !!can?.rostering?.viewAny ||
        !!can?.timesheets?.viewAny ||
        !!can?.timesheets?.viewAssigned;
    if (hasWorkforce) {
        items.push({
            id: 'workforce',
            icon: Briefcase,
            label: 'Workforce',
            subPanel: true,
        });
    }

    // Medications (PR 12 — worker / admin split).
    //
    // Frontline workers (administer-record, no orders-manage/audit) get a
    // single top-level link straight to the operational worker view at
    // `/meds/today`. They never land on the admin-heavy eMAR dashboard by
    // default.
    //
    // Managers / medication leads (orders, stock, audit, or reports) keep the
    // full eMAR sub-panel for oversight, now rooted on the worker view so the
    // first click still matches the frontline experience, with Dashboard kept
    // one level deeper for compliance / management work.
    const canAdminEmar =
        (can?.medications?.view && can?.medications?.ordersManage) ||
        (can?.medications?.view && can?.medications?.stockUpdate) ||
        (can?.medications?.view &&
            can?.medications?.controlledView &&
            can?.medications?.controlledRecord) ||
        can?.medications?.auditView ||
        can?.medications?.reportsExport ||
        can?.reports?.viewAny;
    const canWorkerMeds =
        can?.medications?.administerRecord || can?.medications?.view;
    if (canAdminEmar) {
        items.push({ id: 'emar', icon: Pill, label: 'eMAR', subPanel: true });
    } else if (canWorkerMeds) {
        items.push({
            id: 'meds-today',
            icon: Pill,
            label: 'Meds today',
            href: '/meds/today',
            // Overdue doses for the worker's shift clients (shared by
            // HandleInertiaRequests, 60s cache) — the design's critical chip.
            badge: can?.medications?.overdueTodayCount,
        });
    }

    // Health & Clinical
    const hasClinical = can?.clinical?.dashboard;
    if (hasClinical) {
        items.push({
            id: 'health-clinical',
            icon: Stethoscope,
            label: 'Health & Clinical',
            href: '/health-clinical',
        });
    }

    // Health & Safety
    const hasSafety =
        can?.incidents?.viewAny ||
        can?.incidents?.viewAssigned ||
        can?.compliance?.view ||
        can?.hazards?.view ||
        can?.['health-safety']?.view;
    if (hasSafety) {
        items.push({
            id: 'safety',
            icon: ShieldCheck,
            label: 'Health & Safety',
            subPanel: true,
        });
    }

    // Fleet & Assets
    const hasFleetAssets = canDiscoverFleetNavigation(can);
    if (hasFleetAssets) {
        items.push({
            id: 'fleet-assets',
            icon: Truck,
            label: 'Fleet & Assets',
            subPanel: true,
            dividerAfter: true,
        });
    } else if (items.length > 0) {
        const lastItem = items.at(-1);
        if (lastItem) {
            lastItem.dividerAfter = true;
        }
    }

    // IT & Support — the account/access/equipment request queue fed by
    // onboarding IT tasks, plus the helpdesk. Requesters (everyone on staff)
    // see it too: they raise and track their own tickets there.
    if (
        can?.it?.view ||
        can?.it?.request ||
        can?.it?.knowledge_author ||
        can?.it?.knowledge_review ||
        can?.vendors?.view ||
        can?.vendors?.contracts_view ||
        can?.credentials?.view
    ) {
        items.push({
            id: 'it-provisioning',
            icon: Server,
            label: 'IT & Support',
            subPanel: true,
        });
    }

    // HR — visible if the user has any HR capability (they always have My HR
    // because all employees can view their own records, but non-employees
    // with no HR grant shouldn't see the icon at all).
    const hasAnyHr =
        !!can?.hr?.employees?.viewOwn ||
        !!can?.hr?.employees?.viewAny ||
        !!can?.hr?.recruitment?.view ||
        !!can?.hr?.compliance?.view ||
        !!can?.hr?.training?.view ||
        !!can?.hr?.vetting?.view ||
        !!can?.hr?.leave?.viewOwn ||
        !!can?.hr?.leave?.viewAny ||
        !!can?.hr?.performance?.view ||
        !!can?.hr?.cases?.view ||
        !!can?.hr?.policies?.view ||
        !!can?.hr?.documents?.view ||
        !!can?.hr?.payroll?.view ||
        !!can?.hr?.reports?.view ||
        !!can?.hr?.driver?.view ||
        !!can?.hr?.wellbeing?.view ||
        !!can?.hr?.onboarding?.view ||
        !!can?.hr?.positions?.view ||
        !!can?.hr?.orgchart?.view ||
        !!can?.hr?.time?.view ||
        !!can?.hr?.time?.viewAny ||
        !!can?.hr?.compensation?.view ||
        !!can?.hr?.benefits?.view ||
        !!can?.hr?.goals?.view ||
        !!can?.hr?.assets?.view ||
        !!can?.hr?.calendar?.view ||
        !!can?.hr?.analytics?.view ||
        !!can?.hr?.surveys?.view ||
        !!can?.hr?.expenses?.view ||
        !!can?.hr?.skills?.view ||
        !!can?.hr?.recognition?.view ||
        !!can?.hr?.announcements?.view ||
        !!can?.hr?.approvals?.view ||
        !!can?.hr?.settings?.manage;
    if (hasAnyHr) {
        items.push({
            id: 'hr',
            icon: Briefcase,
            label: 'HR',
            subPanel: true,
            dividerAfter: true,
        });
    }

    // Governance
    if (can?.governance?.view || can?.roadmap?.view) {
        items.push({
            id: 'governance',
            icon: Landmark,
            label: 'Governance',
            subPanel: true,
        });
    }

    // Finance
    // Any visible hub view opens the module — a treasurer with only
    // finance.reports.view can reach /finance/reports, so they get the entry.
    if (
        FINANCE_SECTIONS.some(
            (section) => visibleFinanceSectionTabs(section, can).length > 0,
        )
    ) {
        items.push({
            id: 'finance',
            icon: Banknote,
            label: 'Finance',
            subPanel: true,
            dividerAfter: true,
        });
    }

    // Reporting — only if the user has any report-related grant
    const hasAnyReports =
        !!can?.reports?.viewAny ||
        !!can?.operations?.reports?.view ||
        !!can?.sitesReports?.view ||
        !!can?.hr?.reports?.view ||
        !!can?.fleet?.viewAny ||
        !!can?.governance?.view;
    if (hasAnyReports) {
        items.push({
            id: 'reporting',
            icon: PieChart,
            label: 'Reporting',
            subPanel: true,
        });
    }

    const hasSecurityDevices =
        can?.securityDevices?.viewAny ||
        can?.securityDevices?.devicesView ||
        can?.siteHardware?.view;

    if (hasSecurityDevices) {
        items.push({
            id: 'security-devices',
            icon: Shield,
            label: 'Security & Devices',
            subPanel: true,
        });
    }

    // Control Room — only if the user has any control-room grant
    if (
        can?.controlRoom?.viewAny ||
        can?.controlRoom?.alertsView ||
        can?.controlRoom?.alertsManage ||
        can?.controlRoom?.reportsView
    ) {
        items.push({
            id: 'control-room',
            icon: Radio,
            label: 'Control Room',
            subPanel: true,
        });
    }

    return items;
}

// ── Build sub-panel groups for each section ──────────────────────────────

function buildItSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    if (
        !can?.it?.view &&
        !can?.it?.request &&
        !can?.it?.knowledge_author &&
        !can?.it?.knowledge_review &&
        !can?.vendors?.view &&
        !can?.vendors?.contracts_view &&
        !can?.credentials?.view
    )
        return [];
    return [
        {
            label: 'IT & Support',
            items: [
                ...(can?.it?.view || can?.it?.request
                    ? [{ title: 'Service desk', href: '/it', icon: Server }]
                    : []),
                ...(can?.it?.view ||
                can?.it?.request ||
                can?.it?.knowledge_author ||
                can?.it?.knowledge_review
                    ? [
                          {
                              title:
                                  can?.it?.view ||
                                  can?.it?.knowledge_author ||
                                  can?.it?.knowledge_review
                                      ? 'Knowledge & Documentation'
                                      : 'Guides',
                              href: '/it/knowledge',
                              icon: BookOpen,
                          },
                      ]
                    : []),
                ...(can?.it?.view
                    ? [
                          {
                              title: 'Provisioning',
                              href: '/it/provisioning',
                              icon: Server,
                          },
                          {
                              title: 'Work planning',
                              href: '/it/work',
                              icon: CalendarClock,
                          },
                          {
                              title: 'Problems',
                              href: '/it/problems',
                              icon: Server,
                          },
                          {
                              title: 'Changes',
                              href: '/it/changes',
                              icon: Server,
                          },
                          {
                              title: 'Major incidents',
                              href: '/it/major-incidents',
                              icon: Server,
                          },
                          {
                              title: 'Reports',
                              href: '/it/reports',
                              icon: BarChart3,
                          },
                      ]
                    : []),
                ...(can?.it?.manage
                    ? [{ title: 'Setup', href: '/it/setup', icon: Settings }]
                    : []),
                ...(can?.vendors?.view || can?.credentials?.view || can?.vendors?.contracts_view
                    ? [
                          {
                              title: 'Vendors & Credentials',
                              href: can?.vendors?.view
                                  ? '/vendors?tab=vendors'
                                  : can?.credentials?.view
                                      ? '/vendors?tab=credentials'
                                      : '/vendors',
                              icon: Package,
                          },
                      ]
                    : []),
            ],
        },
    ];
}

function buildSitesSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const items: NavItem[] = [
        { title: 'All Sites', href: '/sites', icon: Building2 },
    ];
    if (can?.sites?.types?.headOfficeView)
        items.push({
            title: 'Head Office',
            href: '/sites?type=head_office',
            icon: Building2,
        });
    if (can?.sites?.types?.houseView)
        items.push({ title: 'Houses', href: '/sites?type=house', icon: Home });
    if (can?.sites?.types?.facilityView)
        items.push({
            title: 'Facilities',
            href: '/sites?type=facility',
            icon: Building2,
        });
    if (can?.calendar?.viewAny)
        items.push({
            title: 'Site Calendar',
            href: '/calendar',
            icon: CalendarDays,
        });
    if (can?.checklists?.view)
        items.push({
            title: 'Checklists',
            href: '/checklists',
            icon: ClipboardCheck,
        });
    if (can?.checklists?.view)
        items.push({
            title: 'Inspections & Maintenance',
            href: '/sites/inspections',
            icon: ClipboardList,
        });
    if (can?.sitesReports?.view)
        items.push({
            title: 'Reports',
            href: '/sites/reports',
            icon: BarChart3,
        });
    if (can?.vendors?.view || can?.credentials?.view || can?.vendors?.contracts_view)
        items.push({
            title: 'Vendors & Credentials',
            href: can?.vendors?.view
                ? '/vendors?tab=vendors'
                : can?.credentials?.view
                    ? '/vendors?tab=credentials'
                    : '/vendors',
            icon: Package,
        });
    items.push({
        title: 'Meal Planner',
        href: '/catering',
        icon: Utensils,
    });

    const groups: SubPanelGroup[] = [{ label: 'Sites & Locations', items }];

    // Respite is now a single tabbed workspace at /respite — Referrals, Booking
    // Requests, Approved Bookings, Calendar, Stays (plus Tasks/records) are tabs,
    // not separate pages. The startsWith match keeps it lit on every /respite/* sub-route.
    if (can?.respite?.viewAny) {
        groups.push({
            label: 'Respite',
            items: [{ title: 'Respite', href: '/respite', icon: Home }],
        });
    }

    return groups;
}

function buildOperationsSubPanelGroups({
    can,
    labels,
}: {
    can?: any;
    role?: string | null;
    labels?: any;
}): SubPanelGroup[] {
    const groups: SubPanelGroup[] = [];

    // PR 18 — role separation for operations nav. Frontline staff (no
    // scheduler/approval capabilities) should not see manager-oriented
    // entries: the operations Dashboard, the scheduler Shifts table, the
    // Rostering planner or the timesheet approvals queue. Their home is
    // `/my-day`, so these links are hidden rather than surfaced and then
    // redirected.
    const isManager =
        !!can?.shifts?.manageAny ||
        !!can?.timesheets?.manageAny ||
        !!can?.timesheets?.approve ||
        !!can?.rostering?.viewAny ||
        !!can?.hr?.analytics?.view ||
        !!can?.hr?.time?.manage ||
        !!can?.hr?.time?.approveTeam;

    // Overview — Dashboard is a scheduler/admin landing surface; staff start
    // on `/my-day` and don't need it here.
    const overview: NavItem[] = [];
    if (isManager || can?.operations?.dashboard) {
        overview.push({
            title: 'Dashboard',
            href: '/operations',
            icon: LayoutGrid,
        });
    }
    if (
        isManager ||
        can?.timeline?.viewAny ||
        can?.summaries?.viewAny ||
        can?.clients?.viewAny
    )
        overview.push({
            title: 'Activity Feed',
            href: '/operations/activity',
            icon: Activity,
        });
    if (can?.timeline?.viewAny || can?.clients?.viewAny)
        overview.push({
            title: 'Timeline',
            href: '/operations/timeline',
            icon: Clock,
        });
    if (can?.summaries?.viewAny || can?.summaries?.generate)
        overview.push({
            title: 'Summaries',
            href: '/operations/summaries',
            icon: FileText,
        });
    if (overview.length > 0)
        groups.push({ label: 'Overview', items: overview });

    // Client Management
    const clientLabel = labels?.['client.singular'] ?? 'Client';
    const clientLabelPlural = labels?.['client.plural'] ?? 'Clients';
    const clientMgmt: NavItem[] = [];
    if (can?.clients?.viewAny || can?.clients?.viewAssigned)
        clientMgmt.push({
            title: clientLabelPlural,
            href: '/operations/clients',
            icon: Users,
        });
    if (can?.onboarding?.viewAny || can?.onboarding?.view)
        clientMgmt.push({
            title: 'Onboarding Pipeline',
            href: '/operations/onboarding',
            icon: UserCheck,
        });
    if (can?.care_plans?.viewAny || can?.clients?.viewAny)
        clientMgmt.push({
            title: 'Care Plans',
            href: '/operations/care-plans',
            icon: ClipboardCheck,
        });
    if (can?.service_agreements?.viewAny || can?.clients?.viewAny)
        clientMgmt.push({
            title: 'Service Agreements',
            href: '/operations/service-agreements',
            icon: FileText,
        });
    // Progress notes retired as a standalone page — they live in each client
    // profile's Daily Notes tab (type filter) since the profile redesign.
    if (can?.progress_notes?.review)
        clientMgmt.push({
            title: 'Review Queue',
            href: '/operations/review-queue',
            icon: AlertTriangle,
        });
    if (can?.funding?.viewAny)
        clientMgmt.push({
            title: 'Funding',
            href: '/operations/funding',
            icon: PieChart,
        });
    if (can?.client_funds?.manage || can?.client_funds?.approve)
        clientMgmt.push({
            title: `${clientLabel} Funds`,
            href: '/operations/client-funds',
            icon: DollarSign,
        });
    if (clientMgmt.length > 0)
        groups.push({ label: `${clientLabel} Management`, items: clientMgmt });

    // Communications
    const comms: NavItem[] = [];
    if (can?.messages?.viewAny || can?.shifts?.viewAny)
        comms.push({
            title: 'Messages',
            href: '/operations/messages',
            icon: MessageSquareText,
        });
    if (comms.length > 0)
        groups.push({ label: 'Communications', items: comms });

    // Tools
    const tools: NavItem[] = [];
    if (can?.mileage?.viewAny || can?.mileage?.viewOwn)
        tools.push({
            title: 'Mileage',
            href: '/operations/mileage',
            icon: Route,
        });
    if (can?.custom_forms?.viewAny)
        tools.push({
            title: 'Custom Forms',
            href: '/operations/forms',
            icon: ClipboardCheck,
        });
    if (can?.care_note_templates?.viewAny)
        tools.push({
            title: 'Note Templates',
            href: '/operations/note-templates',
            icon: FileText,
        });
    if (can?.evv?.viewAny)
        tools.push({ title: 'EVV', href: '/operations/evv', icon: MapPin });
    if (can?.family_portal?.viewAny || can?.family_portal?.manage)
        tools.push({
            title: 'Family Portal',
            href: '/operations/family-portal',
            icon: Users,
        });
    if (can?.integrations?.manageSecrets)
        tools.push({
            title: 'Calendar Sync',
            href: '/settings/calendar-sync',
            icon: CalendarDays,
        });
    if (can?.qualifications?.viewAny)
        tools.push({
            title: 'Qualifications',
            href: '/operations/qualifications',
            icon: ShieldCheck,
        });
    if (tools.length > 0) groups.push({ label: 'Tools', items: tools });

    // Reports
    if (can?.operations?.reports?.view || can?.reports?.viewAny) {
        groups.push({
            label: 'Reports',
            items: [
                {
                    title: 'Reports & Analytics',
                    href: '/operations/reports',
                    icon: PieChart,
                },
            ],
        });
    }

    return groups;
}

function buildWorkforceSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const workforce: NavItem[] = [];

    // The Shifts table is the scheduler view. Frontline staff still use
    // `/my-day` for their assigned shifts rather than this manager surface.
    if (can?.shifts?.viewAny)
        workforce.push({
            title: 'Shifts',
            href: '/operations/shifts',
            icon: CalendarDays,
        });
    if (
        can?.job_board?.viewAny ||
        can?.job_board?.claim ||
        can?.shifts?.viewAny ||
        can?.shifts?.viewAssigned
    )
        workforce.push({
            title: 'Job Board',
            href: '/operations/job-board',
            icon: ClipboardList,
            badge: can?.job_board?.open_count,
        });
    // Rostering's own TabStrip is the navigation for its Calendar tab
    // (/operations/rostering?tab=calendar) — no separate sidebar item.
    if (can?.rostering?.viewAny)
        workforce.push({
            title: 'Rostering',
            href: '/operations/rostering',
            icon: CalendarDays,
        });
    if (can?.rostering?.viewAny)
        workforce.push({
            title: 'Availability',
            href: '/operations/rostering?tab=availability',
            icon: Clock,
        });
    if (canAccessShiftHandovers(can))
        workforce.push({
            title: 'Handovers',
            href: '/operations/handovers',
            icon: GitBranch,
        });
    if (can?.shifts?.viewAny)
        workforce.push({
            title: 'Shift Notes',
            href: '/operations/shift-notes',
            icon: BookOpen,
        });
    if (can?.timesheets?.viewAny || can?.timesheets?.viewAssigned)
        workforce.push({
            title: 'Timesheets',
            href: '/operations/timesheets',
            icon: Clock,
        });
    // Attendance (clock sessions ↔ timesheet sync) sits between Shifts and
    // Timesheets in the workflow; the route also admits frontline workers
    // (own clock history), who additionally reach it via StaffPageShell.
    if (
        can?.timesheets?.viewAny ||
        can?.timesheets?.viewAssigned ||
        can?.shifts?.viewAssigned ||
        can?.shifts?.manageAny
    )
        workforce.push({
            title: 'Attendance',
            href: '/attendance',
            icon: Timer,
        });
    if (can?.rostering?.viewAny)
        workforce.push({
            title: 'Conflict Queue',
            href: '/operations/rostering/conflicts',
            icon: AlertTriangle,
        });

    return workforce.length > 0
        ? [{ label: 'Workforce', items: workforce }]
        : [];
}

function buildEmarSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const groups: SubPanelGroup[] = [];

    // Worker view (PR 12). Top of the panel so even admins can quickly drop
    // into the operational frontline surface before diving into compliance.
    const workerItems: NavItem[] = [];
    if (can?.medications?.administerRecord || can?.medications?.view)
        workerItems.push({
            title: 'Meds today',
            href: '/meds/today',
            icon: Activity,
        });
    if (workerItems.length > 0)
        groups.push({ label: 'Worker view', items: workerItems });

    // Overview
    if (can?.medications?.view)
        groups.push({
            label: 'Overview',
            items: [{ title: 'Dashboard', href: '/emar', icon: LayoutGrid }],
        });

    // Administration
    const admin: NavItem[] = [];
    if (can?.medications?.view)
        admin.push({
            title: 'MAR Charts',
            href: '/emar/mar',
            icon: ClipboardCheck,
        });
    if (can?.medications?.view)
        admin.push({
            title: 'Medication Rounds',
            href: '/emar/rounds',
            icon: Clock,
        });
    if (can?.medications?.view)
        admin.push({ title: 'PRN Records', href: '/emar/prn', icon: BookOpen });
    if (can?.medications?.view && can?.medications?.controlledView)
        admin.push({
            title: 'Controlled Drugs',
            href: '/emar/controlled',
            icon: Shield,
        });
    if (can?.medications?.breakGlass)
        admin.push({
            title: 'Emergency Access',
            href: '/emar/emergency-access',
            icon: ShieldAlert,
        });
    if (admin.length > 0)
        groups.push({ label: 'Administration', items: admin });

    // Management
    const mgmt: NavItem[] = [];
    if (can?.medications?.view)
        mgmt.push({
            title: 'Medications',
            href: '/emar/medications',
            icon: Pill,
        });
    if (can?.medications?.view && can?.medications?.stockUpdate)
        mgmt.push({
            title: 'Stock Management',
            href: '/emar/stock',
            icon: Package,
        });
    if (can?.medications?.view)
        mgmt.push({
            title: 'Prescriptions',
            href: '/emar/prescriptions',
            icon: FileText,
        });
    if (can?.medications?.view)
        mgmt.push({
            title: 'Medication Reviews',
            href: '/emar/reviews',
            icon: CalendarDays,
        });
    if (can?.medications?.view)
        mgmt.push({
            title: 'Self-Administration',
            href: '/emar/self-admin',
            icon: Users,
        });
    if (mgmt.length > 0) groups.push({ label: 'Management', items: mgmt });

    // Compliance
    const compliance: NavItem[] = [];
    if (can?.medications?.auditView)
        compliance.push({
            title: 'Audit Trail',
            href: '/emar/audit',
            icon: Shield,
        });
    if (can?.reports?.viewAny || can?.medications?.reportsExport)
        compliance.push({
            title: 'Reports',
            href: '/emar/reports',
            icon: PieChart,
        });
    if (can?.medications?.view)
        compliance.push({
            title: 'Competency',
            href: '/emar/competency',
            icon: ClipboardCheck,
        });
    if (can?.medications?.view && can?.medications?.controlledView)
        compliance.push({
            title: 'Destructions',
            href: '/emar/destructions',
            icon: Trash2,
        });
    if (can?.medications?.view)
        compliance.push({
            title: 'Handovers',
            href: '/emar/handovers',
            icon: GitBranch,
        });
    if (can?.medications?.view)
        compliance.push({
            title: 'Medication Errors',
            href: '/emar/errors',
            icon: AlertTriangle,
        });
    if (compliance.length > 0)
        groups.push({ label: 'Compliance', items: compliance });

    return groups;
}

function buildSafetySubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const groups: SubPanelGroup[] = [];

    // ── 1. Command centre — the H&S home / "start here" ──────────────────
    const overview: NavItem[] = [];
    if (can?.hazards?.view || can?.compliance?.view)
        overview.push({
            title: 'H&S Dashboard',
            href: '/health-safety',
            icon: ShieldCheck,
        });
    if (overview.length > 0)
        groups.push({ label: 'Command centre', items: overview });

    // ── 2. Report & respond — the front doors. Every safety event is logged
    //    here (incident / near miss / fleet / safeguarding) before it converges
    //    into the governance registers below. ─────────────────────────────────
    const incidents: NavItem[] = [];
    if (can?.incidents?.viewAny || can?.incidents?.viewAssigned)
        incidents.push({
            title: 'Incidents',
            href: '/incidents',
            icon: ShieldAlert,
        });
    if (can?.incidents?.viewAny || can?.incidents?.viewAssigned)
        incidents.push({
            title: 'Near Misses',
            href: '/incidents?tab=near_misses',
            icon: AlertTriangle,
        });
    if (can?.fleet?.viewAny || can?.assets?.viewAny || can?.incidents?.viewAny)
        incidents.push({
            title: 'Fleet Incidents',
            href: '/fleet-assets/incidents',
            icon: Truck,
        });
    if (can?.safeguarding?.viewAny || can?.safeguarding?.create)
        incidents.push({
            title: 'Safeguarding',
            href: '/safeguarding',
            icon: Shield,
        });
    if (incidents.length > 0)
        groups.push({ label: 'Report & respond', items: incidents });

    // ── 3. Investigate & resolve — the governance registers where every event
    //    converges, is investigated, and is driven to a verified corrective
    //    action. "Events" is the master register (was mislabelled
    //    "Investigations"); Corrective Actions is the verification register. ───
    const investigate: NavItem[] = [];
    if (can?.hazards?.view || can?.compliance?.view)
        investigate.push({
            title: 'Events',
            href: '/health-safety/events',
            icon: ClipboardCheck,
        });
    if (can?.hazards?.view || can?.compliance?.view)
        investigate.push({
            title: 'Corrective Actions',
            href: '/health-safety/corrective-actions',
            icon: Wrench,
        });
    if (investigate.length > 0)
        groups.push({ label: 'Investigate & resolve', items: investigate });

    // ── 4. Analyse & assure — trends, KPIs and board assurance close the loop.
    //    Sits after the operational registers so the nav reads report →
    //    investigate → resolve → analyse top-to-bottom. ───────────────────────
    const analyse: NavItem[] = [];
    if (can?.hazards?.view || can?.compliance?.view || can?.reports?.viewAny)
        analyse.push({
            title: 'Analytics',
            href: '/health-safety/analytics',
            icon: BarChart3,
        });
    if (analyse.length > 0)
        groups.push({ label: 'Analyse & assure', items: analyse });

    // H&S Management
    const hsManagement: NavItem[] = [];
    if (can?.hazards?.view)
        hsManagement.push({
            title: 'Hazards',
            href: '/compliance/hazards',
            icon: AlertOctagon,
        });
    if (can?.hazards?.view || can?.compliance?.view)
        hsManagement.push({
            title: 'Worker Participation',
            href: '/health-safety/worker-participation',
            icon: Users,
        });
    if (can?.hazards?.view || can?.compliance?.view)
        hsManagement.push({
            title: 'Lone Worker Safety',
            href: '/health-safety/lone-workers',
            icon: PersonStanding,
        });
    if (can?.hazards?.view || can?.compliance?.view)
        hsManagement.push({
            title: 'Emergency Drills',
            href: '/health-safety/drills',
            icon: Siren,
        });
    if (hsManagement.length > 0)
        groups.push({ label: 'H&S Management', items: hsManagement });

    // Registers
    const registers: NavItem[] = [];
    if (can?.hazards?.view || can?.compliance?.view)
        registers.push({
            title: 'Chemical Register',
            href: '/health-safety/substances',
            icon: FlaskConical,
        });
    if (can?.hazards?.view || can?.compliance?.view)
        registers.push({
            title: 'PPE & Equipment',
            href: '/health-safety/ppe',
            icon: HardHat,
        });
    if (can?.hazards?.view || can?.compliance?.view || can?.clinical?.dashboard)
        registers.push({
            title: 'First Aid Register',
            href: '/health-safety/first-aid',
            icon: HeartPulse,
        });
    if (can?.restraints?.view)
        registers.push({
            title: 'Restraints & Behaviour Support',
            href: '/health-safety/restraints',
            icon: Clipboard,
        });
    if (registers.length > 0)
        groups.push({ label: 'Registers', items: registers });

    // Injury & Recovery
    const injury: NavItem[] = [];
    if (can?.hazards?.view || can?.hr?.wellbeing?.view)
        injury.push({
            title: 'Workplace Injuries',
            href: '/health-safety/injuries',
            icon: Activity,
        });
    if (can?.hazards?.view || can?.compliance?.view)
        injury.push({
            title: 'Safe Work Procedures',
            href: '/health-safety/procedures',
            icon: FileText,
        });
    if (injury.length > 0)
        groups.push({ label: 'Injury & Procedures', items: injury });

    // Compliance & Risk
    const compliance: NavItem[] = [];
    if (can?.compliance?.view)
        compliance.push({
            title: 'Compliance',
            href: '/compliance',
            icon: Shield,
        });
    if (can?.risks?.viewAny || can?.risks?.viewAssigned)
        compliance.push({
            title: 'Risks',
            href: '/health-safety/risk-assessments',
            icon: Target,
        });
    if (can?.privacy?.viewRequests)
        compliance.push({
            title: 'Privacy',
            href: '/privacy/dashboard',
            icon: Shield,
        });
    if (compliance.length > 0)
        groups.push({ label: 'Compliance & Risk', items: compliance });

    return groups;
}

function buildFleetAssetsSubPanelGroups({
    can,
}: {
    can?: FleetNavigationPermissions;
}): SubPanelGroup[] {
    const items = fleetPrimaryLinks(can);
    return items.length ? [{ label: 'Fleet & Assets', items }] : [];
}
/**
 * Governance pages a viewer can act in — manage, decide, request, or (for
 * the person being reviewed) take part in — keyed by hub tab key
 * (lib/governance-sections.ts). Viewing alone never earns a sidebar entry:
 * ordinary members keep their focused navigation. Navigation only — every
 * page is still authorised on the server.
 */
export function governanceActionableTabKeys(can?: any): Set<string> {
    const gov = can?.governance ?? {};
    const keys = new Set<string>();
    const add = (key: string, allowed: unknown) => {
        if (allowed) keys.add(key);
    };

    add('meetings', gov.meetings?.manage);
    add('packs', gov.packs?.manage);
    add('ceo-reports', gov['ceo-reports']?.manage);
    add('resolutions', gov.resolutions?.manage);
    add('actions', gov.actions?.manage);
    add('risks', gov.risks?.manage);
    add('compliance', gov.compliance?.manage);
    add('clinical', gov.clinical?.manage);
    add('te-tiriti', gov['te-tiriti']?.manage);
    add(
        'budgets',
        gov.budgets?.create || gov.budgets?.submit || gov.budgets?.approve,
    );
    add('spend-approvals', gov.spend?.request || gov.spend?.approve);
    add('strategy', gov.strategy?.manage);
    add(
        'performance',
        gov.performance?.manage || gov.performance?.reviewee,
    );
    add('roadmap', can?.roadmap?.manage);
    add('policies', gov.policies?.manage);
    add('documents', gov.documents?.manage);
    add('members', gov.meetings?.manage);
    add('evaluations', gov.evaluations?.manage);
    add('settings', gov.settings?.manage);

    return keys;
}

function buildGovernanceSubPanelGroups({
    can,
}: {
    can?: any;
}): SubPanelGroup[] {
    // Everyone with Governance access gets Home, My work, Calendar and
    // Records. A hub (lib/governance-sections.ts) is added only when the
    // viewer can act in one of its pages — a treasurer gets Board finance,
    // the CEO gets Meetings (CEO reports) and, once they have a review,
    // Strategy & performance. Each hub entry opens the first page the viewer
    // can act in; its sibling pages are the hub's header rail.
    const groups: SubPanelGroup[] = [];
    const actionable = governanceActionableTabKeys(can);

    // 1. Primary Member destinations
    const overview: NavItem[] = [];
    if (can?.governance?.view) {
        overview.push({
            title: 'Home',
            href: '/governance/dashboard',
            icon: Landmark,
        });
        overview.push({
            title: 'My work',
            href: '/governance/my-work',
            icon: ListChecks,
        });
        overview.push({
            title: 'Calendar',
            href: '/governance/calendar',
            icon: CalendarDays,
        });
        overview.push({
            title: 'Records',
            href: '/governance/records',
            icon: FileText,
        });
    }

    // One entry per hub the viewer acts in; the hub's pages are the
    // connected-tab rail in its page header, so the sidebar stays short.
    const hubGroups: SubPanelGroup[] = [];
    let recordsHubShown = false;
    for (const group of ['board', 'oversight', 'admin'] as const) {
        const items: NavItem[] = [];
        for (const section of GOVERNANCE_SECTIONS) {
            if (section.group !== group) continue;
            const visible = visibleSectionTabs(section, can);
            // The page being reviewed is reachable for its reviewee even
            // though reviews are otherwise limited to the board's reviewers.
            const reachable = [
                ...visible,
                ...section.tabs.filter(
                    (tab) =>
                        !visible.includes(tab) &&
                        tab.key === 'performance' &&
                        actionable.has('performance'),
                ),
            ];
            const entry = reachable.find((tab) => actionable.has(tab.key));
            if (!entry) continue;
            if (section.key === 'records') recordsHubShown = true;
            items.push({
                title: section.label,
                href: entry.href,
                icon: section.icon,
            });
        }
        if (items.length > 0) {
            hubGroups.push({
                label: GOVERNANCE_SECTION_GROUP_LABELS[group],
                items,
            });
        }
    }

    // Records search lives inside the "Policies & records" hub when that
    // hub is shown; everyone else keeps it in the main list.
    const primary = recordsHubShown
        ? overview.filter((item) => item.href !== '/governance/records')
        : overview;
    if (primary.length > 0) {
        groups.push({ label: 'Governance', items: primary });
    } else if (hubGroups.length === 0 && can?.roadmap?.view) {
        // Roadmap-only viewers still need a way in (it has no other entry).
        groups.push({
            label: 'Governance',
            items: [
                { title: 'Roadmap', href: '/roadmap/dashboard', icon: Map },
            ],
        });
    }

    return [...groups, ...hubGroups];
}

/**
 * Finance sub-panel: ONE entry per hub from `lib/finance-sections.ts`
 * (anti-pattern "One sidebar link per register", corrected 2026-09-16). A hub
 * is shown when the viewer can open at least one of its views, and its entry
 * points at the first such view so the link never lands on a 403. The hub's
 * other views are the connected rail in the page header, and `matchScore`
 * keeps the entry lit across all of them via `financeHubContainsUrl`.
 */
function buildFinanceSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const items: NavItem[] = [];

    for (const section of FINANCE_SECTIONS) {
        const visible = visibleFinanceSectionTabs(section, can);
        if (visible.length === 0) continue;
        // The hub landing URL when its own first view is reachable, otherwise
        // straight to the first view this viewer can open.
        const href =
            visible[0].href === section.tabs[0].href
                ? section.href
                : visible[0].href;
        items.push({ title: section.label, href, icon: section.icon });
    }

    return items.length > 0 ? [{ label: 'Finance', items }] : [];
}

function buildSystemSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const items: NavItem[] = [];
    if (can?.reports?.viewAny)
        items.push({ title: 'Reports', href: '/reports', icon: FileText });
    if (can?.sitesReports?.view)
        items.push({
            title: 'Site Reports',
            href: '/reports/sites',
            icon: FileText,
        });
    if (can?.timeline?.viewAny)
        items.push({ title: 'Timeline', href: '/timeline', icon: Clock });
    if (can?.summaries?.viewAny)
        items.push({ title: 'Summaries', href: '/summaries', icon: FileText });
    if (can?.audit?.viewAny)
        items.push({ title: 'Audit Logs', href: '/audit', icon: FileText });
    if (can?.controlRoom?.viewAny)
        items.push({
            title: 'Control Room',
            href: '/control-room',
            icon: LayoutGrid,
        });
    if (can?.integrations?.view)
        items.push({
            title: 'Integrations',
            href: '/security-devices/integrations',
            icon: Settings,
        });
    if (can?.settings?.manageAccess)
        items.push({ title: 'Settings', href: '/settings', icon: Settings });
    if (can?.settings?.manageAccess)
        items.push({
            title: 'Roles & Permissions',
            href: '/system/access/roles',
            icon: Shield,
        });
    return [{ label: 'System', items }];
}

function buildReportingSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const groups: SubPanelGroup[] = [];

    const overview: NavItem[] = [];
    if (can?.reports?.viewAny)
        overview.push({
            title: 'Reports Dashboard',
            href: '/reports',
            icon: BarChart3,
        });
    if (overview.length) groups.push({ label: 'Overview', items: overview });

    const ops: NavItem[] = [];
    if (can?.operations?.reports?.view || can?.reports?.viewAny)
        ops.push({
            title: 'Operations Reports',
            href: '/operations/reports',
            icon: ClipboardList,
        });
    if (ops.length) groups.push({ label: 'Operations', items: ops });

    const sites: NavItem[] = [];
    if (can?.sitesReports?.view)
        sites.push({
            title: 'Site Reports',
            href: '/sites/reports',
            icon: Building2,
        });
    if (sites.length)
        groups.push({ label: 'Sites & Facilities', items: sites });

    const hr: NavItem[] = [];
    if (can?.hr?.reports?.view)
        hr.push({ title: 'HR Reports', href: '/hr/reports', icon: Users });
    if (can?.hr?.reports?.view)
        hr.push({
            title: 'Report Builder',
            href: '/hr/reports/builder',
            icon: Wrench,
        });
    if (hr.length) groups.push({ label: 'HR & People', items: hr });

    const fleet: NavItem[] = [];
    if (can?.fleet?.viewAny || can?.assets?.viewAny)
        fleet.push({
            title: 'Fleet Reports',
            href: '/fleet-assets/reports',
            icon: Car,
        });
    if (fleet.length) groups.push({ label: 'Fleet & Assets', items: fleet });

    const governance: NavItem[] = [];
    if (can?.governance?.view)
        governance.push({
            title: 'Board monthly report',
            href: '/governance/reports/board-monthly',
            icon: FileText,
        });
    // These reports check their register's permission on the server, so the
    // links only show to people who can open them.
    if (can?.governance?.compliance?.view)
        governance.push({
            title: 'Compliance status report',
            href: '/governance/reports/compliance-status',
            icon: ShieldCheck,
        });
    if (can?.governance?.risks?.view)
        governance.push({
            title: 'Top risks report',
            href: '/governance/reports/risk-narrative',
            icon: ShieldCheck,
        });
    if (governance.length)
        groups.push({ label: 'Governance', items: governance });

    return groups.filter((g) => g.items.length > 0);
}

function buildControlRoomSubPanelGroups({
    can,
}: {
    can?: any;
}): SubPanelGroup[] {
    const groups: SubPanelGroup[] = [];

    // All items assume `controlRoom.viewAny` because the icon itself is
    // already gated on that capability. We still gate sub-items that require
    // richer permissions (alerts, reports, messaging, settings).
    // The old Dashboard / All Alerts / Escalation Queue / Incident Tracker
    // entries merged into ONE command centre (tabs on the page itself).
    const live: NavItem[] = [];
    if (can?.controlRoom?.viewAny) {
        live.push({
            title: 'Desk',
            href: '/control-room',
            icon: LayoutDashboard,
        });
        live.push({
            title: 'My queue',
            href: '/control-room/my-tasks',
            icon: CheckCircle2,
        });
        live.push({ title: 'Live Map', href: '/control-room/map', icon: Map });
        live.push({
            title: 'Shifts',
            href: '/control-room/shifts',
            icon: Clock,
        });
    }
    if (live.length) groups.push({ label: 'Live Monitoring', items: live });

    const comms: NavItem[] = [];
    if (can?.controlRoom?.alertsCreate || can?.controlRoom?.alertsManage)
        comms.push({
            title: 'Broadcast',
            href: '/control-room/broadcast',
            icon: Megaphone,
        });
    if (can?.messages?.send || can?.messages?.viewAny)
        comms.push({
            title: 'Staff Messaging',
            href: '/control-room/messaging',
            icon: MessageSquare,
        });
    if (comms.length) groups.push({ label: 'Communications', items: comms });

    const ops: NavItem[] = [];
    if (can?.controlRoom?.viewAny)
        ops.push({
            title: 'Device signals',
            href: '/control-room/devices',
            icon: Smartphone,
        });
    if (can?.controlRoom?.viewAny)
        ops.push({
            title: 'Playbooks',
            href: '/control-room/playbooks',
            icon: ClipboardCheck,
        });
    if (can?.controlRoom?.viewAny)
        ops.push({
            title: 'SLA Management',
            href: '/control-room/sla',
            icon: Target,
        });
    if (ops.length) groups.push({ label: 'Operations', items: ops });

    const analytics: NavItem[] = [];
    if (can?.controlRoom?.viewAny)
        analytics.push({
            title: 'Real-time Stats',
            href: '/control-room/stats',
            icon: Activity,
        });
    if (can?.controlRoom?.reportsView)
        analytics.push({
            title: 'Reports',
            href: '/control-room/reports',
            icon: FileText,
        });
    if (analytics.length) groups.push({ label: 'Analytics', items: analytics });

    const config: NavItem[] = [];
    if (can?.controlRoom?.alertsManage)
        config.push({
            title: 'Settings',
            href: '/control-room/settings',
            icon: Settings,
        });
    if (config.length) groups.push({ label: 'Configuration', items: config });

    return groups;
}

function buildSecurityDevicesSubPanelGroups({
    can,
}: {
    can?: any;
} = {}): SubPanelGroup[] {
    return buildSecurityDevicesNavigationGroups(can?.securityDevices);
}

function buildHrSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const groups: SubPanelGroup[] = [];

    // My HR - always visible
    // Documents, Training, Payslips, Leave, etc. are now tabs within the My HR hub.
    const myHr: SubPanelGroup = {
        label: 'My HR',
        items: [{ title: 'My HR', href: '/hr/my', icon: Home }],
    };
    groups.push(myHr);

    // People — Directory, Positions, Departments & Org chart are now tabs within
    // the People hub (their old routes redirect to /hr/people).
    const people: SubPanelGroup = {
        label: 'People',
        items: [],
    };
    // Route /hr/people requires employees.viewAny; gate on the same (viewOwn,
    // held by all frontline, used to surface a link that 403'd on click).
    if (can?.hr?.employees?.viewAny) {
        people.items.push({ title: 'People', href: '/hr/people', icon: Users });
    }
    if (can?.hr?.recruitment?.view) {
        people.items.push({
            title: 'Recruitment',
            href: '/hr/recruitment',
            icon: Users,
        });
    }
    if (people.items.length > 0) groups.push(people);

    // Time & Leave
    const timeAndLeave: SubPanelGroup = { label: 'Time & Leave', items: [] };
    if (can?.hr?.leave?.viewAny) {
        timeAndLeave.items.push({
            title: 'Leave & Rosters',
            href: '/hr/leave',
            icon: CalendarDays,
        });
    }
    if (can?.hr?.time?.view) {
        timeAndLeave.items.push({
            title: 'Timekeeping',
            href: '/hr/time',
            icon: Clock,
        });
    }
    if (
        can?.hr?.calendar?.view ||
        can?.hr?.leave?.viewAny ||
        can?.hr?.leave?.viewOwn
    ) {
        timeAndLeave.items.push({
            title: 'Calendar',
            href: '/hr/calendar',
            icon: CalendarDays,
        });
    }
    if (timeAndLeave.items.length > 0) groups.push(timeAndLeave);

    // Pay & Benefits — compensation, benefit plans and expense reimbursement.
    const payAndBenefits: SubPanelGroup = {
        label: 'Pay & Benefits',
        items: [],
    };
    if (
        can?.hr?.compensation?.view ||
        can?.hr?.benefits?.view ||
        can?.hr?.expenses?.view
    ) {
        payAndBenefits.items.push({
            title: 'Compensation & Benefits',
            href: can?.hr?.compensation?.view
                ? '/hr/compensation/bands'
                : can?.hr?.benefits?.view
                  ? '/hr/compensation/benefits'
                  : '/hr/compensation/expenses',
            icon: DollarSign,
        });
    }
    if (payAndBenefits.items.length > 0) groups.push(payAndBenefits);

    // Performance — Reviews, Goals & OKRs, Competencies, 360 Feedback, PIPs &
    // Succession are now tabs within the Performance hub.
    const performance: SubPanelGroup = {
        label: 'Performance & Development',
        items: [],
    };
    if (can?.hr?.performance?.view) {
        performance.items.push({
            title: 'Performance',
            href: '/hr/performance',
            icon: ClipboardCheck,
        });
    }
    // Goals & OKRs is also a tab inside the Performance hub, but surface a
    // direct link so the register is reachable in one click from the nav.
    if (can?.hr?.performance?.view) {
        performance.items.push({
            title: 'Goals & OKRs',
            href: '/hr/goals',
            icon: Target,
        });
    }
    if (can?.hr?.training?.view) {
        performance.items.push({
            title: 'Training',
            href: '/hr/training',
            icon: GraduationCap,
        });
    }
    if (performance.items.length > 0) groups.push(performance);

    // Engagement
    const engagement: SubPanelGroup = { label: 'Engagement', items: [] };
    // Gate on the recognition-view permission that actually guards the
    // /hr/feed route (granted to all staff), not announcements/employees —
    // those hid the peer-recognition feed from the frontline it's meant for.
    if (can?.hr?.recognition?.view) {
        engagement.items.push({
            title: 'Community Feed',
            href: '/hr/feed',
            icon: MessageSquareText,
        });
    }
    if (can?.hr?.announcements?.view) {
        engagement.items.push({
            title: 'Announcements',
            href: '/hr/announcements',
            icon: MessageSquareText,
        });
    }
    // Surveys & Wellbeing unified on the richer engagement-survey system
    // (the standalone /hr/surveys system was retired and redirects here).
    // /hr/wellbeing requires wellbeing.view; gate on exactly that so the link
    // never shows to analytics/surveys-only roles that would 403 on click.
    if (can?.hr?.wellbeing?.view) {
        engagement.items.push({
            title: 'Surveys & Wellbeing',
            href: '/hr/wellbeing',
            icon: Target,
        });
    }
    if (engagement.items.length > 0) groups.push(engagement);

    // The former flat "Admin" group (20 items) is split into three short,
    // scannable panels. Several entries became hub tabs and are reached from
    // their hub's tab strip: Course Catalog -> Compliance; Analytics +
    // Headcount -> Reports; Onboarding Emails -> Onboarding; Payslips ->
    // Payroll; Signatures -> Documents. ("Expiring Docs" pointed at a
    // non-existent route and was removed.)

    // Compliance & Records — regulatory compliance plus the document, policy,
    // asset and skills registers.
    const records: SubPanelGroup = { label: 'Compliance & Records', items: [] };
    if (can?.hr?.compliance?.view) {
        records.items.push({
            title: 'Compliance',
            href: '/hr/compliance',
            icon: Shield,
        });
    }
    if (can?.hr?.documents?.view || can?.hr?.policies?.view) {
        records.items.push({
            title: 'Documents & Policies',
            href: can?.hr?.documents?.view
                ? '/hr/documents'
                : '/hr/documents/policies',
            icon: FileText,
        });
    }
    if (can?.hr?.assets?.view) {
        records.items.push({
            title: 'Assets',
            href: '/hr/assets',
            icon: Package,
        });
    }
    if (records.items.length > 0) groups.push(records);

    // Employee Lifecycle — joiners, casework, leavers and approvals.
    const lifecycle: SubPanelGroup = {
        label: 'Employee Lifecycle',
        items: [],
    };
    if (can?.hr?.onboarding?.view) {
        lifecycle.items.push({
            title: 'Onboarding',
            href: '/hr/onboarding',
            icon: ClipboardCheck,
        });
    }
    if (can?.hr?.onboarding?.view) {
        lifecycle.items.push({
            title: 'Offboarding',
            href: '/hr/offboarding',
            icon: ClipboardCheck,
        });
    }
    if (can?.hr?.cases?.view) {
        lifecycle.items.push({
            title: 'HR Cases',
            href: '/hr/cases',
            icon: Shield,
        });
    }
    // Gate on the exit-interviews perms the route actually requires (was
    // cases.view||employees.manage, which 403'd for roles lacking exit-interviews).
    if (
        can?.hr?.['exit-interviews']?.view ||
        can?.hr?.['exit-interviews']?.manage
    ) {
        lifecycle.items.push({
            title: 'Exit Interviews',
            href: '/hr/exit-interviews',
            icon: Users,
        });
    }
    if (can?.hr?.approvals?.view || can?.hr?.approvals?.manage) {
        lifecycle.items.push({
            title: 'Approvals',
            href: '/hr/approvals/pending',
            icon: ClipboardCheck,
        });
    }
    if (lifecycle.items.length > 0) groups.push(lifecycle);

    // Payroll — Runs (hr.payroll.view) + Payslips (hr.payslips.view) are tabs
    // within the Payroll hub; show one entry if either is openable, deep-linked
    // to a page the user can actually open (no 403-on-click).
    const payroll: SubPanelGroup = { label: 'Payroll', items: [] };
    if (can?.hr?.payroll?.view || can?.hr?.payslips?.view) {
        payroll.items.push({
            title: 'Payroll',
            href: can?.hr?.payroll?.view
                ? '/hr/payroll'
                : '/hr/payroll/payslips',
            icon: DollarSign,
        });
    }
    if (payroll.items.length > 0) groups.push(payroll);

    // Admin & Configuration — reporting, configuration and data import/export.
    const adminConfig: SubPanelGroup = {
        label: 'Admin & Configuration',
        items: [],
    };
    if (can?.hr?.reports?.view) {
        adminConfig.items.push({
            title: 'Reports',
            href: '/hr/reports',
            icon: FileText,
        });
    }
    if (can?.hr?.settings?.manage) {
        adminConfig.items.push({
            title: 'Settings',
            href: '/hr/settings/webhooks',
            icon: Settings,
        });
    }
    // /hr/import-export requires employees.manage, not just viewAny.
    if (can?.hr?.employees?.manage) {
        adminConfig.items.push({
            title: 'Import/Export',
            href: '/hr/import-export',
            icon: FileText,
        });
    }
    if (adminConfig.items.length > 0) groups.push(adminConfig);

    return groups;
}

// ── Sub-panel component ────────────────────────────────────────────────────

function SubPanel({
    groups,
    currentUrl,
    isSidebarCollapsed,
    onClose,
    title = '',
}: {
    groups: SubPanelGroup[];
    currentUrl: string;
    isSidebarCollapsed: boolean;
    onClose: () => void;
    title?: string;
}) {
    const panelRef = useRef<HTMLDivElement>(null);
    const visibleGroups = filterVisibleSidebarGroups(groups);

    useEffect(() => {
        function handleClickOutside(e: MouseEvent) {
            if (
                panelRef.current &&
                !panelRef.current.contains(e.target as Node)
            ) {
                // Check if the click is on the sidebar icon that triggered the panel
                const target = e.target as HTMLElement;
                if (target.closest('[data-sub-panel-trigger]')) return;
                onClose();
            }
        }
        function handleEsc(e: KeyboardEvent) {
            if (e.key === 'Escape') onClose();
        }
        document.addEventListener('mousedown', handleClickOutside);
        document.addEventListener('keydown', handleEsc);
        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
            document.removeEventListener('keydown', handleEsc);
        };
    }, [onClose]);

    return (
        <div
            ref={panelRef}
            className={cn(
                'fixed top-[58px] bottom-0 z-50 w-64 overflow-y-auto border-r border-sidebar-border bg-sidebar text-sidebar-foreground shadow-lg transition-[left] duration-200 ease-in-out',
                isSidebarCollapsed ? 'left-16' : 'left-66',
            )}
        >
            {/* Panel header */}
            <div className="flex items-center justify-between border-b border-sidebar-border/50 px-4 py-3">
                <span className="text-sm font-semibold text-sidebar-foreground">
                    {title || 'Menu'}
                </span>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label="Close menu"
                    onClick={onClose}
                    className="h-6 w-6 rounded-md text-sidebar-foreground/60 transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
                >
                    <X className="h-4 w-4" />
                </Button>
            </div>

            {/* Panel links. Section captions were dropped (2026-09-16):
                they competed with the links and made the panel harder to
                scan, so each module is one continuous list in group order. */}
            <div className="py-2">
                {flattenSidebarGroups(visibleGroups).map(({ key, item }) => {
                    const active = isSubItemActive(currentUrl, item.href);
                    return (
                        <Link
                            key={key}
                            href={item.href}
                            aria-current={active ? 'page' : undefined}
                            prefetch
                            preserveScroll
                            className={cn(
                                'flex items-center gap-3 px-4 py-2 text-sm transition-colors',
                                active
                                    ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                                    : 'text-sidebar-foreground/80 hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground',
                            )}
                        >
                            {item.icon && <SidebarItemIcon icon={item.icon} />}
                            <span className="truncate">{item.title}</span>
                            {item.badge != null && item.badge > 0 && (
                                <span className="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-status-critical px-1 text-[10px] leading-none font-bold text-white">
                                    {item.badge > 9 ? '9+' : item.badge}
                                </span>
                            )}
                            {active && (
                                <ChevronRight
                                    className={cn(
                                        'h-3 w-3 text-sidebar-foreground/40',
                                        item.badge != null && item.badge > 0
                                            ? 'ml-0'
                                            : 'ml-auto',
                                    )}
                                />
                            )}
                        </Link>
                    );
                })}
            </div>
        </div>
    );
}

function InlineSubPanelGroups({
    groups,
    currentUrl,
    title,
}: {
    groups: SubPanelGroup[];
    currentUrl: string;
    title: string;
}) {
    const visibleGroups = filterVisibleSidebarGroups(groups);

    // Section captions were dropped (2026-09-16): they competed with the
    // links and made the rail harder to scan. Each module renders one
    // continuous list; the group order is preserved.
    return (
        <div
            role="group"
            aria-label={`${title} navigation`}
            className="mt-0.5 mb-1 space-y-px py-0.5"
        >
            {flattenSidebarGroups(visibleGroups).map(
                ({ key, item: subItem }) => {
                    const active = isSubItemActive(currentUrl, subItem.href);

                    return (
                        <Link
                            key={key}
                            href={subItem.href}
                            aria-current={active ? 'page' : undefined}
                            prefetch
                            preserveScroll
                            data-sidebar-item
                            className={cn(
                                'flex min-h-8 w-full items-center gap-2 rounded-md py-1.5 pr-2 pl-[38px] text-[13px] transition-colors outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring',
                                active
                                    ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                                    : 'text-sidebar-foreground/80 hover:bg-sidebar-accent/50 hover:text-sidebar-accent-foreground',
                            )}
                        >
                            <span className="min-w-0 flex-1 truncate">
                                {subItem.title}
                            </span>
                            <CountPill count={subItem.badge ?? 0} />
                        </Link>
                    );
                },
            )}
        </div>
    );
}

// ── Main AppSidebar component ──────────────────────────────────────────────

export function AppSidebar({
    collapsed: collapsedProp,
    onCollapsedChange,
}: {
    collapsed?: boolean;
    onCollapsedChange?: (collapsed: boolean) => void;
}) {
    const page = usePage<PageProps & Record<string, any>>();
    const { auth, labels: labelsProp } = page.props;
    const role = auth.user?.role ?? null;
    // Inertia returns fresh object identities for shared props on every
    // visit; stabilise the ones feeding the nav-tree memos below so the
    // 200+-item structure is only rebuilt when the contents change.
    const can = useStableValue(auth?.can);
    const portalClients = useStableValue(auth?.portalClients ?? null);
    const labels = useStableValue(labelsProp);
    const unreadMessageCount = (auth as any)?.unreadMessageCount ?? 0;
    const currentUrl = page.url;
    const { collapsed: fallbackCollapsed, setExpanded: setFallbackExpanded } =
        useAppSidebarState((page.props as any)?.sidebarOpen ?? true);
    const isCollapsed = collapsedProp ?? fallbackCollapsed;

    // Flyout panel for the collapsed icon rail (one at a time)…
    const [openPanelId, setOpenPanelId] = useState<string | null>(null);
    // …vs the folded/unfolded module groups of the expanded rail (any number,
    // persisted per user).
    const [expandedGroupIds, setExpandedGroupIds] =
        useState<string[]>(readStoredGroupIds);
    const railScrollRef = useRef<HTMLDivElement>(null);

    // Restore the rail's scroll offset before paint so the remount is invisible.
    useLayoutEffect(() => {
        const el = railScrollRef.current;
        if (!el) return;
        const stored = readStoredScrollTop();
        if (stored > 0) el.scrollTop = stored;
    }, []);

    const iconNavItems = useMemo(
        () =>
            buildIconNavItems({ role, can, portalClients, unreadMessageCount }),
        [role, can, portalClients, unreadMessageCount],
    );

    const subPanelMap = useMemo(
        () => ({
            'it-provisioning': buildItSubPanelGroups({ can }),
            sites: buildSitesSubPanelGroups({ can }),
            operations: buildOperationsSubPanelGroups({
                can,
                role,
                labels: labels as any,
            }),
            workforce: buildWorkforceSubPanelGroups({ can }),
            emar: buildEmarSubPanelGroups({ can }),
            safety: buildSafetySubPanelGroups({ can }),
            'fleet-assets': buildFleetAssetsSubPanelGroups({ can }),
            hr: buildHrSubPanelGroups({ can }),
            governance: buildGovernanceSubPanelGroups({ can }),
            finance: buildFinanceSubPanelGroups({ can }),
            reporting: buildReportingSubPanelGroups({ can }),
            'security-devices': buildSecurityDevicesSubPanelGroups({ can }),
            'control-room': buildControlRoomSubPanelGroups({ can }),
        }),
        [can, labels, role],
    );

    const toggleSubPanel = useCallback((id: string) => {
        setOpenPanelId((prev) => (prev === id ? null : id));
    }, []);

    const closeSubPanel = useCallback(() => {
        setOpenPanelId(null);
    }, []);

    const toggleGroup = useCallback((id: string) => {
        setExpandedGroupIds((prev) => {
            const next = prev.includes(id)
                ? prev.filter((groupId) => groupId !== id)
                : [...prev, id];
            persistGroupIds(next);
            return next;
        });
    }, []);

    const toggleCollapsed = useCallback(() => {
        const nextCollapsed = !isCollapsed;

        if (onCollapsedChange) {
            onCollapsedChange(nextCollapsed);
        } else {
            setFallbackExpanded(!nextCollapsed);
        }
    }, [isCollapsed, onCollapsedChange, setFallbackExpanded]);

    // Folding a group must never hide where you are: unfold the active module
    // after navigation (other folds are left as the user set them). The
    // collapsed rail instead drops its flyout on navigation.
    useEffect(() => {
        if (isCollapsed) {
            setOpenPanelId(null);
            return;
        }

        const activePanel = iconNavItems.find((item) => {
            const panelGroups = item.subPanel
                ? ((subPanelMap as any)[item.id] as SubPanelGroup[] | undefined)
                : undefined;

            return item.subPanel && isIconActive(currentUrl, item, panelGroups);
        });

        if (!activePanel) return;

        setExpandedGroupIds((prev) => {
            if (prev.includes(activePanel.id)) return prev;
            const next = [...prev, activePanel.id];
            persistGroupIds(next);
            return next;
        });
    }, [currentUrl, iconNavItems, isCollapsed, subPanelMap]);

    return (
        <TooltipProvider delayDuration={0}>
            <div
                className={cn(
                    'sticky z-30 hidden shrink-0 md:block',
                    SHELL_HEADER_HEIGHT_CLASS,
                )}
            >
                <nav
                    id="app-sidebar-nav"
                    aria-label="Primary navigation"
                    data-state={isCollapsed ? 'collapsed' : 'expanded'}
                    className={cn(
                        'flex h-full flex-col overflow-x-hidden bg-sidebar pt-2 pb-2 text-sidebar-foreground transition-[width] duration-200 ease-in-out',
                        isCollapsed ? 'w-16 items-center' : 'w-66',
                    )}
                >
                    <div
                        ref={railScrollRef}
                        onScroll={(event) =>
                            persistScrollTop(event.currentTarget.scrollTop)
                        }
                        className={cn(
                            'scrollbar-none flex w-full flex-1 flex-col gap-0.5 overflow-y-auto px-2',
                            isCollapsed ? 'items-center' : 'items-stretch',
                        )}
                    >
                        {iconNavItems.map((item) => {
                            const panelGroups = item.subPanel
                                ? ((subPanelMap as any)[item.id] as
                                      | SubPanelGroup[]
                                      | undefined)
                                : undefined;
                            const active = isIconActive(
                                currentUrl,
                                item,
                                panelGroups,
                            );
                            const itemClassName = cn(
                                SIDEBAR_ITEM_BASE,
                                'py-2',
                                isCollapsed
                                    ? 'justify-center px-0'
                                    : 'justify-start gap-2.5 px-3',
                                active
                                    ? SIDEBAR_ITEM_ACTIVE
                                    : SIDEBAR_ITEM_INACTIVE,
                            );
                            const iconClassName = active
                                ? SIDEBAR_ICON_ACTIVE
                                : SIDEBAR_ICON_INACTIVE;

                            if (item.subPanel) {
                                const isPanelOpen = openPanelId === item.id;
                                const isGroupOpen = expandedGroupIds.includes(
                                    item.id,
                                );
                                const isExpanded = isCollapsed
                                    ? isPanelOpen
                                    : isGroupOpen;
                                // Folding a group must never hide an alert:
                                // roll its children's counts up onto the
                                // header row (visible in every fold state).
                                const groupBadge = (panelGroups ?? []).reduce(
                                    (total, group) =>
                                        total +
                                        (group?.items ?? []).reduce(
                                            (sum, sub) =>
                                                sum + (sub.badge ?? 0),
                                            0,
                                        ),
                                    0,
                                );

                                return (
                                    <div
                                        key={item.id}
                                        className={cn(
                                            'w-full',
                                            item.dividerAfter &&
                                                'mb-1 border-b border-sidebar-border/60 pb-1',
                                        )}
                                    >
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                {/* eslint-disable-next-line no-restricted-syntax -- nav row on the ink chrome; <Button>'s ghost hover fights the sidebar tokens */}
                                                <button
                                                    type="button"
                                                    data-sub-panel-trigger
                                                    data-sidebar-item
                                                    aria-label={`${item.label} menu`}
                                                    aria-current={
                                                        active
                                                            ? 'page'
                                                            : undefined
                                                    }
                                                    aria-expanded={isExpanded}
                                                    onClick={() =>
                                                        isCollapsed
                                                            ? toggleSubPanel(
                                                                  item.id,
                                                              )
                                                            : toggleGroup(
                                                                  item.id,
                                                              )
                                                    }
                                                    className={itemClassName}
                                                >
                                                    <SidebarItemIcon
                                                        icon={item.icon}
                                                        className={
                                                            iconClassName
                                                        }
                                                    />
                                                    {!isCollapsed && (
                                                        <>
                                                            <span className="min-w-0 flex-1 truncate text-left font-medium">
                                                                {item.label}
                                                            </span>
                                                            <CountPill
                                                                count={
                                                                    groupBadge
                                                                }
                                                            />
                                                            <ChevronRight
                                                                className={cn(
                                                                    'size-4 shrink-0 opacity-60 transition-transform',
                                                                    isExpanded &&
                                                                        'rotate-90',
                                                                )}
                                                            />
                                                        </>
                                                    )}
                                                    {isCollapsed && (
                                                        <CountPill
                                                            count={groupBadge}
                                                            collapsed
                                                        />
                                                    )}
                                                </button>
                                            </TooltipTrigger>
                                            <TooltipContent
                                                side="right"
                                                hidden={!isCollapsed}
                                            >
                                                {item.label}
                                            </TooltipContent>
                                        </Tooltip>

                                        {!isCollapsed && isGroupOpen ? (
                                            <InlineSubPanelGroups
                                                groups={panelGroups ?? []}
                                                currentUrl={currentUrl}
                                                title={item.label}
                                            />
                                        ) : null}
                                    </div>
                                );
                            }

                            return (
                                <div
                                    key={item.id}
                                    className={cn(
                                        'w-full',
                                        item.dividerAfter &&
                                            'mb-1 border-b border-sidebar-border/60 pb-1',
                                    )}
                                >
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <Link
                                                href={item.href!}
                                                aria-current={
                                                    active ? 'page' : undefined
                                                }
                                                aria-label={item.label}
                                                prefetch
                                                data-sidebar-item
                                                className={itemClassName}
                                            >
                                                <SidebarItemIcon
                                                    icon={item.icon}
                                                    className={iconClassName}
                                                />
                                                {!isCollapsed && (
                                                    <span className="min-w-0 flex-1 truncate">
                                                        {item.label}
                                                    </span>
                                                )}
                                                <CountPill
                                                    count={item.badge ?? 0}
                                                    collapsed={isCollapsed}
                                                    testId={`sidebar-badge-${item.id}`}
                                                />
                                            </Link>
                                        </TooltipTrigger>
                                        <TooltipContent
                                            side="right"
                                            hidden={!isCollapsed}
                                        >
                                            {item.label}
                                        </TooltipContent>
                                    </Tooltip>
                                </div>
                            );
                        })}
                    </div>

                    <div
                        className={cn(
                            'mt-auto flex w-full flex-col gap-1 border-t border-sidebar-border px-2 pt-2',
                            isCollapsed ? 'items-center' : 'items-stretch',
                        )}
                    >
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Link
                                    href="/settings"
                                    aria-current={
                                        currentUrl.startsWith('/settings')
                                            ? 'page'
                                            : undefined
                                    }
                                    aria-label="Settings"
                                    prefetch
                                    data-sidebar-item
                                    className={cn(
                                        SIDEBAR_ITEM_BASE,
                                        'py-2',
                                        isCollapsed
                                            ? 'justify-center px-0'
                                            : 'justify-start gap-2.5 px-3',
                                        currentUrl.startsWith('/settings')
                                            ? SIDEBAR_ITEM_ACTIVE
                                            : SIDEBAR_ITEM_INACTIVE,
                                    )}
                                >
                                    <Settings
                                        aria-hidden="true"
                                        className={cn(
                                            SIDEBAR_OPCN_CLASS,
                                            currentUrl.startsWith('/settings')
                                                ? SIDEBAR_ICON_ACTIVE
                                                : SIDEBAR_ICON_INACTIVE,
                                        )}
                                    />
                                    {!isCollapsed && (
                                        <span className="min-w-0 flex-1 truncate">
                                            Settings
                                        </span>
                                    )}
                                </Link>
                            </TooltipTrigger>
                            <TooltipContent side="right" hidden={!isCollapsed}>
                                Settings
                            </TooltipContent>
                        </Tooltip>
                    </div>
                </nav>

                {/* Whole-sidebar collapse: the edge tab handle — a 12×56px
                    lip flowing out of the sidebar's right edge, with an
                    invisible ≥44px hit area around the slim visual. */}
                {/* eslint-disable-next-line no-restricted-syntax -- custom edge-tab control; no Button variant matches the lip */}
                <button
                    type="button"
                    aria-controls="app-sidebar-nav"
                    aria-expanded={!isCollapsed}
                    aria-label={
                        isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'
                    }
                    title={isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                    onClick={toggleCollapsed}
                    className="group absolute top-1/2 left-full z-40 -translate-y-1/2 outline-none"
                >
                    <span
                        aria-hidden="true"
                        className="absolute -inset-x-4 -inset-y-4"
                    />
                    <span className="flex h-14 w-3 items-center justify-center rounded-r-[7px] bg-sidebar text-sidebar-foreground/70 shadow-md transition-colors group-hover:text-sidebar-accent-foreground group-focus-visible:ring-2 group-focus-visible:ring-sidebar-ring">
                        {isCollapsed ? (
                            <ChevronRight className="size-3" />
                        ) : (
                            <ChevronLeft className="size-3" />
                        )}
                    </span>
                </button>

                {isCollapsed &&
                    openPanelId &&
                    (subPanelMap as any)[openPanelId] && (
                        <SubPanel
                            groups={(subPanelMap as any)[openPanelId]}
                            currentUrl={currentUrl}
                            isSidebarCollapsed={isCollapsed}
                            onClose={closeSubPanel}
                            title={
                                iconNavItems.find((i) => i.id === openPanelId)
                                    ?.label ?? ''
                            }
                        />
                    )}
            </div>
        </TooltipProvider>
    );
}

// ── Mobile sidebar (full drawer with labels) ──────────────────────────────

export function AppSidebarMobile({ onClose }: { onClose: () => void }) {
    const page = usePage<PageProps & Record<string, any>>();
    const { auth, labels: labelsProp } = page.props as any;
    const role = auth.user?.role ?? null;
    // Same identity-stabilisation as the desktop sidebar above.
    const can = useStableValue(auth?.can);
    const portalClients = useStableValue(auth?.portalClients ?? null);
    const labels = useStableValue(labelsProp);
    const unreadMessageCount = auth?.unreadMessageCount ?? 0;
    const currentUrl = page.url;

    const iconNavItems = useMemo(
        () =>
            buildIconNavItems({ role, can, portalClients, unreadMessageCount }),
        [role, can, portalClients, unreadMessageCount],
    );

    const mobileSubPanelMap = useMemo(
        () => ({
            'it-provisioning': buildItSubPanelGroups({ can }),
            sites: buildSitesSubPanelGroups({ can }),
            operations: buildOperationsSubPanelGroups({
                can,
                role,
                labels: labels as any,
            }),
            workforce: buildWorkforceSubPanelGroups({ can }),
            emar: buildEmarSubPanelGroups({ can }),
            safety: buildSafetySubPanelGroups({ can }),
            'fleet-assets': buildFleetAssetsSubPanelGroups({ can }),
            hr: buildHrSubPanelGroups({ can }),
            governance: buildGovernanceSubPanelGroups({ can }),
            finance: buildFinanceSubPanelGroups({ can }),
            reporting: buildReportingSubPanelGroups({ can }),
            'security-devices': buildSecurityDevicesSubPanelGroups({ can }),
            'control-room': buildControlRoomSubPanelGroups({ can }),
        }),
        [can, labels, role],
    );

    const [expandedId, setExpandedId] = useState<string | null>(null);

    // Close on navigation
    useEffect(() => {
        onClose();
    }, [currentUrl, onClose]);

    return (
        <SheetContent
            side="left"
            overlayClassName="bg-black/50 md:hidden"
            closeButtonClassName="frontline-focus frontline-tap top-1.5 right-2.5 flex items-center justify-center rounded-md text-sidebar-foreground opacity-100 hover:bg-sidebar-accent hover:opacity-100 focus:ring-sidebar-ring [&_svg]:size-5"
            closeLabel="Close menu"
            className="w-72 max-w-[calc(100vw-2rem)] gap-0 overflow-hidden border-sidebar-border bg-sidebar p-0 text-sidebar-foreground sm:max-w-72 md:hidden"
        >
            <SheetHeader className="min-h-14 justify-center border-b border-sidebar-border/50 px-4 py-3 pr-16">
                <SheetTitle className="text-sm text-sidebar-foreground">
                    Menu
                </SheetTitle>
                <SheetDescription className="sr-only">
                    Choose an area to navigate to.
                </SheetDescription>
            </SheetHeader>

            <nav
                aria-label="Main navigation"
                className="min-h-0 flex-1 overflow-y-auto px-0.5 py-2"
            >
                {iconNavItems.map((item) => {
                    if (item.subPanel) {
                        const groups = (mobileSubPanelMap as any)[item.id] as
                            | SubPanelGroup[]
                            | undefined;
                        const active = isIconActive(currentUrl, item, groups);
                        const isExpanded = expandedId === item.id;
                        return (
                            <div key={item.id}>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    aria-expanded={isExpanded}
                                    aria-current={active ? 'page' : undefined}
                                    aria-label={`${item.label} menu`}
                                    onClick={() =>
                                        setExpandedId(
                                            isExpanded ? null : item.id,
                                        )
                                    }
                                    className={cn(
                                        'frontline-focus min-h-11 w-full justify-start gap-3 rounded-none px-4 py-2 text-sm font-normal transition-colors',
                                        active
                                            ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                                            : 'text-sidebar-foreground/80 hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground',
                                    )}
                                >
                                    <SidebarItemIcon icon={item.icon} />
                                    <span>{item.label}</span>
                                    <ChevronRight
                                        className={cn(
                                            'ml-auto h-4 w-4 transition-transform',
                                            isExpanded && 'rotate-90',
                                        )}
                                    />
                                </Button>
                                {isExpanded &&
                                    filterVisibleSidebarGroups(
                                        groups ?? [],
                                    ).map((group) => (
                                        <div key={group.label} className="ml-4">
                                            <div className="px-4 py-1 text-[11px] font-medium tracking-wider text-sidebar-foreground/40 uppercase">
                                                {group.label}
                                            </div>
                                            {(group.items ?? []).map((sub) => (
                                                <Link
                                                    key={resolveUrl(sub.href)}
                                                    href={sub.href}
                                                    onClick={onClose}
                                                    aria-current={
                                                        isSubItemActive(
                                                            currentUrl,
                                                            sub.href,
                                                        )
                                                            ? 'page'
                                                            : undefined
                                                    }
                                                    prefetch
                                                    className={cn(
                                                        'frontline-focus flex min-h-11 items-center gap-3 px-4 py-2 text-sm transition-colors',
                                                        isSubItemActive(
                                                            currentUrl,
                                                            sub.href,
                                                        )
                                                            ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                                                            : 'text-sidebar-foreground/80 hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground',
                                                    )}
                                                >
                                                    {sub.icon && (
                                                        <SidebarItemIcon
                                                            icon={sub.icon}
                                                        />
                                                    )}
                                                    <span>{sub.title}</span>
                                                    {sub.badge != null &&
                                                        sub.badge > 0 && (
                                                            <span className="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-status-critical px-1 text-[10px] leading-none font-bold text-white">
                                                                {sub.badge > 9
                                                                    ? '9+'
                                                                    : sub.badge}
                                                            </span>
                                                        )}
                                                </Link>
                                            ))}
                                        </div>
                                    ))}
                                {item.dividerAfter && (
                                    <div className="mx-4 my-1 border-b border-sidebar-border/30" />
                                )}
                            </div>
                        );
                    }

                    const active = item.href
                        ? matchScore(currentUrl, item.href) > 0
                        : false;
                    return (
                        <div key={item.id}>
                            <Link
                                href={item.href!}
                                onClick={onClose}
                                aria-current={active ? 'page' : undefined}
                                prefetch
                                className={cn(
                                    'frontline-focus flex min-h-11 items-center gap-3 px-4 py-2 text-sm transition-colors',
                                    active
                                        ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                                        : 'text-sidebar-foreground/80 hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground',
                                )}
                            >
                                <SidebarItemIcon icon={item.icon} />
                                <span>{item.label}</span>
                            </Link>
                            {item.dividerAfter && (
                                <div className="mx-4 my-1 border-b border-sidebar-border/30" />
                            )}
                        </div>
                    );
                })}
            </nav>
        </SheetContent>
    );
}

// ── Flat, permission-filtered nav catalog for global search ────────────────

export type NavSearchItem = {
    id: string;
    label: string;
    href: string;
    section: string;
    group?: string;
    icon?: LucideIcon;
};

export function buildNavSearchCatalog(ctx: {
    role?: string | null;
    can?: any;
    labels?: Record<string, string> | null;
    portalClients?: PortalClient[] | null;
    unreadMessageCount?: number;
}): NavSearchItem[] {
    const { role, can, labels, portalClients, unreadMessageCount } = ctx;

    const iconNavItems = buildIconNavItems({
        role,
        can,
        portalClients,
        unreadMessageCount,
    });

    const subPanelMap: Record<string, SubPanelGroup[]> = {
        'it-provisioning': buildItSubPanelGroups({ can }),
        sites: buildSitesSubPanelGroups({ can }),
        operations: buildOperationsSubPanelGroups({
            can,
            role,
            labels: labels as any,
        }),
        workforce: buildWorkforceSubPanelGroups({ can }),
        emar: buildEmarSubPanelGroups({ can }),
        safety: buildSafetySubPanelGroups({ can }),
        'fleet-assets': buildFleetAssetsSubPanelGroups({ can }),
        hr: buildHrSubPanelGroups({ can }),
        governance: buildGovernanceSubPanelGroups({ can }),
        finance: buildFinanceSubPanelGroups({ can }),
        reporting: buildReportingSubPanelGroups({ can }),
        'security-devices': buildSecurityDevicesSubPanelGroups({ can }),
        'control-room': buildControlRoomSubPanelGroups({ can }),
        system: buildSystemSubPanelGroups({ can }),
    };

    const catalog: NavSearchItem[] = [];
    const seen = new Set<string>();

    const push = (item: NavSearchItem) => {
        const key = `${item.href}|${item.label}`;
        if (seen.has(key)) return;
        seen.add(key);
        catalog.push(item);
    };

    for (const icon of iconNavItems) {
        // Keep relocated Fleet pages discoverable in command search as well as
        // their workspace menus; consolidation only shortens the left rail.
        if (icon.id === 'fleet-assets') {
            for (const workspace of FLEET_WORKSPACES) {
                for (const group of visibleFleetGroups(workspace, can)) {
                    for (const item of group.links) {
                        push({
                            id: `fleet-assets:${workspace.key}:${item.href}`,
                            label: item.label,
                            href: item.href,
                            section: icon.label,
                            group: workspace.label,
                            icon: workspace.icon,
                        });
                    }
                }
            }
        }
        if (icon.href && !icon.subPanel) {
            push({
                id: icon.id,
                label: icon.label,
                href: resolveUrl(icon.href),
                section: 'General',
                icon: icon.icon,
            });
        }

        if (icon.subPanel) {
            const groups = filterVisibleSidebarGroups(
                subPanelMap[icon.id] ?? [],
            );
            for (const group of groups) {
                for (const sub of group?.items ?? []) {
                    const href = resolveUrl(sub.href);
                    const clean = sub.title.includes(' > ')
                        ? (sub.title.split(' > ').pop() ?? sub.title)
                        : sub.title;
                    push({
                        id: `${icon.id}:${href}`,
                        label: clean,
                        href,
                        section: icon.label,
                        group: group.label,
                        icon: sub.icon ?? icon.icon,
                    });
                }
            }
        }
    }

    return catalog;
}
