import type {
    AutomationForm,
    AutomationRow,
} from '@/pages/security-devices/alerts/_types';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    AddAutomation,
    AutomationOnOff,
    AutomationTest,
} from './automation-popups';

const http = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
}));
vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return {
        default: {
            ...actual.default,
            post: http.post,
            put: http.put,
            delete: http.delete,
            isAxiosError: actual.default.isAxiosError,
        },
    };
});
vi.mock('sonner', () => ({ toast: { success: vi.fn(), warning: vi.fn() } }));

const form: AutomationForm = {
    sites: [{ value: '4', label: 'Synthetic House' }],
    devices: {
        '4': {
            doors: [{ id: 21, name: 'Front door' }],
            sensors: [{ id: 22, name: 'Hot water tap' }],
            cameras: [{ id: 23, name: 'Hallway camera' }],
            panels: [{ id: 24, name: 'Alarm panel' }],
            any: [{ id: 21, name: 'Front door · Door' }],
        },
    },
    roles: [{ value: 'house_manager', label: 'House manager' }],
    // The Site's house modes, for "House mode is".
    modes: {
        '4': [
            { id: 2, name: 'Day' },
            { id: 3, name: 'Night' },
        ],
    },
    // Exactly what SafeActions::choices() sends: there is no disarm or unlock to offer.
    actions: [
        {
            key: 'notify',
            label: 'Tell people',
            description: 'The staff on shift at the house.',
        },
        {
            key: 'open_alert',
            label: 'Open an alert',
            description: 'An alert in Alerts.',
        },
        {
            key: 'camera_privacy_on',
            label: 'Turn camera privacy on',
            description: 'No picture until a time.',
        },
        {
            key: 'door_locked_check',
            label: 'Check doors are locked',
            description: 'Reads each lock.',
        },
        {
            key: 'alarm_armed_check',
            label: 'Check the alarm is armed',
            description: 'Reads the areas.',
        },
    ],
    metrics: [
        {
            value: 'temperature',
            label: 'Temperature',
            unit: '°C',
            min: -40,
            max: 120,
        },
        { value: 'humidity', label: 'Humidity', unit: '%', min: 0, max: 100 },
    ],
    max_actions: 5,
};

const row: AutomationRow = {
    id: 5,
    version: 3,
    name: 'Front door at night',
    when: 'Front door opens between 10:00 pm and 6:00 am',
    sub: 'Automation · door',
    icon: 'door-open',
    where: 'Synthetic House',
    then: 'Tell the staff on shift (app)',
    last_run: null,
    on: true,
    can_change: true,
    definition: {
        site_id: 4,
        name: 'Front door at night',
        is_on: true,
        trigger: { kind: 'door_opened', device_id: 21 },
        conditions: { between: { from: '22:00', to: '06:00' } },
        actions: [
            {
                action: 'notify',
                who: 'on_shift',
                email: false,
                push: false,
                message: null,
            },
        ],
    },
};

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

const continueTo = (dialog: HTMLElement) =>
    fireEvent.click(within(dialog).getByRole('button', { name: 'Continue' }));

describe('Add automation (pop-up 40)', () => {
    it('walks when, only if, then and check, and saves only safe actions', async () => {
        http.post.mockResolvedValue({
            data: {
                id: 9,
                name: 'A door opens in Night mode',
                on: true,
                message: 'Automation turned on.',
            },
        });
        const onSaved = vi.fn();
        const onTest = vi.fn();
        render(
            <AddAutomation
                open
                form={form}
                formFailed={false}
                load={() => undefined}
                editing={null}
                onClose={() => undefined}
                onSaved={onSaved}
                onTest={onTest}
            />,
        );
        const dialog = await screen.findByRole('dialog');
        expect(
            within(dialog).getByText('What should start this automation?'),
        ).toBeInTheDocument();

        // Nothing chosen yet: Continue says what's missing.
        continueTo(dialog);
        expect(
            await within(dialog).findByText(
                'Choose what starts the automation.',
            ),
        ).toBeInTheDocument();

        fireEvent.click(
            within(dialog).getByRole('button', { name: /Door opened/ }),
        );
        continueTo(dialog);
        expect(
            await within(dialog).findByText('When should it run?'),
        ).toBeInTheDocument();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Night' }));
        continueTo(dialog);
        expect(
            await within(dialog).findByText('What should happen?'),
        ).toBeInTheDocument();
        expect(within(dialog).getByText('Tell people')).toBeInTheDocument();
        expect(
            within(dialog).getByText(
                /They never lock, unlock, arm or disarm anything/,
            ),
        ).toBeInTheDocument();
        expect(
            within(dialog).queryByText(/^Disarm|^Unlock/),
        ).not.toBeInTheDocument();
        continueTo(dialog);

        expect(
            await within(dialog).findByText('Name it and turn it on'),
        ).toBeInTheDocument();
        expect(within(dialog).getByLabelText(/^Name/)).toHaveValue(
            'A door opens in Night mode',
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Turn on automation' }),
        );

        await waitFor(() =>
            expect(http.post).toHaveBeenCalledWith(
                '/security-devices/automations',
                {
                    site_id: 4,
                    name: 'A door opens in Night mode',
                    is_on: true,
                    trigger: { kind: 'door_opened', device_id: null },
                    conditions: { between: null, modes: [3] },
                    actions: [
                        {
                            action: 'notify',
                            who: 'on_shift',
                            email: false,
                            push: false,
                            message: null,
                        },
                    ],
                },
                expect.anything(),
            ),
        );
        expect(onSaved).toHaveBeenCalled();
        expect(
            await screen.findByText('A door opens in Night mode is on'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Test it now/ }));
        expect(onTest).toHaveBeenCalledWith({
            id: 9,
            name: 'A door opens in Night mode',
        });
    });

    it('goes back to the step the server refused, with its words', async () => {
        http.put.mockRejectedValue(
            Object.assign(new Error('Unprocessable'), {
                isAxiosError: true,
                response: {
                    status: 422,
                    data: {
                        errors: {
                            'actions.0.action': [
                                'That action can’t be automated: automations and house modes never disarm or unlock.',
                            ],
                        },
                    },
                },
            }),
        );
        render(
            <AddAutomation
                open
                form={form}
                formFailed={false}
                load={() => undefined}
                editing={row}
                onClose={() => undefined}
                onSaved={() => undefined}
                onTest={() => undefined}
            />,
        );
        const dialog = await screen.findByRole('dialog');
        // Edit is the same wizard, prefilled: straight to the last step.
        fireEvent.click(
            within(dialog).getAllByRole('button', {
                name: /Check and turn on/,
            })[0],
        );
        expect(within(dialog).getByLabelText(/^Name/)).toHaveValue(
            'Front door at night',
        );
        expect(
            within(dialog).getByText(
                'Front door opens between 10:00 pm and 6:00 am',
            ),
        ).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Save changes' }),
        );

        await waitFor(() =>
            expect(http.put).toHaveBeenCalledWith(
                '/security-devices/automations/5',
                expect.objectContaining({
                    version: 3,
                    trigger: { kind: 'door_opened', device_id: 21 },
                }),
                expect.anything(),
            ),
        );
        expect(
            await within(dialog).findByText('What should happen?'),
        ).toBeInTheDocument();
        // Beside the action it refused.
        expect(
            within(dialog).getByText(/house modes never disarm or unlock/),
        ).toBeInTheDocument();
    });
});

describe('Test it (pop-up 41)', () => {
    it('runs the test once, shows what each action did, and runs again on request', async () => {
        http.post.mockResolvedValue({
            data: {
                run: {
                    id: 40,
                    test: true,
                    at: '2026-11-02T09:21:00Z',
                    summary: 'Front door opened at 10:21 pm',
                    outcome: 'ok',
                    lines: [
                        {
                            tone: 'success',
                            text: 'You got “TEST · Front door at night” in the app',
                            at: '2026-11-02T09:21:00Z',
                        },
                        {
                            tone: 'info',
                            text: 'A real run would tell the staff on shift: Mere Tane (app). Only you were told, marked “TEST”',
                            at: '2026-11-02T09:21:00Z',
                        },
                    ],
                    result: 'The action worked',
                },
            },
        });
        const onRan = vi.fn();
        render(
            <AutomationTest
                target={{ id: 5, name: 'Front door at night' }}
                onClose={() => undefined}
                onRan={onRan}
            />,
        );

        const dialog = await screen.findByRole('dialog', {
            name: 'Test: Front door at night',
        });
        expect(
            await within(dialog).findByText(
                'You got “TEST · Front door at night” in the app',
            ),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(
                /A real run would tell the staff on shift/,
            ),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText('The action worked'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(
                'Only you got a message, marked “TEST”. Nothing else happened.',
            ),
        ).toBeInTheDocument();
        expect(http.post).toHaveBeenCalledTimes(1);
        expect(http.post).toHaveBeenCalledWith(
            '/security-devices/automations/5/test',
            {},
            expect.anything(),
        );
        expect(onRan).toHaveBeenCalled();

        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Run again' }),
        );
        await waitFor(() => expect(http.post).toHaveBeenCalledTimes(2));
        expect(
            within(dialog).getByRole('button', { name: 'Done' }),
        ).toBeInTheDocument();
    });
});

describe('Turn an automation off', () => {
    it('needs a reason, kept in the history', async () => {
        http.post.mockResolvedValue({
            data: { message: 'Automation turned off.' },
        });
        const onChanged = vi.fn();
        render(
            <AutomationOnOff
                row={row}
                onClose={() => undefined}
                onChanged={onChanged}
            />,
        );

        const dialog = await screen.findByRole('alertdialog', {
            name: 'Turn off this automation?',
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Turn off automation' }),
        );
        expect(
            await within(dialog).findByText(
                'Say why in at least 10 characters.',
            ),
        ).toBeInTheDocument();
        expect(http.post).not.toHaveBeenCalled();

        fireEvent.change(within(dialog).getByLabelText(/Why turn it off/), {
            target: { value: 'The door is being replaced' },
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Turn off automation' }),
        );
        await waitFor(() =>
            expect(http.post).toHaveBeenCalledWith(
                '/security-devices/automations/5/off',
                { version: 3, reason: 'The door is being replaced' },
                expect.anything(),
            ),
        );
        await waitFor(() => expect(onChanged).toHaveBeenCalled());
    });
});
