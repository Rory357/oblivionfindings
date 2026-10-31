import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    MonitorHistoryDialog,
    type MonitorHistoryPayload,
} from './monitor-history-dialog';

vi.mock('axios');

function buildHistoryPayload(
    overrides: Partial<MonitorHistoryPayload> = {},
): MonitorHistoryPayload {
    return {
        monitor: {
            id: 404,
            name: 'Gi1/0/48 Core Uplink',
            kind: 'snmp_interface',
            enabled: true,
            reported_state: 'degraded',
            effective_state: 'degraded',
            stale_after_seconds: 300,
            last_observation_at: '2026-10-26T06:00:00Z',
            device: {
                id: 19,
                name: 'Core Switch A',
                domain: 'it_infrastructure',
                category: 'network',
                href: '/security-devices/devices/19',
            },
        },
        range: {
            key: '24h',
            seconds: 86400,
            from: '2026-10-25T06:00:00Z',
            to: '2026-10-26T06:00:00Z',
            total_observations: 288,
            sampled_observations: 288,
            shown_points: 3,
            downsampled: true,
            truncated_read: false,
        },
        primary_metric: {
            key: 'in_utilization_pct',
            label: 'Inbound utilisation',
            unit: '%',
            is_interface: true,
        },
        thresholds: {
            source_kind: 'site',
            rule_name: 'Core uplink utilisation',
            metric_key: 'in_utilization_pct',
            direction: 'above',
            warning_rising: 75,
            warning_falling: 65,
            critical_rising: 90,
            critical_falling: 80,
        },
        maintenance: {
            active_window: null,
            upcoming_window: {
                id: 7,
                name: 'Overnight firmware window',
                starts_at: '2026-10-27T10:00:00Z',
                ends_at: '2026-10-27T12:00:00Z',
                timezone: 'Pacific/Auckland',
                life_safety_protected: false,
            },
        },
        summary: {
            sample_count: 288,
            min: 22.4,
            max: 84.5,
            average: 58.1,
            latest: 81.2,
            unit: '%',
            availability_pct: 99.3,
            healthy_samples: 270,
            degraded_samples: 18,
            failed_samples: 0,
            freshness: 'fresh',
        },
        series: [
            {
                observed_at: '2026-10-26T04:00:00Z',
                state: 'healthy',
                value: 44.2,
                raw_value: 44.2,
                unit: '%',
                latency_ms: null,
                in_bps: 442_000_000,
                out_bps: 210_000_000,
                in_utilization_pct: 44.2,
                out_utilization_pct: 21.0,
                errors: 0,
                discards: 0,
                packet_loss_pct: null,
            },
            {
                observed_at: '2026-10-26T05:00:00Z',
                state: 'degraded',
                value: 84.5,
                raw_value: 84.5,
                unit: '%',
                latency_ms: null,
                in_bps: 845_000_000,
                out_bps: 410_000_000,
                in_utilization_pct: 84.5,
                out_utilization_pct: 41.0,
                errors: 2,
                discards: 1,
                packet_loss_pct: null,
            },
            {
                observed_at: '2026-10-26T06:00:00Z',
                state: 'degraded',
                value: 81.2,
                raw_value: 81.2,
                unit: '%',
                latency_ms: null,
                in_bps: 812_000_000,
                out_bps: 390_000_000,
                in_utilization_pct: 81.2,
                out_utilization_pct: 39.0,
                errors: 2,
                discards: 1,
                packet_loss_pct: null,
            },
        ],
        transitions: [
            {
                observed_at: '2026-10-26T05:00:00Z',
                from_state: 'healthy',
                to_state: 'degraded',
                value: 84.5,
                unit: '%',
            },
        ],
        gaps: [],
        ...overrides,
    };
}

describe('MonitorHistoryDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders bounded interface history, thresholds, summary metrics, and switches windows', async () => {
        vi.mocked(axios.get).mockResolvedValue({
            data: { history: buildHistoryPayload() },
        });

        render(
            <MonitorHistoryDialog
                open={true}
                onOpenChange={vi.fn()}
                target={{
                    monitorId: 404,
                    monitorName: 'Gi1/0/48 Core Uplink',
                    deviceName: 'Core Switch A',
                }}
            />,
        );

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith(
                '/security-devices/monitoring/monitors/404/history',
                expect.objectContaining({
                    params: { range: '24h' },
                }),
            );
        });

        expect(
            await screen.findByText(
                /Showing 3 representative points from 288 retained observations/i,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Upcoming maintenance: Overnight firmware window/i),
        ).toBeInTheDocument();
        expect(screen.getByText('Core uplink utilisation')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Last 7 days' }));

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith(
                '/security-devices/monitoring/monitors/404/history',
                expect.objectContaining({
                    params: { range: '7d' },
                }),
            );
        });
    });

    it('shows a clear empty state when no retained observations exist in the window', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({
            data: {
                history: buildHistoryPayload({
                    series: [],
                    transitions: [],
                    range: {
                        key: '1h',
                        seconds: 3600,
                        from: '2026-10-26T05:00:00Z',
                        to: '2026-10-26T06:00:00Z',
                        total_observations: 0,
                        sampled_observations: 0,
                        shown_points: 0,
                        downsampled: false,
                        truncated_read: false,
                    },
                }),
            },
        });

        render(
            <MonitorHistoryDialog
                open={true}
                onOpenChange={vi.fn()}
                target={{
                    monitorId: 404,
                    monitorName: 'Gi1/0/48 Core Uplink',
                    deviceName: 'Core Switch A',
                }}
            />,
        );

        expect(
            await screen.findByText(
                'No retained observations were recorded in this window.',
            ),
        ).toBeInTheDocument();
    });
});
