import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import DevicesIndex from './index';
import DeviceShow from './show';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { get: vi.fn() },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/command-centre/command-centre-page', () => ({
    CommandCentrePage: ({
        title,
        description,
        status,
        actions,
        children,
    }: {
        title: string;
        description: string;
        status: string;
        actions?: ReactNode;
        children: ReactNode;
    }) => (
        <main>
            <h1>{title}</h1>
            <p>{description}</p>
            <p>{status}</p>
            {actions}
            {children}
        </main>
    ),
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('Control Room Device projection', () => {
    it('hands canonical identity back to Security & Devices and shows safe signal outcomes', () => {
        render(
            <DeviceShow
                device={
                    {
                        id: 9,
                        name: 'Front entrance camera',
                        device_uid: 'CAM-ABC123',
                        type: 'camera',
                        type_label: 'Camera',
                        vendor: 'Canonical Cameras',
                        model: 'CC-4K',
                        reported_battery_level: 12,
                        last_signal_at: '2026-08-03T08:00:00Z',
                        signal_activity: {
                            state: 'recent',
                            label: 'Signal received in the last 24 hours',
                            tone: 'success',
                        },
                        latitude: null,
                        longitude: null,
                        location_description: 'Front entrance',
                        identity_source: 'canonical',
                        canonical: {
                            id: 44,
                            domain: 'security',
                            category: 'cctv',
                            subcategory: 'camera',
                            status: 'active',
                            health_status: 'healthy',
                            battery_level: 88,
                            last_seen_at: '2026-08-03T08:00:00Z',
                            detail_url: '/security-devices/devices/44',
                        },
                        signal_source: null,
                        site: { id: 2, name: 'Kowhai House' },
                        client: null,
                        asset: null,
                    } as never
                }
                signals={
                    [
                        {
                            id: 1,
                            signal_type_code: 'camera.offline',
                            severity_hint: 'high',
                            occurred_at: '2026-08-03T08:00:00Z',
                            status: 'processed',
                            outcome: {
                                label: 'Processed',
                                tone: 'success',
                                alert_reference: null,
                                href: null,
                            },
                            payload: {
                                credential: 'raw-signal-secret-sentinel',
                            },
                        },
                    ] as never
                }
                alerts={[]}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'Open in Security & Devices' }),
        ).toHaveAttribute('href', '/security-devices/devices/44');
        expect(screen.getByText('Processed')).toBeInTheDocument();
        expect(
            screen.queryByText(/raw-signal-secret-sentinel/),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(/Raw provider payloads are kept out/i),
        ).toBeInTheDocument();
    });

    it('uses the backend device-type catalogue instead of a partial hard-coded tab list', () => {
        render(
            <DevicesIndex
                devices={{ data: [], links: [] }}
                stats={{
                    signal_sources: 0,
                    active_24h: 0,
                    canonical_linked: 0,
                    reconciliation_needed: 0,
                }}
                filters={{
                    type: '',
                    activity: '',
                    site_id: '',
                    linkage: '',
                }}
                sites={[]}
                device_types={{
                    camera: 'Camera',
                    personal_tracker: 'Personal Tracker',
                    vehicle_tracker: 'Vehicle Tracker',
                }}
                can={{ view_canonical_devices: true }}
                canonicalIndexUrl="/security-devices/devices"
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Device signals' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Open Device registry' }),
        ).toHaveAttribute('href', '/security-devices/devices');
        expect(
            screen.getByRole('tab', { name: 'Vehicle Tracker' }),
        ).toBeInTheDocument();
        expect(screen.queryByText('Signal offline')).not.toBeInTheDocument();
        expect(screen.queryByText('Low Battery')).not.toBeInTheDocument();
    });
});
