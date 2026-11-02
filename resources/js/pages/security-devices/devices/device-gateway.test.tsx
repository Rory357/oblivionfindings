import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DevicePage from './device';
import type { DevicePageProps } from './_device-page-types';
import type { GatewayOverview } from './_gateway-overview';
import { meter, minutesAgo, pageFixture } from './device-page-test-fixtures';

const inertia = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), visit: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: { ...actual.router, ...inertia, on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

const SENSORS: GatewayOverview['sensors'] = [
    { id: 60, name: 'Synthetic room sensor', kind: 'sensor', model: 'Synthetic AM', state: 'online', where: 'Room 3', reading: '21.4 °C · 48% · 620 ppm', reading_tone: null, signal: '−87 dBm', battery: 12, battery_low: true, seen_at: minutesAgo(2), href: '/security-devices/devices/60', address: null, site: 'Synthetic House', site_id: 4 },
    { id: 61, name: 'Synthetic leak sensor', kind: 'sensor', model: 'Synthetic WS', state: 'online', where: null, reading: null, reading_tone: null, signal: null, battery: null, battery_low: false, seen_at: null, href: '/security-devices/devices/61', address: null, site: 'Synthetic House', site_id: 4 },
    { id: 62, name: 'Synthetic tap probe', kind: 'sensor', model: 'Synthetic TS', state: 'online', where: 'Room 3 basin', reading: '58 °C', reading_tone: 'critical', signal: '−90 dBm', battery: 96, battery_low: false, seen_at: minutesAgo(2), href: '/security-devices/devices/62', address: null, site: 'Synthetic House', site_id: 4 },
];

function gateway(overview: Partial<GatewayOverview> = {}, overrides: Partial<DevicePageProps> = {}): DevicePageProps {
    const base = pageFixture();
    return pageFixture({
        device: { ...base.device, id: 71, name: 'Synthetic gateway', kind: 'sensor_gateway', kind_label: 'Sensor gateway', group: 'other', back: { href: '/security-devices/devices?tab=sensors', label: 'Devices' } },
        meters: [
            meter({ key: 'reporting', label: 'Sensors reporting', value: '3 of 3', caption: { text: 'Last one {ago}', at: minutesAgo(1) }, tone: 'success' }),
            meter({ key: 'reaches', label: 'Reaches Oblivion', value: 'Synthetic House hub', caption: { text: 'Encrypted, port 8883', at: null }, tone: 'success' }),
            meter({ key: 'batteries', label: 'Batteries low', value: '1', caption: { text: 'Synthetic room sensor', at: null }, tone: 'warning' }),
        ],
        overview: {
            trend: null,
            availability: null,
            events: [],
            facts: [['Model', 'Synthetic UG'], ['Address', '10.20.0.36']],
            sensors: SENSORS,
            gateway: {
                route: {
                    hub: 'Synthetic House hub',
                    reaches: 'Through Synthetic House hub',
                    connection: { tone: 'success', text: 'Encrypted, port 8883' },
                    logins: [{ id: 3, username: 'gw-synthetic01', topic: 'oblivion/4/gw-synthetic01/…', made_at: '2026-10-19T01:00:00Z', last_reading_at: minutesAgo(1) }],
                    broker_href: '/security-devices/setup/hubs?tab=broker',
                },
                quiet: { watched: true, text: 'Oblivion opens one alert, “Synthetic gateway is offline”, and tells who the alert rule names. Readings from its 3 sensors stop arriving until it’s back.' },
                recent: [{ key: 'alert-4', at: minutesAgo(50), tone: 'critical', title: 'Synthetic tap probe: temperature 58 °C', sub: 'Still open', href: '/security-devices/alerts?alert=4' }],
            },
            ...overview,
        } as GatewayOverview,
        ...overrides,
    });
}

function meterBlock(label: string): HTMLElement {
    return screen.getByRole('button', { name: new RegExp(`^${label}:`) });
}

describe('Device page · sensor gateway (pg-sdr-22)', () => {
    beforeEach(() => vi.clearAllMocks());
    afterEach(cleanup);

    it('lists its sensors with reading, signal and battery, saying Not available where one is missing', () => {
        render(<DevicePage {...gateway()} />);

        expect(screen.getByText('Sensors on this gateway')).toBeInTheDocument();
        const table = within(screen.getByRole('table'));
        const missing = table.getByText('Synthetic leak sensor').closest('tr') as HTMLElement;
        expect(within(missing).getAllByText('Not available')).toHaveLength(3);
        const low = table.getByText('Synthetic room sensor').closest('tr') as HTMLElement;
        expect(within(low).getByText('−87 dBm')).toBeInTheDocument();
        expect(within(low).getByText('Low')).toBeInTheDocument();
        const hot = table.getByText('Synthetic tap probe').closest('tr') as HTMLElement;
        expect(within(hot).getByText('58 °C')).toHaveClass('text-status-critical');

        fireEvent.click(low);
        expect(inertia.visit).toHaveBeenCalledWith('/security-devices/devices/60');
    });

    it('says how it reaches Oblivion, never over the public internet', () => {
        render(<DevicePage {...gateway()} />);

        expect(within(meterBlock('Reaches Oblivion')).getByText('Synthetic House hub')).toBeInTheDocument();
        expect(screen.getByText('Never on the public internet.')).toBeInTheDocument();
        const how = screen.getByText('How it reaches Oblivion').closest('section') as HTMLElement;
        expect(within(how).getByText('Encrypted, port 8883')).toBeInTheDocument();
        expect(within(how).getByText('gw-synthetic01 · made 19 Oct 2026')).toBeInTheDocument();
        expect(within(how).getByRole('link', { name: 'Open Sensor broker' })).toHaveAttribute('href', '/security-devices/setup/hubs?tab=broker');
        expect(screen.getByText('Gateway')).toBeInTheDocument();
    });

    it('says so when no login is linked, and offers Watch it when nothing checks the gateway', () => {
        render(
            <DevicePage
                {...gateway({
                    gateway: {
                        route: { hub: null, reaches: null, connection: { tone: 'neutral', text: 'Not available' }, logins: [], broker_href: null },
                        quiet: { watched: false, text: 'Oblivion doesn’t check this gateway yet, so nobody is told if it goes quiet.' },
                        recent: [],
                    },
                })}
            />,
        );

        expect(screen.getByText('No sensor broker login is linked to this gateway.')).toBeInTheDocument();
        expect(screen.queryByText('Never on the public internet.')).not.toBeInTheDocument();
        expect(screen.getByText('Oblivion doesn’t check this gateway yet, so nobody is told if it goes quiet.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Watch it' })).toBeInTheDocument();
        expect(screen.getByText('No alerts on this gateway or its sensors in the last 90 days.')).toBeInTheDocument();
    });

    it('opens each recent alert in Alerts', () => {
        render(<DevicePage {...gateway()} />);

        expect(screen.getByRole('link', { name: 'Open alert: Synthetic tap probe: temperature 58 °C' })).toHaveAttribute('href', '/security-devices/alerts?alert=4');
    });
});
