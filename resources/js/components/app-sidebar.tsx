import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { UserMenuContent } from '@/components/user-menu-content';
import { useAppSidebarState } from '@/hooks/use-app-sidebar-state';
import { useInitials } from '@/hooks/use-initials';
import { cn, resolveUrl } from '@/lib/utils';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    AlertOctagon,
    AlertTriangle,
    ArrowLeftRight,
    ArrowUpCircle,
    Banknote,
    BarChart3,
    Bell,
    BookOpen,
    Briefcase,
    Building2,
    CalendarDays,
    Car,
    Cctv,
    CheckCircle2,
    ChevronRight,
    Clipboard,
    ClipboardCheck,
    ClipboardList,
    Clock,
    Coins,
    CreditCard,
    DollarSign,
    Download,
    FileText,
    FlaskConical,
    FolderOpen,
    Fuel,
    GitBranch,
    GraduationCap,
    HardHat,
    Heart,
    HeartPulse,
    Home,
    Key,
    Landmark,
    LayoutDashboard,
    LayoutGrid,
    Link2,
    ListChecks,
    Map,
    MapPin,
    Megaphone,
    MessageSquare,
    MessageSquareText,
    Package,
    PanelLeftClose,
    PanelLeftOpen,
    PersonStanding,
    PieChart,
    Pill,
    Radio,
    Receipt,
    Route,
    Send,
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
    TrendingUp,
    Truck,
    UserCheck,
    Users,
    UserSearch,
    Utensils,
    Wrench,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import AppLogoIcon from './app-logo-icon';
const dashboard = () => '/dashboard';

const SIDEBAR_OPCN_CLASS = 'size-5 shrink-0';
const SIDEBAR_ITEM_BASE =
    'relative flex h-10 w-full items-center rounded-xl text-sm transition-colors outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring';
const SIDEBAR_ITEM_ACTIVE =
    'bg-sidebar-primary text-sidebar-primary-foreground shadow-sm';
const SIDEBAR_ITEM_INACTIVE =
    'text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground';

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
            organization_id?: number | null;
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

interface SubPanelGroup {
    label: string;
    items: NavItem[];
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
    if (item.id === 'operations' && isWorkforceUrl(currentUrl)) {
        return false;
    }
    // For sub-panel items, check if any child is active
    if (item.subPanel && subPanelGroups) {
        return subPanelGroups.some((group) =>
            (group?.items ?? []).some((sub) => matchScore(currentUrl, sub.href) > 0),
        );
    }
    return false;
}

function isSubItemActive(currentUrl: string, href: NavItem['href']): boolean {
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
                      label: 'Dashboard',
                      href: '/dashboard',
                  } as IconNavItem,
                  {
                      id: 'today',
                      icon: ClipboardList,
                      label: 'Today',
                      href: '/today',
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
        !!can?.shifts?.viewAny ||
        !!can?.shifts?.viewAssigned ||
        !!can?.job_board?.viewAny ||
        !!can?.job_board?.claim ||
        !!can?.rostering?.viewAny ||
        !!can?.handovers?.viewAny ||
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
    // Managers / medication leads (orders-manage, audit, or reports) keep the
    // full eMAR sub-panel for oversight, now rooted on the worker view so the
    // first click still matches the frontline experience, with Dashboard kept
    // one level deeper for compliance / management work.
    const canAdminEmar =
        can?.medications?.ordersManage ||
        can?.medications?.auditView ||
        can?.medications?.reportsExport ||
        can?.reports?.viewAny;
    const canWorkerMeds =
        can?.medications?.administerRecord ||
        can?.medications?.view ||
        can?.clients?.update;
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
    const hasFleetAssets =
        can?.fleet?.viewAny ||
        can?.assets?.viewAny ||
        can?.assets?.viewAssigned;
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

    // IT & Provisioning — the account/access/equipment request queue fed by
    // onboarding IT tasks, plus the helpdesk ticket queue.
    if (can?.it?.view) {
        items.push({
            id: 'it-provisioning',
            icon: Server,
            label: 'IT & Provisioning',
            href: '/it',
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
    if (
        can?.finance?.dashboard ||
        can?.finance?.ledger?.view ||
        can?.finance?.ap?.view ||
        can?.finance?.ar?.view ||
        can?.finance?.ar?.manage
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
    if (can?.vendors?.view || can?.credentials?.view)
        items.push({
            title: 'Vendors & Credentials',
            href: '/vendors',
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
    if (can?.client_funds?.manage)
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
    if (can?.rostering?.viewAny || can?.shifts?.viewAny)
        tools.push({
            title: 'Calendar Sync',
            href: '/operations/calendar-sync',
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
    if (can?.handovers?.viewAny || can?.shifts?.viewAny)
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
    const workerItems: NavItem[] = [
        { title: 'Meds today', href: '/meds/today', icon: Activity },
    ];
    groups.push({ label: 'Worker view', items: workerItems });

    // Overview
    groups.push({
        label: 'Overview',
        items: [
            { title: 'Dashboard', href: '/emar', icon: LayoutGrid },
        ],
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
    if (can?.medications?.view)
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
    if (can?.medications?.view)
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
    if (can?.reports?.viewAny)
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
    if (can?.medications?.view)
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
    can?: any;
}): SubPanelGroup[] {
    const groups: SubPanelGroup[] = [];

    // Overview
    const overview: SubPanelGroup = {
        label: 'Overview',
        items: [
            { title: 'Dashboard', href: '/fleet-assets', icon: LayoutGrid },
            { title: 'Live Map', href: '/fleet-assets/map', icon: Map },
            {
                title: 'Daily Checks',
                href: '/fleet-assets/daily-check',
                icon: CheckCircle2,
            },
            {
                title: 'Driver App',
                href: '/fleet-assets/mobile/dashboard',
                icon: Smartphone,
            },
        ],
    };
    groups.push(overview);

    // Fleet
    const fleet: SubPanelGroup = { label: 'Fleet', items: [] };
    if (can?.fleet?.viewAny) {
        fleet.items.push({
            title: 'Vehicles',
            href: '/fleet-assets/vehicles',
            icon: Truck,
        });
        fleet.items.push({
            title: 'Trips',
            href: '/fleet-assets/trips',
            icon: Route,
        });
        fleet.items.push({
            title: 'Fuel Logs',
            href: '/fleet-assets/fuel',
            icon: Fuel,
        });
        fleet.items.push({
            title: 'Compliance',
            href: '/fleet-assets/compliance',
            icon: ShieldCheck,
        });
    }
    if (fleet.items.length > 0) groups.push(fleet);

    // Assets
    const assets: SubPanelGroup = { label: 'Assets', items: [] };
    if (can?.assets?.viewAny || can?.assets?.viewAssigned) {
        assets.items.push({
            title: 'All Assets',
            href: '/fleet-assets/assets',
            icon: Package,
        });
    }
    if (can?.hr?.assets?.view) {
        assets.items.push({
            title: 'HR Asset Register',
            href: '/hr/assets',
            icon: Briefcase,
        });
    }
    if (can?.assets?.alertsView) {
        assets.items.push({
            title: 'Alerts',
            href: '/fleet-assets/alerts',
            icon: AlertTriangle,
        });
    }
    if (
        can?.assets?.geofencesManage ||
        can?.geofences?.viewAny ||
        can?.fleet?.viewAny
    ) {
        assets.items.push({
            title: 'Geofences',
            href: '/fleet-assets/geofences',
            icon: MapPin,
        });
    }
    if (assets.items.length > 0) groups.push(assets);

    // Maintenance
    const maintenance: SubPanelGroup = { label: 'Maintenance', items: [] };
    if (can?.fleet?.viewAny || can?.assets?.viewAny) {
        maintenance.items.push({
            title: 'Overview',
            href: '/fleet-assets/maintenance/dashboard',
            icon: LayoutGrid,
        });
        maintenance.items.push({
            title: 'Work Orders',
            href: '/fleet-assets/maintenance/work-orders',
            icon: Wrench,
        });
        maintenance.items.push({
            title: 'Service Schedules',
            href: '/fleet-assets/maintenance/schedules',
            icon: CalendarDays,
        });
        maintenance.items.push({
            title: 'Checklists',
            href: '/fleet-assets/maintenance/checklists',
            icon: ClipboardCheck,
        });
        maintenance.items.push({
            title: 'Inspections',
            href: '/fleet-assets/inspections',
            icon: ClipboardList,
        });
    }
    if (maintenance.items.length > 0) groups.push(maintenance);

    // People
    const people: SubPanelGroup = { label: 'People', items: [] };
    if (can?.fleet?.viewAny || can?.hr?.driver?.view) {
        people.items.push({
            title: 'Drivers',
            href: '/fleet-assets/drivers',
            icon: Users,
        });
    }
    if (can?.fleet?.viewAny || can?.assets?.viewAny) {
        people.items.push({
            title: 'Vehicle Bookings',
            href: '/fleet-assets/bookings',
            icon: CalendarDays,
        });
        people.items.push({
            title: 'Key Management',
            href: '/fleet-assets/keys',
            icon: Key,
        });
        people.items.push({
            title: 'Resident Tracking',
            href: '/fleet-assets/resident-tracking',
            icon: UserSearch,
        });
        people.items.push({
            title: 'Transport Logs',
            href: '/fleet-assets/transports',
            icon: UserCheck,
        });
        people.items.push({
            title: 'Medication Transit',
            href: '/fleet-assets/transports/medications',
            icon: Pill,
        });
        people.items.push({
            title: 'Outings',
            href: '/fleet-assets/outings',
            icon: MapPin,
        });
        people.items.push({
            title: 'Shift Handovers',
            href: '/fleet-assets/handovers',
            icon: ArrowLeftRight,
        });
    }
    if (people.items.length > 0) groups.push(people);

    // Devices
    const devices: SubPanelGroup = { label: 'Devices', items: [] };
    if (can?.assets?.trackersManage || can?.fleet?.viewAny) {
        devices.items.push({
            title: 'Tracking Devices',
            href: '/fleet-assets/devices',
            icon: Radio,
        });
    }
    if (devices.items.length > 0) groups.push(devices);

    // Safety — keep label/icon consistent with the Health & Safety flyout's
    // "Fleet Incidents" entry so the same destination reads the same everywhere.
    // Wandering Alerts now lives as a tab on Resident Tracking
    // (/fleet-assets/resident-tracking?tab=wandering), so it no longer gets a
    // sidebar entry of its own.
    const safety: SubPanelGroup = { label: 'Safety', items: [] };
    safety.items.push({
        title: 'Fleet Incidents',
        href: '/fleet-assets/incidents',
        icon: Truck,
    });
    if (safety.items.length > 0) groups.push(safety);

    // Reports
    const reports: SubPanelGroup = { label: 'Reports', items: [] };
    if (can?.fleet?.viewAny || can?.assets?.viewAny || can?.reports?.viewAny) {
        reports.items.push({
            title: 'Reports & Analytics',
            href: '/fleet-assets/reports',
            icon: FileText,
        });
        reports.items.push({
            title: 'Usage by House',
            href: '/fleet-assets/reports/by-house',
            icon: Building2,
        });
        reports.items.push({
            title: 'Mileage Reimbursement',
            href: '/fleet-assets/reports/reimbursement',
            icon: Receipt,
        });
        reports.items.push({
            title: 'Mileage Claims',
            href: '/fleet-assets/mileage',
            icon: Receipt,
        });
        reports.items.push({
            title: 'Cost Allocation',
            href: '/fleet-assets/reports/cost-allocation',
            icon: PieChart,
        });
        reports.items.push({
            title: 'Community Access',
            href: '/fleet-assets/reports/community-access',
            icon: Users,
        });
    }
    if (reports.items.length > 0) groups.push(reports);

    // Settings
    const settings: SubPanelGroup = { label: 'Settings', items: [] };
    settings.items.push({
        title: 'Notifications',
        href: '/fleet-assets/settings/notifications',
        icon: Bell,
    });
    if (settings.items.length > 0) groups.push(settings);

    return groups;
}

function buildGovernanceSubPanelGroups({
    can,
}: {
    can?: any;
}): SubPanelGroup[] {
    // Permission-gated builders for each group. Every entry preserves its
    // existing route — this only changes how items are grouped in the sidebar.
    const groups: SubPanelGroup[] = [];

    // Board Meetings — the meeting lifecycle (dashboard, meetings, packs, CEO reports)
    const boardMeetings: NavItem[] = [];
    if (can?.governance?.view) {
        boardMeetings.push({ title: 'Dashboard', href: '/governance/dashboard', icon: Landmark });
        boardMeetings.push({ title: 'Meetings', href: '/governance/meetings', icon: CalendarDays });
    }
    if (can?.governance?.packs?.view || can?.governance?.view) {
        boardMeetings.push({ title: 'Board Packs', href: '/governance/packs', icon: FileText });
    }
    if (can?.governance?.['ceo-reports']?.view || can?.governance?.view) {
        boardMeetings.push({ title: 'CEO Reports', href: '/governance/ceo-reports', icon: FileText });
    }
    if (boardMeetings.length > 0) groups.push({ label: 'Board Meetings', items: boardMeetings });

    // Decisions & Actions — resolutions, actions, evaluations
    const decisions: NavItem[] = [];
    if (can?.governance?.resolutions?.view || can?.governance?.view) {
        decisions.push({ title: 'Resolutions', href: '/governance/resolutions', icon: ClipboardCheck });
    }
    if (can?.governance?.actions?.view || can?.governance?.view) {
        decisions.push({ title: 'Action Items', href: '/governance/actions', icon: ClipboardList });
    }
    if (can?.governance?.evaluations?.view || can?.governance?.view) {
        decisions.push({ title: 'Board Evaluations', href: '/governance/evaluations', icon: ClipboardCheck });
    }
    if (decisions.length > 0) groups.push({ label: 'Decisions & Actions', items: decisions });

    // Risk & Compliance — register, compliance calendar, clinical, Te Tiriti
    const risk: NavItem[] = [];
    if (can?.governance?.risks?.view || can?.governance?.view) {
        risk.push({ title: 'Risk Register', href: '/governance/risks', icon: Target });
    }
    if (can?.governance?.compliance?.view || can?.governance?.view) {
        risk.push({ title: 'Compliance', href: '/governance/compliance', icon: Shield });
    }
    // Operational compliance command centre (org-wide exception roll-up) — surfaced for
    // board assurance. Distinct from the obligations register above; gate on its own view perm.
    if (can?.compliance?.view || can?.governance?.view) {
        risk.push({ title: 'Operational Compliance', href: '/compliance', icon: ShieldCheck });
    }
    if (can?.governance?.clinical?.view || can?.governance?.view) {
        risk.push({ title: 'Clinical Governance', href: '/governance/clinical', icon: Shield });
    }
    if (can?.governance?.['te-tiriti']?.view || can?.governance?.view) {
        risk.push({ title: 'Te Tiriti', href: '/governance/te-tiriti', icon: Landmark });
    }
    if (risk.length > 0) groups.push({ label: 'Risk & Compliance', items: risk });

    // Finance & Spend — budgets and spend approvals
    const finance: NavItem[] = [];
    if (can?.governance?.budgets?.view || can?.governance?.view) {
        finance.push({ title: 'Budgets', href: '/governance/budgets', icon: DollarSign });
    }
    if (can?.governance?.spend?.view) {
        finance.push({ title: 'Spend Approvals', href: '/governance/spend-approvals', icon: DollarSign });
    }
    if (finance.length > 0) groups.push({ label: 'Finance & Spend', items: finance });

    // Strategy & Performance — strategic plan and performance reviews
    const strategy: NavItem[] = [];
    if (can?.governance?.strategy?.view || can?.governance?.view) {
        strategy.push({ title: 'Strategic Plan', href: '/governance/strategy', icon: Target });
    }
    if (can?.governance?.performance?.view || can?.governance?.view) {
        strategy.push({ title: 'Performance', href: '/governance/performance', icon: ClipboardCheck });
    }
    if (can?.roadmap?.view) {
        strategy.push({ title: 'Roadmap', href: '/roadmap/dashboard', icon: Map });
    }
    if (strategy.length > 0) groups.push({ label: 'Strategy & Performance', items: strategy });

    // Policies & Evidence — policies, documents, interests register
    const policies: NavItem[] = [];
    if (can?.governance?.policies?.view || can?.governance?.view) {
        policies.push({ title: 'Policies', href: '/governance/policies', icon: FileText });
    }
    if (can?.governance?.documents?.view || can?.governance?.view) {
        policies.push({ title: 'Documents', href: '/governance/documents', icon: FileText });
    }
    if (can?.governance?.interests?.view || can?.governance?.view) {
        policies.push({ title: 'Interests Register', href: '/governance/interests', icon: ClipboardList });
    }
    if (policies.length > 0) groups.push({ label: 'Policies & Evidence', items: policies });

    // Admin & Settings — board members, audit log, settings
    const admin: NavItem[] = [];
    if (can?.governance?.meetings?.manage) {
        admin.push({ title: 'Board Members', href: '/governance/admin/board-members', icon: Users });
    }
    if (can?.governance?.audit?.view) {
        admin.push({ title: 'Audit Log', href: '/governance/audit-log', icon: ClipboardCheck });
    }
    if (can?.governance?.settings?.view) {
        admin.push({ title: 'Governance Settings', href: '/governance/settings', icon: Shield });
    }
    if (admin.length > 0) groups.push({ label: 'Admin & Settings', items: admin });

    return groups;
}

function buildFinanceSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const overview: NavItem[] = [];
    overview.push({
        title: 'Dashboard',
        href: '/finance/dashboard',
        icon: LayoutDashboard,
    });

    if (can?.finance?.dashboard) {
        // Obligation calendar — invoice/bill due dates, payment runs, GST deadlines.
        overview.push({
            title: 'Calendar',
            href: '/finance/calendar',
            icon: CalendarDays,
        });
    }

    if (
        can?.finance?.ledger?.view ||
        can?.finance?.ledger?.manage ||
        can?.finance?.admin ||
        can?.finance?.assets?.view
    ) {
        // General Ledger hub — chart of accounts, journals, cost centres, fiscal
        // periods, currencies, FX revaluations and fixed assets are now tabs here.
        overview.push({
            title: 'General Ledger',
            href: '/finance/ledger',
            icon: BookOpen,
        });
    }

    const ap: NavItem[] = [];
    if (can?.finance?.ap?.view) {
        // Purchases & Payables hub — bills, purchase orders, vendors, credit notes
        // and payment runs are now tabs here.
        ap.push({ title: 'Payables', href: '/finance/payables', icon: Receipt });
    }

    const ar: NavItem[] = [];
    if (can?.finance?.ar?.view) {
        // Sales & Receivables hub — invoices, quotes, recurring charges, billing,
        // aged AR, statements, price books and allocations are now tabs here.
        ar.push({
            title: 'Receivables',
            href: '/finance/receivables',
            icon: DollarSign,
        });
    }

    const banking: NavItem[] = [];
    if (
        can?.finance?.bank?.view ||
        can?.finance?.bank?.manage ||
        can?.finance?.pettyCash?.view
    ) {
        // Banking & Cash hub — accounts, transactions, reconciliation, matching,
        // feeds, EFTPOS, petty cash and match rules are now tabs here.
        banking.push({
            title: 'Banking',
            href: '/finance/banking',
            icon: Landmark,
        });
    }

    const other: NavItem[] = [];
    // Tax & Compliance hub — GST returns, IRD filings, audit exports and
    // consolidation are now tabs here.
    if (
        can?.finance?.tax?.view ||
        can?.finance?.tax?.manage ||
        can?.finance?.reports?.view ||
        can?.finance?.admin
    ) {
        other.push({
            title: 'Tax & Compliance',
            href: '/finance/tax',
            icon: Receipt,
        });
    }
    // Petty Cash is now a tab in the Banking & Cash hub (see `banking` above).
    if (can?.finance?.reports?.view)
        other.push({
            title: 'Donor Funds',
            href: '/finance/donor-funds',
            icon: Heart,
        });

    const reports: NavItem[] = [];
    if (can?.finance?.reports?.view) {
        // Reports & Planning hub — P&L, balance sheet, trial balance, cash flow,
        // aged AR/AP, funding summary, budget vs actuals and cash-flow forecast
        // are now tabs here.
        reports.push({
            title: 'Reports',
            href: '/finance/reports',
            icon: BarChart3,
        });
    }

    if (can?.finance?.admin) {
        // Settings hub — accounting integrations (Xero/MYOB) and funding streams
        // are now tabs here. (Fiscal periods, cost centres, currencies live in the
        // Ledger hub; match rules in the Banking hub — not duplicated here.)
        other.push({
            title: 'Settings',
            href: '/finance/settings',
            icon: Settings,
        });
    }

    const groups: SubPanelGroup[] = [{ label: 'Finance', items: overview }];
    if (ap.length) groups.push({ label: 'Accounts Payable', items: ap });
    if (ar.length) groups.push({ label: 'Accounts Receivable', items: ar });
    if (banking.length) groups.push({ label: 'Banking', items: banking });
    if (other.length) groups.push({ label: 'Other', items: other });
    if (reports.length) groups.push({ label: 'Reports', items: reports });
    return groups;
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
            title: 'Board Reports',
            href: '/governance/reports/board-monthly',
            icon: FileText,
        });
    if (can?.governance?.compliance?.view || can?.governance?.view)
        governance.push({
            title: 'Compliance',
            href: '/governance/reports/compliance-status',
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
    const live: NavItem[] = [];
    if (can?.controlRoom?.viewAny) {
        live.push({
            title: 'Dashboard',
            href: '/control-room',
            icon: LayoutDashboard,
        });
        live.push({
            title: 'My Day',
            href: '/control-room/my-tasks',
            icon: CheckCircle2,
        });
        live.push({ title: 'Live Map', href: '/control-room/map', icon: Map });
        live.push({
            title: 'Active Shifts',
            href: '/control-room/shifts',
            icon: Clock,
        });
    }
    if (live.length) groups.push({ label: 'Live Monitoring', items: live });

    const alerts: NavItem[] = [];
    if (can?.controlRoom?.alertsView || can?.controlRoom?.alertsManage)
        alerts.push({
            title: 'All Alerts',
            href: '/control-room/alerts',
            icon: AlertTriangle,
        });
    if (can?.controlRoom?.alertsEscalate || can?.controlRoom?.alertsManage)
        alerts.push({
            title: 'Escalation Queue',
            href: '/control-room/escalations',
            icon: ArrowUpCircle,
        });
    if (can?.incidents?.viewAny || can?.incidents?.viewAssigned)
        alerts.push({
            title: 'Incident Tracker',
            href: '/control-room/incidents',
            icon: AlertCircle,
        });
    if (alerts.length)
        groups.push({ label: 'Alerts & Escalations', items: alerts });

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
            title: 'Devices',
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
    const items: NavItem[] = [];

    if (can?.securityDevices?.viewAny)
        items.push({
            title: 'Dashboard',
            href: '/security-devices',
            icon: LayoutDashboard,
        });
    if (can?.securityDevices?.eventsView)
        items.push({
            title: 'Alarms',
            href: '/security-devices/alarms',
            icon: Siren,
        });
    if (can?.securityDevices?.devicesView)
        items.push({
            title: 'CCTV',
            href: '/security-devices/cctv',
            icon: Cctv,
        });
    if (can?.securityDevices?.devicesView)
        items.push({
            title: 'Tracking Devices',
            href: '/security-devices/tracking-devices',
            icon: Smartphone,
        });
    if (can?.securityDevices?.devicesView)
        items.push({
            title: 'Smart IoT & Healthcare',
            href: '/security-devices/smart-iot-healthcare',
            icon: HeartPulse,
        });
    if (can?.securityDevices?.devicesView)
        items.push({
            title: 'Access Control',
            href: '/security-devices/access-control',
            icon: Key,
        });
    if (can?.securityDevices?.devicesView)
        items.push({
            title: 'IT Infrastructure',
            href: '/security-devices/it-infrastructure',
            icon: Server,
        });
    if (can?.securityDevices?.devicesView)
        items.push({
            title: 'Facilities',
            href: '/security-devices/facilities',
            icon: Building2,
        });
    if (can?.securityDevices?.groupsManage)
        items.push({
            title: 'Device Groups',
            href: '/security-devices/device-groups',
            icon: GitBranch,
        });
    if (can?.securityDevices?.eventsView)
        items.push({
            title: 'Alerts & Events',
            href: '/security-devices/alerts-events',
            icon: Bell,
        });
    if (
        can?.securityDevices?.maintenanceView ||
        can?.securityDevices?.maintenanceManage
    )
        items.push({
            title: 'Maintenance & Health',
            href: '/security-devices/maintenance-health',
            icon: Wrench,
        });
    if (
        can?.securityDevices?.integrationsView ||
        can?.securityDevices?.integrationsManage
    )
        items.push({
            title: 'APIs & Integrations',
            href: '/security-devices/integrations',
            icon: Link2,
        });
    if (can?.securityDevices?.reportsView)
        items.push({
            title: 'Reports',
            href: '/security-devices/reports',
            icon: FileText,
        });

    return items.length ? [{ label: 'Security & Devices', items }] : [];
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
                'fixed top-0 bottom-0 z-50 w-64 overflow-y-auto border-r border-sidebar-border bg-sidebar text-sidebar-foreground shadow-lg transition-[left] duration-200 ease-in-out',
                isSidebarCollapsed ? 'left-16' : 'left-64',
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

            {/* Panel groups */}
            <div className="py-2">
                {groups.filter(Boolean).map((group) => (
                    <div key={group.label} className="mb-1">
                        <div className="px-4 py-1.5 text-[11px] font-medium tracking-wider text-sidebar-foreground/40 uppercase">
                            {group.label}
                        </div>
                        {(group.items ?? []).map((item) => {
                            const active = isSubItemActive(
                                currentUrl,
                                item.href,
                            );
                            return (
                                <Link
                                    key={resolveUrl(item.href)}
                                    href={item.href}
                                    aria-current={active ? 'page' : undefined}
                                    prefetch
                                    preserveScroll
                                    className={cn(
                                        'flex items-center gap-3 px-4 py-2 text-sm transition-colors',
                                        active
                                            ? 'bg-sidebar-primary/10 font-medium text-foreground dark:text-foreground'
                                            : 'text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground',
                                    )}
                                >
                                    {item.icon && (
                                        <SidebarItemIcon icon={item.icon} />
                                    )}
                                    <span className="truncate">
                                        {item.title}
                                    </span>
                                    {item.badge != null && item.badge > 0 && (
                                        <span className="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-status-critical px-1 text-[10px] leading-none font-bold text-white">
                                            {item.badge > 9 ? '9+' : item.badge}
                                        </span>
                                    )}
                                    {active && (
                                        <ChevronRight
                                            className={cn(
                                                'h-3 w-3 text-sidebar-foreground/40',
                                                item.badge != null &&
                                                    item.badge > 0
                                                    ? 'ml-0'
                                                    : 'ml-auto',
                                            )}
                                        />
                                    )}
                                </Link>
                            );
                        })}
                    </div>
                ))}
            </div>
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
    const { auth, branding, name: appName, labels } = page.props;
    const role = auth.user?.role ?? null;
    const can = auth?.can;
    const portalClients = auth?.portalClients ?? null;
    const unreadMessageCount = (auth as any)?.unreadMessageCount ?? 0;
    const currentUrl = page.url;
    const getInitials = useInitials();
    const displayName: string =
        (branding as any)?.name ?? appName ?? 'Oblivion Findings';
    const logoUrl: string | null = (branding as any)?.logoUrl ?? null;
    const { collapsed: fallbackCollapsed, setExpanded: setFallbackExpanded } =
        useAppSidebarState((page.props as any)?.sidebarOpen ?? true);
    const isCollapsed = collapsedProp ?? fallbackCollapsed;

    const [openPanelId, setOpenPanelId] = useState<string | null>(null);

    const iconNavItems = useMemo(
        () =>
            buildIconNavItems({ role, can, portalClients, unreadMessageCount }),
        [role, can, portalClients, unreadMessageCount],
    );

    const subPanelMap = useMemo(
        () => ({
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

    const toggleCollapsed = useCallback(() => {
        const nextCollapsed = !isCollapsed;

        if (onCollapsedChange) {
            onCollapsedChange(nextCollapsed);
        } else {
            setFallbackExpanded(!nextCollapsed);
        }
    }, [isCollapsed, onCollapsedChange, setFallbackExpanded]);

    // Close sub-panel on navigation
    useEffect(() => {
        setOpenPanelId(null);
    }, [currentUrl]);

    return (
        <TooltipProvider delayDuration={0}>
            <div className="relative hidden md:flex">
                <nav
                    id="app-sidebar-nav"
                    aria-label="Primary navigation"
                    data-state={isCollapsed ? 'collapsed' : 'expanded'}
                    className={cn(
                        'fixed top-0 left-0 z-40 flex h-svh flex-col overflow-x-hidden overflow-y-hidden border-r border-sidebar-border bg-sidebar py-3 transition-[width] duration-200 ease-in-out',
                        isCollapsed ? 'w-16 items-center' : 'w-64',
                    )}
                >
                    <div
                        className={cn(
                            'flex w-full items-center gap-2 px-3 pb-3',
                            isCollapsed ? 'flex-col' : 'justify-between',
                        )}
                    >
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Link
                                    href={dashboard()}
                                    aria-label={displayName}
                                    prefetch
                                    className={cn(
                                        'flex min-w-0 items-center rounded-xl text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none',
                                        isCollapsed
                                            ? 'h-10 w-10 justify-center'
                                            : 'h-11 flex-1 gap-3 px-2',
                                    )}
                                >
                                    <span className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-sidebar-primary text-sidebar-primary-foreground">
                                        {logoUrl ? (
                                            <img
                                                src={logoUrl}
                                                alt=""
                                                className="h-full w-full object-cover"
                                            />
                                        ) : (
                                            <AppLogoIcon className="size-5 fill-current text-white dark:text-black" />
                                        )}
                                    </span>
                                    {!isCollapsed && (
                                        <span className="min-w-0 truncate text-sm font-semibold text-sidebar-foreground">
                                            {displayName}
                                        </span>
                                    )}
                                </Link>
                            </TooltipTrigger>
                            <TooltipContent side="right" hidden={!isCollapsed}>
                                {displayName}
                            </TooltipContent>
                        </Tooltip>

                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-controls="app-sidebar-nav"
                            aria-expanded={!isCollapsed}
                            aria-label={
                                isCollapsed
                                    ? 'Expand sidebar'
                                    : 'Collapse sidebar'
                            }
                            title={
                                isCollapsed
                                    ? 'Expand sidebar'
                                    : 'Collapse sidebar'
                            }
                            onClick={toggleCollapsed}
                            className="size-9 shrink-0 rounded-xl text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground"
                        >
                            {isCollapsed ? (
                                <PanelLeftOpen className={SIDEBAR_OPCN_CLASS} />
                            ) : (
                                <PanelLeftClose
                                    className={SIDEBAR_OPCN_CLASS}
                                />
                            )}
                        </Button>
                    </div>

                    <div
                        className={cn(
                            'scrollbar-none flex w-full flex-1 flex-col gap-1 overflow-y-auto px-2',
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
                                isCollapsed
                                    ? 'justify-center px-0'
                                    : 'justify-start gap-3 px-3',
                                active
                                    ? SIDEBAR_ITEM_ACTIVE
                                    : SIDEBAR_ITEM_INACTIVE,
                            );

                            if (item.subPanel) {
                                const isPanelOpen = openPanelId === item.id;

                                return (
                                    <div
                                        key={item.id}
                                        className={cn(
                                            'w-full',
                                            item.dividerAfter &&
                                                'mb-1 border-b border-sidebar-border/30 pb-1',
                                        )}
                                    >
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    data-sub-panel-trigger
                                                    aria-label={`${item.label} menu`}
                                                    aria-current={
                                                        active
                                                            ? 'page'
                                                            : undefined
                                                    }
                                                    aria-expanded={isPanelOpen}
                                                    onClick={() =>
                                                        toggleSubPanel(item.id)
                                                    }
                                                    className={cn(
                                                        itemClassName,
                                                        isPanelOpen &&
                                                            SIDEBAR_ITEM_ACTIVE,
                                                    )}
                                                >
                                                    <SidebarItemIcon
                                                        icon={item.icon}
                                                    />
                                                    {!isCollapsed && (
                                                        <>
                                                            <span className="min-w-0 flex-1 truncate text-left">
                                                                {item.label}
                                                            </span>
                                                            <ChevronRight className="size-4 shrink-0 opacity-70" />
                                                        </>
                                                    )}
                                                </Button>
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
                            }

                            return (
                                <div
                                    key={item.id}
                                    className={cn(
                                        'w-full',
                                        item.dividerAfter &&
                                            'mb-1 border-b border-sidebar-border/30 pb-1',
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
                                                className={itemClassName}
                                            >
                                                <SidebarItemIcon
                                                    icon={item.icon}
                                                />
                                                {!isCollapsed && (
                                                    <span className="min-w-0 flex-1 truncate">
                                                        {item.label}
                                                    </span>
                                                )}
                                                {item.badge != null &&
                                                    item.badge > 0 && (
                                                        <span
                                                            data-test={`sidebar-badge-${item.id}`}
                                                            className={cn(
                                                                'flex h-5 min-w-5 items-center justify-center rounded-full bg-status-critical px-1 text-[10px] leading-none font-bold text-white',
                                                                isCollapsed
                                                                    ? 'absolute top-0 right-1'
                                                                    : 'ml-auto',
                                                            )}
                                                        >
                                                            {item.badge > 9
                                                                ? '9+'
                                                                : item.badge}
                                                        </span>
                                                    )}
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
                            'mt-auto flex w-full flex-col gap-1 border-t border-sidebar-border/30 px-2 pt-2',
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
                                    className={cn(
                                        SIDEBAR_ITEM_BASE,
                                        isCollapsed
                                            ? 'justify-center px-0'
                                            : 'justify-start gap-3 px-3',
                                        currentUrl.startsWith('/settings')
                                            ? SIDEBAR_ITEM_ACTIVE
                                            : SIDEBAR_ITEM_INACTIVE,
                                    )}
                                >
                                    <Settings
                                        aria-hidden="true"
                                        className={SIDEBAR_OPCN_CLASS}
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

                        {auth.user && (
                            <DropdownMenu>
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <DropdownMenuTrigger asChild>
                                            <button
                                                type="button"
                                                aria-label={`Open user menu for ${auth.user.name}`}
                                                className={cn(
                                                    SIDEBAR_ITEM_BASE,
                                                    isCollapsed
                                                        ? 'justify-center px-0'
                                                        : 'justify-start gap-3 px-3',
                                                    SIDEBAR_ITEM_INACTIVE,
                                                )}
                                            >
                                                <Avatar className="size-8 shrink-0 overflow-hidden rounded-full">
                                                    <AvatarImage
                                                        src={auth.user.avatar}
                                                        alt={auth.user.name}
                                                    />
                                                    <AvatarFallback className="rounded-full bg-muted text-xs text-black dark:bg-muted dark:text-white">
                                                        {getInitials(
                                                            auth.user.name,
                                                        )}
                                                    </AvatarFallback>
                                                </Avatar>
                                                {!isCollapsed && (
                                                    <span className="min-w-0 flex-1 text-left">
                                                        <span className="block truncate text-sm font-medium text-sidebar-foreground">
                                                            {auth.user.name}
                                                        </span>
                                                        <span className="block truncate text-xs text-sidebar-foreground/80">
                                                            {auth.user.email}
                                                        </span>
                                                    </span>
                                                )}
                                            </button>
                                        </DropdownMenuTrigger>
                                    </TooltipTrigger>
                                    <TooltipContent
                                        side="right"
                                        hidden={!isCollapsed}
                                    >
                                        {auth.user.name}
                                    </TooltipContent>
                                </Tooltip>
                                <DropdownMenuContent
                                    className="min-w-56 rounded-lg"
                                    align={isCollapsed ? 'center' : 'start'}
                                    side="right"
                                >
                                    <UserMenuContent user={auth.user as any} />
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                </nav>

                {openPanelId && (subPanelMap as any)[openPanelId] && (
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

export function AppSidebarMobile({
    open,
    onClose,
}: {
    open: boolean;
    onClose: () => void;
}) {
    const page = usePage<PageProps & Record<string, any>>();
    const { auth, labels } = page.props as any;
    const role = auth.user?.role ?? null;
    const can = auth?.can;
    const portalClients = auth?.portalClients ?? null;
    const unreadMessageCount = auth?.unreadMessageCount ?? 0;
    const currentUrl = page.url;

    const iconNavItems = useMemo(
        () =>
            buildIconNavItems({ role, can, portalClients, unreadMessageCount }),
        [role, can, portalClients, unreadMessageCount],
    );

    const mobileSubPanelMap = useMemo(
        () => ({
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

    if (!open) return null;

    return (
        <>
            {/* Backdrop */}
            <div
                className="fixed inset-0 z-40 bg-black/50 md:hidden"
                onClick={onClose}
            />

            {/* Drawer */}
            <div className="fixed inset-y-0 left-0 z-50 w-72 overflow-y-auto bg-sidebar text-sidebar-foreground md:hidden">
                {/* Close button */}
                <div className="flex items-center justify-between border-b border-sidebar-border/50 px-4 py-3">
                    <span className="text-sm font-semibold">Menu</span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Close menu"
                        onClick={onClose}
                        className="h-8 w-8 rounded-md p-1 hover:bg-sidebar-accent"
                    >
                        <X className="h-5 w-5" />
                    </Button>
                </div>

                <div className="py-2">
                    {iconNavItems.map((item) => {
                        if (item.subPanel) {
                            const groups = (mobileSubPanelMap as any)[
                                item.id
                            ] as SubPanelGroup[] | undefined;
                            const active = isIconActive(
                                currentUrl,
                                item,
                                groups,
                            );
                            const isExpanded = expandedId === item.id;
                            return (
                                <div key={item.id}>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        aria-expanded={isExpanded}
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                        aria-label={`${item.label} menu`}
                                        onClick={() =>
                                            setExpandedId(
                                                isExpanded ? null : item.id,
                                            )
                                        }
                                        className={cn(
                                            'h-auto w-full justify-start gap-3 rounded-none px-4 py-2 text-sm font-normal transition-colors',
                                            active
                                                ? 'bg-sidebar-primary/10 font-medium text-foreground dark:text-foreground'
                                                : 'text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground',
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
                                        (groups ?? []).filter(Boolean).map((group) => (
                                            <div
                                                key={group.label}
                                                className="ml-4"
                                            >
                                                <div className="px-4 py-1 text-[11px] font-medium tracking-wider text-sidebar-foreground/40 uppercase">
                                                    {group.label}
                                                </div>
                                                {(group.items ?? []).map((sub) => (
                                                    <Link
                                                        key={resolveUrl(
                                                            sub.href,
                                                        )}
                                                        href={sub.href}
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
                                                            'flex items-center gap-3 px-4 py-2 text-sm transition-colors',
                                                            isSubItemActive(
                                                                currentUrl,
                                                                sub.href,
                                                            )
                                                                ? 'bg-sidebar-primary/10 font-medium text-foreground dark:text-foreground'
                                                                : 'text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground',
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
                                                                    {sub.badge >
                                                                    9
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
                                    aria-current={active ? 'page' : undefined}
                                    prefetch
                                    className={cn(
                                        'flex items-center gap-3 px-4 py-2 text-sm transition-colors',
                                        active
                                            ? 'bg-sidebar-primary/10 font-medium text-foreground dark:text-foreground'
                                            : 'text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground',
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
                </div>
            </div>
        </>
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
            const groups = subPanelMap[icon.id] ?? [];
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
