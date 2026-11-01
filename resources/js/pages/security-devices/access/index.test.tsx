import { buildSecurityDevicesNavigationGroups } from '@/components/security-devices/security-devices-navigation';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AccessIndex, { type AccessPageProps } from './index';
import type { DoorCard, PersonRow } from './_types';

const inertia = vi.hoisted(() => ({ get: vi.fn(), visit: vi.fn(), reload: vi.fn() }));
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

const hourAgo = new Date(Date.now() - 3_600_000).toISOString();

function door(overrides: Partial<DoorCard> = {}): DoorCard {
    return {
        id: 1,
        external_id: 'door-front',
        console_id: 3,
        site_id: 4,
        name: 'Synthetic front door',
        sub: 'UniFi Access G2 reader · front',
        icon: 'door-closed',
        group: 'normal',
        reason: null,
        badge: { tone: 'success', text: 'Online' },
        note: { text: 'Checked {ago}', at: hourAgo },
        value: 'Locked',
        value_sub: 'Closed · 3 can open it',
        can_open: 3,
        graph: { label: 'Entries per hour, last 24 hours', note: { text: '4 in 24 hours', at: null }, spark: Array.from({ length: 24 }, (_, index) => (index % 6 === 0 ? 1 : 0)) },
        site: 'Synthetic House',
        seen: { label: 'Seen', at: hourAgo },
        href: '/security-devices/devices/41',
        ...overrides,
    };
}

function person(overrides: Partial<PersonRow> = {}): PersonRow {
    return {
        id: 11,
        console_id: 3,
        site_id: 4,
        site: 'Synthetic House',
        name: 'Test Worker',
        sub: 'Support worker · Synthetic House',
        kind: 'staff',
        status: 'active',
        employee_number: 'E100',
        user_id: 70,
        suggestion: null,
        cards: [{ id: 91, display: '••4821' }],
        gets_in: 'Card ••4821',
        doors: 'House staff',
        policy_ids: ['policy-staff'],
        rank: 4,
        badge: { tone: 'success', text: 'Active' },
        note: null,
        last_used: { text: '{time}', at: hourAgo },
        can_log: true,
        ...overrides,
    };
}

function fixture(overrides: Partial<AccessPageProps> = {}): AccessPageProps {
    return {
        tab: 'doors',
        filters: { site: 'all', q: null, state: 'all', status: 'all', when: 'all' },
        sites: [{ value: '4', label: 'Synthetic House' }],
        consoles: [
            {
                id: 3,
                site_id: 4,
                site: 'Synthetic House',
                state: 'ok',
                synced_at: hourAgo,
                doors: [{ id: 'door-front', name: 'Synthetic front door', readers: [{ id: 'reader-front', name: 'Front reader' }] }],
                policies: [{ id: 'policy-staff', name: 'House staff', doors: 'Synthetic front door', people: 3 }],
            },
        ],
        counts: { doors: 2, people: 3, visitors: 1 },
        meters: {
            locked: { count: 1, of: 2, caption: 'Synthetic back door at Synthetic House unlocked' },
            held_open: { count: 1, caption: { text: 'Synthetic front door at Synthetic House, {time}', at: hourAgo } },
            turned_off: { count: 2, caption: 'This month · 1 lost, 1 leaver' },
            to_match: { count: 1, caption: 'In UniFi, not linked to staff yet' },
        },
        doors: {
            groups: [
                {
                    key: 'attention',
                    title: 'Needs a look',
                    count: 1,
                    sub: '1 held open today',
                    items: [door({ id: 2, name: 'Synthetic back door', group: 'attention', reason: 'held', badge: { tone: 'warning', text: 'Held open today' }, note: { text: 'At {time} · left open after someone came through', at: hourAgo }, href: null })],
                },
                { key: 'normal', title: 'Working normally', count: 1, sub: null, items: [door()] },
            ],
            total: 2,
            emergency: [{ site_id: 4, site: 'Synthetic House', doors: 2, mode: 'normal', known: true }],
            not_managed: { count: 0, names: [] },
        },
        people: null,
        visitors: null,
        schedules: null,
        activity: null,
        can: { manage: true, roll_call: true },
        ...overrides,
    };
}

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('Access › Doors', () => {
    it('puts doors that need a look first, each with its lock, who can open it and its entries', () => {
        render(<AccessIndex {...fixture()} />);

        expect(screen.getByRole('heading', { name: 'Access' })).toBeInTheDocument();
        expect(screen.getByText(/Every door opens from the inside/)).toBeInTheDocument();
        const groups = screen.getAllByRole('region').filter((region) => ['Needs a look', 'Working normally'].includes(region.getAttribute('aria-label') ?? ''));
        expect(groups.map((group) => group.getAttribute('aria-label'))).toEqual(['Needs a look', 'Working normally']);
        expect(within(groups[0]).getByText('Held open today')).toBeInTheDocument();
        expect(within(groups[1]).getByText('Closed · 3 can open it')).toBeInTheDocument();
        expect(within(groups[1]).getByText('4 in 24 hours')).toBeInTheDocument();
        expect(screen.getByText('Normal: no lockdown or evacuation')).toBeInTheDocument();
        expect(screen.queryByText(/\{time\}|\{ago\}/)).not.toBeInTheDocument();
        // No lockdown or evacuation buttons until that pop-up is built.
        expect(screen.queryByRole('button', { name: /lockdown|evacuat/i })).not.toBeInTheDocument();
        // Clock-ins come later.
        expect(screen.queryByText(/clocked in from a badge/i)).not.toBeInTheDocument();
        expect(screen.queryByRole('tab', { name: /clock-ins/i })).not.toBeInTheDocument();
    });

    it('opens the door’s device page from its card, and the unlocked doors from the header', () => {
        render(<AccessIndex {...fixture()} />);

        fireEvent.click(screen.getByRole('article', { name: 'Synthetic front door' }));
        expect(inertia.visit).toHaveBeenCalledWith('/security-devices/devices/41');

        fireEvent.click(screen.getByRole('button', { name: 'Show unlocked doors' }));
        expect(inertia.get).toHaveBeenCalledWith('/security-devices/access', { state: 'unlocked' }, expect.anything());
    });

    it('hides every change for someone who can only look', () => {
        render(<AccessIndex {...fixture({ can: { manage: false, roll_call: true } })} />);

        expect(screen.queryByRole('button', { name: /give someone access/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /add visitor pass/i })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /roll call/i })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /lockdown or evacuation/i })).not.toBeInTheDocument();
    });

    it('offers lockdown or evacuation (pop-up 86) only to people who may, with the governed steps', () => {
        const action = { available: true, reason: null, step_up: false, approval: true, type_name: true, via: 'Synthetic House hub' };
        render(
            <AccessIndex
                {...fixture({
                    emergency_actions: [
                        {
                            console_id: 1,
                            device_id: 90,
                            site_id: 4,
                            site: 'Synthetic House',
                            mode: 'normal',
                            type_name: 'Synthetic House doors',
                            lockdown: action,
                            evacuate: action,
                            end: action,
                            commands: [{ id: 3, phase: 'approval', words: 'Waiting for a second person to approve it', at: null }],
                            confirm_identity_url: '/security-devices/devices/90/safety/confirm-identity',
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByText(/Waiting for a second person to approve it/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Lockdown or evacuation…' }));
        const popup = screen.getByRole('alertdialog', { name: 'Lockdown at Synthetic House?' });
        expect(within(popup).getByLabelText(/Type Synthetic House doors to confirm/)).toBeInTheDocument();
    });
});

describe('Access › People', () => {
    const people = {
        to_match: {
            count: 1,
            people: [person({ id: 12, name: 'Sam T', kind: 'unmatched', user_id: null, employee_number: null, rank: 1, badge: { tone: 'warning', text: 'Not matched' }, can_log: false })],
            lines: ['**Sam T** has no employee number in UniFi'],
        },
        rows: [
            person({ id: 12, name: 'Sam T', sub: 'In UniFi · not linked to staff · Synthetic House', kind: 'unmatched', user_id: null, rank: 1, badge: { tone: 'warning', text: 'Not matched' }, note: { text: 'No employee number in UniFi', at: null }, can_log: false }),
            person(),
            person({
                id: 13,
                name: 'Synthetic Resident',
                sub: 'Resident · Synthetic House',
                kind: 'resident',
                user_id: null,
                gets_in: 'Resident fob',
                badge: { tone: 'neutral', text: 'Resident fob' },
                note: { text: 'Their own home, any time', at: null },
                last_used: { text: 'Not shown for residents', at: null },
                can_log: false,
            }),
        ],
        meta: { page: 1, last_page: 1, total: 3, from: 1, to: 3 },
        can_get_in: 3,
        turned_off: 0,
    };

    it('shows who to match first, then everyone, and never when a resident came and went', () => {
        render(<AccessIndex {...fixture({ tab: 'people', doors: null, people })} />);

        expect(screen.getByText('1 person in UniFi isn’t linked to your staff yet')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /match people/i })).toBeInTheDocument();
        expect(screen.getByText('Not shown for residents')).toBeInTheDocument();
        expect(screen.getAllByText('Resident fob').length).toBeGreaterThanOrEqual(2);
        expect(screen.getByText('How cards work here')).toBeInTheDocument();
        expect(screen.queryByText(/\(D\d+\)/)).not.toBeInTheDocument();
    });

    it('offers no matching to someone who can only look', () => {
        render(<AccessIndex {...fixture({ tab: 'people', doors: null, people, can: { manage: false, roll_call: true } })} />);

        expect(screen.queryByRole('button', { name: /match people/i })).not.toBeInTheDocument();
    });
});

describe('Access › Visitors, Schedules and Activity', () => {
    it('lists visitor passes with their times and status', () => {
        render(
            <AccessIndex
                {...fixture({
                    tab: 'visitors',
                    doors: null,
                    visitors: {
                        total: 1,
                        rows: [
                            {
                                id: 5,
                                name: 'Test Contractor',
                                site_id: 4,
                                site: 'Synthetic House',
                                starts_at: '2026-11-03T21:00:00Z',
                                ends_at: '2026-11-03T23:00:00Z',
                                phase: 'coming',
                                badge: { tone: 'neutral', text: 'Starts {date}', at: '2026-11-03T21:00:00Z' },
                                can_cancel: true,
                            },
                        ],
                    },
                })}
            />,
        );

        expect(screen.getByText('Test Contractor')).toBeInTheDocument();
        expect(screen.getByText(/Residents’ own guests are their business/)).toBeInTheDocument();
        expect(screen.getByText(/Starts Wed 4 Nov/)).toBeInTheDocument();
    });

    it('lists door groups read only, with nothing to edit', () => {
        render(
            <AccessIndex
                {...fixture({
                    tab: 'schedules',
                    doors: null,
                    schedules: { rows: [{ id: '3-policy-staff', name: 'House staff', site: 'Synthetic House', site_id: 4, sub: '3 people', doors: 'Synthetic House: Synthetic front door', door_count: 1, when: 'On a schedule set in UniFi Access' }] },
                })}
            />,
        );

        expect(screen.getByText('House staff')).toBeInTheDocument();
        expect(screen.getByText('On a schedule set in UniFi Access')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /edit|add schedule|add group/i })).not.toBeInTheDocument();
    });

    it('names matched staff only, and shows residents as “Resident fob”', () => {
        render(
            <AccessIndex
                {...fixture({
                    tab: 'activity',
                    doors: null,
                    activity: {
                        rows: [
                            { id: 1, at: hourAgo, door_id: 1, door: 'Synthetic front door', site: 'Synthetic House', who: { text: 'Test Worker', named: true, person_id: 11 }, how: 'Card', what: { text: 'Came in', badge: null } },
                            { id: 2, at: hourAgo, door_id: 1, door: 'Synthetic front door', site: 'Synthetic House', who: { text: 'Resident fob', named: false, person_id: null }, how: 'Fob', what: { text: 'Came in', badge: null } },
                            { id: 3, at: hourAgo, door_id: 1, door: 'Synthetic front door', site: 'Synthetic House', who: { text: '—', named: false, person_id: null }, how: 'Door sensor', what: { text: 'Held open', badge: { tone: 'warning', text: 'Held open' } } },
                        ],
                        meta: { page: 1, last_page: 1, total: 3, from: 1, to: 3 },
                        filters: { door: 'all', kind: 'all', days: 'today', person: null },
                        person: null,
                        doors: [{ value: '1', label: 'Synthetic front door · Synthetic House' }],
                        worth_a_look: [{ key: 'event-3', at: hourAgo, tone: 'warning', title: 'Synthetic front door held open', sub: 'Synthetic House' }],
                        how_in: { total: 2, parts: [{ label: 'Card', value: 1 }, { label: 'Resident fob', value: 1 }] },
                        events_read: true,
                    },
                })}
            />,
        );

        expect(screen.getByText('Test Worker')).toBeInTheDocument();
        expect(screen.getAllByText('Resident fob').length).toBeGreaterThanOrEqual(1);
        expect(screen.getByText('Synthetic front door held open')).toBeInTheDocument();
        expect(screen.getByRole('img', { name: /2 came in today/ })).toBeInTheDocument();
        expect(screen.getByText('What’s kept')).toBeInTheDocument();
    });
});

describe('Access in the Security & devices menu', () => {
    it('is hidden from people who can’t see door access', () => {
        const items = buildSecurityDevicesNavigationGroups({ viewAny: true, devicesView: true, eventsView: true })[0]?.items ?? [];

        expect(items.map((item) => item.title)).not.toContain('Access');
    });

    it('sits between Sites and Alerts for people who can', () => {
        const items = buildSecurityDevicesNavigationGroups({ viewAny: true, devicesView: true, eventsView: true, accessControlView: true })[0]?.items ?? [];

        expect(items.map((item) => [item.title, item.href])).toEqual([
            ['Overview', '/security-devices'],
            ['Devices', '/security-devices/devices'],
            ['Sites', '/security-devices/sites'],
            ['Access', '/security-devices/access'],
            ['Alerts', '/security-devices/alerts'],
        ]);
    });
});
