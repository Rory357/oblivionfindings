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

vi.mock('@/components/client-location/client-location-map', () => ({
    default: () => <div data-testid="client-location-map" />,
}));

beforeEach(() => {
    inertiaMocks.post.mockClear();
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                active: true,
                export_allowed: false,
                access_fingerprint: 'a'.repeat(64),
                available: true,
                request: null,
                checked_at: '2026-09-21T01:00:00Z',
            }),
        }),
    );
});

it('renders the location workspace and opens the governed Locate now workflow', async () => {
    render(
        <ClientLocationTab
            clientId={9012}
            clientName="Amelia Wilson"
            clientHouse="Harbour Respite"
            clientPhoto={null}
            location={{
                privacyStatusUrl: '/privacy-status',
                accessFingerprint: 'a'.repeat(64),
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
                    locate_requests_url:
                        '/operations/clients/9012/location/locate-requests',
                    acknowledge_panic_url:
                        '/operations/clients/9012/location/acknowledge-panic',
                    last_command_status: 'acked',
                    tracking_workspace_url:
                        '/security-devices/tracking?tab=personal-safety',
                    tracking_workspace_access: {
                        state: 'available',
                        label: 'Open Tracking workspace',
                    },
                    detail_url: '/security-devices/devices/12',
                    detail_access: {
                        state: 'available',
                        label: 'Open Device Profile',
                    },
                },
                currentLocation: {
                    lat: -37.723657,
                    lng: 175.241655,
                    address: '12 Example Street, Hamilton',
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

    expect(await screen.findByText('Status unconfirmed')).toBeVisible();
    expect(screen.getByText('12 Example Street, Hamilton')).toBeVisible();
    expect(screen.getByLabelText('Coordinates')).toHaveTextContent(
        '-37.723657, 175.241655',
    );
    expect(screen.getByText('Alert time not recorded')).toBeVisible();
    expect(screen.getAllByText(/Charging/i).length).toBeGreaterThan(0);
    expect(screen.getByText('Acknowledged')).toBeVisible();
    expect(
        screen.getByRole('link', { name: 'Open Tracking workspace' }),
    ).toHaveAttribute('href', '/security-devices/tracking?tab=personal-safety');
    expect(
        screen.getByRole('link', { name: 'Open Device Profile' }),
    ).toHaveAttribute('href', '/security-devices/devices/12');
    expect(
        screen.queryByText('Location Tracking Consent Not Active'),
    ).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Locate Now/i }));

    expect(
        await screen.findByRole('dialog', { name: 'Locate now' }),
    ).toBeVisible();
    expect(
        await screen.findByRole('button', { name: 'Request location' }),
    ).toBeVisible();
    expect(inertiaMocks.post).not.toHaveBeenCalled();
});

it('shows the active panic banner and acknowledges it', async () => {
    render(
        <ClientLocationTab
            clientId={42}
            clientName="Test Person"
            clientHouse="House 1"
            clientPhoto={null}
            location={{
                privacyStatusUrl: '/privacy-status',
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

    expect(
        (await screen.findAllByText('Panic alert active')).length,
    ).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole('button', { name: /Acknowledge/i }));
    expect(inertiaMocks.post).toHaveBeenCalledWith(
        '/operations/clients/42/location/acknowledge-panic',
        {},
        expect.objectContaining({ preserveScroll: true }),
    );
});

it('does not expose tracker commands when the server omits management URLs', async () => {
    render(
        <ClientLocationTab
            clientId={43}
            clientName="Read Only Person"
            clientHouse="House 2"
            clientPhoto={null}
            location={{
                privacyStatusUrl: '/privacy-status',
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
                    tracking_workspace_url: null,
                    tracking_workspace_access: {
                        state: 'restricted',
                        label: 'Tracking workspace access required',
                    },
                    detail_url: null,
                    detail_access: {
                        state: 'restricted',
                        label: 'Device Profile access required',
                    },
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

    await screen.findByText('Read-only pendant');
    expect(
        screen.queryByRole('button', { name: /Locate Now/i }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: /Acknowledge/i }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByText('Tracking workspace access required'),
    ).toBeVisible();
    expect(screen.getByText('Device Profile access required')).toBeVisible();
    expect(
        screen.queryByRole('link', { name: /Tracking workspace/i }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: /Device Profile/i }),
    ).not.toBeInTheDocument();

    expect(inertiaMocks.post).not.toHaveBeenCalled();
});

it('does not offer tracker assignment to a read-only location viewer', async () => {
    render(
        <ClientLocationTab
            clientId={44}
            clientName="Untracked Person"
            clientHouse="House 3"
            clientPhoto={null}
            location={{
                privacyStatusUrl: '/privacy-status',
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
        await screen.findByText('No personal tracker assigned'),
    ).toBeVisible();
    expect(
        screen.queryByRole('link', { name: /Assign Tracker/i }),
    ).not.toBeInTheDocument();
});

it('shows only the inactive-consent state when tracking data is restricted', async () => {
    vi.mocked(fetch).mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: async () => ({ active: false, export_allowed: false }),
    } as Response);

    render(
        <ClientLocationTab
            clientId={45}
            clientName="Consent Restricted Person"
            clientHouse="House 4"
            clientPhoto={null}
            location={{
                privacyStatusUrl: '/privacy-status',
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
        await screen.findByText('Location access is not active'),
    ).toBeVisible();
    expect(
        screen.queryByText('No Personal Tracker Assigned'),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: /Assign Tracker/i }),
    ).not.toBeInTheDocument();
});
