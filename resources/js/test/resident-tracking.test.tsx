import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

import ResidentTrackingIndex from '@/pages/fleet-assets/resident-tracking';

const inertiaMocks = vi.hoisted(() => ({
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

vi.mock('@/components/fleet-hero', () => ({
    default: ({ title, actions }: { title: string; actions?: React.ReactNode }) => (
        <header><h1>{title}</h1>{actions}</header>
    ),
}));

vi.mock('@/components/leaflet-map', () => ({
    default: () => <div data-testid="resident-map" />,
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
                    speed: 0,
                    geofence_status: 'in_zone',
                    on_outing: false,
                    locate_now_url: '/fleet-assets/resident-tracking/9012/locate-now',
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
                    speed: 0,
                    geofence_status: 'in_zone',
                    on_outing: false,
                    locate_now_url: '/fleet-assets/resident-tracking/9013/locate-now',
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
                    speed: 0,
                    geofence_status: 'in_zone',
                    on_outing: false,
                    locate_now_url: '/fleet-assets/resident-tracking/9014/locate-now',
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
    inertiaMocks.post.mockClear();
});

it('queues Locate Now from the resident tracking list and shows command state', async () => {
    renderResidentTracking();

    expect(screen.getByText('Queued')).toBeVisible();
    expect(screen.getByText('Battery not reported')).toBeVisible();
    expect(screen.getByText('SOS received')).toBeVisible();
    expect(screen.getByText('Low battery')).toBeVisible();
    expect(screen.getByText('Man down alert')).toBeVisible();
    expect(screen.getByText('Charging')).toBeVisible();

    fireEvent.click(screen.getAllByRole('button', { name: /Locate Now/i })[0]);

    expect(inertiaMocks.post).toHaveBeenCalledWith(
        '/fleet-assets/resident-tracking/9012/locate-now',
        {},
        expect.objectContaining({ preserveScroll: true }),
    );
});
