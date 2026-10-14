import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    EventBoard,
    EventEditorButton,
    EventRegistration,
    eventInitial,
} from './_events';
import type { HubEvent, HubProps } from './_types';

const { request, reload } = vi.hoisted(() => ({
    request: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({ router: { reload } }));
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
    beforeEach(() => vi.clearAllMocks());
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
            screen.getByRole('button', { name: 'Cancel my place' }),
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
        render(<EventEditorButton event={event} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit event details' }),
        );
        expect(await screen.findByRole('dialog')).toBeInTheDocument();
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
});
