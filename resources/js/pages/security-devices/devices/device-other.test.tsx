import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DevicePage from './device';
import type { DevicePageProps, NotesTab } from './_device-page-types';
import { meter, minutesAgo, pageFixture } from './device-page-test-fixtures';

const inertia = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), visit: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: { ...actual.router, ...inertia, on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

function meterBlock(label: string): HTMLElement {
    return screen.getByRole('button', { name: new RegExp(`^${label}:`) });
}

function other(kind: 'printer' | 'ups' | 'recorder' | 'sensor_gateway', name: string, overrides: Partial<DevicePageProps> = {}): DevicePageProps {
    const base = pageFixture();
    return pageFixture({
        device: { ...base.device, name, kind, kind_label: name, group: 'other', back: { href: '/security-devices/devices?tab=other', label: 'Devices' } },
        overview: { trend: null, availability: null, events: [], facts: [['Model', 'Synthetic model']] },
        ...overrides,
    });
}

describe('Device page · printers, UPSs, recorders (pg-sdr-10, 16, 21, 5d)', () => {
    beforeEach(() => vi.clearAllMocks());
    afterEach(cleanup);

    it('says Not available for a printer that sends no toner or page counts', () => {
        render(
            <DevicePage
                {...other('printer', 'Synthetic printer', {
                    meters: [
                        meter({ key: 'toner', label: 'Toner' }),
                        meter({ key: 'pages', label: 'Pages this month' }),
                        meter({ key: 'paper', label: 'Paper' }),
                    ],
                })}
            />,
        );

        for (const label of ['Toner', 'Pages this month', 'Paper']) {
            expect(within(meterBlock(label)).getByText('Not available')).toBeInTheDocument();
        }
        expect(screen.getByText(/doesn’t send its toner level or page counts/)).toBeInTheDocument();
        expect(screen.getByText('About this printer')).toBeInTheDocument();
        expect(screen.queryByText(/Open its web page/)).not.toBeInTheDocument();
    });

    it('shows a UPS’s readings when it sends them, and its battery over time', () => {
        render(
            <DevicePage
                {...other('ups', 'Synthetic UPS', {
                    meters: [
                        meter({ key: 'runtime', label: 'Runtime left', value: '41 min', caption: { text: 'Read {ago}', at: minutesAgo(1) } }),
                        meter({ key: 'load', label: 'Load', value: '34%', caption: { text: 'Read {ago}', at: minutesAgo(1) } }),
                        meter({ key: 'battery', label: 'Battery' }),
                        meter({ key: 'power_cut', label: 'Last power cut', caption: { text: 'None recorded', at: null } }),
                    ],
                    overview: {
                        trend: null,
                        availability: null,
                        events: [],
                        facts: [['Model', 'Synthetic UPS 1500']],
                        reading_trend: { title: 'Battery charge', range: '7d', step_seconds: 21_600, start: minutesAgo(60 * 24 * 7), points: Array.from({ length: 28 }, (_, index) => (index === 16 ? 94 : 100)), unit: '%' },
                    },
                })}
            />,
        );

        expect(within(meterBlock('Runtime left')).getByText('41 min')).toBeInTheDocument();
        expect(within(meterBlock('Battery')).getByText('Not available')).toBeInTheDocument();
        expect(within(meterBlock('Last power cut')).getByText('Not available')).toBeInTheDocument();
        expect(screen.getByText('Battery charge')).toBeInTheDocument();
        expect(screen.getByText('Power history')).toBeInTheDocument();
        expect(screen.getByText('About this UPS')).toBeInTheDocument();
    });

    it('says when a recorder has no cameras linked yet', () => {
        render(<DevicePage {...other('recorder', 'Synthetic recorder', { overview: { trend: null, availability: null, events: [], facts: [['Model', 'Synthetic NVR']], cameras: [] } })} />);

        expect(screen.getByText('No cameras are linked to this recorder yet.')).toBeInTheDocument();
        expect(screen.getByText('For IT')).toBeInTheDocument();
    });

    it('shares the Notes & documents tab: documents, expiry, upload', () => {
        const notes: NotesTab = {
            upkeep: [],
            documents: [
                { id: 1, title: 'Synthetic service contract', file: 'PDF', kind: 'Other', added_at: '2026-07-01T00:00:00Z', expiry: { date: '2027-06-30', ended: false, soon: false }, href: '/security-devices/devices/41/documents/1' },
                { id: 2, title: 'Synthetic warranty', file: 'PDF', kind: 'Certificate', added_at: '2023-06-09T00:00:00Z', expiry: { date: '2025-06-09', ended: true, soon: false }, href: '/security-devices/devices/41/documents/2' },
            ],
            kinds: [{ value: 'manual', label: 'Manual' }],
            notes: [],
            purchase: { price: null, asset: null },
        };
        render(<DevicePage {...other('printer', 'Synthetic printer', { tab: 'notes', overview: null, notes, meters: [] })} />);

        expect(screen.getByText('Synthetic service contract')).toBeInTheDocument();
        expect(screen.getByText('Ends 30 Jun 2027')).toBeInTheDocument();
        expect(screen.getByText('Ended 9 Jun 2025')).toBeInTheDocument();
        expect(screen.getByText('No install, service or warranty dates are recorded.')).toBeInTheDocument();
        expect(screen.getByText('No notes yet.')).toBeInTheDocument();
        expect(screen.getByText('No price or asset record is linked.')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Upload document' }));
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByText('Up to 20 MB · kept privately in Oblivion')).toBeInTheDocument();
    });
});
