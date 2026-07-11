import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

import ResidentTrackingIndex from '@/pages/fleet-assets/resident-tracking';
import ResidentTrackingHistory from '@/pages/fleet-assets/resident-tracking/history';
import type { Resident } from '@/components/resident-tracking/types';

const inertiaMocks = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>{children}</a>
    ),
    router: {
        get: inertiaMocks.get,
        post: inertiaMocks.post,
        reload: inertiaMocks.reload,
        visit: inertiaMocks.visit,
    },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

vi.mock('@/components/page-shell', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/leaflet-map', () => ({
    default: ({ markers }: { markers?: unknown[] }) => (
        <div data-marker-count={markers?.length ?? 0} data-testid="resident-map" />
    ),
}));

vi.mock('@/components/fleet-charts', () => ({
    FLEET_COLORS: { primary: '#1f7a4d', warning: '#d97706', danger: '#dc2626' },
    HalfMoonGauge: () => <div data-testid="safety-gauge" />,
}));

function renderResidentTracking() {
    return render(
        <ResidentTrackingIndex
            residents={[
                {
                    id: 12,
                    client_id: 9012,
                    name: 'Amelia Wilson',
                    preferred_name: 'Amelia',
                    house: 'Harbour House',
                    site_id: 1,
                    photo: null,
                    tracker_name: 'Amelia pendant',
                    tracker_serial: 'GL30-1',
                    status: 'online',
                    last_seen_at: '2026-05-18T04:00:00Z',
                    lat: -37.723657,
                    lng: 175.241655,
                    battery: null,
                    battery_status: 'unknown',
                    battery_low_threshold: 20,
                    charging_status: null,
                    external_power: false,
                    last_power_event: null,
                    last_safety_event: 'vehicle_sos',
                    last_safety_event_at: '2026-05-18T04:00:00Z',
                    panic_active: false,
                    speed: 0,
                    geofence_status: 'in_zone',
                    on_outing: false,
                    locate_now_url: '/fleet-assets/resident-tracking/9012/locate-now',
                    acknowledge_panic_url: '/fleet-assets/resident-tracking/9012/acknowledge-panic',
                    last_command_status: 'queued',
                },
                {
                    id: 13,
                    client_id: 9013,
                    name: 'Ben Taylor',
                    preferred_name: 'Ben',
                    house: 'Harbour House',
                    site_id: 1,
                    photo: null,
                    tracker_name: 'Ben pendant',
                    tracker_serial: 'GL30-2',
                    status: 'online',
                    last_seen_at: '2026-05-18T04:00:00Z',
                    lat: -37.724,
                    lng: 175.242,
                    battery: 15,
                    battery_status: 'low',
                    battery_low_threshold: 20,
                    charging_status: 'not_charging',
                    external_power: false,
                    last_power_event: null,
                    last_safety_event: 'man_down',
                    last_safety_event_at: '2026-05-18T04:00:00Z',
                    panic_active: true,
                    speed: 0,
                    geofence_status: 'in_zone',
                    on_outing: false,
                    locate_now_url: '/fleet-assets/resident-tracking/9013/locate-now',
                    acknowledge_panic_url: '/fleet-assets/resident-tracking/9013/acknowledge-panic',
                    last_command_status: null,
                },
                {
                    id: 14,
                    client_id: 9014,
                    name: 'Cara Singh',
                    preferred_name: 'Cara',
                    house: 'Harbour House',
                    site_id: 1,
                    photo: null,
                    tracker_name: 'Cara pendant',
                    tracker_serial: 'GL30-3',
                    status: 'online',
                    last_seen_at: '2026-05-18T04:00:00Z',
                    lat: -37.725,
                    lng: 175.243,
                    battery: 55,
                    battery_status: 'normal',
                    battery_low_threshold: 20,
                    charging_status: 'charging',
                    external_power: true,
                    last_power_event: 'power_on',
                    last_safety_event: null,
                    last_safety_event_at: null,
                    panic_active: false,
                    speed: 0,
                    geofence_status: 'in_zone',
                    on_outing: false,
                    locate_now_url: '/fleet-assets/resident-tracking/9014/locate-now',
                    acknowledge_panic_url: '/fleet-assets/resident-tracking/9014/acknowledge-panic',
                    last_command_status: null,
                },
            ]}
            stats={{
                tracked: 3,
                online: 3,
                offline: 0,
                untracked: 0,
                online_percent: 100,
                in_geofence: 3,
                outside_geofence: 0,
                low_battery: 1,
                safety_score: 100,
                avg_battery: 35,
            }}
            recent_alerts={[]}
            active_outings={[]}
            geofences={[]}
            can={{ manage: true }}
        />,
    );
}

beforeEach(() => {
    inertiaMocks.get.mockClear();
    inertiaMocks.post.mockClear();
});

function historyLocation(
    index: number,
    eventType: string | null = 'location_report',
    label = `Point ${index}`,
) {
    return {
        lat: -37.723657 - index * 0.00001,
        lng: 175.241655 + index * 0.00001,
        display_location: label,
        timestamp: `2026-05-18T08:${String(59 - index).padStart(2, '0')}:00Z`,
        speed: index % 2 === 0 ? 0 : 1,
        battery: index === 0 ? 100 : 95,
        event_type: eventType,
    };
}

function historyResident(overrides: Partial<Resident> = {}): Resident {
    return {
        id: 12,
        client_id: 9012,
        name: 'Amelia Wilson',
        preferred_name: 'Amelia',
        house: 'Harbour House',
        photo: null,
        status: 'online',
        last_seen_at: '2026-05-18T08:59:00Z',
        lat: -37.723657,
        lng: 175.241655,
        battery: 100,
        battery_status: 'normal',
        battery_voltage_mv: 4117,
        battery_low_threshold: 20,
        battery_updated_at: '2026-05-18T08:59:00Z',
        charging_status: 'charging',
        external_power: true,
        last_power_event: null,
        last_safety_event: null,
        last_safety_event_at: null,
        panic_active: false,
        speed: 0,
        geofence_status: 'in_zone',
        on_outing: false,
        ...overrides,
    };
}

it('renders the resident sidebar and queues Locate Now from a list row', async () => {
    renderResidentTracking();

    expect(screen.getByText('Residents tracked')).toBeInTheDocument();
    expect(screen.getByText('3 online')).toBeInTheDocument();
    expect(screen.getByText('Battery not reported')).toBeVisible();
    expect(screen.getByText('Low battery')).toBeVisible();
    expect(screen.getByText('Charging')).toBeVisible();

    fireEvent.click(screen.getAllByRole('button', { name: /Locate/i })[0]);

    expect(inertiaMocks.post).toHaveBeenCalledWith(
        '/fleet-assets/resident-tracking/9012/locate-now',
        {},
        expect.objectContaining({ preserveScroll: true }),
    );
});

it('renders the new history page with quick range pills and event filter', async () => {
    render(
        <ResidentTrackingHistory
            client={{
                id: 9012,
                name: 'Amelia Wilson',
                house: 'Harbour House',
                photo: null,
            }}
            resident={null}
            tracker={{
                id: 12,
                device_uid: 'GL30-TEST-9012',
                name: 'Care tracker 867963069916998',
                serial: null,
                status: 'active',
                detail_url: '/security-devices/devices/12',
            }}
            available_event_types={['location_report']}
            filters={{ range: '24h', date_from: null, date_to: null, event_types: [] }}
            locations={[
                {
                    lat: -37.723657,
                    lng: 175.241655,
                    timestamp: '2026-05-18T08:06:00Z',
                    speed: 0,
                    battery: null,
                    event_type: 'location_report',
                },
                {
                    lat: -37.723687,
                    lng: 175.241568,
                    timestamp: '2026-05-18T07:47:00Z',
                    speed: 0,
                    battery: null,
                    event_type: 'location_report',
                },
            ]}
        />,
    );

    // New UI exposes range pills + map shows all points as markers
    expect(screen.getByRole('button', { name: /^Today$/i })).toBeVisible();
    expect(screen.getByRole('button', { name: /^24h$/i })).toBeVisible();
    expect(screen.getByRole('button', { name: /^7d$/i })).toBeVisible();
    expect(screen.getByTestId('resident-map')).toHaveAttribute('data-marker-count', '2');
    expect(screen.getByText(/points/i)).toBeVisible();
});

it('shows charging clearly in the history status strip', async () => {
    render(
        <ResidentTrackingHistory
            client={{
                id: 9012,
                name: 'Amelia Wilson',
                house: 'Harbour House',
                photo: null,
            }}
            resident={historyResident()}
            tracker={null}
            available_event_types={['location_report']}
            filters={{ range: '24h', date_from: null, date_to: null, event_types: [] }}
            locations={[historyLocation(0)]}
        />,
    );

    expect(screen.getByLabelText('Charging status: Charging')).toBeVisible();
});

it('defaults the history map to important pins and lets the user show all pins', async () => {
    const locations = Array.from({ length: 12 }, (_, index) =>
        historyLocation(index, index === 3 ? 'battery_low' : 'location_report'),
    );

    render(
        <ResidentTrackingHistory
            client={{
                id: 9012,
                name: 'Amelia Wilson',
                house: 'Harbour House',
                photo: null,
            }}
            resident={null}
            tracker={null}
            available_event_types={['location_report', 'battery_low']}
            filters={{ range: '24h', date_from: null, date_to: null, event_types: [] }}
            locations={locations}
        />,
    );

    expect(screen.getByTestId('resident-map')).toHaveAttribute('data-marker-count', '3');
    expect(screen.getByText('3 of 12 pins shown')).toBeVisible();

    fireEvent.click(screen.getByRole('button', { name: /All pins/i }));

    expect(screen.getByTestId('resident-map')).toHaveAttribute('data-marker-count', '12');
    expect(screen.getByText('12 of 12 pins shown')).toBeVisible();
});

it('filters the timeline without changing the loaded map data', async () => {
    render(
        <ResidentTrackingHistory
            client={{
                id: 9012,
                name: 'Amelia Wilson',
                house: 'Harbour House',
                photo: null,
            }}
            resident={null}
            tracker={null}
            available_event_types={['location_report', 'battery_low', 'power_on']}
            filters={{ range: '24h', date_from: null, date_to: null, event_types: [] }}
            locations={[
                historyLocation(0, 'location_report', 'Normal location'),
                historyLocation(1, 'battery_low', 'Low battery location'),
                historyLocation(2, 'power_on', 'Power event location'),
            ]}
        />,
    );

    expect(screen.getByText('Timeline')).toBeVisible();
    expect(screen.getByText('Normal location')).toBeVisible();
    expect(screen.getByText('Low battery location')).toBeVisible();
    expect(screen.getByText('Power event location')).toBeVisible();

    fireEvent.pointerDown(screen.getByRole('button', { name: /Timeline events/i }));
    fireEvent.click(screen.getByRole('menuitemcheckbox', { name: /Battery low/i }));

    expect(screen.getByText('1 of 3 shown')).toBeVisible();
    expect(screen.queryByText('Normal location')).not.toBeInTheDocument();
    expect(screen.getByText('Low battery location')).toBeVisible();
    expect(screen.queryByText('Power event location')).not.toBeInTheDocument();
    expect(screen.getByTestId('resident-map')).toHaveAttribute('data-marker-count', '3');
});
