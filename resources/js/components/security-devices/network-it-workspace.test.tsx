import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    NetworkItWorkspacePanels,
    type NetworkItWorkspaceData,
} from './network-it-workspace';

const base: NetworkItWorkspaceData = {
    permissions: { viewItWork: true },
    boundary: {
        title: 'Native monitoring, honest evidence',
        description:
            'Oblivion Findings presents retained native observations and known topology evidence.',
        collectionNote:
            'Missing collection stays visible as not collected or unsupported.',
        managementNote:
            'Configuration and firmware are read-only until governed command workflows are enabled.',
    },
    overview: {
        inventory: {
            devices: 8,
            sites: 3,
            wan_paths: 2,
            monitored_devices: 6,
            unmonitored_devices: 2,
        },
        monitoring: {
            enabled: 10,
            healthy: 7,
            attention: 2,
            uncertain: 1,
        },
        evidence: {
            topology_edges: 5,
            interfaces: 4,
            capacity_series: 3,
            configuration: 2,
            firmware: 3,
        },
        attention: {
            devices: 1,
            monitoring: 2,
            capacity: 1,
            configuration: 1,
            firmware: 1,
            open_work: 2,
        },
        requiredActions: [
            {
                key: 'monitoring',
                label: 'Monitor failures or degradation',
                count: 2,
                description: 'Review failed and degraded checks.',
                href: '/security-devices/network-it?tab=services&attention=monitoring',
            },
        ],
        sites: [
            {
                id: 4,
                name: 'Kauri House',
                href: '/security-devices/sites/4',
                devices: 4,
                monitoredDevices: 3,
                attention: 1,
            },
        ],
        wanPaths: [
            {
                id: 11,
                name: 'Kauri SD-WAN gateway',
                site: 'Kauri House',
                state: 'healthy',
                lastSeenAt: '2026-07-19T05:00:00.000Z',
                href: '/security-devices/devices/11',
            },
        ],
        itWork: [
            {
                id: 9,
                reference: 'IT-000009',
                title: 'Investigate WAN capacity',
                status: 'open',
                href: '/it/tickets/9',
            },
        ],
    },
    activeTab: {
        key: 'overview',
        label: 'Overview',
        description: 'Network and IT posture.',
        inventoryTotal: 8,
        inventoryShown: 8,
        inventoryTruncated: false,
        devices: [],
        topology: {
            state: 'partial',
            label: 'Known topology is incomplete',
            nodeCount: 3,
            edgeCount: 1,
            unlinkedCount: 1,
            nodes: [],
            edges: [],
        },
        interfaces: [],
        services: [],
        traffic: [],
        configuration: [],
        gaps: {
            devicesWithoutMonitors: 2,
            devicesWithoutInterfaceEvidence: 4,
            devicesWithoutCapacityEvidence: 5,
            devicesWithoutConfigurationEvidence: 6,
            devicesWithoutFirmwareEvidence: 5,
            devicesWithoutServiceChecks: 2,
        },
    },
};

describe('NetworkItWorkspacePanels', () => {
    it('leads with SD-WAN, monitoring coverage, required action and the native evidence boundary', () => {
        render(<NetworkItWorkspacePanels data={base} />);

        expect(
            screen.getByRole('heading', {
                name: 'Network operations at a glance',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('8 devices')).toBeInTheDocument();
        expect(screen.getByText('3 authorised sites')).toBeInTheDocument();
        expect(screen.getByText('2 WAN paths identified')).toBeInTheDocument();
        expect(screen.getByText('6 monitored devices')).toBeInTheDocument();
        expect(
            screen.getByRole('heading', {
                name: 'Native monitoring, honest evidence',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: /Monitor failures or degradation/,
            }),
        ).toHaveAttribute(
            'href',
            '/security-devices/network-it?tab=services&attention=monitoring',
        );
        expect(
            screen.getByRole('link', { name: /Kauri SD-WAN gateway/ }),
        ).toHaveAttribute('href', '/security-devices/devices/11');
        expect(screen.getByRole('link', { name: /IT-000009/ })).toHaveAttribute(
            'href',
            '/it/tickets/9',
        );
    });

    it('renders known topology while making incomplete discovery unmistakable', () => {
        render(
            <NetworkItWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'map',
                        label: 'Map',
                        topology: {
                            state: 'partial',
                            label: 'Known topology is incomplete',
                            nodeCount: 3,
                            edgeCount: 1,
                            unlinkedCount: 1,
                            nodes: [
                                {
                                    id: 11,
                                    name: 'Edge gateway',
                                    category: 'network',
                                    subcategory: 'edge_router',
                                    health: 'healthy',
                                    site: 'Kauri House',
                                    href: '/security-devices/devices/11',
                                },
                                {
                                    id: 12,
                                    name: 'Core switch',
                                    category: 'network',
                                    subcategory: 'managed_switch',
                                    health: 'warning',
                                    site: 'Kauri House',
                                    href: '/security-devices/devices/12',
                                },
                            ],
                            edges: [
                                {
                                    id: 3,
                                    parentId: 11,
                                    parentName: 'Edge gateway',
                                    childId: 12,
                                    childName: 'Core switch',
                                    type: 'uplinks_to',
                                    label: 'Uplinks to',
                                    port: 'WAN1',
                                },
                            ],
                        },
                    },
                }}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Known topology evidence' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Known topology is incomplete'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: /^Edge gatewayKauri House · Healthy$/,
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: /^Core switchKauri House · Warning$/,
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('WAN1')).toBeInTheDocument();
        expect(
            screen.getByText('1 device has no known relationship'),
        ).toBeInTheDocument();
    });

    it('links an infrastructure device and its canonical Site workspace', () => {
        render(
            <NetworkItWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'devices',
                        label: 'Devices',
                        devices: [
                            {
                                id: 21,
                                name: 'Kauri core switch',
                                category: 'networking',
                                subcategory: 'managed_switch',
                                status: 'active',
                                health: 'healthy',
                                lastSeenAt: '2026-07-19T05:00:00.000Z',
                                href: '/security-devices/devices/21',
                                site: {
                                    id: 4,
                                    name: 'Kauri House',
                                    href: '/security-devices/sites/4',
                                },
                                identifiers: {
                                    ipAddress: '10.0.0.21',
                                    macAddress: null,
                                    serialNumber: null,
                                },
                                firmwareVersion: '1.4.0',
                                monitoring: {
                                    enabled: 2,
                                    attention: 0,
                                    uncertain: 0,
                                },
                                wanPath: false,
                            },
                        ],
                    },
                }}
            />,
        );

        const card = screen.getByRole('article', {
            name: 'Kauri core switch',
        });
        expect(
            within(card).getByRole('link', { name: 'Kauri core switch' }),
        ).toHaveAttribute('href', '/security-devices/devices/21');
        expect(
            within(card).getByRole('link', { name: 'Kauri House' }),
        ).toHaveAttribute('href', '/security-devices/sites/4');
    });

    it('shows allowlisted interface and retained capacity observations with visible gaps', () => {
        const network: NetworkItWorkspaceData = {
            ...base,
            activeTab: {
                ...base.activeTab,
                key: 'interfaces',
                label: 'Interfaces',
                interfaces: [
                    {
                        monitorId: 4,
                        deviceId: 12,
                        deviceName: 'Core switch',
                        deviceHref: '/security-devices/devices/12',
                        name: 'WAN 1',
                        index: 7,
                        state: 'healthy',
                        enabled: true,
                        adminStatus: 'up',
                        operationalStatus: 'up',
                        speedBps: 1_000_000_000,
                        inBps: 850_000_000,
                        outBps: 620_000_000,
                        inUtilisation: 85,
                        outUtilisation: 62,
                        errors: 12,
                        discards: 3,
                        capacityState: 'warning',
                        observedAt: '2026-07-19T05:00:00.000Z',
                    },
                ],
            },
        };

        const { rerender } = render(
            <NetworkItWorkspacePanels data={network} />,
        );
        const row = screen.getByRole('article', {
            name: 'WAN 1 on Core switch',
        });
        expect(within(row).getByText('85% inbound')).toBeInTheDocument();
        expect(within(row).getByText('62% outbound')).toBeInTheDocument();
        expect(within(row).getByText('1 Gbps')).toBeInTheDocument();
        expect(
            screen.getByText('4 devices have no interface evidence'),
        ).toBeInTheDocument();

        rerender(
            <NetworkItWorkspacePanels
                data={{
                    ...network,
                    activeTab: {
                        ...network.activeTab,
                        key: 'traffic-capacity',
                        label: 'Traffic & capacity',
                        traffic: [
                            {
                                monitorId: 4,
                                deviceId: 12,
                                deviceName: 'Core switch',
                                deviceHref: '/security-devices/devices/12',
                                interface: 'WAN 1',
                                speedBps: 1_000_000_000,
                                inBps: 850_000_000,
                                outBps: 620_000_000,
                                inUtilisation: 85,
                                outUtilisation: 62,
                                state: 'warning',
                                observedAt: '2026-07-19T05:00:00.000Z',
                                source: 'retained_native_observation',
                            },
                        ],
                    },
                }}
            />,
        );
        expect(
            screen.getByText('Retained native observation'),
        ).toBeInTheDocument();
        expect(screen.getByText('Capacity warning')).toBeInTheDocument();
    });

    it('shows service coverage and dependency context without provider configuration', () => {
        render(
            <NetworkItWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'services',
                        label: 'Services',
                        services: [
                            {
                                id: 20,
                                deviceId: 11,
                                deviceName: 'Edge gateway',
                                deviceHref: '/security-devices/devices/11',
                                name: 'Client portal HTTPS',
                                kind: 'http',
                                kindLabel: 'HTTP',
                                state: 'failed',
                                enabled: true,
                                affectsAvailability: true,
                                lastObservationAt: '2026-07-19T05:00:00.000Z',
                                dependentCount: 2,
                                collector: null,
                            },
                        ],
                    },
                }}
            />,
        );

        const row = screen.getByRole('article', {
            name: 'Client portal HTTPS',
        });
        expect(within(row).getByText('Failed')).toBeInTheDocument();
        expect(within(row).getByText('2 known dependants')).toBeInTheDocument();
        expect(
            screen.getByText('2 devices have no service checks'),
        ).toBeInTheDocument();
    });

    it('keeps configuration and firmware evidence read-only and labels unsupported devices', () => {
        render(
            <NetworkItWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'configuration-firmware',
                        label: 'Configuration & firmware',
                        configuration: [
                            {
                                deviceId: 31,
                                deviceName: 'Drifted firewall',
                                deviceHref: '/security-devices/devices/31',
                                configuration: {
                                    state: 'drifted',
                                    observedHash: 'observed-hash',
                                    desiredHash: 'desired-hash',
                                    observedAt: '2026-07-19T04:00:00.000Z',
                                },
                                firmware: {
                                    state: 'update_available',
                                    currentVersion: '1.4.0',
                                    desiredVersion: '1.5.0',
                                    observedAt: '2026-07-19T03:00:00.000Z',
                                },
                                latestSnapshot: {
                                    id: 902,
                                    sourceKind: 'provider',
                                    source: 'unifi',
                                    capturedAt: '2026-07-19T04:00:00.000Z',
                                    contentHash: 'a'.repeat(64),
                                    configurationHash: 'b'.repeat(64),
                                    contentSize: 2048,
                                    mimeType: 'application/json',
                                    firmwareVersion: '1.4.0',
                                    storageState: 'available',
                                    previousSnapshotId: 901,
                                    diff: {
                                        added: ['configuration.services.https'],
                                        removed: [],
                                        changed: [
                                            'configuration.interfaces.wan.mtu',
                                        ],
                                        count: 2,
                                        truncated: false,
                                    },
                                    downloadHref:
                                        '/security-devices/devices/31/configuration-snapshots/902',
                                },
                                snapshotHistory: [
                                    {
                                        id: 902,
                                        sourceKind: 'provider',
                                        source: 'unifi',
                                        capturedAt: '2026-07-19T04:00:00.000Z',
                                        contentHash: 'a'.repeat(64),
                                        configurationHash: 'b'.repeat(64),
                                        contentSize: 2048,
                                        mimeType: 'application/json',
                                        firmwareVersion: '1.4.0',
                                        storageState: 'available',
                                        previousSnapshotId: 901,
                                        diff: {
                                            added: [
                                                'configuration.services.https',
                                            ],
                                            removed: [],
                                            changed: [
                                                'configuration.interfaces.wan.mtu',
                                            ],
                                            count: 2,
                                            truncated: false,
                                        },
                                        downloadHref:
                                            '/security-devices/devices/31/configuration-snapshots/902',
                                    },
                                    {
                                        id: 901,
                                        sourceKind: 'provider',
                                        source: 'unifi',
                                        capturedAt: '2026-07-17T04:00:00.000Z',
                                        contentHash: 'c'.repeat(64),
                                        configurationHash: 'd'.repeat(64),
                                        contentSize: 1900,
                                        mimeType: 'application/json',
                                        firmwareVersion: '1.3.0',
                                        storageState: 'available',
                                        previousSnapshotId: null,
                                        diff: {
                                            added: [],
                                            removed: [],
                                            changed: [],
                                            count: 0,
                                            truncated: false,
                                        },
                                        downloadHref:
                                            '/security-devices/devices/31/configuration-snapshots/901',
                                    },
                                ],
                                snapshotHistoryTruncated: true,
                            },
                            {
                                deviceId: 32,
                                deviceName: 'Basic printer',
                                deviceHref: '/security-devices/devices/32',
                                configuration: {
                                    state: 'not_observed',
                                    observedHash: null,
                                    desiredHash: null,
                                    observedAt: null,
                                },
                                firmware: {
                                    state: 'not_observed',
                                    currentVersion: null,
                                    desiredVersion: null,
                                    observedAt: null,
                                },
                            },
                        ],
                    },
                }}
            />,
        );

        expect(screen.getByText('Configuration drift')).toBeInTheDocument();
        expect(screen.getByText('Update available')).toBeInTheDocument();
        const drifted = screen.getByRole('article', {
            name: 'Drifted firewall',
        });
        expect(
            within(drifted).getByText(
                /Compared with snapshot #901: 1 added, 1 changed/,
            ),
        ).toBeInTheDocument();
        expect(
            within(drifted).getByText(/View previous governed snapshots \(1\)/),
        ).toBeInTheDocument();
        expect(
            within(drifted).getByText(
                /Additional retained snapshots exist outside this bounded Site history/,
            ),
        ).toBeInTheDocument();
        expect(
            within(drifted).getByRole('link', {
                name: 'Download governed snapshot',
            }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices/31/configuration-snapshots/902',
        );
        const unsupported = screen.getByRole('article', {
            name: 'Basic printer',
        });
        expect(
            within(unsupported).getAllByText('Not observed').length,
        ).toBeGreaterThanOrEqual(2);
        expect(
            screen.getByText(/read-only until governed command workflows/i),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /apply|upgrade|push/i }),
        ).not.toBeInTheDocument();
    });
});
