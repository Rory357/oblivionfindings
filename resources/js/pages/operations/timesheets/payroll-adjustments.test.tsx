import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import PayrollAdjustments, {
    adjustmentChanges,
    adjustmentValue,
    type PayrollAmendment,
} from './payroll-adjustments';
const { get, post, visit, auth } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    visit: vi.fn(),
    auth: { id: 7 },
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { user: { id: auth.id }, can: {} } } }),
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { get, post, visit },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
const amendment: PayrollAmendment = {
    id: 12,
    timesheet_id: 51,
    staff_name: 'Hemi',
    client_name: 'Aroha',
    site_name: 'House A',
    work_date: '2026-10-07',
    original_values: {
        break_minutes: 15,
        notes: 'Original note',
        public_holiday: false,
    },
    proposed_values: {
        break_minutes: 30,
        notes: 'The full correction note must remain visible.',
        public_holiday: true,
    },
    reason: 'An approved correction after checking the recorded break.',
    requested_by: 'Hemi',
    reviewed_by: 'Reviewer',
    reviewed_at: '2026-10-07T00:00:00Z',
    payroll_reference: 'PAY-17',
    timesheet_url: '/operations/timesheets?view=51',
};
const props = {
    canProcess: true,
    evidence: { timezone: 'Pacific/Auckland' },
    amendments: {
        data: [amendment],
        current_page: 1,
        last_page: 2,
        per_page: 20,
        total: 21,
        links: [],
    },
};
const open = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Review #12' }));
const start = () => {
    open();
    fireEvent.click(
        screen.getByRole('button', { name: 'Record external processing' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Record processing' }));
};
beforeEach(() => {
    vi.clearAllMocks();
    auth.id = 7;
});
it('shows complete original and approved values and uses explicit external-processing confirmation', () => {
    render(<PayrollAdjustments {...props} />);
    open();
    const dialog = within(screen.getByRole('dialog'));
    expect(dialog.getByText('Original note')).toBeTruthy();
    expect(
        dialog.getByText('The full correction note must remain visible.'),
    ).toBeTruthy();
    expect(dialog.getByText('Yes')).toBeTruthy();
    expect(dialog.getByText('No')).toBeTruthy();
    fireEvent.click(
        dialog.getByRole('button', { name: 'Record external processing' }),
    );
    expect(screen.getByRole('alertdialog')).toHaveTextContent(
        'It does not change the original timesheet or mark wages as paid.',
    );
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(post).not.toHaveBeenCalled();
});
it('keeps review available while withdrawing an unavailable action', () => {
    const { rerender } = render(<PayrollAdjustments {...props} />);
    open();
    rerender(<PayrollAdjustments {...props} canProcess={false} />);
    expect(
        screen.getByRole('button', { name: 'Record external processing' }),
    ).toBeDisabled();
    expect(
        screen.getByText(/This adjustment or your access has changed/),
    ).toBeTruthy();
    expect(post).not.toHaveBeenCalled();
});
it('does not apply retained review to a changed correction or different signed-in person', () => {
    const { rerender } = render(<PayrollAdjustments {...props} />);
    open();
    rerender(
        <PayrollAdjustments
            {...props}
            amendments={{
                ...props.amendments,
                data: [
                    { ...amendment, proposed_values: { break_minutes: 60 } },
                ],
            }}
        />,
    );
    expect(
        screen.getByRole('button', { name: 'Record external processing' }),
    ).toBeDisabled();
    auth.id = 8;
    rerender(<PayrollAdjustments {...props} />);
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(
        screen.queryByText('The full correction note must remain visible.'),
    ).toBeNull();
});
it('blocks queue navigation and record links while the processing request is in flight', () => {
    render(<PayrollAdjustments {...props} />);
    start();
    expect(post).toHaveBeenCalledTimes(1);
    expect(
        screen.getByRole('button', { name: 'View timesheet' }),
    ).toBeDisabled();
    expect(
        screen.getByRole('button', { name: 'Next', hidden: true }),
    ).toBeDisabled();
    expect(
        screen.getByRole('button', { name: 'Reload queue', hidden: true }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'View timesheet' }));
    expect(visit).not.toHaveBeenCalled();
});
it('shows the committed result when the processed row disappears from the refreshed queue', () => {
    const { rerender } = render(<PayrollAdjustments {...props} />);
    start();
    const callbacks = post.mock.calls[0][2];
    rerender(
        <PayrollAdjustments
            {...props}
            amendments={{
                ...props.amendments,
                data: [],
                total: 0,
                last_page: 1,
            }}
        />,
    );
    act(() => {
        callbacks.onSuccess({
            props: {
                flash: {
                    timesheet_payroll_adjustment_result: {
                        action: 'process_payroll_adjustment',
                        actor_id: 7,
                        amendment_id: 12,
                        timesheet_id: 51,
                        changed: true,
                        outcome: 'recorded_external_processing',
                        processing_method: 'external',
                        applied_at: '2026-10-07T08:17:00.000Z',
                    },
                },
            },
        });
        callbacks.onFinish();
    });
    expect(screen.getByRole('status')).toHaveTextContent(
        'External processing recorded',
    );
    expect(screen.getByRole('status')).toHaveTextContent(
        'payment status is managed separately',
    );
});
it('holds an unconfirmed action and requires an explicit read instead of replaying the write', () => {
    render(<PayrollAdjustments {...props} />);
    start();
    const callbacks = post.mock.calls[0][2];
    act(() => {
        callbacks.onSuccess({ props: { flash: { success: 'Processed' } } });
        callbacks.onFinish();
    });
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Processing may already be recorded',
    );
    expect(
        screen.getByRole('button', { name: 'Record external processing' }),
    ).toBeDisabled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Reload pending adjustments' }),
    );
    expect(get.mock.calls[0].slice(0, 2)).toEqual([
        '/operations/timesheets/payroll-adjustments',
        { page: 1 },
    ]);
    expect(post).toHaveBeenCalledTimes(1);
});
it('paginates authoritative totals and retains the old queue after a failed read', () => {
    render(<PayrollAdjustments {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(get.mock.calls[0][1]).toEqual({ page: 2 });
    act(() => get.mock.calls[0][2].onFinish());
    expect(screen.getByRole('alert')).toHaveTextContent(
        'previous results are still shown',
    );
    expect(screen.getByRole('button', { name: 'Review #12' })).toBeTruthy();
});
it('does not describe an empty permitted queue as everyone processed or paid', () => {
    render(
        <PayrollAdjustments
            {...props}
            amendments={{
                ...props.amendments,
                data: [],
                current_page: 2,
                last_page: 1,
                total: 0,
            }}
        />,
    );
    expect(screen.getByText(/within your permitted sites/)).toBeTruthy();
    expect(
        screen.queryByText('All approved amendments have been processed.'),
    ).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Go to first page' }));
    expect(get.mock.calls[0][1]).toEqual({ page: 1 });
});
it('preserves structured changes and renders date-only values without browser timezone shifts', () => {
    expect(
        adjustmentValue('work_date', '2026-10-07', 'America/Los_Angeles'),
    ).toBe('7 Oct 2026');
    expect(adjustmentValue('custom', { code: 'A' }, 'Pacific/Auckland')).toBe(
        '{"code":"A"}',
    );
    expect(
        adjustmentChanges({
            ...amendment,
            original_values: { notes: null },
            proposed_values: { notes: null, public_holiday: false },
        }),
    ).toEqual([
        {
            key: 'public_holiday',
            label: 'Public holiday',
            before: undefined,
            after: false,
        },
    ]);
});

it('keeps an unconfirmed attempt held across close and reopen until a successful queue read', () => {
    render(<PayrollAdjustments {...props} />);
    start();
    const callbacks = post.mock.calls[0][2];
    act(() => {
        callbacks.onCancel();
        callbacks.onFinish();
    });
    fireEvent.click(screen.getByRole('button', { name: 'Close review' }));
    open();
    expect(
        screen.getByRole('button', { name: 'Record external processing' }),
    ).toBeDisabled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Reload pending adjustments' }),
    );
    act(() => get.mock.calls[0][2].onFinish());
    open();
    expect(
        screen.getByRole('button', { name: 'Record external processing' }),
    ).toBeDisabled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Reload pending adjustments' }),
    );
    act(() => {
        get.mock.calls[1][2].onSuccess();
        get.mock.calls[1][2].onFinish();
    });
    open();
    expect(
        screen.getByRole('button', { name: 'Record external processing' }),
    ).toBeEnabled();
    expect(post).toHaveBeenCalledTimes(1);
});
