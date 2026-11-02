import type {
    HouseModeEditOptions,
    HouseSummary,
    SiteModeItem,
} from '@/pages/security-devices/sites/_types';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { EditHouseMode, SwitchHouseMode } from './house-mode-popups';

const inertia = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return { ...actual, router: { ...actual.router, ...inertia } };
});

const options: HouseModeEditOptions = {
    choices: [
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
    ],
    doors: [
        { id: 21, name: 'Synthetic front door' },
        { id: 22, name: 'Synthetic back door' },
    ],
    cameras: [{ id: 31, name: 'Synthetic hallway camera' }],
    panels: [{ id: 11, name: 'Synthetic alarm' }],
    max: 8,
};

const night: SiteModeItem = {
    id: 2,
    name: 'Night',
    sub: '9:00 pm – 7:00 am',
    on: false,
    flag: { tone: 'info', text: 'Starts {time}', at: null },
    facts: [],
    schedule: { days: [1, 2, 3, 4, 5, 6, 7], starts: '21:00' },
    actions: [
        { action: 'door_locked_check', doors: [21] },
        { action: 'alarm_armed_check', panels: [11], after_minutes: 15 },
    ],
};

beforeEach(() => {
    inertia.post.mockReset();
    inertia.put.mockReset();
});

afterEach(() => {
    cleanup();
});

describe('Edit a house mode (pop-up 43)', () => {
    it('offers only the safe actions, with no way to add a disarm or an unlock', () => {
        render(
            <EditHouseMode
                open
                onClose={() => undefined}
                siteId={4}
                siteName="Synthetic House"
                mode={night}
                options={options}
            />,
        );

        const popup = screen.getByTestId('sd-edit-mode');
        expect(
            within(popup).getByText('Edit Night mode · Synthetic House'),
        ).toBeInTheDocument();
        expect(
            within(popup).getByText('What Night does, and when it starts'),
        ).toBeInTheDocument();
        const switches = within(popup)
            .getAllByRole('switch')
            .map((control) => control.getAttribute('id'));
        expect(switches).toEqual([
            'sd-mode-scheduled',
            'sd-mode-action-notify',
            'sd-mode-action-open_alert',
            'sd-mode-action-camera_privacy_on',
            'sd-mode-action-door_locked_check',
            'sd-mode-action-alarm_armed_check',
        ]);
        expect(
            within(popup).queryByRole('switch', {
                name: /disarm|unlock|arm the whole|lock the/i,
            }),
        ).not.toBeInTheDocument();
        expect(
            within(popup).getByText(
                /Modes check and remind\. They never lock, arm, disarm or unlock anything/,
            ),
        ).toBeInTheDocument();
        // The alarm check says when, from the start time.
        expect(within(popup).getByText('By 9:15 pm')).toBeInTheDocument();
        const buttons = within(popup)
            .getAllByRole('button')
            .map((button) => button.textContent);
        expect(buttons.indexOf('Cancel')).toBeLessThan(
            buttons.indexOf('Save mode'),
        );
    });

    it('saves the schedule and the chosen safe actions', () => {
        render(
            <EditHouseMode
                open
                onClose={() => undefined}
                siteId={4}
                siteName="Synthetic House"
                mode={night}
                options={options}
            />,
        );
        const popup = screen.getByTestId('sd-edit-mode');

        fireEvent.click(
            within(popup).getByRole('switch', { name: 'Tell people' }),
        );
        fireEvent.change(within(popup).getByLabelText('Message'), {
            target: { value: 'Night staff, check the side gate' },
        });
        fireEvent.click(
            within(popup).getByRole('switch', {
                name: 'Turn camera privacy on',
            }),
        );
        fireEvent.click(
            within(popup).getByRole('button', {
                name: 'Synthetic hallway camera',
            }),
        );
        fireEvent.click(
            within(popup).getByRole('button', { name: 'Synthetic back door' }),
        );
        fireEvent.click(
            within(popup).getByRole('button', { name: 'Save mode' }),
        );

        expect(inertia.put).toHaveBeenCalledWith(
            '/security-devices/sites/4/modes/2',
            {
                schedule: { days: [1, 2, 3, 4, 5, 6, 7], starts: '21:00' },
                actions: [
                    {
                        action: 'notify',
                        message: 'Night staff, check the side gate',
                    },
                    {
                        action: 'camera_privacy_on',
                        cameras: [31],
                        until: '06:00',
                    },
                    { action: 'door_locked_check', doors: [21, 22] },
                    {
                        action: 'alarm_armed_check',
                        panels: [11],
                        after_minutes: 15,
                    },
                ],
            },
            expect.anything(),
        );
    });

    it('shows a server refusal beside the action it is about', () => {
        inertia.put.mockImplementation(
            (
                _url: string,
                _data: unknown,
                visit: {
                    onError: (errors: Record<string, string>) => void;
                    onFinish: () => void;
                },
            ) => {
                visit.onError({
                    'actions.0.doors': 'Choose at least one door at this Site.',
                });
                visit.onFinish();
            },
        );
        render(
            <EditHouseMode
                open
                onClose={() => undefined}
                siteId={4}
                siteName="Synthetic House"
                mode={night}
                options={options}
            />,
        );
        const popup = screen.getByTestId('sd-edit-mode');

        fireEvent.click(
            within(popup).getByRole('button', { name: 'Save mode' }),
        );
        expect(
            within(popup).getByText('Choose at least one door at this Site.'),
        ).toBeInTheDocument();
        expect(
            within(popup).queryByText(/Result not confirmed/),
        ).not.toBeInTheDocument();
    });

    it('adds a mode by hand only, and asks for its name first', () => {
        render(
            <EditHouseMode
                open
                onClose={() => undefined}
                siteId={4}
                siteName="Synthetic House"
                mode={null}
                options={options}
            />,
        );
        const popup = screen.getByTestId('sd-edit-mode');
        expect(
            within(popup).getByText('Add a mode · Synthetic House'),
        ).toBeInTheDocument();

        fireEvent.click(
            within(popup).getByRole('button', { name: 'Add mode' }),
        );
        expect(
            within(popup).getByText(
                'Give the mode a short name (up to 40 letters).',
            ),
        ).toBeInTheDocument();
        expect(inertia.post).not.toHaveBeenCalled();

        fireEvent.change(within(popup).getByLabelText(/^Name/), {
            target: { value: 'Visitors' },
        });
        fireEvent.click(
            within(popup).getByRole('switch', { name: 'Starts on a schedule' }),
        );
        fireEvent.click(
            within(popup).getByRole('button', { name: 'Add mode' }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/sites/4/modes',
            { name: 'Visitors', schedule: null, actions: [] },
            expect.anything(),
        );
    });

    it('says “Result not confirmed” when no answer comes back, instead of inviting a second save', () => {
        inertia.put.mockImplementation(
            (_url: string, _data: unknown, visit: { onFinish: () => void }) =>
                visit.onFinish(),
        );
        render(
            <EditHouseMode
                open
                onClose={() => undefined}
                siteId={4}
                siteName="Synthetic House"
                mode={night}
                options={options}
            />,
        );
        const popup = screen.getByTestId('sd-edit-mode');

        fireEvent.click(
            within(popup).getByRole('button', { name: 'Save mode' }),
        );
        expect(
            within(popup).getByText(/Result not confirmed/),
        ).toBeInTheDocument();
    });
});

describe('Switch the house to a mode (pop-up 42)', () => {
    const house: HouseSummary = {
        current: null,
        next: null,
        modes: [
            {
                id: 3,
                name: 'Away',
                summary: [
                    'Away doesn’t do anything yet: only the house mode changes.',
                ],
                usual: null,
            },
        ],
    };

    it('names the mode, says what it does, and switches', () => {
        const onClose = vi.fn();
        inertia.post.mockImplementation(
            (
                _url: string,
                _data: unknown,
                visit: { onSuccess: () => void; onFinish: () => void },
            ) => {
                visit.onSuccess();
                visit.onFinish();
            },
        );
        render(
            <SwitchHouseMode
                open
                onClose={onClose}
                siteId={4}
                siteName="Synthetic House"
                house={house}
                modeId={3}
            />,
        );
        const popup = screen.getByTestId('sd-switch-mode');

        expect(
            within(popup).getByText('Switch Synthetic House to Away?'),
        ).toBeInTheDocument();
        expect(
            within(popup).getByText(
                'Away doesn’t do anything yet: only the house mode changes.',
            ),
        ).toBeInTheDocument();
        expect(within(popup).getByText('No mode yet')).toBeInTheDocument();
        expect(
            within(popup).getByText('Switched by hand only'),
        ).toBeInTheDocument();
        fireEvent.click(
            within(popup).getByRole('button', { name: 'Switch to Away' }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/sites/4/modes/3/switch',
            {},
            expect.anything(),
        );
        expect(onClose).toHaveBeenCalled();
    });
});
