import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AlarmPage from './alarm';
import type { AlarmPageProps, AlarmZone, GovernedAction, SafetyCommand } from './_safety-kit-types';

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

/** Synthetic panels only: no real Sites or people. */
const minutesAgo = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString();

const zone = (id: string, name: string, state: AlarmZone['state'], area: string | null = null): AlarmZone => ({
    id,
    name,
    number: id,
    state,
    label: { normal: 'Ready', open: 'Open', triggered: 'Alarm', bypassed: 'Left out', tamper: 'Tampered with', fault: 'Fault', not_reported: 'No report yet' }[state],
    tone: state === 'normal' ? 'success' : state === 'not_reported' ? 'neutral' : 'warning',
    area,
    changed_at: state === 'not_reported' ? null : minutesAgo(30),
});

const action = (overrides: Partial<GovernedAction> = {}): GovernedAction => ({
    available: true,
    reason: null,
    step_up: false,
    approval: false,
    type_name: false,
    via: 'Synthetic Lodge hub',
    ...overrides,
});

function viewOnly(overrides: Partial<AlarmPageProps> = {}): AlarmPageProps {
    const zones = [zone('1', 'Front door', 'normal'), zone('2', 'Lounge motion', 'normal'), zone('3', 'Laundry window', 'bypassed')];
    return {
        device: {
            id: 51,
            name: 'Synthetic House alarm',
            kind: 'alarm_panel',
            kind_label: 'Alarm panel',
            state: 'online',
            badge: { label: 'Online', tone: 'success' },
            model: 'Ajax Hub 2 Plus',
            site: 'Synthetic House',
            site_id: 4,
            address: null,
            watching: 'Online',
            subline: [
                { text: 'Ajax Hub 2 Plus', at: null },
                { text: 'Synthetic House · hallway cupboard', at: null },
                { text: 'last report {ago}', at: minutesAgo(2) },
            ],
            back: { href: '/security-devices/devices?tab=alarms_doors', label: 'Devices' },
        },
        tab: 'overview',
        tabs: [
            { key: 'overview', label: 'Overview' },
            { key: 'zones', label: 'Zones' },
            { key: 'checks', label: 'Checks & alerts' },
            { key: 'notes', label: 'Notes & documents' },
        ],
        panel: { state: 'armed', label: 'Armed (away)', tone: 'info', mode: 'away', since: minutesAgo(40), armed: 1, total: 1 },
        meters: [
            { key: 'zones', label: 'Zones ready', value: '2 of 3', caption: { text: 'Laundry window left out', at: null }, tone: 'warning', tab: 'zones' },
            { key: 'power', label: 'Power', value: null, caption: { text: 'Not reported by this panel', at: null }, tone: 'brand', tab: 'overview' },
            { key: 'alarms', label: 'Alarms in 30 days', value: '0', caption: { text: 'None in the last 30 days', at: null }, tone: 'brand', tab: 'overview' },
        ],
        can: { edit: true, watch: true, move: true, retire: true, assign: true, see_events: true, upload: true, arm: false, disarm: false },
        control: {
            controllable: false,
            view_only: true,
            brand: 'Ajax',
            arm: null,
            disarm: null,
            refusal: null,
            commands: [],
            zones,
            confirm_identity_url: '/security-devices/devices/51/safety/confirm-identity',
        },
        areas: [{ id: '01', name: 'Area 1', armed: true, mode: 'away', since: minutesAgo(40), by: 'At the panel' }],
        overview: {
            lines: [
                { state: 'info', lead: 'View only.', text: 'Arm and disarm in the Ajax app.', at: null },
                { state: 'warning', lead: 'Laundry window', text: 'is left out, so it isn’t watched while armed · since {time}', at: minutesAgo(30) },
            ],
            zones,
            zone_count: 3,
            last_report: minutesAgo(2),
            events: [{ key: 'event-1', at: minutesAgo(40), tone: 'info', title: 'Armed', sub: 'At the panel' }],
            facts: [
                ['Reports by', { text: 'SIA DC-09 to Oblivion’s alarm receiver', at: null }],
                ['Arm and disarm from Oblivion', { text: 'Not available · Ajax hubs have no local control API', at: null }],
            ],
        },
        zones: null,
        checks: null,
        notes: null,
        ...overrides,
    };
}

function inception(overrides: Partial<AlarmPageProps> = {}): AlarmPageProps {
    const base = viewOnly();
    const zones = [zone('1', 'Laundry window', 'open', 'Outside doors and windows'), zone('2', 'Shed door', 'normal', 'Garage and shed')];
    return viewOnly({
        device: { ...base.device, name: 'Synthetic Lodge alarm', model: 'Inner Range Inception' },
        panel: { state: 'partly', label: 'Partly armed', tone: 'info', mode: null, since: minutesAgo(90), armed: 1, total: 3 },
        can: { ...base.can, arm: true, disarm: true },
        control: {
            ...base.control,
            controllable: true,
            view_only: false,
            brand: 'Inner Range',
            arm: action(),
            disarm: action({ type_name: true, approval: true }),
            zones,
        },
        areas: [
            { id: 'a1', name: 'Outside doors and windows', armed: false, mode: null, since: minutesAgo(600), by: null },
            { id: 'a2', name: 'Staff office', armed: true, mode: null, since: minutesAgo(90), by: 'At the panel' },
            { id: 'a3', name: 'Garage and shed', armed: false, mode: null, since: null, by: null },
        ],
        overview: {
            ...base.overview!,
            lines: [
                { state: 'success', lead: null, text: 'Arm and disarm here · the panel last reported {ago}', at: minutesAgo(1) },
                { state: 'success', lead: null, text: 'After each command Oblivion reads the state back from the panel; if it doesn’t change, you’re told', at: null },
            ],
            zones,
        },
        ...overrides,
    });
}

function dialog(name: RegExp): HTMLElement {
    return screen.getByRole('alertdialog', { name });
}

describe('Alarm panel page (pg-sdr-5, 5b, 5c, sdr-17, sdr-71)', () => {
    beforeEach(() => vi.clearAllMocks());
    afterEach(() => {
        cleanup();
        vi.useRealTimers();
    });

    it('says once that an Ajax panel is view only, and offers no arm or disarm', () => {
        render(<AlarmPage {...viewOnly()} />);

        expect(screen.getByRole('heading', { name: 'Synthetic House alarm' })).toBeInTheDocument();
        expect(screen.getAllByText('Armed (away)').length).toBeGreaterThan(0);
        expect(screen.getByText('Alarm · view only')).toBeInTheDocument();
        expect(screen.getAllByText('View only.')).toHaveLength(1);
        expect(screen.getByText(/Arm and disarm in the Ajax app/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Arm/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Disarm/ })).not.toBeInTheDocument();
        expect(screen.getByText(/is left out, so it isn’t watched while armed/)).toBeInTheDocument();
        expect(screen.getByText('For IT')).toBeInTheDocument();
        expect(screen.getByText('Not reported by this panel')).toBeInTheDocument();
    });

    it('offers Arm an area and Disarm on a panel whose maker allows it', () => {
        render(<AlarmPage {...inception()} />);

        expect(screen.getByText('Alarm')).toBeInTheDocument();
        expect(screen.getByText(/Partly armed: 1 of 3 areas/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Arm an area…' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Disarm…' })).toBeInTheDocument();
        expect(screen.getByText('Areas')).toBeInTheDocument();
    });

    it('disarms only with a reason and the typed name the framework asks for, stating the effect first', () => {
        render(<AlarmPage {...inception()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Disarm…' }));

        const popup = dialog(/Disarm Synthetic Lodge alarm\?/);
        expect(within(popup).getByText(/will be disarmed/)).toBeInTheDocument();
        expect(within(popup).getByText(/second person who approves device commands/)).toBeInTheDocument();
        const send = within(popup).getByRole('button', { name: 'Disarm' });
        expect(send).toBeDisabled();

        fireEvent.change(within(popup).getByLabelText(/Reason/), { target: { value: 'Staff arriving for the morning shift' } });
        expect(send).toBeDisabled();
        fireEvent.change(within(popup).getByLabelText(/Type the alarm’s name/), { target: { value: 'Synthetic Lodge alarm' } });
        expect(send).toBeEnabled();
        fireEvent.click(send);

        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/devices/51/safety/disarm',
            expect.objectContaining({ areas: ['a2'], reason: 'Staff arriving for the morning shift', confirmation_text: 'Synthetic Lodge alarm', idempotency_key: expect.stringMatching(/^disarm-/) }),
            expect.anything(),
        );
    });

    it('asks the person to confirm it’s them before a disarm when the framework wants it', () => {
        const base = inception();
        render(<AlarmPage {...inception({ control: { ...base.control, disarm: action({ step_up: true }) } })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Disarm…' }));

        const popup = dialog(/Disarm/);
        expect(within(popup).queryByLabelText(/Reason/)).not.toBeInTheDocument();
        fireEvent.click(within(popup).getByRole('button', { name: 'Confirm it’s you' }));
        expect(inertia.visit).toHaveBeenCalledWith('/security-devices/devices/51/safety/confirm-identity');
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('arms the chosen areas and names an open zone before anything is sent', () => {
        render(<AlarmPage {...inception()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Arm an area…' }));

        const popup = screen.getByRole('dialog', { name: /Arm an area/ });
        expect(within(popup).getByText(/Laundry window is open\. Close it first/)).toBeInTheDocument();
        expect(within(popup).getByRole('checkbox', { name: /Staff office/ })).toBeDisabled();
        fireEvent.click(within(popup).getByRole('checkbox', { name: /Garage and shed/ }));
        fireEvent.click(within(popup).getByRole('button', { name: 'Arm' }));

        expect(inertia.post).toHaveBeenCalledWith('/security-devices/devices/51/safety/arm', expect.objectContaining({ areas: ['a1'] }), expect.anything());
    });

    it('shows the hub’s refusal in its own words instead of the buttons', () => {
        const refusal = 'Synthetic Lodge hub needs its software update before it can arm or disarm the alarm';
        const base = inception();
        render(
            <AlarmPage
                {...inception({
                    can: { ...base.can, arm: false, disarm: false },
                    control: { ...base.control, refusal, arm: action({ available: false, reason: refusal }), disarm: action({ available: false, reason: refusal }) },
                    overview: { ...base.overview!, lines: [{ state: 'warning', lead: null, text: refusal, at: null }] },
                })}
            />,
        );

        expect(screen.getByText(refusal)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Disarm/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Arm an area/ })).not.toBeInTheDocument();
    });

    it('says Checking… after a command and reads the state back until the panel confirms it', () => {
        vi.useFakeTimers();
        const checking: SafetyCommand = {
            id: 9,
            action: 'disarm',
            area: 'a2',
            phase: 'checking',
            words: 'Checking…',
            failure: null,
            at: minutesAgo(0),
            done_at: null,
            by: 'Synthetic Staff',
            approved_by: null,
            reason: 'Staff arriving for the morning shift',
            mine: true,
            can_run: false,
        };
        const base = inception();
        render(<AlarmPage {...inception({ control: { ...base.control, commands: [checking] } })} />);

        expect(screen.getByText(/Disarm · Staff office: Checking…/)).toBeInTheDocument();
        expect(screen.getByText(/asked by you/)).toBeInTheDocument();
        act(() => {
            vi.advanceTimersByTime(3_100);
        });
        expect(inertia.reload).toHaveBeenCalledWith({ only: expect.arrayContaining(['control', 'panel']) });
    });

    it('lists every zone on the Zones tab, with how the Ajax modes work', () => {
        const zones = viewOnly().control.zones;
        render(<AlarmPage {...viewOnly({ tab: 'zones', overview: null, zones: { rows: zones, modes_help: true } })} />);

        const table = screen.getByRole('table');
        expect(within(table).getAllByRole('row')).toHaveLength(4);
        expect(within(table).getByText('Left out')).toBeInTheDocument();
        expect(screen.getByText('How the modes work')).toBeInTheDocument();
        expect(screen.getByText(/Arm, disarm and add zones in the Ajax app/)).toBeInTheDocument();
    });
});
