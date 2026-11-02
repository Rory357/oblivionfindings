import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DevicePage from './device';
import type { HistoryTab, PortsTab, SettingsTab, SwitchPort } from './_device-page-types';
import { minutesAgo, pageFixture } from './device-page-test-fixtures';
import type { LogbookLine } from './_types';

/** A switch's Ports (pg-sdr-3d), History (3e) and Settings (3f) tabs, with synthetic devices only. */

const inertia = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), visit: vi.fn(), reload: vi.fn(), delete: vi.fn() }));
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
vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return { default: { ...actual.default, put: vi.fn(), post: vi.fn(), get: vi.fn(), isAxiosError: actual.default.isAxiosError } };
});

const TABS = [
    { key: 'overview' as const, label: 'Overview' },
    { key: 'ports' as const, label: 'Ports' },
    { key: 'checks' as const, label: 'Checks & alerts' },
    { key: 'history' as const, label: 'History' },
    { key: 'settings' as const, label: 'Settings' },
    { key: 'notes' as const, label: 'Notes & documents' },
];

function port(overrides: Partial<SwitchPort> & Pick<SwitchPort, 'port'>): SwitchPort {
    return {
        device: null,
        label: null,
        up: true,
        speed: '1 Gbps',
        poe_w: null,
        errors: 0,
        errors_total: null,
        changed_at: null,
        change: null,
        problem: false,
        free: false,
        ...overrides,
    };
}

const ports: PortsTab = {
    rows: [
        port({
            port: 4,
            device: { id: 9, name: 'Synthetic back yard camera', kind: 'camera', model: 'Synthetic dome', state: 'online', href: '/security-devices/devices/9' },
            speed: '100 Mbps',
            errors: 214,
            problem: true,
            changed_at: minutesAgo(60 * 7),
            change: 'came up',
        }),
        port({ port: 7, device: { id: 10, name: 'Synthetic Wi-Fi', kind: 'wifi_point', model: 'Synthetic U6', state: 'online', href: '/security-devices/devices/10' } }),
        port({ port: 6, up: false, speed: null, errors: 0, free: true }),
    ],
    total: 3,
    tally: { free: 1, poe_w: null, powering: 0, errors: 1, errors_first: 'Port 4 · Synthetic back yard camera', changed: 1, changed_ports: [4] },
    source: { checks: 3, every: 'every 5 min', by: 'Synthetic hub', read_at: minutesAgo(3), unifi_at: null, unifi_hub: null, error_hours: 24 },
};

function line(overrides: Partial<LogbookLine> & Pick<LogbookLine, 'key' | 'title'>): LogbookLine {
    return { kind: 'alerts', at: minutesAgo(30), tone: 'info', sub: null, who: 'Oblivion', compare_id: null, span: null, href: null, tab: null, ...overrides };
}

const history: HistoryTab = {
    noun: 'switch',
    what: 'all',
    from: null,
    kinds: [
        { value: 'all', label: 'Everything' },
        { value: 'alerts', label: 'Alerts' },
        { value: 'settings', label: 'Settings' },
        { value: 'quiet', label: 'Quiet times' },
        { value: 'moves', label: 'Moves' },
        { value: 'terminal', label: 'Terminal' },
    ],
    lines: [
        line({ key: 'q1', kind: 'quiet', title: 'Quiet time', sub: 'Synthetic Planner · nobody alerted', who: 'Synthetic Planner', span: { from: minutesAgo(-30), to: minutesAgo(-90) } }),
        line({ key: 'b1', kind: 'settings', title: 'Settings changed: 2 lines', sub: 'Compared with the copy before', compare_id: 88, at: minutesAgo(60 * 24 * 3) }),
        line({ key: 't1', kind: 'terminal', title: 'Terminal opened', sub: 'Synthetic Planner · 4 commands, all kept', who: 'Synthetic Planner', tab: 'terminal', at: minutesAgo(60 * 24 * 2) }),
        line({ key: 'm1', kind: 'moves', title: 'Room or spot changed', who: 'Synthetic Mover', at: minutesAgo(60 * 24 * 40) }),
    ],
};

const settings: SettingsTab = {
    noun: 'switch',
    name: 'Synthetic switch',
    site: 'Synthetic House',
    site_id: 4,
    room: 'Comms cupboard',
    checked_by: 'Synthetic hub',
    address: '10.0.0.2',
    read_through: 'Ping and SNMP',
    watching: {
        on: true,
        by_service: false,
        every: 'every minute',
        checked_by: 'Synthetic hub',
        offline_alert: true,
        offline_ids: [501],
        stop_ids: [501, 502],
        other_checks: 1,
        backup: null,
        relied_on: true,
        dependents: 11,
    },
    about: { model: 'Synthetic SW-24', added_at: '2026-09-14T01:00:00Z', added_by: 'Synthetic Adder', from_scan: true },
    it: [
        ['MAC', '74:ac:b9:00:00:01'],
        ['Software', '6.6.61'],
    ],
    edit: { domain: 'it_infrastructure', category: 'network', subcategory: 'switch' },
};

beforeEach(() => vi.clearAllMocks());
afterEach(cleanup);

describe('Switch · Ports tab (pg-sdr-3d)', () => {
    it('lists ports problems first with real numbers, Not available for power, and no port control', () => {
        render(<DevicePage {...pageFixture({ tab: 'ports', tabs: TABS, ports })} />);

        const rail = screen.getByRole('tablist', { name: /views/ });
        for (const label of ['Overview', 'Ports', 'Checks & alerts', 'History', 'Settings']) expect(within(rail).getByText(label)).toBeInTheDocument();

        const table = screen.getByRole('table');
        const rows = within(table).getAllByRole('row');
        expect(within(rows[1]).getByText('4')).toBeInTheDocument();
        expect(within(rows[1]).getByText('214 in 24 h')).toBeInTheDocument();
        expect(within(rows[1]).getByText('Cable or plug may be damaged')).toBeInTheDocument();
        expect(within(table).getAllByText('Not available').length).toBeGreaterThan(0);
        // Free ports are tucked away until asked for.
        expect(within(table).queryByText('Free')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Show free ports (1)' }));
        expect(within(screen.getByRole('table')).getByText('Free')).toBeInTheDocument();

        fireEvent.click(within(rows[1]).getByText('Synthetic back yard camera'));
        expect(inertia.visit).toHaveBeenCalledWith('/security-devices/devices/9');

        expect(screen.getByText(/Oblivion doesn’t do this, or restart the switch/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /power|restart|turn (on|off)/i })).not.toBeInTheDocument();
        expect(screen.getByText('Port 4 · Synthetic back yard camera')).toBeInTheDocument();
    });
});

describe('Switch · History tab (pg-sdr-3e)', () => {
    it('is one logbook, newest first, filtered from one What dropdown, never pill chips', () => {
        vi.mocked(axios.get).mockReturnValue(new Promise(() => undefined));
        render(<DevicePage {...pageFixture({ tab: 'history', tabs: [...TABS, { key: 'terminal', label: 'Terminal' }], history })} />);

        expect(screen.getByText('Today')).toBeInTheDocument();
        expect(screen.getByText('Earlier this week')).toBeInTheDocument();
        expect(screen.getByText(/^Quiet time · /)).toBeInTheDocument();
        expect(screen.getByRole('combobox', { name: 'What' })).toBeInTheDocument();
        // No pill chip per kind.
        for (const kind of ['Alerts', 'Quiet times', 'Moves']) expect(screen.queryByRole('button', { name: kind })).not.toBeInTheDocument();
        expect(screen.getByText('Terminal sessions show only to IT admins')).toBeInTheDocument();
        expect(screen.getByText(/every reading for 14 days, then daily summaries for 5 years/)).toBeInTheDocument();
        expect(screen.getByText('Synthetic Planner')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'See commands' }));
        expect(inertia.get).toHaveBeenLastCalledWith('/security-devices/devices/41', { tab: 'terminal' }, expect.anything());
        fireEvent.click(screen.getByRole('button', { name: /^Compare/ }));
        expect(screen.getByTestId('sd-settings-changed')).toBeInTheDocument();
    });

    it('says plainly when nothing matches and offers everything again', () => {
        render(<DevicePage {...pageFixture({ tab: 'history', tabs: TABS, history: { ...history, what: 'moves', lines: [] } })} />);
        expect(screen.getByText('Nothing matches. Choose Everything or another date.')).toBeInTheDocument();
        fireEvent.click(screen.getAllByRole('button', { name: 'Show everything' })[0]);
        expect(inertia.get).toHaveBeenLastCalledWith('/security-devices/devices/41', { tab: 'history' }, expect.anything());
    });
});

describe('Switch · Settings tab (pg-sdr-3f)', () => {
    it('reviews changes old → new and flags stopping watching as loosening a safety check, with a reason', async () => {
        vi.mocked(axios.put).mockResolvedValue({ data: {} });
        vi.mocked(axios.post).mockResolvedValue({ data: {} });
        render(<DevicePage {...pageFixture({ tab: 'settings', tabs: TABS, settings, actions: { ping: true, backup: null, remote_href: null } })} />);

        expect(screen.getByText('Name and place')).toBeInTheDocument();
        expect(screen.getByText('How Oblivion reaches it')).toBeInTheDocument();
        expect(screen.getByText('Watching')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Move to another Site' })).toBeInTheDocument();
        expect(screen.getByText(/Remove device takes it off every list and stops its checks; its history stays/)).toBeInTheDocument();
        expect(screen.getByText(/Turn off Watch this switch first/)).toBeInTheDocument();
        // One action, Remove device; no Retire.
        expect(screen.queryByRole('button', { name: /^Retire/ })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Remove device' })).toBeInTheDocument();
        expect(screen.getByText('No unsaved changes')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText(/^Room or spot/), { target: { value: 'Comms cupboard, top shelf' } });
        fireEvent.click(screen.getByRole('switch', { name: 'Watch this switch' }));
        expect(screen.getByText('2 changes not saved')).toBeInTheDocument();
        expect(screen.getAllByText('Changed')).toHaveLength(2);
        expect(screen.getByText('Loosens a safety check')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByText('Save 2 changes to Synthetic switch?')).toBeInTheDocument();
        expect(within(dialog).getByText(/Comms cupboard → Comms cupboard, top shelf/)).toBeInTheDocument();
        expect(within(dialog).getByText(/On → Off/)).toBeInTheDocument();
        expect(within(dialog).getByText(/the 11 devices behind it can’t be checked/)).toBeInTheDocument();
        const save = within(dialog).getByRole('button', { name: 'Save, including the loosened check' });

        // A reason is needed before anything is sent.
        fireEvent.click(save);
        expect(await within(dialog).findByText('Choose why it’s no longer watched.')).toBeInTheDocument();
        expect(axios.put).not.toHaveBeenCalled();
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('shows read-only values to people who can’t change them', () => {
        render(
            <DevicePage
                {...pageFixture({
                    tab: 'settings',
                    tabs: TABS,
                    settings: { ...settings, edit: null },
                    can: { edit: false, watch: false, move: false, retire: false, assign: false, upload: false, see_events: true },
                })}
            />,
        );
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(screen.getByRole('switch', { name: 'Watch this switch' })).toBeDisabled();
        expect(screen.queryByRole('button', { name: 'Review changes' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Move to another Site' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Remove device' })).not.toBeInTheDocument();
        expect(screen.getByText('Synthetic SW-24')).toBeInTheDocument();
        expect(screen.getByText(/by Synthetic Adder, from a scan/)).toBeInTheDocument();
    });
});
