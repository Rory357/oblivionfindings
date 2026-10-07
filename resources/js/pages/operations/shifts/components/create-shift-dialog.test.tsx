import { Button } from '@/components/ui/button';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { webcrypto } from 'node:crypto';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    CreateShiftDialog,
    isCurrentEditableShift,
    type EditableShift,
} from './create-shift-dialog';
import { useCreateShiftLauncher } from './use-create-shift-launcher';

import {
    shiftSaveHash,
    type ShiftSaveProjection,
} from './use-shift-save-command';
const t = vi.hoisted(() => ({
    actor: 1,
    override: true,
    put: vi.fn(),
    post: vi.fn(),
}));
vi.mock('@inertiajs/react', async () => {
    const React = await vi.importActual<typeof import('react')>('react');
    return {
        router: { post: t.post, put: t.put },
        usePage: () => ({
            props: {
                workerTimezone: 'Pacific/Auckland',
                auth: {
                    user: { id: t.actor },
                    can: { shifts: { overrideEligibility: t.override } },
                },
            },
        }),
        useForm: (initial: Record<string, unknown>) => {
            const [data, setData] = React.useState(initial);
            const [errors, setErrors] = React.useState({});
            return {
                data,
                errors,
                processing: false,
                setData: (
                    key: string | Record<string, unknown>,
                    value?: unknown,
                ) =>
                    typeof key === 'string'
                        ? setData((old) => ({ ...old, [key]: value }))
                        : setData(key),
                clearErrors: () => setErrors({}),
                setError: (key: string, value: string) =>
                    setErrors((old) => ({ ...old, [key]: value })),
            };
        },
    };
});
const current: EditableShift = {
    id: 55,
    actor_id: 1,
    worker_timezone: 'Pacific/Auckland',
    source: {
        shift_id: 55,
        client_id: 10,
        site_id: 2,
        user_id: 7,
        service_context_id: 3,
        shift_series_id: null,
        status: 'scheduled',
    },
    starts_at: '2026-10-04T20:17:00+00:00',
    ends_at: '2026-10-05T04:47:00+00:00',
    status: 'scheduled',
    shift_type: 'standard',
    is_lone_worker: true,
    is_sleepover: false,
    is_on_call: false,
    expected_break_minutes: 45,
    notes: 'Keep the support plan',
    location: 'Community venue',
    coverage_roles: ['driver'],
    required_licence_class: '2',
    required_licence_endorsements: ['P'],
    client: { id: 10 },
    staff: { id: 7 },
    site: { id: 2, name: 'Kowhai House' },
    service_context_id: 3,
    tasks: [
        {
            id: 31,
            label: 'Check overnight notes',
            scheduled_time: '10:17',
            can_edit: true,
        },
        {
            id: 32,
            label: 'Care plan medicine check',
            scheduled_time: '11:00',
            can_edit: false,
        },
    ],
};
const base = {
    open: true,
    onClose: vi.fn(),
    clients: [
        {
            id: 10,
            first_name: 'Ari',
            last_name: 'Kauri',
            site_id: 2,
            service_context_id: 3,
        },
        {
            id: 11,
            first_name: 'Moana',
            last_name: 'Rimu',
            site_id: 4,
            service_context_id: 3,
        },
    ],
    staff: [{ id: 7, name: 'Aroha King' }],
    sites: [
        { id: 2, name: 'Kowhai House' },
        { id: 4, name: 'Rimu House' },
    ],
    serviceContexts: [
        { id: 3, name: 'Residential', type: 'residential', is_active: true },
    ],
    defaultStartsAt: '2026-10-04T20:17:00Z',
    defaultEndsAt: '2026-10-05T04:47:00Z',
};
let editable: EditableShift;
let fetcher: ReturnType<typeof vi.fn>;
beforeEach(() => {
    vi.clearAllMocks();
    t.actor = 1;
    t.override = true;
    editable = structuredClone(current);
    vi.stubGlobal('crypto', webcrypto);
    fetcher = vi.fn(async (url: string) => ({
        ok: true,
        json: async () =>
            String(url).includes('/editable')
                ? editable
                : {
                      is_eligible: true,
                      is_allowed: true,
                      blocked_reasons: [],
                      warning_reasons: [],
                  },
    }));
    vi.stubGlobal('fetch', fetcher);
});
afterEach(async () => {
    cleanup();
    // Radix restores modal focus on the next task, as in shell-focus.test.tsx.
    // Complete that cleanup before opening the next test's nested picker.
    await new Promise((resolve) => window.setTimeout(resolve, 0));
    vi.unstubAllGlobals();
});
async function edit() {
    const view = render(
        <CreateShiftDialog
            {...base}
            initialShift={{ ...current, notes: 'Stale row' }}
        />,
    );
    await screen.findByRole('heading', { name: 'Edit shift #55' });
    return view;
}
function step(name: RegExp) {
    fireEvent.click(screen.getByRole('button', { name }));
}
async function send(editMode = true) {
    step(/Review.*Confirm/);
    const button = screen.getByRole('button', {
        name: editMode ? 'Save changes' : 'Create shift',
    });
    await waitFor(() => expect(button).toBeEnabled());
    fireEvent.click(button);
    await waitFor(() =>
        expect(editMode ? t.put : t.post).toHaveBeenCalledTimes(1),
    );
    return (editMode ? t.put : t.post).mock.calls[0];
}
async function confirm(call: unknown[], changed = true) {
    const payload = call[1] as ShiftSaveProjection;
    const options = call[2] as {
        onSuccess: (page: unknown) => void;
        onFinish: () => void;
    };
    const values = {
        ...payload,
        required_licence_class: payload.required_licence_class ?? null,
        required_licence_endorsements:
            payload.required_licence_endorsements ?? [],
    };
    await act(async () => {
        options.onSuccess({
            props: {
                flash: {
                    shift_result: {
                        action: String(call[0]).endsWith('/55')
                            ? 'update'
                            : 'create',
                        actor_id: 1,
                        shift_id: String(call[0]).endsWith('/55') ? 55 : 56,
                        scope: 'single',
                        source: String(call[0]).endsWith('/55')
                            ? editable.source
                            : null,
                        client_id: values.client_id,
                        site_id: 2,
                        user_id: values.user_id,
                        service_context_id: values.service_context_id,
                        status: values.status,
                        changed,
                        outcome: changed ? 'saved' : 'unchanged',
                        starts_at: values.starts_at,
                        ends_at: values.ends_at,
                        values_hash: await shiftSaveHash(values),
                    },
                },
            },
        });
        options.onFinish();
    });
}
it('requires complete actor-bound editable data and accepts offset instants', () => {
    expect(isCurrentEditableShift(current, 55, 1)).toBe(true);
    for (const bad of [
        { ...current, actor_id: 2 },
        { ...current, is_lone_worker: undefined },
        { ...current, tasks: [{ id: 31, label: 'Missing ownership' }] },
        { ...current, worker_timezone: 'Invalid/Zone' },
        { ...current, source: { ...current.source, shift_id: 56 } },
    ])
        expect(isCurrentEditableShift(bad, 55, 1)).toBe(false);
});
it('loads current fields, preserves exact times and manual task IDs, and protects linked care tasks', async () => {
    await edit();
    expect(fetcher).toHaveBeenCalledWith(
        '/operations/shifts/55/editable',
        expect.objectContaining({
            cache: 'no-store',
            credentials: 'same-origin',
        }),
    );
    expect(
        screen.queryByRole('button', { name: /Repeat weekly/ }),
    ).not.toBeInTheDocument();
    step(/Schedule.*Times/);
    expect(screen.getByRole('button', { name: /^Scheduled/ })).toBeEnabled();
    expect(screen.getByRole('button', { name: /^Scheduled/ })).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    step(/Tasks & notes/);
    expect(screen.getByLabelText(/Handover notes/)).toHaveValue(
        'Keep the support plan',
    );
    expect(screen.getByText('Care plan medicine check')).toBeVisible();
    expect(
        screen.queryByRole('button', { name: 'Remove task 2' }),
    ).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/Handover notes/), {
        target: { value: 'Updated handover' },
    });
    const call = await send();
    expect(call[0]).toBe('/operations/shifts/55');
    expect(call[1]).toMatchObject({
        starts_at: '2026-10-04T20:17:00.000Z',
        ends_at: '2026-10-05T04:47:00.000Z',
        is_lone_worker: true,
        expected_break_minutes: 45,
        coverage_roles: ['driver'],
        required_licence_class: '2',
        required_licence_endorsements: ['P'],
        notes: 'Updated handover',
        tasks: [
            { id: 31, label: 'Check overnight notes', scheduled_time: '10:17' },
        ],
    });
    await confirm(call);
    expect(
        await screen.findByText('Your shift changes were saved.'),
    ).toBeVisible();
    expect(base.onClose).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Done' }));
    expect(base.onClose).toHaveBeenCalledTimes(1);
});
it('retains a nullable break, service context and unassigned worker on edit instead of substituting defaults', async () => {
    editable = {
        ...editable,
        expected_break_minutes: null,
        service_context_id: null,
        staff: null,
        status: 'draft',
        source: {
            ...editable.source!,
            service_context_id: null,
            user_id: null,
            status: 'draft',
        },
    };
    await edit();
    const call = await send();
    expect(call[1]).toMatchObject({
        expected_break_minutes: null,
        service_context_id: null,
        user_id: null,
        status: 'draft',
    });
});
it('does not silently choose a different client for an existing legacy shift without one', async () => {
    editable = {
        ...editable,
        client: null,
        source: { ...editable.source!, client_id: null },
    };
    await edit();
    step(/Review.*Confirm/);
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Save changes' }),
        ).toBeEnabled(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(t.put).not.toHaveBeenCalled();
    expect(screen.getByText('Choose a client')).toBeVisible();
});
it('allows deliberately clearing driving requirements while preserving the remaining fields', async () => {
    await edit();
    step(/Who & where/);
    fireEvent.change(screen.getByLabelText('Required licence class'), {
        target: { value: '' },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Passenger endorsement' }),
    );
    const call = await send();
    expect(call[1]).toMatchObject({
        required_licence_class: null,
        required_licence_endorsements: [],
        is_lone_worker: true,
        coverage_roles: ['driver'],
    });
});
it('keeps all six create steps, advances Continue and sends optional driving requirements', async () => {
    render(<CreateShiftDialog {...base} />);
    expect(screen.getByRole('button', { name: /Repeat weekly/ })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByRole('combobox', { name: 'Person supported' }),
    ).toBeVisible();
    fireEvent.change(screen.getByLabelText('Required licence class'), {
        target: { value: '2' },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Passenger endorsement' }),
    );
    const call = await send(false);
    expect(call[0]).toBe('/operations/shifts');
    expect(call[1]).toMatchObject({
        required_licence_class: '2',
        required_licence_endorsements: ['P'],
        status: 'draft',
        user_id: null,
    });
});
it('omits optional driving keys for ordinary creation and confirms only the committed receipt', async () => {
    render(<CreateShiftDialog {...base} />);
    step(/Schedule.*Times/);
    expect(screen.getByRole('button', { name: /^Draft/ })).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    expect(screen.getByRole('button', { name: /^Scheduled/ })).toBeDisabled();
    expect(screen.getByText('Assign a staff member first.')).toBeVisible();
    const call = await send(false);
    expect(call[1]).not.toHaveProperty('required_licence_class');
    expect(call[1]).not.toHaveProperty('required_licence_endorsements');
    await confirm(call);
    expect(await screen.findByText('The shift was created.')).toBeVisible();
});
it('searches people and follows their site while preserving a manually entered location', async () => {
    render(<CreateShiftDialog {...base} />);
    step(/Who & where/);
    expect(screen.getByLabelText(/Location/)).toHaveValue('Kowhai House');
    fireEvent.click(screen.getByRole('combobox', { name: 'Person supported' }));
    fireEvent.change(
        await screen.findByRole('combobox', {
            name: 'Search person supported',
        }),
        { target: { value: 'Moana' } },
    );
    fireEvent.click(await screen.findByRole('option', { name: /Moana Rimu/ }));
    expect(screen.getByLabelText(/Location/)).toHaveValue('Rimu House');
    fireEvent.change(screen.getByLabelText(/Location/), {
        target: { value: 'Community outing' },
    });
    fireEvent.click(screen.getByRole('combobox', { name: 'Person supported' }));
    fireEvent.click(await screen.findByRole('option', { name: /Ari Kauri/ }));
    expect(screen.getByLabelText(/Location/)).toHaveValue('Community outing');
});
it('blocks an end before the start and retains the schedule step', async () => {
    render(
        <CreateShiftDialog {...base} defaultEndsAt="2026-10-04T19:00:00Z" />,
    );
    step(/Schedule.*Times/);
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByText('End must be after the start')).toBeVisible();
    expect(t.post).not.toHaveBeenCalled();
});
it('allows retry after a denied canonical load without editing stale row fields', async () => {
    fetcher.mockResolvedValueOnce({ ok: false });
    render(<CreateShiftDialog {...base} initialShift={current} />);
    expect(
        await screen.findByText(/The current shift could not be loaded/),
    ).toBeVisible();
    expect(screen.queryByLabelText(/Handover notes/)).not.toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry loading shift' }),
    );
    await screen.findByRole('heading', { name: 'Edit shift #55' });
});
it('closes and hides private fields if the signed-in account changes', async () => {
    const view = await edit();
    step(/Tasks & notes/);
    t.actor = 2;
    view.rerender(<CreateShiftDialog {...base} initialShift={current} />);
    expect(
        screen.queryByDisplayValue('Keep the support plan'),
    ).not.toBeInTheDocument();
    expect(base.onClose).toHaveBeenCalledTimes(1);
});
it('keeps entries and blocks a duplicate request while saving or when only success text returns', async () => {
    await edit();
    const call = await send();
    fireEvent.click(screen.getByRole('button', { name: 'Saving…' }));
    expect(t.put).toHaveBeenCalledTimes(1);
    const options = call[2];
    act(() => {
        options.onSuccess({ props: { flash: { success: 'Shift updated.' } } });
        options.onFinish();
    });
    expect(
        await screen.findByRole('link', { name: 'Open roster to check' }),
    ).toHaveAttribute('target', '_blank');
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
    expect(
        screen.queryByText('Your shift changes were saved.'),
    ).not.toBeInTheDocument();
    step(/Tasks & notes/);
    expect(screen.getByLabelText(/Handover notes/)).toHaveValue(
        'Keep the support plan',
    );
});
it('retains validation errors on the relevant step and allows corrected resubmission', async () => {
    await edit();
    const call = await send();
    act(() => {
        call[2].onError({ location: 'Enter a shorter location' });
        call[2].onFinish();
    });
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Enter a shorter location',
    );
    step(/Who & where/);
    fireEvent.change(screen.getByLabelText(/Location/), {
        target: { value: 'Short' },
    });
    step(/Review.*Confirm/);
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Save changes' }),
        ).toBeEnabled(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(t.put).toHaveBeenCalledTimes(2));
});
it('uses the shared discard guard and retains edits when the user keeps the draft', async () => {
    await edit();
    step(/Tasks & notes/);
    fireEvent.change(screen.getByLabelText(/Handover notes/), {
        target: { value: 'Unfinished change' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.getByRole('alertdialog')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: /Keep editing/ }));
    expect(screen.getByLabelText(/Handover notes/)).toHaveValue(
        'Unfinished change',
    );
    expect(base.onClose).not.toHaveBeenCalled();
});
it('preserves the recurring endpoint and holds duplicate submissions until its response', async () => {
    render(
        <CreateShiftDialog
            {...base}
            defaultRepeatWeekly
            defaultRepeatEndDate="2026-10-19"
        />,
    );
    const call = await send(false);
    expect(call[0]).toBe('/operations/shifts/series');
    expect(call[1]).toMatchObject({
        timezone: 'Pacific/Auckland',
        start_date: '2026-10-05',
        end_date: '2026-10-19',
        starts_time: '09:17',
        ends_time: '17:47',
        by_weekday: ['mon'],
    });
    fireEvent.click(screen.getByRole('button', { name: 'Saving…' }));
    expect(t.post).toHaveBeenCalledTimes(1);
    act(() => {
        call[2].onSuccess({
            props: { flash: { success: 'Recurring shifts created (3).' } },
        });
        call[2].onFinish();
    });
    expect(
        await screen.findByText('Recurring shifts created (3).'),
    ).toBeVisible();
});

it('omits only the new task ID in edit transport while retaining existing manual task identity', async () => {
    await edit();
    step(/Tasks & notes/);
    fireEvent.click(screen.getByRole('button', { name: 'Add' }));
    const entries = screen.getAllByPlaceholderText(/^Task \d+$/);
    fireEvent.change(entries[entries.length - 1], {
        target: { value: 'New manual task' },
    });
    const call = await send();
    const tasks = call[1].tasks;
    expect(tasks[0].id).toBe(31);
    expect(tasks[1]).toEqual({
        label: 'New manual task',
        scheduled_time: null,
    });
    expect(tasks[1]).not.toHaveProperty('id');
});

it('shows current typed eligibility warnings and submits an explicit authorised reason', async () => {
    await edit();
    const call = await send();
    const hash = await shiftSaveHash(call[1]);
    act(() => {
        call[2].onSuccess({
            props: {
                flash: {
                    shift_result: {
                        action: 'update',
                        actor_id: 1,
                        shift_id: 55,
                        scope: 'single',
                        source: current.source,
                        changed: false,
                        outcome: 'not_saved',
                        reason: 'eligibility_warning',
                        values_hash: hash,
                    },
                    eligibility_result: {
                        is_eligible: false,
                        is_allowed: true,
                        blocked_reasons: [],
                        warning_reasons: ['Review fatigue warning'],
                        overrideable_warnings: [
                            {
                                rule: 'fatigue',
                                message: 'Review fatigue warning',
                                overrideable: true,
                            },
                        ],
                    },
                },
            },
        });
        call[2].onFinish();
    });
    expect(await screen.findByText('Review fatigue warning')).toBeVisible();
    expect(
        screen.queryByText('Your shift changes were saved.'),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(
        screen.getByRole('button', { name: 'Confirm and save' }),
    ).toBeDisabled();
    fireEvent.change(screen.getByLabelText(/Reason for override/), {
        target: { value: 'Confirmed safe cover arrangement' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Confirm and save' }));
    await waitFor(() => expect(t.put).toHaveBeenCalledTimes(2));
    expect(t.put.mock.calls[1][1]).toMatchObject({
        override_acknowledged: true,
        override_reason: 'Confirmed safe cover arrangement',
    });
    await confirm(t.put.mock.calls[1]);
    expect(
        await screen.findByText('Your shift changes were saved.'),
    ).toBeVisible();
});

it('uses the fetched roster timezone for creation from another module and preserves the original instants', async () => {
    fetcher.mockImplementation(async () => ({
        ok: true,
        json: async () => ({
            clients: base.clients,
            staff: base.staff,
            serviceContexts: base.serviceContexts,
            workerTimezone: 'America/New_York',
            defaultServiceContextId: null,
            defaultClientId: 10,
            defaultSiteId: 2,
            defaultUserId: null,
            defaultStartsAt: base.defaultStartsAt,
            defaultEndsAt: base.defaultEndsAt,
            defaultRepeatWeekly: false,
            defaultRepeatEndDate: null,
            coverageReservationToken: null,
            coverageContext: null,
        }),
    }));
    function Launcher() {
        const { openWith, dialog } = useCreateShiftLauncher();
        return (
            <>
                <Button onClick={() => void openWith({ client_id: 10 })}>
                    Add from person
                </Button>
                {dialog}
            </>
        );
    }
    render(<Launcher />);
    fireEvent.click(screen.getByRole('button', { name: 'Add from person' }));
    await screen.findByRole('heading', { name: 'Create shift' });
    step(/Schedule.*Times/);
    expect(
        screen.getByText(/All dates and times use America\/New_York/),
    ).toBeVisible();
    expect(
        screen.getByRole('button', { name: 'Start time: 04:17 PM' }),
    ).toBeVisible();
    const call = await send(false);
    expect(call[1]).toMatchObject({
        client_id: 10,
        user_id: null,
        starts_at: '2026-10-04T20:17:00.000Z',
        ends_at: '2026-10-05T04:47:00.000Z',
    });
});

it('waits through eligibility debounce and ignores the old response after details change', async () => {
    type PreviewResponse = {
        ok: boolean;
        json: () => Promise<Record<string, unknown>>;
    };
    const responses: Array<(value: PreviewResponse) => void> = [];
    fetcher.mockImplementation((url: string) =>
        String(url).includes('/editable')
            ? Promise.resolve({ ok: true, json: async () => editable })
            : new Promise((resolve) => responses.push(resolve)),
    );
    await edit();
    step(/Review.*Confirm/);
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
    fireEvent.submit(document.querySelector('#workforce-shift-form')!);
    expect(t.put).not.toHaveBeenCalled();
    await waitFor(() => expect(responses).toHaveLength(1));
    step(/Who & where/);
    fireEvent.change(screen.getByLabelText('Required licence class'), {
        target: { value: '1' },
    });
    step(/Review.*Confirm/);
    await act(async () =>
        responses[0]({
            ok: true,
            json: async () => ({
                is_allowed: false,
                blocked_reasons: ['Stale blocked result'],
                warning_reasons: [],
            }),
        }),
    );
    expect(screen.queryByText('Stale blocked result')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
    await waitFor(() => expect(responses).toHaveLength(2));
    await act(async () =>
        responses[1]({
            ok: true,
            json: async () => ({
                is_eligible: true,
                is_allowed: true,
                blocked_reasons: [],
                warning_reasons: [],
            }),
        }),
    );
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeEnabled();
    expect(t.put).not.toHaveBeenCalled();
});

it('retains a rejected draft and refreshes current eligibility before another attempt', async () => {
    await edit();
    const call = await send();
    fetcher.mockResolvedValue({
        ok: true,
        json: async () => ({
            is_eligible: false,
            is_allowed: true,
            blocked_reasons: [],
            warning_reasons: ['Current workload needs review'],
            overrideable_warnings: [
                {
                    rule: 'fatigue',
                    message: 'Current workload needs review',
                    overrideable: true,
                },
            ],
        }),
    });
    act(() => {
        call[2].onError({
            user_id: 'Staff availability changed. Review before saving.',
        });
        call[2].onFinish();
    });
    expect(base.onClose).not.toHaveBeenCalled();
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Staff availability changed. Review before saving.',
    );
    step(/Review.*Confirm/);
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
    expect(
        await screen.findByText('Current workload needs review'),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(
        screen.getByRole('button', { name: 'Confirm and save' }),
    ).toBeVisible();
    expect(t.put).toHaveBeenCalledTimes(1);
    expect(base.onClose).not.toHaveBeenCalled();
    expect(
        screen.queryByText('Your shift changes were saved.'),
    ).not.toBeInTheDocument();
});
