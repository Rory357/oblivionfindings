import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    HouseSummary,
    SiteModeItem,
    SiteModes,
    SiteRooms,
} from './_types';
import SiteShow, { type SitePageProps } from './show';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    visit: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
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

const inHours = (hours: number) =>
    new Date(Date.now() + hours * 3_600_000).toISOString();

const CHOICES: NonNullable<SiteModes['edit']>['choices'] = [
    {
        key: 'notify',
        label: 'Tell people',
        description:
            'The staff on shift at the house, a role, or named people, in the app (and by push or email if chosen).',
    },
    {
        key: 'open_alert',
        label: 'Open an alert',
        description:
            'An alert in Security & devices › Alerts, for the staff on shift.',
    },
    {
        key: 'camera_privacy_on',
        label: 'Turn camera privacy on',
        description:
            'No picture from the chosen cameras until a time, the same as their privacy hours.',
    },
    {
        key: 'door_locked_check',
        label: 'Check doors are locked',
        description:
            'Reads each chosen door’s lock; any that isn’t locked is reported to the staff on shift to lock by hand.',
    },
    {
        key: 'alarm_armed_check',
        label: 'Check the alarm is armed',
        description:
            'Reads the alarm’s areas; if one isn’t armed, the staff on shift are reminded to arm it.',
    },
];

const day: SiteModeItem = {
    id: 1,
    name: 'Day',
    sub: '7:00 am – 9:00 pm · on now',
    on: true,
    flag: { tone: 'success', text: 'On now', at: null },
    facts: [{ tone: 'info', text: 'Tell staff on shift it’s Day' }],
    schedule: { days: [1, 2, 3, 4, 5, 6, 7], starts: '07:00' },
    actions: [{ action: 'notify', message: null }],
};

const night: SiteModeItem = {
    id: 2,
    name: 'Night',
    sub: '9:00 pm – 7:00 am',
    on: false,
    flag: { tone: 'info', text: 'Starts {time}', at: inHours(3) },
    facts: [
        {
            tone: 'none',
            text: 'Camera privacy on until 6:00 am: Synthetic hallway camera',
        },
        {
            tone: 'info',
            text: 'Check the door is locked: Synthetic front door · tell staff on shift if not',
        },
        {
            tone: 'info',
            text: 'Check the alarm is armed by 9:15 pm · remind staff on shift if not',
        },
    ],
    schedule: { days: [1, 2, 3, 4, 5, 6, 7], starts: '21:00' },
    actions: [
        { action: 'camera_privacy_on', cameras: [31], until: '06:00' },
        { action: 'door_locked_check', doors: [21] },
        { action: 'alarm_armed_check', panels: [11], after_minutes: 15 },
    ],
};

const house: HouseSummary = {
    current: { id: 1, name: 'Day', since: inHours(-5) },
    next: { name: 'Night', at: inHours(3) },
    modes: [
        {
            id: 1,
            name: 'Day',
            summary: ['Staff on shift are told.'],
            usual: 'Day normally starts at 7:00 am',
        },
        {
            id: 2,
            name: 'Night',
            summary: [
                'It turns on camera privacy for **Synthetic hallway camera** until 6:00 am.',
                'It checks **Synthetic front door** is locked and tells staff on shift if it isn’t. It doesn’t lock or unlock it.',
                'It doesn’t arm the alarm: it checks the alarm is armed 15 min after the switch and reminds staff on shift if not.',
            ],
            usual: 'Night normally starts at 9:00 pm',
        },
    ],
};

const modes = (overrides: Partial<SiteModes> = {}): SiteModes => ({
    items: [day, night],
    log: [
        {
            id: 9,
            at: inHours(-5),
            tone: 'warning',
            title: 'Night → Day',
            sub: 'By schedule · Synthetic back door not locked · staff on shift told',
        },
        {
            id: 8,
            at: inHours(-14),
            tone: 'success',
            title: 'Alarm armed check passed',
            sub: 'Whole house armed at 9:06 pm (from the panel’s report)',
        },
    ],
    edit: {
        choices: CHOICES,
        doors: [{ id: 21, name: 'Synthetic front door' }],
        cameras: [{ id: 31, name: 'Synthetic hallway camera' }],
        panels: [{ id: 11, name: 'Synthetic alarm' }],
        max: 8,
    },
    ...overrides,
});

const rooms: SiteRooms = {
    items: [
        {
            key: 'room-3',
            name: 'Room 3 · Hemi',
            sub: '1 device',
            flag: { tone: 'warning', text: 'Battery low' },
            facts: [
                {
                    tone: 'success',
                    text: 'Synthetic room sensor: **21.4 °C** · CO₂ 620 ppm',
                    href: '/security-devices/devices/41',
                },
                {
                    tone: 'warning',
                    text: 'Synthetic room sensor battery **12%**',
                    href: '/security-devices/devices/41',
                },
            ],
        },
        {
            key: 'room-5',
            name: 'Lounge',
            sub: '1 device',
            flag: { tone: 'success', text: 'All online' },
            facts: [
                {
                    tone: 'success',
                    text: 'Synthetic lounge Wi-Fi · online',
                    href: '/security-devices/devices/42',
                },
            ],
        },
    ],
    room_count: 6,
    unplaced: {
        count: 2,
        names: ['Synthetic phone base', 'Synthetic UPS'],
        more: 0,
    },
    profile_href: '/sites/4',
};

function fixture(overrides: Partial<SitePageProps> = {}): SitePageProps {
    return {
        site: {
            id: 4,
            name: 'Synthetic House',
            address: '1 Test Street, Testville',
        },
        tab: 'modes',
        flag: { tone: 'success', text: 'All online' },
        hub: {
            label: 'Synthetic House hub',
            own: true,
            answering: true,
            version: '1.0.0',
            seen_at: new Date().toISOString(),
        },
        meters: {
            online: {
                count: 3,
                of: 3,
                caption: {
                    text: 'Checked {ago}',
                    at: new Date(Date.now() - 60_000).toISOString(),
                },
            },
            alerts: { count: 0, caption: 'None open' },
            scan: null,
        },
        overview: null,
        network: null,
        internet: null,
        rooms: null,
        modes: modes(),
        house,
        can: {
            add_device: true,
            assign: true,
            scan: true,
            switch_mode: true,
            edit_modes: true,
        },
        ...overrides,
    };
}

beforeEach(() => {
    Object.values(inertia).forEach((mock) => mock.mockReset());
});

afterEach(() => {
    cleanup();
});

describe('Site page · Rooms tab (pg-sdr-12d)', () => {
    it('groups devices by room with the reading that matters, and lists the ones not in a room yet', () => {
        render(<SiteShow {...fixture({ tab: 'rooms', modes: null, rooms })} />);

        expect(screen.getByRole('tab', { name: 'Rooms' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(
            screen.getByText('Devices grouped by where they are'),
        ).toBeInTheDocument();
        expect(screen.getByText('Room 3 · Hemi')).toBeInTheDocument();
        expect(screen.getByText('21.4 °C').tagName).toBe('B');
        expect(screen.getByText('Battery low')).toBeInTheDocument();
        expect(screen.getByText('Lounge')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Synthetic lounge Wi-Fi/ }),
        ).toHaveAttribute('href', '/security-devices/devices/42');
        expect(screen.getByText('Not in a room yet')).toBeInTheDocument();
        expect(screen.getByText('Place them')).toBeInTheDocument();
        expect(
            screen.getByText('Synthetic phone base, Synthetic UPS'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Add or rename rooms in Sites & locations › Synthetic House.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Open Site profile' }),
        ).toHaveAttribute('href', '/sites/4');
    });

    it('says so plainly when the Site has no rooms, and hides the profile link from those who can’t open it', () => {
        render(
            <SiteShow
                {...fixture({
                    tab: 'rooms',
                    modes: null,
                    rooms: {
                        items: [],
                        room_count: 0,
                        unplaced: null,
                        profile_href: null,
                    },
                })}
            />,
        );

        expect(
            screen.getByText(
                'This Site has no rooms yet, and no devices here to place.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Open Site profile' }),
        ).not.toBeInTheDocument();
    });
});

describe('Site page · Modes tab (pg-sdr-12c)', () => {
    it('shows the current mode, the next switch, each mode’s safe actions and the log', () => {
        render(<SiteShow {...fixture()} />);

        expect(screen.getByRole('tab', { name: 'Modes' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(
            screen.queryByRole('tab', { name: /devices|alerts|logbook/i }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('House mode')).toBeInTheDocument();
        expect(
            screen.getByText(
                /^Night starts \d{1,2}:\d{2} (am|pm)|^Night starts \w{3} \d{1,2} \w{3}/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('7:00 am – 9:00 pm · on now'),
        ).toBeInTheDocument();
        expect(screen.getByText('On now')).toBeInTheDocument();
        expect(screen.getByText(/^Starts /)).toBeInTheDocument();
        expect(
            screen.getByText(
                'Check the door is locked: Synthetic front door · tell staff on shift if not',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Camera privacy on until 6:00 am: Synthetic hallway camera',
            ),
        ).toBeInTheDocument();
        // Said plainly: a mode checks and reminds; it never disarms or unlocks.
        expect(
            screen.getByText(
                'Modes and automations never disarm an alarm or unlock a door.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /A mode never disarms the alarm or unlocks a door/,
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Night → Day')).toBeInTheDocument();
        expect(
            screen.getByText(
                'By schedule · Synthetic back door not locked · staff on shift told',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Alarm armed check passed'),
        ).toBeInTheDocument();
        expect(screen.getByText(/For example “Visitors”/)).toBeInTheDocument();
    });

    it('switches by hand from a mode’s menu, with a confirm that says exactly what will happen (pop-up 42)', () => {
        render(<SiteShow {...fixture()} />);

        fireEvent.contextMenu(
            screen.getByText('9:00 pm – 7:00 am').closest('li')!,
        );
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'Switch to Night' }),
        );

        const popup = screen.getByTestId('sd-switch-mode');
        expect(
            within(popup).getByText('Switch Synthetic House to Night?'),
        ).toBeInTheDocument();
        expect(within(popup).getByText('Synthetic front door').tagName).toBe(
            'B',
        );
        expect(
            within(popup).getByText(/It doesn’t lock or unlock it\./),
        ).toBeInTheDocument();
        expect(
            within(popup).getByText(/It doesn’t arm the alarm/),
        ).toBeInTheDocument();
        expect(within(popup).getByText('Mode: Day')).toBeInTheDocument();
        expect(
            within(popup).getByText('Night normally starts at 9:00 pm'),
        ).toBeInTheDocument();
        const buttons = within(popup)
            .getAllByRole('button')
            .map((button) => button.textContent);
        expect(buttons.indexOf('Cancel')).toBeLessThan(
            buttons.indexOf('Switch to Night'),
        );

        fireEvent.click(
            within(popup).getByRole('button', { name: 'Switch to Night' }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/sites/4/modes/2/switch',
            {},
            expect.anything(),
        );
    });

    it('offers Change mode in the header only to those who may switch', () => {
        const { unmount } = render(<SiteShow {...fixture()} />);
        expect(
            screen.getByRole('button', { name: /Change mode/ }),
        ).toBeInTheDocument();
        unmount();

        render(
            <SiteShow
                {...fixture({
                    can: {
                        add_device: false,
                        assign: false,
                        scan: false,
                        switch_mode: false,
                        edit_modes: false,
                    },
                    modes: modes({ edit: null }),
                })}
            />,
        );
        expect(
            screen.queryByRole('button', { name: /Change mode/ }),
        ).not.toBeInTheDocument();
        fireEvent.contextMenu(
            screen.getByText('9:00 pm – 7:00 am').closest('li')!,
        );
        expect(
            screen.queryByRole('menuitem', { name: 'Switch to Night' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('menuitem', { name: 'Edit mode' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/For example “Visitors”/),
        ).not.toBeInTheDocument();
    });

    it('says plainly when the Site has no modes yet', () => {
        render(
            <SiteShow
                {...fixture({
                    modes: modes({ items: [], log: [] }),
                    house: { current: null, next: null, modes: [] },
                })}
            />,
        );

        expect(
            screen.getByText('This Site has no house modes yet.'),
        ).toBeInTheDocument();
        expect(screen.getByText('None yet')).toBeInTheDocument();
        expect(
            screen.getByText('Add one on the Modes tab'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'No switches yet. Each one shows here with what it found.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Change mode/ }),
        ).not.toBeInTheDocument();
    });
});
