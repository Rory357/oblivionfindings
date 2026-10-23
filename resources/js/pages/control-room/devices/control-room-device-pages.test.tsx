import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import DevicesIndex from './index';
import DeviceShow from './show';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    url: '/control-room/devices',
}));

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
});

function indexProps(
    overrides: Partial<ComponentProps<typeof DevicesIndex>> = {},
): ComponentProps<typeof DevicesIndex> {
    return {
        devices: {
            data: [
                {
                    id: 7,
                    name: 'KL Room 2 bed sensor',
                    device_uid: 'ms-vs121-kl-r2',
                    type: 'bed_sensor',
                    type_label: 'Bed Sensor',
                    vendor: 'Milesight',
                    model: 'VS121',
                    reported_battery_level: 64,
                    last_signal_at: '2026-10-24T08:00:00Z',
                    signal_activity: {
                        state: 'recent',
                        label: 'Signal received in the last 24 hours',
                        tone: 'success',
                    },
                    location_description: null,
                    site_id: 2,
                    site_name: 'Kōwhai Lodge',
                    signal_source_name: 'Milesight gateway',
                    identity_source: 'canonical',
                    canonical_health_status: 'degraded',
                    canonical_status: 'active',
                    canonical_battery_level: 64,
                    canonical_last_seen_at: '2026-10-24T08:00:00Z',
                    canonical_detail_url: '/security-devices/devices/44',
                },
            ],
            links: [],
            current_page: 1,
            last_page: 1,
            total: 1,
            from: 1,
            to: 1,
        },
        stats: {
            signal_sources: 14,
            active_24h: 9,
            canonical_linked: 11,
            reconciliation_needed: 3,
        },
        filters: { type: '', activity: '', site_id: '', linkage: '' },
        sites: [
            { id: 2, name: 'Kōwhai Lodge' },
            { id: 3, name: 'Aurora House' },
        ],
        device_types: {
            camera: 'Camera',
            personal_tracker: 'Personal Tracker',
            vehicle_tracker: 'Vehicle Tracker',
        },
        can: { view_canonical_devices: true },
        canonicalIndexUrl: '/security-devices/devices',
        ...overrides,
    };
}

describe('Monitoring › Signals', () => {
    it('opens on the Monitoring header with linked numbers and the way out to the Device registry', () => {
        render(<DevicesIndex {...indexProps()} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Monitoring' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open Device registry' })).toHaveAttribute(
            'href',
            '/security-devices/devices',
        );
        expect(
            screen.getByRole('link', { name: 'Show sources with no device record' }),
        ).toHaveAttribute('href', '/control-room/devices?linkage=unlinked');
        expect(
            screen.getByRole('link', { name: 'Show sources with a signal in the last 24 hours' }),
        ).toHaveAttribute('href', '/control-room/devices?activity=recent');
        expect(screen.queryByText('Signal offline')).not.toBeInTheDocument();
        expect(screen.queryByText('Low Battery')).not.toBeInTheDocument();
    });

    it('uses the backend device-type catalogue for the type filter, in sentence case', () => {
        render(<DevicesIndex {...indexProps()} />);

        fireEvent.click(screen.getByRole('button', { name: 'All types' }));

        expect(screen.getByRole('button', { name: 'Vehicle tracker' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Personal tracker' })).toBeInTheDocument();
    });

    it('lists each source in a table with its match and health, and the same actions on kebab and right-click', () => {
        render(<DevicesIndex {...indexProps()} />);

        const row = screen.getByRole('link', { name: 'KL Room 2 bed sensor' }).closest('tr')!;
        expect(screen.getByRole('link', { name: 'KL Room 2 bed sensor' })).toHaveAttribute(
            'href',
            '/control-room/devices/7',
        );
        expect(within(row).getByText('Matched')).toBeInTheDocument();
        expect(within(row).getByText('Degraded')).toBeInTheDocument();
        expect(within(row).getByText('Bed sensor · Milesight VS121')).toBeInTheDocument();

        fireEvent.click(within(row).getByRole('button', { name: 'Actions for KL Room 2 bed sensor' }));
        const kebab = screen.getAllByRole('menuitem').map((item) => item.textContent);
        expect(kebab).toEqual([
            'Open signal record',
            'Open in Security & Devices',
            'Copy source ID',
        ]);
        fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' });

        fireEvent.contextMenu(row);
        expect(screen.getAllByRole('menuitem').map((item) => item.textContent)).toEqual(kebab);
    });

    it('hides device matching and health from people without Security & Devices access', () => {
        render(
            <DevicesIndex
                {...indexProps({
                    can: { view_canonical_devices: false },
                    canonicalIndexUrl: null,
                    stats: {
                        signal_sources: 14,
                        active_24h: 9,
                        canonical_linked: null,
                        reconciliation_needed: null,
                    },
                })}
            />,
        );

        expect(screen.queryByRole('link', { name: 'Open Device registry' })).not.toBeInTheDocument();
        expect(screen.queryByText('Needs matching')).not.toBeInTheDocument();
        expect(screen.queryByRole('columnheader', { name: 'Device health' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Any device match' })).not.toBeInTheDocument();
        expect(screen.getByText(/Device matching and health show only/)).toBeInTheDocument();
    });
});

describe('Monitoring › one signal source', () => {
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
                alerts={[
                    {
                        id: 31,
                        reference_number: 'CR-2026-00399',
                        alert_type: 'camera_offline',
                        severity: 'high',
                        status: 'open',
                        health_status: null,
                        triggered_at: '2026-08-03T08:00:00Z',
                    },
                ]}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'Open in Security & Devices' }),
        ).toHaveAttribute('href', '/security-devices/devices/44');
        expect(screen.getByRole('link', { name: 'Open linked alert' })).toHaveAttribute(
            'href',
            '/control-room/alerts/31',
        );
        expect(screen.getByRole('link', { name: 'Back' })).toHaveAttribute(
            'href',
            '/control-room/devices',
        );
        const signalRow = screen.getByText('Processed').closest('tr')!;
        expect(within(signalRow).getByText('Camera offline')).toBeInTheDocument();
        expect(within(signalRow).getByText('High severity')).toBeInTheDocument();
        // The alert row leads with what happened; its reference goes small.
        const alertRow = screen.getByText('CR-2026-00399').closest('tr')!;
        expect(within(alertRow).getByRole('link', { name: 'Camera offline' })).toHaveAttribute(
            'href',
            '/control-room/alerts/31',
        );
        expect(screen.queryByText(/raw-signal-secret-sentinel/)).not.toBeInTheDocument();
        expect(screen.getByText(/Raw provider payloads are kept out/i)).toBeInTheDocument();
        // The canonical battery wins over the signal's own report.
        expect(screen.getAllByText('88%').length).toBeGreaterThan(0);
        expect(screen.queryByText('12%')).not.toBeInTheDocument();
    });

    it('tells the operator to match a signal-only source in Security & Devices', () => {
        render(
            <DeviceShow
                device={
                    {
                        id: 10,
                        name: null,
                        device_uid: 'LEAK-01',
                        type: 'environmental',
                        type_label: 'Environmental Sensor',
                        vendor: null,
                        model: null,
                        reported_battery_level: null,
                        last_signal_at: null,
                        signal_activity: { state: 'never', label: 'No signal recorded', tone: 'muted' },
                        latitude: null,
                        longitude: null,
                        location_description: null,
                        identity_source: 'signal_projection',
                        canonical: null,
                        signal_source: null,
                        site: null,
                        client: null,
                        asset: null,
                    } as never
                }
                signals={[]}
                alerts={[]}
            />,
        );

        expect(screen.getByRole('heading', { level: 1, name: 'LEAK-01' })).toBeInTheDocument();
        expect(
            screen.getByText(/No authorised device record is linked\. Reconcile this signal source/),
        ).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Open in Security & Devices' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Open linked alert' })).not.toBeInTheDocument();
        expect(screen.getByText('No signals to show')).toBeInTheDocument();
    });
});
