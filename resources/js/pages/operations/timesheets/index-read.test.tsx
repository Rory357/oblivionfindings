import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import TimesheetsIndex, { type TimesheetRow } from './index';
const { get, post, view, edit } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    view: vi.fn(),
    edit: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { user: { id: 7 }, can: {} } } }),
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { get, post },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/timesheets/create-timesheet-dialog', () => ({
    default: () => null,
}));
vi.mock('@/components/timesheets/edit-timesheet-dialog', () => ({
    default: (props: unknown) => {
        edit(props);
        return null;
    },
}));
vi.mock('@/components/timesheets/view-timesheet-dialog', () => ({
    default: (props: unknown) => {
        view(props);
        return null;
    },
}));
const row: TimesheetRow = {
    id: 51,
    work_date: '2026-10-05',
    starts_at: '2026-10-04T20:00:00Z',
    ends_at: '2026-10-05T04:00:00Z',
    break_minutes: 30,
    status: 'submitted',
    can_mutate: true,
    can_approve: true,
    can_edit: true,
    can_update: true,
    staff: { id: 7, name: 'Hemi' },
    client: { id: 4, first_name: 'Aroha', last_name: 'Example' },
    total_hours: 7.5,
};
const props = {
    timesheets: { data: [row] },
    filters: {
        tab: 'all',
        from: null,
        to: null,
        staff_id: null,
        client_id: null,
        search: '',
        page: 1,
    },
    pagination: { current_page: 1, last_page: 2, total: 51, from: 1, to: 50 },
    lists: { timesheets: { includes_detail_record: false, shown: 50 } },
    evidence: {
        scope: 'permitted_sites',
        checked_at: '2026-10-07T00:00:00Z',
        timezone: 'Pacific/Auckland',
    },
    tabCounts: { all: 51, submitted: 12, returned: 2, approved: 37 },
    heroSummary: {
        week_start: '2026-10-05',
        week_end: '2026-10-11',
        week_number: 41,
        hours_this_week: 7.5,
        hours_target: 0,
        next_payroll_date: null,
        regions_count: null,
        evidence: {
            timezone: 'Pacific/Auckland',
            checked_at: '2026-10-07T00:00:00Z',
            scope: 'permitted_sites',
        },
    },
    isOwnOnlyView: false,
    clients: [],
    sites: [],
    staff: [],
    availableShifts: [],
    canApprove: true,
    canCreate: true,
};
beforeEach(() => {
    get.mockReset();
    post.mockReset();
    view.mockClear();
    edit.mockClear();
    window.history.replaceState({}, '', '/operations/timesheets');
});
it('paginates the full filtered cohort without inventing a date filter', () => {
    render(<TimesheetsIndex {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    expect(get.mock.calls[0][1]).toEqual({ ...props.filters, page: 2 });
    expect(screen.getByText(/Showing 1–50 of 51 timesheets/)).toBeTruthy();
    expect(screen.queryByText(/payroll closes/i)).toBeNull();
    expect(screen.getByText(/0h planned in assigned shifts/)).toBeTruthy();
});
it('meters open their actual destination, preserving the newest typed search', () => {
    render(<TimesheetsIndex {...props} />);
    fireEvent.change(screen.getByRole('searchbox'), {
        target: { value: 'māia' },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'View awaiting approval' }),
    );
    expect(get.mock.calls[0][1]).toEqual({
        ...props.filters,
        tab: 'submitted',
        search: 'māia',
        page: 1,
    });
});
it('keeps authorized deep links outside page counts and opens their original record', () => {
    window.history.replaceState({}, '', '/operations/timesheets?view=900');
    const linked = { ...row, id: 900, status: 'paid' };
    render(
        <TimesheetsIndex
            {...props}
            timesheets={{ data: [linked, row] }}
            lists={{ timesheets: { includes_detail_record: true, shown: 50 } }}
        />,
    );
    expect(
        screen.getByText('Linked timesheet #900 is outside these filters.'),
    ).toBeTruthy();
    expect(view.mock.lastCall?.[0].timesheet.id).toBe(900);
    expect(
        screen.queryByRole('button', { name: 'Open timesheet #900' }),
    ).toBeNull();
    expect(
        screen.getByRole('button', { name: 'Open timesheet #51' }),
    ).toBeTruthy();
});
it('preserves attendance editing denial for a legacy edit link', () => {
    window.history.replaceState({}, '', '/operations/timesheets?edit=51');
    render(
        <TimesheetsIndex
            {...props}
            timesheets={{ data: [{ ...row, attendance_session_id: 22 }] }}
        />,
    );
    expect(view.mock.lastCall?.[0].timesheet.id).toBe(51);
    expect(edit.mock.lastCall?.[0].open).toBe(false);
});
it('mobile cards expose hours, break, source and the same detail action', () => {
    render(<TimesheetsIndex {...props} />);
    const card = screen.getByLabelText('Timesheet 51');
    expect(within(card).getByText('7.50h')).toBeTruthy();
    expect(within(card).getByText('30 min')).toBeTruthy();
    expect(within(card).getByText('Aroha Example')).toBeTruthy();
    fireEvent.click(
        within(card).getByRole('button', { name: 'Open timesheet #51' }),
    );
    expect(view.mock.lastCall?.[0].timesheet.id).toBe(51);
});
it('drops previous-page selections when fresh rows lose review eligibility', () => {
    const { rerender } = render(<TimesheetsIndex {...props} />);
    fireEvent.click(
        screen.getByRole('checkbox', { name: 'Select timesheet 51' }),
    );
    expect(
        screen.getByRole('button', { name: 'Approve 1 selected' }),
    ).toBeTruthy();
    rerender(
        <TimesheetsIndex
            {...props}
            timesheets={{ data: [{ ...row, can_approve: false }] }}
        />,
    );
    expect(
        screen.queryByRole('button', { name: 'Approve 1 selected' }),
    ).toBeNull();
});

it('withdraws commands from an open viewer when refreshed permissions are removed', () => {
    const { rerender } = render(<TimesheetsIndex {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Open timesheet #51' }));
    expect(view.mock.lastCall?.[0].canApprove).toBe(true);
    rerender(
        <TimesheetsIndex
            {...props}
            timesheets={{
                data: [{ ...row, can_approve: false, can_mutate: false }],
            }}
        />,
    );
    expect(view.mock.lastCall?.[0].canApprove).toBe(false);
    rerender(<TimesheetsIndex {...props} timesheets={{ data: [] }} />);
    expect(view.mock.lastCall?.[0].canApprove).toBe(false);
    expect(view.mock.lastCall?.[0].canSubmit).toBe(false);
});
it('retains the captured edit record but removes its edit permission after fresh denial', () => {
    window.history.replaceState({}, '', '/operations/timesheets?edit=51');
    const draft = { ...row, status: 'draft' };
    const { rerender } = render(
        <TimesheetsIndex {...props} timesheets={{ data: [draft] }} />,
    );
    expect(edit.mock.lastCall?.[0].canEdit).toBe(true);
    rerender(
        <TimesheetsIndex
            {...props}
            timesheets={{ data: [{ ...draft, can_edit: false }] }}
        />,
    );
    expect(edit.mock.lastCall?.[0].canEdit).toBe(false);
    expect(edit.mock.lastCall?.[0].timesheet).toBe(draft);
});

it('distinguishes recorded manual activities from linked shift task completion', () => {
    const { rerender } = render(
        <TimesheetsIndex
            {...props}
            timesheets={{
                data: [
                    {
                        ...row,
                        activity_items: ['Team training'],
                        tasks_total: 1,
                        tasks_completed: 1,
                    },
                ],
            }}
        />,
    );
    expect(
        within(screen.getByLabelText('Timesheet 51')).getByText(
            'Activity items: 1 recorded',
        ),
    ).toBeTruthy();
    expect(screen.getByText('1 activity item')).toBeTruthy();
    expect(screen.queryByText(/Recorded shift tasks/)).toBeNull();
    rerender(
        <TimesheetsIndex
            {...props}
            timesheets={{
                data: [
                    {
                        ...row,
                        shift_id: 17,
                        tasks_total: 2,
                        tasks_completed: 1,
                    },
                ],
            }}
        />,
    );
    expect(
        within(screen.getByLabelText('Timesheet 51')).getByText(
            'Recorded shift tasks: 1/2',
        ),
    ).toBeTruthy();
    expect(screen.queryByText(/Activity items:/)).toBeNull();
});

it('provides payroll adjustment navigation only with the current review grant', () => {
    const { rerender } = render(<TimesheetsIndex {...props} />);
    expect(
        screen.getByRole('link', { name: 'Payroll adjustments' }),
    ).toHaveAttribute('href', '/operations/timesheets/payroll-adjustments');
    rerender(<TimesheetsIndex {...props} canApprove={false} />);
    expect(
        screen.queryByRole('link', { name: 'Payroll adjustments' }),
    ).toBeNull();
});
