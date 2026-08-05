import { describe, expect, it } from 'vitest';

import {
    buildSecurityDevicesNavigationGroups,
    isSecurityDevicesDestinationActive,
    securityDevicesDomainHref,
} from './security-devices-navigation';

const allPermissions = {
    viewAny: true,
    devicesView: true,
    groupsManage: true,
    eventsView: true,
    maintenanceView: true,
    maintenanceManage: true,
    integrationsView: true,
    integrationsManage: true,
    reportsView: true,
    commandsAdmin: true,
};

describe('Security & Devices navigation contract', () => {
    it('routes estate domains through their canonical workspaces', () => {
        expect(securityDevicesDomainHref('security')).toBe(
            '/security-devices/security',
        );
        expect(securityDevicesDomainHref('tracking')).toBe(
            '/security-devices/tracking',
        );
        expect(securityDevicesDomainHref('iot_healthcare')).toBe(
            '/security-devices/healthcare',
        );
        expect(securityDevicesDomainHref('it_infrastructure')).toBe(
            '/security-devices/network-it',
        );
        expect(securityDevicesDomainHref('facilities')).toBe(
            '/security-devices/facilities-iot',
        );
        expect(securityDevicesDomainHref('unknown')).toBe(
            '/security-devices/devices',
        );
    });

    it('uses the approved four groups and destination order', () => {
        const groups = buildSecurityDevicesNavigationGroups(allPermissions);

        expect(groups.map((group) => group.label)).toEqual([
            'Overview',
            'Workspaces',
            'Operations',
            'Setup',
        ]);
        expect(
            groups.map((group) =>
                group.items.map((item) => [item.title, item.href]),
            ),
        ).toEqual([
            [
                ['Estate overview', '/security-devices'],
                ['Sites', '/security-devices/sites'],
                ['All devices', '/security-devices/devices'],
            ],
            [
                ['Network & IT', '/security-devices/network-it'],
                ['Security', '/security-devices/security'],
                ['Healthcare', '/security-devices/healthcare'],
                ['Tracking', '/security-devices/tracking'],
                ['Facilities & IoT', '/security-devices/facilities-iot'],
            ],
            [
                ['Monitoring', '/security-devices/monitoring'],
                ['Maintenance', '/security-devices/maintenance'],
            ],
            [
                ['Discovery & collectors', '/security-devices/discovery'],
                ['Integrations', '/security-devices/integrations'],
                ['Settings & audit', '/security-devices/settings'],
            ],
        ]);
    });

    it('removes destinations the actor cannot view without leaving empty groups', () => {
        const groups = buildSecurityDevicesNavigationGroups({
            viewAny: true,
            devicesView: true,
        });

        expect(groups.map((group) => group.label)).toEqual([
            'Overview',
            'Workspaces',
        ]);
        expect(groups[0]?.items.map((item) => item.title)).toEqual([
            'Estate overview',
            'Sites',
            'All devices',
        ]);
        expect(groups[1]?.items.map((item) => item.title)).toEqual([
            'Network & IT',
            'Security',
            'Healthcare',
            'Tracking',
            'Facilities & IoT',
        ]);

        const operationsOnly = buildSecurityDevicesNavigationGroups({
            eventsView: true,
            maintenanceView: true,
        });
        expect(operationsOnly.map((group) => group.label)).toEqual([
            'Operations',
        ]);
        expect(operationsOnly[0]?.items.map((item) => item.title)).toEqual([
            'Monitoring',
            'Maintenance',
        ]);

        expect(
            buildSecurityDevicesNavigationGroups({
                integrationsManage: true,
            }),
        ).toEqual([]);

        const commandAdminOnly = buildSecurityDevicesNavigationGroups({
            commandsAdmin: true,
        });
        expect(commandAdminOnly.map((group) => group.label)).toEqual(['Setup']);
        expect(
            commandAdminOnly[0]?.items.map((item) => [item.title, item.href]),
        ).toEqual([['Settings & audit', '/security-devices/settings']]);
    });

    it('matches canonical and preserved legacy destinations without making the estate root greedy', () => {
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices',
                '/security-devices',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/security?tab=cctv',
                '/security-devices/security',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/cctv?site=4',
                '/security-devices/security',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/maintenance-health',
                '/security-devices/maintenance',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/alerts-events',
                '/security-devices/monitoring',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/alerts-events',
                '/security-devices/security',
            ),
        ).toBe(false);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/devices/42',
                '/security-devices/devices',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/security',
                '/security-devices',
            ),
        ).toBe(false);
    });
});
