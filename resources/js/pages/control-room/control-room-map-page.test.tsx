import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import ControlRoomMap from './map';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    url: '/control-room/map',
}));

/** A tiny stand-in for Leaflet: records what the page drew. */
const leaflet = vi.hoisted(() => {
    class Layer {
        popup = '';
        openPopup = vi.fn();
        bindPopup(html: string) {
            this.popup = html;
            return this;
        }
        on() {
            return this;
        }
        addTo() {
            return this;
        }
    }
    const map = {
        setView: vi.fn(),
        fitBounds: vi.fn(),
        invalidateSize: vi.fn(),
        remove: vi.fn(),
    };
    const markers: Layer[] = [];
    return {
        map: vi.fn(() => map),
        tileLayer: vi.fn(() => ({ addTo: vi.fn() })),
        layerGroup: vi.fn(() => ({
            addTo() {
                return this;
            },
            clearLayers: vi.fn(),
        })),
        marker: vi.fn(() => {
            const layer = new Layer();
            markers.push(layer);
            return layer;
        }),
        circle: vi.fn(() => new Layer()),
        polygon: vi.fn(() => new Layer()),
        divIcon: vi.fn(() => ({})),
        latLngBounds: vi.fn(() => ({})),
        instance: map,
        markers,
    };
});

vi.mock('leaflet', () => ({ default: leaflet }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        preserveState: _preserveState,
        preserveScroll: _preserveScroll,
        ...props
    }: {
        href: string;
        children: ReactNode;
        preserveState?: boolean;
        preserveScroll?: boolean;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    usePage: () => ({
        url: inertia.url,
        props: { auth: { can: { controlRoom: { viewAny: true } } } },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    leaflet.markers.length = 0;
});

function minutesAgo(minutes: number): string {
    return new Date(Date.now() - minutes * 60_000).toISOString();
}

function props(
    overrides: Partial<ComponentProps<typeof ControlRoomMap>> = {},
): ComponentProps<typeof ControlRoomMap> {
    return {
        devices: [
            {
                id: 4,
                device_uid: 'PT-AH-03',
                name: 'Personal tracker PT-AH-03',
                type: 'personal_tracker',
                type_label: 'Personal safety tracker',
                status: 'online',
                health_status: 'healthy',
                latitude: -36.85,
                longitude: 174.76,
                battery_level: 15,
                // Relative to the real clock so the fixture never goes old.
                last_seen_at: minutesAgo(10),
                position_source: 'canonical_device',
                context_label: 'Hemi Walker',
                manufacturer: null,
                model: null,
                site_id: 2,
                identity_source: 'canonical',
                privacy_state: 'allowed',
                privacy_basis: 'consent',
                detail_url: '/security-devices/devices/4',
            },
        ],
        sites: [],
        geofences: [],
        alerts: [
            {
                id: 418,
                alert_type: 'geofence_breach',
                severity: 'high',
                status: 'open',
                triggered_at: '2026-10-24T08:00:00Z',
                device_id: 4,
                site_id: 2,
                latitude: -36.85,
                longitude: 174.76,
                detail_url: '/control-room/alerts/418',
            },
        ],
        all_sites: [
            { id: 2, name: 'Aurora House' },
            { id: 3, name: 'Kōwhai Lodge' },
        ],
        stats: {
            total_devices: 6,
            online: 4,
            offline: 1,
            active_alerts: 9,
            location_restricted: 2,
        },
        location_boundary: {
            position_access: true,
            title: 'Location access follows purpose',
            description:
                'Positions come from Tracking. A position only shows when site access, permission, purpose and consent all allow it.',
            canonical_url: '/security-devices/tracking',
        },
        filters: { site_id: '2', type: null, status: null, alert_only: null },
        ...overrides,
    };
}

describe('Monitoring › Map', () => {
    it('opens on the Monitoring header; every number links to its real subset', () => {
        render(<ControlRoomMap {...props()} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Monitoring' })).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Show trackers that are reporting' }),
        ).toHaveAttribute('href', '/control-room/map?site_id=2&status=online');
        expect(
            screen.getByRole('link', { name: 'Show trackers that are offline' }),
        ).toHaveAttribute('href', '/control-room/map?site_id=2&status=offline');
        expect(
            screen.getByRole('link', { name: 'Open the active alerts in Live response' }),
        ).toHaveAttribute('href', '/control-room?site_id=2');
        expect(screen.getByRole('link', { name: 'Open Tracking' })).toHaveAttribute(
            'href',
            '/security-devices/tracking',
        );
        expect(screen.getByRole('checkbox', { name: 'With an active alert' })).toBeInTheDocument();
    });

    it('keeps the location boundary notice and says how many positions are withheld', () => {
        render(<ControlRoomMap {...props()} />);

        expect(screen.getByText('Location access follows purpose')).toBeInTheDocument();
        expect(screen.getByText(/2 trackers have no position shown here/)).toBeInTheDocument();
    });

    it('lists positions person-first and finds one on the map', async () => {
        render(<ControlRoomMap {...props()} />);

        await waitFor(() => expect(leaflet.marker).toHaveBeenCalled());

        const row = screen.getByRole('button', { name: /^Hemi Walker/ });
        expect(within(row).getByText('Aurora House')).toBeInTheDocument();
        expect(within(row).getByText('Active alert')).toBeInTheDocument();
        expect(within(row).getByText('Battery 15%')).toBeInTheDocument();

        fireEvent.click(row);
        expect(leaflet.instance.setView).toHaveBeenCalledWith([-36.85, 174.76], 16);
        expect(within(row).getByText('last seen 10 min ago')).toBeInTheDocument();
        expect(within(row).queryByText('Old position')).not.toBeInTheDocument();
    });

    it('marks a position over 24 hours old in words, with a badge, and draws it faded', async () => {
        const base = props().devices[0];
        render(
            <ControlRoomMap
                {...props({
                    devices: [
                        { ...base, last_seen_at: minutesAgo(3 * 24 * 60 + 5) },
                        {
                            ...base,
                            id: 5,
                            device_uid: 'HIACE-1',
                            name: 'Hiace tracker',
                            type: 'vehicle_tracker',
                            type_label: 'Fleet tracker',
                            context_label: 'Hiace ZX1234',
                            battery_level: null,
                            last_seen_at: minutesAgo(2),
                        },
                    ],
                })}
            />,
        );

        await waitFor(() => expect(leaflet.marker).toHaveBeenCalled());

        const old = screen.getByRole('button', { name: /^Hemi Walker/ });
        expect(within(old).getByText('Old position')).toBeInTheDocument();
        // Online but old: never "Reporting", and the age is said once, in words.
        expect(within(old).queryByText('Reporting')).not.toBeInTheDocument();
        expect(
            within(old).getByText('Last seen 3 days ago, so it may not be where it is now.'),
        ).toBeInTheDocument();
        expect(within(old).queryByText('last seen 3 days ago')).not.toBeInTheDocument();

        const current = screen.getByRole('button', { name: /^Hiace ZX1234/ });
        expect(within(current).queryByText('Old position')).not.toBeInTheDocument();
        expect(within(current).getByText('Recently updated')).toBeInTheDocument();

        expect(screen.getByText(/1 position is over 24 hours old/)).toBeInTheDocument();
        expect(
            within(screen.getByRole('list', { name: 'Map legend' })).getByText(
                'Old position — over 24 hours, faded',
            ),
        ).toBeInTheDocument();

        const icons = leaflet.divIcon.mock.calls.map(
            (call) => (call as unknown as [{ html: string }])[0].html,
        );
        expect(icons.some((html) => html.includes('data-old-position="true"') && html.includes('dashed'))).toBe(true);
        expect(icons.some((html) => html.includes('data-old-position="false"'))).toBe(true);
        const popups = leaflet.markers.map((marker) => marker.popup).join('\n');
        expect(popups).toContain('Last seen 3 days ago, so it may not be where it is now.');
        // The alert raised from that tracker says its position is old too.
        expect(popups).toContain('Old tracker position.');
    });

    it('escapes every value in the popups and uses plain words', async () => {
        render(
            <ControlRoomMap
                {...props({
                    devices: [
                        {
                            ...props().devices[0],
                            context_label: '<img src=x onerror="steal()">',
                        },
                    ],
                })}
            />,
        );

        await waitFor(() => expect(leaflet.markers.length).toBeGreaterThan(1));
        const popups = leaflet.markers.map((marker) => marker.popup).join('\n');
        expect(popups).not.toContain('<img src=x');
        expect(popups).toContain('&lt;img src=x');
        expect(popups).toContain('Geofence breach');
        expect(popups).toContain('High severity');
        expect(popups).toContain('Open device record');
    });
});
