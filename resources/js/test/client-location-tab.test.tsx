import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

import ClientLocationTab from '@/components/client-location-tab';

const inertiaMocks = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
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

it('renders the unified resident sidebar and queues Locate Now', () => {
    render(
        <ClientLocationTab
            clientId={9012}
            clientName="Amelia Wilson"
            clientHouse="Harbour Respite"
            clientPhoto={null}
            location={{
                canManage: true,
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
                    last_safety_event: null,
                    last_safety_event_at: null,
                    panic_active: false,
                    locate_now_url:
                        '/operations/clients/9012/location/locate-now',
                    acknowledge_panic_url:
                        '/operations/clients/9012/location/acknowledge-panic',
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
                    status: 'given',
                    given_at: '2026-05-01T00:00:00Z',
                    expires_at: null,
                },
                geofences: [],
                geofenceStatus: 'unknown',
            }}
        />,
    );

    expect(screen.getByText('Panic not currently active')).toBeVisible();
    expect(screen.getByText('No panic events recorded')).toBeVisible();
    expect(screen.getAllByText(/Charging/i).length).toBeGreaterThan(0);
    expect(screen.getByText('Acknowledged')).toBeVisible();
    expect(
        screen.queryByText('Location Tracking Consent Not Active'),
    ).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Locate Now/i }));

    expect(inertiaMocks.post).toHaveBeenCalledWith(
        '/operations/clients/9012/location/locate-now',
        {},
        expect.objectContaining({ preserveScroll: true }),
    );
});

it('shows the active panic banner and acknowledges it', () => {
    render(
        <ClientLocationTab
            clientId={42}
            clientName="Test Person"
            clientHouse="House 1"
            clientPhoto={null}
            location={{
                canManage: true,
                tracker: {
                    id: 1,
                    name: 'Pendant',
                    serial: 'SN-1',
                    mac: null,
                    provider: 'queclink',
                    status: 'online',
                    last_seen_at: '2026-05-18T04:00:00Z',
                    battery: 64,
                    battery_status: 'normal',
                    battery_low_threshold: 20,
                    panic_active: true,
                    last_safety_event: 'sos',
                    last_safety_event_at: '2026-05-18T04:00:00Z',
                    locate_now_url:
                        '/operations/clients/42/location/locate-now',
                    acknowledge_panic_url:
                        '/operations/clients/42/location/acknowledge-panic',
                },
                currentLocation: {
                    lat: 0,
                    lng: 0,
                    speed: null,
                    heading: null,
                    accuracy: null,
                },
                trackingConsent: {
                    status: 'active',
                    given_at: null,
                    expires_at: null,
                },
                geofences: [],
                geofenceStatus: 'unknown',
            }}
        />,
    );

    expect(screen.getByText('SOS received')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: /Acknowledge/i }));
    expect(inertiaMocks.post).toHaveBeenCalledWith(
        '/operations/clients/42/location/acknowledge-panic',
        {},
        expect.objectContaining({ preserveScroll: true }),
    );
});

it('does not expose tracker commands when the server omits management URLs', () => {
    render(
        <ClientLocationTab
            clientId={43}
            clientName="Read Only Person"
            clientHouse="House 2"
            clientPhoto={null}
            location={{
                canManage: false,
                tracker: {
                    id: 2,
                    name: 'Read-only pendant',
                    serial: 'SN-2',
                    mac: null,
                    provider: 'queclink',
                    status: 'online',
                    last_seen_at: '2026-05-18T04:00:00Z',
                    battery: 64,
                    panic_active: true,
                    last_safety_event: 'sos',
                    last_safety_event_at: '2026-05-18T04:00:00Z',
                },
                currentLocation: {
                    lat: 0,
                    lng: 0,
                    speed: null,
                    heading: null,
                    accuracy: null,
                },
                trackingConsent: {
                    status: 'active',
                    given_at: null,
                    expires_at: null,
                },
                geofences: [],
                geofenceStatus: 'unknown',
            }}
        />,
    );

    expect(screen.getByRole('button', { name: /Locate Now/i })).toBeDisabled();
    expect(
        screen.queryByRole('button', { name: /Acknowledge/i }),
    ).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Locate Now/i }));
    expect(inertiaMocks.post).not.toHaveBeenCalled();
});

it('does not offer tracker assignment to a read-only location viewer', () => {
    render(
        <ClientLocationTab
            clientId={44}
            clientName="Untracked Person"
            clientHouse="House 3"
            clientPhoto={null}
            location={{
                canManage: false,
                tracker: null,
                currentLocation: null,
                trackingConsent: null,
                geofences: [],
                geofenceStatus: 'unknown',
            }}
        />,
    );

    expect(screen.getByText('No Personal Tracker Assigned')).toBeVisible();
    expect(
        screen.queryByRole('link', { name: /Assign Tracker/i }),
    ).not.toBeInTheDocument();
});

it('shows only the inactive-consent state when tracking data is restricted', () => {
    render(
        <ClientLocationTab
            clientId={45}
            clientName="Consent Restricted Person"
            clientHouse="House 4"
            clientPhoto={null}
            location={{
                trackingRestricted: true,
                canManage: false,
                tracker: null,
                currentLocation: null,
                trackingConsent: null,
                geofences: [],
                geofenceStatus: 'unknown',
            }}
        />,
    );

    expect(
        screen.getByText('Location Tracking Consent Not Active'),
    ).toBeVisible();
    expect(
        screen.queryByText('No Personal Tracker Assigned'),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: /Assign Tracker/i }),
    ).not.toBeInTheDocument();
});
