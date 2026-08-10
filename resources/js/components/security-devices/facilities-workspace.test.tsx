import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    FacilitiesWorkspacePanels,
    type FacilitiesWorkspaceData,
} from './facilities-workspace';

const device = {
    id: 11,
    name: 'Kauri cool room sensor',
    href: '/security-devices/devices/11',
    group: 'environment',
    category: 'cold_chain',
    categoryLabel: 'Cold Chain',
    subcategory: 'cool_room_sensor',
    subcategoryLabel: 'Cool Room Sensor',
    status: 'active',
    health: 'healthy',
    technicalState: 'healthy',
    site: {
        id: 4,
        name: 'Kauri House',
        href: '/security-devices/sites/4',
    },
    provider: 'milesight',
    monitoring: { enabled: 1, attention: 0, uncertain: 0 },
    unmonitored: false,
    observation: {
        value: '3.2',
        unit: 'C',
        state: 'healthy',
        observedAt: '2026-07-19T05:00:00.000Z',
        source: 'retained_native_observation',
    },
    freshness: {
        state: 'fresh',
        observedAt: '2026-07-19T05:00:00.000Z',
    },
    thresholdEvent: {
        id: 9,
        type: 'temperature_threshold_exceeded',
        label: 'Temperature Threshold Exceeded',
        severity: 'warning',
        source: 'native',
        occurredAt: '2026-07-19T04:55:00.000Z',
        processed: false,
    },
    activeEventCount: 1,
    maintenance: {
        openCount: 1,
        overdueCount: 0,
        nextDue: '2026-07-25',
        href: '/security-devices/maintenance?device_id=11',
        source: 'canonical_device_maintenance',
    },
    integration: {
        provider: 'milesight',
        name: 'Milesight IoT',
        state: 'active',
        capabilities: ['environmental', 'event_stream'],
        lastTestedAt: '2026-07-19T04:00:00.000Z',
        lastSync: {
            action: 'sync_health',
            status: 'success',
            itemsProcessed: 4,
            completedAt: '2026-07-19T04:50:00.000Z',
        },
    },
    automation: {
        name: 'Cool room ventilation',
        enabled: true,
        status: 'success',
        lastExecutedAt: '2026-07-19T04:45:00.000Z',
        source: 'allowlisted_device_automation_evidence',
    },
} satisfies FacilitiesWorkspaceData['activeTab']['environment'][number];

const base: FacilitiesWorkspaceData = {
    permissions: {
        events: true,
        maintenance: true,
        integrations: true,
        export: true,
    },
    boundary: {
        title: 'Technical facilities evidence, not building control',
        description: 'Canonical facility evidence.',
        evidenceNote: 'Missing collection stays visible.',
        managementNote: 'Building controls remain read-only.',
    },
    overview: {
        inventory: {
            devices: 4,
            environment: 1,
            building_systems: 1,
            utilities: 1,
            automations: 1,
            sites: 1,
        },
        attention: {
            devices: 1,
            monitoring: 1,
            active_events: 1,
            unmonitored: 1,
            stale: 1,
            overdue_maintenance: 1,
            integration: 0,
        },
        freshness: { fresh: 2, stale: 1, not_collected: 1 },
        requiredActions: [
            {
                key: 'active-events',
                label: 'Active facility events',
                count: 1,
                description: 'Review canonical events.',
                href: '/security-devices/facilities-iot?tab=history',
            },
        ],
        sites: [
            {
                id: 4,
                name: 'Kauri House',
                href: '/security-devices/sites/4',
                devices: 4,
                attention: 1,
                activeEvents: 1,
            },
        ],
    },
    activeTab: {
        key: 'overview',
        label: 'Overview',
        description: 'Facilities posture.',
        inventoryTruncated: false,
        devices: [],
        environment: [],
        buildingSystems: [],
        utilities: [],
        automations: [],
        history: {
            events: [],
            observations: [],
            filters: {
                kind: 'all',
                deviceId: null,
                severity: null,
                eventType: null,
                source: null,
            },
            filterOptions: {
                devices: [],
                severities: [],
                eventTypes: [],
                sources: [],
            },
            exportHref: null,
            eventAccessRestricted: false,
            deviceCount: 4,
        },
        gaps: {
            environmentWithoutReadings: 0,
            buildingSystemsUnmonitored: 1,
            utilitiesWithoutIntegrations: 0,
            automationsWithoutExecutionEvidence: 0,
        },
    },
};

function withTab(
    key: FacilitiesWorkspaceData['activeTab']['key'],
    rows: Partial<FacilitiesWorkspaceData['activeTab']> = {},
): FacilitiesWorkspaceData {
    return {
        ...base,
        activeTab: {
            ...base.activeTab,
            ...rows,
            key,
        },
    };
}

describe('FacilitiesWorkspacePanels', () => {
    it('separates operational groups and leads with evidence, attention and site impact', () => {
        render(<FacilitiesWorkspacePanels data={base} />);

        expect(
            screen.getByRole('heading', {
                name: 'Facilities operations at a glance',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Technical facilities evidence, not building control',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('1 environmental devices')).toBeInTheDocument();
        expect(screen.getByText('1 building systems')).toBeInTheDocument();
        expect(screen.getByText('1 utilities')).toBeInTheDocument();
        expect(screen.getByText('1 automations')).toBeInTheDocument();
        expect(screen.getByText('Active facility events')).toBeInTheDocument();
        expect(screen.getByText('Kauri House')).toBeInTheDocument();
    });

    it('shows environmental readings, freshness and threshold evidence without controls', () => {
        render(
            <FacilitiesWorkspacePanels
                data={withTab('environment', { environment: [device] })}
            />,
        );

        const card = screen.getByRole('article', { name: device.name });
        expect(within(card).getByText('3.2 C')).toBeInTheDocument();
        expect(within(card).getAllByText('Fresh')).toHaveLength(2);
        expect(
            within(card).getByText('Temperature Threshold Exceeded'),
        ).toBeInTheDocument();
        expect(
            within(card).getByRole('link', { name: 'Kauri House' }),
        ).toHaveAttribute('href', '/security-devices/sites/4');
        expect(
            screen.queryByRole('button', { name: /silence|reset|control/i }),
        ).not.toBeInTheDocument();
    });

    it('links building maintenance back to the canonical register', () => {
        const building = {
            ...device,
            id: 12,
            name: 'Kauri fire panel',
            group: 'building_systems',
        } satisfies typeof device;
        render(
            <FacilitiesWorkspacePanels
                data={withTab('building-systems', {
                    buildingSystems: [building],
                })}
            />,
        );

        const card = screen.getByRole('article', { name: building.name });
        expect(
            within(card).getByText('1 open maintenance item'),
        ).toBeInTheDocument();
        expect(
            within(card).getByRole('link', { name: 'Open maintenance' }),
        ).toHaveAttribute('href', '/security-devices/maintenance?device_id=11');
    });

    it('shows explicit utility integration and automation execution evidence as read-only', () => {
        const { rerender } = render(
            <FacilitiesWorkspacePanels
                data={withTab('utilities', { utilities: [device] })}
            />,
        );
        expect(screen.getByText('Milesight IoT')).toBeInTheDocument();
        expect(screen.getByText('Last sync: Success')).toBeInTheDocument();

        rerender(
            <FacilitiesWorkspacePanels
                data={withTab('automations', { automations: [device] })}
            />,
        );
        expect(screen.getByText('Cool room ventilation')).toBeInTheDocument();
        expect(screen.getByText('Last execution: Success')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /run|switch|control/i }),
        ).not.toBeInTheDocument();
    });

    it('shows filtered canonical history and only offers its authorised export', () => {
        const history = {
            ...base.activeTab.history,
            events: [
                {
                    ...device.thresholdEvent,
                    deviceId: device.id,
                    deviceName: device.name,
                    deviceHref: device.href,
                },
            ],
            observations: [
                {
                    id: 30,
                    deviceId: device.id,
                    deviceName: device.name,
                    deviceHref: device.href,
                    monitorName: 'Cool room temperature',
                    state: 'healthy',
                    value: '3.2',
                    unit: 'C',
                    observedAt: '2026-07-19T05:00:00.000Z',
                    source: 'retained_native_observation',
                },
            ],
            exportHref:
                '/security-devices/reports/events.csv?domain=facilities',
        } satisfies FacilitiesWorkspaceData['activeTab']['history'];
        render(
            <FacilitiesWorkspacePanels
                data={withTab('history', { history })}
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Canonical facility history',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Temperature Threshold Exceeded'),
        ).toBeInTheDocument();
        expect(screen.getByText('Cool room temperature')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Export events' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/reports/events.csv?domain=facilities',
        );
    });
});
