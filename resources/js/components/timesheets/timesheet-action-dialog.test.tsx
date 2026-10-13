import { TimesheetWriteScope } from './timesheet-write-scope';
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
const { post, on, actor } = vi.hoisted(() => ({ post: vi.fn(), actor: { id: 7 },
    on: vi.fn((_name: string, _callback: (event: CustomEvent<{ visit: object }>) => void) => () => {}),
}));
vi.mock('@inertiajs/react', () => ({
    router: { post, on },
    usePage: () => ({ props: { auth: { user: { id: actor.id }, can: {} } } }),
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
    actor.id = 7;
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


it('keeps the real review reason after a before veto and still withdraws current approval authority', async () => {
    // Actual child controls; this fake transport proves no real endpoint behavior.
    let observer: ((event: CustomEvent<{ visit: object }>) => void) | null = null;
    const cleanupBefore = vi.fn();
    on.mockImplementationOnce((_name, callback) => { observer = callback; return cleanupBefore; });
    const transport = vi.fn();
    post.mockImplementationOnce((_url, _payload, options) => {
        const visit = { method: 'post' };
        options.onBefore(visit);
        const event = new CustomEvent('inertia:before', { cancelable: true, detail: { visit } });
        observer!(event); event.preventDefault();
        if (!event.defaultPrevented) { transport(); options.onStart(); }
    });
    const { rerender } = render(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} />);
    fireEvent.change(screen.getByLabelText('What needs changing?'), { target: { value: 'Please retain this review explanation' } });
    fireEvent.click(screen.getByRole('button', { name: 'Return to staff' }));
    expect(await screen.findByText(/This action was cancelled before sending/)).toBeVisible();
    expect(screen.getByLabelText('What needs changing?')).toHaveValue('Please retain this review explanation');
    expect(screen.getByLabelText('What needs changing?')).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Return to staff' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeEnabled();
    expect(transport).not.toHaveBeenCalled(); expect(post).toHaveBeenCalledOnce();
    expect(cleanupBefore).toHaveBeenCalledOnce(); expect(close).not.toHaveBeenCalled();
    rerender(<TimesheetActionDialog open action="return" record={row} canAct={false} onOpenChange={close} />);
    expect(screen.getByLabelText('What needs changing?')).toHaveValue('Please retain this review explanation');
    expect(screen.getByLabelText('What needs changing?')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Return to staff' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Return to staff' }));
    expect(post).toHaveBeenCalledOnce();
});


it('keeps the real decision reason and sends nothing when action permission disappears during hashing', async () => {
    let releaseDigest: ((value: ArrayBuffer) => void) | null = null;
    const digest = vi.fn().mockResolvedValue(new ArrayBuffer(32));
    digest.mockImplementationOnce(() => new Promise<ArrayBuffer>((resolve) => { releaseDigest = resolve; }));
    vi.stubGlobal('crypto', { subtle: { digest } });
    const { rerender } = render(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} />);
    fireEvent.change(screen.getByLabelText('What needs changing?'), { target: { value: 'Keep the original review reason' } });
    fireEvent.click(screen.getByRole('button', { name: 'Return to staff' }));
    expect(digest).toHaveBeenCalledOnce(); expect(post).not.toHaveBeenCalled();
    rerender(<TimesheetActionDialog open action="return" record={row} canAct={false} onOpenChange={close} />);
    await act(async () => { releaseDigest!(new ArrayBuffer(32)); });
    expect(await screen.findByText(/No request was sent because the record or your access changed/)).toBeVisible();
    expect(post).not.toHaveBeenCalled(); expect(close).not.toHaveBeenCalled();
    expect(screen.getByLabelText('What needs changing?')).toHaveValue('Keep the original review reason');
    expect(screen.getByLabelText('What needs changing?')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Return to staff' })).toBeDisabled();
});

it('keeps the actual scoped Action reason and its field error visible while the result is unknown', async () => {
    const scope = new TimesheetWriteScope(() => 7);
    render(<TimesheetActionDialog open action="return" record={row} canAct
        onOpenChange={close} writeCoordination={{ scope }} />);
    fireEvent.change(screen.getByLabelText('What needs changing?'), { target: { value: 'Original review reason' } });
    fireEvent.click(screen.getByRole('button', { name: 'Return to staff' }));
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    const options = post.mock.calls[0][2];
    act(() => { options.onError({ returned_notes: 'Explain which recorded break needs checking.' }); options.onFinish(); });
    expect(scope.snapshot()).toBe('needs_check');
    expect(screen.getByText('Explain which recorded break needs checking.')).toBeTruthy();
    expect(screen.getByLabelText('What needs changing?')).toHaveValue('Original review reason');
    expect(screen.getByRole('button', { name: 'Return to staff' })).toBeDisabled();
    expect(close).not.toHaveBeenCalled(); expect(post).toHaveBeenCalledOnce();
});

it('retains the actual review reason across an external close and reopen', () => {
    const form = render(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} />);
    fireEvent.change(screen.getByLabelText('What needs changing?'), { target: { value: 'Kept review reason' } });
    form.rerender(<TimesheetActionDialog open={false} action="return" record={row} canAct onOpenChange={close} />);
    expect(screen.queryByLabelText('What needs changing?')).toBeNull();
    form.rerender(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} />);
    expect(screen.getByLabelText('What needs changing?')).toHaveValue('Kept review reason');
    expect(post).not.toHaveBeenCalled();
});
it('keeps an unknown scoped review reason and original hold after closing its confirmation', async () => {
    const scope = new TimesheetWriteScope(() => 7);
    const form = render(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} writeCoordination={{ scope }} />);
    fireEvent.change(screen.getByLabelText('What needs changing?'), { target: { value: 'Original unknown reason' } });
    fireEvent.click(screen.getByRole('button', { name: 'Return to staff' }));
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    const options = post.mock.calls[0][2];
    act(() => { options.onCancel(); options.onFinish(); });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.getByText(/Closing keeps the original reason and attempt/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Close form' }));
    form.rerender(<TimesheetActionDialog open={false} action="return" record={row} canAct onOpenChange={close} writeCoordination={{ scope }} />);
    form.rerender(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} writeCoordination={{ scope }} />);
    expect(screen.getByLabelText('What needs changing?')).toHaveValue('Original unknown reason');
    expect(screen.getByRole('button', { name: 'Return to staff' })).toBeDisabled();
    expect(scope.snapshot()).toBe('needs_check'); expect(post).toHaveBeenCalledOnce();
});
it('retains the closed owner through nullable inputs without disclosing its reason', () => {
    const form = render(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} />);
    fireEvent.change(screen.getByLabelText('What needs changing?'), { target: { value: 'Nullable owner reason' } });
    form.rerender(<TimesheetActionDialog open={false} action={null} record={null} canAct={false} onOpenChange={close} />);
    expect(screen.queryByText('Nullable owner reason')).toBeNull();
    form.rerender(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} />);
    expect(screen.getByLabelText('What needs changing?')).toHaveValue('Nullable owner reason');
    expect(post).not.toHaveBeenCalled();
});
it('withholds a dirty retained owner under another requested review and restores it on return', () => {
    const form = render(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} />);
    fireEvent.change(screen.getByLabelText('What needs changing?'), { target: { value: 'Private original review' } });
    form.rerender(<TimesheetActionDialog open action="reject" record={{ ...row, id: 52 }} canAct onOpenChange={close} />);
    expect(screen.getByText('Timesheet review unavailable')).toBeTruthy();
    expect(screen.queryByLabelText('What needs changing?')).toBeNull();
    expect(screen.queryByText(/#51/)).toBeNull(); expect(screen.queryByText('Private original review')).toBeNull();
    form.rerender(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} />);
    expect(screen.getByLabelText('What needs changing?')).toHaveValue('Private original review'); expect(post).not.toHaveBeenCalled();
});
it('withdraws private reason rendering after the actor changes and restores the original owner only', () => {
    const form = render(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} />);
    fireEvent.change(screen.getByLabelText('What needs changing?'), { target: { value: 'Actor-bound private reason' } });
    actor.id = 8; form.rerender(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} />);
    expect(screen.queryByLabelText('What needs changing?')).toBeNull(); expect(screen.queryByText(/#51/)).toBeNull();
    expect(screen.getByText('Timesheet review unavailable')).toBeTruthy();
    actor.id = 7; form.rerender(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} />);
    expect(screen.getByLabelText('What needs changing?')).toHaveValue('Actor-bound private reason'); expect(post).not.toHaveBeenCalled();
});
it('allows a clean review owner to follow an explicitly selected different record and action', () => {
    const form = render(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} />);
    form.rerender(<TimesheetActionDialog open action="reject" record={{ ...row, id: 52 }} canAct onOpenChange={close} />);
    expect(screen.getByLabelText('Reason for rejection')).toHaveValue('');
    expect(screen.getByRole('button', { name: 'Reject timesheet' })).toBeEnabled();
    expect(screen.queryByText('Timesheet review unavailable')).toBeNull(); expect(post).not.toHaveBeenCalled();
});
it('retains a hidden pending command until its real matching result finishes', async () => {
    const scope = new TimesheetWriteScope(() => 7), reason = 'Hidden original reason';
    const form = render(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} writeCoordination={{ scope }} />);
    fireEvent.change(screen.getByLabelText('What needs changing?'), { target: { value: reason } });
    fireEvent.click(screen.getByRole('button', { name: 'Return to staff' }));
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    const options = post.mock.calls[0][2];
    form.rerender(<TimesheetActionDialog open={false} action="return" record={row} canAct onOpenChange={close} writeCoordination={{ scope }} />);
    const values_hash = createHash('sha256').update(JSON.stringify({ timesheet_id: 51, action: 'return', reason })).digest('hex');
    act(() => {
        options.onSuccess({ props: { flash: { timesheet_result: {
            actor_id: 7, action: 'return', timesheet_id: 51, user_id: 8, client_id: 4,
            shift_id: null, effective_site_id: 6, site_id: 6, shift_site_id: null,
            status: 'returned', changed: true, outcome: 'saved', values_hash,
            work_date: row.work_date, starts_at: row.starts_at, ends_at: row.ends_at,
            break_minutes: row.break_minutes, total_hours: '7.50', sleepover: false, on_call: false,
            submitted_by: null, submitted_at: null, approved_by: null, approved_at: null,
            returned_by: 7, returned_at: '2026-10-05T05:00:00Z',
        } } } }); options.onFinish();
    });
    expect(scope.snapshot()).toBe('idle');
    form.rerender(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} writeCoordination={{ scope }} />);
    expect(screen.getByText(/Timesheet #51 is confirmed returned/)).toBeTruthy(); expect(post).toHaveBeenCalledOnce();
});
it('releases an explicitly discarded never-sent reason and opens a fresh form later', () => {
    const form = render(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} />);
    fireEvent.change(screen.getByLabelText('What needs changing?'), { target: { value: 'Explicitly discarded reason' } });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    fireEvent.click(screen.getByRole('button', { name: 'Close form' }));
    form.rerender(<TimesheetActionDialog open={false} action={null} record={null} canAct={false} onOpenChange={close} />);
    form.rerender(<TimesheetActionDialog open action="return" record={row} canAct onOpenChange={close} />);
    expect(screen.getByLabelText('What needs changing?')).toHaveValue(''); expect(post).not.toHaveBeenCalled();
});
