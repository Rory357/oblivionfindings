import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { HubEventDialog } from './_dialogs';
import { HubEventReader } from './_event-reader';
import type { HubEvent } from './_types';

const { request, reload, visit, post, page } = vi.hoisted(() => ({
    request: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    post: vi.fn(),
    page: { props: { auth: { user: { id: 11 } } } },
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    router: { reload, visit, post },
    usePage: () => page,
    useForm: vi.fn(),
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));
vi.mock('@/components/hr/calendar/event-wizard-dialog', () => ({
    EventWizardDialog: () => null,
}));

const event: HubEvent = {
    id: 'event-2-2099-10-15',
    title: 'An afternoon together',
    start: '2099-10-15T10:00:00+13:00',
    end: '2099-10-15T11:00:00+13:00',
    allDay: false,
    editable: true,
    extendedProps: {
        eventId: 2,
        editVersion: 4,
        location: 'Company garden',
        description: 'A little time to reconnect.',
        createdBy: 'People & culture',
        myRsvp: 'yes',
        recurring: true,
        site: null,
        recurrenceParentId: null,
        occurrenceDate: '2099-10-15',
        audienceType: 'people',
        attendeeUserIds: [11],
        cancellation: {
            cancelled: false,
            cancelled_at: null,
            reason: null,
            actor_id: 11,
            can_cancel: true,
            scopes: ['this', 'following', 'all'],
        },
        registration: {
            enabled: true,
            capacity: 20,
            version: 2,
            going: 10,
            waiting: 0,
            mine: 'going',
            date: '2099-10-15',
        },
    },
};
const cancelled: HubEvent = {
    ...event,
    id: 'event-7-2099-10-15',
    extendedProps: {
        ...event.extendedProps,
        eventId: 7,
        recurrenceParentId: 2,
        editVersion: 5,
        cancellation: {
            ...event.extendedProps.cancellation!,
            cancelled: true,
            cancelled_at: '2099-10-14T09:00:00Z',
            can_cancel: false,
            reason: 'The venue is unavailable.',
            scopes: [],
        },
        registration: {
            ...event.extendedProps.registration!,
            cancelled: true,
            can_respond: false,
            place_reserved: false,
        },
    },
};
beforeEach(() => {
    vi.clearAllMocks();
    page.props.auth.user.id = 11;
});

it('shows sample registration independently of a roster while hiding live editing and calendar actions', () => {
    render(
        <HubEventReader
            event={event}
            events={[event]}
            bookmarks={[]}
            preview
            sample
            onSelect={vi.fn()}
            close={vi.fn()}
            returnLabel="Back to events"
        />,
    );
    expect(
        screen.getByRole('button', { name: 'Cancel my place' }),
    ).toBeDisabled();
    expect(
        screen.queryByRole('button', { name: 'Edit event details' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: /calendar/i }),
    ).not.toBeInTheDocument();
    expect(request).not.toHaveBeenCalled();
});

it('presents cancellation without a confirmed place, reminders or editable event actions', () => {
    render(
        <HubEventDialog
            event={cancelled}
            close={vi.fn()}
            events={[cancelled]}
        />,
    );
    expect(
        screen.getByRole('complementary', { name: 'Event invitation' }),
    ).toBeVisible();
    expect(
        screen.getByRole('region', { name: 'Event cancellation' }),
    ).toHaveTextContent('The venue is unavailable.');
    expect(
        screen.getByRole('region', { name: 'Previous registration' }),
    ).toHaveTextContent('You were registered');
    expect(
        screen.queryByText('Your place is confirmed for this date.'),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Cancel my place' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'I’m going' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Edit event details' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByLabelText('Calendar reminder'),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: 'Download cancelled date' }),
    ).toHaveAttribute('href', '/company-hub/events/7/calendar?date=2099-10-15');
    expect(request).not.toHaveBeenCalled();
});

it('keeps cancellation inline, blocks dismissal while saving and switches to the canonical cancelled occurrence', async () => {
    let finish!: (value: unknown) => void;
    request.mockReturnValue(
        new Promise((resolve) => {
            finish = resolve;
        }),
    );
    const onSelect = vi.fn();
    const close = vi.fn();
    render(
        <HubEventDialog
            event={event}
            close={close}
            events={[event]}
            onSelect={onSelect}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel event' }));
    expect(screen.getAllByRole('dialog')).toHaveLength(1);
    fireEvent.keyDown(
        screen.getByRole('heading', { name: 'Cancel this event?' }),
        { key: 'Escape' },
    );
    expect(close).not.toHaveBeenCalled();
    expect(
        screen.queryByRole('heading', { name: 'Cancel this event?' }),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel event' }));
    fireEvent.click(screen.getByRole('radio', { name: /This event only/ }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Confirm cancellation' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(close).not.toHaveBeenCalled();
    await act(async () =>
        finish({
            actor_id: 11,
            event_id: 7,
            edit_version: 5,
            date: '2099-10-15',
            scope: 'this',
            cancellation: cancelled.extendedProps.cancellation,
            registration: cancelled.extendedProps.registration,
        }),
    );
    expect(
        screen.queryByText('Your place is confirmed for this date.'),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('region', { name: 'Event cancellation' }),
    ).toBeVisible();
    const callbacks = reload.mock.calls[0][0];
    act(() =>
        callbacks.onSuccess({
            props: { auth: page.props.auth, events: [cancelled] },
        }),
    );
    expect(onSelect).toHaveBeenCalledWith(cancelled);
});

it('allows a read-only refresh after saved cancellation without sending the cancellation again', async () => {
    request.mockResolvedValue({
        actor_id: 11,
        event_id: 7,
        edit_version: 5,
        date: '2099-10-15',
        scope: 'this',
        cancellation: cancelled.extendedProps.cancellation,
        registration: cancelled.extendedProps.registration,
    });
    render(
        <HubEventDialog
            event={event}
            close={vi.fn()}
            events={[event]}
            onSelect={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel event' }));
    fireEvent.click(screen.getByRole('radio', { name: /This event only/ }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Confirm cancellation' }),
    );
    await waitFor(() => expect(reload).toHaveBeenCalledTimes(1));
    act(() => reload.mock.calls[0][0].onFinish());
    fireEvent.click(screen.getByRole('button', { name: 'Refresh event' }));
    expect(reload).toHaveBeenCalledTimes(2);
    expect(request).toHaveBeenCalledTimes(1);
});

it('refreshes a split cancellation through its new canonical page even outside the upcoming feed', async () => {
    request.mockResolvedValue({
        actor_id: 11,
        event_id: 7,
        edit_version: 5,
        date: '2099-10-15',
        scope: 'following',
        cancellation: cancelled.extendedProps.cancellation,
        registration: cancelled.extendedProps.registration,
    });
    const onSelect = vi.fn();
    render(
        <HubEventReader
            event={event}
            events={[]}
            bookmarks={[]}
            onSelect={onSelect}
            close={vi.fn()}
            returnLabel="Back to events"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel event' }));
    fireEvent.click(screen.getByRole('radio', { name: /This and following/ }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Confirm cancellation' }),
    );
    await waitFor(() =>
        expect(visit).toHaveBeenCalledWith(
            '/company-hub/events/7?date=2099-10-15',
            expect.objectContaining({
                only: ['events', 'selectedEvent'],
                preserveState: true,
            }),
        ),
    );
    expect(reload).not.toHaveBeenCalled();
    const callbacks = visit.mock.calls[0][1];
    act(() => {
        callbacks.onSuccess({
            props: {
                auth: page.props.auth,
                events: [],
                selectedEvent: {
                    ...cancelled,
                    extendedProps: {
                        ...cancelled.extendedProps,
                        recurrenceParentId: null,
                    },
                },
            },
        });
        callbacks.onFinish();
    });
    expect(
        screen.queryByRole('button', { name: 'Refresh event' }),
    ).not.toBeInTheDocument();
    expect(onSelect).not.toHaveBeenCalled();
    expect(request).toHaveBeenCalledTimes(1);
});

it('labels cancelled dates in the selector without showing an active reservation', () => {
    const onSelect = vi.fn();
    const next = {
        ...cancelled,
        id: 'cancelled-next',
        start: '2099-10-22T10:00:00+13:00',
    };
    render(
        <HubEventDialog
            event={event}
            close={vi.fn()}
            events={[event, next]}
            onSelect={onSelect}
        />,
    );
    const select = screen.getByRole('combobox', { name: 'Choose a date' });
    fireEvent.keyDown(select, { key: 'ArrowDown' });
    const option = screen.getByRole('option', { name: /Cancelled/ });
    expect(option).not.toHaveTextContent('You’re going');
    fireEvent.click(option);
    expect(onSelect).toHaveBeenCalledWith(next);
    expect(
        screen.getByRole('progressbar', { name: 'Event places filled' }),
    ).toHaveAttribute('aria-valuenow', '10');
    fireEvent.keyDown(
        screen.getByRole('combobox', { name: 'Calendar reminder' }),
        { key: 'ArrowDown' },
    );
    fireEvent.click(screen.getByRole('option', { name: 'None' }));
    expect(
        screen.getByRole('link', { name: 'Add this date to my calendar' }),
    ).toHaveAttribute('href', '/company-hub/events/2/calendar?date=2099-10-15');
});
