import { describe, expect, it } from 'vitest';

import {
    buildSecurityDevicesNavigationGroups,
    isSecurityDevicesDestinationActive,
    securityDevicesDomainHref,
    securityDevicesWorkspaceLabel,
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
    monitoringManage: true,
    reportsView: true,
    commandsAdmin: true,
};

describe('Security & Devices navigation contract', () => {
    it('routes estate domains into the single Devices list with domain filters', () => {
        expect(securityDevicesDomainHref('security')).toBe(
            '/security-devices/devices?domain=security',
        );
        expect(securityDevicesDomainHref('tracking')).toBe(
            '/security-devices/devices?domain=tracking',
        );
        expect(securityDevicesDomainHref('iot_healthcare')).toBe(
            '/security-devices/devices?domain=iot_healthcare',
        );
        expect(securityDevicesDomainHref('it_infrastructure')).toBe(
            '/security-devices/devices?domain=it_infrastructure',
        );
        expect(securityDevicesDomainHref('facilities')).toBe(
            '/security-devices/devices?domain=facilities',
        );
        expect(securityDevicesDomainHref('unknown')).toBe(
            '/security-devices/devices',
        );
    });

    it('preserves readable labels for legacy workspace slugs on retained batches', () => {
        expect(securityDevicesWorkspaceLabel('network-it')).toBe(
            'Network & IT',
        );
        expect(securityDevicesWorkspaceLabel('security')).toBe('Security');
        expect(securityDevicesWorkspaceLabel('healthcare')).toBe('Healthcare');
        expect(securityDevicesWorkspaceLabel('tracking')).toBe('Tracking');
        expect(securityDevicesWorkspaceLabel('facilities-iot')).toBe(
            'Facilities & IoT',
        );
    });

    it('uses the five menu entries in exact order', () => {
        const groups = buildSecurityDevicesNavigationGroups(allPermissions);

        expect(groups.map((group) => group.label)).toEqual([
            'Security & Devices',
        ]);
        expect(groups[0]?.items.map((item) => [item.title, item.href])).toEqual(
            [
                ['Overview', '/security-devices'],
                ['Devices', '/security-devices/devices'],
                ['Sites', '/security-devices/sites'],
                ['Alerts & upkeep', '/security-devices/monitoring'],
                ['Setup', '/security-devices/discovery'],
            ],
        );
    });

    it('removes destinations the actor cannot view and resolves permitted landing URLs', () => {
        const devicesOnly = buildSecurityDevicesNavigationGroups({
            viewAny: true,
            devicesView: true,
        });
        expect(
            devicesOnly[0]?.items.map((item) => [item.title, item.href]),
        ).toEqual([
            ['Overview', '/security-devices'],
            ['Devices', '/security-devices/devices'],
            ['Sites', '/security-devices/sites'],
        ]);

        const maintenanceOnly = buildSecurityDevicesNavigationGroups({
            maintenanceView: true,
        });
        expect(
            maintenanceOnly[0]?.items.map((item) => [item.title, item.href]),
        ).toEqual([['Alerts & upkeep', '/security-devices/maintenance']]);

        const collectorManager = buildSecurityDevicesNavigationGroups({
            integrationsManage: true,
        });
        expect(
            collectorManager[0]?.items.map((item) => [item.title, item.href]),
        ).toEqual([['Setup', '/security-devices/agent-downloads']]);

        const monitoringManagerOnly = buildSecurityDevicesNavigationGroups({
            monitoringManage: true,
        });
        expect(
            monitoringManagerOnly[0]?.items.map((item) => [
                item.title,
                item.href,
            ]),
        ).toEqual([['Setup', '/security-devices/settings']]);
    });

    it('matches hub pages and preserved legacy routes without making Overview greedy', () => {
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices',
                '/security-devices',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/security?tab=cctv',
                '/security-devices/devices',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/cctv?site=4',
                '/security-devices/devices',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/devices/42',
                '/security-devices/devices',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/maintenance-health',
                '/security-devices/monitoring',
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
                '/security-devices/integrations',
                '/security-devices/discovery',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/supported-devices',
                '/security-devices/discovery',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/settings',
                '/security-devices/agent-downloads',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/alerts-events',
                '/security-devices/devices',
            ),
        ).toBe(false);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/security',
                '/security-devices',
            ),
        ).toBe(false);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/scripts',
                '/security-devices/devices',
            ),
        ).toBe(false);
    });
});
