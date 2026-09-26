import {
    BarChart3,
    Bell,
    LayoutGrid,
    Map,
    Package,
    Truck,
    Wrench,
    type LucideIcon,
} from 'lucide-react';

/** Navigation only. The server retains all role, site, record and privacy checks. */
export interface FleetNavigationPermissions {
    fleet?: { viewAny?: boolean };
    assets?: {
        viewAny?: boolean;
        viewAssigned?: boolean;
        alertsView?: boolean;
        geofencesManage?: boolean;
        trackersManage?: boolean;
        telemetryView?: boolean;
    };
    hr?: { assets?: { view?: boolean }; driver?: { view?: boolean } };
    clients?: { viewAny?: boolean; viewAssigned?: boolean };
    controlRoom?: { viewAny?: boolean; alertsView?: boolean };
    securityDevices?: { devicesView?: boolean };
}

type Can = FleetNavigationPermissions | null | undefined;
type Visibility = (can: Can) => boolean;
export interface FleetNavigationLink {
    label: string;
    href: string;
    visible: Visibility;
}
export interface FleetNavigationGroup {
    label: string;
    links: FleetNavigationLink[];
}
export interface FleetWorkspace {
    key: string;
    label: string;
    icon: LucideIcon;
    /** Ordered permitted landing candidates; never send a viewer to a denied register. */
    landings: string[];
    groups: FleetNavigationGroup[];
}

const fleet: Visibility = (can) => Boolean(can?.fleet?.viewAny);
const operational: Visibility = (can) =>
    fleet(can) || Boolean(can?.assets?.viewAny);
export const canSeeFleetNavigation: Visibility = (can) =>
    operational(can) || Boolean(can?.assets?.viewAssigned);
const inventory: Visibility = (can) =>
    Boolean(can?.assets?.viewAny || can?.assets?.viewAssigned);
const link = (
    label: string,
    path: string,
    visible: Visibility = operational,
): FleetNavigationLink => ({
    label,
    href: path.startsWith('/')
        ? path
        : `/fleet-assets${path ? `/${path}` : ''}`,
    visible,
});

/** The seven workspaces and their short contextual menus share one route map. */
export const FLEET_WORKSPACES: FleetWorkspace[] = [
    {
        key: 'overview',
        label: 'Overview',
        icon: LayoutGrid,
        landings: ['/fleet-assets'],
        groups: [
            {
                label: 'Overview',
                links: [link('Overview', '', canSeeFleetNavigation)],
            },
            {
                label: 'Alerts & safety',
                links: [
                    link('Fleet alerts', 'alerts', (can) =>
                        Boolean(
                            can?.assets?.viewAny || can?.assets?.alertsView,
                        ),
                    ),
                    link('Fleet incidents', 'incidents'),
                    link('Control Room', '/control-room', (can) =>
                        Boolean(can?.controlRoom?.viewAny),
                    ),
                ],
            },
        ],
    },
    {
        key: 'fleet',
        label: 'Fleet',
        icon: Truck,
        landings: [
            '/fleet-assets/vehicles',
            '/fleet-assets/bookings',
            '/fleet-assets/drivers',
        ],
        groups: [
            { label: 'Vehicles', links: [link('Vehicles', 'vehicles', fleet)] },
            { label: 'Bookings', links: [link('Bookings', 'bookings')] },
            {
                label: 'Transport',
                links: [
                    link('Transport journeys', 'transports'),
                    link('Outings', 'outings'),
                    link('Medication transit', 'transports/medications'),
                ],
            },
            {
                label: 'Operating records',
                links: [
                    link('Trips', 'trips', fleet),
                    link('Fuel logs', 'fuel', fleet),
                    link('Compliance', 'compliance', fleet),
                ],
            },
            {
                label: 'People & custody',
                links: [
                    link(
                        'Drivers & eligibility',
                        'drivers',
                        (can) => fleet(can) || Boolean(can?.hr?.driver?.view),
                    ),
                    link('Keys', 'keys'),
                    link('Shift handovers', 'handovers'),
                ],
            },
        ],
    },
    {
        key: 'assets',
        label: 'Assets',
        icon: Package,
        landings: ['/fleet-assets/assets', '/hr/assets'],
        groups: [
            {
                label: 'Inventory',
                links: [link('Inventory', 'assets', inventory)],
            },
            {
                label: 'Employee equipment',
                links: [
                    link('HR asset register', '/hr/assets', (can) =>
                        Boolean(can?.hr?.assets?.view),
                    ),
                ],
            },
        ],
    },
    {
        key: 'maintenance',
        label: 'Maintenance',
        icon: Wrench,
        landings: [
            '/fleet-assets/maintenance/work-orders',
            '/fleet-assets/daily-check',
        ],
        groups: [
            {
                label: 'Work',
                links: [
                    link('Work queue', 'maintenance/work-orders'),
                    link('Maintenance overview', 'maintenance/dashboard'),
                ],
            },
            {
                label: 'Schedules',
                links: [link('Service schedules', 'maintenance/schedules')],
            },
            {
                label: 'Checks & inspections',
                links: [
                    link('Daily checks', 'daily-check', canSeeFleetNavigation),
                    link('Checklists', 'maintenance/checklists'),
                    link('Inspections', 'inspections'),
                ],
            },
        ],
    },
    {
        key: 'maps',
        label: 'Maps & boundaries',
        icon: Map,
        landings: ['/fleet-assets/map'],
        groups: [
            {
                label: 'Map',
                links: [link('Map', 'map', canSeeFleetNavigation)],
            },
            {
                label: 'Boundaries',
                links: [
                    link(
                        'Boundaries',
                        'geofences',
                        (can) =>
                            fleet(can) || Boolean(can?.assets?.geofencesManage),
                    ),
                ],
            },
            {
                label: 'Client location',
                links: [
                    link('Clients', '/operations/clients', (can) =>
                        Boolean(
                            can?.clients?.viewAny || can?.clients?.viewAssigned,
                        ),
                    ),
                    link(
                        'Authorised client tracking',
                        'resident-tracking',
                        (can) =>
                            operational(can) &&
                            Boolean(can?.assets?.telemetryView),
                    ),
                ],
            },
            {
                label: 'Devices',
                links: [
                    link(
                        'Tracking devices & pairing',
                        'devices',
                        (can) =>
                            fleet(can) || Boolean(can?.assets?.trackersManage),
                    ),
                    link(
                        'Security & Devices registry',
                        '/security-devices/devices',
                        (can) => Boolean(can?.securityDevices?.devicesView),
                    ),
                ],
            },
        ],
    },
    {
        key: 'reports',
        label: 'Reports',
        icon: BarChart3,
        landings: ['/fleet-assets/reports', '/fleet-assets/mileage'],
        groups: [
            // fleet.reports.view is not in shared auth.can. Do not substitute the unrelated reports.viewAny permission.
            {
                label: 'Reports',
                links: [link('Reports & analytics', 'reports', fleet)],
            },
            {
                label: 'Resource use',
                links: [
                    link('Usage by house', 'reports/by-house', fleet),
                    link('Community access', 'reports/community-access', fleet),
                ],
            },
            {
                label: 'Costs & mileage',
                links: [
                    link('Cost allocation', 'reports/cost-allocation', fleet),
                    link(
                        'Mileage reimbursement',
                        'reports/reimbursement',
                        fleet,
                    ),
                    link('Mileage claims', 'mileage'),
                ],
            },
        ],
    },
    {
        key: 'settings',
        label: 'Settings',
        icon: Bell,
        landings: ['/fleet-assets/settings/notifications'],
        groups: [
            {
                label: 'Notifications',
                links: [link('Notifications', 'settings/notifications')],
            },
        ],
    },
];

export function visibleFleetGroups(
    workspace: FleetWorkspace,
    can: Can,
): FleetNavigationGroup[] {
    return workspace.groups
        .map((group) => ({
            ...group,
            links: group.links.filter((item) => item.visible(can)),
        }))
        .filter((group) => group.links.length > 0);
}

export function fleetPrimaryLinks(can: Can) {
    if (!canSeeFleetNavigation(can)) return [];
    return FLEET_WORKSPACES.flatMap((workspace) => {
        const links = visibleFleetGroups(workspace, can).flatMap(
            (group) => group.links,
        );
        const href = workspace.landings.find((candidate) =>
            links.some((item) => item.href === candidate),
        );
        return href
            ? [{ title: workspace.label, href, icon: workspace.icon }]
            : [];
    });
}

export function fleetNavigationPath(url: string): string {
    return (url.split(/[?#]/)[0] || '/').replace(/\/+$/, '') || '/';
}

/** Only Fleet-owned routes select this workspace. Other modules retain their own active state. */
export function fleetWorkspaceForUrl(url: string) {
    const path = fleetNavigationPath(url);
    return FLEET_WORKSPACES.flatMap((workspace) =>
        workspace.groups.flatMap((group) =>
            group.links
                .filter(
                    (item) =>
                        item.href.startsWith('/fleet-assets') &&
                        (path === item.href ||
                            (item.href !== '/fleet-assets' &&
                                path.startsWith(`${item.href}/`))),
                )
                .map((item) => ({ workspace, item })),
        ),
    ).sort((a, b) => b.item.href.length - a.item.href.length)[0];
}

/** Undefined leaves the existing sidebar matcher alone for every unrelated link. */
export function fleetPrimaryLinkActive(
    url: string,
    href: string,
): boolean | undefined {
    if (!href.startsWith('/fleet-assets')) return undefined;
    const workspace = FLEET_WORKSPACES.find((candidate) =>
        candidate.landings.includes(href),
    );
    if (!workspace) return undefined;
    return fleetWorkspaceForUrl(url)?.workspace.key === workspace.key;
}
