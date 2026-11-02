import type { MyDayClockin } from '@/lib/security-devices/door-clockins';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DoorClockinBand, DoorClockinNotice } from './door-clockin-band';

const inertia = vi.hoisted(() => ({ reload: vi.fn(), visit: vi.fn() }));
const http = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, usePage: () => ({ props: { auth: { user: { id: 7, name: 'Sione Fifita' } } } }), router: { ...actual.router, ...inertia } };
});
vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return { default: { ...actual.default, post: http.post, isAxiosError: actual.default.isAxiosError } };
});

// Thu 29 Oct 2026, NZ daylight time (UTC+13): badge 6:52 am, shift 7:00 am – 3:00 pm.
const clockin: MyDayClockin = {
    id: 41,
    site: 'Kauri House',
    door: 'Front door',
    place: 'Kauri House front door',
    method: 'card',
    badge_at: '2026-10-28T17:52:00Z',
    shift: { starts_at: '2026-10-28T18:00:00Z', ends_at: '2026-10-29T02:00:00Z' },
    badge_time: true,
    notice: {
        body: 'From Mon 19 Oct, when you badge in at the front or back door at Kauri House, near the start of a rostered shift, Oblivion will suggest clocking you in at that time. Questions: Ana Ruha, operations manager.',
        sent_at: '2026-10-11T20:00:00Z',
        by: 'Ana Ruha',
        by_email: true,
    },
};

describe('Clock-in suggestion on My Day (pg-sdr-19g, pop-up 88)', () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
        window.history.replaceState(null, '', '/');
    });

    it('asks in one band, with the three choices and what is used', () => {
        render(<DoorClockinBand data={clockin} />);
        const band = screen.getByRole('region', { name: 'Clock-in suggestion' });

        expect(within(band).getByText('From your badge · 6:52 am')).toBeInTheDocument();
        expect(within(band).getByRole('heading', { name: 'You badged in at Kauri House front door at 6:52 am. Clock in for your 7:00 am shift?' })).toBeInTheDocument();
        expect(within(band).getByText(/Nothing is clocked in until you choose/)).toBeInTheDocument();
        expect(within(band).getByText('Uses the door, the time and your card. Never your face or fingerprint.')).toBeInTheDocument();
        expect(within(band).getAllByRole('button').map((button) => button.textContent)).toEqual(['I’m not working', 'Clock in now instead', 'Clock in at 6:52 am']);
    });

    it('clocks in at the badge time only when the person chooses it', async () => {
        http.post.mockResolvedValueOnce({ data: { answer: 'badge' } });
        render(<DoorClockinBand data={clockin} />);
        expect(http.post).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Clock in at 6:52 am' }));
        await waitFor(() => expect(http.post).toHaveBeenCalledWith('/door-clockins/41/answer', { choice: 'badge' }));
        await waitFor(() => expect(inertia.reload).toHaveBeenCalled());
    });

    it('records "I’m not working" and shows attendance’s own refusal word for word', async () => {
        http.post.mockRejectedValueOnce(
            Object.assign(new Error('422'), { isAxiosError: true, response: { status: 422, data: { errors: { choice: ['You are already clocked in. Please clock out before starting another session.'] } } } }),
        );
        render(<DoorClockinBand data={clockin} />);
        fireEvent.click(screen.getByRole('button', { name: 'Clock in now instead' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('You are already clocked in. Please clock out before starting another session.');
        expect(http.post).toHaveBeenCalledWith('/door-clockins/41/answer', { choice: 'now' });

        http.post.mockResolvedValueOnce({ data: { answer: 'not_working' } });
        fireEvent.click(screen.getByRole('button', { name: 'I’m not working' }));
        await waitFor(() => expect(http.post).toHaveBeenLastCalledWith('/door-clockins/41/answer', { choice: 'not_working' }));
    });

    it('offers only "Clock in now" when Setup turned the badge time off', () => {
        render(<DoorClockinBand data={{ ...clockin, badge_time: false }} />);
        const band = screen.getByRole('region', { name: 'Clock-in suggestion' });

        expect(within(band).getAllByRole('button').map((button) => button.textContent)).toEqual(['I’m not working', 'Clock in now']);
    });

    it('opens pop-up 88 from the app notice, Cancel first as "Later", the main button last', async () => {
        window.history.replaceState(null, '', '/my-day?door_clockin=41');
        render(<DoorClockinBand data={clockin} />);

        const popup = await screen.findByRole('dialog', { name: 'Clock in for your 7:00 am shift?' });
        expect(within(popup).getByText('You badged in at Kauri House front door at 6:52 am.')).toBeInTheDocument();
        expect(within(popup).getByText('Sione Fifita')).toBeInTheDocument();
        expect(within(popup).getByText('Rostered today 7:00 am – 3:00 pm')).toBeInTheDocument();
        expect(within(popup).getByText('Front door, 6:52 am · card')).toBeInTheDocument();
        expect(within(popup).getByText('7:00 am – 3:00 pm · Kauri House')).toBeInTheDocument();
        expect(within(popup).getByText(/Faces and fingerprints are never used/)).toBeInTheDocument();

        const footer = popup.querySelector('[data-slot="popup-footer"]') as HTMLElement;
        expect(within(footer).getAllByRole('button').map((button) => button.textContent)).toEqual(['I’m not working', 'Later', 'Clock in now instead', 'Clock in at 6:52 am']);
        expect(window.location.search).toBe('');

        http.post.mockResolvedValueOnce({ data: { answer: 'badge' } });
        fireEvent.click(within(footer).getByRole('button', { name: 'Clock in at 6:52 am' }));
        await waitFor(() => expect(http.post).toHaveBeenCalledWith('/door-clockins/41/answer', { choice: 'badge' }));
    });

    it('keeps the notice staff were told in reach, word for word', () => {
        render(<DoorClockinNotice data={clockin} />);

        expect(screen.getByText('What you were told')).toBeInTheDocument();
        expect(screen.getByText('Sent Mon 12 Oct 2026 by Ana Ruha, in the app and by email')).toBeInTheDocument();
        expect(screen.getByText(clockin.notice!.body)).toBeInTheDocument();
    });
});
