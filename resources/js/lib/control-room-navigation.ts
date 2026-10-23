import {
    Activity,
    ArrowUpCircle,
    Bell,
    BookOpen,
    Clock,
    FileText,
    BellOff,
    CircleCheck,
    FileWarning,
    LayoutDashboard,
    ListChecks,
    ListTodo,
    Map,
    MessageSquare,
    Radio,
    RadioTower,
    Send,
    Settings,
    SlidersHorizontal,
    Target,
    Wrench,
    type LucideIcon,
} from 'lucide-react';

/**
 * Control Room navigation — the Fleet / eMAR pattern: one map owns the six
 * sidebar destinations (approved 11 Oct 2026: 12 menu items → 6) and the view
 * tabs on each destination's header rail. Old URLs keep working; each one
 * belongs to exactly one destination so the sidebar entry stays lit.
 *
 * Navigation only. The server keeps every permission check; a hidden link is
 * never the security boundary.
 */
export type ControlRoomNavCapabilities = {
    controlRoom?: {
        viewAny?: boolean;
        alertsView?: boolean;
        alertsManage?: boolean;
        alertsAssign?: boolean;
        alertsEscalate?: boolean;
        alertsCreate?: boolean;
        reportsView?: boolean;
    };
};

type Can = ControlRoomNavCapabilities | null | undefined;

export type ControlRoomDestinationKey =
    | 'response'
    | 'monitoring'
    | 'communications'
    | 'duty'
    | 'reports'
    | 'settings';

export type ControlRoomView = {
    /** The view's canonical URL; also its rail key. */
    key: string;
    label: string;
    icon: LucideIcon;
};

export type ControlRoomDestination = {
    key: ControlRoomDestinationKey;
    title: string;
    icon: LucideIcon;
    /** The first view this person may open. */
    href: string;
    views: ControlRoomView[];
};

const read = (can: Can) => Boolean(can?.controlRoom?.viewAny);
const manage = (can: Can) => Boolean(can?.controlRoom?.alertsManage);
const alertList = (can: Can) =>
    read(can) ||
    manage(can) ||
    Boolean(can?.controlRoom?.alertsView);

function views(can: Can, key: ControlRoomDestinationKey): ControlRoomView[] {
    const all: Array<ControlRoomView & { allowed: boolean }> = (() => {
        switch (key) {
            case 'response':
                return [
                    {
                        key: read(can) ? '/control-room' : '/control-room/alerts',
                        label: 'Needs action',
                        icon: Bell,
                        allowed: alertList(can),
                    },
                    {
                        key: '/control-room/my-tasks',
                        label: 'Mine',
                        icon: ListTodo,
                        allowed: read(can),
                    },
                    {
                        key: '/control-room/escalations',
                        label: 'Escalated',
                        icon: ArrowUpCircle,
                        allowed: read(can),
                    },
                    {
                        key: '/control-room/alerts?lens=snoozed',
                        label: 'Snoozed',
                        icon: BellOff,
                        allowed: alertList(can),
                    },
                    {
                        key: '/control-room/incidents',
                        label: 'Incident reports',
                        icon: FileWarning,
                        allowed: read(can),
                    },
                    {
                        key: '/control-room/alerts?lens=history',
                        label: 'Done',
                        icon: CircleCheck,
                        allowed: alertList(can),
                    },
                ];
            case 'monitoring':
                return [
                    {
                        key: '/control-room/map',
                        label: 'Map',
                        icon: Map,
                        allowed: read(can),
                    },
                    {
                        key: '/control-room/devices',
                        label: 'Signals',
                        icon: Radio,
                        allowed: read(can),
                    },
                ];
            case 'communications':
                return [
                    {
                        key: '/control-room/broadcast',
                        label: 'Broadcasts',
                        icon: Send,
                        allowed: read(can),
                    },
                    {
                        key: '/control-room/messaging',
                        label: 'Conversations',
                        icon: MessageSquare,
                        allowed: read(can),
                    },
                ];
            case 'duty':
                return [
                    {
                        key: '/control-room/shifts',
                        label: 'Current duty',
                        icon: Clock,
                        allowed: read(can),
                    },
                ];
            case 'reports':
                return [
                    {
                        key: '/control-room/reports',
                        label: 'Overview',
                        icon: LayoutDashboard,
                        allowed:
                            read(can) || Boolean(can?.controlRoom?.reportsView),
                    },
                    {
                        key: '/control-room/stats',
                        label: 'Trends',
                        icon: Activity,
                        allowed: read(can),
                    },
                    {
                        key: '/control-room/sla/breaches',
                        label: 'Target breaches',
                        icon: Target,
                        allowed: read(can),
                    },
                ];
            case 'settings':
                return [
                    {
                        key: '/control-room/playbooks',
                        label: 'Procedures',
                        icon: BookOpen,
                        allowed: read(can),
                    },
                    {
                        key: '/control-room/sla',
                        label: 'Response targets',
                        icon: Target,
                        allowed: read(can),
                    },
                    {
                        key: '/control-room/settings?tab=rules',
                        label: 'Routing',
                        icon: RadioTower,
                        allowed: manage(can),
                    },
                    {
                        key: '/control-room/settings?tab=queues',
                        label: 'Queues',
                        icon: ListChecks,
                        allowed: manage(can),
                    },
                    {
                        key: '/control-room/settings?tab=sources',
                        label: 'Sources',
                        icon: Radio,
                        allowed: manage(can),
                    },
                    {
                        key: '/control-room/settings?tab=maintenance',
                        label: 'Maintenance',
                        icon: Wrench,
                        allowed: manage(can),
                    },
                    {
                        key: '/control-room/settings?tab=signal-outbox',
                        label: 'Delivery recovery',
                        icon: Activity,
                        allowed: manage(can),
                    },
                    {
                        key: '/control-room/settings?tab=ticket-options',
                        label: 'Options',
                        icon: SlidersHorizontal,
                        allowed: manage(can),
                    },
                ];
        }
    })();
    return all
        .filter((view) => view.allowed)
        .map(({ key: viewKey, label, icon }) => ({ key: viewKey, label, icon }));
}

const DESTINATIONS: Array<{
    key: ControlRoomDestinationKey;
    title: string;
    icon: LucideIcon;
}> = [
    { key: 'response', title: 'Live response', icon: Bell },
    { key: 'monitoring', title: 'Monitoring', icon: Map },
    { key: 'communications', title: 'Communications', icon: MessageSquare },
    { key: 'duty', title: 'Duty handover', icon: Clock },
    { key: 'reports', title: 'Reports', icon: FileText },
    { key: 'settings', title: 'Settings', icon: Settings },
];

/** The sidebar entries this person may open, each at its first permitted view. */
export function controlRoomDestinations(can: Can): ControlRoomDestination[] {
    return DESTINATIONS.flatMap((destination) => {
        const list = views(can, destination.key);
        if (list.length === 0) return [];
        return [{ ...destination, href: list[0].key, views: list }];
    });
}

function pathOf(url: string): string {
    const path = url.split(/[?#]/)[0].replace(/\/+$/, '');
    return path === '' ? '/' : path;
}

/** Which destination a Control Room URL belongs to (undefined outside Control Room). */
export function controlRoomDestinationForPath(
    url: string,
): ControlRoomDestinationKey | undefined {
    const path = pathOf(url);
    if (path !== '/control-room' && !path.startsWith('/control-room/'))
        return undefined;
    if (/^\/control-room\/(map|devices)(\/|$)/.test(path)) return 'monitoring';
    if (/^\/control-room\/(broadcast|messaging)(\/|$)/.test(path))
        return 'communications';
    if (/^\/control-room\/shifts(\/|$)/.test(path)) return 'duty';
    if (
        /^\/control-room\/(reports|stats|sla\/breaches|sla-breaches)(\/|$)/.test(
            path,
        )
    )
        return 'reports';
    if (/^\/control-room\/(settings|playbooks|sla)(\/|$)/.test(path))
        return 'settings';
    return 'response';
}

/**
 * The sidebar's generic longest-prefix rule can't tell sibling legacy URLs
 * apart (/control-room prefixes everything), so the Control Room entries are
 * lit by destination. undefined = not a Control Room link.
 */
export function controlRoomPrimaryLinkActive(
    currentPath: string,
    itemPath: string,
): boolean | undefined {
    const item = controlRoomDestinationForPath(itemPath);
    if (item === undefined) return undefined;
    return controlRoomDestinationForPath(currentPath) === item;
}

/**
 * The active view key for a URL among a destination's views: the most
 * specific match on path + query wins; a child page (/control-room/devices/12)
 * lights its parent view.
 */
export function controlRoomActiveView(url: string, keys: string[]): string {
    const current = new URL(url, 'https://control-room.invalid');
    if (current.pathname === '/control-room/sla-breaches')
        current.pathname = '/control-room/sla/breaches';
    const exact = [...keys]
        .sort(
            (a, b) =>
                new URL(b, current.origin).searchParams.size -
                new URL(a, current.origin).searchParams.size,
        )
        .find((key) => {
            const candidate = new URL(key, current.origin);
            return (
                candidate.pathname === current.pathname &&
                [...candidate.searchParams].every(
                    ([name, value]) => current.searchParams.get(name) === value,
                )
            );
        });
    if (exact) return exact;
    // /control-room/alerts with no lens (or lens=active) is the Active view.
    if (current.pathname === '/control-room/alerts') {
        const active = keys.find(
            (key) => key === '/control-room' || key === '/control-room/alerts',
        );
        if (active) return active;
    }
    // Same page, no matching query (/control-room/settings with no tab):
    // the first view on that page.
    const samePage = keys.find(
        (key) => new URL(key, current.origin).pathname === current.pathname,
    );
    if (samePage) return samePage;
    const child = keys.find((key) =>
        current.pathname.startsWith(`${pathOf(key)}/`),
    );
    return child ?? keys[0] ?? '';
}

/** The destination + its views for a URL, for the header rail and breadcrumbs. */
export function controlRoomHubForUrl(url: string, can: Can) {
    const key = controlRoomDestinationForPath(url);
    if (!key) return null;
    const destination = DESTINATIONS.find((d) => d.key === key)!;
    const list = views(can, key);
    return {
        destination: {
            ...destination,
            href: list[0]?.key ?? '/control-room',
            views: list,
        } satisfies ControlRoomDestination,
        activeView: controlRoomActiveView(
            url,
            list.map((view) => view.key),
        ),
    };
}
