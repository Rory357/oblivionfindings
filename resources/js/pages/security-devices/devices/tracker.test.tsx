import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TrackerPage from './tracker';
import type { TrackerCommands, TrackerPageProps } from './_sensor-tracker-types';
import { minutesAgo } from './device-page-test-fixtures';

const inertia = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), visit: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7, name: 'Synthetic Viewer' } } } }),
        router: { ...actual.router, ...inertia, on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
const map = vi.hoisted(() => ({ rendered: vi.fn() }));
vi.mock('@/components/leaflet-map', () => ({
    default: (props: { markers?: { title?: string }[] }) => {
        map.rendered(props);
        return <div data-testid="tracker-map">{props.markers?.[0]?.title}</div>;
    },
}));
const http = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return { ...actual, default: { ...actual.default, post: http.post, isAxiosError: actual.default.isAxiosError } };
});

const commands = (overrides: Partial<TrackerCommands> = {}): TrackerCommands => ({
    ready: true,
    reason: null,
    interval: 300,
    intervals: [1, 2, 5, 10, 30],
    locate_off: false,
    options: [
        { key: 'request_location', label: 'Ask for position now', sub: 'Gets a fresh location', risky: false, message: 'AT+GTRTO=••••••,1,,,,,····$', messages: null },
        {
            key: 'update_reporting_interval',
            label: 'Change report interval',
            sub: 'How often it sends its position',
            risky: true,
            message: null,
            messages: { '1': 'AT+GTCFG=••••••,,GL30MEU,150,08E3,006F,1,60,····$', '2': 'AT+GTCFG=••••••,,GL30MEU,150,08E3,006F,1,120,····$', '5': 'AT+GTCFG=••••••,,GL30MEU,150,08E3,006F,1,300,····$', '10': null, '30': null },
        },
        { key: 'reboot', label: 'Restart the unit', sub: 'Turns it off and on again', risky: true, message: 'AT+GTRTO=••••••,3,,,,,····$', messages: null },
        { key: 'read_config', label: 'Ask for settings', sub: 'Reads back what it’s set to', risky: false, message: 'AT+GTRTO=••••••,2,,,,,····$', messages: null },
    ],
    ...overrides,
});

function tracker(overrides: Partial<TrackerPageProps> = {}): TrackerPageProps {
    return {
        device: {
            id: 80,
            name: 'Synthetic care tracker',
            kind: 'care_tracker',
            kind_label: 'Care tracker',
            group: 'tracker',
            state: 'online',
            badge: { label: 'Online', tone: 'success' },
            model: 'Queclink GL320MG',
            site: 'Synthetic House',
            site_id: 4,
            address: null,
            watching: 'Reports from Queclink',
            subline: [
                { text: 'Queclink GL320MG', at: null },
                { text: 'Synthetic Person', at: null },
                { text: 'Synthetic House', at: null },
                { text: 'IMEI 8679•••••••0017', at: null },
                { text: 'position {ago}', at: minutesAgo(2) },
            ],
            back: { href: '/security-devices/devices?tab=trackers', label: 'Devices' },
        },
        tab: 'location',
        tabs: [
            { key: 'location', label: 'Location' },
            { key: 'console', label: 'Console' },
        ],
        meters: [
            { key: 'position', label: 'Last position', value: null, time: minutesAgo(2), caption: { text: 'Accurate to about 10 m', at: minutesAgo(2) }, tone: 'success', tab: 'location' },
            { key: 'battery', label: 'Battery', value: '41%', time: null, caption: { text: 'Read {ago}', at: minutesAgo(5) }, tone: 'success', tab: 'location' },
            { key: 'reports', label: 'Reports', value: 'Every 5 min', time: null, caption: { text: 'Read back from the unit {ago}', at: minutesAgo(60) }, tone: 'brand', tab: 'console' },
            { key: 'consent', label: 'Location consent', value: 'Active', time: null, caption: { text: 'Until {date}', at: '2027-03-30T11:00:00Z' }, tone: 'success', tab: 'location' },
        ],
        can: { edit: true, watch: true, move: true, assign: true, retire: true, upload: false, see_events: true, see_console: true, command: true, locate: true },
        privacy: { shown: true, state: 'available', person: { kind: 'client', short: 'Synthetic', consent: { active: true, until: '2027-03-30T11:00:00Z', ended: null, details: true } } },
        commands: commands(),
        location: {
            position: { lat: -41.2865, lng: 174.7762, accuracy_m: 10, at: minutesAgo(2), address: null },
            fix: null,
            record_href: null,
            battery: { start: minutesAgo(7 * 24 * 60), step_seconds: 7200, points: Array.from({ length: 84 }, (_, index) => 100 - (index % 40)), unit: '%' },
            checkins: [
                { label: 'Last heard', text: '{time}', at: minutesAgo(1) },
                { label: 'Connection', text: 'Connected to Oblivion', at: null },
            ],
            consent_history: null,
            linked: { name: 'Synthetic Person', sub: 'Synthetic House', flag: { label: 'Consent active', tone: 'success' }, href: '/clients/9' },
        },
        console: null,
        ...overrides,
    };
}

const withoutConsent = (): Partial<TrackerPageProps> => ({
    meters: [
        { key: 'position', label: 'Last position', value: 'Hidden', time: null, caption: { text: 'No active location consent', at: null }, tone: 'brand', tab: 'location' },
        { key: 'consent', label: 'Location consent', value: 'Not active', time: null, caption: { text: 'Ended {date}', at: '2026-09-30T02:00:00Z' }, tone: 'critical', tab: 'location' },
    ],
    can: { edit: true, watch: true, move: true, assign: true, retire: true, upload: false, see_events: true, see_console: true, command: true, locate: false },
    privacy: { shown: false, state: 'consent_required', person: { kind: 'client', short: 'Synthetic', consent: { active: false, until: null, ended: '2026-09-30T02:00:00Z', details: true } } },
    commands: commands({ locate_off: true, options: commands().options.filter((option) => option.key !== 'request_location') }),
    location: {
        position: null,
        fix: {
            tone: 'warning',
            note: 'Location is hidden because Synthetic’s location consent is not active.',
            title: 'Record Synthetic’s location consent in Clients',
            sub: 'Talk it through with Synthetic and their whānau first. When consent is recorded and linked to this tracker, the location shows again.',
        },
        record_href: '/operations/clients/9/consents',
        battery: null,
        checkins: [],
        consent_history: [
            { key: 'ended-3', at: '2026-09-30T02:00:00Z', tone: 'critical', title: 'Location consent ended', sub: 'Location hidden from then on', sub_at: null },
            { key: 'given-3', at: '2025-10-01T02:00:00Z', tone: 'success', title: 'Location consent recorded', sub: 'Until {date} · given by mother', sub_at: '2026-09-30T02:00:00Z' },
        ],
        linked: { name: 'Synthetic Person', sub: 'Synthetic House', flag: { label: 'Consent not active', tone: 'warning' }, href: '/clients/9' },
    },
});

function meterBlock(label: string): HTMLElement {
    return screen.getByRole('button', { name: new RegExp(`^${label}:`) });
}

describe('Care tracker page (pg-sdr-8, 8b, 8c) and pop-up 19', () => {
    beforeEach(() => vi.clearAllMocks());
    afterEach(cleanup);

    it('shows the location only because consent is active, and says so first', () => {
        render(<TrackerPage {...tracker()} />);

        expect(screen.getByText('Location shows because Synthetic’s location consent is active (until 31 Mar 2027).')).toBeInTheDocument();
        expect(screen.getByTestId('tracker-map')).toBeInTheDocument();
        expect(within(meterBlock('Location consent')).getByText('Until 31 Mar 2027')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Ask for position now' })).toBeInTheDocument();
        expect(screen.getByText('Check-ins')).toBeInTheDocument();
        // Only the drawn tabs.
        expect(screen.queryByRole('tab', { name: /Battery & checks/ })).not.toBeInTheDocument();
    });

    it('hides the location without consent, says who can fix it and what still works', () => {
        render(<TrackerPage {...tracker(withoutConsent())} />);

        expect(screen.getByText('Location is hidden because Synthetic’s location consent is not active.')).toBeInTheDocument();
        expect(map.rendered).not.toHaveBeenCalled();
        expect(screen.queryByTestId('tracker-map')).not.toBeInTheDocument();
        expect(within(meterBlock('Last position')).getByText('Hidden')).toBeInTheDocument();
        expect(within(meterBlock('Location consent')).getByText('Ended 30 Sep 2026')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Record consent' })).toHaveAttribute('href', '/operations/clients/9/consents');
        expect(screen.getByText('What still works without consent')).toBeInTheDocument();
        expect(screen.getByText('Consent history')).toBeInTheDocument();
        expect(screen.getByText('Until 30 Sep 2026 · given by mother')).toBeInTheDocument();
        expect(screen.getByText('Consent not active')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Ask for position now' })).not.toBeInTheDocument();
    });

    it('shows the exact message, warns before a restart and needs a reason for it', async () => {
        http.post.mockResolvedValue({ data: { message: 'Sent. The unit picks it up next time it checks in.' } });
        render(<TrackerPage {...tracker()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Send a command' }));
        const popup = await screen.findByTestId('sd-tracker-command');
        expect(within(popup).getByText('Send a command to the tracker')).toBeInTheDocument();
        expect(within(popup).getByText('Consent active')).toBeInTheDocument();
        expect(within(popup).getByText('Queclink GL320MG · Synthetic Person · IMEI 8679•••••••0017')).toBeInTheDocument();

        fireEvent.click(within(popup).getByRole('button', { name: /Restart the unit/ }));
        expect(within(popup).getByTestId('sd-tracker-message')).toHaveTextContent('AT+GTRTO=••••••,3,,,,,····$');
        expect(within(popup).getByText(/out of touch for about 2 minutes while it restarts\. Synthetic’s location won’t update/)).toBeInTheDocument();

        fireEvent.click(within(popup).getByRole('button', { name: 'Restart the unit' }));
        expect(await within(popup).findByText('Say why in a few words. It’s kept in the console history.')).toBeInTheDocument();
        expect(http.post).not.toHaveBeenCalled();

        fireEvent.change(within(popup).getByLabelText(/Why/), { target: { value: 'It stopped sending positions' } });
        fireEvent.click(within(popup).getByRole('button', { name: 'Restart the unit' }));
        await waitFor(() =>
            expect(http.post).toHaveBeenCalledWith(
                '/security-devices/devices/80/tracker/commands',
                { command: 'reboot', interval_minutes: null, reason: 'It stopped sending positions' },
                expect.anything(),
            ),
        );
        expect(await screen.findByText('Sent. The unit picks it up next time it checks in.')).toBeInTheDocument();
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['console', 'commands', 'meters'] });
    });

    it('builds the interval message for the chosen interval', async () => {
        render(<TrackerPage {...tracker()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Send a command' }));
        const popup = await screen.findByTestId('sd-tracker-command');
        fireEvent.click(within(popup).getByRole('button', { name: /Change report interval/ }));
        expect(within(popup).getByTestId('sd-tracker-message')).toHaveTextContent('AT+GTCFG=••••••,,GL30MEU,150,08E3,006F,1,300,····$');
        expect(within(popup).getByText(/It reports every 5 minutes now, so it picks this up within 5 minutes\./)).toBeInTheDocument();
        expect(within(popup).getByRole('button', { name: 'Send command' })).toBeInTheDocument();
    });

    it('offers no command and no position request to someone who may not send them', () => {
        render(
            <TrackerPage
                {...tracker({
                    can: { edit: false, watch: false, move: false, assign: false, retire: false, upload: false, see_events: false, see_console: false, command: false, locate: false },
                    tabs: [{ key: 'location', label: 'Location' }],
                    commands: commands({ options: [] }),
                })}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Send a command' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Ask for position now' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
    });

    it('shows the unit’s messages newest last and the commands with their message and reply', () => {
        render(
            <TrackerPage
                {...tracker({
                    tab: 'console',
                    location: null,
                    console: {
                        host: 'Synthetic care tracker · IMEI 8679•••••••0017 · TCP to Oblivion, port 8090',
                        lines: [
                            { id: 1, at: minutesAgo(10), out: false, text: '+RESP:GTFRI,5E0100,8679•••••••0017,,0,0,1,1,0.0,0,12.0,[location hidden],[location hidden],0006$', note: 'position report' },
                            { id: 2, at: minutesAgo(5), out: true, text: 'AT+GTRTO=••••••,2,,,,,0007$', note: 'command sent to the unit' },
                        ],
                        commands: [
                            { id: 9, at: minutesAgo(5), title: 'Ask for its settings', sub: 'Synthetic Viewer · the unit replied in 2 seconds', tone: 'success', message: 'AT+GTRTO=••••••,2,,,,,0007$', reply: '+ACK:GTRTO,5E0100,8679•••••••0017,,RTO,0007$' },
                        ],
                        settings: { read_at: minutesAgo(60), rows: [['Reports', 'Every 5 min'], ['Server', 'synthetic.example · port 8090']] },
                        location_hidden: true,
                    },
                })}
            />,
        );

        expect(screen.getByText('Synthetic care tracker · IMEI 8679•••••••0017 · TCP to Oblivion, port 8090')).toBeInTheDocument();
        const lines = within(screen.getByRole('list', { name: 'Messages from the unit' })).getAllByRole('listitem');
        expect(lines[0]).toHaveTextContent('# position report');
        expect(lines[1]).toHaveTextContent('obli> AT+GTRTO=••••••,2,,,,,0007$');
        expect(screen.getByText('Positions are hidden in these messages because the location can’t be shown.')).toBeInTheDocument();
        expect(screen.getByText('Synthetic Viewer · the unit replied in 2 seconds')).toBeInTheDocument();
        expect(screen.getByText('← +ACK:GTRTO,5E0100,8679•••••••0017,,RTO,0007$')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Ask the unit for its settings' })).toBeInTheDocument();
    });
});
