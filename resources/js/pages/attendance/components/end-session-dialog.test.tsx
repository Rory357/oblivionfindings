import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { EndSessionDialog } from './end-session-dialog';
import type { OnClockRow } from './shared';
import type { AttendanceResult } from './use-attendance-command';

type Options = {
    onSuccess?: (page: { props: Record<string, unknown> }) => void;
    onError?: (errors: Record<string, string>) => void;
    onCancel?: () => void;
    onFinish?: () => void;
};
const transport = vi.hoisted(() => ({
    requests: [] as { url: string; data: unknown; options: Options }[],
    throwNext: false,
}));
vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url: string, data: unknown, options: Options) => {
            if (transport.throwNext) throw new Error('Disconnected');
            transport.requests.push({ url, data, options });
        },
    },
}));
beforeEach(() => {
    transport.requests.length = 0;
    transport.throwNext = false;
});
afterEach(cleanup);

const session: OnClockRow = {
    id: 8,
    user_id: 14,
    user_name: 'Aroha',
    clock_in_at: '2026-10-05T08:00:00+13:00',
    shift_id: 7,
    shift_location: 'Harbour House',
    shift_ends_at: '2026-10-05T17:00:00+13:00',
    is_stale: true,
};
const result: AttendanceResult = {
    action: 'end_session',
    session_id: 8,
    shift_id: 7,
    session_status: 'closed',
    clock_in_at: session.clock_in_at,
    clock_out_at: '2026-10-05T17:00:00+13:00',
    break_minutes: 30,
    worked_hours: 8.5,
    timesheet_sync_outcome: 'skipped_follow_up',
    timesheet_id: null,
    timesheet_status: null,
    handover_outcome: null,
};
const props = {
    open: true,
    session,
    currentSession: session,
    viewerId: 2,
    canManage: true,
    onClose: vi.fn(),
};
function enterReason(value = '  Manager confirmed the worker has finished.  ') {
    fireEvent.change(screen.getByRole('textbox', { name: 'Reason' }), {
        target: { value },
    });
}
function submit() {
    fireEvent.click(screen.getByRole('button', { name: 'End session' }));
}
function finish(flash?: unknown) {
    act(() => {
        const request = transport.requests.at(-1)!;
        if (flash !== undefined) request.options.onSuccess?.({ props: { flash } });
        request.options.onFinish?.();
    });
}

describe('manager End session recovery', () => {
    it('validates required and server-length reasons before submitting', () => {
        render(<EndSessionDialog {...props} />);
        submit();
        expect(screen.getByText('Enter a reason for ending this session.')).toBeVisible();
        expect(screen.getByRole('textbox', { name: 'Reason' })).toHaveFocus();
        enterReason('x'.repeat(1001));
        submit();
        expect(screen.getByText('Keep the reason to 1,000 characters or fewer.')).toBeVisible();
        expect(transport.requests).toHaveLength(0);
    });

    it('reserves one request and prevents close or reason changes while sending', () => {
        const onClose = vi.fn();
        render(<EndSessionDialog {...props} onClose={onClose} />);
        enterReason();
        const button = screen.getByRole('button', { name: 'End session' });
        act(() => {
            button.click();
            button.click();
        });
        expect(transport.requests).toHaveLength(1);
        expect(transport.requests[0]).toMatchObject({
            url: '/attendance/sessions/8/end',
            data: { reason: 'Manager confirmed the worker has finished.' },
        });
        expect(screen.getByRole('textbox', { name: 'Reason' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(onClose).not.toHaveBeenCalled();
    });

    it('shows the returned payroll follow-up and waits for explicit close', () => {
        const onClose = vi.fn();
        render(<EndSessionDialog {...props} onClose={onClose} />);
        enterReason();
        submit();
        finish({ attendance_result: result });
        expect(screen.getByRole('status')).toHaveTextContent(
            'Attendance was saved, but the timesheet was not changed.',
        );
        expect(screen.getByRole('heading', { name: 'Session closed' })).toBeVisible();
        expect(onClose).not.toHaveBeenCalled();
        expect(screen.queryByRole('button', { name: 'End session' })).toBeNull();
        // The standard corner close and footer Close both use the same guarded close.
        fireEvent.click(screen.getAllByRole('button', { name: 'Close' }).at(-1)!);
        expect(onClose).toHaveBeenCalledOnce();
    });

    it.each([
        { info: 'This session was already closed.' },
        { success: 'Session ended.' },
        { attendance_result: { ...result, session_id: 9 } },
        { attendance_result: { ...result, shift_id: 99 } },
        undefined,
    ])('holds an unconfirmed response without discarding or resending: %j', (flash) => {
        render(<EndSessionDialog {...props} />);
        enterReason('Keep this audit reason');
        submit();
        finish(flash);
        expect(screen.getByRole('alert')).toHaveTextContent('could not be confirmed');
        expect(screen.getByRole('textbox', { name: 'Reason' })).toHaveValue('Keep this audit reason');
        expect(screen.getByRole('button', { name: 'End session' })).toBeDisabled();
        expect(screen.getByRole('link', { name: /Check attendance/ })).toHaveAttribute('target', '_blank');
        expect(transport.requests).toHaveLength(1);
    });

    it('retains the reason and allows correction after server validation', () => {
        render(<EndSessionDialog {...props} />);
        enterReason();
        submit();
        act(() => {
            transport.requests[0].options.onError?.({ reason: 'Please explain why.' });
            transport.requests[0].options.onFinish?.();
        });
        expect(screen.getByRole('alert')).toHaveTextContent('Please explain why.');
        expect(screen.getByRole('textbox', { name: 'Reason' })).not.toBeDisabled();
        enterReason('Updated reason');
        submit();
        expect(transport.requests).toHaveLength(2);
    });

    it('uses the existing dirty-close confirmation', () => {
        const onClose = vi.fn();
        render(<EndSessionDialog {...props} onClose={onClose} />);
        enterReason();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(onClose).not.toHaveBeenCalled();
        expect(screen.getByRole('alertdialog')).toHaveTextContent('Discard your entries?');
        fireEvent.click(screen.getByRole('button', { name: 'Keep this form open' }));
        expect(screen.getByRole('textbox', { name: 'Reason' })).not.toHaveValue('');
    });

    it('withdraws private content and cannot revive after access returns', () => {
        const view = render(<EndSessionDialog {...props} />);
        enterReason('Private audit reason');
        submit();
        view.rerender(<EndSessionDialog {...props} canManage={false} />);
        finish({ attendance_result: result });
        expect(screen.queryByRole('textbox')).toBeNull();
        expect(screen.queryByText('Private audit reason')).toBeNull();
        expect(screen.queryByRole('heading', { name: /Aroha/ })).toBeNull();
        view.rerender(<EndSessionDialog {...props} />);
        expect(screen.getByRole('heading', { name: 'Session unavailable' })).toBeVisible();
        expect(screen.queryByRole('button', { name: 'End session' })).toBeNull();
        expect(transport.requests).toHaveLength(1);
    });

    it.each([
        { ...props, viewerId: 3 },
        { ...props, session: { ...session, id: 9, user_name: 'Another worker' } },
    ])('does not retarget a mounted command or its late response', (next) => {
        const view = render(<EndSessionDialog {...props} />);
        enterReason();
        submit();
        view.rerender(<EndSessionDialog {...next} />);
        finish({ attendance_result: result });
        expect(screen.getByRole('heading', { name: 'Session unavailable' })).toBeVisible();
        expect(screen.queryByRole('status')).toBeNull();
        expect(transport.requests).toHaveLength(1);
    });

    it('retains an uncertain synchronous transport failure without retry', () => {
        transport.throwNext = true;
        render(<EndSessionDialog {...props} />);
        enterReason('Do not lose this');
        submit();
        expect(screen.getByRole('alert')).toHaveTextContent('could not be confirmed');
        expect(screen.getByRole('textbox', { name: 'Reason' })).toHaveValue('Do not lose this');
        expect(screen.getByRole('button', { name: 'End session' })).toBeDisabled();
    });

    it('shows the recorded end in the supplied work timezone', () => {
        render(<EndSessionDialog {...props} timezone="UTC" />);
        enterReason();
        submit();
        finish({ attendance_result: result });
        expect(screen.getByRole('status')).toHaveTextContent(/4:00\s*am/i);
    });

    it('holds a cancelled request without claiming rollback', () => {
        render(<EndSessionDialog {...props} />);
        enterReason('Keep the pending reason');
        submit();
        act(() => {
            transport.requests[0].options.onCancel?.();
            transport.requests[0].options.onFinish?.();
        });
        expect(screen.getByRole('alert')).toHaveTextContent('may already have saved');
        expect(screen.getByRole('button', { name: 'End session' })).toBeDisabled();
        expect(screen.getByRole('textbox', { name: 'Reason' })).toHaveValue('Keep the pending reason');
        expect(transport.requests).toHaveLength(1);
    });

    it('accepts a valid trimmed reason at the server length boundary', () => {
        render(<EndSessionDialog {...props} />);
        enterReason(`  ${'x'.repeat(1000)}  `);
        submit();
        expect(transport.requests).toHaveLength(1);
        expect(transport.requests[0].data).toEqual({ reason: 'x'.repeat(1000) });
    });

    it('withdraws an idle target removed by a current board refresh', () => {
        const view = render(<EndSessionDialog {...props} />);
        enterReason('Private retained reason');
        view.rerender(<EndSessionDialog {...props} currentSession={null} />);
        expect(screen.queryByRole('textbox')).toBeNull();
        expect(screen.queryByRole('heading', { name: /Aroha/ })).toBeNull();
        expect(screen.queryByRole('button', { name: 'End session' })).toBeNull();
        expect(screen.getByRole('alert')).toHaveTextContent('no longer available in this view');
        view.rerender(<EndSessionDialog {...props} />);
        expect(screen.getByRole('heading', { name: 'Session unavailable' })).toBeVisible();
        expect(transport.requests).toHaveLength(0);
    });

    it('keeps the pending owner when a successful refresh removes the closed row', () => {
        const onClose = vi.fn();
        const view = render(<EndSessionDialog {...props} onClose={onClose} />);
        enterReason();
        submit();
        view.rerender(
            <EndSessionDialog {...props} currentSession={null} onClose={onClose} />,
        );
        expect(screen.queryByRole('textbox')).toBeNull();
        expect(onClose).not.toHaveBeenCalled();
        finish({ attendance_result: result });
        expect(screen.getByRole('heading', { name: 'Session closed' })).toBeVisible();
        expect(screen.getByRole('status')).toHaveTextContent('timesheet was not changed');
        expect(onClose).not.toHaveBeenCalled();
        expect(transport.requests).toHaveLength(1);
    });

    it('does not revive a missing target after an unconfirmed response', () => {
        const view = render(<EndSessionDialog {...props} />);
        enterReason();
        submit();
        view.rerender(<EndSessionDialog {...props} currentSession={null} />);
        finish();
        view.rerender(<EndSessionDialog {...props} />);
        expect(screen.getByRole('heading', { name: 'Session unavailable' })).toBeVisible();
        expect(screen.queryByRole('textbox')).toBeNull();
        expect(transport.requests).toHaveLength(1);
    });

    it('retires a same-session shift change instead of rebinding its expectation', () => {
        const view = render(<EndSessionDialog {...props} />);
        enterReason();
        submit();
        view.rerender(
            <EndSessionDialog
                {...props}
                currentSession={{ ...session, shift_id: 99 }}
            />,
        );
        finish({ attendance_result: { ...result, shift_id: 99 } });
        expect(screen.getByRole('heading', { name: 'Session unavailable' })).toBeVisible();
        expect(screen.queryByRole('status')).toBeNull();
        expect(transport.requests).toHaveLength(1);
    });

    it.each([false, true])(
        'keeps a withdrawn pending form hidden when the row returns; valid receipt=%s',
        (confirmed) => {
            const view = render(<EndSessionDialog {...props} />);
            enterReason('Private reason must not revive');
            submit();
            view.rerender(<EndSessionDialog {...props} currentSession={null} />);
            view.rerender(<EndSessionDialog {...props} />);
            expect(screen.queryByRole('textbox')).toBeNull();
            expect(screen.queryByRole('heading', { name: /Aroha/ })).toBeNull();
            finish(confirmed ? { attendance_result: result } : undefined);
            expect(screen.queryByRole('textbox')).toBeNull();
            expect(screen.queryByRole('button', { name: 'End session' })).toBeNull();
            expect(
                screen.getByRole('heading', {
                    name: confirmed ? 'Session closed' : 'Session unavailable',
                }),
            ).toBeVisible();
            expect(transport.requests).toHaveLength(1);
        },
    );

    it('uses the confirmation width and resets the body after an explicit close/reopen', () => {
        const view = render(<EndSessionDialog {...props} />);
        expect(screen.getByRole('dialog')).toHaveStyle({
            width: 'min(92vw, 480px)',
            maxWidth: 'min(92vw, 480px)',
        });
        enterReason('A previous draft');
        view.rerender(<EndSessionDialog {...props} open={false} session={null} />);
        view.rerender(<EndSessionDialog {...props} />);
        expect(screen.getByRole('textbox', { name: 'Reason' })).toHaveValue('');
        expect(screen.getByRole('button', { name: 'End session' })).not.toBeDisabled();
        expect(transport.requests).toHaveLength(0);
    });
});
