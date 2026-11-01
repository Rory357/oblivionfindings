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
    monitoringManage: true,
    reportsView: true,
    commandsAdmin: true,
    alertsAcknowledge: true,
    accessControlView: true,
    setupOpen: true,
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

    it('uses the six menu entries in exact order (C6, the menu switch)', () => {
        const groups = buildSecurityDevicesNavigationGroups(allPermissions);

        expect(groups.map((group) => group.label)).toEqual([
            'Security & Devices',
        ]);
        expect(groups[0]?.items.map((item) => [item.title, item.href])).toEqual(
            [
                ['Overview', '/security-devices'],
                ['Devices', '/security-devices/devices'],
                ['Sites', '/security-devices/sites'],
                ['Access', '/security-devices/access'],
                ['Alerts', '/security-devices/alerts'],
                ['Setup', '/security-devices/setup'],
            ],
        );
    });

    it('never points an entry at a retired page', () => {
        const retired = [
            '/network-it',
            '/security?',
            '/healthcare',
            '/tracking',
            '/facilities-iot',
            '/device-groups',
            '/command-batches',
            '/alerts-events',
            '/monitoring',
            '/maintenance',
            '/discovery',
            '/settings',
        ];
        const shapes = [
            allPermissions,
            { viewAny: true, devicesView: true, maintenanceView: true, maintenanceManage: true },
            { viewAny: true, reportsView: true, setupOpen: true },
            { viewAny: true, eventsView: true },
        ];

        for (const can of shapes) {
            for (const item of buildSecurityDevicesNavigationGroups(can)[0]?.items ?? []) {
                for (const path of retired) {
                    expect(item.href).not.toContain(`/security-devices${path}`);
                }
            }
        }
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

        // Upkeep moved to Devices › Upkeep: a maintenance-only person no longer gets an Alerts entry.
        const maintenanceOnly = buildSecurityDevicesNavigationGroups({
            viewAny: true,
            devicesView: true,
            maintenanceView: true,
            maintenanceManage: true,
        });
        expect(
            maintenanceOnly[0]?.items.map((item) => item.title),
        ).toEqual(['Overview', 'Devices', 'Sites']);

        const alertsOnly = buildSecurityDevicesNavigationGroups({
            alertsAcknowledge: true,
        });
        expect(
            alertsOnly[0]?.items.map((item) => [item.title, item.href]),
        ).toEqual([['Alerts', '/security-devices/alerts']]);

        const collectorManager = buildSecurityDevicesNavigationGroups({
            integrationsManage: true,
        });
        expect(
            collectorManager[0]?.items.map((item) => [item.title, item.href]),
        ).toEqual([['Setup', '/security-devices/setup']]);

        // The old Settings & audit readers reach Setup › Change history.
        const historyReader = buildSecurityDevicesNavigationGroups({
            reportsView: true,
            setupOpen: true,
        });
        expect(
            historyReader[0]?.items.map((item) => [item.title, item.href]),
        ).toEqual([['Setup', '/security-devices/setup']]);

        // The server's answer wins over the fallback.
        expect(
            buildSecurityDevicesNavigationGroups({ reportsView: true, setupOpen: false }),
        ).toEqual([]);

        // IT admins who can't open Setup still reach the Obli downloads (kept until pop-up 23 has a place).
        const downloadsOnly = buildSecurityDevicesNavigationGroups({
            commandsAdmin: true,
            setupOpen: false,
        });
        expect(
            downloadsOnly[0]?.items.map((item) => [item.title, item.href]),
        ).toEqual([['Setup', '/security-devices/agent-downloads']]);
    });

    it('matches hub pages and the pages still under an entry without making Overview greedy', () => {
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices',
                '/security-devices',
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
                '/security-devices/scans/7',
                '/security-devices/devices',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/integrations/queclink',
                '/security-devices/setup',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/agent-downloads',
                '/security-devices/setup',
            ),
        ).toBe(true);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/alerts',
                '/security-devices/devices',
            ),
        ).toBe(false);
        expect(
            isSecurityDevicesDestinationActive(
                '/security-devices/sites/3',
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
