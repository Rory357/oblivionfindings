import type {
    AlertRuleRow,
    SelectedAlert,
} from '@/pages/security-devices/alerts/_types';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    AddAlertRule,
    alertLine,
    AssignAlert,
    nextWholeHour,
    nzInstant,
    QuietTime,
    ReportIncidentFromAlert,
    RuleOnOff,
    SnoozeAlert,
} from './alert-popups';

const inertia = vi.hoisted(() => ({ visit: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        router: { ...actual.router, ...inertia, on: () => () => undefined },
    };
});
vi.mock('sonner', () => ({ toast: { success: vi.fn(), warning: vi.fn() } }));

const ago = (minutes: number) =>
    new Date(Date.now() - minutes * 60_000).toISOString();
const target = {
    id: 4,
    title: 'Synthetic Lodge router is offline',
    opened_at: ago(60),
};

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.clearAllMocks();
    sessionStorage.clear();
});

describe('alert lines and times', () => {
    it('fills the server’s placeholders in NZ words', () => {
        const now = new Date('2026-11-01T02:00:00Z');
        expect(alertLine('Open {for}', '2026-11-01T00:00:00Z', now)).toBe(
            'Open 2 h',
        );
        expect(
            alertLine('Resolved {closed}', null, now, {
                closed: '2026-11-01T01:00:00Z',
            }),
        ).toBe('Resolved 1 h ago');
        expect(
            alertLine('Snoozed until {snoozed}', null, now, {
                snoozed: '2026-11-01T03:30:00Z',
            }),
        ).toBe('Snoozed until 4:30 pm');
    });

    it('turns NZ wall-clock time into an exact time, refusing one the clocks skip', () => {
        expect(nzInstant('2026-11-02T11:00')).toEqual({
            iso: '2026-11-02T11:00:00+13:00',
        });
        // 27 Sep 2026: clocks go from 2:00 am to 3:00 am.
        expect(nzInstant('2026-09-27T02:30')).toEqual({
            error: expect.stringContaining('skipped'),
        });
        expect(nextWholeHour(30, new Date('2026-11-01T02:10:00Z'))).toBe(
            '2026-11-01T16:00',
        );
    });
});

describe('Assign alert', () => {
    it('lists who can look after it, then tells the chosen person', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({
            data: { people: [{ id: 3, name: 'Test Person', hint: 'IT lead' }] },
        });
        const post = vi
            .spyOn(axios, 'post')
            .mockResolvedValue({
                data: { message: 'Test Person has been told.' },
            });
        render(<AssignAlert open alert={target} onClose={vi.fn()} />);

        const dialog = await screen.findByRole('dialog', {
            name: 'Assign alert',
        });
        expect(
            within(dialog).getByRole('button', { name: 'Assign' }),
        ).toBeDisabled();
        fireEvent.click(
            await within(dialog).findByRole('combobox', { name: 'Person' }),
        );
        fireEvent.click(await screen.findByText('Test Person'));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Assign' }));

        await waitFor(() =>
            expect(post).toHaveBeenCalledWith(
                '/security-devices/alerts/4/assign',
                { user_id: 3, note: null },
                expect.anything(),
            ),
        );
    });

    it('says when the list of people couldn’t load, with Retry', async () => {
        const get = vi.spyOn(axios, 'get').mockRejectedValue(new Error('boom'));
        render(<AssignAlert open alert={target} onClose={vi.fn()} />);

        expect(
            await screen.findByText('The list of people couldn’t load.'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
        expect(get).toHaveBeenCalledTimes(2);
    });
});

describe('Snooze alert', () => {
    it('refuses a time less than 5 minutes ahead without sending anything', async () => {
        const post = vi.spyOn(axios, 'post');
        const { rerender } = render(
            <SnoozeAlert open={false} alert={target} onClose={vi.fn()} />,
        );
        rerender(<SnoozeAlert open alert={target} onClose={vi.fn()} />);
        const dialog = await screen.findByRole('dialog', {
            name: 'Snooze alert',
        });
        expect(within(dialog).getByText('Snooze until')).toBeInTheDocument();
        // The pop-up opened on the next whole hour; by the time Snooze is pressed, that is in the past.
        vi.spyOn(Date, 'now').mockReturnValue(
            new Date().getTime() + 2 * 86_400_000,
        );
        fireEvent.click(within(dialog).getByRole('button', { name: 'Snooze' }));

        expect(
            await within(dialog).findByText(
                'Choose a time at least 5 minutes from now.',
            ),
        ).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
    });
});

describe('Quiet time for planned work', () => {
    it('keeps the safety wording and asks for devices and a reason before pausing anything', async () => {
        const post = vi.spyOn(axios, 'post');
        render(
            <QuietTime
                open
                devices={[
                    {
                        id: 11,
                        name: 'Synthetic switch',
                        site_id: 1,
                        site: 'Synthetic House',
                        kind: 'Network switch',
                        domain: 'it_infrastructure',
                    },
                ]}
                devicesFailed={false}
                loadDevices={vi.fn()}
                actorId={7}
                onClose={vi.fn()}
            />,
        );

        const dialog = await screen.findByRole('dialog', {
            name: 'Quiet time for planned work',
        });
        expect(
            within(dialog).getByText(
                'Panic buttons, alarms and bed sensors are never paused.',
            ),
        ).toBeInTheDocument();
        fireEvent.change(within(dialog).getByLabelText(/Why/), {
            target: { value: 'short' },
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Pause alerts' }),
        );

        expect(
            await within(dialog).findByText('Choose at least one device.'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(/at least 10 characters/),
        ).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
    });

    it('shows loading, then a way to retry when the devices couldn’t load', async () => {
        const load = vi.fn();
        render(
            <QuietTime
                open
                devices={undefined}
                devicesFailed
                loadDevices={load}
                actorId={7}
                onClose={vi.fn()}
            />,
        );

        fireEvent.click(await screen.findByRole('button', { name: 'Retry' }));
        expect(load).toHaveBeenCalled();
    });
});

describe('Report incident from this alert', () => {
    const alert: SelectedAlert = {
        id: 4,
        title: 'Synthetic Lodge router is offline',
        tone: 'critical',
        state: 'open',
        label: { text: 'Open {for}', at: ago(60) },
        opened_at: ago(60),
        closed_at: null,
        device: { id: 9, name: 'Synthetic Lodge router', sub: 'Test Router 1' },
        facts: [
            { icon: 'map-pin', text: 'Synthetic Lodge · office', at: null },
        ],
        saw: 'It stopped answering at {time}.',
        affects: null,
        timeline: [],
        acknowledged: null,
        assigned: null,
        can: {
            acknowledge: true,
            assign: true,
            snooze: true,
            report_incident: true,
        },
    };

    it('shows the facts read-only, notes it on the alert, then opens the incident form', async () => {
        const post = vi
            .spyOn(axios, 'post')
            .mockResolvedValue({
                data: { url: '/incidents/create?type=incident' },
            });
        render(
            <ReportIncidentFromAlert open alert={alert} onClose={vi.fn()} />,
        );

        const dialog = await screen.findByRole('dialog', {
            name: 'Report incident from this alert',
        });
        expect(
            within(dialog).getByText('Paste them into the incident form.', {
                exact: false,
            }),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByRole('button', { name: /Copy the details/ }),
        ).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Open incident form' }),
        );

        await waitFor(() =>
            expect(post).toHaveBeenCalledWith(
                '/security-devices/alerts/4/incident',
                {},
                expect.anything(),
            ),
        );
        await waitFor(() =>
            expect(inertia.visit).toHaveBeenCalledWith(
                '/incidents/create?type=incident',
            ),
        );
    });
});

describe('Add alert rule', () => {
    const form = {
        roles: [{ value: 'house_manager', label: 'House manager' }],
        can_all_sites: true,
    };

    it('adds a rule with only the fields a rule really has', async () => {
        const post = vi
            .spyOn(axios, 'post')
            .mockResolvedValue({
                status: 201,
                data: { alert_route: { id: 9, version: 1, state: 'active' } },
            });
        render(
            <AddAlertRule
                open
                form={form}
                formFailed={false}
                sites={[{ value: '1', label: 'Synthetic House' }]}
                devices={[]}
                devicesFailed={false}
                load={vi.fn()}
                onClose={vi.fn()}
            />,
        );

        const dialog = await screen.findByRole('dialog', {
            name: 'Add alert rule',
        });
        expect(
            within(dialog).getByText(/can’t be rerouted or muted/),
        ).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getByRole('button', { name: /Device offline/ }),
        );
        fireEvent.click(within(dialog).getByRole('radio', { name: 'Network' }));
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Add rule' }),
        );

        await waitFor(() =>
            expect(post).toHaveBeenCalledWith(
                '/security-devices/settings/monitoring/alert-routes',
                expect.objectContaining({
                    scope_kind: 'application',
                    site_id: null,
                    device_id: null,
                    device_category: 'network',
                    monitor_kind: 'icmp',
                    critical_notify_in_app: true,
                    critical_notify_email: true,
                    critical_open_it_ticket: true,
                    warning_notify_in_app: true,
                    warning_notify_email: false,
                    critical_escalate_control_room: false,
                    recipient_role: null,
                    name: expect.stringContaining('All Sites'),
                }),
                expect.anything(),
            ),
        );
    });

    it('offers no "All Sites" to someone who can’t see every Site, and warns in red when nobody would be told', async () => {
        render(
            <AddAlertRule
                open
                form={{ ...form, can_all_sites: false }}
                formFailed={false}
                sites={[]}
                devices={[]}
                devicesFailed={false}
                load={vi.fn()}
                onClose={vi.fn()}
            />,
        );

        const dialog = await screen.findByRole('dialog', {
            name: 'Add alert rule',
        });
        expect(
            within(dialog).queryByRole('radio', { name: 'All Sites' }),
        ).not.toBeInTheDocument();
        const fails = within(dialog).getByRole('group', {
            name: 'When it fails',
        });
        fireEvent.click(within(fails).getByRole('button', { name: 'App' }));
        fireEvent.click(within(fails).getByRole('button', { name: 'Email' }));

        expect(
            within(dialog).getByText(
                /Nobody will be told when these devices fail/,
            ),
        ).toBeInTheDocument();
    });
});

describe('Turn a rule off or on', () => {
    const rule: AlertRuleRow = {
        id: 5,
        version: 3,
        name: 'Synthetic rule',
        when: 'A device stops answering',
        sub: 'Rule · network gear',
        icon: 'wifi-off',
        where: 'All Sites',
        then: 'Tell IT',
        on: true,
        can_change: true,
    };

    it('needs a reason, then sends the rule’s version so a stale change is refused', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} });
        render(<RuleOnOff rule={rule} onClose={vi.fn()} />);

        const dialog = await screen.findByRole('alertdialog', {
            name: 'Turn off this rule?',
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Turn off rule' }),
        );
        expect(
            await within(dialog).findByText(
                'Say why in at least 10 characters.',
            ),
        ).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();

        fireEvent.change(within(dialog).getByLabelText(/Why turn it off/), {
            target: { value: 'Jordan has left the team' },
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Turn off rule' }),
        );
        await waitFor(() =>
            expect(post).toHaveBeenCalledWith(
                '/security-devices/settings/monitoring/alert-routes/5/deactivate',
                { version: 3, reason: 'Jordan has left the team' },
                expect.anything(),
            ),
        );
    });
});
