import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { MyHrClockFeedback } from './my-hr-clock-feedback';
import type { useMyHrClock } from './use-my-hr-clock';

afterEach(cleanup);
function command(): ReturnType<typeof useMyHrClock> {
    return {
        submit: vi.fn(), refresh: vi.fn(), pending: false, refreshing: false,
        blocked: true, isBlocked: () => true,
        outcome: { status: 'unknown', message: 'The request may already have saved.' },
    };
}

it('offers a current HR read after uncertainty without replaying the command', () => {
    const value = command();
    render(<MyHrClockFeedback command={value} timezone="Pacific/Auckland" />);
    expect(screen.getByRole('alert')).toHaveTextContent('may already have saved');
    expect(screen.getByRole('link', { name: 'Check current clock record' })).toHaveAttribute('href', '/hr/my');
    expect(screen.queryByRole('button')).toBeNull();
    expect(value.refresh).not.toHaveBeenCalled();
    expect(value.submit).not.toHaveBeenCalled();
});

it('reports the recorded legacy entry and payroll follow-up without claiming a saved draft', () => {
    const value = command();
    value.outcome = {
        status: 'confirmed', refreshed: false,
        receipt: {
            schema_version: 1, scope: 'my_hr_clock_recorded_result', actor_id: 7,
            action: 'clock_out', target_kind: 'orphan_time_entry', attendance_result: null,
            time_entry_result: {
                id: 701, attendance_session_id: null, shift_id: null, status: 'submitted',
                clock_in_at: '2026-10-20T08:00:00+13:00', clock_out_at: '2026-10-20T10:00:00+13:00',
                break_minutes: 15, worked_hours: 1.75,
                timesheet_sync_outcome: 'skipped_follow_up', timesheet_id: null, timesheet_status: null,
            },
        },
    };
    render(<MyHrClockFeedback command={value} timezone="Pacific/Auckland" />);
    expect(screen.getByRole('status')).toHaveTextContent('Time entry closed');
    expect(screen.getByRole('status')).toHaveTextContent('timesheet was not changed');
    expect(screen.getByRole('status')).toHaveTextContent('Time entry was saved');
    expect(screen.queryByText(/Attendance was saved/)).toBeNull();
    expect(screen.queryByText(/draft.*created/i)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh clock record' }));
    expect(value.refresh).toHaveBeenCalledOnce();
    expect(value.submit).not.toHaveBeenCalled();
});

it('shows only waiting guidance while a clock action is in flight', () => {
    render(<MyHrClockFeedback command={{ ...command(), pending: true }} timezone="Pacific/Auckland" />);
    expect(screen.getByRole('status')).toHaveTextContent('Saving your clock action');
    expect(screen.queryByRole('link')).toBeNull();
});
