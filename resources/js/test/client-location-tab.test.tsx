import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

import ClientLocationTab from '@/components/client-location-tab';

const inertiaMocks = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>{children}</a>
    ),
    router: {
        post: inertiaMocks.post,
        reload: inertiaMocks.reload,
    },
}));

vi.mock('@/components/leaflet-map', () => ({
    default: () => <div data-testid="client-location-map" />,
}));

beforeEach(() => {
    inertiaMocks.post.mockClear();
});

it('queues Locate Now from the client location tab and shows command state', async () => {
    render(
        <ClientLocationTab
            clientId={9012}
            clientName="Amelia Wilson"
            location={{
                tracker: {
                    id: 12,
                    name: 'Amelia pendant',
                    serial: 'GL30-1',
                    mac: null,
                    provider: 'queclink',
                    status: 'online',
                    last_seen_at: '2026-05-18T04:00:00Z',
                    battery: 84,
                    battery_status: 'normal',
                    battery_low_threshold: 20,
                    charging_status: 'charging',
                    external_power: true,
                    last_power_event: 'power_on',
                    last_safety_event: 'vehicle_sos',
                    locate_now_url: '/operations/clients/9012/location/locate-now',
                    last_command_status: 'acked',
                },
                currentLocation: {
                    lat: -37.723657,
                    lng: 175.241655,
                    speed: 0,
                    heading: null,
                    accuracy: 8,
                },
                trackingConsent: {
                    status: 'active',
                    given_at: '2026-05-01T00:00:00Z',
                    expires_at: null,
                },
                geofences: [],
            }}
        />,
    );

    expect(screen.getAllByText('Acknowledged')[0]).toBeVisible();
    expect(screen.getByText('Charging')).toBeVisible();
    expect(screen.getByText('SOS received')).toBeVisible();

    fireEvent.click(screen.getByRole('button', { name: /Locate Now/i }));

    expect(inertiaMocks.post).toHaveBeenCalledWith(
        '/operations/clients/9012/location/locate-now',
        {},
        expect.objectContaining({ preserveScroll: true }),
    );
});
