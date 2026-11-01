import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DevicePage from './device';

// S&D Phase G (pop-up 22): IT's own remote support dialog, opened in place.
vi.mock('@/pages/it/remote-support/_dialogs', () => ({
    RemoteConnectionDialog: ({ open, device, actorId }: { open: boolean; device: { id: number; name: string }; actorId: number }) =>
        open ? <div role="dialog" aria-label="Remote support">{`${device.name} · actor ${actorId}`}</div> : null,
}));
import type { ComputerHealth, DevicePageProps, RemoteSupport } from './_device-page-types';
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

function computer(overrides: Partial<DevicePageProps> = {}): DevicePageProps {
    const base = pageFixture();
    return pageFixture({
        device: {
            ...base.device,
            name: 'Synthetic office PC',
            kind: 'computer',
            kind_label: 'Computer',
            group: 'computer',
            model: 'Synthetic Desk 400',
            subline: [
                { text: 'Synthetic Desk 400', at: null },
                { text: 'Synthetic House', at: null },
                { text: 'checked {ago} by Obli', at: minutesAgo(1) },
            ],
            back: { href: '/security-devices/devices?tab=computers', label: 'Devices' },
        },
        tabs: [
            { key: 'overview', label: 'Overview' },
            { key: 'health', label: 'Health' },
            { key: 'remote', label: 'Remote support' },
            { key: 'checks', label: 'Checks & alerts' },
            { key: 'notes', label: 'Notes & documents' },
        ],
        meters: [
            { ...meter({ key: 'disk', label: 'Disk used', value: '148 GB of 238 GB', caption: { text: 'C: drive', at: null }, tab: 'health' }), bar: 62 },
            meter({ key: 'restart', label: 'Last restart', ago: minutesAgo(60 * 24 * 3), caption: { text: '{time}', at: minutesAgo(60 * 24 * 3) }, tab: 'health' }),
            meter({ key: 'obli', label: 'Obli', value: '1.3.0', caption: { text: 'Heard from {ago}', at: minutesAgo(1) }, tone: 'success' }),
        ],
        actions: { ping: true, backup: null, remote_href: '/it/remote-support?device=41' },
        overview: {
            now: { read_at: minutesAgo(1), cpu: 12, memory: 49, disk: 62 },
            restricted: false,
            safety: [
                { label: 'Antivirus on', state: 'success', note: 'Microsoft Defender · running' },
                { label: 'Firewall on', state: 'success', note: 'Windows Firewall · running' },
                { label: 'Drive encrypted', state: 'none', note: 'BitLocker · not available' },
                { label: 'Files backed up', state: 'none', note: 'Not available' },
            ],
            events: [],
            facts: [
                ['Model', 'Synthetic Desk 400'],
                ['Windows', '11 Pro 24H2'],
                ['Intune', 'Not connected'],
            ],
        },
        ...overrides,
    });
}

describe('Device page · computer (pg-sdr-9, 9b, 9c)', () => {
    beforeEach(() => vi.clearAllMocks());
    afterEach(cleanup);

    it('draws the computer overview from what Obli sends, with remote support one click away', () => {
        render(<DevicePage {...computer()} />);

        expect(screen.getByRole('heading', { name: 'Synthetic office PC' })).toBeInTheDocument();
        expect(screen.getByText(/checked 1 min ago by Obli/)).toBeInTheDocument();
        expect(within(meterBlock('Disk used')).getByText('148 GB of 238 GB')).toBeInTheDocument();
        expect(within(meterBlock('Obli')).getByText('1.3.0')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Start remote support' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Start remote support' }));
        expect(screen.getByRole('dialog', { name: 'Remote support' })).toHaveTextContent('Synthetic office PC · actor 7');

        const rail = screen.getByRole('tablist', { name: /views/ });
        expect(within(rail).getByText('Health')).toBeInTheDocument();
        expect(within(rail).getByText('Remote support')).toBeInTheDocument();
        // Obli help (Phase I), Software and Settings have no approved drawing or build yet.
        for (const hidden of ['Obli help', 'Software', 'Settings']) {
            expect(within(rail).queryByText(hidden)).not.toBeInTheDocument();
        }
        expect(screen.queryByRole('button', { name: 'Restart' })).not.toBeInTheDocument();

        expect(screen.getByText('Right now')).toBeInTheDocument();
        expect(screen.getByText('49%')).toBeInTheDocument();
        expect(screen.getByText('Safety checks')).toBeInTheDocument();
        expect(screen.getByText('BitLocker · not available')).toBeInTheDocument();
    });

    it('says Not available when Obli hasn’t sent its details', () => {
        render(
            <DevicePage
                {...computer({
                    meters: [
                        meter({ key: 'disk', label: 'Disk used', caption: { text: 'Not sent by Obli yet', at: null }, tab: 'health' }),
                        meter({ key: 'obli', label: 'Obli', caption: { text: 'Obli isn’t installed on it', at: null } }),
                    ],
                    actions: { ping: false, backup: null, remote_href: null },
                    overview: { now: null, restricted: false, safety: [], events: [], facts: [['Model', '—']] },
                })}
            />,
        );

        expect(within(meterBlock('Disk used')).getByText('Not available')).toBeInTheDocument();
        expect(within(meterBlock('Obli')).getByText('Not available')).toBeInTheDocument();
        expect(screen.getByText('Not available: Obli hasn’t sent this computer’s details.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Start remote support' })).not.toBeInTheDocument();
    });

    it('lists past remote support and says sessions aren’t recorded', () => {
        const remote: RemoteSupport = {
            ready: [
                { label: 'PC online', state: 'success', note: null },
                { label: 'Obli 1.3.0 running', state: 'success', note: null },
                { label: 'Remote support turned on for this PC', state: 'success', note: null },
            ],
            asked_every_time: true,
            can_control: true,
            max_minutes: 30,
            start_href: '/it/remote-support?device=41',
            sessions: [
                { key: 'session-1', at: minutesAgo(60 * 24 * 2), who: 'Synthetic Tech', outcome: 'Allowed at the PC', tone: 'success', minutes: 18, why: 'Printer driver' },
                { key: 'session-2', at: minutesAgo(60 * 24 * 9), who: 'Synthetic Tech', outcome: 'Declined at the PC', tone: 'none', minutes: null, why: 'Windows updates' },
            ],
        };
        render(<DevicePage {...computer({ tab: 'remote', overview: null, remote })} />);

        expect(screen.getByText('How remote support works')).toBeInTheDocument();
        expect(screen.getByText('Sessions are not recorded.')).toBeInTheDocument();
        expect(screen.getByText('Synthetic Tech · Allowed at the PC')).toBeInTheDocument();
        expect(screen.getByText('18 min · Printer driver')).toBeInTheDocument();
        expect(screen.getByText('Synthetic Tech · Declined at the PC')).toBeInTheDocument();
        expect(screen.getByText('Asked every time')).toBeInTheDocument();
    });

    it('shows Health from Obli’s readings and its gaps', () => {
        const health: ComputerHealth = {
            read_at: minutesAgo(1),
            restricted: false,
            available: true,
            safety: [
                {
                    name: 'Windows updates',
                    sub: 'Windows Update and background downloads',
                    flag: { label: 'On', tone: 'success' },
                    facts: [
                        { tone: 'success', text: 'Windows Update · Running' },
                        { tone: 'none', text: 'Updates waiting and restart needed: Not available, Obli can’t read them yet' },
                    ],
                },
            ],
            disks: [{ name: 'C:', used: '148 GB', total: '238 GB', percent: 62 }],
            services: [{ label: 'Print spooler', state: 'success', note: 'Running' }],
            facts: [['Model', 'Synthetic Desk 400']],
            warranty_until: null,
            programs: 86,
        };
        render(<DevicePage {...computer({ tab: 'health', overview: null, health })} />);

        expect(screen.getByText('Windows updates')).toBeInTheDocument();
        expect(screen.getByText(/Updates waiting and restart needed: Not available/)).toBeInTheDocument();
        expect(screen.getByText('148 GB of 238 GB · 62%')).toBeInTheDocument();
        expect(screen.getByText('Print spooler')).toBeInTheDocument();
        expect(screen.getByText('Not recorded')).toBeInTheDocument();
        expect(screen.getByText('86 programs installed')).toBeInTheDocument();
    });
});
