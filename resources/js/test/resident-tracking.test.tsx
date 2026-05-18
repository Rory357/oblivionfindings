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
            residents={[{
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
                battery: 84,
                speed: 0,
                geofence_status: 'in_zone',
                on_outing: false,
                locate_now_url: '/fleet-assets/resident-tracking/9012/locate-now',
                last_command_status: 'queued',
            }]}
            stats={{
                tracked: 1,
                online: 1,
                offline: 0,
                untracked: 0,
                online_percent: 100,
                in_geofence: 1,
                outside_geofence: 0,
                low_battery: 0,
                safety_score: 100,
                avg_battery: 84,
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

    fireEvent.click(screen.getByRole('button', { name: /Locate Now/i }));

    expect(inertiaMocks.post).toHaveBeenCalledWith(
        '/fleet-assets/resident-tracking/9012/locate-now',
        {},
        expect.objectContaining({ preserveScroll: true }),
    );
});
