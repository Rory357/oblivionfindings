import type { NavItem } from '@/types';
import {
    Activity,
    Cpu,
    LayoutDashboard,
    MapPinned,
    Settings,
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
    hubHrefs?: string[];
    resolveHref?: (can: SecurityDevicesPermissions) => string;
    aliases?: string[];
    visible: (can: SecurityDevicesPermissions) => boolean;
}

export interface SecurityDevicesNavigationGroup {
    label: 'Security & Devices';
    items: SecurityDevicesNavigationItem[];
}

const knownDomains = new Set([
    'security',
    'tracking',
    'iot_healthcare',
    'it_infrastructure',
    'facilities',
]);

export function securityDevicesDomainHref(domain: string): string {
    if (knownDomains.has(domain)) {
        return `/security-devices/devices?domain=${domain}`;
    }

    return '/security-devices/devices';
}

const legacyWorkspaceLabels: Record<string, string> = {
    'network-it': 'Network & IT',
    'it-infrastructure': 'Network & IT',
    security: 'Security',
    alarms: 'Security',
    cctv: 'Security',
    'access-control': 'Security',
    healthcare: 'Healthcare',
    'smart-iot-healthcare': 'Healthcare',
    tracking: 'Tracking',
    'tracking-devices': 'Tracking',
    'facilities-iot': 'Facilities & IoT',
    facilities: 'Facilities & IoT',
};

const navigationGroups: SecurityDevicesNavigationGroup[] = [
    {
        label: 'Security & Devices',
        items: [
            {
                title: 'Overview',
                href: '/security-devices',
                hubHrefs: ['/security-devices'],
                icon: LayoutDashboard,
                visible: (can) => Boolean(can.viewAny),
            },
            {
                title: 'Devices',
                href: '/security-devices/devices',
                hubHrefs: ['/security-devices/devices'],
                aliases: [
                    '/security-devices/network-it',
                    '/security-devices/it-infrastructure',
                    '/security-devices/security',
                    '/security-devices/alarms',
                    '/security-devices/cctv',
                    '/security-devices/access-control',
                    '/security-devices/healthcare',
                    '/security-devices/smart-iot-healthcare',
                    '/security-devices/tracking',
                    '/security-devices/tracking-devices',
                    '/security-devices/facilities-iot',
                    '/security-devices/facilities',
                    '/security-devices/device-groups',
                    '/security-devices/command-batches',
                ],
                icon: Cpu,
                visible: (can) => Boolean(can.devicesView),
            },
            {
                title: 'Sites',
                href: '/security-devices/sites',
                hubHrefs: ['/security-devices/sites'],
                icon: MapPinned,
                visible: (can) => Boolean(can.devicesView),
            },
            {
                title: 'Alerts & upkeep',
                href: '/security-devices/monitoring',
                hubHrefs: [
                    '/security-devices/monitoring',
                    '/security-devices/maintenance',
                ],
                resolveHref: (can) =>
                    can.eventsView
                        ? '/security-devices/monitoring'
                        : '/security-devices/maintenance',
                aliases: [
                    '/security-devices/monitoring',
                    '/security-devices/alerts-events',
                    '/security-devices/maintenance',
                    '/security-devices/maintenance-health',
                    '/security-devices/runtime-health',
                ],
                icon: Activity,
                visible: (can) =>
                    Boolean(
                        can.eventsView ||
                        can.maintenanceView ||
                        can.maintenanceManage,
                    ),
            },
            {
                title: 'Setup',
                href: '/security-devices/discovery',
                hubHrefs: [
                    '/security-devices/discovery',
                    '/security-devices/agent-downloads',
                    '/security-devices/settings',
                ],
                resolveHref: (can) => {
                    if (can.integrationsView) {
                        return '/security-devices/discovery';
                    }

                    if (can.commandsAdmin || can.integrationsManage) {
                        return '/security-devices/agent-downloads';
                    }

                    return '/security-devices/settings';
                },
                aliases: [
                    '/security-devices/discovery',
                    '/security-devices/integrations',
                    '/security-devices/supported-devices',
                    '/security-devices/agent-downloads',
                    '/security-devices/settings',
                    '/security-devices/reports',
                ],
                icon: Settings,
                visible: (can) =>
                    Boolean(
                        can.integrationsView ||
                        can.integrationsManage ||
                        can.commandsAdmin ||
                        can.groupsManage ||
                        can.reportsView ||
                        can.monitoringManage,
                    ),
            },
        ],
    },
];

export function securityDevicesWorkspaceLabel(
    slug: string,
): string | undefined {
    if (slug in legacyWorkspaceLabels) {
        return legacyWorkspaceLabels[slug];
    }

    const href = `/security-devices/${slug}`;
    const item = navigationGroups
        .flatMap((group) => group.items)
        .find((entry) => entry.href === href || entry.aliases?.includes(href));

    return item?.title;
}

export function buildSecurityDevicesNavigationGroups(
    can: SecurityDevicesPermissions = {},
): SecurityDevicesNavigationGroup[] {
    return navigationGroups
        .map((group) => ({
            ...group,
            items: group.items
                .filter((item) => item.visible(can))
                .map((item) => ({
                    ...item,
                    href: item.resolveHref ? item.resolveHref(can) : item.href,
                })),
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

function isApprovedScriptsPath(path: string): boolean {
    return (
        path === '/security-devices/scripts' ||
        path.startsWith('/security-devices/scripts/')
    );
}

export function isSecurityDevicesDestinationActive(
    currentUrl: string,
    destinationHref: string,
): boolean {
    const currentPath = normalizePath(currentUrl);
    const destinationPath = normalizePath(destinationHref);

    if (isApprovedScriptsPath(currentPath)) {
        return false;
    }

    const destination = navigationGroups
        .flatMap((group) => group.items)
        .find(
            (item) =>
                normalizePath(item.href) === destinationPath ||
                item.hubHrefs?.some(
                    (hub) => normalizePath(hub) === destinationPath,
                ),
        );

    if (pathMatches(currentPath, destinationPath)) {
        return true;
    }

    return Boolean(
        destination?.aliases?.some((alias) =>
            pathMatches(currentPath, normalizePath(alias)),
        ),
    );
}

export function securityDevicesPrimaryLinkActive(
    currentPath: string,
    itemPath: string,
): boolean | undefined {
    const normalizedItemPath = normalizePath(itemPath);
    const isHubEntry = navigationGroups
        .flatMap((group) => group.items)
        .some((item) =>
            (item.hubHrefs ?? [item.href]).some(
                (hub) => normalizePath(hub) === normalizedItemPath,
            ),
        );

    if (!isHubEntry) {
        return undefined;
    }

    return isSecurityDevicesDestinationActive(currentPath, normalizedItemPath);
}

export const securityDevicesNavigationGroups = navigationGroups;
