import type { NavItem } from '@/types';
import {
    Activity,
    Cpu,
    KeyRound,
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
    alertsAcknowledge?: boolean;
    automationsManage?: boolean;
    /** S&D Phase H: the Access menu entry (pg-sdr-19). */
    accessControlView?: boolean;
    /** S&D rebuild C6: whether Setup opens for this person (SetupPresenter::canOpen). */
    setupOpen?: boolean;
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

/**
 * The six-entry menu (S&D rebuild plan v11: Overview · Devices · Sites · Access · Alerts · Setup).
 * C6, the menu switch: every old page now redirects into one of these, so no entry points at an
 * old page except Setup for IT admins who reach only the Obli downloads (kept until pop-up 23 and
 * the Hubs list have a place).
 */
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
                // Found devices (a scan's results) belongs to Devices.
                aliases: ['/security-devices/scans'],
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
                // S&D Phase H (pg-sdr-19): doors, people, visitors, schedules and the door log.
                title: 'Access',
                href: '/security-devices/access',
                hubHrefs: ['/security-devices/access'],
                icon: KeyRound,
                visible: (can) => Boolean(can.viewAny && can.accessControlView),
            },
            {
                // Only for people who can open Alerts (AlertsPresenter::canOpen). Upkeep (batteries and
                // updates) is Devices › Upkeep, so the old maintenance-only landing is gone.
                title: 'Alerts',
                href: '/security-devices/alerts',
                hubHrefs: ['/security-devices/alerts'],
                icon: Activity,
                visible: (can) => Boolean(can.eventsView || can.alertsAcknowledge),
            },
            {
                title: 'Setup',
                href: '/security-devices/setup',
                hubHrefs: ['/security-devices/setup', '/security-devices/agent-downloads'],
                resolveHref: (can) =>
                    setupOpens(can) ? '/security-devices/setup' : '/security-devices/agent-downloads',
                // Each connected service's own page opens from Setup › Connected services.
                aliases: ['/security-devices/integrations', '/security-devices/agent-downloads'],
                icon: Settings,
                visible: (can) => setupOpens(can) || Boolean(can.commandsAdmin),
            },
        ],
    },
];

/** SetupPresenter::canOpen, shared by the server; the fallback covers callers that don't send it. */
function setupOpens(can: SecurityDevicesPermissions): boolean {
    if (typeof can.setupOpen === 'boolean') {
        return can.setupOpen;
    }

    return Boolean(
        can.integrationsView ||
        can.integrationsManage ||
        can.monitoringManage ||
        can.automationsManage ||
        can.reportsView,
    );
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
