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
    Trash2,
    TrendingUp,
    Truck,
    UserCheck,
    Users,
    UserSearch,
    Wrench,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import AppLogoIcon from './app-logo-icon';
const dashboard = () => '/dashboard';

const SIDEBAR_ICON_CLASS = 'size-5 shrink-0';
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
            className={cn(SIDEBAR_ICON_CLASS, className)}
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

function isIconActive(
    currentUrl: string,
    item: IconNavItem,
    subPanelGroups?: SubPanelGroup[],
): boolean {
    if (item.href) {
        return matchScore(currentUrl, item.href) > 0;
    }
    // For sub-panel items, check if any child is active
    if (item.subPanel && subPanelGroups) {
        return subPanelGroups.some((group) =>
            group.items.some((sub) => matchScore(currentUrl, sub.href) > 0),
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
            label: 'Calendar',
            href: '/my-calendar',
            dividerAfter: true,
        },
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

    // Operations (Clients, Shifts, Timesheets, Rostering) — any capability in
    // the Operations domain grants the icon. Role-only fallbacks were removed
    // so permissions strictly control visibility; support workers still see
    // the icon via `shifts.viewAssigned` / `timesheets.viewAssigned`.
    const hasOps =
        !!can?.clients?.viewAny ||
        !!can?.clients?.viewAssigned ||
        !!can?.shifts?.viewAny ||
        !!can?.shifts?.viewAssigned ||
        !!can?.timesheets?.viewAny ||
        !!can?.timesheets?.viewAssigned ||
        !!can?.rostering?.viewAny ||
        !!can?.progress_notes?.viewAny ||
        !!can?.care_plans?.viewAny;
    if (hasOps) {
        items.push({
            id: 'operations',
            icon: Users,
            label: 'Operations',
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
    if (can?.governance?.view) {
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
        can?.finance?.ap?.view
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
            title: 'Calendars',
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
    if (can?.vendors?.view)
        items.push({ title: 'Vendors', href: '/vendors', icon: Package });
    if (can?.siteHardware?.view)
        items.push({
            title: 'Site Hardware',
            href: '/site-hardware',
            icon: Settings,
        });
    if (can?.unifi?.manage)
        items.push({ title: 'UniFi', href: '/unifi', icon: Settings });

    const groups: SubPanelGroup[] = [{ label: 'Sites & Locations', items }];

    // Respite (moved from Operations — site-based stays)
    if (can?.respite?.viewAny) {
        const respiteItems: NavItem[] = [
            { title: 'Referrals', href: '/respite', icon: Users },
            {
                title: 'Booking Requests',
                href: '/respite/requests',
                icon: CalendarDays,
            },
            {
                title: 'Approved Bookings',
                href: '/respite/bookings',
                icon: ClipboardCheck,
            },
            {
                title: 'Calendar',
                href: '/respite/calendar',
                icon: CalendarDays,
            },
            { title: 'Stays', href: '/respite/stays', icon: Home },
        ];
        groups.push({ label: 'Respite', items: respiteItems });
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
    if (can?.progress_notes?.viewAny || can?.progress_notes?.create)
        clientMgmt.push({
            title: 'Progress Notes',
            href: '/operations/progress-notes',
            icon: MessageSquareText,
        });
    if (can?.client_funds?.manage)
        clientMgmt.push({
            title: `${clientLabel} Funds`,
            href: '/operations/client-funds',
            icon: DollarSign,
        });
    if (clientMgmt.length > 0)
        groups.push({ label: `${clientLabel} Management`, items: clientMgmt });

    // Scheduling
    // PR 18 — the `/operations/shifts` table is the scheduler view of the
    // roster. Frontline workers see their own shifts on `/my-day`, so hide
    // this entry for them. Managers with `shifts.viewAny` still see it.
    const scheduling: NavItem[] = [];
    if (can?.shifts?.viewAny)
        scheduling.push({
            title: 'Shifts',
            href: '/operations/shifts',
            icon: CalendarDays,
        });
    if (can?.job_board?.viewAny || can?.job_board?.claim)
        scheduling.push({
            title: 'Job Board',
            href: '/operations/job-board',
            icon: ClipboardList,
        });
    if (can?.rostering?.viewAny)
        scheduling.push({
            title: 'Rostering',
            href: '/operations/rostering',
            icon: CalendarDays,
        });
    if (can?.rostering?.viewAny)
        scheduling.push({
            title: 'Availability',
            href: '/operations/availability',
            icon: Clock,
        });
    if (scheduling.length > 0)
        groups.push({ label: 'Scheduling', items: scheduling });

    // Time & Billing
    const timeBilling: NavItem[] = [];
    if (can?.timesheets?.viewAny || can?.timesheets?.viewAssigned)
        timeBilling.push({
            title: 'Timesheets',
            href: '/operations/timesheets',
            icon: Clock,
        });
    if (can?.billing?.viewAny)
        timeBilling.push({
            title: 'Billing',
            href: '/operations/billing',
            icon: DollarSign,
        });
    if (can?.invoices?.viewAny)
        timeBilling.push({
            title: 'Invoices',
            href: '/operations/invoices',
            icon: Receipt,
        });
    if (can?.funding?.viewAny)
        timeBilling.push({
            title: 'Funding',
            href: '/operations/funding',
            icon: PieChart,
        });
    if (can?.price_books?.viewAny)
        timeBilling.push({
            title: 'Price Books',
            href: '/operations/price-books',
            icon: BookOpen,
        });
    if (can?.quotes?.viewAny)
        timeBilling.push({
            title: 'Quotes',
            href: '/operations/quotes',
            icon: FileText,
        });
    if (can?.recurring_charges?.viewAny)
        timeBilling.push({
            title: 'Recurring Charges',
            href: '/operations/recurring-charges',
            icon: Receipt,
        });
    if (can?.mileage?.viewAny || can?.mileage?.viewOwn)
        timeBilling.push({
            title: 'Mileage',
            href: '/operations/mileage',
            icon: Route,
        });
    if (can?.payroll?.export || can?.payroll_exports?.viewAny)
        timeBilling.push({
            title: 'Payroll Export',
            href: '/operations/payroll-export',
            icon: Download,
        });
    if (timeBilling.length > 0)
        groups.push({ label: 'Time & Billing', items: timeBilling });

    // Communications
    const comms: NavItem[] = [];
    if (can?.messages?.viewAny || can?.shifts?.viewAny)
        comms.push({
            title: 'Messages',
            href: '/operations/messages',
            icon: MessageSquareText,
        });
    if (can?.handovers?.viewAny || can?.shifts?.viewAny)
        comms.push({
            title: 'Handovers',
            href: '/operations/handovers',
            icon: GitBranch,
        });
    if (can?.shifts?.viewAny)
        comms.push({
            title: 'Shift Notes',
            href: '/operations/shift-notes',
            icon: BookOpen,
        });
    if (comms.length > 0)
        groups.push({ label: 'Communications', items: comms });

    // Tools
    const tools: NavItem[] = [];
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
            { title: 'Daily Overview', href: '/emar/daily', icon: Activity },
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

    // H&S Overview — any hazards/compliance permission grants the dashboard
    const overview: NavItem[] = [];
    if (can?.hazards?.view || can?.compliance?.view)
        overview.push({
            title: 'H&S Dashboard',
            href: '/health-safety',
            icon: ShieldCheck,
        });
    if (can?.hazards?.view || can?.compliance?.view || can?.reports?.viewAny)
        overview.push({
            title: 'Analytics',
            href: '/health-safety/analytics',
            icon: BarChart3,
        });
    if (overview.length > 0)
        groups.push({ label: 'Health & Safety', items: overview });

    // Incident Management
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
            href: '/incidents?type=near_miss',
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
        groups.push({ label: 'Incidents & Safeguarding', items: incidents });

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
            title: 'PPE Management',
            href: '/health-safety/ppe',
            icon: HardHat,
        });
    if (can?.hazards?.view || can?.compliance?.view || can?.clinical?.dashboard)
        registers.push({
            title: 'First Aid Register',
            href: '/health-safety/first-aid',
            icon: HeartPulse,
        });
    if (can?.hazards?.view || can?.safeguarding?.viewAny)
        registers.push({
            title: 'Restraint Register',
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
            title: 'Privacy & GDPR',
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

    // Safety
    const safety: SubPanelGroup = { label: 'Safety', items: [] };
    safety.items.push({
        title: 'Incidents',
        href: '/fleet-assets/incidents',
        icon: AlertOctagon,
    });
    safety.items.push({
        title: 'Wandering Alerts',
        href: '/fleet-assets/wandering-alerts',
        icon: ShieldAlert,
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
    const items: NavItem[] = [
        { title: 'Dashboard', href: '/governance/dashboard', icon: Landmark },
        { title: 'Meetings', href: '/governance/meetings', icon: CalendarDays },
    ];
    if (can?.governance?.meetings?.manage)
        items.push({
            title: 'Admin',
            href: '/governance/admin/board-members',
            icon: Users,
        });
    items.push({ title: 'Risks', href: '/governance/risks', icon: Target });
    items.push({
        title: 'Resolutions',
        href: '/governance/resolutions',
        icon: ClipboardCheck,
    });
    items.push({
        title: 'Compliance',
        href: '/governance/compliance',
        icon: Shield,
    });
    items.push({
        title: 'Strategy',
        href: '/governance/strategy',
        icon: Target,
    });
    items.push({
        title: 'Performance',
        href: '/governance/performance',
        icon: ClipboardCheck,
    });
    items.push({
        title: 'Budgets',
        href: '/governance/budgets',
        icon: DollarSign,
    });
    items.push({
        title: 'Board Packs',
        href: '/governance/packs',
        icon: FileText,
    });
    items.push({
        title: 'Action Items',
        href: '/governance/actions',
        icon: ClipboardList,
    });
    return [{ label: 'Governance', items }];
}

function buildFinanceSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const overview: NavItem[] = [];
    overview.push({
        title: 'Dashboard',
        href: '/finance/dashboard',
        icon: LayoutDashboard,
    });

    if (can?.finance?.ledger?.view) {
        overview.push({
            title: 'Chart of Accounts',
            href: '/finance/accounts',
            icon: ClipboardList,
        });
        overview.push({
            title: 'Journals',
            href: '/finance/journals',
            icon: BookOpen,
        });
    }

    const ap: NavItem[] = [];
    if (can?.finance?.ap?.view) {
        ap.push({ title: 'Vendors', href: '/finance/vendors', icon: Users });
        ap.push({
            title: 'Purchase Orders',
            href: '/finance/purchase-orders',
            icon: ClipboardCheck,
        });
        ap.push({ title: 'Bills', href: '/finance/bills', icon: Receipt });
        ap.push({
            title: 'Credit Notes',
            href: '/finance/credit-notes',
            icon: FileText,
        });
        ap.push({
            title: 'Payment Runs',
            href: '/finance/payment-runs',
            icon: ArrowLeftRight,
        });
    }

    const ar: NavItem[] = [];
    if (can?.finance?.ar?.view) {
        ar.push({
            title: 'Receivables',
            href: '/finance/receivables',
            icon: DollarSign,
        });
        ar.push({
            title: 'Invoices',
            href: '/finance/invoices',
            icon: FileText,
        });
    }

    const banking: NavItem[] = [];
    if (can?.finance?.bank?.view) {
        banking.push({
            title: 'Bank Accounts',
            href: '/finance/bank-accounts',
            icon: Landmark,
        });
        banking.push({
            title: 'Reconciliation',
            href: '/finance/bank-reconciliation',
            icon: CheckCircle2,
        });
        banking.push({
            title: 'Payment Matching',
            href: '/finance/payment-matching',
            icon: ArrowLeftRight,
        });
        banking.push({
            title: 'Bank Feeds',
            href: '/finance/bank-feeds',
            icon: Radio,
        });
        banking.push({
            title: 'EFTPOS',
            href: '/finance/eftpos/terminals',
            icon: CreditCard,
        });
    }

    const other: NavItem[] = [];
    if (can?.finance?.tax?.view)
        other.push({
            title: 'GST Returns',
            href: '/finance/gst-returns',
            icon: Receipt,
        });
    if (can?.finance?.tax?.manage)
        other.push({
            title: 'IRD E-Filing',
            href: '/finance/ird-filings',
            icon: Send,
        });
    if (can?.finance?.assets?.view)
        other.push({
            title: 'Fixed Assets',
            href: '/finance/fixed-assets',
            icon: Package,
        });
    if (can?.finance?.pettyCash?.view)
        other.push({
            title: 'Petty Cash',
            href: '/finance/petty-cash',
            icon: Banknote,
        });
    if (can?.finance?.reports?.view)
        other.push({
            title: 'Donor Funds',
            href: '/finance/donor-funds',
            icon: Heart,
        });

    const reports: NavItem[] = [];
    if (can?.finance?.reports?.view) {
        reports.push({
            title: 'Trial Balance',
            href: '/finance/reports/trial-balance',
            icon: BarChart3,
        });
        reports.push({
            title: 'Profit & Loss',
            href: '/finance/reports/profit-loss',
            icon: BarChart3,
        });
        reports.push({
            title: 'Balance Sheet',
            href: '/finance/reports/balance-sheet',
            icon: BarChart3,
        });
        reports.push({
            title: 'Budget vs Actuals',
            href: '/finance/reports/budget-vs-actuals',
            icon: Target,
        });
        reports.push({
            title: 'Cash Flow Forecast',
            href: '/finance/cash-flow-forecast',
            icon: TrendingUp,
        });
        reports.push({
            title: 'FX Revaluations',
            href: '/finance/fx-revaluations',
            icon: ArrowLeftRight,
        });
        reports.push({
            title: 'Audit Exports',
            href: '/finance/audit-exports',
            icon: Download,
        });
    }

    if (can?.finance?.admin) {
        other.push({
            title: 'Fiscal Periods',
            href: '/finance/fiscal-periods',
            icon: CalendarDays,
        });
        other.push({
            title: 'Cost Centres',
            href: '/finance/cost-centres',
            icon: Building2,
        });
        other.push({
            title: 'Funding Streams',
            href: '/finance/funding-streams',
            icon: GitBranch,
        });
        other.push({
            title: 'Currencies',
            href: '/finance/currencies',
            icon: Coins,
        });
        other.push({
            title: 'Consolidation',
            href: '/finance/consolidation',
            icon: Building2,
        });
        other.push({
            title: 'Integrations',
            href: '/finance/integrations',
            icon: Link2,
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
    if (can?.calendar?.viewAny)
        items.push({
            title: 'Calendar',
            href: '/calendar',
            icon: CalendarDays,
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
            href: '/integrations',
            icon: Settings,
        });
    if (can?.settings?.manageAccess)
        items.push({ title: 'Settings', href: '/settings', icon: Settings });
    if (can?.settings?.rbacManage)
        items.push({
            title: 'Roles & Permissions',
            href: '/settings/roles',
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
    if (can?.reports?.viewAny || can?.shifts?.viewAny)
        ops.push({
            title: 'Shift Reports',
            href: '/reports/shifts',
            icon: Clock,
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
    const myHr: SubPanelGroup = {
        label: 'My HR',
        items: [
            { title: 'My HR', href: '/hr/my', icon: Home },
            {
                title: 'My Documents',
                href: '/hr/my/documents',
                icon: FolderOpen,
            },
            { title: 'My Training', href: '/hr/my/training', icon: Target },
            { title: 'My Payslips', href: '/hr/my/payslips', icon: FileText },
        ],
    };
    groups.push(myHr);

    // People
    const people: SubPanelGroup = {
        label: 'People',
        items: [],
    };
    if (can?.hr?.employees?.viewAny || can?.hr?.employees?.viewOwn) {
        people.items.push({
            title: 'Directory',
            href: '/hr/directory',
            icon: Users,
        });
    }
    if (can?.hr?.employees?.viewAny) {
        people.items.push({ title: 'People', href: '/hr/people', icon: Users });
        people.items.push({
            title: 'Import/Export',
            href: '/hr/import-export',
            icon: FileText,
        });
    }
    if (can?.hr?.positions?.view) {
        people.items.push({
            title: 'Positions',
            href: '/hr/positions',
            icon: Briefcase,
        });
    }
    const hasAnyHr =
        can?.hr?.recruitment?.view ||
        can?.hr?.employees?.viewAny ||
        can?.hr?.compliance?.view ||
        can?.hr?.leave?.viewAny ||
        can?.hr?.performance?.view ||
        can?.hr?.reports?.view ||
        can?.hr?.policies?.view ||
        can?.hr?.positions?.view ||
        can?.hr?.time?.view ||
        can?.hr?.compensation?.view;
    if (hasAnyHr) {
        people.items.push({
            title: 'Org Chart',
            href: '/hr/orgchart',
            icon: GitBranch,
        });
    }
    if (can?.hr?.recruitment?.view) {
        people.items.push({
            title: 'Recruitment',
            href: '/hr/recruitment',
            icon: Users,
        });
        people.items.push({
            title: 'Job Postings',
            href: '/hr/job-postings',
            icon: Briefcase,
        });
    }
    groups.push(people);

    // Workforce
    const workforce: SubPanelGroup = { label: 'Workforce', items: [] };
    if (can?.hr?.leave?.viewAny) {
        workforce.items.push({
            title: 'Leave & Rosters',
            href: '/hr/leave',
            icon: CalendarDays,
        });
        workforce.items.push({
            title: 'Leave Reports',
            href: '/hr/leave/reports',
            icon: FileText,
        });
    }
    if (can?.hr?.time?.view) {
        workforce.items.push({
            title: 'Timekeeping',
            href: '/hr/time',
            icon: Clock,
        });
    }
    if (can?.hr?.compensation?.view) {
        workforce.items.push({
            title: 'Compensation',
            href: '/hr/compensation/bands',
            icon: DollarSign,
        });
    }
    if (can?.hr?.benefits?.view) {
        workforce.items.push({
            title: 'Benefits',
            href: '/hr/benefits',
            icon: Shield,
        });
    }
    if (can?.hr?.expenses?.view) {
        workforce.items.push({
            title: 'Expenses',
            href: '/hr/expenses',
            icon: DollarSign,
        });
    }
    if (can?.hr?.leave?.viewAny || can?.hr?.leave?.viewOwn) {
        workforce.items.push({
            title: 'Time Off Calendar',
            href: '/hr/calendar/time-off',
            icon: CalendarDays,
        });
    }
    if (workforce.items.length > 0) groups.push(workforce);

    // Performance
    const performance: SubPanelGroup = { label: 'Performance', items: [] };
    if (can?.hr?.performance?.view) {
        performance.items.push({
            title: 'Performance',
            href: '/hr/performance',
            icon: ClipboardCheck,
        });
        performance.items.push({
            title: '360 Feedback',
            href: '/hr/feedback',
            icon: Users,
        });
        performance.items.push({
            title: 'PIPs',
            href: '/hr/performance/pips',
            icon: ClipboardList,
        });
        performance.items.push({
            title: 'Competencies',
            href: '/hr/performance/competencies',
            icon: Target,
        });
        performance.items.push({
            title: 'Succession',
            href: '/hr/succession',
            icon: Users,
        });
    }
    if (can?.hr?.goals?.view) {
        performance.items.push({
            title: 'Goals',
            href: '/hr/goals',
            icon: Target,
        });
    }
    if (performance.items.length > 0) groups.push(performance);

    // Engagement
    const engagement: SubPanelGroup = { label: 'Engagement', items: [] };
    if (can?.hr?.announcements?.view || can?.hr?.employees?.viewAny) {
        engagement.items.push({
            title: 'Community Feed',
            href: '/hr/feed',
            icon: MessageSquareText,
        });
    }
    if (can?.hr?.surveys?.view) {
        engagement.items.push({
            title: 'Surveys',
            href: '/hr/surveys',
            icon: ClipboardList,
        });
    }
    if (can?.hr?.announcements?.view) {
        engagement.items.push({
            title: 'Announcements',
            href: '/hr/announcements',
            icon: MessageSquareText,
        });
    }
    if (can?.hr?.analytics?.view) {
        engagement.items.push({
            title: 'Wellbeing',
            href: '/hr/wellbeing',
            icon: Target,
        });
    }
    if (engagement.items.length > 0) groups.push(engagement);

    // Admin
    const admin: SubPanelGroup = { label: 'Admin', items: [] };
    if (can?.hr?.compliance?.view) {
        admin.items.push({
            title: 'Compliance',
            href: '/hr/compliance',
            icon: Shield,
        });
        admin.items.push({
            title: 'Compliance Calendar',
            href: '/hr/compliance/calendar',
            icon: CalendarDays,
        });
    }
    if (can?.hr?.settings?.manage || can?.hr?.employees?.manage) {
        admin.items.push({
            title: 'Departments',
            href: '/hr/departments',
            icon: Briefcase,
        });
    }
    if (can?.hr?.training?.view) {
        admin.items.push({
            title: 'Course Catalog',
            href: '/hr/training/catalog',
            icon: BookOpen,
        });
        admin.items.push({
            title: 'Training Dashboard',
            href: '/hr/compliance/training',
            icon: GraduationCap,
        });
    }
    if (can?.hr?.assets?.view) {
        admin.items.push({
            title: 'Assets',
            href: '/hr/assets',
            icon: Package,
        });
    }
    if (can?.hr?.skills?.view) {
        admin.items.push({ title: 'Skills', href: '/hr/skills', icon: Target });
    }
    if (can?.hr?.analytics?.view) {
        admin.items.push({
            title: 'Analytics',
            href: '/hr/analytics',
            icon: LayoutGrid,
        });
        admin.items.push({
            title: 'Headcount',
            href: '/hr/headcount',
            icon: Users,
        });
    }
    if (can?.hr?.calendar?.view) {
        admin.items.push({
            title: 'Calendar',
            href: '/hr/calendar',
            icon: CalendarDays,
        });
    }
    if (can?.hr?.vetting?.view) {
        admin.items.push({
            title: 'Vetting',
            href: '/hr/compliance/vetting',
            icon: Shield,
        });
    }
    if (can?.hr?.driver?.view) {
        admin.items.push({
            title: 'Drivers',
            href: '/hr/compliance/drivers',
            icon: Users,
        });
    }
    if (can?.hr?.onboarding?.view) {
        admin.items.push({
            title: 'Onboarding',
            href: '/hr/onboarding',
            icon: ClipboardCheck,
        });
        admin.items.push({
            title: 'Onboarding Emails',
            href: '/hr/onboarding/emails',
            icon: MessageSquareText,
        });
    }
    if (can?.hr?.documents?.view) {
        admin.items.push({
            title: 'Documents',
            href: '/hr/documents',
            icon: FileText,
        });
        admin.items.push({
            title: 'Expiring Docs',
            href: '/hr/documents/expiring',
            icon: ShieldAlert,
        });
    }
    if (can?.hr?.approvals?.view || can?.hr?.approvals?.manage) {
        admin.items.push({
            title: 'Approvals',
            href: '/hr/approvals/pending',
            icon: ClipboardCheck,
        });
    }
    if (can?.hr?.documents?.view || can?.hr?.documents?.manage) {
        admin.items.push({
            title: 'Signatures',
            href: '/hr/signatures/pending',
            icon: FileText,
        });
    }
    if (can?.hr?.cases?.view) {
        admin.items.push({
            title: 'HR Cases',
            href: '/hr/cases',
            icon: Shield,
        });
    }
    if (can?.hr?.payroll?.view) {
        admin.items.push({
            title: 'Payroll',
            href: '/hr/payroll',
            icon: DollarSign,
        });
        admin.items.push({
            title: 'Payslips',
            href: '/hr/payroll/payslips',
            icon: FileText,
        });
    }
    if (can?.hr?.policies?.view) {
        admin.items.push({
            title: 'Policies',
            href: '/hr/policies',
            icon: FileText,
        });
    }
    if (can?.hr?.cases?.view || can?.hr?.employees?.manage) {
        admin.items.push({
            title: 'Exit Interviews',
            href: '/hr/exit-interviews',
            icon: Users,
        });
    }
    if (can?.hr?.reports?.view) {
        admin.items.push({
            title: 'Reports',
            href: '/hr/reports',
            icon: FileText,
        });
        admin.items.push({
            title: 'Report Builder',
            href: '/hr/reports/builder',
            icon: LayoutGrid,
        });
    }
    if (can?.hr?.settings?.manage) {
        admin.items.push({
            title: 'Settings',
            href: '/hr/settings/webhooks',
            icon: Settings,
        });
        admin.items.push({
            title: 'Custom Fields',
            href: '/hr/settings/custom-fields',
            icon: Settings,
        });
        admin.items.push({
            title: 'Audit Log',
            href: '/hr/settings/audit-log',
            icon: FileText,
        });
    }
    if (admin.items.length > 0) groups.push(admin);

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
                {groups.map((group) => (
                    <div key={group.label} className="mb-1">
                        <div className="px-4 py-1.5 text-[11px] font-medium tracking-wider text-sidebar-foreground/40 uppercase">
                            {group.label}
                        </div>
                        {group.items.map((item) => {
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
                                    {active && (
                                        <ChevronRight className="ml-auto h-3 w-3 text-sidebar-foreground/40" />
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
                                <PanelLeftOpen className={SIDEBAR_ICON_CLASS} />
                            ) : (
                                <PanelLeftClose
                                    className={SIDEBAR_ICON_CLASS}
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
                                        className={SIDEBAR_ICON_CLASS}
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
                                        groups?.map((group) => (
                                            <div
                                                key={group.label}
                                                className="ml-4"
                                            >
                                                <div className="px-4 py-1 text-[11px] font-medium tracking-wider text-sidebar-foreground/40 uppercase">
                                                    {group.label}
                                                </div>
                                                {group.items.map((sub) => (
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
                for (const sub of group.items) {
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
