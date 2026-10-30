import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import {
    EventCancellation,
    cancellationEvent,
    type EventCancellationResult,
    type EventCancellationState,
} from './_event-cancellation';
import type { HubEvent } from './_types';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('./_api', () => ({ hubRequest: request }));

const cancellation: EventCancellationState = {
    cancelled: false,
    cancelled_at: null,
    reason: null,
    actor_id: 11,
    can_cancel: true,
    scopes: ['this', 'all', 'following'],
};
const event = {
    id: 'event-2-2099-10-15',
    title: 'Team gathering',
    start: '2099-10-15T10:00:00+13:00',
    end: '2099-10-15T11:00:00+13:00',
    allDay: false,
    editable: true,
    extendedProps: {
        eventId: 2,
        editVersion: 4,
        location: 'Main room',
        description: 'Time together',
        createdBy: 'Company team',
        myRsvp: null,
        recurring: true,
        site: null,
        recurrenceParentId: null,
        occurrenceDate: '2099-10-15',
        cancellation,
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
} satisfies HubEvent & {
    extendedProps: HubEvent['extendedProps'] & {
        cancellation: EventCancellationState;
    };
};
const confirmed: EventCancellationResult = {
    actor_id: 11,
    event_id: 7,
    edit_version: 5,
    date: '2099-10-15',
    scope: 'this',
    cancellation: {
        ...cancellation,
        cancelled: true,
        cancelled_at: '2099-10-14T09:00:00Z',
        can_cancel: false,
        scopes: [],
    },
    registration: {
        ...event.extendedProps.registration,
        cancelled: true,
        can_respond: false,
        place_reserved: false,
    },
};
beforeEach(() => {
    request.mockReset();
});

it('requires an explicit recurring scope and confirmation, then prevents duplicate commands', async () => {
    let finish!: (value: EventCancellationResult) => void;
    request.mockReturnValue(
        new Promise((resolve) => {
            finish = resolve;
        }),
    );
    const onSaved = vi.fn();
    const onBusyChange = vi.fn();
    render(
        <EventCancellation
            event={event}
            actorId={11}
            onSaved={onSaved}
            onBusyChange={onBusyChange}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel event' }));
    expect(
        screen.getByRole('heading', { name: 'Cancel this event?' }),
    ).toHaveFocus();
    const confirm = screen.getByRole('button', {
        name: 'Confirm cancellation',
    });
    expect(confirm).toBeDisabled();
    fireEvent.click(screen.getByRole('radio', { name: /This event only/ }));
    fireEvent.change(
        screen.getByLabelText('A note for colleagues · optional'),
        { target: { value: '  The venue is unavailable.  ' } },
    );
    fireEvent.click(confirm);
    fireEvent.click(screen.getByRole('button', { name: 'Cancelling…' }));
    expect(request).toHaveBeenCalledTimes(1);
    expect(request).toHaveBeenCalledWith(
        '/company-hub/events/2/cancellation',
        'PUT',
        {
            actor_id: 11,
            edit_version: 4,
            date: '2099-10-15',
            scope: 'this',
            reason: 'The venue is unavailable.',
        },
    );
    expect(screen.getByRole('button', { name: 'Keep event' })).toBeDisabled();
    expect(onSaved).not.toHaveBeenCalled();
    await act(async () => finish(confirmed));
    expect(onSaved).toHaveBeenCalledWith(confirmed);
    expect(onBusyChange).toHaveBeenLastCalledWith(false);
});

it('lets the editor keep the event and returns keyboard focus without sending', async () => {
    render(
        <EventCancellation
            event={event}
            actorId={11}
            onSaved={vi.fn()}
            onBusyChange={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel event' }));
    fireEvent.keyDown(
        screen.getByRole('heading', { name: 'Cancel this event?' }),
        { key: 'Escape' },
    );
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Cancel event' }),
        ).toHaveFocus(),
    );
    expect(request).not.toHaveBeenCalled();
});

it('offers only server-permitted scopes and requires the same current account', () => {
    const limited = {
        ...event,
        extendedProps: {
            ...event.extendedProps,
            cancellation: {
                ...cancellation,
                scopes: ['this'],
            } as EventCancellationState,
        },
    };
    const { rerender } = render(
        <EventCancellation
            event={limited}
            actorId={11}
            onSaved={vi.fn()}
            onBusyChange={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel event' }));
    expect(screen.queryByRole('radio')).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Confirm cancellation' }),
    ).toBeEnabled();
    rerender(
        <EventCancellation
            event={event}
            actorId={12}
            onSaved={vi.fn()}
            onBusyChange={vi.fn()}
        />,
    );
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    rerender(
        <EventCancellation
            event={{
                ...event,
                extendedProps: {
                    ...event.extendedProps,
                    cancellation: { ...cancellation, can_cancel: false },
                },
            }}
            actorId={11}
            onSaved={vi.fn()}
            onBusyChange={vi.fn()}
        />,
    );
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
});

it('retains the explanation after a failed cancellation and rejects a mismatched acknowledgement', async () => {
    request.mockRejectedValueOnce(new Error('This event changed. Reopen it.'));
    request.mockResolvedValueOnce({ ...confirmed, date: '2099-10-16' });
    const onSaved = vi.fn();
    render(
        <EventCancellation
            event={event}
            actorId={11}
            onSaved={onSaved}
            onBusyChange={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel event' }));
    fireEvent.click(screen.getByRole('radio', { name: /This event only/ }));
    fireEvent.change(
        screen.getByLabelText('A note for colleagues · optional'),
        { target: { value: 'Venue change' } },
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Confirm cancellation' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'This event changed',
    );
    expect(
        screen.getByLabelText('A note for colleagues · optional'),
    ).toHaveValue('Venue change');
    fireEvent.click(
        screen.getByRole('button', { name: 'Confirm cancellation' }),
    );
    await waitFor(() =>
        expect(screen.getByRole('alert')).toHaveTextContent(
            'could not be confirmed',
        ),
    );
    expect(onSaved).not.toHaveBeenCalled();
});

it('drops an old acknowledgement after the current account changes', async () => {
    let finish!: (value: EventCancellationResult) => void;
    request.mockReturnValue(
        new Promise((resolve) => {
            finish = resolve;
        }),
    );
    const onSaved = vi.fn();
    const onBusyChange = vi.fn();
    const { rerender } = render(
        <EventCancellation
            event={event}
            actorId={11}
            onSaved={onSaved}
            onBusyChange={onBusyChange}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel event' }));
    fireEvent.click(screen.getByRole('radio', { name: /This event only/ }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Confirm cancellation' }),
    );
    rerender(
        <EventCancellation
            event={event}
            actorId={12}
            onSaved={onSaved}
            onBusyChange={onBusyChange}
        />,
    );
    await act(async () => finish(confirmed));
    expect(onSaved).not.toHaveBeenCalled();
});

it('finds the refreshed occurrence after a following split, including an existing exception', () => {
    const future = {
        ...event,
        id: 'later-date',
        extendedProps: {
            ...event.extendedProps,
            eventId: 7,
            occurrenceDate: '2099-10-22',
        },
    };
    const oldRoot = { ...event, id: 'old-root' };
    const exception = {
        ...event,
        id: 'existing-exception',
        extendedProps: {
            ...event.extendedProps,
            eventId: 19,
            recurrenceParentId: 7,
        },
    };
    expect(
        cancellationEvent([future, oldRoot, exception], {
            ...confirmed,
            scope: 'following',
        }),
    ).toBe(exception);
    expect(cancellationEvent([oldRoot, future], confirmed)).toBeUndefined();
});
