import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    HealthcareWorkspacePanels,
    type HealthcareWorkspaceData,
} from './healthcare-workspace';

const base: HealthcareWorkspaceData = {
    permissions: {
        clientContext: true,
        clinicalMonitoring: true,
        maintenance: true,
        it: true,
    },
    boundary: {
        title: 'Technical device operations only',
        description:
            'Clinical readings, thresholds, diagnoses, medications, and clinical review stay in Client Health Monitoring.',
        clinicalHref: '/health-clinical/health-monitoring',
    },
    overview: {
        inventory: {
            total: 7,
            client_assigned: 3,
            shared_site: 2,
            unassigned: 2,
        },
        attention: {
            offline: 1,
            data_flow_issues: 3,
            overdue_calibration: 1,
            maintenance_due: 2,
        },
        requiredActions: [
            {
                key: 'offline_devices',
                label: 'Offline healthcare devices',
                count: 1,
                description: 'Investigate offline devices.',
                href: '/security-devices/devices?domain=iot_healthcare&status=offline',
            },
            {
                key: 'overdue_calibration',
                label: 'Overdue calibration',
                count: 1,
                description: 'Complete canonical calibration work.',
                href: '/security-devices/maintenance?status=overdue&type=calibration&domain=iot_healthcare',
            },
        ],
    },
    activeTab: {
        key: 'overview',
        label: 'Overview',
        description: 'Technical posture.',
        restricted: false,
        inventoryTotal: 7,
        inventoryShown: 0,
        inventoryTruncated: false,
        devices: [],
        flowGroups: [],
        maintenanceRecords: [],
    },
};

const clientDevice = {
    id: 11,
    name: 'Mere wearable',
    category: 'fall_detection',
    subcategory: 'wearable_fall',
    provider: 'native',
    status: 'active',
    health: 'healthy',
    lastSeenAt: '2026-07-19T03:00:00.000Z',
    deviceHref: '/security-devices/devices/11',
    client: {
        id: 4,
        displayName: 'Mere',
        href: '/clients/4',
    },
    location: null,
    assignment: {
        type: 'client',
        assignmentType: 'permanent',
        label: 'Assigned to Mere',
        assignedAt: '2026-07-18T03:00:00.000Z',
    },
    supportContact: {
        name: 'Aroha Support',
        role: 'key worker',
    },
    technical: {
        battery: {
            level: 72,
            updatedAt: '2026-07-19T02:55:00.000Z',
            state: 'ok',
        },
        connectivity: {
            state: 'connected',
            source: 'allowlisted_integration_evidence',
        },
        integration: {
            state: 'healthy',
            source: 'allowlisted_integration_evidence',
        },
        delivery: {
            state: 'fresh',
            lastSuccessfulAt: '2026-07-19T02:57:00.000Z',
            staleAfterMinutes: 30,
        },
        flow: {
            state: 'healthy',
            label: 'Healthy flow',
            description: 'Positive technical evidence.',
        },
    },
    monitoring: {
        state: 'configured',
        enabledCount: 2,
    },
    maintenance: {
        nextServiceDue: '2026-08-01T00:00:00.000Z',
        openCount: 1,
        overdueCount: 0,
        next: null,
    },
    itTickets: [
        {
            id: 9,
            reference: 'IT-000009',
            title: 'Restore device delivery',
            status: 'open',
            href: '/it/tickets/9',
        },
    ],
} satisfies HealthcareWorkspaceData['activeTab']['devices'][number];

describe('HealthcareWorkspacePanels', () => {
    it('leads with assignment, technical attention, and the clinical boundary', () => {
        render(<HealthcareWorkspacePanels data={base} />);

        expect(
            screen.getByRole('heading', {
                name: 'Healthcare devices at a glance',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('3 client assigned')).toBeInTheDocument();
        expect(screen.getByText('2 shared or site')).toBeInTheDocument();
        expect(screen.getByText('2 unassigned')).toBeInTheDocument();
        expect(
            screen.getByRole('heading', {
                name: 'Technical device operations only',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Open Client Health Monitoring' }),
        ).toHaveAttribute('href', '/health-clinical/health-monitoring');
        expect(
            screen.getByRole('link', { name: /Offline healthcare devices/ }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices?domain=iot_healthcare&status=offline',
        );
    });

    it('explains the clinical boundary without exposing a dead link when access is restricted', () => {
        render(
            <HealthcareWorkspacePanels
                data={{
                    ...base,
                    permissions: {
                        ...base.permissions,
                        clinicalMonitoring: false,
                    },
                    boundary: {
                        ...base.boundary,
                        clinicalHref: null,
                    },
                }}
            />,
        );

        expect(
            screen.queryByRole('link', {
                name: 'Open Client Health Monitoring',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Client Health Monitoring access required'),
        ).toBeInTheDocument();
    });

    it('shows minimum client identity and technical support context with authorised IT links', () => {
        render(
            <HealthcareWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'client-devices',
                        label: 'Client devices',
                        inventoryTotal: 1,
                        inventoryShown: 1,
                        devices: [clientDevice],
                    },
                }}
            />,
        );

        const card = screen.getByRole('article', { name: 'Mere wearable' });
        expect(
            within(card).getByRole('link', { name: 'Mere' }),
        ).toHaveAttribute('href', '/clients/4');
        expect(within(card).getByText('Aroha Support')).toBeInTheDocument();
        expect(within(card).getByText('72% battery')).toBeInTheDocument();
        expect(within(card).getByText('Connected')).toBeInTheDocument();
        expect(within(card).getByText('Healthy flow')).toBeInTheDocument();
        expect(
            within(card).getByRole('link', {
                name: /IT-000009 Restore device delivery/,
            }),
        ).toHaveAttribute('href', '/it/tickets/9');
        expect(
            within(card).queryByText(/123 bpm|diabetes|warfarin/i),
        ).not.toBeInTheDocument();
    });

    it('makes shared site responsibility explicit without implying a client assignment', () => {
        render(
            <HealthcareWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'shared-site-devices',
                        label: 'Shared & site devices',
                        inventoryTotal: 1,
                        inventoryShown: 1,
                        devices: [
                            {
                                ...clientDevice,
                                id: 12,
                                name: 'Shared occupancy sensor',
                                deviceHref: '/security-devices/devices/12',
                                client: null,
                                location: {
                                    site: {
                                        id: 8,
                                        name: 'Kauri House',
                                        href: '/sites/8',
                                        access: {
                                            state: 'available',
                                            label: 'Open Site profile',
                                        },
                                    },
                                    room: null,
                                },
                                assignment: {
                                    type: 'site',
                                    assignmentType: 'shared',
                                    label: 'Shared at Kauri House',
                                    assignedAt: null,
                                },
                                supportContact: {
                                    name: 'Kauri Site Lead',
                                    role: 'site primary contact',
                                },
                            },
                        ],
                    },
                }}
            />,
        );

        const card = screen.getByRole('article', {
            name: 'Shared occupancy sensor',
        });
        expect(
            within(card).getByText('Shared at Kauri House'),
        ).toBeInTheDocument();
        expect(
            within(card).getByRole('link', { name: 'Kauri House' }),
        ).toHaveAttribute('href', '/sites/8');
        expect(within(card).getByText('Kauri Site Lead')).toBeInTheDocument();
        expect(
            within(card).queryByText(/client assigned/i),
        ).not.toBeInTheDocument();
    });

    it('retains safe Site context without a link when Site profile access is restricted', () => {
        render(
            <HealthcareWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'shared-site-devices',
                        label: 'Shared & site devices',
                        inventoryTotal: 1,
                        inventoryShown: 1,
                        devices: [
                            {
                                ...clientDevice,
                                id: 13,
                                name: 'Restricted occupancy sensor',
                                deviceHref: '/security-devices/devices/13',
                                client: null,
                                location: {
                                    site: {
                                        id: 9,
                                        name: 'Restricted House',
                                        href: null,
                                        access: {
                                            state: 'restricted',
                                            label: 'Site profile access required',
                                        },
                                    },
                                    room: null,
                                },
                            },
                        ],
                    },
                }}
            />,
        );

        const card = screen.getByRole('article', {
            name: 'Restricted occupancy sensor',
        });
        expect(within(card).getByText('Restricted House')).toBeInTheDocument();
        expect(
            within(card).getByText('Site profile access required'),
        ).toBeInTheDocument();
        expect(
            within(card).queryByRole('link', { name: 'Restricted House' }),
        ).not.toBeInTheDocument();
    });

    it('separates every technical flow state and never calls unsupported monitoring healthy', () => {
        render(
            <HealthcareWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'data-flow',
                        label: 'Connectivity & data flow',
                        flowGroups: [
                            {
                                state: 'offline',
                                label: 'Offline',
                                description: 'Offline evidence.',
                                count: 1,
                                deviceIds: [1],
                            },
                            {
                                state: 'integration_failure',
                                label: 'Integration failure',
                                description: 'Integration error.',
                                count: 1,
                                deviceIds: [2],
                            },
                            {
                                state: 'stale_delivery',
                                label: 'Stale delivery',
                                description: 'Delivery is stale.',
                                count: 1,
                                deviceIds: [3],
                            },
                            {
                                state: 'unsupported',
                                label: 'Monitoring unsupported',
                                description: 'No supported evidence.',
                                count: 1,
                                deviceIds: [4],
                            },
                            {
                                state: 'healthy',
                                label: 'Healthy flow',
                                description: 'Positive evidence.',
                                count: 1,
                                deviceIds: [5],
                            },
                        ],
                    },
                }}
            />,
        );

        for (const label of [
            'Offline',
            'Integration failure',
            'Stale delivery',
            'Monitoring unsupported',
            'Healthy flow',
        ]) {
            expect(
                screen.getByRole('heading', { name: label }),
            ).toBeInTheDocument();
        }
        const unsupported = screen
            .getByRole('heading', { name: 'Monitoring unsupported' })
            .closest('article');
        expect(unsupported).not.toBeNull();
        expect(
            within(unsupported!).queryByText('Healthy'),
        ).not.toBeInTheDocument();
    });

    it('shows canonical calibration records and an honest restricted state', () => {
        const { rerender } = render(
            <HealthcareWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'calibration-maintenance',
                        label: 'Calibration & maintenance',
                        maintenanceRecords: [
                            {
                                id: 3,
                                type: 'calibration',
                                status: 'scheduled',
                                description: 'Annual calibration',
                                scheduledFor: '2026-07-18T00:00:00.000Z',
                                completedAt: null,
                                vendorReference: 'CAL-100',
                                overdue: true,
                                device: {
                                    id: 11,
                                    name: 'Mere wearable',
                                    href: '/security-devices/devices/11',
                                },
                            },
                        ],
                    },
                }}
            />,
        );

        expect(screen.getByText('Annual calibration')).toBeInTheDocument();
        expect(screen.getByText('CAL-100')).toBeInTheDocument();
        expect(screen.getByText('Overdue')).toBeInTheDocument();

        rerender(
            <HealthcareWorkspacePanels
                data={{
                    ...base,
                    permissions: { ...base.permissions, clientContext: false },
                    activeTab: {
                        ...base.activeTab,
                        key: 'client-devices',
                        label: 'Client devices',
                        restricted: true,
                        inventoryTotal: 0,
                    },
                }}
            />,
        );
        expect(
            screen.getByText(
                'Client-device context is restricted by permission.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/no client devices/i),
        ).not.toBeInTheDocument();
    });
});
