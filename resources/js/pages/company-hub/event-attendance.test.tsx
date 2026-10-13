import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EventAttendance } from './_event-attendance';
import type { HubEvent } from './_types';
const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({ router: { reload: vi.fn() } }));
const event = {
    id: 'event-4',
    editable: true,
    extendedProps: { eventId: 4, registration: { date: '2026-10-13' } },
} as HubEvent;
const roster = {
    can_record: true,
    registrations: [
        {
            id: 9,
            name: 'Alex Staff',
            status: 'going',
            attendance: null,
            version: 2,
        },
        {
            id: 10,
            name: 'Robin Staff',
            status: 'waiting',
            attendance: null,
            version: 1,
        },
    ],
};
describe('Company Hub attendance', () => {
    beforeEach(() => vi.clearAllMocks());
    it('does not request or expose the roster to staff', () => {
        render(<EventAttendance event={{ ...event, editable: false }} />);
        expect(
            screen.queryByText('People & attendance'),
        ).not.toBeInTheDocument();
        expect(request).not.toHaveBeenCalled();
    });
    it('records attendance for a confirmed place and supports versioned undo', async () => {
        request
            .mockResolvedValueOnce(roster)
            .mockResolvedValueOnce({
                ...roster,
                registrations: [
                    {
                        ...roster.registrations[0],
                        attendance: 'attended',
                        version: 3,
                    },
                ],
            })
            .mockResolvedValueOnce(roster);
        render(<EventAttendance event={event} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Manage attendance' }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Mark Alex Staff attended',
            }),
        );
        expect(
            screen.queryByRole('button', { name: 'Mark Robin Staff attended' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Clear attendance for Alex Staff',
            }),
        );
        await waitFor(() =>
            expect(request).toHaveBeenLastCalledWith(
                '/company-hub/events/4/attendance',
                'PUT',
                {
                    date: '2026-10-13',
                    registration_id: 9,
                    attendance: null,
                    version: 3,
                },
            ),
        );
        expect(
            await screen.findByText('Alex Staff: attendance cleared.'),
        ).toBeInTheDocument();
    });
    it('preserves the roster on conflict and offers an explicit refresh', async () => {
        request
            .mockResolvedValueOnce(roster)
            .mockRejectedValueOnce(new Error('This registration changed.'))
            .mockResolvedValueOnce({ ...roster, can_record: false });
        render(<EventAttendance event={event} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Manage attendance' }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Mark Alex Staff attended',
            }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'This registration changed',
        );
        expect(
            screen.getByText('Place confirmed · attendance not recorded'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Refresh list' }));
        await waitFor(() =>
            expect(
                screen.queryByRole('button', {
                    name: 'Mark Alex Staff attended',
                }),
            ).not.toBeInTheDocument(),
        );
    });
    it('does not let a response from an unmounted date update the next one', async () => {
        let resolve!: (value: typeof roster) => void;
        request
            .mockReturnValueOnce(
                new Promise((done) => {
                    resolve = done;
                }),
            )
            .mockResolvedValueOnce({ can_record: false, registrations: [] });
        const view = render(<EventAttendance key="first" event={event} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Manage attendance' }),
        );
        view.rerender(
            <EventAttendance
                key="next"
                event={{
                    ...event,
                    id: 'event-5',
                    extendedProps: { ...event.extendedProps, eventId: 5 },
                }}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Manage attendance' }),
        );
        expect(
            await screen.findByText('No registrations for this date.'),
        ).toBeInTheDocument();
        resolve(roster);
        await waitFor(() =>
            expect(screen.queryByText('Alex Staff')).not.toBeInTheDocument(),
        );
    });
});
