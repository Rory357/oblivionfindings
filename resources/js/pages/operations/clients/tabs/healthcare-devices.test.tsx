import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    ClientHealthcareDevicesTab,
    type ClientHealthcareDevicesProjection,
} from './healthcare-devices';

const projection: ClientHealthcareDevicesProjection = {
    boundary: {
        title: 'Technical device status only',
        description:
            'This projection never includes readings, vital values, thresholds, diagnoses, medications, or clinical notes.',
    },
    summary: {
        total: 1,
        offline: 1,
        data_flow_issues: 0,
        overdue_calibration: 1,
        maintenance_due: 1,
    },
    devices: [
        {
            id: 73,
            name: 'Mere bed sensor',
            category: 'bed_sensor',
            subcategory: 'pressure_mat',
            provider: 'milesight',
            status: 'offline',
            health: 'critical',
            last_seen_at: '2026-07-24T08:00:00Z',
            href: '/security-devices/devices/73',
            assignment: {
                type: 'client',
                assignmentType: 'permanent',
                label: 'Assigned to Mere',
                assignedAt: '2026-07-23T08:00:00Z',
            },
            technical: {
                battery: {
                    level: 64,
                    updatedAt: '2026-07-24T08:00:00Z',
                    state: 'ok',
                },
                connectivity: {
                    state: 'offline',
                    source: 'allowlisted_integration_evidence',
                },
                integration: {
                    state: 'healthy',
                    source: 'allowlisted_integration_evidence',
                },
                delivery: {
                    state: 'fresh',
                    lastSuccessfulAt: '2026-07-24T08:00:00Z',
                    staleAfterMinutes: 30,
                },
                flow: {
                    state: 'offline',
                    label: 'Offline',
                    description: 'The canonical device state is offline.',
                },
            },
            monitoring: { state: 'configured', enabledCount: 2 },
            maintenance: {
                nextServiceDue: '2026-07-25',
                openCount: 1,
                overdueCount: 1,
                next: null,
            },
            it_tickets: [
                {
                    id: 91,
                    reference: 'IT-000091',
                    title: 'Restore delivery',
                    status: 'open',
                    href: '/it/tickets/91',
                },
            ],
        },
    ],
    truncated: false,
    permissions: {
        clientContext: true,
        maintenance: true,
        it: true,
    },
    links: {
        healthcare: '/security-devices/healthcare?tab=client-devices',
        clinical: '/operations/clients/17?tab=health_monitoring',
    },
};

describe('Client healthcare devices projection', () => {
    it('keeps technical operations distinct from clinical monitoring', () => {
        render(<ClientHealthcareDevicesTab data={projection} />);

        expect(screen.getByText('Healthcare devices')).toBeVisible();
        expect(screen.getByText('Technical device status only')).toBeVisible();
        expect(screen.getByText('Mere bed sensor')).toBeVisible();
        expect(screen.getByText('Connectivity: offline')).toBeVisible();
        expect(screen.getByText(/1 maintenance · 1 IT/i)).toBeVisible();
        expect(
            screen.getByRole('link', { name: /open device/i }),
        ).toHaveAttribute('href', '/security-devices/devices/73');
        expect(
            screen.getByRole('link', { name: /open health monitoring/i }),
        ).toHaveAttribute(
            'href',
            '/operations/clients/17?tab=health_monitoring',
        );
        expect(screen.queryByText('Clinical reading')).not.toBeInTheDocument();
    });

    it('shows an honest empty state and withholds a clinical link when restricted', () => {
        render(
            <ClientHealthcareDevicesTab
                data={{
                    ...projection,
                    summary: {
                        total: 0,
                        offline: 0,
                        data_flow_issues: 0,
                        overdue_calibration: null,
                        maintenance_due: null,
                    },
                    devices: [],
                    links: { ...projection.links, clinical: null },
                }}
            />,
        );

        expect(
            screen.getByText('No healthcare devices assigned'),
        ).toBeVisible();
        expect(screen.getByText('Restricted')).toBeVisible();
        expect(
            screen.queryByRole('link', { name: /open health monitoring/i }),
        ).not.toBeInTheDocument();
    });
});
