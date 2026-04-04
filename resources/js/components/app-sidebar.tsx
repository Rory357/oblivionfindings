import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { resolveUrl } from '@/lib/utils';
import { type NavItem } from '@/types';
const dashboard = () => '/dashboard';
import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    AlertOctagon,
    AlertTriangle,
    ArrowLeftRight,
    ArrowUpCircle,
    BarChart3,
    Bell,
    BookOpen,
    Banknote,
    Briefcase,
    Building2,
    CalendarDays,
    Car,
    CheckCircle2,
    ChevronRight,
    ClipboardCheck,
    ClipboardList,
    Clock,
    Coins,
    CreditCard,
    DollarSign,
    Download,
    FileText,
    Fuel,
    GitBranch,
    Heart,
    Home,
    Key,
    Landmark,
    LayoutDashboard,
    Link2,
    LayoutGrid,
    Map,
    MapPin,
    Megaphone,
    MessageSquare,
    MessageSquareText,
    Package,
    PieChart,
    Pill,
    Radio,
    Receipt,
    Route,
    Send,
    Settings,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Siren,
    Smartphone,
    Target,
    Trash2,
    TrendingUp,
    Truck,
    UserCheck,
    Users,
    UserSearch,
    Wrench,
    X,
    HardHat,
    HeartPulse,
    FlaskConical,
    Flame,
    PersonStanding,
    Clipboard,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import AppLogoIcon from './app-logo-icon';

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

    const [currentPath, currentQuery = ''] = current.split('?');
    const [itemPath, itemQuery = ''] = item.split('?');

    const normalizedCurrentPath = normalizePath(currentPath);
    const normalizedItemPath = normalizePath(itemPath);

    if (itemQuery.length > 0) {
        return normalizedCurrentPath === normalizedItemPath && currentQuery === itemQuery
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

function isIconActive(currentUrl: string, item: IconNavItem, subPanelGroups?: SubPanelGroup[]): boolean {
    if (item.href) {
        return matchScore(currentUrl, item.href) > 0;
    }
    // For sub-panel items, check if any child is active
    if (item.subPanel && subPanelGroups) {
        return subPanelGroups.some(group =>
            group.items.some(sub => matchScore(currentUrl, sub.href) > 0)
        );
    }
    return false;
}

function isSubItemActive(currentUrl: string, href: NavItem['href']): boolean {
    return matchScore(currentUrl, href) > 0;
}

// ── Build icon nav items ───────────────────────────────────────────────────

function buildPortalNavItems(portalClients?: PortalClient[] | null): IconNavItem[] {
    const clients = portalClients ?? [];
    if (clients.length === 1) {
        const cid = clients[0].id;
        return [
            { id: 'dashboard', icon: LayoutGrid, label: 'Dashboard', href: `/portal/clients/${cid}/dashboard` },
            { id: 'timeline', icon: Clock, label: 'Timeline', href: `/portal/clients/${cid}/timeline` },
            { id: 'calendar', icon: CalendarDays, label: 'Calendar & Visits', href: `/portal/clients/${cid}/calendar` },
            { id: 'messages', icon: MessageSquareText, label: 'Messages', href: `/portal/clients/${cid}/messages`, dividerAfter: true },
            { id: 'health', icon: Heart, label: 'Health & Care', href: `/portal/clients/${cid}/health` },
            { id: 'documents', icon: FileText, label: 'Documents', href: `/portal/clients/${cid}/documents` },
            { id: 'photos', icon: Clipboard, label: 'Photo Gallery', href: `/portal/clients/${cid}/photos`, dividerAfter: true },
            { id: 'notifications', icon: Bell, label: 'Notifications', href: '/portal/notifications' },
            { id: 'preferences', icon: Settings, label: 'Preferences', href: '/portal/preferences' },
        ];
    }
    // Multi-client: minimal nav, they pick a client from the home page
    return [
        { id: 'home', icon: LayoutGrid, label: 'Home', href: '/portal' },
        { id: 'notifications', icon: Bell, label: 'Notifications', href: '/portal/notifications' },
        { id: 'preferences', icon: Settings, label: 'Preferences', href: '/portal/preferences' },
    ];
}

function buildIconNavItems({
    role,
    can,
    portalClients,
}: {
    role?: string | null;
    can?: any;
    portalClients?: PortalClient[] | null;
}): IconNavItem[] {
    // Portal users (family members / clients) get a dedicated sidebar
    if (role === 'next_of_kin' || role === 'client') {
        return buildPortalNavItems(portalClients);
    }

    const items: IconNavItem[] = [
        { id: 'dashboard', icon: LayoutGrid, label: 'Dashboard', href: '/dashboard' },
        { id: 'today', icon: ClipboardList, label: 'Today', href: '/today' },
        { id: 'my-tasks', icon: CheckCircle2, label: 'My Day', href: '/my-tasks' },
        { id: 'my-calendar', icon: CalendarDays, label: 'Calendar', href: '/my-calendar', dividerAfter: true },
    ];

    // Sites & Locations
    if (can?.sites?.viewAny) {
        items.push({ id: 'sites', icon: Building2, label: 'Sites & Locations', subPanel: true });
    }

    // Operations (Clients, Shifts, Timesheets, Rostering)
    const hasOps = can?.clients?.viewAny || can?.shifts?.viewAny || can?.timesheets?.viewAny || can?.timesheets?.viewAssigned || role === 'support_worker';
    if (hasOps) {
        items.push({ id: 'operations', icon: Users, label: 'Operations', subPanel: true });
    }

    // eMAR (Electronic Medication Administration)
    const hasEmar = can?.medications?.view || can?.medications?.administer?.record;
    if (hasEmar) {
        items.push({ id: 'emar', icon: Pill, label: 'eMAR', subPanel: true });
    }

    // Health & Safety
    const hasSafety = can?.incidents?.viewAny || can?.incidents?.viewAssigned || can?.compliance?.view || can?.hazards?.view || can?.['health-safety']?.view;
    if (hasSafety) {
        items.push({ id: 'safety', icon: ShieldCheck, label: 'Health & Safety', subPanel: true });
    }

    // Fleet & Assets
    const hasFleetAssets = can?.fleet?.viewAny || can?.assets?.viewAny || can?.assets?.viewAssigned;
    if (hasFleetAssets) {
        items.push({ id: 'fleet-assets', icon: Truck, label: 'Fleet & Assets', subPanel: true, dividerAfter: true });
    } else if (items.length > 0) {
        items[items.length - 1].dividerAfter = true;
    }

    // HR - always visible (at minimum My HR)
    items.push({ id: 'hr', icon: Briefcase, label: 'HR', subPanel: true, dividerAfter: true });

    // Governance
    if (can?.governance?.view) {
        items.push({ id: 'governance', icon: Landmark, label: 'Governance', subPanel: true });
    }

    // Finance
    if (can?.finance?.dashboard || can?.finance?.ledger?.view || can?.finance?.ap?.view) {
        items.push({ id: 'finance', icon: Banknote, label: 'Finance', subPanel: true, dividerAfter: true });
    }

    // Reporting
    items.push({ id: 'reporting', icon: PieChart, label: 'Reporting', subPanel: true });

    // Control Room
    items.push({ id: 'control-room', icon: Radio, label: 'Control Room', subPanel: true });

    return items;
}

// ── Build sub-panel groups for each section ──────────────────────────────

function buildSitesSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const items: NavItem[] = [
        { title: 'All Sites', href: '/sites', icon: Building2 },
    ];
    if (can?.sites?.types?.headOfficeView) items.push({ title: 'Head Office', href: '/sites?type=head_office', icon: Building2 });
    if (can?.sites?.types?.houseView) items.push({ title: 'Houses', href: '/sites?type=house', icon: Home });
    if (can?.sites?.types?.facilityView) items.push({ title: 'Facilities', href: '/sites?type=facility', icon: Building2 });
    if (can?.calendar?.viewAny) items.push({ title: 'Calendars', href: '/calendar', icon: CalendarDays });
    if (can?.checklists?.view) items.push({ title: 'Checklists', href: '/checklists', icon: ClipboardCheck });
    if (can?.checklists?.view) items.push({ title: 'Inspections & Maintenance', href: '/sites/inspections', icon: ClipboardList });
    if (can?.hazards?.view) items.push({ title: 'Hazards', href: '/hazards', icon: ShieldAlert });
    if (can?.reports?.sitesView) items.push({ title: 'Reports', href: '/sites/reports', icon: BarChart3 });
    if (can?.vendors?.view) items.push({ title: 'Vendors', href: '/vendors', icon: Package });
    if (can?.siteHardware?.view) items.push({ title: 'Site Hardware', href: '/site-hardware', icon: Settings });
    if (can?.unifi?.manage) items.push({ title: 'UniFi', href: '/unifi', icon: Settings });
    if (can?.assets?.geofences?.manage || can?.fleet?.viewAny) items.push({ title: 'Geofences', href: '/fleet-assets/geofences', icon: MapPin });

    const groups: SubPanelGroup[] = [{ label: 'Sites & Locations', items }];

    // Respite (moved from Operations — site-based stays)
    if (can?.respite?.viewAny) {
        const respiteItems: NavItem[] = [
            { title: 'Referrals', href: '/respite', icon: Users },
            { title: 'Booking Requests', href: '/respite/requests', icon: CalendarDays },
            { title: 'Approved Bookings', href: '/respite/bookings', icon: ClipboardCheck },
            { title: 'Calendar', href: '/respite/calendar', icon: CalendarDays },
            { title: 'Stays', href: '/respite/stays', icon: Home },
        ];
        groups.push({ label: 'Respite', items: respiteItems });
    }

    return groups;
}

function buildOperationsSubPanelGroups({ can, role, labels }: { can?: any; role?: string | null; labels?: any }): SubPanelGroup[] {
    const groups: SubPanelGroup[] = [];

    // Overview
    const overview: NavItem[] = [
        { title: 'Dashboard', href: '/operations', icon: LayoutGrid },
        { title: 'Activity Feed', href: '/operations/activity', icon: Activity },
        { title: 'Timeline', href: '/operations/timeline', icon: Clock },
        { title: 'Summaries', href: '/operations/summaries', icon: FileText },
    ];
    groups.push({ label: 'Overview', items: overview });

    // Client Management
    const clientLabel = labels?.['client.singular'] ?? 'Client';
    const clientLabelPlural = labels?.['client.plural'] ?? 'Clients';
    const clientMgmt: NavItem[] = [];
    if (can?.clients?.viewAny || role === 'support_worker') clientMgmt.push({ title: clientLabelPlural, href: '/operations/clients', icon: Users });
    if (can?.clients?.viewAny) clientMgmt.push({ title: 'Onboarding Pipeline', href: '/operations/onboarding', icon: UserCheck });
    if (can?.care_plans?.viewAny || can?.clients?.viewAny) clientMgmt.push({ title: 'Care Plans', href: '/operations/care-plans', icon: ClipboardCheck });
    if (can?.service_agreements?.viewAny || can?.clients?.viewAny) clientMgmt.push({ title: 'Service Agreements', href: '/operations/service-agreements', icon: FileText });
    if (can?.progress_notes?.viewAny || can?.clients?.viewAny || role === 'support_worker') clientMgmt.push({ title: 'Progress Notes', href: '/operations/progress-notes', icon: MessageSquareText });
    if (can?.clients?.viewAny) clientMgmt.push({ title: `${clientLabel} Funds`, href: '/operations/client-funds', icon: DollarSign });
    if (clientMgmt.length > 0) groups.push({ label: `${clientLabel} Management`, items: clientMgmt });

    // Scheduling
    const scheduling: NavItem[] = [];
    if (can?.shifts?.viewAny || role === 'support_worker') scheduling.push({ title: 'Shifts', href: '/operations/shifts', icon: CalendarDays });
    if (can?.shifts?.viewAny || role === 'support_worker') scheduling.push({ title: 'Job Board', href: '/operations/job-board', icon: ClipboardList });
    if (can?.rostering?.viewAny) scheduling.push({ title: 'Rostering', href: '/operations/rostering', icon: CalendarDays });
    if (can?.rostering?.viewAny) scheduling.push({ title: 'Availability', href: '/operations/availability', icon: Clock });
    if (scheduling.length > 0) groups.push({ label: 'Scheduling', items: scheduling });

    // Time & Billing
    const timeBilling: NavItem[] = [];
    if (can?.timesheets?.viewAny || can?.timesheets?.viewAssigned || role === 'support_worker') timeBilling.push({ title: 'Timesheets', href: '/operations/timesheets', icon: Clock });
    if (can?.billing?.viewAny) timeBilling.push({ title: 'Billing', href: '/operations/billing', icon: DollarSign });
    if (can?.invoices?.viewAny) timeBilling.push({ title: 'Invoices', href: '/operations/invoices', icon: Receipt });
    if (can?.funding?.viewAny) timeBilling.push({ title: 'Funding', href: '/operations/funding', icon: PieChart });
    if (can?.billing?.viewAny) timeBilling.push({ title: 'Price Books', href: '/operations/price-books', icon: BookOpen });
    if (can?.quotes?.viewAny) timeBilling.push({ title: 'Quotes', href: '/operations/quotes', icon: FileText });
    if (can?.billing?.viewAny) timeBilling.push({ title: 'Recurring Charges', href: '/operations/recurring-charges', icon: Receipt });
    if (can?.mileage?.viewAny || can?.mileage?.viewOwn) timeBilling.push({ title: 'Mileage', href: '/operations/mileage', icon: Route });
    if (can?.billing?.viewAny || can?.timesheets?.manageAny) timeBilling.push({ title: 'Payroll Export', href: '/operations/payroll-export', icon: Download });
    if (timeBilling.length > 0) groups.push({ label: 'Time & Billing', items: timeBilling });

    // Communications
    const comms: NavItem[] = [];
    if (can?.messages?.viewAny || can?.shifts?.viewAny) comms.push({ title: 'Messages', href: '/operations/messages', icon: MessageSquareText });
    if (can?.handovers?.viewAny || can?.shifts?.viewAny) comms.push({ title: 'Handovers', href: '/operations/handovers', icon: GitBranch });
    if (can?.shifts?.viewAny) comms.push({ title: 'Shift Notes', href: '/operations/shift-notes', icon: BookOpen });
    if (comms.length > 0) groups.push({ label: 'Communications', items: comms });

    // Tools
    const tools: NavItem[] = [];
    if (can?.custom_forms?.viewAny) tools.push({ title: 'Custom Forms', href: '/operations/forms', icon: ClipboardCheck });
    if (can?.care_note_templates?.viewAny) tools.push({ title: 'Note Templates', href: '/operations/note-templates', icon: FileText });
    if (can?.evv?.viewAny) tools.push({ title: 'EVV', href: '/operations/evv', icon: MapPin });
    if (can?.evv?.viewAny) tools.push({ title: 'Geofences', href: '/operations/geofences', icon: MapPin });
    if (can?.clients?.update) tools.push({ title: 'Family Portal', href: '/operations/family-portal', icon: Users });
    if (can?.rostering?.viewAny || can?.shifts?.viewAny) tools.push({ title: 'Calendar Sync', href: '/operations/calendar-sync', icon: CalendarDays });
    if (can?.rostering?.viewAny) tools.push({ title: 'Qualifications', href: '/operations/qualifications', icon: ShieldCheck });
    if (tools.length > 0) groups.push({ label: 'Tools', items: tools });

    // Reports
    if (can?.operations?.reports?.view || can?.reports?.viewAny) {
        groups.push({ label: 'Reports', items: [
            { title: 'Reports & Analytics', href: '/operations/reports', icon: PieChart },
        ]});
    }

    return groups;
}

function buildEmarSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const groups: SubPanelGroup[] = [];

    // Overview
    groups.push({ label: 'Overview', items: [
        { title: 'Dashboard', href: '/emar', icon: LayoutGrid },
        { title: 'Daily Overview', href: '/emar/daily', icon: Activity },
    ]});

    // Administration
    const admin: NavItem[] = [];
    if (can?.medications?.view) admin.push({ title: 'MAR Charts', href: '/emar/mar', icon: ClipboardCheck });
    if (can?.medications?.view) admin.push({ title: 'Medication Rounds', href: '/emar/rounds', icon: Clock });
    if (can?.medications?.view) admin.push({ title: 'PRN Records', href: '/emar/prn', icon: BookOpen });
    if (can?.medications?.view) admin.push({ title: 'Controlled Drugs', href: '/emar/controlled', icon: Shield });
    if (can?.medications?.breakGlass) admin.push({ title: 'Emergency Access', href: '/emar/emergency-access', icon: ShieldAlert });
    if (admin.length > 0) groups.push({ label: 'Administration', items: admin });

    // Management
    const mgmt: NavItem[] = [];
    if (can?.medications?.view) mgmt.push({ title: 'Medications', href: '/emar/medications', icon: Pill });
    if (can?.medications?.view) mgmt.push({ title: 'Stock Management', href: '/emar/stock', icon: Package });
    if (can?.medications?.view) mgmt.push({ title: 'Prescriptions', href: '/emar/prescriptions', icon: FileText });
    if (can?.medications?.view) mgmt.push({ title: 'Medication Reviews', href: '/emar/reviews', icon: CalendarDays });
    if (can?.medications?.view) mgmt.push({ title: 'Self-Administration', href: '/emar/self-admin', icon: Users });
    if (mgmt.length > 0) groups.push({ label: 'Management', items: mgmt });

    // Compliance
    const compliance: NavItem[] = [];
    if (can?.medications?.audit?.view) compliance.push({ title: 'Audit Trail', href: '/emar/audit', icon: Shield });
    if (can?.reports?.viewAny) compliance.push({ title: 'Reports', href: '/emar/reports', icon: PieChart });
    if (can?.medications?.view) compliance.push({ title: 'Competency', href: '/emar/competency', icon: ClipboardCheck });
    if (can?.medications?.view) compliance.push({ title: 'Destructions', href: '/emar/destructions', icon: Trash2 });
    if (can?.medications?.view) compliance.push({ title: 'Handovers', href: '/emar/handovers', icon: GitBranch });
    if (can?.medications?.view) compliance.push({ title: 'Medication Errors', href: '/emar/errors', icon: AlertTriangle });
    if (compliance.length > 0) groups.push({ label: 'Compliance', items: compliance });

    return groups;
}

function buildSafetySubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const groups: SubPanelGroup[] = [];

    // H&S Overview
    const overview: NavItem[] = [];
    overview.push({ title: 'H&S Dashboard', href: '/health-safety', icon: ShieldCheck });
    overview.push({ title: 'Analytics', href: '/health-safety/analytics', icon: BarChart3 });
    if (overview.length > 0) groups.push({ label: 'Health & Safety', items: overview });

    // Incident Management
    const incidents: NavItem[] = [];
    if (can?.incidents?.viewAny || can?.incidents?.viewAssigned) incidents.push({ title: 'Incidents', href: '/incidents', icon: ShieldAlert });
    if (can?.incidents?.viewAny || can?.incidents?.viewAssigned) incidents.push({ title: 'Near Misses', href: '/incidents?type=near_miss', icon: AlertTriangle });
    incidents.push({ title: 'Fleet Incidents', href: '/fleet-assets/incidents', icon: Truck });
    if (can?.safeguarding?.viewAny || can?.safeguarding?.create) incidents.push({ title: 'Safeguarding', href: '/safeguarding', icon: Shield });
    if (incidents.length > 0) groups.push({ label: 'Incidents & Safeguarding', items: incidents });

    // H&S Management
    const hsManagement: NavItem[] = [];
    if (can?.hazards?.view) hsManagement.push({ title: 'Hazards', href: '/compliance/hazards', icon: AlertOctagon });
    hsManagement.push({ title: 'Worker Participation', href: '/health-safety/worker-participation', icon: Users });
    hsManagement.push({ title: 'Lone Worker Safety', href: '/health-safety/lone-workers', icon: PersonStanding });
    hsManagement.push({ title: 'Emergency Drills', href: '/health-safety/drills', icon: Siren });
    if (hsManagement.length > 0) groups.push({ label: 'H&S Management', items: hsManagement });

    // Registers
    const registers: NavItem[] = [];
    registers.push({ title: 'Chemical Register', href: '/health-safety/substances', icon: FlaskConical });
    registers.push({ title: 'PPE Management', href: '/health-safety/ppe', icon: HardHat });
    registers.push({ title: 'First Aid Register', href: '/health-safety/first-aid', icon: HeartPulse });
    registers.push({ title: 'Restraint Register', href: '/health-safety/restraints', icon: Clipboard });
    if (registers.length > 0) groups.push({ label: 'Registers', items: registers });

    // Injury & Recovery
    const injury: NavItem[] = [];
    injury.push({ title: 'Workplace Injuries', href: '/health-safety/injuries', icon: Activity });
    injury.push({ title: 'Safe Work Procedures', href: '/health-safety/procedures', icon: FileText });
    if (injury.length > 0) groups.push({ label: 'Injury & Procedures', items: injury });

    // Compliance & Risk
    const compliance: NavItem[] = [];
    if (can?.compliance?.view) compliance.push({ title: 'Compliance', href: '/compliance', icon: Shield });
    if (can?.risks?.viewAny || can?.risks?.viewAssigned) compliance.push({ title: 'Risks', href: '/risks', icon: Target });
    if (can?.privacy?.viewRequests) compliance.push({ title: 'Privacy & GDPR', href: '/privacy/dashboard', icon: Shield });
    if (compliance.length > 0) groups.push({ label: 'Compliance & Risk', items: compliance });

    return groups;
}

function buildFleetAssetsSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const groups: SubPanelGroup[] = [];

    // Overview
    const overview: SubPanelGroup = {
        label: 'Overview',
        items: [
            { title: 'Dashboard', href: '/fleet-assets', icon: LayoutGrid },
            { title: 'Live Map', href: '/fleet-assets/map', icon: Map },
            { title: 'Daily Checks', href: '/fleet-assets/daily-check', icon: CheckCircle2 },
            { title: 'Driver App', href: '/fleet-assets/mobile/dashboard', icon: Smartphone },
        ],
    };
    groups.push(overview);

    // Fleet
    const fleet: SubPanelGroup = { label: 'Fleet', items: [] };
    if (can?.fleet?.viewAny) {
        fleet.items.push({ title: 'Vehicles', href: '/fleet-assets/vehicles', icon: Truck });
        fleet.items.push({ title: 'Trips', href: '/fleet-assets/trips', icon: Route });
        fleet.items.push({ title: 'Fuel Logs', href: '/fleet-assets/fuel', icon: Fuel });
        fleet.items.push({ title: 'Compliance', href: '/fleet-assets/compliance', icon: ShieldCheck });
    }
    if (fleet.items.length > 0) groups.push(fleet);

    // Assets
    const assets: SubPanelGroup = { label: 'Assets', items: [] };
    if (can?.assets?.viewAny || can?.assets?.viewAssigned) {
        assets.items.push({ title: 'All Assets', href: '/fleet-assets/assets', icon: Package });
    }
    if (can?.assets?.alertsView) {
        assets.items.push({ title: 'Alerts', href: '/fleet-assets/alerts', icon: AlertTriangle });
    }
    if (can?.assets?.geofences?.manage || can?.fleet?.viewAny) {
        assets.items.push({ title: 'Geofences', href: '/fleet-assets/geofences', icon: MapPin });
    }
    if (assets.items.length > 0) groups.push(assets);

    // Maintenance
    const maintenance: SubPanelGroup = { label: 'Maintenance', items: [] };
    if (can?.fleet?.viewAny || can?.assets?.viewAny) {
        maintenance.items.push({ title: 'Overview', href: '/fleet-assets/maintenance/dashboard', icon: LayoutGrid });
        maintenance.items.push({ title: 'Work Orders', href: '/fleet-assets/maintenance/work-orders', icon: Wrench });
        maintenance.items.push({ title: 'Service Schedules', href: '/fleet-assets/maintenance/schedules', icon: CalendarDays });
        maintenance.items.push({ title: 'Checklists', href: '/fleet-assets/maintenance/checklists', icon: ClipboardCheck });
        maintenance.items.push({ title: 'Inspections', href: '/fleet-assets/inspections', icon: ClipboardList });
    }
    if (maintenance.items.length > 0) groups.push(maintenance);

    // People
    const people: SubPanelGroup = { label: 'People', items: [] };
    if (can?.fleet?.viewAny || can?.hr?.driver?.view) {
        people.items.push({ title: 'Drivers', href: '/fleet-assets/drivers', icon: Users });
    }
    if (can?.fleet?.viewAny || can?.assets?.viewAny) {
        people.items.push({ title: 'Vehicle Bookings', href: '/fleet-assets/bookings', icon: CalendarDays });
        people.items.push({ title: 'Key Management', href: '/fleet-assets/keys', icon: Key });
        people.items.push({ title: 'Resident Tracking', href: '/fleet-assets/resident-tracking', icon: UserSearch });
        people.items.push({ title: 'Transport Logs', href: '/fleet-assets/transports', icon: UserCheck });
        people.items.push({ title: 'Medication Transit', href: '/fleet-assets/transports/medications', icon: Pill });
        people.items.push({ title: 'Outings', href: '/fleet-assets/outings', icon: MapPin });
        people.items.push({ title: 'Shift Handovers', href: '/fleet-assets/handovers', icon: ArrowLeftRight });
    }
    if (people.items.length > 0) groups.push(people);

    // Devices
    const devices: SubPanelGroup = { label: 'Devices', items: [] };
    if (can?.assets?.trackers?.manage || can?.fleet?.viewAny) {
        devices.items.push({ title: 'Tracking Devices', href: '/fleet-assets/devices', icon: Radio });
    }
    if (devices.items.length > 0) groups.push(devices);

    // Safety
    const safety: SubPanelGroup = { label: 'Safety', items: [] };
    safety.items.push({ title: 'Incidents', href: '/fleet-assets/incidents', icon: AlertOctagon });
    safety.items.push({ title: 'Wandering Alerts', href: '/fleet-assets/wandering-alerts', icon: ShieldAlert });
    if (safety.items.length > 0) groups.push(safety);

    // Reports
    const reports: SubPanelGroup = { label: 'Reports', items: [] };
    if (can?.fleet?.reports?.view || can?.fleet?.viewAny) {
        reports.items.push({ title: 'Reports & Analytics', href: '/fleet-assets/reports', icon: FileText });
        reports.items.push({ title: 'Usage by House', href: '/fleet-assets/reports/by-house', icon: Building2 });
        reports.items.push({ title: 'Mileage Reimbursement', href: '/fleet-assets/reports/reimbursement', icon: Receipt });
        reports.items.push({ title: 'Mileage Claims', href: '/fleet-assets/mileage', icon: Receipt });
        reports.items.push({ title: 'Cost Allocation', href: '/fleet-assets/reports/cost-allocation', icon: PieChart });
        reports.items.push({ title: 'Community Access', href: '/fleet-assets/reports/community-access', icon: Users });
    }
    if (reports.items.length > 0) groups.push(reports);

    // Settings
    const settings: SubPanelGroup = { label: 'Settings', items: [] };
    settings.items.push({ title: 'Notifications', href: '/fleet-assets/settings/notifications', icon: Bell });
    if (settings.items.length > 0) groups.push(settings);

    return groups;
}

function buildGovernanceSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const items: NavItem[] = [
        { title: 'Dashboard', href: '/governance/dashboard', icon: Landmark },
        { title: 'Meetings', href: '/governance/meetings', icon: CalendarDays },
    ];
    if (can?.governance?.meetings?.manage) items.push({ title: 'Admin', href: '/governance/admin/board-members', icon: Users });
    items.push({ title: 'Risks', href: '/governance/risks', icon: Target });
    items.push({ title: 'Resolutions', href: '/governance/resolutions', icon: ClipboardCheck });
    items.push({ title: 'Compliance', href: '/governance/compliance', icon: Shield });
    items.push({ title: 'Strategy', href: '/governance/strategy', icon: Target });
    items.push({ title: 'Performance', href: '/governance/performance', icon: ClipboardCheck });
    items.push({ title: 'Budgets', href: '/governance/budgets', icon: DollarSign });
    items.push({ title: 'Board Packs', href: '/governance/packs', icon: FileText });
    items.push({ title: 'Action Items', href: '/governance/actions', icon: ClipboardList });
    return [{ label: 'Governance', items }];
}

function buildFinanceSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const overview: NavItem[] = [];
    overview.push({ title: 'Dashboard', href: '/finance/dashboard', icon: LayoutDashboard });

    if (can?.finance?.ledger?.view) {
        overview.push({ title: 'Chart of Accounts', href: '/finance/accounts', icon: ClipboardList });
        overview.push({ title: 'Journals', href: '/finance/journals', icon: BookOpen });
    }

    const ap: NavItem[] = [];
    if (can?.finance?.ap?.view) {
        ap.push({ title: 'Vendors', href: '/finance/vendors', icon: Users });
        ap.push({ title: 'Purchase Orders', href: '/finance/purchase-orders', icon: ClipboardCheck });
        ap.push({ title: 'Bills', href: '/finance/bills', icon: Receipt });
        ap.push({ title: 'Credit Notes', href: '/finance/credit-notes', icon: FileText });
        ap.push({ title: 'Payment Runs', href: '/finance/payment-runs', icon: ArrowLeftRight });
    }

    const ar: NavItem[] = [];
    if (can?.finance?.ar?.view) {
        ar.push({ title: 'Receivables', href: '/finance/receivables', icon: DollarSign });
        ar.push({ title: 'Invoices', href: '/finance/invoices', icon: FileText });
    }

    const banking: NavItem[] = [];
    if (can?.finance?.bank?.view) {
        banking.push({ title: 'Bank Accounts', href: '/finance/bank-accounts', icon: Landmark });
        banking.push({ title: 'Reconciliation', href: '/finance/bank-reconciliation', icon: CheckCircle2 });
        banking.push({ title: 'Payment Matching', href: '/finance/payment-matching', icon: ArrowLeftRight });
        banking.push({ title: 'Bank Feeds', href: '/finance/bank-feeds', icon: Radio });
        banking.push({ title: 'EFTPOS', href: '/finance/eftpos/terminals', icon: CreditCard });
    }

    const other: NavItem[] = [];
    if (can?.finance?.tax?.view) other.push({ title: 'GST Returns', href: '/finance/gst-returns', icon: Receipt });
    if (can?.finance?.tax?.manage) other.push({ title: 'IRD E-Filing', href: '/finance/ird-filings', icon: Send });
    if (can?.finance?.assets?.view) other.push({ title: 'Fixed Assets', href: '/finance/fixed-assets', icon: Package });
    if (can?.finance?.pettyCash?.view) other.push({ title: 'Petty Cash', href: '/finance/petty-cash', icon: Banknote });
    if (can?.finance?.reports?.view) other.push({ title: 'Donor Funds', href: '/finance/donor-funds', icon: Heart });

    const reports: NavItem[] = [];
    if (can?.finance?.reports?.view) {
        reports.push({ title: 'Trial Balance', href: '/finance/reports/trial-balance', icon: BarChart3 });
        reports.push({ title: 'Profit & Loss', href: '/finance/reports/profit-loss', icon: BarChart3 });
        reports.push({ title: 'Balance Sheet', href: '/finance/reports/balance-sheet', icon: BarChart3 });
        reports.push({ title: 'Budget vs Actuals', href: '/finance/reports/budget-vs-actuals', icon: Target });
        reports.push({ title: 'Cash Flow Forecast', href: '/finance/cash-flow-forecast', icon: TrendingUp });
        reports.push({ title: 'FX Revaluations', href: '/finance/fx-revaluations', icon: ArrowLeftRight });
        reports.push({ title: 'Audit Exports', href: '/finance/audit-exports', icon: Download });
    }

    if (can?.finance?.admin) {
        other.push({ title: 'Fiscal Periods', href: '/finance/fiscal-periods', icon: CalendarDays });
        other.push({ title: 'Cost Centres', href: '/finance/cost-centres', icon: Building2 });
        other.push({ title: 'Funding Streams', href: '/finance/funding-streams', icon: GitBranch });
        other.push({ title: 'Currencies', href: '/finance/currencies', icon: Coins });
        other.push({ title: 'Consolidation', href: '/finance/consolidation', icon: Building2 });
        other.push({ title: 'Integrations', href: '/finance/integrations', icon: Link2 });
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
    if (can?.reports?.viewAny) items.push({ title: 'Reports', href: '/reports', icon: FileText });
    if (can?.sitesReports?.view) items.push({ title: 'Site Reports', href: '/reports/sites', icon: FileText });
    if (can?.calendar?.viewAny) items.push({ title: 'Calendar', href: '/calendar', icon: CalendarDays });
    if (can?.timeline?.viewAny) items.push({ title: 'Timeline', href: '/timeline', icon: Clock });
    if (can?.summaries?.viewAny) items.push({ title: 'Summaries', href: '/summaries', icon: FileText });
    if (can?.audit?.viewAny) items.push({ title: 'Audit Logs', href: '/audit', icon: FileText });
    if (can?.controlRoom?.viewAny) items.push({ title: 'Control Room', href: '/control-room', icon: LayoutGrid });
    if (can?.integrations?.view) items.push({ title: 'Integrations', href: '/integrations', icon: Settings });
    if (can?.settings?.manageAccess) items.push({ title: 'Settings', href: '/settings', icon: Settings });
    if (can?.settings?.rbacManage) items.push({ title: 'Roles & Permissions', href: '/settings/roles', icon: Shield });
    return [{ label: 'System', items }];
}

function buildReportingSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const groups: SubPanelGroup[] = [];

    groups.push({
        label: 'Overview',
        items: [
            { title: 'Reports Dashboard', href: '/reports', icon: BarChart3 },
        ],
    });

    groups.push({
        label: 'Operations',
        items: [
            { title: 'Operations Reports', href: '/operations/reports', icon: ClipboardList },
            { title: 'Shift Reports', href: '/reports/shifts', icon: Clock },
        ],
    });

    groups.push({
        label: 'Sites & Facilities',
        items: [
            { title: 'Site Reports', href: '/sites/reports', icon: Building2 },
        ],
    });

    groups.push({
        label: 'HR & People',
        items: [
            { title: 'HR Reports', href: '/hr/reports', icon: Users },
            { title: 'Report Builder', href: '/hr/reports/builder', icon: Wrench },
        ],
    });

    groups.push({
        label: 'Fleet & Assets',
        items: [
            { title: 'Fleet Reports', href: '/fleet-assets/reports', icon: Car },
        ],
    });

    groups.push({
        label: 'Governance',
        items: [
            { title: 'Board Reports', href: '/governance/reports/board-monthly', icon: FileText },
            { title: 'Compliance', href: '/governance/reports/compliance-status', icon: ShieldCheck },
        ],
    });

    return groups.filter(g => g.items.length > 0);
}

function buildControlRoomSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    return [
        {
            label: 'Live Monitoring',
            items: [
                { title: 'Dashboard', href: '/control-room', icon: LayoutDashboard },
                { title: 'My Day', href: '/control-room/my-tasks', icon: CheckCircle2 },
                { title: 'Live Map', href: '/control-room/map', icon: Map },
                { title: 'Active Shifts', href: '/control-room/shifts', icon: Clock },
            ],
        },
        {
            label: 'Alerts & Escalations',
            items: [
                { title: 'All Alerts', href: '/control-room/alerts', icon: AlertTriangle },
                { title: 'Escalation Queue', href: '/control-room/escalations', icon: ArrowUpCircle },
                { title: 'Incident Tracker', href: '/control-room/incidents', icon: AlertCircle },
            ],
        },
        {
            label: 'Communications',
            items: [
                { title: 'Broadcast', href: '/control-room/broadcast', icon: Megaphone },
                { title: 'Staff Messaging', href: '/control-room/messaging', icon: MessageSquare },
            ],
        },
        {
            label: 'Operations',
            items: [
                { title: 'Devices', href: '/control-room/devices', icon: Smartphone },
                { title: 'Playbooks', href: '/control-room/playbooks', icon: ClipboardCheck },
                { title: 'SLA Management', href: '/control-room/sla', icon: Target },
            ],
        },
        {
            label: 'Analytics',
            items: [
                { title: 'Real-time Stats', href: '/control-room/stats', icon: Activity },
                { title: 'Reports', href: '/control-room/reports', icon: FileText },
            ],
        },
        {
            label: 'Configuration',
            items: [
                { title: 'Settings', href: '/control-room/settings', icon: Settings },
            ],
        },
    ];
}

function buildHrSubPanelGroups({ can }: { can?: any }): SubPanelGroup[] {
    const groups: SubPanelGroup[] = [];

    // My HR - always visible
    const myHr: SubPanelGroup = {
        label: 'My HR',
        items: [
            { title: 'My HR', href: '/hr/my', icon: Home },
            { title: 'My Training', href: '/hr/my/training', icon: Target },
            { title: 'My Payslips', href: '/hr/my/payslips', icon: FileText },
        ],
    };
    groups.push(myHr);

    // People
    const people: SubPanelGroup = {
        label: 'People',
        items: [
            { title: 'Directory', href: '/hr/directory', icon: Users },
        ],
    };
    if (can?.hr?.employees?.viewAny) {
        people.items.push({ title: 'People', href: '/hr/people', icon: Users });
        people.items.push({ title: 'Import/Export', href: '/hr/import-export', icon: FileText });
    }
    if (can?.hr?.positions?.view) {
        people.items.push({ title: 'Positions', href: '/hr/positions', icon: Briefcase });
    }
    const hasAnyHr = can?.hr?.recruitment?.view || can?.hr?.employees?.viewAny || can?.hr?.compliance?.view
        || can?.hr?.leave?.viewAny || can?.hr?.performance?.view || can?.hr?.reports?.view
        || can?.hr?.policies?.view || can?.hr?.positions?.view || can?.hr?.time?.view
        || can?.hr?.compensation?.view;
    if (hasAnyHr) {
        people.items.push({ title: 'Org Chart', href: '/hr/orgchart', icon: GitBranch });
    }
    if (can?.hr?.recruitment?.view) {
        people.items.push({ title: 'Recruitment', href: '/hr/recruitment', icon: Users });
        people.items.push({ title: 'Job Postings', href: '/hr/job-postings', icon: Briefcase });
    }
    groups.push(people);

    // Workforce
    const workforce: SubPanelGroup = { label: 'Workforce', items: [] };
    if (can?.hr?.leave?.viewAny) {
        workforce.items.push({ title: 'Leave & Rosters', href: '/hr/leave', icon: CalendarDays });
        workforce.items.push({ title: 'Leave Reports', href: '/hr/leave/reports', icon: FileText });
    }
    if (can?.hr?.time?.view) {
        workforce.items.push({ title: 'Time Tracking', href: '/hr/time', icon: Clock });
    }
    if (can?.hr?.compensation?.view) {
        workforce.items.push({ title: 'Compensation', href: '/hr/compensation/bands', icon: DollarSign });
    }
    if (can?.hr?.benefits?.view) {
        workforce.items.push({ title: 'Benefits', href: '/hr/benefits', icon: Shield });
    }
    if (can?.hr?.expenses?.view) {
        workforce.items.push({ title: 'Expenses', href: '/hr/expenses', icon: DollarSign });
    }
    workforce.items.push({ title: 'Time Off Calendar', href: '/hr/calendar/time-off', icon: CalendarDays });
    if (workforce.items.length > 0) groups.push(workforce);

    // Performance
    const performance: SubPanelGroup = { label: 'Performance', items: [] };
    if (can?.hr?.performance?.view) {
        performance.items.push({ title: 'Performance', href: '/hr/performance', icon: ClipboardCheck });
        performance.items.push({ title: '360 Feedback', href: '/hr/feedback', icon: Users });
        performance.items.push({ title: 'PIPs', href: '/hr/performance/pips', icon: ClipboardList });
        performance.items.push({ title: 'Competencies', href: '/hr/performance/competencies', icon: Target });
        performance.items.push({ title: 'Succession', href: '/hr/succession', icon: Users });
    }
    if (can?.hr?.goals?.view) {
        performance.items.push({ title: 'Goals', href: '/hr/goals', icon: Target });
    }
    if (performance.items.length > 0) groups.push(performance);

    // Engagement
    const engagement: SubPanelGroup = { label: 'Engagement', items: [] };
    engagement.items.push({ title: 'Community Feed', href: '/hr/feed', icon: MessageSquareText });
    if (can?.hr?.surveys?.view) {
        engagement.items.push({ title: 'Surveys', href: '/hr/surveys', icon: ClipboardList });
    }
    if (can?.hr?.announcements?.view) {
        engagement.items.push({ title: 'Announcements', href: '/hr/announcements', icon: MessageSquareText });
    }
    if (can?.hr?.analytics?.view) {
        engagement.items.push({ title: 'Wellbeing', href: '/hr/wellbeing', icon: Target });
    }
    if (engagement.items.length > 0) groups.push(engagement);

    // Admin
    const admin: SubPanelGroup = { label: 'Admin', items: [] };
    if (can?.hr?.compliance?.view) {
        admin.items.push({ title: 'Compliance', href: '/hr/compliance', icon: Shield });
        admin.items.push({ title: 'Compliance Calendar', href: '/hr/compliance/calendar', icon: CalendarDays });
    }
    if (can?.hr?.settings?.manage || can?.hr?.employees?.manage) {
        admin.items.push({ title: 'Departments', href: '/hr/departments', icon: Briefcase });
    }
    if (can?.hr?.training?.view) {
        admin.items.push({ title: 'Training', href: '/hr/training/catalog', icon: BookOpen });
    }
    if (can?.hr?.assets?.view) {
        admin.items.push({ title: 'Assets', href: '/hr/assets', icon: Package });
    }
    if (can?.hr?.skills?.view) {
        admin.items.push({ title: 'Skills', href: '/hr/skills', icon: Target });
    }
    if (can?.hr?.analytics?.view) {
        admin.items.push({ title: 'Analytics', href: '/hr/analytics', icon: LayoutGrid });
        admin.items.push({ title: 'Headcount', href: '/hr/headcount', icon: Users });
    }
    if (can?.hr?.calendar?.view) {
        admin.items.push({ title: 'Calendar', href: '/hr/calendar', icon: CalendarDays });
    }
    if (can?.hr?.vetting?.view) {
        admin.items.push({ title: 'Vetting', href: '/hr/compliance/vetting', icon: Shield });
    }
    if (can?.hr?.driver?.view) {
        admin.items.push({ title: 'Drivers', href: '/hr/compliance/drivers', icon: Users });
    }
    if (can?.hr?.onboarding?.view) {
        admin.items.push({ title: 'Onboarding', href: '/hr/onboarding', icon: ClipboardCheck });
        admin.items.push({ title: 'Onboarding Emails', href: '/hr/onboarding/emails', icon: MessageSquareText });
    }
    if (can?.hr?.documents?.view) {
        admin.items.push({ title: 'Documents', href: '/hr/documents', icon: FileText });
        admin.items.push({ title: 'Expiring Docs', href: '/hr/documents/expiring', icon: ShieldAlert });
    }
    admin.items.push({ title: 'Approvals', href: '/hr/approvals/pending', icon: ClipboardCheck });
    admin.items.push({ title: 'Signatures', href: '/hr/signatures/pending', icon: FileText });
    if (can?.hr?.cases?.view) {
        admin.items.push({ title: 'HR Cases', href: '/hr/cases', icon: Shield });
    }
    if (can?.hr?.payroll?.view) {
        admin.items.push({ title: 'Payroll', href: '/hr/payroll', icon: DollarSign });
        admin.items.push({ title: 'Payslips', href: '/hr/payroll/payslips', icon: FileText });
    }
    if (can?.hr?.policies?.view) {
        admin.items.push({ title: 'Policies', href: '/hr/policies', icon: FileText });
    }
    admin.items.push({ title: 'Exit Interviews', href: '/hr/exit-interviews', icon: Users });
    if (can?.hr?.reports?.view) {
        admin.items.push({ title: 'Reports', href: '/hr/reports', icon: FileText });
        admin.items.push({ title: 'Report Builder', href: '/hr/reports/builder', icon: LayoutGrid });
    }
    if (can?.hr?.settings?.manage) {
        admin.items.push({ title: 'Settings', href: '/hr/settings/webhooks', icon: Settings });
        admin.items.push({ title: 'Custom Fields', href: '/hr/settings/custom-fields', icon: Settings });
        admin.items.push({ title: 'Audit Log', href: '/hr/settings/audit-log', icon: FileText });
    }
    if (admin.items.length > 0) groups.push(admin);

    return groups;
}

// ── Sub-panel component ────────────────────────────────────────────────────

function SubPanel({
    groups,
    currentUrl,
    onClose,
    title = '',
}: {
    groups: SubPanelGroup[];
    currentUrl: string;
    onClose: () => void;
    title?: string;
}) {
    const panelRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        function handleClickOutside(e: MouseEvent) {
            if (panelRef.current && !panelRef.current.contains(e.target as Node)) {
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
            className="fixed left-14 top-0 bottom-0 z-50 w-60 border-r border-sidebar-border bg-sidebar text-sidebar-foreground overflow-y-auto shadow-lg"
        >
            {/* Panel header */}
            <div className="flex items-center justify-between px-4 py-3 border-b border-sidebar-border/50">
                <span className="text-sm font-semibold text-sidebar-foreground">{title || 'Menu'}</span>
                <button
                    onClick={onClose}
                    className="flex items-center justify-center w-6 h-6 rounded-md text-sidebar-foreground/60 hover:text-sidebar-foreground hover:bg-sidebar-accent transition-colors"
                >
                    <X className="h-4 w-4" />
                </button>
            </div>

            {/* Panel groups */}
            <div className="py-2">
                {groups.map((group) => (
                    <div key={group.label} className="mb-1">
                        <div className="px-4 py-1.5 text-[11px] font-medium uppercase tracking-wider text-sidebar-foreground/40">
                            {group.label}
                        </div>
                        {group.items.map((item) => {
                            const active = isSubItemActive(currentUrl, item.href);
                            return (
                                <Link
                                    key={resolveUrl(item.href)}
                                    href={item.href}
                                    prefetch
                                    preserveScroll
                                    className={cn(
                                        'flex items-center gap-2.5 px-4 py-1.5 text-sm transition-colors',
                                        active
                                            ? 'bg-sidebar-primary/10 text-sidebar-primary-foreground font-medium'
                                            : 'text-sidebar-foreground/70 hover:text-sidebar-foreground hover:bg-sidebar-accent',
                                    )}
                                >
                                    {item.icon && <item.icon className="h-4 w-4 shrink-0" />}
                                    <span className="truncate">{item.title}</span>
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

export function AppSidebar() {
    const page = usePage<PageProps & Record<string, any>>();
    const { auth, branding, name: appName, labels } = page.props;
    const role = auth.user?.role ?? null;
    const can = auth?.can;
    const portalClients = auth?.portalClients ?? null;
    const currentUrl = page.url;
    const getInitials = useInitials();
    const displayName: string = (branding as any)?.name ?? appName ?? 'Oblivion Findings';
    const logoUrl: string | null = (branding as any)?.logoUrl ?? null;

    const [openPanelId, setOpenPanelId] = useState<string | null>(null);

    const iconNavItems = useMemo(() => buildIconNavItems({ role, can, portalClients }), [role, can, portalClients]);

    const subPanelMap = useMemo(() => ({
        sites: buildSitesSubPanelGroups({ can }),
        operations: buildOperationsSubPanelGroups({ can, role, labels: labels as any }),
        emar: buildEmarSubPanelGroups({ can }),
        safety: buildSafetySubPanelGroups({ can }),
        'fleet-assets': buildFleetAssetsSubPanelGroups({ can }),
        hr: buildHrSubPanelGroups({ can }),
        governance: buildGovernanceSubPanelGroups({ can }),
        finance: buildFinanceSubPanelGroups({ can }),
        reporting: buildReportingSubPanelGroups({ can }),
        'control-room': buildControlRoomSubPanelGroups({ can }),
    }), [can, role]);

    const toggleSubPanel = useCallback((id: string) => {
        setOpenPanelId((prev) => (prev === id ? null : id));
    }, []);

    const closeSubPanel = useCallback(() => {
        setOpenPanelId(null);
    }, []);

    // Close sub-panel on navigation
    useEffect(() => {
        setOpenPanelId(null);
    }, [currentUrl]);

    return (
        <TooltipProvider delayDuration={0}>
            <div className="relative hidden md:flex">
                {/* Icon sidebar - 56px (w-14), fixed position */}
                <nav className="fixed top-0 left-0 z-40 flex h-svh w-14 flex-col items-center bg-sidebar border-r border-sidebar-border py-3 overflow-x-hidden overflow-y-hidden">
                    {/* Top: Logo */}
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Link
                                href={dashboard()}
                                prefetch
                                className="flex items-center justify-center w-10 h-10 rounded-xl mb-4 bg-sidebar-primary text-sidebar-primary-foreground overflow-hidden"
                            >
                                {logoUrl ? (
                                    <img src={logoUrl} alt={displayName} className="h-full w-full object-cover" />
                                ) : (
                                    <AppLogoIcon className="size-5 fill-current text-white dark:text-black" />
                                )}
                            </Link>
                        </TooltipTrigger>
                        <TooltipContent side="right">{displayName}</TooltipContent>
                    </Tooltip>

                    {/* Middle: Nav icons */}
                    <div className="flex flex-1 flex-col items-center gap-1 overflow-y-auto scrollbar-none w-full px-2">
                        {iconNavItems.map((item) => {
                            const panelGroups = item.subPanel ? (subPanelMap as any)[item.id] as SubPanelGroup[] | undefined : undefined;
                            const active = isIconActive(currentUrl, item, panelGroups);

                            if (item.subPanel) {
                                const isPanelOpen = openPanelId === item.id;
                                return (
                                    <div key={item.id} className={cn(item.dividerAfter && 'mb-1 pb-1 border-b border-sidebar-border/30 w-full flex justify-center')}>
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <button
                                                    data-sub-panel-trigger
                                                    onClick={() => toggleSubPanel(item.id)}
                                                    className={cn(
                                                        'flex items-center justify-center w-10 h-10 rounded-xl transition-colors',
                                                        active || isPanelOpen
                                                            ? 'bg-sidebar-primary text-sidebar-primary-foreground'
                                                            : 'text-sidebar-foreground/60 hover:text-sidebar-foreground hover:bg-sidebar-accent',
                                                    )}
                                                >
                                                    <item.icon className="h-6 w-6" />
                                                </button>
                                            </TooltipTrigger>
                                            <TooltipContent side="right">{item.label}</TooltipContent>
                                        </Tooltip>
                                    </div>
                                );
                            }

                            return (
                                <div key={item.id} className={cn(item.dividerAfter && 'mb-1 pb-1 border-b border-sidebar-border/30 w-full flex justify-center')}>
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <Link
                                                href={item.href!}
                                                prefetch
                                                className={cn(
                                                    'flex items-center justify-center w-10 h-10 rounded-xl transition-colors',
                                                    active
                                                        ? 'bg-sidebar-primary text-sidebar-primary-foreground'
                                                        : 'text-sidebar-foreground/60 hover:text-sidebar-foreground hover:bg-sidebar-accent',
                                                )}
                                            >
                                                <item.icon className="h-6 w-6" />
                                            </Link>
                                        </TooltipTrigger>
                                        <TooltipContent side="right">{item.label}</TooltipContent>
                                    </Tooltip>
                                </div>
                            );
                        })}
                    </div>

                    {/* Bottom: Settings + User avatar */}
                    <div className="mt-auto flex flex-col items-center gap-1 pt-2 border-t border-sidebar-border/30 w-full px-2">
                        {/* Settings */}
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Link
                                    href="/settings"
                                    prefetch
                                    className={cn(
                                        'flex items-center justify-center w-10 h-10 rounded-xl transition-colors',
                                        currentUrl.startsWith('/settings')
                                            ? 'bg-sidebar-primary text-sidebar-primary-foreground'
                                            : 'text-sidebar-foreground/60 hover:text-sidebar-foreground hover:bg-sidebar-accent',
                                    )}
                                >
                                    <Settings className="h-5 w-5" />
                                </Link>
                            </TooltipTrigger>
                            <TooltipContent side="right">Settings</TooltipContent>
                        </Tooltip>

                        {/* User avatar with dropdown */}
                        {auth.user && (
                            <DropdownMenu>
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <DropdownMenuTrigger asChild>
                                            <button className="flex items-center justify-center w-10 h-10 rounded-lg transition-colors text-sidebar-foreground/60 hover:text-sidebar-foreground hover:bg-sidebar-accent">
                                                <Avatar className="h-8 w-8 overflow-hidden rounded-full">
                                                    <AvatarImage src={auth.user.avatar} alt={auth.user.name} />
                                                    <AvatarFallback className="rounded-full bg-neutral-200 text-black text-xs dark:bg-neutral-700 dark:text-white">
                                                        {getInitials(auth.user.name)}
                                                    </AvatarFallback>
                                                </Avatar>
                                            </button>
                                        </DropdownMenuTrigger>
                                    </TooltipTrigger>
                                    <TooltipContent side="right">{auth.user.name}</TooltipContent>
                                </Tooltip>
                                <DropdownMenuContent
                                    className="min-w-56 rounded-lg"
                                    align="end"
                                    side="right"
                                >
                                    <UserMenuContent user={auth.user as any} />
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                </nav>

                {/* Sub-panel (slides out for any section) */}
                {openPanelId && (subPanelMap as any)[openPanelId] && (
                    <SubPanel
                        groups={(subPanelMap as any)[openPanelId]}
                        currentUrl={currentUrl}
                        onClose={closeSubPanel}
                        title={iconNavItems.find(i => i.id === openPanelId)?.label ?? ''}
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
    const currentUrl = page.url;

    const iconNavItems = useMemo(() => buildIconNavItems({ role, can, portalClients }), [role, can, portalClients]);

    const mobileSubPanelMap = useMemo(() => ({
        sites: buildSitesSubPanelGroups({ can }),
        operations: buildOperationsSubPanelGroups({ can, role, labels: labels as any }),
        emar: buildEmarSubPanelGroups({ can }),
        safety: buildSafetySubPanelGroups({ can }),
        'fleet-assets': buildFleetAssetsSubPanelGroups({ can }),
        hr: buildHrSubPanelGroups({ can }),
        governance: buildGovernanceSubPanelGroups({ can }),
        finance: buildFinanceSubPanelGroups({ can }),
        reporting: buildReportingSubPanelGroups({ can }),
        'control-room': buildControlRoomSubPanelGroups({ can }),
    }), [can, role]);

    const [expandedId, setExpandedId] = useState<string | null>(null);

    // Close on navigation
    useEffect(() => {
        onClose();
    }, [currentUrl]);

    if (!open) return null;

    return (
        <>
            {/* Backdrop */}
            <div className="fixed inset-0 z-40 bg-black/50 md:hidden" onClick={onClose} />

            {/* Drawer */}
            <div className="fixed inset-y-0 left-0 z-50 w-72 bg-sidebar text-sidebar-foreground overflow-y-auto md:hidden">
                {/* Close button */}
                <div className="flex items-center justify-between px-4 py-3 border-b border-sidebar-border/50">
                    <span className="text-sm font-semibold">Menu</span>
                    <button onClick={onClose} className="p-1 rounded-md hover:bg-sidebar-accent">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="py-2">
                    {iconNavItems.map((item) => {
                        if (item.subPanel) {
                            const groups = (mobileSubPanelMap as any)[item.id] as SubPanelGroup[] | undefined;
                            const active = isIconActive(currentUrl, item, groups);
                            const isExpanded = expandedId === item.id;
                            return (
                                <div key={item.id}>
                                    <button
                                        onClick={() => setExpandedId(isExpanded ? null : item.id)}
                                        className={cn(
                                            'flex items-center gap-3 w-full px-4 py-2 text-sm transition-colors',
                                            active
                                                ? 'bg-sidebar-primary/10 text-sidebar-primary-foreground font-medium'
                                                : 'text-sidebar-foreground/70 hover:text-sidebar-foreground hover:bg-sidebar-accent',
                                        )}
                                    >
                                        <item.icon className="h-5 w-5 shrink-0" />
                                        <span>{item.label}</span>
                                        <ChevronRight className={cn('ml-auto h-4 w-4 transition-transform', isExpanded && 'rotate-90')} />
                                    </button>
                                    {isExpanded && groups?.map((group) => (
                                        <div key={group.label} className="ml-4">
                                            <div className="px-4 py-1 text-[11px] font-medium uppercase tracking-wider text-sidebar-foreground/40">
                                                {group.label}
                                            </div>
                                            {group.items.map((sub) => (
                                                <Link
                                                    key={resolveUrl(sub.href)}
                                                    href={sub.href}
                                                    prefetch
                                                    className={cn(
                                                        'flex items-center gap-2.5 px-4 py-1.5 text-sm transition-colors',
                                                        isSubItemActive(currentUrl, sub.href)
                                                            ? 'bg-sidebar-primary/10 text-sidebar-primary-foreground font-medium'
                                                            : 'text-sidebar-foreground/70 hover:text-sidebar-foreground hover:bg-sidebar-accent',
                                                    )}
                                                >
                                                    {sub.icon && <sub.icon className="h-4 w-4 shrink-0" />}
                                                    <span>{sub.title}</span>
                                                </Link>
                                            ))}
                                        </div>
                                    ))}
                                    {item.dividerAfter && <div className="my-1 mx-4 border-b border-sidebar-border/30" />}
                                </div>
                            );
                        }

                        const active = item.href ? matchScore(currentUrl, item.href) > 0 : false;
                        return (
                            <div key={item.id}>
                                <Link
                                    href={item.href!}
                                    prefetch
                                    className={cn(
                                        'flex items-center gap-3 px-4 py-2 text-sm transition-colors',
                                        active
                                            ? 'bg-sidebar-primary/10 text-sidebar-primary-foreground font-medium'
                                            : 'text-sidebar-foreground/70 hover:text-sidebar-foreground hover:bg-sidebar-accent',
                                    )}
                                >
                                    <item.icon className="h-5 w-5 shrink-0" />
                                    <span>{item.label}</span>
                                </Link>
                                {item.dividerAfter && <div className="my-1 mx-4 border-b border-sidebar-border/30" />}
                            </div>
                        );
                    })}
                </div>
            </div>
        </>
    );
}
