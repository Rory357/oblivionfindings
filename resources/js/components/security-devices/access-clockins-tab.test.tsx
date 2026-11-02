import type { AccessClockinsData, ClockinPanelData, ClockinRow } from '@/lib/security-devices/door-clockins';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AccessClockinsTab } from './access-clockins-tab';

const inertia = vi.hoisted(() => ({ reload: vi.fn(), visit: vi.fn() }));
const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, usePage: () => ({ props: { auth: { user: { id: 3 } } } }), router: { ...actual.router, ...inertia } };
});
vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return { default: { ...actual.default, get: http.get, post: http.post, isAxiosError: actual.default.isAxiosError } };
});

// Thu 29 Oct 2026, NZ daylight time (UTC+13).
const at = (nz: string) => new Date(`2026-10-29T${nz}:00+13:00`).toISOString();
const shift = { starts_at: at('07:00'), ends_at: at('15:00') };

function row(overrides: Partial<ClockinRow>): ClockinRow {
    return {
        id: 1,
        kind: 'rostered',
        person: { id: 11, name: 'Sione Fifita' },
        site: { id: 4, name: 'Kauri House' },
        day: '2026-10-29',
        badge: null,
        suggestion: null,
        shift,
        clock_in: null,
        apart_minutes: null,
        outcome: 'not_yet',
        tone: 'neutral',
        waiting_for_you: false,
        gap_checked_at: null,
        review: null,
        told: null,
        ...overrides,
    };
}

const hannah = row({
    id: 21,
    person: { id: 12, name: 'Hannah Brooks' },
    badge: { at: at('06:47'), door: 'Front door', method: 'card' },
    suggestion: { at: at('06:48'), answer: null, answered_at: null, closed: false },
    clock_in: { at: at('07:26'), how: 'the usual way' },
    apart_minutes: 39,
    outcome: 'apart',
    tone: 'warning',
    waiting_for_you: true,
});
const wiremu = row({ id: 22, person: { id: 13, name: 'Wiremu Parata' }, outcome: 'no_badge', tone: 'critical', waiting_for_you: true, told: { at: at('07:15'), names: ['Tama Hēnare'] } });
const daniel = row({ id: 23, kind: 'not_rostered', person: { id: 14, name: 'Daniel Kim' }, shift: null, badge: { at: at('09:12'), door: 'Front door', method: 'card' }, outcome: 'not_rostered', tone: 'warning', waiting_for_you: true });
const moana = row({ id: 24, person: { id: 15, name: 'Moana Rewi' }, shift: { starts_at: at('10:00'), ends_at: at('18:00') }, badge: { at: at('09:35'), door: 'Front door', method: 'card' }, suggestion: { at: at('09:36'), answer: null, answered_at: null, closed: false }, outcome: 'waiting', tone: 'info' });
const sione = row({ id: 25, badge: { at: at('06:52'), door: 'Front door', method: 'card' }, suggestion: { at: at('06:52'), answer: 'badge', answered_at: at('06:53'), closed: false }, clock_in: { at: at('06:52'), how: 'from the badge' }, outcome: 'confirmed', tone: 'success' });
const tama = row({ id: 26, person: { id: 16, name: 'Tama Hēnare' }, shift: { starts_at: at('08:30'), ends_at: at('17:00') }, badge: { at: at('08:05'), door: 'Front door', method: 'phone' }, suggestion: { at: at('08:05'), answer: 'now', answered_at: at('08:30'), closed: false }, clock_in: { at: at('08:30'), how: 'the usual way' }, outcome: 'now', tone: 'neutral' });

const data: AccessClockinsData = {
    state: 'on',
    how: { since: '2026-10-19', starts_on: null, places: [{ site: 'Head office', doors: ['Front door'] }, { site: 'Kauri House', doors: ['Back door', 'Front door'] }], before: 60, after: 30, badge_time: true },
    week: { from: '2026-10-26', to: '2026-10-29', starts: 38, badge: 27, other_way: 9, by_manager: 2, none: 0 },
    rows: [wiremu, hannah, daniel, moana, sione, tama],
    count: 3,
    meter: { confirmed: 1, starts: 4 },
    can: { review: true, setup: true },
};

const panel: ClockinPanelData = {
    ...hannah,
    next_shift: null,
    morning: [
        { at: at('06:47'), text: 'Badged in at the front door · clock-in suggested' },
        { at: at('07:15'), text: 'Reminder sent: not confirmed yet' },
        { at: at('07:26'), text: 'Clocked in the usual way' },
    ],
    choices: ['keep', 'ask'],
    can_note: true,
};

describe('Access › Clock-ins (pg-sdr-19e) and its side panel (pop-up 89)', () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
        window.history.replaceState(null, '', '/');
    });

    it('says how it works, with the window and the rules that are never settings', () => {
        render(<AccessClockinsTab data={data} />);

        expect(screen.getByText('How clock-ins from doors work')).toBeInTheDocument();
        expect(screen.getByText('On at Head office and Kauri House since Mon 19 Oct')).toBeInTheDocument();
        expect(screen.getByText('60 min before to 30 min after')).toBeInTheDocument();
        expect(screen.getByText('Faces and fingerprints are never used (D18), and residents’ fobs never count')).toBeInTheDocument();
        expect(screen.getByText('71%')).toBeInTheDocument();
        expect(screen.getByText('Missed, fixed by a manager')).toBeInTheDocument();
        expect(screen.getByText('7 in 10 from a badge.')).toBeInTheDocument();
    });

    it('lists today with gaps and mismatches first, each status in words and the next step', () => {
        render(<AccessClockinsTab data={data} />);
        const people = screen.getAllByRole('row').map((element) => within(element).queryAllByRole('cell')[0]?.textContent ?? '');
        const order = ['Wiremu Parata', 'Hannah Brooks', 'Daniel Kim', 'Moana Rewi', 'Sione Fifita', 'Tama Hēnare'];
        const positions = order.map((name) => people.findIndex((text) => text.startsWith(name)));
        expect(positions.every((position) => position >= 0)).toBe(true);
        expect(positions).toEqual([...positions].sort((a, b) => a - b));

        expect(screen.getByText('No badge, no clock-in by 7:15 am')).toBeInTheDocument();
        expect(screen.getByText('Tama Hēnare told at 7:15 am')).toBeInTheDocument();
        expect(screen.getByText('Ask Wiremu')).toBeInTheDocument();
        expect(screen.getByText('Badge and clock-in 39 min apart')).toBeInTheDocument();
        expect(screen.getByText('Clocked in 7:26 am the usual way')).toBeInTheDocument();
        expect(screen.getByText('Badged, not on the roster')).toBeInTheDocument();
        expect(screen.getByText('Not on the roster today')).toBeInTheDocument();
        expect(screen.getByText('Waiting for Moana')).toBeInTheDocument();
        expect(screen.getByText('Confirmed 6:52 am')).toBeInTheDocument();
        expect(screen.getByText('Tapped at 6:53 am')).toBeInTheDocument();
        expect(screen.getByText('Clocked in now instead, 8:30 am')).toBeInTheDocument();
        expect(screen.getByText('Front door, 8:05 am · phone')).toBeInTheDocument();
    });

    it('opens a gap’s side panel: keep the clock-in or ask her, never a clock-in time made up here', async () => {
        http.get.mockResolvedValueOnce({ data: panel });
        http.post.mockResolvedValueOnce({ data: { review: 'asked' } });
        render(<AccessClockinsTab data={data} />);

        fireEvent.click(screen.getByText('Hannah Brooks'));
        const sheet = await screen.findByTestId('door-clockin-89');
        await within(sheet).findByText('Front door, 6:47 am · card');
        expect(http.get).toHaveBeenCalledWith('/security-devices/access/clockins/21/panel', expect.anything());
        expect(within(sheet).getByText('Badge and clock-in 39 min apart · Thu 29 Oct 2026')).toBeInTheDocument();
        expect(within(sheet).getByText('To check')).toBeInTheDocument();
        expect(within(sheet).getByText('“Clock in at 6:47 am?” · not answered')).toBeInTheDocument();
        expect(within(sheet).getByText('7:00 am – 3:00 pm · Kauri House')).toBeInTheDocument();
        expect(within(sheet).getByText('Reminder sent: not confirmed yet')).toBeInTheDocument();
        expect(within(sheet).getByRole('button', { name: /Keep the clock-in/ })).toBeInTheDocument();
        expect(within(sheet).queryByText(/Use the badge time/)).not.toBeInTheDocument();

        // A gap waiting for the manager opens on "Ask"; the main button says who it goes to.
        expect(within(sheet).getByRole('button', { name: /Ask Hannah/ })).toHaveAttribute('aria-pressed', 'true');
        fireEvent.change(within(sheet).getByLabelText(/Message/), { target: { value: 'Did you start at 6:50 am?' } });
        fireEvent.click(within(sheet).getByRole('button', { name: 'Send to Hannah' }));
        await waitFor(() => expect(http.post).toHaveBeenCalledWith('/security-devices/access/clockins/21/review', { choice: 'ask', note: 'Did you start at 6:50 am?' }, expect.anything()));
        await waitFor(() => expect(inertia.reload).toHaveBeenCalled());
    });

    it('for a badge from someone not on the roster, offers not work or ask him, and points to the roster', async () => {
        http.get.mockResolvedValueOnce({
            data: { ...daniel, next_shift: new Date('2026-10-30T07:00:00+13:00').toISOString(), morning: [{ at: at('09:12'), text: 'Badged in at the front door · nothing suggested' }], choices: ['not_work', 'ask'], can_note: true } satisfies ClockinPanelData,
        });
        render(<AccessClockinsTab data={data} />);

        fireEvent.click(screen.getByText('Daniel Kim'));
        const sheet = await screen.findByTestId('door-clockin-89');
        await within(sheet).findByText('Not today · next Fri 30 Oct, 7:00 am');
        expect(within(sheet).getByRole('button', { name: /Not work/ })).toBeInTheDocument();
        expect(within(sheet).getByRole('button', { name: /Ask Daniel/ })).toBeInTheDocument();
        expect(within(sheet).getByRole('link', { name: 'roster' })).toHaveAttribute('href', '/operations/rostering');
    });

    it('opens the panel a gap notice links to', async () => {
        window.history.replaceState(null, '', '/security-devices/access?tab=clockins&clockin=22');
        http.get.mockResolvedValueOnce({ data: { ...wiremu, next_shift: null, morning: [], choices: ['ask'], can_note: true } satisfies ClockinPanelData });
        render(<AccessClockinsTab data={data} />);

        await screen.findByTestId('door-clockin-89');
        expect(http.get).toHaveBeenCalledWith('/security-devices/access/clockins/22/panel', expect.anything());
    });

    it('says plainly when it is off, and where to turn it on', () => {
        render(<AccessClockinsTab data={{ ...data, state: 'off', rows: [], week: null }} />);

        expect(screen.getByText(/Clock-ins from doors are off/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Turn on in Setup' })).toHaveAttribute('href', '/security-devices/setup/access?tab=clockins');
    });
});
