import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DevicePage from './device';
import type { BackupAction, ChecksTab, TroubleState } from './_device-page-types';
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

describe('Device page · network gear (pg-sdr-3, 3c, 17, 20)', () => {
    beforeEach(() => vi.clearAllMocks());
    afterEach(cleanup);

    it('draws the switch page: header numbers, tabs as drawn without the unbuilt ones, and the overview', () => {
        render(<DevicePage {...pageFixture()} />);

        expect(screen.getByRole('heading', { name: 'Synthetic switch' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Back' })).toHaveAttribute('href', '/security-devices/devices?tab=network');
        expect(within(meterBlock('Reply time')).getByText('2 ms')).toBeInTheDocument();
        expect(within(meterBlock('Ports in use')).getByText('18 of 24')).toBeInTheDocument();
        expect(within(meterBlock('Settings')).getByText('No changes')).toBeInTheDocument();
        expect(within(meterBlock('Up for')).getByText('41 days')).toBeInTheDocument();

        const rail = screen.getByRole('tablist', { name: /views/ });
        expect(within(rail).getByText('Checks & alerts')).toBeInTheDocument();
        expect(within(rail).getByText('Notes & documents')).toBeInTheDocument();
        for (const hidden of ['Terminal', 'Ports', 'History', 'Settings']) {
            expect(within(rail).queryByText(hidden)).not.toBeInTheDocument();
        }
        // Phase G and things with no source are never offered.
        expect(screen.queryByText(/Open terminal|Open its web page|Firmware|Restart port|Turn port/i)).not.toBeInTheDocument();

        expect(screen.getByText('Reply time', { selector: 'h3' })).toBeInTheDocument();
        expect(screen.getByText('Connected to')).toBeInTheDocument();
        expect(screen.getByText('Synthetic gateway')).toBeInTheDocument();
        expect(screen.getByText('99.9%')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'See change' })).toBeInTheDocument();
    });

    it('says Not available, never 0, when nothing is recorded', () => {
        render(
            <DevicePage
                {...pageFixture({
                    meters: [
                        meter({ key: 'reply', label: 'Reply time', caption: { text: 'Not checked by Oblivion', at: null }, tab: 'checks' }),
                        meter({ key: 'uptime', label: 'Up for', caption: { text: 'Not reported by this device', at: null } }),
                        meter({ key: 'ports', label: 'Ports in use', caption: { text: 'Ports aren’t read from it', at: null }, tab: 'checks' }),
                    ],
                    overview: { trend: null, availability: null, events: [], facts: [['Model', 'Synthetic SW-24']], connected: null },
                })}
            />,
        );

        for (const label of ['Reply time', 'Up for', 'Ports in use']) {
            expect(within(meterBlock(label)).getByText('Not available')).toBeInTheDocument();
            expect(within(meterBlock(label)).queryByText('0')).not.toBeInTheDocument();
        }
        expect(screen.getByText(/no reply times recorded at this Site/)).toBeInTheDocument();
        expect(screen.getByText(/it hasn’t been checked at this Site/)).toBeInTheDocument();
        expect(screen.getByText('Nothing recorded at this Site yet.')).toBeInTheDocument();
        expect(screen.queryByText('Connected to')).not.toBeInTheDocument();
    });

    it('shows the router-in-trouble state with the devices behind it in grey', () => {
        const trouble: TroubleState = {
            since: minutesAgo(120),
            checked_by: 'Synthetic hub',
            last_try_at: minutesAgo(1),
            every_seconds: 60,
            last_reply: { at: minutesAgo(121), latency_ms: 3 },
            missed: { count: 120, first_at: minutesAgo(120), last_at: minutesAgo(1) },
            behind: [
                { id: 50, name: 'Synthetic recorder', kind: 'recorder', model: 'Synthetic NVR', state: 'cant_check', last_seen_at: minutesAgo(121), href: '/security-devices/devices/50' },
                { id: 51, name: 'Synthetic fridge sensor', kind: 'sensor', model: 'Synthetic TH', state: 'cant_check', last_seen_at: null, href: '/security-devices/devices/51' },
            ],
            behind_count: 2,
            next: 'Most likely power or the internet line at Synthetic House: ask someone there to check the router’s lights.',
            told: { sub: 'From Oblivion’s standard rule for network gear', rows: [['When it stops working', 'IT staff who look after Synthetic House · app and email']] },
            events: [],
        };
        render(
            <DevicePage
                {...pageFixture({
                    device: { ...pageFixture().device, name: 'Synthetic router', kind: 'router', kind_label: 'Router', state: 'offline', badge: { label: 'Offline', tone: 'critical' } },
                    trouble,
                    meters: [
                        meter({ key: 'offline', label: 'Offline for', from: minutesAgo(120), caption: { text: 'Since {time}', at: minutesAgo(120) }, tone: 'critical' }),
                        meter({ key: 'cant_check', label: 'Can’t check', value: '2', caption: { text: 'Devices behind it', at: null } }),
                    ],
                })}
            />,
        );

        expect(screen.getByText('Synthetic router isn’t answering')).toBeInTheDocument();
        expect(screen.getByText(/ask someone there to check the router’s lights/)).toBeInTheDocument();
        expect(screen.getByText('Where it sits')).toBeInTheDocument();
        expect(screen.getByText('2 devices behind it can’t be checked')).toBeInTheDocument();
        expect(screen.getByText('Grey, not 2 more alerts')).toBeInTheDocument();
        expect(screen.getAllByText('Synthetic recorder').length).toBeGreaterThan(0);
        expect(screen.getByText('Still no reply')).toBeInTheDocument();
        expect(screen.getByText('120 checks missed')).toBeInTheDocument();
        expect(within(meterBlock('Offline for')).getByText('2 h')).toBeInTheDocument();
        // Acknowledge and quiet time come with the Alerts menu; they aren't offered yet.
        expect(screen.queryByRole('button', { name: /Acknowledge|Assign|Quiet time/ })).not.toBeInTheDocument();
    });

    it('offers Back up settings, with the hub route’s reason and the SSH key notice when it can’t run', () => {
        const backup: BackupAction = {
            available: false,
            reason: 'Synthetic hub isn’t connected right now, so this can’t run until it’s back. Oblivion doesn’t reach into the house directly.',
            via: null,
            ssh_key: { trusted: null, offered: { fingerprint: 'SHA256:synthetic', seen_at: minutesAgo(30), changed: false }, can_trust: false, trust_url: '/trust' },
            last_request: null,
        };
        render(<DevicePage {...pageFixture({ actions: { ping: true, backup, remote_href: null } })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Back up settings' }));
        const dialog = screen.getByRole('alertdialog');
        expect(within(dialog).getByText(/isn’t connected right now/)).toBeInTheDocument();
        expect(within(dialog).getByText(/SSH key hasn’t been trusted yet/)).toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: 'Back up now' })).toBeDisabled();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('lists checks in plain words on the Checks & alerts tab', () => {
        const checks: ChecksTab = {
            noun: 'switch',
            rows: [
                { id: 1, kind: 'icmp', title: 'Online', question: 'Does it answer?', how: 'Ping from Synthetic hub', every: 'Every minute', result: { label: 'Answered in 2 ms', tone: 'success', at: minutesAgo(1) }, alert_when: '3 missed in a row' },
            ],
            quiet_time: null,
            told: { sub: 'From Oblivion’s standard rule for network gear', rows: [['When it stops working', 'IT staff who look after Synthetic House · app and email']] },
            depends: { upstream: ['Synthetic gateway'], downstream: 11 },
            alerts: [],
            backups: [],
        };
        render(<DevicePage {...pageFixture({ tab: 'checks', overview: null, checks })} />);

        expect(screen.getByText('Checks on this switch')).toBeInTheDocument();
        expect(screen.getByText('Ping from Synthetic hub')).toBeInTheDocument();
        expect(screen.getByText('Answered in 2 ms')).toBeInTheDocument();
        expect(screen.getByText('3 missed in a row')).toBeInTheDocument();
        expect(screen.getByText(/11 devices depend on it/)).toBeInTheDocument();
        expect(screen.getByText('No alerts in the last 90 days.')).toBeInTheDocument();
        expect(screen.queryByText(/collector|observation|canonical|Not observed/i)).not.toBeInTheDocument();
    });
});
