import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    SiteTechnologyProjectionPanel,
    type SiteTechnologyProjection,
} from './site-technology-projection';

const projection: SiteTechnologyProjection = {
    summary: {
        health: 'warning',
        devices: 2,
        attention_devices: 1,
        offline_devices: 1,
        monitored_devices: 1,
        unmonitored_devices: 1,
        coverage_percent: 50,
        failed_monitors: 1,
        active_findings: 1,
        active_control_room_alerts: null,
        open_it_work: null,
        overdue_maintenance: 1,
        collector: {
            state: 'stale',
            label: 'Collector needs attention',
            count: 1,
            last_seen_at: '2026-07-24T08:00:00Z',
        },
        last_change_at: '2026-07-24T08:05:00Z',
    },
    wan: {
        known: true,
        label: 'WAN / SD-WAN equipment identified',
        devices: [
            {
                id: 71,
                name: 'Harbour gateway',
                status: 'offline',
                health_status: 'critical',
                href: '/security-devices/devices/71',
            },
        ],
        configuration: {
            state: 'warning',
            label: 'Configuration changed on 1 WAN device since its previous governed snapshot.',
            observed_devices: 1,
            changed_devices: 1,
            total_devices: 1,
            observed_at: '2026-07-24T08:04:00Z',
            href: '/security-devices/network-it?tab=configuration-firmware',
        },
    },
    topology: { device_count: 2, edge_count: 1, is_complete: true },
    monitoring: {
        total_devices: 2,
        monitored_devices: 1,
        unmonitored_devices: 1,
        failed_monitors: 1,
        uncertain_monitors: 0,
        issues: [
            {
                id: 91,
                device_id: 71,
                device_name: 'Harbour gateway',
                name: 'WAN availability',
                kind: 'icmp',
                state: 'failed',
                last_observation_at: '2026-07-24T08:00:00Z',
            },
        ],
    },
    devices: [
        {
            id: 71,
            name: 'Harbour gateway',
            domain: 'it_infrastructure',
            category: 'network',
            status: 'offline',
            health_status: 'critical',
            provider: 'unifi',
            last_seen_at: '2026-07-24T08:00:00Z',
            monitor_count: 1,
            monitoring_state: 'attention',
            href: '/security-devices/devices/71',
        },
    ],
    alerts: [],
    it_work: [],
    maintenance: [
        {
            id: 31,
            device_id: 71,
            device_name: 'Harbour gateway',
            type: 'repair',
            status: 'scheduled',
            description: 'Restore WAN failover',
            scheduled_for: '2026-07-24T07:00:00Z',
            is_overdue: true,
        },
    ],
    collectors: [
        {
            id: 41,
            name: 'Harbour collector',
            state: 'stale',
            status: 'offline',
            last_seen_at: '2026-07-24T08:00:00Z',
        },
    ],
    changes: [],
    links: {
        full: '/security-devices/sites/9004',
        devices: '/security-devices/devices?site_id=9004',
        monitoring: '/security-devices/monitoring?site_id=9004',
        maintenance: '/security-devices/maintenance?site_id=9004',
    },
    can: {
        view_control_room: false,
        view_it_work: false,
        view_room_placement: true,
    },
};

describe('Site technology projection', () => {
    it('shows canonical health and honest restricted cross-module context', () => {
        render(
            <SiteTechnologyProjectionPanel
                siteId={9004}
                data={projection}
                canViewHardwarePlacement={false}
            />,
        );

        expect(screen.getByText('Technology & monitoring')).toBeVisible();
        expect(screen.getByText('Harbour gateway')).toBeVisible();
        expect(screen.getByText('Configuration evidence')).toBeVisible();
        expect(
            screen.getByText(/Configuration changed on 1 WAN device/),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Open configuration evidence' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/network-it?tab=configuration-firmware',
        );
        expect(screen.getByText('50% monitored')).toBeVisible();
        expect(
            screen.getByText('Restricted by Control Room permissions.'),
        ).toBeVisible();
        expect(
            screen.getByText('Restricted by IT & Support permissions.'),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: /open full technology view/i }),
        ).toHaveAttribute('href', '/security-devices/sites/9004');
        expect(
            screen.queryByRole('link', { name: /room placement/i }),
        ).not.toBeInTheDocument();
    });

    it('uses a clear empty state and exposes room placement only when both gates pass', () => {
        render(
            <SiteTechnologyProjectionPanel
                siteId={9004}
                data={{
                    ...projection,
                    summary: {
                        ...projection.summary,
                        health: 'unknown',
                        devices: 0,
                        attention_devices: 0,
                        monitored_devices: 0,
                        coverage_percent: null,
                    },
                    devices: [],
                    monitoring: {
                        ...projection.monitoring,
                        total_devices: 0,
                        monitored_devices: 0,
                        issues: [],
                    },
                }}
                canViewHardwarePlacement
            />,
        );

        expect(screen.getByText('No Site devices')).toBeVisible();
        expect(screen.getByText('Not measured')).toBeVisible();
        expect(
            screen.getByRole('link', { name: /room placement/i }),
        ).toHaveAttribute('href', '/sites/9004/hardware');
    });
});
