import { render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/page-shell', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/leaflet-map', () => ({
    default: () => <div data-testid="fleet-map" />,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
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
        get: vi.fn(),
        post: vi.fn(),
        reload: vi.fn(),
    },
}));

import VehiclesIndex from './index';

describe('Fleet vehicle freshness', () => {
    it('formats the last-seen timestamp for NZ workers instead of exposing raw ISO', () => {
        render(
            <VehiclesIndex
                vehicles={[
                    {
                        id: 66,
                        name: 'Demo Van',
                        asset_tag: 'DEMO-VAN',
                        status: 'active',
                        state: {
                            status: 'offline',
                            lat: -36.8485,
                            lng: 174.7633,
                            speed_kph: 12,
                            battery_pct: 76,
                            last_seen_at: '2026-05-02T04:30:19.000000Z',
                        },
                        home_site: null,
                    },
                ]}
                sites={[]}
                hero={{ total: 1, available: 1, in_use: 0, maintenance: 0 }}
                compliance={{
                    wof_due: 0,
                    wof_expired: 0,
                    rego_due: 0,
                    rego_expired: 0,
                    cof_due: 0,
                    cof_expired: 0,
                    insurance_expiring: null,
                    insurance_expired: null,
                    open_alerts: 0,
                    critical_alerts: 0,
                }}
                can={{ manage: false }}
            />,
        );

        expect(screen.getByText('Last seen: Sat 2 May, 4:30 pm')).toBeVisible();
        expect(screen.queryByText(/2026-05-02T04:30:19/)).toBeNull();
    });
});
