import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { createHash, webcrypto } from 'node:crypto';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { TimesheetActionDialog } from './timesheet-action-dialog';
const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    router: { post },
    usePage: () => ({ props: { auth: { user: { id: 7 }, can: {} } } }),
}));
const row = {
    id: 51,
    client_id: 4,
    work_date: '2026-10-05',
    starts_at: '2026-10-04T20:00:00Z',
    ends_at: '2026-10-05T04:00:00Z',
    break_minutes: 30,
    status: 'submitted',
    staff: { id: 8, name: 'Support worker' },
};
const close = vi.fn();
beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('crypto', { subtle: webcrypto.subtle });
});
afterEach(() => vi.unstubAllGlobals());
it('keeps a returned reason when the result cannot be confirmed and never replays', async () => {
    render(
        <TimesheetActionDialog
            open
            action="return"
            record={row}
            canAct
            onOpenChange={close}
        />,
    );
    fireEvent.change(screen.getByLabelText('What needs changing?'), {
        target: { value: '  Please check the break.  ' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Return to staff' }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(post.mock.calls[0][1]).toEqual({
        returned_notes: 'Please check the break.',
    });
    const options = post.mock.calls[0][2];
    act(() => {
        options.onCancel();
        options.onFinish();
    });
    expect(screen.getByLabelText('What needs changing?')).toHaveValue(
        '  Please check the break.  ',
    );
    expect(
        screen.getByRole('button', { name: 'Return to staff' }),
    ).toBeDisabled();
    expect(
        screen.getByRole('link', { name: /Check current timesheet/ }),
    ).toHaveAttribute('href', '/operations/timesheets?view=51');
    expect(close).not.toHaveBeenCalled();
});
it('requires an explanation before returning or rejecting work', () => {
    render(
        <TimesheetActionDialog
            open
            action="reject"
            record={row}
            canAct
            onOpenChange={close}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Reject timesheet' }));
    expect(
        screen.getByText('Explain the decision for the staff member.'),
    ).toBeTruthy();
    expect(post).not.toHaveBeenCalled();
});
it('withdraws authority while preserving the entered decision', () => {
    const { rerender } = render(
        <TimesheetActionDialog
            open
            action="approve"
            record={row}
            canAct
            onOpenChange={close}
        />,
    );
    fireEvent.change(screen.getByLabelText('Decision notes (optional)'), {
        target: { value: 'Draft review' },
    });
    rerender(
        <TimesheetActionDialog
            open
            action="approve"
            record={row}
            canAct={false}
            onOpenChange={close}
        />,
    );
    expect(screen.getByLabelText('Decision notes (optional)')).toHaveValue(
        'Draft review',
    );
    expect(
        screen.getByRole('button', { name: 'Approve timesheet' }),
    ).toBeDisabled();
    expect(post).not.toHaveBeenCalled();
});
it('distinguishes a confirmed prior decision from a newly saved reason', async () => {
    render(
        <TimesheetActionDialog
            open
            action="approve"
            record={row}
            canAct
            onOpenChange={close}
        />,
    );
    fireEvent.change(screen.getByLabelText('Decision notes (optional)'), {
        target: { value: 'New proposed reason' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Approve timesheet' }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    const receipt = {
        action: 'approve',
        actor_id: 7,
        timesheet_id: 51,
        user_id: 8,
        shift_id: null,
        client_id: 4,
        site_id: 6,
        shift_site_id: 6,
        effective_site_id: 6,
        status: 'approved',
        changed: false,
        outcome: 'already_in_state',
        values_hash: createHash('sha256')
            .update(JSON.stringify({ timesheet_id: 51, action: 'approve' }))
            .digest('hex'),
        work_date: row.work_date,
        starts_at: row.starts_at,
        ends_at: row.ends_at,
        break_minutes: 30,
        total_hours: '7.50',
        submitted_by: 8,
        submitted_at: '2026-10-05T05:00:00Z',
        approved_by: 9,
        approved_at: '2026-10-06T05:00:00Z',
        returned_by: null,
        returned_at: null,
        sleepover: false,
        on_call: false,
    };
    const options = post.mock.calls[0][2];
    act(() => {
        options.onSuccess({ props: { flash: { timesheet_result: receipt } } });
        options.onFinish();
    });
    expect(
        screen.getByText(/was already approved. Your new reason was not added/),
    ).toBeTruthy();
    expect(close).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Done' }));
    expect(close).toHaveBeenCalledWith(false);
});
it('will not send a captured reason for a retargeted owner', () => {
    const { rerender } = render(
        <TimesheetActionDialog
            open
            action="return"
            record={row}
            canAct
            onOpenChange={close}
        />,
    );
    fireEvent.change(screen.getByLabelText('What needs changing?'), {
        target: { value: 'Original worker reason' },
    });
    rerender(
        <TimesheetActionDialog
            open
            action="return"
            record={{ ...row, staff: { id: 99, name: 'Different worker' } }}
            canAct
            onOpenChange={close}
        />,
    );
    expect(
        screen.getByRole('button', { name: 'Return to staff' }),
    ).toBeDisabled();
    expect(screen.getByLabelText('What needs changing?')).toHaveValue(
        'Original worker reason',
    );
    expect(post).not.toHaveBeenCalled();
});

it('retains but does not dispatch a reason after the current person changes', () => {
    const { rerender } = render(
        <TimesheetActionDialog
            open
            action="return"
            record={row}
            canAct
            onOpenChange={close}
        />,
    );
    fireEvent.change(screen.getByLabelText('What needs changing?'), {
        target: { value: 'Reason for original person' },
    });
    rerender(
        <TimesheetActionDialog
            open
            action="return"
            record={{ ...row, client_id: 99 }}
            canAct
            onOpenChange={close}
        />,
    );
    expect(
        screen.getByRole('button', { name: 'Return to staff' }),
    ).toBeDisabled();
    expect(screen.getByLabelText('What needs changing?')).toHaveValue(
        'Reason for original person',
    );
    expect(post).not.toHaveBeenCalled();
});
