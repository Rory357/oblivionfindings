import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SensorPage from './sensor';
import type { SensorPageProps } from './_sensor-tracker-types';
import { minutesAgo } from './device-page-test-fixtures';

const inertia = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), visit: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7, name: 'Synthetic Viewer' } } } }),
        router: { ...actual.router, ...inertia, on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

const points = (value: number) => Array.from({ length: 96 }, (_, index) => (index > 80 ? value + (index % 3) * 0.2 : null));

function sensor(overrides: Partial<SensorPageProps> = {}): SensorPageProps {
    return {
        device: {
            id: 70,
            name: 'Synthetic room sensor',
            kind: 'sensor',
            kind_label: 'Sensor',
            group: 'sensor',
            state: 'online',
            badge: { label: 'Online', tone: 'success' },
            model: 'Synthetic AM-1',
            site: 'Synthetic House',
            site_id: 4,
            address: null,
            watching: 'Reports from its sensor feed',
            subline: [
                { text: 'Synthetic AM-1', at: null },
                { text: 'Synthetic House · Room 3', at: null },
                { text: 'through Synthetic gateway', at: null },
                { text: 'reading {ago}', at: minutesAgo(2) },
            ],
            back: { href: '/security-devices/devices?tab=sensors', label: 'Devices' },
        },
        tab: 'readings',
        tabs: [
            { key: 'readings', label: 'Readings' },
            { key: 'alerts', label: 'Alerts' },
        ],
        range: '24h',
        meters: [
            { key: 'temperature', label: 'Temperature', value: '21.4 °C', caption: { text: 'In the usual range', at: null }, tone: 'success', tab: 'readings' },
            { key: 'humidity', label: 'Humidity', value: '48%', caption: { text: 'In the usual range', at: null }, tone: 'success', tab: 'readings' },
            { key: 'co2', label: 'CO₂', value: '1,210 ppm', caption: { text: 'Above the usual range', at: null }, tone: 'warning', tab: 'readings' },
            { key: 'battery', label: 'Battery', value: '12%', caption: { text: 'About 2 weeks left', at: null }, tone: 'warning', tab: 'readings' },
        ],
        can: { edit: true, watch: true, move: true, assign: true, retire: true, upload: false, see_events: true },
        readings: {
            series: [
                { key: 'temperature', title: 'Temperature', unit: '°C', start: minutesAgo(24 * 60), step_seconds: 900, points: points(21), limits: [{ value: 12, label: 'Alert below 12 °C' }, { value: 32, label: 'Alert above 32 °C' }] },
                { key: 'humidity', title: 'Humidity', unit: '%', start: minutesAgo(24 * 60), step_seconds: 900, points: points(48), limits: [{ value: 25, label: 'Alert below 25%' }, { value: 75, label: 'Alert above 75%' }] },
                { key: 'co2', title: 'CO₂', unit: 'ppm', start: minutesAgo(24 * 60), step_seconds: 900, points: points(900), limits: [{ value: 1000, label: 'Alert at 1,000 ppm' }, { value: 1500, label: 'Urgent at 1,500 ppm' }] },
            ],
            rules: [
                { label: 'Temperature below 12 °C or above 32 °C', hint: 'Staff on shift at Synthetic House · in the app' },
                { label: 'Battery under 20%', hint: 'Staff on shift at Synthetic House · in the app' },
            ],
            where: {
                rows: [
                    { label: 'Site', text: 'Synthetic House', at: null },
                    { label: 'Gateway', text: 'Synthetic gateway · online', at: null },
                    { label: 'Signal', text: '−87 dBm', at: null },
                    { label: 'Last reading', text: '{time}', at: minutesAgo(2) },
                ],
                gateway_href: '/security-devices/devices/71',
            },
        },
        alerts: null,
        ...overrides,
    };
}

function meterBlock(label: string): HTMLElement {
    return screen.getByRole('button', { name: new RegExp(`^${label}:`) });
}

describe('Sensor page (pg-sdr-7)', () => {
    beforeEach(() => vi.clearAllMocks());
    afterEach(cleanup);

    it('shows the readings now with their state and the battery forecast', () => {
        render(<SensorPage {...sensor()} />);

        expect(within(meterBlock('Temperature')).getByText('21.4 °C')).toBeInTheDocument();
        expect(within(meterBlock('CO₂')).getByText('Above the usual range')).toBeInTheDocument();
        expect(within(meterBlock('Battery')).getByText('About 2 weeks left')).toBeInTheDocument();
        expect(screen.getByText(/through Synthetic gateway · reading 2 min ago/)).toBeInTheDocument();
        // Only the drawn tabs; History and Settings aren't built, and alert rules don't cover sensors.
        expect(screen.queryByRole('tab', { name: /History/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('tab', { name: /Settings/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add alert rule' })).not.toBeInTheDocument();
    });

    it('says Not available for a battery the sensor doesn’t send', () => {
        render(
            <SensorPage
                {...sensor({
                    meters: [{ key: 'battery', label: 'Battery', value: null, caption: { text: 'Not sent by this sensor', at: null }, tone: 'brand', tab: 'readings' }],
                })}
            />,
        );

        expect(within(meterBlock('Battery')).getByText('Not available')).toBeInTheDocument();
        expect(within(meterBlock('Battery')).getByText('Not sent by this sensor')).toBeInTheDocument();
    });

    it('draws each reading against the lines where an alert opens, with one range switch', () => {
        render(<SensorPage {...sensor()} />);

        expect(screen.getByText('Alert below 12 °C')).toBeInTheDocument();
        expect(screen.getByText('Alert at 1,000 ppm')).toBeInTheDocument();
        const switches = screen.getAllByRole('group', { name: /time range/ });
        expect(switches).toHaveLength(1);
        expect(switches[0]).toHaveAccessibleName('CO₂: time range');

        fireEvent.click(within(switches[0]).getByRole('radio', { name: '7 days' }));
        expect(inertia.get).toHaveBeenCalledWith('/security-devices/devices/70', { range: '7d' }, expect.objectContaining({ preserveState: true }));
    });

    it('lists who is told for each built-in rule and where the sensor is', () => {
        render(<SensorPage {...sensor()} />);

        const rules = screen.getByText('Alert rules').closest('section') as HTMLElement;
        expect(within(rules).getByText('Temperature below 12 °C or above 32 °C')).toBeInTheDocument();
        expect(within(rules).getAllByText('On')).toHaveLength(2);
        const where = screen.getByText('Where it is').closest('section') as HTMLElement;
        expect(within(where).getByText('−87 dBm')).toBeInTheDocument();
        expect(within(where).getByRole('link', { name: 'Open gateway' })).toHaveAttribute('href', '/security-devices/devices/71');
    });

    it('says so when the sensor hasn’t sent anything yet', () => {
        render(<SensorPage {...sensor({ readings: { series: [], rules: [], where: { rows: [], gateway_href: null } } })} />);

        expect(screen.getByText('This sensor hasn’t sent any readings yet.')).toBeInTheDocument();
    });

    it('lists its alerts, each opening in Alerts, and a quiet line when there are none', () => {
        const { unmount } = render(
            <SensorPage
                {...sensor({
                    tab: 'alerts',
                    readings: null,
                    alerts: [{ key: 'alert-5', at: minutesAgo(30), tone: 'warning', title: 'Synthetic room sensor: co2 1210 ppm', sub: 'Still open', href: '/security-devices/alerts?alert=5' }],
                })}
            />,
        );

        expect(screen.getByRole('link', { name: 'Open alert: Synthetic room sensor: co2 1210 ppm' })).toHaveAttribute('href', '/security-devices/alerts?alert=5');
        unmount();

        render(<SensorPage {...sensor({ tab: 'alerts', readings: null, alerts: [] })} />);
        expect(screen.getByText('No alerts for this sensor in the last 90 days.')).toBeInTheDocument();
    });

    it('shows who it is linked to with their plan’s rule, and the scald watch (Phase F)', () => {
        render(
            <SensorPage
                {...sensor({
                    care: {
                        link: { title: 'Linked to Ana’s falls plan', rule: 'Alert night staff if Ana out of bed for over 10 minutes between 22:00 and 06:00', plan: 'Falls prevention plan', person_href: '/clients/11', linked_at: minutesAgo(60) },
                        can_link: true,
                        scald: { on: true, can_change: false },
                    },
                })}
            />,
        );

        expect(screen.getByText('Linked to Ana’s falls plan')).toBeInTheDocument();
        expect(screen.getByText('Alert night staff if Ana out of bed for over 10 minutes between 22:00 and 06:00')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Unlink' })).toBeInTheDocument();
        const scald = screen.getByText('Watch this tap for scald risk').closest('span')?.parentElement as HTMLElement;
        expect(within(scald).getByText('On')).toBeInTheDocument();
    });

    it('offers Link to a person when nobody is linked and the viewer may link', () => {
        render(<SensorPage {...sensor({ care: { link: null, can_link: true, scald: null } })} />);

        expect(screen.getByText('Not linked to anyone’s care.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Link to a person' })).toBeInTheDocument();
    });
});
