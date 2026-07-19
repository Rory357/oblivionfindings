import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import {
    TrackingWorkspacePanels,
    type TrackingWorkspaceData,
} from './tracking-workspace';

vi.mock('@/components/leaflet-map', () => ({
    default: ({ markers = [], geofences = [] }) => (
        <div data-testid="tracking-map">
            {markers.length} markers, {geofences.length} geofences
        </div>
    ),
}));

const base: TrackingWorkspaceData = {
    permissions: {
        personalSafety: true,
        fleet: true,
        assets: true,
        telemetry: true,
        geofences: true,
    },
    boundary: {
        title: 'Location access follows purpose',
        description:
            'Technical device access does not grant personal or operational location access.',
        retentionDays: 365,
    },
    overview: {
        inventory: {
            total: 5,
            personal_safety: 2,
            fleet: 1,
            assets: 2,
        },
        attention: {
            offline: 1,
            low_battery: 1,
            consent_blocked: 1,
            unassigned: 1,
            stale: 1,
        },
        groups: [
            {
                key: 'personal-safety',
                label: 'Personal safety',
                count: 2,
                description: 'Client and lone-worker safety trackers.',
                href: '/security-devices/tracking?tab=personal-safety',
            },
            {
                key: 'fleet',
                label: 'Fleet',
                count: 1,
                description: 'Vehicle tracker hardware.',
                href: '/security-devices/tracking?tab=fleet',
            },
            {
                key: 'assets',
                label: 'Assets',
                count: 2,
                description: 'Tracked operational assets.',
                href: '/security-devices/tracking?tab=assets',
            },
        ],
        requiredActions: [
            {
                key: 'consent-blocked',
                label: 'Location access blocked',
                count: 1,
                description: 'Review purpose or consent in the owning module.',
                href: '/security-devices/tracking?tab=personal-safety',
            },
        ],
    },
    activeTab: {
        key: 'overview',
        label: 'Overview',
        description: 'Tracking posture by purpose.',
        restricted: false,
        inventoryTotal: 5,
        inventoryShown: 0,
        inventoryTruncated: false,
        devices: [],
        markers: [],
        geofences: [],
        history: [],
        retentionDays: 365,
    },
};

const clientDevice: TrackingWorkspaceData['activeTab']['devices'][number] = {
    id: 11,
    name: 'Ani safety pendant',
    category: 'personal_tracker',
    subcategory: 'pendant',
    status: 'active',
    health: 'healthy',
    battery: 82,
    lastSeenAt: '2026-07-19T04:00:00.000Z',
    deviceHref: '/security-devices/devices/11',
    group: 'personal-safety',
    person: {
        id: 7,
        displayName: 'Ani',
        href: '/clients/7',
    },
    asset: null,
    personalSafety: {
        personType: 'client',
        purposeLabel: 'Personal safety location tracking',
        sessionStatus: null,
    },
    privacy: {
        state: 'active',
        basis: 'active_client_tracking_consent',
        locationAllowed: true,
        reason: 'Active client tracking consent and destination permissions.',
        expiresAt: '2026-08-19T04:00:00.000Z',
    },
    location: {
        latitude: -36.8485,
        longitude: 174.7633,
        observedAt: '2026-07-19T04:00:00.000Z',
        source: 'canonical_device',
    },
    canonicalHref: '/operations/clients/7?tab=location',
    historyHref: '/operations/clients/7?tab=location',
};

describe('TrackingWorkspacePanels', () => {
    it('separates personal, fleet and asset tracking at a glance', () => {
        render(<TrackingWorkspacePanels data={base} />);

        expect(
            screen.getByRole('heading', { name: 'Tracking at a glance' }),
        ).toBeInTheDocument();
        expect(screen.getByText('2 personal safety')).toBeInTheDocument();
        expect(screen.getByText('1 Fleet')).toBeInTheDocument();
        expect(screen.getByText('2 asset tracking')).toBeInTheDocument();
        expect(
            screen.getByRole('heading', {
                name: 'Location access follows purpose',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Personal safety/ }),
        ).toHaveAttribute(
            'href',
            '/security-devices/tracking?tab=personal-safety',
        );
    });

    it('shows active client consent, minimum identity and canonical links', () => {
        render(
            <TrackingWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'personal-safety',
                        label: 'Personal safety',
                        inventoryTotal: 1,
                        inventoryShown: 1,
                        devices: [clientDevice],
                        markers: [
                            {
                                id: 11,
                                lat: -36.8485,
                                lng: 174.7633,
                                title: 'Ani safety pendant',
                                type: 'default',
                                status: 'online',
                            },
                        ],
                    },
                }}
            />,
        );

        const card = screen.getByRole('article', {
            name: 'Ani safety pendant',
        });
        expect(within(card).getByText('Ani')).toBeInTheDocument();
        expect(within(card).getByText('Consent active')).toBeInTheDocument();
        expect(
            within(card).getByText('Personal safety location tracking'),
        ).toBeInTheDocument();
        expect(within(card).getByText(/Last location/)).toBeInTheDocument();
        expect(
            within(card).getByRole('link', { name: 'Open client location' }),
        ).toHaveAttribute('href', '/operations/clients/7?tab=location');
        expect(screen.getByTestId('tracking-map')).toHaveTextContent(
            '1 markers',
        );
    });

    it('keeps withdrawn or unknown purpose visible as blocked without location', () => {
        render(
            <TrackingWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'personal-safety',
                        label: 'Personal safety',
                        inventoryTotal: 1,
                        inventoryShown: 1,
                        devices: [
                            {
                                ...clientDevice,
                                privacy: {
                                    state: 'withdrawn',
                                    basis: 'none',
                                    locationAllowed: false,
                                    reason: 'Tracking consent was withdrawn.',
                                    expiresAt: null,
                                },
                                location: null,
                                historyHref: null,
                            },
                        ],
                        markers: [],
                    },
                }}
            />,
        );

        const card = screen.getByRole('article', {
            name: 'Ani safety pendant',
        });
        expect(within(card).getByText('Consent withdrawn')).toBeInTheDocument();
        expect(
            within(card).getByText('Tracking consent was withdrawn.'),
        ).toBeInTheDocument();
        expect(
            within(card).queryByText(/Last location/),
        ).not.toBeInTheDocument();
        expect(screen.queryByTestId('tracking-map')).not.toBeInTheDocument();
    });

    it('renders canonical geofences and retained history without raw envelopes', () => {
        const geofenceData: TrackingWorkspaceData = {
            ...base,
            activeTab: {
                ...base.activeTab,
                key: 'geofences',
                label: 'Geofences',
                inventoryTotal: 1,
                geofences: [
                    {
                        id: 3,
                        name: 'Fleet depot',
                        type: 'circle',
                        scope: 'vehicle',
                        active: true,
                        shape: {
                            center: { lat: -36.85, lng: 174.76 },
                            radius_m: 200,
                        },
                        subjectLabel: 'Community van',
                        canonicalHref: '/fleet-assets/geofences',
                        privacy: {
                            state: 'operational',
                            basis: 'fleet_operations',
                        },
                    },
                ],
            },
        };
        const { rerender } = render(
            <TrackingWorkspacePanels data={geofenceData} />,
        );

        expect(screen.getByText('Fleet depot')).toBeInTheDocument();
        expect(screen.getByText('Community van')).toBeInTheDocument();
        expect(screen.getByTestId('tracking-map')).toHaveTextContent(
            '1 geofences',
        );

        rerender(
            <TrackingWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'history',
                        label: 'History',
                        history: [
                            {
                                id: 8,
                                eventType: 'location_report',
                                occurredAt: '2026-07-19T04:00:00.000Z',
                                deviceName: 'Community van tracker',
                                subjectLabel: 'Community van',
                                group: 'fleet',
                                latitude: -36.85,
                                longitude: 174.76,
                                battery: 80,
                                speed: 0,
                                canonicalHref: '/fleet-assets/vehicles/2',
                            },
                        ],
                    },
                }}
            />,
        );
        expect(
            screen.getByText('Retained location history'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('365-day retention window'),
        ).toBeInTheDocument();
        expect(screen.getByText('Location Report')).toBeInTheDocument();
    });

    it('shows an honest restricted state with no counts or map', () => {
        render(
            <TrackingWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'fleet',
                        label: 'Fleet',
                        restricted: true,
                        inventoryTotal: 0,
                        inventoryShown: 0,
                    },
                }}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Restricted workspace' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Fleet permission is required/),
        ).toBeInTheDocument();
        expect(screen.queryByTestId('tracking-map')).not.toBeInTheDocument();
    });
});
