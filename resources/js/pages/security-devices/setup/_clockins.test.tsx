import type { SetupClockinsData } from '@/lib/security-devices/door-clockins';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SetupClockins } from './_clockins';

const inertia = vi.hoisted(() => ({ reload: vi.fn(), visit: vi.fn(), on: vi.fn(() => () => undefined) }));
const http = vi.hoisted(() => ({ put: vi.fn(), patch: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, usePage: () => ({ props: { auth: { user: { id: 3 } } } }), router: { ...actual.router, ...inertia } };
});
vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return { default: { ...actual.default, put: http.put, patch: http.patch, post: http.post, isAxiosError: actual.default.isAxiosError } };
});

const doors = [
    { id: 1, name: 'Front door', site: { id: 7, name: 'Head office' }, readers: ['Front door reader'], counts: true, starts_on: '2026-10-19' },
    { id: 2, name: 'Back door', site: { id: 7, name: 'Head office' }, readers: [], counts: false, starts_on: null },
    { id: 3, name: 'Front door', site: { id: 4, name: 'Kauri House' }, readers: ['Front door lock'], counts: true, starts_on: '2026-10-19' },
    { id: 4, name: 'Back door', site: { id: 4, name: 'Kauri House' }, readers: [], counts: true, starts_on: '2026-10-19' },
    { id: 5, name: 'Server room', site: { id: 4, name: 'Kauri House' }, readers: [], counts: false, starts_on: null },
];

const on: SetupClockinsData = {
    state: 'on',
    since: '2026-10-19',
    starts_on: null,
    turned_off_at: null,
    places: [
        { site: 'Head office', doors: ['Front door'] },
        { site: 'Kauri House', doors: ['Back door', 'Front door'] },
    ],
    notice: { sent_at: '2026-10-11T20:00:00Z', by: 'Ana Ruha', by_email: true, starts_on: '2026-10-19', told: 14 },
    today: { badge: 4, other_way: 1, gaps: 3 },
    settings: {
        before: 60,
        after: 30,
        badge_time: true,
        gaps: { unconfirmed: { app: true, email: false }, no_badge: { app: true, email: false }, not_rostered: { app: true, email: true } },
    },
    doors,
    told_by_site: { '4': 9, '7': 5 },
    me: { name: 'Ana Ruha', position: 'Operations manager' },
    earliest_start: '2026-11-09',
    options: { before: [30, 60, 90], after: [15, 30, 60] },
    can: { change: true, review: true, shared: true },
};

const off: SetupClockinsData = { ...on, state: 'off', since: null, places: [], notice: null, today: null, doors: doors.map((door) => ({ ...door, counts: false, starts_on: null })) };

describe('Setup › Clock-ins from doors (pg-sdr-15n) and pop-up 92', () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('shows that it’s on, this morning, the rows and what is kept', () => {
        render(<SetupClockins data={on} />);

        expect(screen.getByText('On since Mon 19 Oct')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Clock-ins from doors are on at Head office and Kauri House' })).toBeInTheDocument();
        expect(screen.getByText(/This morning: 4 confirmed from a badge, 1 clocked in another way, 3 gaps for managers/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Today’s clock-ins' })).toHaveAttribute('href', '/security-devices/access?tab=clockins');
        expect(screen.getByRole('button', { name: /Doors that count: Head office front door, Kauri House back and front doors/ })).toBeInTheDocument();
        expect(screen.getByText('Not used')).toBeInTheDocument();
        expect(screen.getByText('Never')).toBeInTheDocument();
        expect(screen.getByText(/NZ Biometric Processing Privacy Code/)).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: 'Badged in, not on the roster: Email' })).toBeChecked();
        expect(screen.getByRole('switch', { name: 'On the roster, no badge and no clock-in: Email' })).not.toBeChecked();
        expect(screen.getByText('Not a time record; goes with the door event at 90 days')).toBeInTheDocument();
    });

    it('keeps switch changes as a draft until they are reviewed and saved', async () => {
        http.patch.mockResolvedValueOnce({ data: { changed: ['badge_time'] } });
        render(<SetupClockins data={on} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Clock in at the badge time' }));
        expect(screen.getByText('1 change not saved')).toBeInTheDocument();
        expect(http.patch).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
        const review = await screen.findByRole('dialog', { name: 'Save 1 change to clock-ins from doors?' });
        expect(within(review).getByText(/On → Off/)).toBeInTheDocument();
        fireEvent.click(within(review).getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(http.patch).toHaveBeenCalledWith('/security-devices/setup/clockins/settings', { changes: { badge_time: false } }, expect.anything()));
    });

    it('turns on only by sending the notice first, starting at least a week later', async () => {
        http.put.mockResolvedValueOnce({ data: { added: 2, removed: 0, told: 9, not_told: 1, starts_on: '2026-11-09' } });
        render(<SetupClockins data={off} />);

        expect(screen.getByRole('heading', { name: 'Clock-ins from doors are off' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Turn on' }));
        const popup = await screen.findByRole('dialog', { name: 'Turn on clock-ins from doors' });
        expect(within(popup).getByText('Staff are told before it starts.')).toBeInTheDocument();
        expect(within(popup).getByText('Never, even on readers that can use them')).toBeInTheDocument();
        expect(within(popup).getByText('Never: it’s their home, not work')).toBeInTheDocument();
        expect(within(popup).getByText('Not taken from doors: exit buttons don’t badge')).toBeInTheDocument();

        fireEvent.click(within(popup).getByLabelText(/^Kauri House front door/));
        fireEvent.click(within(popup).getByLabelText(/^Kauri House back door/));
        const notice = within(popup).getByLabelText(/Notice/) as HTMLTextAreaElement;
        expect(notice.value).toMatch(/^From Mon 9 Nov, when you badge in at the front or back door at Kauri House, near the start of a rostered shift/);
        expect(notice.value).toContain('never your face or fingerprint');
        expect(notice.value).toContain('Questions: Ana Ruha, operations manager.');
        expect(within(popup).getByText(/Goes to 9 people matched to staff with a card at Kauri House/)).toBeInTheDocument();
        expect(within(popup).getByRole('button', { name: 'App (always)' })).toBeDisabled();

        fireEvent.click(within(popup).getByRole('button', { name: 'Send notice and turn on' }));
        await waitFor(() =>
            expect(http.put).toHaveBeenCalledWith(
                '/security-devices/setup/clockins/doors',
                expect.objectContaining({ door_ids: [3, 4], before: 60, after: 30, email: true, starts_on: '2026-11-09', notice: expect.stringContaining('From Mon 9 Nov') }),
                expect.anything(),
            ),
        );
        await waitFor(() => expect(inertia.reload).toHaveBeenCalled());
    });

    it('needs no notice when doors are only taken away', async () => {
        http.put.mockResolvedValueOnce({ data: { added: 0, removed: 1, told: 0, not_told: 0, starts_on: null } });
        render(<SetupClockins data={on} />);

        fireEvent.click(screen.getByRole('button', { name: /Doors that count:/ }));
        const popup = await screen.findByRole('dialog', { name: 'Change clock-ins from doors' });
        fireEvent.click(within(popup).getByLabelText(/^Head office front door/));
        expect(within(popup).getByText(/No new doors, so no notice is needed/)).toBeInTheDocument();
        expect(within(popup).queryByLabelText(/Notice/)).not.toBeInTheDocument();

        fireEvent.click(within(popup).getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(http.put).toHaveBeenCalledWith('/security-devices/setup/clockins/doors', expect.objectContaining({ door_ids: [3, 4], notice: null, starts_on: null }), expect.anything()));
    });

    it('asks before turning it off, and says confirmed clock-ins stay', async () => {
        http.post.mockResolvedValueOnce({ data: { removed: 3 } });
        render(<SetupClockins data={on} />);

        fireEvent.click(screen.getByRole('button', { name: 'Turn off' }));
        const confirm = await screen.findByRole('alertdialog', { name: 'Turn off clock-ins from doors?' });
        expect(within(confirm).getByText(/Clock-ins people already confirmed stay on their timesheets/)).toBeInTheDocument();
        fireEvent.click(within(confirm).getByRole('button', { name: 'Turn off' }));
        await waitFor(() => expect(http.post).toHaveBeenCalledWith('/security-devices/setup/clockins/off', {}, expect.anything()));
    });

    it('is read-only for someone who can’t change it', () => {
        render(<SetupClockins data={{ ...on, can: { change: false, review: false, shared: false } }} />);

        expect(screen.getByRole('switch', { name: 'Suggest a clock-in from a door badge' })).toBeDisabled();
        expect(screen.getByRole('switch', { name: 'Clock in at the badge time' })).toBeDisabled();
        expect(screen.queryByRole('button', { name: 'Turn off' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Today’s clock-ins' })).not.toBeInTheDocument();
        expect(screen.queryByRole('region', { name: 'Save changes' })).not.toBeInTheDocument();
    });

    it('lets a manager of some Sites change their doors, but not what applies to every Site', () => {
        render(<SetupClockins data={{ ...on, can: { change: true, review: true, shared: false } }} />);

        expect(screen.getByRole('switch', { name: 'Suggest a clock-in from a door badge' })).toBeEnabled();
        expect(screen.getByRole('switch', { name: 'Clock in at the badge time' })).toBeDisabled();
        expect(screen.getByRole('combobox', { name: 'Window: from' })).toBeDisabled();
        expect(screen.getAllByText(/only someone who looks after every Site changes it/).length).toBeGreaterThan(0);
    });
});
