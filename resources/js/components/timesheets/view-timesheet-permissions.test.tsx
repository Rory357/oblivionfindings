import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import ViewTimesheetDialog, {
    type ViewTimesheetRow,
} from './view-timesheet-dialog';
const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    router: { post },
    usePage: () => ({ props: { auth: { user: { id: 7 }, can: {} } } }),
}));
const row: ViewTimesheetRow = {
    id: 51,
    work_date: '2026-10-05',
    starts_at: '2026-10-05T00:00:00Z',
    ends_at: '2026-10-05T01:00:00Z',
    break_minutes: 0,
    status: 'submitted',
    can_mutate: true,
};
beforeEach(() => post.mockReset());
it('retains a typed review reason but does not submit it after permission changes', () => {
    const { rerender } = render(
        <ViewTimesheetDialog
            open
            timesheet={row}
            canApprove
            onOpenChange={() => {}}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Return for changes' }));
    fireEvent.change(
        screen.getByRole('textbox', { name: 'What needs changing?' }),
        { target: { value: 'Please check the recorded break.' } },
    );
    rerender(
        <ViewTimesheetDialog
            open
            timesheet={row}
            canApprove={false}
            onOpenChange={() => {}}
        />,
    );
    expect(
        screen.getByRole('textbox', { name: 'What needs changing?' }),
    ).toHaveValue('Please check the recorded break.');
    fireEvent.click(screen.getByRole('button', { name: 'Return to staff' }));
    expect(post).not.toHaveBeenCalled();
    expect(screen.getByText(/This action is no longer available/)).toBeTruthy();
});
it('withholds draft submission when a refreshed caller explicitly denies it', () => {
    render(
        <ViewTimesheetDialog
            open
            timesheet={{ ...row, status: 'draft' }}
            canSubmit={false}
            onOpenChange={() => {}}
        />,
    );
    expect(
        screen.queryByRole('button', { name: 'Submit for approval' }),
    ).toBeNull();
});
it('retains the permitted scoped direct-view draft submission path', async () => {
    render(
        <ViewTimesheetDialog
            open
            timesheet={{ ...row, status: 'draft' }}
            onOpenChange={() => {}}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Submit for approval' }),
    );
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Submit for approval' }),
    );
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(post.mock.calls[0][0]).toBe('/operations/timesheets/51/submit');
});

it('describes the synthetic no-client fallback without inventing an individual allocation', () => {
    const { rerender } = render(
        <ViewTimesheetDialog
            open
            onOpenChange={() => {}}
            timesheet={{
                ...row,
                client_allocations: [
                    { client_id: 0, hours: 1.25, allocation_method: 'single' },
                ],
            }}
        />,
    );
    expect(
        screen.getByText('Not allocated to an individual client'),
    ).toBeTruthy();
    expect(screen.queryByText('Client #0')).toBeNull();
    expect(screen.queryByText('single')).toBeNull();
    expect(screen.queryByText(/100%/)).toBeNull();
    rerender(
        <ViewTimesheetDialog
            open
            onOpenChange={() => {}}
            timesheet={{
                ...row,
                client_allocations: [
                    {
                        client_id: 4,
                        client_name: 'Aroha',
                        hours: 1.25,
                        allocation_method: 'single',
                    },
                ],
            }}
        />,
    );
    expect(screen.getByText('Aroha')).toBeTruthy();
    expect(screen.getByText(/100%/)).toBeTruthy();
    expect(screen.getByText('single')).toBeTruthy();
});
