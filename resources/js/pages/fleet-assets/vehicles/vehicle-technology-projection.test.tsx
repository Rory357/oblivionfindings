import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    type VehicleTechnologyProjection,
    VehicleTechnologyProjectionPanel,
} from './vehicle-technology-projection';

const projection: VehicleTechnologyProjection = {
    boundary: {
        title: 'Technology here, vehicle operations in Fleet',
        description:
            'This is a read-only projection of installed trackers, cameras, gateways, and sensors.',
        management:
            'Device configuration and commands continue through governed Security & Devices workflows.',
    },
    summary: {
        total: 1,
        offline: 1,
        attention: 1,
        unmonitored: 0,
        monitor_alerts: 1,
        configuration_drift: 1,
        firmware_updates: 1,
        overdue_maintenance: 1,
        open_it_work: 1,
    },
    devices: [
        {
            id: 41,
            name: 'Van 12 telematics gateway',
            domain: 'tracking',
            category: 'vehicle_gateway',
            subcategory: 'telematics',
            provider: 'queclink',
            status: 'offline',
            health: 'critical',
            battery: 48,
            last_seen_at: '2026-07-24T08:00:00Z',
            href: '/security-devices/devices/41',
            installation: {
                type: 'installed_in',
                installed_at: '2026-07-20T08:00:00Z',
            },
            connectivity: { state: 'offline', label: 'Offline' },
            monitoring: {
                enabled: 2,
                attention: 1,
                uncertain: 0,
                states: [],
            },
            configuration: {
                state: 'drifted',
                observed_at: '2026-07-24T08:00:00Z',
            },
            firmware: {
                state: 'update_available',
                current_version: '1.2.0',
                desired_version: '1.3.0',
                observed_at: '2026-07-24T08:00:00Z',
            },
            maintenance: {
                open: 1,
                overdue: 1,
                next: {
                    type: 'firmware_update',
                    status: 'scheduled',
                    scheduled_for: '2026-07-23',
                },
                href: '/security-devices/maintenance?device_id=41',
            },
            it_work: {
                open: 1,
                items: [
                    {
                        id: 90,
                        reference: 'IT-000090',
                        title: 'Restore van gateway',
                        status: 'open',
                        priority: 'high',
                        href: '/it/tickets/90',
                    },
                ],
            },
        },
    ],
    truncated: false,
    permissions: { monitoring: true, maintenance: true, it_work: true },
    links: {
        tracking: '/security-devices/tracking?tab=fleet',
        devices: '/security-devices/devices',
        maintenance: '/security-devices/maintenance',
        it_work: '/it?tab=tickets',
    },
};

describe('Fleet vehicle technology projection', () => {
    it('keeps canonical technology work separate from Fleet vehicle operations', () => {
        render(
            <VehicleTechnologyProjectionPanel
                projection={projection}
                loading={false}
                failed={false}
            />,
        );

        expect(
            screen.getByText('Technology here, vehicle operations in Fleet'),
        ).toBeVisible();
        expect(screen.getByText('Van 12 telematics gateway')).toBeVisible();
        expect(screen.getByText('2 checks · 1 alert')).toBeVisible();
        expect(screen.getByText('Desired 1.3.0')).toBeVisible();
        expect(
            screen.getByText(/1 technical maintenance · 1 overdue/i),
        ).toBeVisible();
        expect(
            screen.getByText(/IT-000090 · Restore van gateway/i),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: /open device/i }),
        ).toHaveAttribute('href', '/security-devices/devices/41');
        expect(
            screen.queryByText('RAW-PROVIDER-SENTINEL'),
        ).not.toBeInTheDocument();
    });

    it('shows honest loading, denial, and empty states', () => {
        const { rerender } = render(
            <VehicleTechnologyProjectionPanel
                projection={undefined}
                loading
                failed={false}
            />,
        );
        expect(screen.getByText(/Loading canonical device/i)).toBeVisible();

        rerender(
            <VehicleTechnologyProjectionPanel
                projection={null}
                loading={false}
                failed={false}
            />,
        );
        expect(
            screen.getByText('Vehicle technology is not available'),
        ).toBeVisible();
        expect(
            screen.getByText(/current Site and source permissions/i),
        ).toBeVisible();

        rerender(
            <VehicleTechnologyProjectionPanel
                projection={{
                    ...projection,
                    devices: [],
                    summary: { ...projection.summary, total: 0 },
                }}
                loading={false}
                failed={false}
            />,
        );
        expect(
            screen.getByText('No access-approved technology is installed'),
        ).toBeVisible();
    });
});
