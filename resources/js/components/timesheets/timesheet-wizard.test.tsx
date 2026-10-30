import { TimesheetWriteScope } from './timesheet-write-scope';
import { TimesheetActionDialog } from './timesheet-action-dialog';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { webcrypto } from 'node:crypto';
import { startTransition } from 'react';
import { flushSync } from 'react-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import CreateTimesheetDialog, {
    type ShiftOption,
} from './create-timesheet-dialog';
import EditTimesheetDialog from './edit-timesheet-dialog';
const { post, put, on, actor } = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn(), actor: { id: 7 },
    on: vi.fn((_name: string, _callback: (event: CustomEvent<{ visit: object }>) => void) => () => {}),
}));
vi.mock('@inertiajs/react', () => ({
    router: { post, put, on },
    usePage: () => ({ props: { auth: { user: { id: actor.id }, can: {} } } }),
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
    actor.id = 7;
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


it.each(['create', 'edit'] as const)('keeps the real %s wizard draft editable after an exact before veto', async (mode) => {
    // Real child and shared Wizard, bounded fake router: no actual HTTP or browser.
    let observer: ((event: CustomEvent<{ visit: object }>) => void) | null = null;
    const cleanupBefore = vi.fn();
    on.mockImplementationOnce((_name, callback) => { observer = callback; return cleanupBefore; });
    const transport = vi.fn();
    const method = mode === 'create' ? post : put;
    method.mockImplementationOnce((_url, _payload, options) => {
        const visit = { method: mode === 'create' ? 'post' : 'put' };
        options.onBefore(visit);
        const event = new CustomEvent('inertia:before', { cancelable: true, detail: { visit } });
        observer!(event);
        event.preventDefault();
        if (!event.defaultPrevented) { transport(); options.onStart(); }
        // Inertia returns without onCancel/onFinish for this before veto.
    });
    if (mode === 'create') create();
    else render(<EditTimesheetDialog open timesheet={row} clients={[]} canEdit onOpenChange={close} />);
    hours();
    fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Keep these entered hours and notes' } });
    review();
    const saveLabel = mode === 'create' ? 'Save as draft' : 'Save changes';
    fireEvent.click(screen.getByRole('button', { name: saveLabel }));
    expect(await screen.findByText(/This action was cancelled before sending/)).toBeVisible();
    expect(screen.getByRole('button', { name: saveLabel })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeEnabled();
    expect(method).toHaveBeenCalledOnce();
    expect(transport).not.toHaveBeenCalled();
    expect(cleanupBefore).toHaveBeenCalledOnce();
    expect(close).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(screen.getByLabelText('Notes')).toHaveValue('Keep these entered hours and notes');
    expect(screen.getByLabelText('Notes')).toBeEnabled();
    review();
    // Only this new deliberate click starts a second request preparation.
    fireEvent.click(screen.getByRole('button', { name: saveLabel }));
    await waitFor(() => expect(method).toHaveBeenCalledTimes(2));
    expect(method.mock.calls[1][1].notes).toBe('Keep these entered hours and notes');
});


it.each(['create-grant', 'submit-grant', 'edit-grant', 'edit-source'] as const)('keeps real Wizard entries but sends nothing when %s changes during preparation', async (kind) => {
    let releaseDigest: ((value: ArrayBuffer) => void) | null = null;
    const digest = vi.fn().mockResolvedValue(new ArrayBuffer(32));
    digest.mockImplementationOnce(() => new Promise<ArrayBuffer>((resolve) => { releaseDigest = resolve; }));
    vi.stubGlobal('crypto', { subtle: { digest } });
    const creating = kind === 'create-grant' || kind === 'submit-grant';
    const rendered = creating ? create(true) : render(<EditTimesheetDialog open timesheet={row} clients={[]} canEdit canSubmit onOpenChange={close} />);
    hours();
    fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Keep this preparation draft' } });
    review();
    if (kind === 'submit-grant') {
        fireEvent.click(screen.getByRole('button', { name: 'Save and submit' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Save and submit' }).at(-1)!);
    } else fireEvent.click(screen.getByRole('button', { name: creating ? 'Save as draft' : 'Save changes' }));
    expect(digest).toHaveBeenCalledOnce();
    expect(post).not.toHaveBeenCalled(); expect(put).not.toHaveBeenCalled();
    if (creating) rendered.rerender(<CreateTimesheetDialog open onOpenChange={close} shifts={[shift]} clients={[]} sites={[]} initialShiftId={77}
        canCreate={kind !== 'create-grant'} canSubmit={kind !== 'submit-grant'} />);
    else rendered.rerender(<EditTimesheetDialog open timesheet={kind === 'edit-source' ? { ...row, client_id: 99 } : row}
        clients={[]} canEdit={kind !== 'edit-grant'} canSubmit onOpenChange={close} />);
    await act(async () => { releaseDigest!(new ArrayBuffer(32)); });
    expect(await screen.findByText(/No request was sent because the record or your access changed/)).toBeVisible();
    expect(post).not.toHaveBeenCalled(); expect(put).not.toHaveBeenCalled();
    expect(close).not.toHaveBeenCalled();
    const saveButton = screen.getByRole('button', { name: creating ? 'Save as draft' : 'Save changes' });
    if (kind === 'submit-grant') {
        expect(saveButton).toBeEnabled();
        expect(screen.queryByRole('button', { name: 'Save and submit' })).not.toBeInTheDocument();
    } else expect(saveButton).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(screen.getByLabelText('Notes')).toHaveValue('Keep this preparation draft');
    // Losing submission only still permits editing; a fresh explicit draft
    // action uses the current separate permission, never the cancelled submit.
    if (kind === 'submit-grant') {
        expect(screen.getByLabelText('Notes')).toBeEnabled();
        review();
        expect(post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Save as draft' }));
        await waitFor(() => expect(post).toHaveBeenCalledOnce());
        expect(post.mock.calls[0][0]).toBe('/operations/timesheets');
        expect(post.mock.calls[0][1]).toMatchObject({ submit: false, shift_id: 77,
            notes: 'Keep this preparation draft' });
        expect(put).not.toHaveBeenCalled();
        expect(close).not.toHaveBeenCalled();
    } else expect(screen.getByLabelText('Notes')).toBeDisabled();
});

function retainedCreate(open: boolean) {
    return <CreateTimesheetDialog open={open} onOpenChange={close}
        shifts={[shift]} clients={[]} sites={[]} initialShiftId={77} canCreate />;
}
function retainedEdit(open: boolean, timesheet: typeof row | null = row) {
    return <EditTimesheetDialog open={open} onOpenChange={close}
        timesheet={timesheet} clients={[]} canEdit />;
}
it.each(['create', 'edit'] as const)('keeps the real %s draft and step through a parent close', (kind) => {
    const element = kind === 'create' ? retainedCreate : retainedEdit;
    const view = render(element(true));
    hours();
    fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Keep across temporary close' } });
    view.rerender(element(false));
    expect(screen.queryByLabelText('Notes')).toBeNull();
    view.rerender(element(true));
    expect(screen.getByLabelText('Notes')).toHaveValue('Keep across temporary close');
    expect(screen.getByRole('button', { name: 'Review timesheet' })).toBeTruthy();
    expect(post).not.toHaveBeenCalled(); expect(put).not.toHaveBeenCalled();
});
it('closes an unknown attempt without discarding it and refuses a second save on reopen', async () => {
    const view = render(retainedCreate(true)); hours();
    fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Original unknown entries' } });
    review(); fireEvent.click(screen.getByRole('button', { name: 'Save as draft' }));
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    const options = post.mock.calls[0][2];
    act(() => { options.onSuccess({ props: { flash: { success: 'generic' } } }); options.onFinish(); });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.getByText(/entries and this unconfirmed attempt stay in this page/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Close form' }));
    expect(close).toHaveBeenCalledWith(false);
    view.rerender(retainedCreate(false)); view.rerender(retainedCreate(true));
    expect(screen.getByText(/result could not be confirmed/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Save as draft' })).toBeDisabled();
    expect(screen.getByRole('link', { name: /Check current timesheets/ })).toHaveAttribute('target', '_blank');
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(screen.getByLabelText('Notes')).toHaveValue('Original unknown entries');
    expect(post).toHaveBeenCalledOnce(); expect(put).not.toHaveBeenCalled();
});
it('keeps the live original visit through a parent close and settles it without replay', async () => {
    const view = render(retainedCreate(true)); hours(); review();
    fireEvent.click(screen.getByRole('button', { name: 'Save as draft' }));
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    const options = post.mock.calls[0][2]; view.rerender(retainedCreate(false));
    act(() => options.onFinish()); view.rerender(retainedCreate(true));
    expect(screen.getByText(/result could not be confirmed/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Save as draft' })).toBeDisabled();
    expect(post).toHaveBeenCalledOnce(); expect(put).not.toHaveBeenCalled();
});
it('keeps an edit body through the actual nullable closed wrapper', () => {
    const view = render(retainedEdit(true)); hours();
    fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Nullable wrapper retains me' } });
    view.rerender(retainedEdit(false, null)); expect(screen.queryByLabelText('Notes')).toBeNull();
    view.rerender(retainedEdit(true));
    expect(screen.getByLabelText('Notes')).toHaveValue('Nullable wrapper retains me');
    expect(post).not.toHaveBeenCalled(); expect(put).not.toHaveBeenCalled();
});
it('does not reseed a retained edit from a different target', () => {
    const view = render(retainedEdit(true)); hours();
    fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Private original draft' } });
    view.rerender(retainedEdit(true, { ...row, id: 52, notes: 'Different target' }));
    expect(screen.getByText('Timesheet form unavailable')).toBeTruthy();
    expect(screen.queryByDisplayValue('Private original draft')).toBeNull();
    expect(screen.queryByDisplayValue('Different target')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
    view.rerender(retainedEdit(true));
    expect(screen.getByLabelText('Notes')).toHaveValue('Private original draft');
    expect(post).not.toHaveBeenCalled(); expect(put).not.toHaveBeenCalled();
});
it('withdraws the retained private form from another signed-in actor', () => {
    const view = render(retainedCreate(true)); hours();
    fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Private worker notes' } });
    actor.id = 8; view.rerender(retainedCreate(true));
    expect(screen.getByText('Timesheet form unavailable')).toBeTruthy();
    expect(screen.queryByDisplayValue('Private worker notes')).toBeNull();
    expect(screen.queryByLabelText('Notes')).toBeNull();
    expect(screen.getByRole('dialog').querySelector('[data-wizard-region="progress"]')).toBeNull();
    for (const step of screen.getAllByRole('button', { name: /Kept form/ }))
        expect(step).toBeDisabled();
    actor.id = 7; view.rerender(retainedCreate(true));
    expect(screen.getByLabelText('Notes')).toHaveValue('Private worker notes');
    expect(post).not.toHaveBeenCalled(); expect(put).not.toHaveBeenCalled();
});
it('releases a known unsaved draft only after explicit discard', () => {
    const view = render(retainedCreate(true)); hours();
    fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Deliberately discarded' } });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }));
    expect(close).toHaveBeenCalledWith(false);
    view.rerender(retainedCreate(false)); view.rerender(retainedCreate(true)); hours();
    expect(screen.getByLabelText('Notes')).not.toHaveValue('Deliberately discarded');
    expect(post).not.toHaveBeenCalled(); expect(put).not.toHaveBeenCalled();
});

it('switches a clean externally closed edit to the requested current target', () => {
    const view = render(retainedEdit(true)); hours();
    view.rerender(retainedEdit(false, null));
    view.rerender(retainedEdit(true, { ...row, id: 52, notes: 'Fresh target B' }));
    expect(screen.queryByText('Timesheet form unavailable')).toBeNull();
    hours(); expect(screen.getByLabelText('Notes')).toHaveValue('Fresh target B');
    expect(post).not.toHaveBeenCalled(); expect(put).not.toHaveBeenCalled();
});
it('reopens an untouched create form using the newly requested initial Shift', async () => {
    const nextShift = { ...shift, id: 88 };
    const view = render(retainedCreate(true)); hours();
    view.rerender(retainedCreate(false));
    view.rerender(<CreateTimesheetDialog open onOpenChange={close}
        shifts={[shift, nextShift]} clients={[]} sites={[]} initialShiftId={88} canCreate />);
    hours(); review(); fireEvent.click(screen.getByRole('button', { name: 'Save as draft' }));
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    expect(post.mock.calls[0][1]).toMatchObject({ shift_id: 88, submit: false });
    expect(put).not.toHaveBeenCalled();
});
it('keeps an untouched original edit owner when hashing has already started', async () => {
    let release!: (value: ArrayBuffer) => void;
    const digest = vi.fn(() => new Promise<ArrayBuffer>((resolve) => { release = resolve; }));
    vi.stubGlobal('crypto', { subtle: { digest } });
    const view = render(retainedEdit(true)); hours(); review();
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(digest).toHaveBeenCalledOnce();
    view.rerender(retainedEdit(false, null));
    view.rerender(retainedEdit(true, { ...row, id: 52, notes: 'Must not replace a preparing owner' }));
    expect(screen.getByText('Timesheet form unavailable')).toBeTruthy();
    await act(async () => { release(new ArrayBuffer(32)); });
    expect(post).not.toHaveBeenCalled(); expect(put).not.toHaveBeenCalled();
    view.rerender(retainedEdit(true)); fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(screen.getByLabelText('Notes')).toHaveValue('Original note');
    expect(screen.getByText(/No request was sent because the record or your access changed/)).toBeTruthy();
});
it('keeps an untouched edit owner after real dispatch even when another target is selected', async () => {
    const view = render(retainedEdit(true)); hours(); review();
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(put).toHaveBeenCalledOnce());
    const options = put.mock.calls[0][2];
    view.rerender(retainedEdit(false, null));
    view.rerender(retainedEdit(true, { ...row, id: 52, notes: 'Must not replace a dispatched owner' }));
    expect(screen.getByText('Timesheet form unavailable')).toBeTruthy();
    act(() => options.onFinish()); view.rerender(retainedEdit(true));
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
    expect(screen.getByText(/record or signed-in user has changed/)).toBeTruthy();
    expect(put).toHaveBeenCalledOnce(); expect(post).not.toHaveBeenCalled();
});
it('does not reset an unknown create attempt merely because its fields are untouched', async () => {
    const view = render(retainedCreate(true)); hours(); review();
    fireEvent.click(screen.getByRole('button', { name: 'Save as draft' }));
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    const options = post.mock.calls[0][2]; act(() => options.onFinish());
    view.rerender(retainedCreate(false)); view.rerender(retainedCreate(true));
    expect(screen.getByText(/result could not be confirmed/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Save as draft' })).toBeDisabled();
    expect(post).toHaveBeenCalledOnce(); expect(put).not.toHaveBeenCalled();
});

it('protects the preparing owner from a higher-priority target change in the same event batch', async () => {
    let release!: (value: ArrayBuffer) => void;
    const digest = vi.fn(() => new Promise<ArrayBuffer>((resolve) => { release = resolve; }));
    vi.stubGlobal('crypto', { subtle: { digest } });
    const view = render(retainedEdit(true)); hours(); review();
    const save = screen.getByRole('button', { name: 'Save changes' });
    act(() => {
        // Transition state may still be pending when the explicit parent
        // target is committed. The actual command has already started hashing.
        startTransition(() => save.click());
        expect(digest).toHaveBeenCalledOnce();
        flushSync(() => view.rerender(retainedEdit(true, {
            ...row, id: 52, notes: 'Higher-priority replacement must not take the owner',
        })));
    });
    expect(screen.getByText('Timesheet form unavailable')).toBeTruthy();
    expect(screen.queryByDisplayValue('Higher-priority replacement must not take the owner')).toBeNull();
    await act(async () => { release(new ArrayBuffer(32)); });
    expect(post).not.toHaveBeenCalled(); expect(put).not.toHaveBeenCalled();
    view.rerender(retainedEdit(true));
    expect(screen.getByText(/No request was sent because the record or your access changed/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(screen.getByLabelText('Notes')).toHaveValue('Original note');
    expect(close).not.toHaveBeenCalled();
});

it('offers a separate current-record read without discarding a draft after save access is withdrawn', () => {
    const view = render(retainedEdit(true)); hours();
    fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Keep while checking access' } });
    view.rerender(<EditTimesheetDialog open onOpenChange={close}
        timesheet={row} clients={[]} canEdit={false} />);
    expect(screen.getByLabelText('Notes')).toHaveValue('Keep while checking access');
    expect(screen.getByLabelText('Notes')).toBeDisabled();
    const link = screen.getByRole('link', { name: 'Check record and access in a new tab' });
    expect(link).toHaveAttribute('href', '/operations/timesheets?view=51');
    expect(link).toHaveAttribute('target', '_blank');
    expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    expect(link).toHaveClass('frontline-tap');
    expect(screen.getByText(/Before leaving or refreshing this page, check the record/)).toBeTruthy();
    expect(screen.queryByText(/Close and refresh/)).toBeNull();
    expect(post).not.toHaveBeenCalled(); expect(put).not.toHaveBeenCalled();
    expect(close).not.toHaveBeenCalled();
});

it('uses neutral kept-entry guidance when access is withdrawn after an unknown attempted save', async () => {
    const view = render(retainedEdit(true)); hours();
    fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Original uncertain entries' } });
    review(); fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(put).toHaveBeenCalledOnce());
    const options = put.mock.calls[0][2];
    act(() => {
        options.onSuccess({ props: { flash: { success: 'Generic success is not a matched result' } } });
        options.onFinish();
    });
    view.rerender(<EditTimesheetDialog open onOpenChange={close}
        timesheet={row} clients={[]} canEdit={false} />);
    expect(screen.getByText(/Your entries are kept in this form/)).toBeTruthy();
    expect(screen.queryByText(/Your unsaved entries/)).toBeNull();
    expect(screen.getByText(/A missing or inaccessible record does not prove that saving failed/)).toBeTruthy();
    const link = screen.getByRole('link', { name: 'Check record and access in a new tab' });
    expect(link).toHaveAttribute('href', '/operations/timesheets?view=51');
    expect(link).toHaveAttribute('target', '_blank');
    const save = screen.getByRole('button', { name: 'Save changes' });
    expect(save).toBeDisabled(); fireEvent.click(save);
    expect(put).toHaveBeenCalledOnce(); expect(post).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(screen.getByLabelText('Notes')).toHaveValue('Original uncertain entries');
    expect(screen.getByLabelText('Notes')).toBeDisabled();
    expect(close).not.toHaveBeenCalled();
});

it('uses the shared scope in actual Create and keeps field errors visible with an unknown result', async () => {
    const scope = new TimesheetWriteScope(() => 7);
    render(<CreateTimesheetDialog open onOpenChange={close} shifts={[shift]} clients={[]}
        sites={[]} initialShiftId={77} canCreate writeCoordination={{ scope }} />);
    hours(); fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Kept support note' } });
    review(); fireEvent.click(screen.getByRole('button', { name: 'Save as draft' }));
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    const options = post.mock.calls[0][2];
    act(() => { options.onError({ starts_at: 'Choose a valid start time.' }); options.onFinish(); });
    expect(scope.snapshot()).toBe('needs_check');
    expect(screen.getByText('Choose a valid start time.')).toBeTruthy();
    expect(screen.getByText('Kept support note')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Save as draft' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Save as draft' }));
    expect(post).toHaveBeenCalledOnce(); expect(close).not.toHaveBeenCalled();
});
it('passes explicit missing coordination through retained Edit and preserves its edited note without sending', async () => {
    render(<EditTimesheetDialog open timesheet={row} clients={[]} canEdit
        onOpenChange={close} writeCoordination={{ scope: null }} />);
    hours(); fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Edit kept without scope' } });
    review(); fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(screen.getByText(/Saving is unavailable in this view/)).toBeTruthy());
    expect(post).not.toHaveBeenCalled(); expect(put).not.toHaveBeenCalled(); expect(on).not.toHaveBeenCalled();
    expect(screen.getByText('Edit kept without scope')).toBeTruthy(); expect(close).not.toHaveBeenCalled();
});
it('keeps a hidden Create command excluded from a sibling Action with the same scope', async () => {
    const scope = new TimesheetWriteScope(() => 7);
    const form = render(<CreateTimesheetDialog open onOpenChange={close} shifts={[shift]} clients={[]}
        sites={[]} initialShiftId={77} canCreate writeCoordination={{ scope }} />);
    hours(); review(); fireEvent.click(screen.getByRole('button', { name: 'Save as draft' }));
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    const original = post.mock.calls[0][2];
    form.rerender(<CreateTimesheetDialog open={false} onOpenChange={close} shifts={[shift]} clients={[]}
        sites={[]} initialShiftId={77} canCreate writeCoordination={{ scope }} />);
    render(<TimesheetActionDialog open action="return" record={{ ...row, status: 'submitted' }}
        canAct onOpenChange={close} writeCoordination={{ scope }} />);
    fireEvent.change(screen.getByLabelText('What needs changing?'), { target: { value: 'Kept sibling reason' } });
    fireEvent.click(screen.getByRole('button', { name: 'Return to staff' }));
    await waitFor(() => expect(screen.getByText(/Saving is unavailable in this view/)).toBeTruthy());
    expect(post).toHaveBeenCalledOnce(); expect(scope.snapshot()).toBe('busy');
    act(() => { original.onCancel(); original.onFinish(); });
    expect(scope.snapshot()).toBe('needs_check');
    fireEvent.click(screen.getByRole('button', { name: 'Return to staff' }));
    expect(post).toHaveBeenCalledOnce();
    expect(screen.getByLabelText('What needs changing?')).toHaveValue('Kept sibling reason');
});
