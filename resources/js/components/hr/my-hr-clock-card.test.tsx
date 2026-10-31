import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import { MyHrClockCard } from './my-hr-clock-card';
import type { MyHrAttendance } from './my-hr-attendance';

const mocks = vi.hoisted(() => ({
    post: vi.fn(), reload: vi.fn(),
    auth: { user: { id: 7 }, can: { timesheets: { create: true } } },
}));
vi.mock('@inertiajs/react', () => ({
    router: { reload: mocks.reload },
    usePage: () => ({ props: { auth: mocks.auth } }),
}));
vi.mock('axios', () => ({
    default: { post: mocks.post, isAxiosError: (error: { isAxiosError?: boolean }) => error?.isAxiosError === true },
}));

function open(): MyHrAttendance {
    return {
        schema_version: 1, scope: 'my_hr_self_attendance', state: 'open',
        actor_id: 7, timezone: 'Pacific/Auckland', can_break: true, can_clock_out: true, time_entry: null,
        session: {
            id: 42, shift_id: 81, clock_in_at: '2026-10-20T08:00:00Z',
            is_on_break: false, break_started_at: null, break_minutes: 0, break_count: 0,
        },
    };
}
function legacy(): MyHrAttendance {
    return {
        schema_version: 1, scope: 'my_hr_self_attendance', state: 'entry_only',
        actor_id: 7, timezone: 'Pacific/Auckland', can_break: false, can_clock_out: false, session: null,
        time_entry: { id: 701, shift_id: 81, clock_in_at: '2026-10-20T08:00:00Z', break_minutes: 15 },
    };
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-20T12:00:00Z'));
    mocks.post.mockReset().mockReturnValue(new Promise(() => {}));
    mocks.reload.mockReset();
    mocks.auth = { user: { id: 7 }, can: { timesheets: { create: true } } };
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

it('labels an overnight clock as this session and never invents its finish time', () => {
    render(<MyHrClockCard attendance={open()} />);

    expect(screen.getByText('This session · 4 hr')).toBeInTheDocument();
    expect(screen.getByText(/Clocked in 20 Oct 2026.*9:00 pm/)).toBeInTheDocument();
    expect(screen.queryByText(/^Today ·/)).toBeNull();
    expect(screen.queryByText(/^Out ·/)).toBeNull();
    expect(screen.queryByRole('progressbar')).toBeNull();
});

it('shows the upcoming shift on the work date across the UTC date boundary', () => {
    render(
        <MyHrClockCard
            attendance={{ ...open(), state: 'none', can_break: false, can_clock_out: false, session: null }}
            nextShift={{
                id: 81,
                starts_at: '2026-10-20T12:00:00Z',
                ends_at: '2026-10-20T20:00:00Z',
                location: 'Kauri House',
                service_context_name: null,
            }}
        />,
    );

    expect(screen.getByText(/21 Oct 2026.*1:00 am/)).toBeInTheDocument();
    expect(screen.getByText('Kauri House')).toBeInTheDocument();
});

it('reads a running break from the server and keeps the work timer paused across ticks', () => {
    const read = open();
    if (read.state !== 'open') throw new Error('Fixture');
    read.session = { ...read.session, break_minutes: 15, break_count: 1,
        is_on_break: true, break_started_at: '2026-10-20T11:30:00Z' };
    render(<MyHrClockCard attendance={read} />);
    expect(screen.getByRole('button', { name: 'End break' })).toBeInTheDocument();
    expect(screen.getByLabelText('Elapsed work estimate')).toHaveTextContent('03:15:00');
    act(() => vi.advanceTimersByTime(60_000));
    expect(screen.getByLabelText('Elapsed work estimate')).toHaveTextContent('03:15:00');
    expect(mocks.post).not.toHaveBeenCalled();
});

it('sends the canonical break identity and guards clock-out while it is pending', () => {
    render(<MyHrClockCard attendance={open()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Start break' }));
    expect(mocks.post).toHaveBeenCalledWith('/attendance/break/start', { session_id: 42 }, expect.anything());
    expect(screen.getByRole('button', { name: 'Clock out' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Clock out' }));
    expect(mocks.post).toHaveBeenCalledOnce();
});

it('captures clock-out identity and keeps recorded break totals off the request', () => {
    render(<MyHrClockCard attendance={open()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Clock out' }));
    expect(mocks.post).toHaveBeenCalledWith('/hr/my/time/clock-out', {
        actor_id: 7, target: { kind: 'attendance_session', id: 42 }, expected_shift_id: 81,
    }, expect.anything());
    expect(screen.getByRole('button', { name: 'Start break' })).toBeDisabled();
});

it('does not celebrate a generic successful HTTP response without a recorded result', async () => {
    mocks.post.mockResolvedValue({ status: 200, data: '<html>redirected page</html>' });
    render(<MyHrClockCard attendance={open()} />);
    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Clock out' })));
    expect(screen.getByRole('link', { name: 'Check current clock record' })).toHaveAttribute('href', '/hr/my');
    expect(screen.queryByText('Clocked out')).toBeNull();
    expect(screen.queryByText(/Draft timesheet/)).toBeNull();
    expect(screen.getByRole('button', { name: 'Clock out' })).toBeDisabled();
});

it('preserves the legacy entry recovery path without granting it break actions', () => {
    mocks.auth.can.timesheets.create = false;
    render(<MyHrClockCard attendance={legacy()} />);
    expect(screen.queryByRole('button', { name: /Start break|End break/ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Close time entry' }));
    expect(mocks.post).toHaveBeenCalledWith('/hr/my/time/clock-out', {
        actor_id: 7, target: { kind: 'orphan_time_entry', id: 701 }, expected_shift_id: 81,
    }, expect.anything());
});

it('allows an explicit zero-break override for a legacy entry', () => {
    render(<MyHrClockCard attendance={legacy()} />);
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Change break minutes (optional)' }), { target: { value: '0' } });
    fireEvent.click(screen.getByRole('button', { name: 'Close time entry' }));
    expect(mocks.post.mock.calls[0][1]).toHaveProperty('break_minutes', 0);
});

it('keeps the confirmed HR-only closure after refreshing to idle without granting clock-in', async () => {
    mocks.auth.can.timesheets.create = false;
    mocks.post.mockResolvedValue({
        status: 200, data: {
            schema_version: 1, scope: 'my_hr_clock_recorded_result', actor_id: 7,
            action: 'clock_out', target_kind: 'orphan_time_entry', attendance_result: null,
            time_entry_result: {
                id: 701, attendance_session_id: null, shift_id: 81, status: 'submitted',
                clock_in_at: '2026-10-20T08:00:00Z', clock_out_at: '2026-10-20T12:00:00Z',
                break_minutes: 15, worked_hours: 3.75,
                timesheet_sync_outcome: 'none', timesheet_id: null, timesheet_status: null,
            },
        },
    });
    const { rerender } = render(<MyHrClockCard attendance={legacy()} />);
    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Close time entry' })));
    rerender(<MyHrClockCard attendance={{ ...legacy(), state: 'none', time_entry: null }} />);
    act(() => mocks.reload.mock.calls[0][0].onFinish());
    expect(screen.getByText('Time entry closed')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Clock in' })).toBeNull();
    expect(screen.queryByText('Your clock record or access has changed.')).toBeNull();
});

it('shows a recorded break after the matching server refresh and then releases clock-out', async () => {
    mocks.post.mockResolvedValue({
        status: 200, data: {
            schema_version: 1, scope: 'attendance_break_recorded_result', action: 'start_break', actor_id: 7,
            session: { id: 42, shift_id: 81, status: 'open', break_started_at: '2026-10-20T12:00:00Z', break_minutes: 0, break_count: 1 },
            break_event: { id: 61, session_id: 42, started_at: '2026-10-20T12:00:00Z', ended_at: null, minutes: 0 },
        },
    });
    const { rerender } = render(<MyHrClockCard attendance={open()} />);
    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Start break' })));
    expect(screen.getByRole('button', { name: 'Clock out' })).toBeDisabled();
    const updated = open();
    if (updated.state !== 'open') throw new Error('Fixture');
    updated.session = { ...updated.session, is_on_break: true,
        break_started_at: '2026-10-20T12:00:00Z', break_count: 1 };
    rerender(<MyHrClockCard attendance={updated} />);
    act(() => {
        mocks.reload.mock.calls[0][0].onSuccess({ props: { auth: mocks.auth, myHr: { attendance: updated } } });
        mocks.reload.mock.calls[0][0].onFinish();
    });
    expect(screen.getByRole('button', { name: 'End break' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Clock out' })).toBeEnabled();
    expect(mocks.post).toHaveBeenCalledOnce();
});

it('does not turn absent canonical data or missing grants into a clock action', () => {
    const { rerender } = render(<MyHrClockCard />);
    expect(screen.getByText('Clock record unavailable')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Clock in|Clock out/ })).toBeNull();
    mocks.auth.can.timesheets.create = false;
    rerender(<MyHrClockCard attendance={{ ...open(), can_break: false }} />);
    expect(screen.queryByRole('button', { name: /Clock in|Clock out|Start break/ })).toBeNull();
    expect(mocks.post).not.toHaveBeenCalled();
});

it('keeps all clock actions held when a break reply is lost', async () => {
    mocks.post.mockRejectedValue(new Error('offline'));
    render(<MyHrClockCard attendance={open()} />);
    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Start break' })));
    expect(screen.getByRole('link', { name: 'Check current attendance' })).toHaveAttribute('href', '/hr/my');
    expect(screen.getByRole('button', { name: 'Clock out' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Clock out' }));
    expect(mocks.post).toHaveBeenCalledOnce();
});

it('keeps a historical session visible without offering unavailable commands', () => {
    render(<MyHrClockCard attendance={{ ...open(), can_break: false, can_clock_out: false }} />);
    expect(screen.getByText('This session · 4 hr')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Clock out|Start break/ })).toBeNull();
    expect(screen.getByText(/ask your manager to review it/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Check current clock record' })).toHaveAttribute('href', '/hr/my');
    expect(mocks.post).not.toHaveBeenCalled();
});

it('does not infer clock-out capability from a denied break capability', () => {
    render(<MyHrClockCard attendance={{ ...open(), can_break: false }} />);
    expect(screen.queryByRole('button', { name: /Start break|End break/ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Clock out' }));
    expect(mocks.post.mock.calls[0][1].target).toEqual({ kind: 'attendance_session', id: 42 });
});

it('retains a confirmed clock-in when the new session is not yet eligible for clock-out', async () => {
    mocks.post.mockResolvedValue({
        status: 200, data: {
            schema_version: 1, scope: 'my_hr_clock_recorded_result', actor_id: 7,
            action: 'clock_in', target_kind: 'attendance_session', time_entry_result: null,
            attendance_result: {
                action: 'clock_in', session_id: 42, shift_id: 81, session_status: 'open',
                clock_in_at: '2026-10-20T12:00:00Z', clock_out_at: null,
                break_minutes: 0, worked_hours: null,
                timesheet_sync_outcome: 'none', timesheet_id: null, timesheet_status: null,
                handover_outcome: null,
            },
        },
    });
    const { rerender } = render(<MyHrClockCard attendance={{
        ...open(), state: 'none', session: null, can_break: false, can_clock_out: false,
    }} />);
    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Clock in' })));
    const current = open();
    if (current.state !== 'open') throw new Error('Fixture');
    current.can_clock_out = false;
    current.session.clock_in_at = '2026-10-20T12:00:00Z';
    rerender(<MyHrClockCard attendance={current} />);
    act(() => mocks.reload.mock.calls[0][0].onFinish());
    expect(screen.getByText('Clocked in')).toBeInTheDocument();
    expect(screen.queryByText('Your clock record or access has changed.')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Clock out' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Start break' })).toBeEnabled();
    expect(mocks.post).toHaveBeenCalledOnce();
});
