import type { NavItem } from '@/types';
import {
    Activity,
    Building2,
    Cpu,
    HeartPulse,
    LayoutDashboard,
    MapPinned,
    Network,
    Plug,
    Radar,
    Settings,
    Shield,
    UserSearch,
    Wrench,
} from 'lucide-react';

export interface SecurityDevicesPermissions {
    viewAny?: boolean;
    devicesView?: boolean;
    groupsManage?: boolean;
    eventsView?: boolean;
    maintenanceView?: boolean;
    maintenanceManage?: boolean;
    integrationsView?: boolean;
    integrationsManage?: boolean;
    monitoringManage?: boolean;
    reportsView?: boolean;
    commandsAdmin?: boolean;
}

export interface SecurityDevicesNavigationItem extends NavItem {
    href: string;
    aliases?: string[];
    visible: (can: SecurityDevicesPermissions) => boolean;
}

export interface SecurityDevicesNavigationGroup {
    label: 'Overview' | 'Workspaces' | 'Operations' | 'Setup';
    items: SecurityDevicesNavigationItem[];
}

const domainHrefs: Record<string, string> = {
    security: '/security-devices/security',
    tracking: '/security-devices/tracking',
    iot_healthcare: '/security-devices/healthcare',
    it_infrastructure: '/security-devices/network-it',
    facilities: '/security-devices/facilities-iot',
};

export function securityDevicesDomainHref(domain: string): string {
    return domainHrefs[domain] ?? '/security-devices/devices';
}

const navigationGroups: SecurityDevicesNavigationGroup[] = [
    {
        label: 'Overview',
        items: [
            {
                title: 'Estate overview',
                href: '/security-devices',
                icon: LayoutDashboard,
                visible: (can) => Boolean(can.viewAny),
            },
            {
                title: 'Sites',
                href: '/security-devices/sites',
                icon: MapPinned,
                visible: (can) => Boolean(can.devicesView),
            },
            {
                title: 'All devices',
                href: '/security-devices/devices',
                icon: Cpu,
                visible: (can) => Boolean(can.devicesView),
            },
        ],
    },
    {
        label: 'Workspaces',
        items: [
            {
                title: 'Network & IT',
                href: '/security-devices/network-it',
                aliases: ['/security-devices/it-infrastructure'],
                icon: Network,
                visible: (can) => Boolean(can.devicesView),
            },
            {
                title: 'Security',
                href: '/security-devices/security',
                aliases: [
                    '/security-devices/alarms',
                    '/security-devices/cctv',
                    '/security-devices/access-control',
                ],
                icon: Shield,
                visible: (can) => Boolean(can.devicesView),
            },
            {
                title: 'Healthcare',
                href: '/security-devices/healthcare',
                aliases: ['/security-devices/smart-iot-healthcare'],
                icon: HeartPulse,
                visible: (can) => Boolean(can.devicesView),
            },
            {
                title: 'Tracking',
                href: '/security-devices/tracking',
                aliases: ['/security-devices/tracking-devices'],
                icon: UserSearch,
                visible: (can) => Boolean(can.devicesView),
            },
            {
                title: 'Facilities & IoT',
                href: '/security-devices/facilities-iot',
                aliases: ['/security-devices/facilities'],
                icon: Building2,
                visible: (can) => Boolean(can.devicesView),
            },
        ],
    },
    {
        label: 'Operations',
        items: [
            {
                title: 'Monitoring',
                href: '/security-devices/monitoring',
                aliases: ['/security-devices/alerts-events'],
                icon: Activity,
                visible: (can) => Boolean(can.eventsView),
            },
            {
                title: 'Maintenance',
                href: '/security-devices/maintenance',
                aliases: ['/security-devices/maintenance-health'],
                icon: Wrench,
                visible: (can) =>
                    Boolean(can.maintenanceView || can.maintenanceManage),
            },
        ],
    },
    {
        label: 'Setup',
        items: [
            {
                title: 'Discovery & collectors',
                href: '/security-devices/discovery',
                icon: Radar,
                visible: (can) => Boolean(can.integrationsView),
            },
            {
                title: 'Integrations',
                href: '/security-devices/integrations',
                icon: Plug,
                visible: (can) => Boolean(can.integrationsView),
            },
            {
                title: 'Settings & audit',
                href: '/security-devices/settings',
                aliases: [
                    '/security-devices/device-groups',
                    '/security-devices/reports',
                ],
                icon: Settings,
                visible: (can) =>
                    Boolean(
                        can.groupsManage ||
                        can.reportsView ||
                        can.commandsAdmin ||
                        can.monitoringManage,
                    ),
            },
        ],
    },
];

export function buildSecurityDevicesNavigationGroups(
    can: SecurityDevicesPermissions = {},
): SecurityDevicesNavigationGroup[] {
    return navigationGroups
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => item.visible(can)),
        }))
        .filter((group) => group.items.length > 0);
}

function normalizePath(url: string): string {
    const path = url.split('?')[0] ?? '/';
    const trimmed = path.replace(/\/+$/, '');

    return trimmed || '/';
}

function pathMatches(currentPath: string, destinationPath: string): boolean {
    if (destinationPath === '/security-devices') {
        return currentPath === destinationPath;
    }

    return (
        currentPath === destinationPath ||
        currentPath.startsWith(`${destinationPath}/`)
    );
}

export function isSecurityDevicesDestinationActive(
    currentUrl: string,
    destinationHref: string,
): boolean {
    const destination = navigationGroups
        .flatMap((group) => group.items)
        .find((item) => item.href === destinationHref);
    const currentPath = normalizePath(currentUrl);
    const destinationPath = normalizePath(destinationHref);

    if (pathMatches(currentPath, destinationPath)) {
        return true;
    }

    return Boolean(
        destination?.aliases?.some((alias) =>
            pathMatches(currentPath, normalizePath(alias)),
        ),
    );
}

export const securityDevicesNavigationGroups = navigationGroups;
