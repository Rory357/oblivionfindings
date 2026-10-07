import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { webcrypto } from 'node:crypto';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import CreateTimesheetDialog, {
    type ShiftOption,
} from './create-timesheet-dialog';
import EditTimesheetDialog from './edit-timesheet-dialog';
const { post, put } = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    router: { post, put },
    usePage: () => ({ props: { auth: { user: { id: 7 }, can: {} } } }),
}));
vi.mock('@/components/fleet-assets/maintenance/date-picker', () => ({
    DatePicker: ({
        id,
        label,
        value,
        onChange,
    }: {
        id: string;
        label: string;
        value: string;
        onChange: (value: string) => void;
    }) => (
        <input
            id={id}
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
}));
vi.mock('@/components/fleet-assets/maintenance/time-picker', () => ({
    TimePicker: ({
        id,
        label,
        value,
        onChange,
    }: {
        id: string;
        label: string;
        value: string;
        onChange: (value: string) => void;
    }) => (
        <input
            id={id}
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
}));
const shift: ShiftOption = {
    id: 77,
    starts_at: '2026-10-04T20:17:42Z',
    ends_at: '2026-10-05T04:47:42Z',
    client: { id: 4, first_name: 'Aroha', last_name: 'Example' },
    location: 'Kowhai House',
    shift_type: 'standard',
    status: 'completed',
    service_context: 'Residential',
    expected_break_minutes: 30,
    is_sleepover: false,
    is_on_call: false,
    client_id: 4,
    tasks: [{ id: 9, label: 'Read handover', completed: false, minutes: 10 }],
};
const row = {
    id: 51,
    work_date: '2026-10-05',
    starts_at: shift.starts_at,
    ends_at: shift.ends_at,
    break_minutes: 30,
    status: 'draft',
    notes: 'Original note',
    allowance_notes: 'Recorded allowance',
    mileage_km: 2.5,
    is_residential_billable: true,
    staff: { id: 7, name: 'Worker' },
    client: shift.client,
};
const close = vi.fn();
beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('crypto', { subtle: webcrypto.subtle });
});
afterEach(() => vi.unstubAllGlobals());
function create(canSubmit = false) {
    return render(
        <CreateTimesheetDialog
            open
            onOpenChange={close}
            shifts={[shift]}
            clients={[]}
            sites={[]}
            initialShiftId={77}
            canCreate
            canSubmit={canSubmit}
        />,
    );
}
function hours() {
    fireEvent.click(screen.getByRole('button', { name: 'Continue to hours' }));
}
function review() {
    fireEvent.click(screen.getByRole('button', { name: 'Review timesheet' }));
}
it('opens shift dates in worker time and preserves exact original seconds through transport', async () => {
    create();
    hours();
    expect(screen.getByLabelText('Work date')).toHaveValue('2026-10-05');
    expect(screen.getByLabelText('Start time')).toHaveValue('09:17');
    expect(screen.getByLabelText('End time')).toHaveValue('17:47');
    expect(screen.getByText(/does not save task progress/)).toBeTruthy();
    review();
    fireEvent.click(screen.getByRole('button', { name: 'Save as draft' }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(post.mock.calls[0][1]).toMatchObject({
        shift_id: 77,
        work_date: '2026-10-05',
        starts_at: '2026-10-04T20:17:42.000Z',
        ends_at: '2026-10-05T04:47:42.000Z',
        submit: false,
        tasks: [{ id: 9, included: true, completed: false }],
    });
});
it('never offers create-and-submit without exact authority', () => {
    create();
    hours();
    review();
    expect(
        screen.queryByRole('button', { name: 'Save and submit' }),
    ).toBeNull();
    expect(screen.getByRole('button', { name: 'Save as draft' })).toBeEnabled();
});
it('requires an explicit later end date and retains the invalid clocks for correction', async () => {
    create();
    hours();
    fireEvent.change(screen.getByLabelText('End time'), {
        target: { value: '09:17' },
    });
    review();
    expect(screen.getByRole('alert').textContent).toContain(
        'End must be after start',
    );
    expect(post).not.toHaveBeenCalled();
    expect(screen.getByLabelText('End time')).toHaveValue('09:17');
    fireEvent.change(screen.getByLabelText('End date'), {
        target: { value: '2026-10-06' },
    });
    review();
    fireEvent.click(screen.getByRole('button', { name: 'Save as draft' }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(post.mock.calls[0][1].ends_at).toBe('2026-10-05T20:17:00.000Z');
});
it('keeps all edit fields and sends unsaved changes through one atomic resubmit', async () => {
    render(
        <EditTimesheetDialog
            open
            timesheet={row}
            clients={[]}
            canEdit
            canSubmit
            onOpenChange={close}
        />,
    );
    hours();
    expect(screen.getByLabelText('Allowance notes')).toHaveValue(
        'Recorded allowance',
    );
    expect(screen.getByLabelText('Mileage (km)')).toHaveValue(2.5);
    fireEvent.change(screen.getByLabelText('Mileage (km)'), {
        target: { value: '1.005' },
    });
    expect(screen.getByLabelText('Residential billable')).toBeChecked();
    fireEvent.change(screen.getByLabelText('Notes'), {
        target: { value: 'Edited before submit' },
    });
    fireEvent.change(screen.getByLabelText('End time'), {
        target: { value: '18:17' },
    });
    review();
    expect(screen.getByText('1.01 km')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Save and submit' }));
    expect(screen.getByRole('alertdialog')).toBeTruthy();
    fireEvent.click(
        screen.getAllByRole('button', { name: 'Save and submit' }).at(-1)!,
    );
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(post.mock.calls[0][0]).toBe('/operations/timesheets/51/resubmit');
    expect(post.mock.calls[0][1]).toMatchObject({
        notes: 'Edited before submit',
        allowance_notes: 'Recorded allowance',
        mileage_km: '1.01',
        is_residential_billable: true,
        ends_at: '2026-10-05T05:17:00.000Z',
    });
    expect(put).not.toHaveBeenCalled();
});
it('retains edited content when the current record or permission is refreshed', () => {
    const { rerender } = render(
        <EditTimesheetDialog
            open
            timesheet={row}
            clients={[]}
            canEdit
            onOpenChange={close}
        />,
    );
    hours();
    fireEvent.change(screen.getByLabelText('Notes'), {
        target: { value: 'Unsaved explanation' },
    });
    rerender(
        <EditTimesheetDialog
            open
            timesheet={{ ...row, notes: 'Different server note' }}
            clients={[]}
            canEdit={false}
            onOpenChange={close}
        />,
    );
    expect(screen.getByLabelText('Notes')).toHaveValue('Unsaved explanation');
    expect(screen.getByLabelText('Notes')).toBeDisabled();
    expect(post).not.toHaveBeenCalled();
    expect(put).not.toHaveBeenCalled();
});
it('keeps the draft and blocks another save after an unconfirmed redirect', async () => {
    create();
    hours();
    fireEvent.change(screen.getByLabelText('Notes'), {
        target: { value: 'Keep this draft' },
    });
    review();
    fireEvent.click(screen.getByRole('button', { name: 'Save as draft' }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    const options = post.mock.calls[0][2];
    act(() => {
        options.onSuccess({
            props: { flash: { success: 'Timesheet created.' } },
        });
        options.onFinish();
    });
    expect(screen.getByText(/result could not be confirmed/)).toBeTruthy();
    expect(
        screen.getByRole('link', { name: /Check current timesheets/ }),
    ).toHaveAttribute('target', '_blank');
    expect(
        screen.getByRole('button', { name: 'Save as draft' }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(screen.getByLabelText('Notes')).toHaveValue('Keep this draft');
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.getByText('Close this unconfirmed attempt?')).toBeTruthy();
    expect(close).not.toHaveBeenCalled();
});
it('asks before discarding an edit and preserves entries when keeping the draft', () => {
    render(
        <EditTimesheetDialog
            open
            timesheet={row}
            clients={[]}
            canEdit
            onOpenChange={close}
        />,
    );
    hours();
    fireEvent.change(screen.getByLabelText('Notes'), {
        target: { value: 'Do not lose this' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
    expect(screen.getByLabelText('Notes')).toHaveValue('Do not lose this');
    expect(close).not.toHaveBeenCalled();
});
it('lets a field rejection be corrected without resetting the form', async () => {
    create();
    hours();
    fireEvent.change(screen.getByLabelText('Notes'), {
        target: { value: 'Retain this' },
    });
    review();
    fireEvent.click(screen.getByRole('button', { name: 'Save as draft' }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    const options = post.mock.calls[0][2];
    act(() => {
        options.onError({ starts_at: 'Source time changed.' });
        options.onFinish();
    });
    expect(screen.getByText('Source time changed.')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(screen.getByLabelText('Notes')).toHaveValue('Retain this');
    expect(screen.getByLabelText('Notes')).toBeEnabled();
});

it.each([
    { user_id: 99 },
    { client_id: 99 },
    { shift_id: 99 },
    { site: { id: 99, name: 'Changed site' } },
])(
    'retains but disables an edit when the current source changes: %j',
    (changed) => {
        const props = {
            open: true,
            timesheet: row,
            clients: [],
            canEdit: true,
            onOpenChange: close,
        };
        const { rerender } = render(<EditTimesheetDialog {...props} />);
        hours();
        fireEvent.change(screen.getByLabelText('Notes'), {
            target: { value: 'For the original record' },
        });
        rerender(
            <EditTimesheetDialog
                {...props}
                timesheet={{ ...row, ...changed }}
            />,
        );
        expect(screen.getByLabelText('Notes')).toHaveValue(
            'For the original record',
        );
        expect(screen.getByLabelText('Notes')).toBeDisabled();
        expect(post).not.toHaveBeenCalled();
        expect(put).not.toHaveBeenCalled();
    },
);
