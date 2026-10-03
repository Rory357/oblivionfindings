import {
    Activity,
    Ban,
    BarChart3,
    ClipboardList,
    Clock,
    FileText,
    History,
    LayoutGrid,
    Lock,
    OctagonAlert,
    Package,
    Pill,
    Repeat,
    Settings,
    Shield,
    ShieldCheck,
    Stethoscope,
    UserCheck,
    type LucideIcon,
} from 'lucide-react';

/**
 * Medication (eMAR) navigation — the Fleet pattern: one map owns the hub
 * labels, each hub's built views, the permission-ordered
 * landing, visibility and command-search entries.
 *
 * Source: the approved P00 hub map and integrated package routes. Page-local
 * rails retain their own workflow sections; this map also owns discovery.
 *
 * Navigation only. The server keeps every medication permission check; a
 * hidden link is never the security boundary.
 */
export interface EmarNavigationPermissions {
    medications?: {
        view?: boolean;
        administerRecord?: boolean;
        ordersManage?: boolean;
        ordersVerify?: boolean;
        settingsManage?: boolean;
        alertsManageHouse?: boolean;
        witnessPinReset?: boolean;
        /** Server-supplied scoped emergency-policy access, when available. */
        emergencyPolicyAccess?: boolean;
        stockUpdate?: boolean;
        controlledView?: boolean;
        controlledRecord?: boolean;
        controlledWitness?: boolean;
        auditView?: boolean;
        reportsExport?: boolean;
        reportsView?: boolean;
        auditExport?: boolean;
        breakGlass?: boolean;
    };
    reports?: { viewAny?: boolean };
}

type Can = EmarNavigationPermissions | null | undefined;
type Visibility = (can: Can) => boolean;

export type EmarHubKey =
    | 'today'
    | 'mar'
    | 'orders'
    | 'stock'
    | 'safety'
    | 'reports'
    | 'settings';

export interface EmarHubView {
    key: string;
    label: string;
    href: string;
    icon: LucideIcon;
    visible: Visibility;
    /** Older URLs that still belong to this view (redirects, renamed routes). */
    aliases?: string[];
}

export interface EmarHub {
    key: EmarHubKey;
    label: string;
    icon: LucideIcon;
    visible: Visibility;
    views: EmarHubView[];
    /**
     * The hub's pages carry their own in-page rail (Meds today's tabs, the
     * Settings rail) instead of the shared hub rail.
     */
    ownRail?: boolean;
}

const meds = (can: Can) => can?.medications ?? {};
const view: Visibility = (can) => Boolean(meds(can).view);

/** A lead capability: verify or manage orders, or manage medication settings. */
export const hasLeadCapability: Visibility = (can) => {
    const m = meds(can);
    return Boolean(m.ordersVerify || m.ordersManage || m.settingsManage);
};

/**
 * Frontline: no lead or manager capability. Controlled-drug record or witness
 * alone never promotes anyone (eMAR second review §2.1).
 */
export const isFrontlineMedication: Visibility = (can) => {
    const m = meds(can);
    return !(
        m.ordersManage ||
        m.ordersVerify ||
        m.stockUpdate ||
        m.auditView ||
        m.reportsExport ||
        m.reportsView ||
        m.settingsManage ||
        m.alertsManageHouse ||
        m.witnessPinReset ||
        m.emergencyPolicyAccess ||
        m.breakGlass
    );
};

/**
 * A manager capability that opens the cross-person hubs (MAR & medicines,
 * the controlled-drug views). Break-glass is not one: an emergency-access
 * holder gets Safety & oversight › Emergency access alone (approved NAV Q5).
 */
const hasManagerCapability: Visibility = (can) => {
    const m = meds(can);
    return Boolean(
        m.ordersManage ||
        m.ordersVerify ||
        m.stockUpdate ||
        m.auditView ||
        m.reportsExport ||
        m.settingsManage,
    );
};

const lead: Visibility = (can) => view(can) && hasLeadCapability(can);
const all =
    (...checks: Visibility[]): Visibility =>
    (can) =>
        checks.every((check) => check(can));
const any =
    (...checks: Visibility[]): Visibility =>
    (can) =>
        checks.some((check) => check(can));
const flag =
    (
        key: keyof NonNullable<EmarNavigationPermissions['medications']>,
    ): Visibility =>
    (can) =>
        Boolean(meds(can)[key]);

const administer = flag('administerRecord');
const stockUpdate = flag('stockUpdate');
const controlledView = flag('controlledView');
const auditView = flag('auditView');
const reportsView = flag('reportsView');
const settingsManage = flag('settingsManage');
const breakGlass = flag('breakGlass');
const safetyAccess = any(hasLeadCapability, auditView, breakGlass);
const settingsReader = any(settingsManage, auditView);
const settingsAccess = any(
    settingsReader,
    flag('ordersManage'),
    flag('alertsManageHouse'),
    flag('witnessPinReset'),
    flag('emergencyPolicyAccess'),
);

/** The reports route requires module read access; audit adds its own gate. */
export const canOpenEmarReports = reportsView;
export const canOpenEmarAudit = all(reportsView, auditView);

/** Translate a source page's scope into the canonical report period/filter. */
export function emarReportsHref(
    reportView: 'standard' | 'audit' | 'exports',
    scope: {
        date?: string;
        site_id?: number | null;
        client_id?: number | null;
        q?: string | null;
        report?: string;
    } = {},
): string {
    const params = new URLSearchParams({ view: reportView });
    if (scope.date) {
        params.set('period', 'custom');
        params.set('date_from', scope.date);
        params.set('date_to', scope.date);
    }
    if (scope.site_id) params.set('site_id', String(scope.site_id));
    if (scope.client_id) params.set('client_id', String(scope.client_id));
    if (scope.q?.trim()) params.set('q', scope.q.trim());
    if (scope.report) params.set('report', scope.report);
    return `/emar/reports?${params}`;
}

export const EMAR_HUBS: EmarHub[] = [
    {
        key: 'today',
        label: 'Meds today',
        icon: Pill,
        visible: any(administer, hasLeadCapability),
        ownRail: true,
        views: [
            {
                key: 'schedule',
                label: 'Schedule',
                href: '/meds/today',
                icon: Clock,
                visible: any(view, administer),
            },
            {
                key: 'rounds',
                label: 'Rounds',
                href: '/meds/today?view=rounds',
                icon: Repeat,
                visible: any(view, administer),
                aliases: ['/emar/rounds'],
            },
            {
                key: 'followups',
                label: 'Follow-ups',
                href: '/meds/today?view=followups',
                icon: ClipboardList,
                visible: any(view, administer),
            },
        ],
    },
    {
        key: 'mar',
        label: 'MAR & medicines',
        icon: ClipboardList,
        // Anyone with medications.view except frontline and finance-only roles.
        visible: (can) =>
            view(can) &&
            hasManagerCapability(can) &&
            !(stockUpdate(can) && !hasLeadCapability(can) && !auditView(can)),
        views: [
            {
                key: 'charts',
                label: 'MAR charts',
                href: '/emar/mar',
                icon: ClipboardList,
                visible: view,
            },
            {
                key: 'medicines',
                label: 'Medicines',
                href: '/emar/medications',
                icon: Pill,
                visible: view,
            },
            {
                key: 'prn',
                label: 'As-needed history',
                href: '/emar/prn',
                icon: History,
                visible: view,
            },
            {
                key: 'self-admin',
                label: 'Support & self-administration',
                href: '/emar/self-admin',
                icon: UserCheck,
                visible: view,
            },
        ],
    },
    {
        key: 'orders',
        label: 'Orders & reviews',
        icon: FileText,
        visible: hasLeadCapability,
        views: [
            {
                key: 'prescriptions',
                label: 'Prescriptions',
                href: '/emar/prescriptions',
                icon: FileText,
                visible: view,
            },
            {
                key: 'to-check',
                label: 'Orders to check',
                href: '/emar/prescriptions?view=to_check',
                icon: ShieldCheck,
                visible: view,
            },
            {
                key: 'covert',
                label: 'Covert giving',
                href: '/emar/prescriptions?view=covert',
                icon: ShieldCheck,
                visible: view,
            },
            {
                key: 'reconciliation',
                label: 'Reconciliation',
                href: '/emar/prescriptions?view=reconciliation',
                icon: Repeat,
                visible: view,
            },
            {
                key: 'reviews',
                label: 'Medication reviews',
                href: '/emar/reviews',
                icon: Stethoscope,
                visible: view,
            },
        ],
    },
    {
        key: 'stock',
        label: 'Stock & controlled drugs',
        icon: Package,
        visible: (can) =>
            stockUpdate(can) ||
            (controlledView(can) && hasManagerCapability(can)),
        views: [
            {
                key: 'stock',
                label: 'Stock & pharmacy',
                href: '/emar/stock',
                icon: Package,
                visible: all(view, stockUpdate),
            },
            {
                key: 'controlled',
                label: 'Controlled register',
                href: '/emar/controlled',
                icon: Shield,
                visible: all(view, controlledView),
            },
            {
                key: 'losses',
                label: 'Loss reports',
                href: '/emar/controlled?view=losses',
                icon: OctagonAlert,
                visible: all(view, controlledView),
                aliases: ['/emar/controlled/loss-reports'],
            },
            {
                key: 'destructions',
                label: 'Destructions & returns',
                href: '/emar/destructions',
                icon: Ban,
                visible: all(view, controlledView),
                aliases: ['/emar/controlled?view=destructions'],
            },
        ],
    },
    {
        key: 'safety',
        label: 'Safety & oversight',
        icon: ShieldCheck,
        // Workers reach their follow-ups on Meds today; that read permission
        // alone must not add a second sidebar entry. Break-glass remains scoped.
        visible: safetyAccess,
        views: [
            {
                key: 'overview',
                label: 'Overview',
                href: '/emar',
                icon: LayoutGrid,
                visible: lead,
                aliases: ['/emar/daily'],
            },
            {
                key: 'followups',
                label: 'Follow-ups',
                href: '/medication-followups',
                icon: ClipboardList,
                visible: view,
            },
            {
                key: 'errors',
                label: 'Medication errors',
                href: '/emar/errors',
                icon: OctagonAlert,
                visible: all(view, any(hasLeadCapability, auditView)),
            },
            {
                key: 'handovers',
                label: 'Handovers',
                href: '/emar/handovers',
                icon: Repeat,
                visible: lead,
            },
            {
                key: 'eligibility',
                label: 'Staff eligibility',
                href: '/emar/safety/eligibility',
                icon: UserCheck,
                visible: lead,
                aliases: ['/emar/competency'],
            },
            {
                key: 'witness-overrides',
                label: 'Witness overrides',
                href: '/emar/safety/witness-overrides',
                icon: ShieldCheck,
                visible: all(view, controlledView, hasManagerCapability),
            },
            {
                // Request and active grants. Review for audit.view holders
                // (NF-12) joins when P10 builds it; the route is break-glass.
                key: 'emergency',
                label: 'Emergency access',
                href: '/emar/emergency-access',
                icon: Lock,
                visible: breakGlass,
            },
        ],
    },
    {
        key: 'reports',
        label: 'Reports & audit',
        icon: BarChart3,
        visible: reportsView,
        ownRail: true,
        views: [
            {
                key: 'reports',
                label: 'Standard reports',
                href: '/emar/reports',
                icon: BarChart3,
                visible: reportsView,
            },
            {
                key: 'audit',
                label: 'Audit trail',
                href: '/emar/reports?view=audit',
                icon: Activity,
                visible: canOpenEmarAudit,
                aliases: ['/emar/audit', '/medications/audit'],
            },
            {
                key: 'builder',
                label: 'Report builder',
                href: '/emar/reports/builder',
                icon: BarChart3,
                visible: reportsView,
            },
            {
                key: 'exports',
                label: 'Print & exports',
                href: '/emar/reports?view=exports',
                icon: FileText,
                visible: reportsView,
            },
        ],
    },
    {
        key: 'settings',
        label: 'Settings',
        icon: Settings,
        visible: settingsAccess,
        ownRail: true,
        views: [
            {
                key: 'rules',
                label: 'Medication rules',
                href: '/emar/settings',
                icon: Settings,
                visible: settingsReader,
                aliases: ['/emar/settings#rules'],
            },
            {
                key: 'templates',
                label: 'Round templates',
                href: '/emar/settings#rounds/templates',
                aliases: ['/emar/settings#rounds'],
                icon: Repeat,
                visible: any(settingsReader, flag('ordersManage')),
            },
            {
                key: 'witness-pins',
                label: 'Witness PINs',
                href: '/emar/settings#staff/pins',
                aliases: ['/emar/settings#staff'],
                icon: UserCheck,
                visible: any(settingsReader, flag('witnessPinReset')),
            },
            {
                key: 'alerts',
                label: 'Alerts & access',
                href: '/emar/settings#alerts/overview',
                aliases: ['/emar/settings#alerts'],
                icon: OctagonAlert,
                visible: any(settingsReader, flag('alertsManageHouse')),
            },
            {
                key: 'emergency-policy',
                label: 'Emergency access policy',
                href: '/emar/settings#alerts/emergency',
                icon: Lock,
                visible: any(settingsReader, flag('emergencyPolicyAccess')),
            },
            {
                key: 'changes',
                label: 'Settings change history',
                href: '/emar/settings#history/changes',
                aliases: ['/emar/settings#history'],
                icon: History,
                visible: settingsReader,
            },
        ],
    },
];

export function visibleEmarViews(hub: EmarHub, can: Can): EmarHubView[] {
    if (!hub.visible(can)) return [];
    return hub.views.filter((item) => item.visible(can));
}

export function visibleEmarHubs(can: Can): EmarHub[] {
    return EMAR_HUBS.filter((hub) => visibleEmarViews(hub, can).length > 0);
}

/** The first view this viewer may open — never a denied page. */
export function emarHubLanding(hub: EmarHub, can: Can): string | null {
    return visibleEmarViews(hub, can)[0]?.href ?? null;
}

export interface EmarSidebarHubLink {
    key: EmarHubKey;
    title: string;
    href: string;
    icon: LucideIcon;
}

export type EmarSidebar =
    | { mode: 'none' }
    /** Frontline: one top-level "Meds today" entry. */
    | { mode: 'frontline'; href: string }
    /** Everyone else: the "Medication" module listing their hubs. */
    | { mode: 'module'; label: string; hubs: EmarSidebarHubLink[] };

export const EMAR_MODULE_LABEL = 'Medication';

export function emarSidebar(can: Can): EmarSidebar {
    const hubs = visibleEmarHubs(can);
    if (hubs.length === 0) return { mode: 'none' };
    if (hubs.length === 1 && hubs[0].key === 'today') {
        return {
            mode: 'frontline',
            href: emarHubLanding(hubs[0], can) ?? '/meds/today',
        };
    }
    return {
        mode: 'module',
        label: EMAR_MODULE_LABEL,
        hubs: hubs.map((hub) => ({
            key: hub.key,
            title: hub.label,
            href: emarHubLanding(hub, can) as string,
            icon: hub.icon,
        })),
    };
}

export function emarNavigationPath(url: string): string {
    return (url.split(/[?#]/)[0] || '/').replace(/\/+$/, '') || '/';
}

const navigationUrl = (url: string) => new URL(url, 'https://medication.local');

/** Exact view selectors outrank their page's default view; filters are ignored. */
function targetSpecificity(target: string, current: URL): number | undefined {
    const expected = navigationUrl(target);
    const path = emarNavigationPath(current.pathname);
    const targetPath = emarNavigationPath(expected.pathname);
    if (
        path !== targetPath &&
        (targetPath === '/emar' || !path.startsWith(`${targetPath}/`))
    )
        return undefined;
    for (const [key, value] of expected.searchParams) {
        if (current.searchParams.get(key) !== value) return undefined;
    }
    const hash = expected.hash.replace(/^#\/?/, '');
    const currentHash = current.hash.replace(/^#\/?/, '');
    if (hash && currentHash !== hash && !currentHash.startsWith(`${hash}/`))
        return undefined;
    return (
        targetPath.length * 1000 +
        expected.searchParams.size * 100 +
        hash.length
    );
}

/** The hub and view a URL belongs to, or undefined outside the module. */
export function emarHubForUrl(
    url: string,
): { hub: EmarHub; view: EmarHubView } | undefined {
    const current = navigationUrl(url);
    return EMAR_HUBS.flatMap((hub) =>
        hub.views.flatMap((item) => {
            const matches = [item.href, ...(item.aliases ?? [])]
                .map((target) => targetSpecificity(target, current))
                .filter((score): score is number => score !== undefined);
            return matches.length
                ? [{ hub, view: item, score: Math.max(...matches) }]
                : [];
        }),
    ).sort((a, b) => b.score - a.score)[0];
}

/** The viewer's way into the module: their first hub's landing. */
export function emarModuleLanding(can: Can): string | null {
    const first = visibleEmarHubs(can)[0];
    return first ? emarHubLanding(first, can) : null;
}

export interface EmarBreadcrumb {
    title: string;
    href: string;
}

/**
 * Home › Medication › hub › view for a Medication page — the Home-rooted
 * trail (DESIGN.md "Missing or non-Home-rooted breadcrumbs"), in the rail's
 * own labels. The hub crumb opens this viewer's landing for that hub and
 * Medication their first hub, so no crumb leads to a page they can't open.
 */
export function emarBreadcrumbs(url: string, can: Can): EmarBreadcrumb[] {
    const match = emarHubForUrl(url);
    const here = match?.view.href ?? emarNavigationPath(url);
    const trail: EmarBreadcrumb[] = [
        { title: 'Home', href: '/dashboard' },
        { title: EMAR_MODULE_LABEL, href: emarModuleLanding(can) ?? here },
    ];
    if (!match) return trail;
    return [
        ...trail,
        {
            title: match.hub.label,
            href: emarHubLanding(match.hub, can) ?? match.view.href,
        },
        { title: match.view.label, href: match.view.href },
    ];
}

/**
 * A person's medication record (P02): frontline staff reach it from Meds
 * today (Home › Meds today › person); everyone else from the MAR hub
 * (Home › Medication › MAR & medicines › person).
 */
export function emarRecordBreadcrumbs(
    can: Can,
    person: EmarBreadcrumb,
): EmarBreadcrumb[] {
    const home = { title: 'Home', href: '/dashboard' };
    const sidebar = emarSidebar(can);
    if (sidebar.mode === 'frontline') {
        return [home, { title: 'Meds today', href: sidebar.href }, person];
    }
    const mar = EMAR_HUBS.find((hub) => hub.key === 'mar');
    const marLanding = mar ? emarHubLanding(mar, can) : null;
    return [
        home,
        {
            title: EMAR_MODULE_LABEL,
            href: emarModuleLanding(can) ?? person.href,
        },
        ...(mar && marLanding ? [{ title: mar.label, href: marLanding }] : []),
        person,
    ];
}

/**
 * Sidebar active state for a hub link: lit on every page of its hub.
 * Undefined leaves the generic matcher alone for every other link.
 */
export function emarHubLinkActive(
    url: string,
    href: string,
): boolean | undefined {
    const linkHub = emarHubForUrl(href)?.hub;
    if (!linkHub) return undefined;
    return emarHubForUrl(url)?.hub.key === linkHub.key;
}

export interface EmarSearchEntry {
    id: string;
    label: string;
    href: string;
    group: string;
    icon: LucideIcon;
    keywords: string[];
}

/** "eMAR" stays a search synonym for the renamed module (§2.3). */
export const EMAR_SEARCH_KEYWORDS = ['eMAR', 'medication', 'medicines'];

/** Every view this viewer may open, for command search. */
export function emarSearchEntries(can: Can): EmarSearchEntry[] {
    return visibleEmarHubs(can).flatMap((hub) =>
        visibleEmarViews(hub, can).map((item) => ({
            id: `emar:${hub.key}:${item.key}`,
            label: item.label,
            href: item.href,
            group: hub.label,
            icon: item.icon,
            keywords: EMAR_SEARCH_KEYWORDS,
        })),
    );
}
