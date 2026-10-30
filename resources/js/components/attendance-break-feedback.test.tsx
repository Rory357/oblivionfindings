import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { AttendanceBreakFeedback } from './attendance-break-feedback';
import type { useAttendanceBreak } from '@/hooks/use-attendance-break';

afterEach(cleanup);
const command = (): ReturnType<typeof useAttendanceBreak> => ({
    submit: vi.fn(),
    pending: false,
    refreshing: false,
    blocked: true,
    isBlocked: () => true,
    blocksSession: () => true,
    refresh: vi.fn(),
    outcome: { status: 'unknown', message: 'The request may already have saved.' },
});

it('offers an explicit current-state navigation after uncertainty, with no retry action', () => {
    const value = command();
    render(<AttendanceBreakFeedback command={value} checkHref="/my-day" />);
    expect(screen.getByRole('alert')).toHaveTextContent('may already have saved');
    expect(screen.getByRole('link', { name: 'Check current attendance' })).toHaveAttribute('href', '/my-day');
    expect(screen.queryByRole('button')).toBeNull();
    expect(value.submit).not.toHaveBeenCalled();
});

it('shows a waiting message without navigation while a write is pending', () => {
    render(<AttendanceBreakFeedback command={{ ...command(), pending: true }} checkHref="/attendance" />);
    expect(screen.getByRole('status')).toHaveTextContent('Saving your break');
    expect(screen.queryByRole('link')).toBeNull();
});

it('keeps a confirmed write separate from a failed page refresh', () => {
    const value = command();
    value.outcome = {
        status: 'confirmed',
        refreshed: false,
        receipt: {
            schema_version: 1, scope: 'attendance_break_recorded_result',
            action: 'end_break', actor_id: 7,
            session: { id: 42, shift_id: null, status: 'open', break_started_at: null, break_minutes: 0, break_count: 1 },
            break_event: { id: 55, session_id: 42, started_at: '2026-10-20T10:00:00+13:00', ended_at: '2026-10-20T10:00:00+13:00', minutes: 0 },
        },
    };
    render(<AttendanceBreakFeedback command={value} checkHref="/attendance" />);
    expect(screen.getByRole('status')).toHaveTextContent('Break ended');
    fireEvent.click(screen.getByRole('button', { name: 'Refresh attendance' }));
    expect(value.refresh).toHaveBeenCalledOnce();
    expect(value.submit).not.toHaveBeenCalled();
});
