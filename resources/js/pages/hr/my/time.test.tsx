import { cleanup, render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { MyHrShellData } from '@/components/hr/my-hr-types';
import MyTime from './time';

const mocks = vi.hoisted(() => ({
    auth: { user: { id: 7 }, can: { shifts: { viewAssigned: true, viewAny: false, manageAny: false } } },
}));
vi.mock('@inertiajs/react', () => ({
    Link: (props: ComponentProps<'a'>) => <a {...props} />,
    usePage: () => ({ props: { auth: mocks.auth } }),
}));
// Exercise this page's actual content; the shared clock/header has its own suite.
vi.mock('@/components/hr', () => ({
    MyHrShell: ({ children }: { children: ReactNode }) => <>{children}</>,
    hueFromId: () => 240,
}));

type Props = ComponentProps<typeof MyTime>;
const entry: Props['todayEntries'][number] = {
    id: 11, clock_in: '08:00', clock_out: '16:00', break_minutes: 30,
    total_hours: '7.50', status: 'draft', client_name: null, shift: null,
};
function props(): Props {
    return {
        myHr: {} as MyHrShellData,
        activeClock: null,
        todayEntries: [],
        weeklySummary: { week_start: '2026-10-19', week_end: '2026-10-25', daily_hours: {}, total_hours: 0 },
        weekRoster: [{
            day: 'Tue', date: '20 Oct', today: true,
            shifts: [{ id: 81, service_context_id: null, site: 'Kauri House', client_name: null,
                shift_type: 'standard', time: '08:00 – 16:00',
                starts_at: '2026-10-19T19:00:00Z', ends_at: '2026-10-20T03:00:00Z' }],
        }],
    };
}
beforeEach(() => {
    mocks.auth = { user: { id: 7 }, can: { shifts: { viewAssigned: true, viewAny: false, manageAny: false } } };
});
afterEach(cleanup);

it('adds recorded decimal strings and numeric hours without crashing', () => {
    render(<MyTime {...props()} todayEntries={[entry, { ...entry, id: 12, total_hours: 1.25 }]} />);
    expect(screen.getByText('8.8 hr recorded')).toBeInTheDocument();
    expect(screen.getAllByText('Recorded breaks · 30 min')).toHaveLength(2);
});

it('shows zero recorded hours without inferring that the worker is on shift', () => {
    render(<MyTime {...props()} />);
    expect(screen.getByText('0.0 hr recorded')).toBeInTheDocument();
    expect(screen.getByText('No time entries shown for today.')).toBeInTheDocument();
    expect(screen.queryByText(/on shift|clock in from/i)).toBeNull();
});

it('excludes an unfinished entry and does not derive live attendance from the legacy clock prop', () => {
    render(<MyTime {...props()} activeClock={{ id: 11, clock_in: '2026-10-19T19:00:00Z' }}
        todayEntries={[{ ...entry, clock_out: null, total_hours: '9.00' }]} />);
    expect(screen.getByText('0.0 hr recorded')).toBeInTheDocument();
    expect(screen.getByText('Clocked in')).toBeInTheDocument();
    expect(screen.queryByText(/Now — on shift|^live$/)).toBeNull();
});

it.each([null, -2, Number.POSITIVE_INFINITY, 'bad', ''])('reports unavailable closed-entry hours instead of inventing a total: %s', (total_hours) => {
    render(<MyTime {...props()} todayEntries={[{ ...entry, total_hours }]} />);
    expect(screen.getByText('Recorded hours unavailable')).toBeInTheDocument();
});

it('exposes real shift and calendar destinations without requiring a context menu', () => {
    render(<MyTime {...props()} />);
    expect(screen.getByRole('link', { name: /View shift at Kauri House/ })).toHaveAttribute('href', '/operations/shifts/81');
    const download = screen.getByRole('link', { name: /Download calendar file for Kauri House/ });
    expect(download).toHaveAttribute('href', '/hr/my/time/shifts/81/calendar');
    expect(download).toHaveAttribute('download', 'shift-81.ics');
    expect(download).toHaveClass('frontline-tap');
    expect(screen.queryByText(/right-click/)).toBeNull();
});

it('keeps the HR summary and calendar export without granting Workforce detail access', () => {
    mocks.auth.can.shifts = { viewAssigned: false, viewAny: false, manageAny: true };
    render(<MyTime {...props()} />);
    expect(screen.getByText('Kauri House')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /View shift/ })).toBeNull();
    expect(screen.getByRole('link', { name: /Download calendar file/ })).toBeInTheDocument();
});

it('updates actions from current permissions and withdraws links with a withdrawn roster row', () => {
    const { rerender } = render(<MyTime {...props()} />);
    mocks.auth.can.shifts.viewAssigned = false;
    rerender(<MyTime {...props()} />);
    expect(screen.queryByRole('link', { name: /View shift/ })).toBeNull();
    mocks.auth.can.shifts.viewAny = true;
    rerender(<MyTime {...props()} />);
    expect(screen.getByRole('link', { name: /View shift/ })).toBeInTheDocument();
    rerender(<MyTime {...props()} weekRoster={[]} />);
    expect(screen.queryByRole('link', { name: /View shift|Download calendar file/ })).toBeNull();
});

it('withholds record actions for an invalid shift identity', () => {
    const data = props();
    data.weekRoster[0].shifts[0].id = 0;
    render(<MyTime {...data} />);
    expect(screen.queryByRole('link', { name: /View shift|Download calendar file/ })).toBeNull();
});

it('shows complete work-local dates for an overnight recorded entry', () => {
    render(<MyTime {...props()} todayDate="2026-10-21"
        todayEntries={[{ ...entry, clock_in_at: '2026-10-20T12:00:00Z', clock_out_at: '2026-10-20T20:00:00Z' }]} />);
    expect(screen.getByText(/21 Oct 2026.*1:00 am/)).toBeInTheDocument();
    expect(screen.getByText(/21 Oct 2026.*9:00 am/)).toBeInTheDocument();
    expect(screen.queryByText(/time zone not supplied/)).toBeNull();
    expect(screen.getByText('19 Oct 2026 – 25 Oct 2026')).toBeInTheDocument();
});

it('uses the current actor’s validated work timezone for recorded times', () => {
    const data = props();
    data.myHr.attendance = {
        schema_version: 1, scope: 'my_hr_self_attendance', state: 'none', actor_id: 7,
        timezone: 'America/New_York', can_break: false, can_clock_out: false,
        session: null, time_entry: null,
    };
    render(<MyTime {...data} todayEntries={[{ ...entry,
        clock_in_at: '2026-10-20T12:00:00Z', clock_out_at: '2026-10-20T20:00:00Z' }]} />);
    expect(screen.getByText('America/New_York')).toBeInTheDocument();
    expect(screen.getByText(/20 Oct 2026.*8:00 am/)).toBeInTheDocument();
    expect(screen.getByText(/20 Oct 2026.*4:00 pm/)).toBeInTheDocument();
});

it('keeps both actual dates and actions for a Sunday shift continuing into Monday', () => {
    const data = props();
    data.weekRoster[0] = {
        ...data.weekRoster[0], day: 'Mon', date: '19 Oct', date_key: '2026-10-19',
        shifts: [{ ...data.weekRoster[0].shifts[0], continues_from_previous_week: true,
            starts_at: '2026-10-18T09:00:00Z', ends_at: '2026-10-18T13:00:00Z' }],
    };
    render(<MyTime {...data} weekStartDate="2026-10-19" weekEndDate="2026-10-25" />);
    expect(screen.getByText('Continues into this week')).toBeInTheDocument();
    expect(screen.getByText(/18 Oct 2026.*10:00 pm/)).toBeInTheDocument();
    expect(screen.getByText(/19 Oct 2026.*2:00 am/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Download calendar file.*Mon 19 Oct/ })).toHaveAttribute('href', '/hr/my/time/shifts/81/calendar');
});

it.each([null, '2026-10-19T18:00:00Z'])('retains record review but withholds calendar export for an invalid window: %s', (ends_at) => {
    const data = props();
    data.weekRoster[0].shifts[0].ends_at = ends_at;
    render(<MyTime {...data} />);
    expect(screen.getByText('Schedule times unavailable')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /View shift/ })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Download calendar file/ })).toBeNull();
});

it('retains older recorded clock values with an explicit timezone qualification', () => {
    render(<MyTime {...props()} todayEntries={[entry]} />);
    expect(screen.getByText('08:00 (time zone not supplied)')).toBeInTheDocument();
    expect(screen.getByText('16:00 (time zone not supplied)')).toBeInTheDocument();
});

it('does not interpret a malformed new timestamp in the browser timezone', () => {
    render(<MyTime {...props()} todayEntries={[{ ...entry,
        clock_in_at: '2026-10-20T12:00:00', clock_out_at: 'invalid' }]} />);
    expect(screen.getAllByText('Time unavailable')).toHaveLength(2);
    expect(screen.queryByText(/time zone not supplied/)).toBeNull();
});
