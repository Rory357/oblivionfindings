import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DoorPage from './door';
import type { DoorEvent, DoorPageProps } from './_safety-kit-types';

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

/** Synthetic doors and people only. */
const minutesAgo = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString();

const rows: DoorEvent[] = [
    { key: 'i1', at: minutesAgo(5), kind: 'came_in', tone: 'success', title: 'Synthetic Staff came in', who: 'Synthetic Staff', who_kind: 'staff', how: 'Staff card', method: 'card' },
    { key: 'i2', at: minutesAgo(9), kind: 'came_in', tone: 'success', title: 'Came in with a resident fob', who: 'Resident fob', who_kind: 'resident', how: 'Resident fob', method: 'resident' },
    { key: 'c3', at: minutesAgo(20), kind: 'unlocked', tone: 'info', title: 'Unlocked for 5 seconds', who: 'Synthetic Manager', who_kind: 'staff', how: 'From Oblivion · plumber arriving', method: 'oblivion' },
    { key: 'i4', at: minutesAgo(40), kind: 'refused', tone: 'critical', title: 'Card refused', who: null, who_kind: 'unknown', how: 'Card', method: 'refused' },
];

function door(overrides: Partial<DoorPageProps> = {}): DoorPageProps {
    return {
        device: {
            id: 61,
            name: 'Front door lock',
            kind: 'door',
            kind_label: 'Door',
            state: 'online',
            badge: { label: 'Online', tone: 'success' },
            model: 'UniFi Access G2 reader',
            site: 'Synthetic House',
            site_id: 4,
            address: null,
            watching: 'Online',
            subline: [
                { text: 'UniFi Access G2 reader', at: null },
                { text: 'Synthetic House · front door', at: null },
                { text: 'checked {ago} by Synthetic House hub', at: minutesAgo(1) },
            ],
            back: { href: '/security-devices/devices?tab=alarms_doors', label: 'Devices' },
        },
        tab: 'overview',
        tabs: [
            { key: 'overview', label: 'Overview' },
            { key: 'events', label: 'Events' },
            { key: 'access', label: 'Access' },
        ],
        lock: { title: 'Locked and closed', tone: 'success', chip: { label: 'Locked', tone: 'success' }, checked_at: minutesAgo(1), relocks: true },
        meters: [
            { key: 'held', label: 'Held open today', value: '0', caption: { text: 'Not held open today', at: null }, tone: 'brand', tab: 'events' },
            { key: 'in', label: 'Came in today', value: '3', caption: { text: '1 card, 1 resident fob, 1 unlock from Oblivion', at: null }, tone: 'brand', tab: 'events' },
            { key: 'cards', label: 'Cards that open it', value: '6', caption: { text: '4 staff · 2 residents’ fobs', at: null }, tone: 'brand', tab: 'access' },
        ],
        can: { edit: true, watch: true, move: true, retire: true, assign: true, see_events: true, unlock: true },
        control: {
            unlock: { available: true, reason: null, step_up: false, approval: false, type_name: false, via: 'Synthetic House hub' },
            seconds: 5,
            commands: [],
            confirm_identity_url: '/security-devices/devices/61/safety/confirm-identity',
        },
        overview: {
            today: rows,
            who: [
                ['Staff', '4 · cards and phones'],
                ['Residents', '2 fobs · their own home, any time'],
                ['On its own', 'Oblivion never unlocks it by schedule or automation'],
            ],
            week: { labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], values: [3, 5, 2, 0, 4, 1, 3] },
            facts: [['Reader', 'UniFi Access G2 reader'], ['Through', 'Synthetic House hub']],
        },
        events: null,
        access: null,
        ...overrides,
    };
}

describe('Door page (pg-sdr-6, 6b, 6c, sdr-18)', () => {
    beforeEach(() => vi.clearAllMocks());
    afterEach(cleanup);

    it('shows the lock state, who came in, and a resident only as “Resident fob”', () => {
        render(<DoorPage {...door()} />);

        expect(screen.getByRole('heading', { name: 'Front door lock' })).toBeInTheDocument();
        expect(screen.getByText('Locked and closed')).toBeInTheDocument();
        expect(screen.getByText('Synthetic Staff came in')).toBeInTheDocument();
        expect(screen.getByText('Came in with a resident fob')).toBeInTheDocument();
        expect(screen.getByText('Unlocked for 5 seconds by Synthetic Manager')).toBeInTheDocument();
        expect(screen.getByText('Comings and goings')).toBeInTheDocument();
        expect(screen.getByText('Oblivion never unlocks it by schedule or automation')).toBeInTheDocument();
        // "Keep unlocked" has no governed command yet, so it is never offered.
        expect(screen.queryByRole('button', { name: /Keep unlocked/ })).not.toBeInTheDocument();
    });

    it('unlocks for the door’s own seconds only with a reason, stating the effect first', () => {
        render(<DoorPage {...door()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Unlock for 5 seconds' }));

        const popup = screen.getByRole('alertdialog', { name: 'Unlock the front door?' });
        expect(within(popup).getByText(/at Synthetic House will unlock for 5 seconds/)).toBeInTheDocument();
        expect(within(popup).getByText(/locks again after 5 seconds/)).toBeInTheDocument();
        const send = within(popup).getByRole('button', { name: 'Unlock' });
        expect(send).toBeDisabled();
        fireEvent.change(within(popup).getByLabelText(/Reason/), { target: { value: 'Letting the physio in' } });
        fireEvent.click(send);

        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/devices/61/safety/unlock',
            expect.objectContaining({ reason: 'Letting the physio in', idempotency_key: expect.stringMatching(/^unlock-/) }),
            expect.anything(),
        );
        // The seconds are never sent from the browser.
        expect(inertia.post.mock.calls[0][1]).not.toHaveProperty('duration_seconds');
    });

    it('offers no unlock to someone without the key', () => {
        const base = door();
        render(<DoorPage {...door({ can: { ...base.can, unlock: false }, control: { ...base.control, unlock: null } })} />);

        expect(screen.queryByRole('button', { name: /Unlock for/ })).not.toBeInTheDocument();
        expect(screen.queryByText(/asks for a reason/)).not.toBeInTheDocument();
    });

    it('says why an unlock can’t be sent, in plain words', () => {
        const base = door();
        const reason = 'Synthetic House hub isn’t connected right now, so this can’t run until it’s back. Oblivion doesn’t reach into the house directly.';
        render(<DoorPage {...door({ can: { ...base.can, unlock: false }, control: { ...base.control, unlock: { ...base.control.unlock!, available: false, reason } } })} />);

        expect(screen.getByText(reason)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Unlock for/ })).not.toBeInTheDocument();
    });

    it('lists every event with staff by name and residents never named', () => {
        render(
            <DoorPage
                {...door({
                    tab: 'events',
                    overview: null,
                    events: {
                        range: '7d',
                        ranges: [
                            { key: 'today', label: 'Today' },
                            { key: '7d', label: '7 days' },
                            { key: '90d', label: '90 days' },
                        ],
                        rows,
                        total: 4,
                        page: 1,
                        last_page: 1,
                        ways: [
                            { key: 'card', label: 'Staff card', tone: 'chart-1', value: 1 },
                            { key: 'resident', label: 'Resident fob', tone: 'chart-3', value: 1 },
                            { key: 'refused', label: 'Refused', tone: 'critical', value: 1 },
                        ],
                    },
                })}
            />,
        );

        const table = screen.getByRole('table');
        expect(within(table).getAllByRole('row')).toHaveLength(5);
        expect(within(table).getByText('Synthetic Staff')).toBeInTheDocument();
        expect(within(table).getAllByText('Resident fob').length).toBeGreaterThan(0);
        expect(within(table).getByText('Card refused')).toBeInTheDocument();
        expect(screen.getByText('Showing 4 of 4')).toBeInTheDocument();
        expect(screen.getByText('How people came in')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('radio', { name: '90 days' }));
        expect(inertia.get).toHaveBeenCalledWith('/security-devices/devices/61', { tab: 'events', range: '90d' }, expect.anything());
    });

    it('shows who can open it by group, with residents only as a count', () => {
        render(
            <DoorPage
                {...door({
                    tab: 'access',
                    overview: null,
                    access: {
                        groups: [
                            { key: 'staff-any', group: 'Synthetic House staff', kind: 'staff', who: 'Synthetic Staff and Synthetic Manager', when: 'Any time', count: 2 },
                            { key: 'resident-any', group: 'Residents', kind: 'resident', who: '2 residents’ fobs', when: 'Any time · their home', count: 2 },
                        ],
                        schedules: ['Synthetic House staff'],
                    },
                })}
            />,
        );

        expect(screen.getByText('Lock rule')).toBeInTheDocument();
        expect(screen.getByText('2 residents’ fobs')).toBeInTheDocument();
        expect(screen.getByText(/residents’ names aren’t listed/)).toBeInTheDocument();
        expect(screen.getByText('Never (D12)')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Give someone access/ })).not.toBeInTheDocument();
    });
});
