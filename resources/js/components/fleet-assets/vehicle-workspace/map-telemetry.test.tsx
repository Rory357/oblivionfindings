import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { VehicleTelemetryPanel } from './map-telemetry';
import type { VehicleTelemetry } from './map-types';
import type { VehicleWorkspace } from './types';

vi.mock('./mileage-feed', () => ({ MileageFeed: () => null }));

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const workspace = {
    vehicle: { id: 14 },
    can: { view_vehicle_technology: true, manage: false },
} as VehicleWorkspace;

function response(
    devices: VehicleTelemetry['technology']['devices'] = [],
): VehicleTelemetry {
    return {
        technology: {
            devices,
            truncated: false,
            boundary: { title: '', description: '', management: '' },
            summary: {
                total: devices.length,
                offline: 0,
                attention: 0,
                unmonitored: 0,
                monitor_alerts: 0,
                configuration_drift: 0,
                firmware_updates: 0,
                overdue_maintenance: 0,
                open_it_work: 0,
            },
            permissions: {
                monitoring: false,
                maintenance: false,
                it_work: false,
            },
            links: {
                devices: '/security-devices/devices',
                tracking: '/security-devices/tracking',
                maintenance: null,
                it_work: null,
            },
        },
        telemetry: {
            as_of: '2026-09-29T19:00:00Z',
            timezone: 'Pacific/Auckland',
            fresh_minutes: 15,
            tracker: null,
            samples: [],
            current_sample_id: null,
            vehicle: { id: 14, name: 'Test van', vin: null },
            can: { view_alerts: false },
        },
    };
}

describe('vehicle telemetry canonical Device handoff', () => {
    it('exposes the permitted devices even before their first telemetry report', async () => {
        const data = response([
            {
                id: 41,
                name: 'Van tracker',
                href: '/security-devices/devices/41',
            },
            {
                id: 42,
                name: 'Van camera',
                href: '/security-devices/devices/42',
            },
        ] as VehicleTelemetry['technology']['devices']);
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(new Response(JSON.stringify(data))),
        );
        render(
            <VehicleTelemetryPanel
                workspace={workspace}
                onNavigate={vi.fn()}
                onChanged={vi.fn()}
            />,
        );

        expect(
            await screen.findByRole('link', { name: 'Van tracker' }),
        ).toHaveAttribute('href', '/security-devices/devices/41');
        expect(
            screen.getByRole('link', { name: 'Van camera' }),
        ).toHaveAttribute('href', '/security-devices/devices/42');
    });

    it('does not invent a Device link from a telemetry identity omitted from the permitted projection', async () => {
        const data = response();
        data.telemetry.tracker = {
            device_id: 99,
            name: 'Unprojected tracker',
            href: null,
        } as VehicleTelemetry['telemetry']['tracker'];
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(new Response(JSON.stringify(data))),
        );
        render(
            <VehicleTelemetryPanel
                workspace={workspace}
                onNavigate={vi.fn()}
                onChanged={vi.fn()}
            />,
        );

        await screen.findByRole('heading', { name: 'Vehicle telemetry' });
        expect(
            screen.queryByRole('region', { name: 'Installed devices' }),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it.each([403, 404])(
        'removes the handoff when the server denies access (%s)',
        async (status) => {
            vi.stubGlobal(
                'fetch',
                vi.fn().mockResolvedValue(new Response(null, { status })),
            );
            render(
                <VehicleTelemetryPanel
                    workspace={workspace}
                    onNavigate={vi.fn()}
                    onChanged={vi.fn()}
                />,
            );

            await screen.findByText('Telemetry needs device access');
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
        },
    );

    it('does not request telemetry without vehicle technology permission', () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        render(
            <VehicleTelemetryPanel
                workspace={{
                    ...workspace,
                    can: { ...workspace.can, view_vehicle_technology: false },
                }}
                onNavigate={vi.fn()}
                onChanged={vi.fn()}
            />,
        );

        expect(screen.getByText('Telemetry needs device access')).toBeVisible();
        expect(fetch).not.toHaveBeenCalled();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});
