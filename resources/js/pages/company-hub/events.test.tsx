import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EventWorkspace } from './_event-workspace';
import { EventBoard, EventRegistration, eventInitial } from './_events';
import type { HubEvent, HubProps } from './_types';

const { request, reload, actor } = vi.hoisted(() => ({
    request: vi.fn(),
    reload: vi.fn(),
    actor: { id: 1 },
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    router: { reload, visit: vi.fn() },
    usePage: () => ({ props: { auth: { user: { id: actor.id } } } }),
}));
vi.mock('@/components/hr/calendar/event-wizard-dialog', () => ({
    EventWizardDialog: ({
        initial,
    }: {
        initial?: { scope: string; starts_at: string };
    }) => (
        <p>
            Editing {initial?.scope} from {initial?.starts_at}
        </p>
    ),
}));
const event: HubEvent = {
    id: 'event-2-2099-10-15',
    title: 'Team gathering',
    start: '2099-10-15T10:00:00+13:00',
    end: '2099-10-15T11:00:00+13:00',
    allDay: false,
    editable: false,
    extendedProps: {
        eventId: 2,
        editVersion: 1,
        location: 'Main room',
        description: 'Time together',
        createdBy: 'Company team',
        myRsvp: null,
        recurring: true,
        site: null,
        recurrenceParentId: null,
        occurrenceDate: '2099-10-15',
        category: 'social',
        registration: {
            enabled: true,
            capacity: 1,
            version: 2,
            going: 1,
            waiting: 0,
            mine: null,
            date: '2099-10-15',
        },
    },
};
describe('Company Hub events', () => {
    it('presents full sample registration with disabled actions and no request', () => {
        render(<EventRegistration event={event} readOnly />);
        const join = screen.getByRole('button', { name: 'Join waiting list' });
        expect(join).toBeDisabled();
        fireEvent.click(join);
        expect(request).not.toHaveBeenCalled();
    });
    beforeEach(() => {
        vi.clearAllMocks();
        actor.id = 1;
    });
    const personal = {
        ...event.extendedProps.registration!,
        mine: 'going',
        personal_reminder: {
            supported: true,
            enabled: false,
            version: 0,
            can_enable: true,
            can_disable: false,
            due_at: '2099-10-14T10:00:00+13:00',
            inapp_allowed: true,
        },
    };
    it('refreshes notification delivery copy without losing unsaved event settings', () => {
        const shown = {
            ...event,
            editable: true,
            extendedProps: { ...event.extendedProps, registration: personal },
        };
        const { rerender } = render(<EventRegistration event={shown} />);
        fireEvent.change(screen.getByLabelText(/Places per date/), {
            target: { value: '7' },
        });
        rerender(
            <EventRegistration
                event={{
                    ...shown,
                    extendedProps: {
                        ...shown.extendedProps,
                        registration: {
                            ...personal,
                            personal_reminder: {
                                ...personal.personal_reminder,
                                inapp_allowed: false,
                            },
                        },
                    },
                }}
            />,
        );
        expect(
            screen.getByText(/In-app event notifications are off/),
        ).toBeInTheDocument();
        expect(screen.getByLabelText(/Places per date/)).toHaveValue(7);
        expect(
            screen.getByRole('checkbox', { name: 'Remind me the day before' }),
        ).not.toBeChecked();
        expect(request).not.toHaveBeenCalled();
    });
    it('keeps a newer notification projection when an earlier reminder acknowledgement arrives', async () => {
        let finish!: (value: unknown) => void;
        request.mockReturnValueOnce(
            new Promise((resolve) => {
                finish = resolve;
            }),
        );
        const shown = {
            ...event,
            extendedProps: { ...event.extendedProps, registration: personal },
        };
        const { rerender } = render(<EventRegistration event={shown} />);
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Remind me the day before' }),
        );
        rerender(
            <EventRegistration
                event={{
                    ...shown,
                    extendedProps: {
                        ...shown.extendedProps,
                        registration: {
                            ...personal,
                            personal_reminder: {
                                ...personal.personal_reminder,
                                inapp_allowed: false,
                            },
                        },
                    },
                }}
            />,
        );
        await act(async () =>
            finish({
                actor_id: 1,
                event_id: 2,
                edit_version: 1,
                date: personal.date,
                registration: {
                    ...personal,
                    personal_reminder: {
                        ...personal.personal_reminder,
                        enabled: true,
                        version: 1,
                    },
                },
            }),
        );
        expect(
            screen.getByRole('checkbox', { name: 'Remind me the day before' }),
        ).toBeChecked();
        expect(
            screen.getByText(/In-app event notifications are off/),
        ).toBeInTheDocument();
    });
    it('saves a personal reminder once and waits for the correct account, date and version acknowledgement', async () => {
        let finish!: (value: unknown) => void;
        request.mockReturnValueOnce(
            new Promise((resolve) => {
                finish = resolve;
            }),
        );
        render(
            <EventRegistration
                event={{
                    ...event,
                    extendedProps: {
                        ...event.extendedProps,
                        registration: personal,
                    },
                }}
            />,
        );
        const checkbox = screen.getByRole('checkbox', {
            name: 'Remind me the day before',
        });
        fireEvent.click(checkbox);
        fireEvent.click(checkbox);
        expect(checkbox).not.toBeChecked();
        expect(checkbox).toBeDisabled();
        expect(request).toHaveBeenCalledTimes(1);
        expect(request).toHaveBeenCalledWith(
            '/company-hub/events/2/reminder',
            'PUT',
            {
                actor_id: 1,
                edit_version: 1,
                date: '2099-10-15',
                reminder_version: 0,
                enabled: true,
            },
        );
        await act(async () =>
            finish({
                actor_id: 1,
                event_id: 2,
                edit_version: 1,
                date: '2099-10-15',
                registration: {
                    ...personal,
                    personal_reminder: {
                        ...personal.personal_reminder,
                        enabled: true,
                        version: 1,
                        can_disable: true,
                    },
                },
            }),
        );
        expect(checkbox).toBeChecked();
        expect(
            screen.getByText('Your day-before reminder is saved.'),
        ).toBeVisible();
    });
    it('preserves the reminder choice after an acknowledgement for a different account', async () => {
        request.mockResolvedValueOnce({
            actor_id: 2,
            event_id: 2,
            edit_version: 1,
            date: '2099-10-15',
            registration: {
                ...personal,
                personal_reminder: {
                    ...personal.personal_reminder,
                    enabled: true,
                    version: 1,
                },
            },
        });
        render(
            <EventRegistration
                event={{
                    ...event,
                    extendedProps: {
                        ...event.extendedProps,
                        registration: personal,
                    },
                }}
            />,
        );
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Remind me the day before' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'could not be confirmed',
        );
        expect(
            screen.getByRole('checkbox', { name: 'Remind me the day before' }),
        ).not.toBeChecked();
        expect(
            screen.queryByText('Your day-before reminder is saved.'),
        ).not.toBeInTheDocument();
    });
    it('ignores the old account’s reminder completion after the signed-in account changes', async () => {
        let finish!: (value: unknown) => void;
        request.mockReturnValueOnce(
            new Promise((resolve) => {
                finish = resolve;
            }),
        );
        const props = {
            ...event,
            extendedProps: { ...event.extendedProps, registration: personal },
        };
        const view = render(<EventRegistration event={props} />);
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Remind me the day before' }),
        );
        actor.id = 2;
        view.rerender(<EventRegistration event={props} />);
        await act(async () =>
            finish({
                actor_id: 1,
                event_id: 2,
                edit_version: 1,
                date: '2099-10-15',
                registration: {
                    ...personal,
                    personal_reminder: {
                        ...personal.personal_reminder,
                        enabled: true,
                        version: 1,
                    },
                },
            }),
        );
        expect(
            screen.getByRole('checkbox', { name: 'Remind me the day before' }),
        ).not.toBeChecked();
        expect(
            screen.queryByText('Your day-before reminder is saved.'),
        ).not.toBeInTheDocument();
    });
    it('closes new bookings at the cutoff while retaining cancellation of an existing place', () => {
        render(
            <EventRegistration
                event={{
                    ...event,
                    extendedProps: {
                        ...event.extendedProps,
                        registration: {
                            ...personal,
                            closes_at: '2020-10-14T10:00:00+13:00',
                            can_respond: false,
                            can_decline: true,
                        },
                    },
                }}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'I’m going' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Cancel my place' }),
        ).toBeEnabled();
    });
    it('keeps a reserved place when the confirmation is dismissed and preserves it after a failed cancellation', async () => {
        request.mockRejectedValue(new Error('Please try again.'));
        render(
            <EventRegistration
                event={{
                    ...event,
                    extendedProps: {
                        ...event.extendedProps,
                        registration: {
                            ...event.extendedProps.registration!,
                            mine: 'going',
                        },
                    },
                }}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel my place' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Keep my place' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Cancel my place' }),
            ).toHaveFocus(),
        );
        expect(request).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel my place' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm cancellation' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Please try again.',
        );
        expect(
            screen.getByText('Your place is confirmed for this date.'),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Keep my place' }),
        ).toBeEnabled();
    });
    it('shows cancelled cards as cancelled while retaining them in My events', () => {
        const cancelledEvent: HubEvent = {
            ...event,
            extendedProps: {
                ...event.extendedProps,
                cancellation: {
                    cancelled: true,
                    cancelled_at: '2099-10-14T09:00:00Z',
                    reason: null,
                    actor_id: 11,
                    can_cancel: false,
                    scopes: [],
                },
                registration: {
                    ...event.extendedProps.registration!,
                    mine: 'going',
                    cancelled: true,
                    place_reserved: false,
                },
            },
        };
        render(
            <EventBoard
                data={
                    {
                        events: [cancelledEvent],
                        capabilities: {},
                        bookmarks: [],
                    } as unknown as HubProps
                }
                onEvent={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'My events' }));
        expect(
            screen.getByRole('heading', { name: 'Team gathering' }),
        ).toBeVisible();
        expect(screen.getByText('Cancelled')).toBeVisible();
        expect(screen.queryByText('You’re going')).not.toBeInTheDocument();
    });
    it('uses an upcoming active date for a series card when an earlier date is cancelled', () => {
        const first: HubEvent = {
            ...event,
            extendedProps: {
                ...event.extendedProps,
                cancellation: {
                    cancelled: true,
                    cancelled_at: '2099-10-14T09:00:00Z',
                    reason: null,
                    actor_id: 11,
                    can_cancel: false,
                    scopes: [],
                },
            },
        };
        const next = {
            ...event,
            id: 'event-2-next',
            start: '2099-10-22T10:00:00+13:00',
        };
        const onEvent = vi.fn();
        render(
            <EventBoard
                data={
                    {
                        events: [first, next],
                        capabilities: {},
                        bookmarks: [],
                    } as unknown as HubProps
                }
                onEvent={onEvent}
            />,
        );
        expect(screen.queryByText('Cancelled')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /2 matching dates/ }),
        );
        expect(onEvent).toHaveBeenCalledWith(next);
    });
    it('does not replace registration with an acknowledgement for another date', async () => {
        request.mockResolvedValue({
            registration: {
                ...event.extendedProps.registration!,
                date: '2099-10-16',
                mine: 'going',
            },
        });
        render(<EventRegistration event={event} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Join waiting list' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'another date',
        );
        expect(
            screen.queryByText('Your place is confirmed for this date.'),
        ).not.toBeInTheDocument();
    });
    it('loads past dates separately and ignores an older year response arriving late', async () => {
        let finishOld!: (value: { events: HubEvent[] }) => void;
        request.mockReturnValueOnce(
            new Promise((resolve) => {
                finishOld = resolve;
            }),
        );
        request.mockResolvedValueOnce({
            events: [
                {
                    ...event,
                    title: 'Earlier gathering',
                    id: 'past-1',
                    end: '2025-10-15T11:00:00+13:00',
                },
            ],
        });
        render(
            <EventBoard
                data={
                    {
                        events: [event],
                        capabilities: {},
                        bookmarks: [],
                    } as unknown as HubProps
                }
                onEvent={() => {}}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Photos & recaps' }),
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'Loading past events',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Previous year' }));
        expect(
            await screen.findByRole('heading', { name: 'Earlier gathering' }),
        ).toBeInTheDocument();
        await act(async () =>
            finishOld({ events: [{ ...event, title: 'Stale result' }] }),
        );
        expect(
            screen.queryByRole('heading', { name: 'Stale result' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Earlier gathering' }),
        ).toBeInTheDocument();
        expect(request.mock.calls[0][0]).toMatch(/events\/history\?year=/);
    });
    it('offers a retry after history fails without presenting an empty success state', async () => {
        request
            .mockRejectedValueOnce(new Error('Could not load past events.'))
            .mockResolvedValueOnce({ events: [] });
        render(
            <EventBoard
                data={
                    {
                        events: [],
                        capabilities: {},
                        bookmarks: [],
                    } as unknown as HubProps
                }
                onEvent={() => {}}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Photos & recaps' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Could not load past events.',
        );
        expect(screen.queryByText(/No past events/)).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(
            await screen.findByText(/No past events match/),
        ).toBeInTheDocument();
    });
    it('lets an existing registrant cancel after the organiser closes registration', async () => {
        const registration = {
            ...event.extendedProps.registration!,
            enabled: false,
            mine: 'going',
        };
        request.mockResolvedValue({
            registration: { ...registration, mine: 'declined', going: 0 },
        });
        render(
            <EventRegistration
                event={{
                    ...event,
                    extendedProps: { ...event.extendedProps, registration },
                }}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'I’m going' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel my place' }),
        );
        expect(request).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm cancellation' }),
        );
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                '/company-hub/events/2/registration',
                'POST',
                { date: '2099-10-15', status: 'declined' },
            ),
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('button', { name: 'Cancel my place' }),
            ).not.toBeInTheDocument(),
        );
    });
    it('registers only for the displayed date and shows the server waiting-list result', async () => {
        request.mockResolvedValue({
            registration: {
                ...event.extendedProps.registration,
                mine: 'waiting',
                waiting: 1,
                queue_position: 2,
            },
        });
        render(<EventRegistration event={event} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Join waiting list' }),
        );
        await waitFor(() =>
            expect(
                screen.getByText(/You’re on the waiting list/),
            ).toBeInTheDocument(),
        );
        expect(request).toHaveBeenCalledWith(
            '/company-hub/events/2/registration',
            'POST',
            { date: '2099-10-15', status: 'going' },
        );
        expect(screen.getByText(/position 2/)).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Leave waiting list' }),
        ).toBeInTheDocument();
    });
    it('keeps the current response and shows a failed registration without a false success', async () => {
        request.mockRejectedValue(
            new Error('Registration is not open for this event.'),
        );
        render(<EventRegistration event={event} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Join waiting list' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Registration is not open',
        );
        expect(
            screen.queryByText('Your place is confirmed for this date.'),
        ).not.toBeInTheDocument();
    });
    it('keeps staff registration settings hidden and filters to their own events', () => {
        const other = {
            ...event,
            id: 'event-3',
            title: 'Workshop',
            extendedProps: {
                ...event.extendedProps,
                eventId: 3,
                registration: {
                    ...event.extendedProps.registration!,
                    mine: 'going',
                },
            },
        };
        const data = {
            events: [event, other],
            capabilities: {},
            bookmarks: [],
        } as unknown as HubProps;
        render(<EventBoard data={data} onEvent={() => {}} />);
        fireEvent.click(screen.getByRole('button', { name: 'My events' }));
        expect(
            screen.queryByRole('heading', { name: 'Team gathering' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Workshop' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Registration settings'),
        ).not.toBeInTheDocument();
    });
    it('opens a recurring date using the canonical single-occurrence edit scope', () => {
        expect(eventInitial(event)).toMatchObject({
            id: 2,
            scope: 'this',
            occurrence_date: '2099-10-15',
            starts_at: event.start,
        });
    });
    it('keeps recurring dates together instead of filling the board with duplicate cards', () => {
        const next = {
            ...event,
            id: 'event-2-next',
            start: '2099-10-22T10:00:00+13:00',
        };
        const open = vi.fn();
        render(
            <EventBoard
                data={
                    {
                        events: [next, event],
                        bookmarks: [],
                        capabilities: {},
                    } as unknown as HubProps
                }
                onEvent={open}
            />,
        );
        expect(
            screen.getAllByRole('heading', { name: 'Team gathering' }),
        ).toHaveLength(1);
        fireEvent.click(
            screen.getByRole('button', { name: /2 matching dates/ }),
        );
        expect(open).toHaveBeenCalledWith(event);
    });
    it('uses the original start for whole-series edits but keeps an exception’s own date', () => {
        const series = {
            ...event,
            extendedProps: {
                ...event.extendedProps,
                seriesStart: '2099-10-01T10:00:00+13:00',
                seriesEnd: '2099-10-01T11:00:00+13:00',
            },
        };
        expect(eventInitial(series, 'all').starts_at).toBe(
            series.extendedProps.seriesStart,
        );
        expect(
            eventInitial(
                {
                    ...series,
                    extendedProps: {
                        ...series.extendedProps,
                        recurrenceParentId: 1,
                    },
                },
                'all',
            ).starts_at,
        ).toBe(event.start);
    });
    it('requires an explicit scope choice and only offers date changes with recurring permission', async () => {
        request.mockResolvedValue({
            sites: [],
            departments: [],
            teams: [],
            staff: [],
            categories: [],
            can_manage_recurring: false,
        });
        render(
            <EventWorkspace mode="edit" event={{ ...event, editable: true }} />,
        );
        await screen.findByRole('heading', { name: 'Edit a recurring event' });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'This date only' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Whole series' }));
        expect(screen.getByText(/Editing all/)).toBeInTheDocument();
    });
    it('uses fresh server seat counts after an organiser increases capacity', async () => {
        request.mockResolvedValue({
            registration: {
                ...event.extendedProps.registration,
                capacity: 3,
                going: 3,
                waiting: 0,
                version: 3,
            },
        });
        render(<EventRegistration event={{ ...event, editable: true }} />);
        fireEvent.change(screen.getByLabelText(/Places per date/), {
            target: { value: '3' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save registration settings' }),
        );
        expect(await screen.findByText(/3 going/)).toHaveTextContent(
            '0 places left',
        );
        expect(request).toHaveBeenCalledWith(
            '/company-hub/events/2/registration',
            'PUT',
            { enabled: true, capacity: 3, version: 2, date: '2099-10-15' },
        );
    });
    it('locks settings while saving and does not claim an unchanged registration version was saved', async () => {
        let finish!: (value: unknown) => void;
        request.mockReturnValueOnce(
            new Promise((resolve) => {
                finish = resolve;
            }),
        );
        const registration = {
            ...event.extendedProps.registration!,
            cutoff_supported: true,
            closes_before_minutes: 120,
        };
        render(
            <EventRegistration
                event={{
                    ...event,
                    editable: true,
                    extendedProps: { ...event.extendedProps, registration },
                }}
            />,
        );
        fireEvent.click(screen.getByText('Registration settings'));
        fireEvent.change(screen.getByLabelText(/Places per date/), {
            target: { value: '3' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save registration settings' }),
        );
        expect(screen.getByLabelText('Open registration')).toBeDisabled();
        expect(screen.getByLabelText(/Places per date/)).toBeDisabled();
        expect(
            screen.getByLabelText('Minutes before the start'),
        ).toBeDisabled();
        expect(request).toHaveBeenCalledWith(
            '/company-hub/events/2/registration',
            'PUT',
            {
                enabled: true,
                capacity: 3,
                version: 2,
                date: '2099-10-15',
                actor_id: 1,
                edit_version: 1,
                closes_before_minutes: 120,
            },
        );
        await act(async () =>
            finish({ registration: { ...registration, capacity: 3 } }),
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'settings could not be confirmed',
        );
        expect(
            screen.queryByText('Registration settings saved.'),
        ).not.toBeInTheDocument();
        expect(screen.getByLabelText(/Places per date/)).toHaveValue(3);
        expect(screen.getByLabelText(/Places per date/)).toBeEnabled();
    });
});
