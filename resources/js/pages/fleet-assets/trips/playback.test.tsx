import { render, screen, waitFor } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import FleetTripPlayback from './playback';

const leafletMap = vi.hoisted(() => ({
    render: vi.fn(),
}));

vi.mock('@/components/leaflet-map', () => ({
    default: (props: unknown) => {
        leafletMap.render(props);

        return <div data-testid="fleet-trip-map" />;
    },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/components/page-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/components/confirm-dialog', () => ({
    ConfirmDialog: () => null,
}));

vi.mock('@/pages/fleet-assets/components/fleet-compact-hero', () => ({
    FleetCompactHero: ({
        title,
        stats,
        actions,
    }: {
        title: ReactNode;
        stats: ReactNode;
        actions: ReactNode;
    }) => (
        <div>
            {title}
            {stats}
            {actions}
        </div>
    ),
    CompactHeroStat: ({ label, value }: { label: string; value: string }) => (
        <div>
            {label}: {value}
        </div>
    ),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: {
        delete: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
    },
}));

const props: ComponentProps<typeof FleetTripPlayback> = {
    trip: {
        id: 205,
        asset_id: 99,
        asset: {
            id: 99,
            name: 'Harbour Van',
            asset_tag: 'HARBOUR-VAN',
        },
        driver_session_id: null,
        driver: null,
        started_at: '2026-09-01T00:00:00.000000Z',
        ended_at: '2026-09-01T01:00:00.000000Z',
        start_latitude: -36.81,
        start_longitude: 174.71,
        end_latitude: -36.82,
        end_longitude: 174.72,
        distance_km: 12.5,
        duration_s: 3600,
        status: 'closed',
        consent_blocked: false,
    },
    driver_sessions: [],
    can: { manage: false },
};

function stubPlaybackResponse(truncated: boolean): void {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            json: vi.fn().mockResolvedValue({
                trip_id: 205,
                truncated,
                points: [
                    {
                        occurred_at: '2026-09-01T00:00:00.000000Z',
                        lat: '-36.8100000',
                        lng: '174.7100000',
                        speed_kph: '42.50',
                    },
                ],
            }),
        }),
    );
}

function latestMapProps(): {
    polyline: Array<{ lat: number; lng: number }>;
    polylineOptions?: { showEndpoints?: boolean };
} {
    return leafletMap.render.mock.lastCall?.[0] as {
        polyline: Array<{ lat: number; lng: number }>;
        polylineOptions?: { showEndpoints?: boolean };
    };
}

beforeEach(() => {
    leafletMap.render.mockReset();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('Fleet trip playback completeness', () => {
    it('warns that a truncated route omits the true endpoint and hides endpoint markers', async () => {
        stubPlaybackResponse(true);

        render(<FleetTripPlayback {...props} />);

        expect(
            await screen.findByText('Route preview is incomplete'),
        ).toBeVisible();
        expect(
            screen.getByText(
                'Only the first 2,000 route points are shown. The true trip endpoint is not shown.',
            ),
        ).toBeVisible();
        await waitFor(() => {
            expect(latestMapProps()).toMatchObject({
                polyline: [{ lat: -36.81, lng: 174.71 }],
                polylineOptions: { showEndpoints: false },
            });
        });
    });

    it('keeps endpoint markers for a complete route without an incomplete warning', async () => {
        stubPlaybackResponse(false);

        render(<FleetTripPlayback {...props} />);

        await waitFor(() => {
            expect(latestMapProps()).toMatchObject({
                polyline: [{ lat: -36.81, lng: 174.71 }],
                polylineOptions: { showEndpoints: true },
            });
        });
        expect(
            screen.queryByText('Route preview is incomplete'),
        ).not.toBeInTheDocument();
    });
});
