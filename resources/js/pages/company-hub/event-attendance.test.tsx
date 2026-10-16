import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EventAttendance } from './_event-attendance';
import type { HubEvent } from './_types';
const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    usePage: () => ({ props: { auth: { user: { id: 1 } } } }),
}));
const event = {
    id: 'event-4',
    editable: true,
    extendedProps: { eventId: 4, registration: { date: '2026-10-13' } },
} as HubEvent;
const roster = {
    actor_id: 1,
    event_id: 4,
    edit_version: 3,
    registration_version: 2,
    date: '2026-10-13',
    can_export: true,
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
    it('browses a sample roster without loading or exposing live commands even with permissive fixture flags', () => {
        render(
            <EventAttendance
                event={{ ...event, editable: false }}
                sampleRoster={roster}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'View sample attendance' }),
        );
        expect(screen.getByText('Alex Staff')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Refresh list' }),
        ).toBeDisabled();
        expect(
            screen.queryByRole('link', { name: 'Export attendance' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Mark .* attended/ }),
        ).not.toBeInTheDocument();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Find a registrant' }),
            { target: { value: 'Robin' } },
        );
        expect(screen.getByText('Robin Staff')).toBeVisible();
        expect(screen.queryByText('Alex Staff')).not.toBeInTheDocument();
        expect(request).not.toHaveBeenCalled();
    });
    beforeEach(() => vi.resetAllMocks());
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
            .mockResolvedValueOnce({
                ...roster,
                registrations: [{ ...roster.registrations[0], version: 4 }],
            });
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
    it('does not announce attendance saved when the returned person and version are unchanged', async () => {
        request.mockResolvedValueOnce(roster).mockResolvedValueOnce(roster);
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
            'attendance change could not be confirmed',
        );
        expect(
            screen.queryByText('Alex Staff: marked attended.'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Place confirmed · attendance not recorded'),
        ).toBeVisible();
    });
    it('does not let a response from an unmounted date update the next one', async () => {
        let resolve!: (value: typeof roster) => void;
        request
            .mockReturnValueOnce(
                new Promise((done) => {
                    resolve = done;
                }),
            )
            .mockResolvedValueOnce({
                ...roster,
                event_id: 5,
                can_record: false,
                registrations: [],
            });
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
    it('exports the displayed date and confirms an eligible waiting-list place only after explicit confirmation', async () => {
        const waiting = { ...roster.registrations[1], can_offer_place: true };
        request
            .mockResolvedValueOnce({
                ...roster,
                can_record: false,
                registrations: [waiting],
            })
            .mockResolvedValueOnce({
                roster: {
                    ...roster,
                    registrations: [
                        {
                            ...waiting,
                            status: 'going',
                            version: 2,
                            can_offer_place: false,
                        },
                    ],
                },
            });
        render(<EventAttendance event={event} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Manage attendance' }),
        );
        const offer = await screen.findByRole('button', {
            name: 'Offer place to Robin Staff',
        });
        expect(
            screen.getByRole('link', { name: 'Export attendance' }),
        ).toHaveAttribute(
            'href',
            '/company-hub/events/4/attendance/export?date=2026-10-13',
        );
        fireEvent.click(offer);
        expect(request).toHaveBeenCalledTimes(1);
        fireEvent.click(
            screen.getByRole('button', { name: 'Keep on waiting list' }),
        );
        expect(
            screen.queryByRole('button', { name: 'Confirm place' }),
        ).not.toBeInTheDocument();
        fireEvent.click(offer);
        fireEvent.click(screen.getByRole('button', { name: 'Confirm place' }));
        expect(
            await screen.findByText('Robin Staff now has a confirmed place.'),
        ).toBeVisible();
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/events/4/offer-place',
            'POST',
            {
                actor_id: 1,
                edit_version: 3,
                registration_version: 2,
                date: '2026-10-13',
                registration_id: 10,
                version: 1,
            },
        );
        expect(
            screen.queryByRole('button', {
                name: 'Offer place to Robin Staff',
            }),
        ).not.toBeInTheDocument();
    });
    it('does not display another account’s roster or offer its export', async () => {
        request.mockResolvedValueOnce({ ...roster, actor_id: 2 });
        render(<EventAttendance event={event} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Manage attendance' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'could not be confirmed',
        );
        expect(screen.queryByText('Alex Staff')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Export attendance' }),
        ).not.toBeInTheDocument();
    });
    it('keeps a person waiting after an unsuccessful offer and requires a refreshed list', async () => {
        const waiting = { ...roster.registrations[1], can_offer_place: true };
        request
            .mockResolvedValueOnce({
                ...roster,
                can_record: false,
                registrations: [waiting],
            })
            .mockRejectedValueOnce(
                new Error('The last place was already filled.'),
            )
            .mockResolvedValueOnce({
                ...roster,
                can_record: false,
                registrations: [{ ...waiting, can_offer_place: false }],
            });
        render(<EventAttendance event={event} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Manage attendance' }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Offer place to Robin Staff',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Confirm place' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'last place was already filled',
        );
        expect(screen.getByText('Waiting list')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Confirm place' }),
        ).toBeDisabled();
        expect(
            screen.queryByText('Robin Staff now has a confirmed place.'),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Refresh list' }));
        await waitFor(() =>
            expect(
                screen.queryByRole('button', { name: 'Confirm place' }),
            ).not.toBeInTheDocument(),
        );
    });
});
