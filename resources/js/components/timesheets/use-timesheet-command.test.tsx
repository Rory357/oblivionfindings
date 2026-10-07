import { act, renderHook } from '@testing-library/react';
import { createHash, webcrypto } from 'node:crypto';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    timesheetEditProjection,
    timesheetHash,
    timesheetMileage,
    timesheetReceipt,
    timesheetText,
    useTimesheetCommand,
    type TimesheetExpectation,
    type TimesheetReceipt,
} from './use-timesheet-command';
const { post, put } = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { post, put } }));
const values = {
    work_date: '2026-10-05',
    starts_at: '2026-10-04T20:17:00.000Z',
    ends_at: '2026-10-05T04:47:00.000Z',
    break_minutes: 30,
    mileage_km: '1.01',
    allowance_notes: null,
    public_holiday: false,
    notes: 'Māia 🌿\nLine / two\u2028',
    is_residential_billable: false,
    manual: {
        client_id: 4,
        site_id: null,
        activity_type: 'training',
        sleepover: false,
        on_call: false,
    },
    activity_items: ['First item'],
};
const hash = createHash('sha256').update(JSON.stringify(values)).digest('hex');
const expected: TimesheetExpectation = {
    action: 'create',
    actorId: 7,
    ownerId: 7,
    shiftId: null,
    status: 'draft',
    submitRequested: false,
    values,
};
const receipt: TimesheetReceipt = {
    action: 'create',
    actor_id: 7,
    timesheet_id: 51,
    user_id: 7,
    shift_id: null,
    effective_site_id: 6,
    client_id: 4,
    site_id: null,
    shift_site_id: 6,
    sleepover: false,
    on_call: false,
    status: 'draft',
    changed: true,
    outcome: 'saved',
    values_hash: hash,
    work_date: '2026-10-05',
    starts_at: '2026-10-04T20:17:00Z',
    ends_at: '2026-10-05T04:47:00Z',
    break_minutes: 30,
    total_hours: '8.00',
    submit_requested: false,
    submitted_by: null,
    submitted_at: null,
    approved_by: null,
    approved_at: null,
    returned_by: null,
    returned_at: null,
};
beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('crypto', { subtle: webcrypto.subtle });
});
afterEach(() => vi.unstubAllGlobals());
it('normalizes decimal mileage before dispatch without binary rounding drift', () => {
    expect(timesheetMileage('1.005')).toBe('1.01');
    expect(timesheetMileage('001.999')).toBe('2.00');
    expect(timesheetMileage(0)).toBe('0.00');
    expect(timesheetMileage(null)).toBeNull();
    for (const invalid of ['', '-1', 'NaN', '1e3'])
        expect(() => timesheetMileage(invalid)).toThrow();
});
it('matches Laravel text trimming without removing internal Unicode or line breaks', () => {
    expect(
        timesheetText('\u0085\u200b\u{1d173} A\u200b B\r\nC\u{e0020}\0'),
    ).toBe('A\u200b B\r\nC');
    expect(timesheetText('\u2800')).toBeNull();
});
it('hashes explicit typed persisted intent including Unicode, null, order and line terminators', async () => {
    expect(await timesheetHash(values)).toBe(hash);
    expect(await timesheetHash({ ...values, allowance_notes: '' })).not.toBe(
        hash,
    );
});
it('separates editable intent from canonical linked defaults and immutable edit fields', () => {
    const input = {
        ...values,
        client_id: 4,
        site_id: null,
        activity_type: 'training',
        sleepover: false,
        on_call: false,
    };
    expect(timesheetEditProjection('create', input, false)).toEqual(values);
    const edit = timesheetEditProjection('update', input, false);
    expect(edit.manual).toEqual({
        client_id: 4,
        sleepover: false,
        on_call: false,
    });
    expect(edit).not.toHaveProperty('activity_items');
    expect(timesheetEditProjection('create', input, true).manual).toBeNull();
    expect(
        timesheetEditProjection('create', input, true).activity_items,
    ).toBeNull();
});
it('accepts only the exact requester, action, intent, owner and resulting state', () => {
    expect(
        timesheetReceipt({ timesheet_result: receipt }, expected, hash).status,
    ).toBe('confirmed');
    for (const mutation of [
        { actor_id: 8 },
        { user_id: 8 },
        { action: 'update' },
        { shift_id: 1 },
        { status: 'submitted' },
        { submit_requested: true },
        { values_hash: 'a'.repeat(64) },
        { timesheet_id: 0 },
        { changed: false },
        { outcome: 'already_in_state' },
        { effective_site_id: -1 },
    ]) {
        expect(
            timesheetReceipt(
                { timesheet_result: { ...receipt, ...mutation } },
                expected,
                hash,
            ).status,
        ).toBe('unknown');
    }
    expect(
        timesheetReceipt({ success: 'Timesheet created.' }, expected, hash)
            .status,
    ).toBe('unknown');
    expect(
        timesheetReceipt(
            { warning: 'Partial outcome', timesheet_result: receipt },
            expected,
            hash,
        ).status,
    ).toBe('unknown');
});
it('requires exact record identity for an existing command', () => {
    const intent = { ...expected, action: 'update' as const, timesheetId: 51 };
    const result = { ...receipt, action: 'update' };
    expect(
        timesheetReceipt({ timesheet_result: result }, intent, hash).status,
    ).toBe('confirmed');
    expect(
        timesheetReceipt(
            { timesheet_result: result },
            { ...intent, timesheetId: 52 },
            hash,
        ).status,
    ).toBe('unknown');
});
it('distinguishes an already-reviewed record from saving a new reason', async () => {
    const unchangedHash = await timesheetHash({
        timesheet_id: 51,
        action: 'approve',
    });
    const intent = {
        ...expected,
        action: 'approve' as const,
        timesheetId: 51,
        status: 'approved',
    };
    const result = {
        ...receipt,
        action: 'approve',
        status: 'approved',
        changed: false,
        outcome: 'already_in_state',
        values_hash: unchangedHash,
    };
    expect(
        timesheetReceipt(
            { timesheet_result: result },
            intent,
            hash,
            unchangedHash,
        ).status,
    ).toBe('confirmed');
    expect(
        timesheetReceipt(
            {
                timesheet_result: {
                    ...result,
                    changed: true,
                    outcome: 'saved',
                },
            },
            intent,
            hash,
            unchangedHash,
        ).status,
    ).toBe('unknown');
});
it('owns a write before asynchronous hashing so duplicate clicks dispatch once', async () => {
    const { result } = renderHook(() => useTimesheetCommand('7:new'));
    await act(async () => {
        await Promise.all([
            result.current.submit('post', '/timesheets', {}, expected),
            result.current.submit('post', '/timesheets', {}, expected),
        ]);
    });
    expect(post).toHaveBeenCalledTimes(1);
    expect(result.current.pending).toBe(true);
});
it.each(['finish', 'cancel', 'generic', 'wrong', 'exception'])(
    'holds an uncertain %s result without replay',
    async (failure) => {
        if (failure === 'exception')
            post.mockImplementationOnce(() => {
                throw new Error('transport');
            });
        const { result } = renderHook(() => useTimesheetCommand('7:new'));
        await act(async () => {
            await result.current.submit('post', '/timesheets', {}, expected);
        });
        if (failure !== 'exception') {
            const options = post.mock.calls[0][2];
            act(() => {
                if (failure === 'cancel') options.onCancel();
                if (failure === 'generic')
                    options.onSuccess({
                        props: { flash: { success: 'Saved' } },
                    });
                if (failure === 'wrong')
                    options.onSuccess({
                        props: {
                            flash: {
                                timesheet_result: { ...receipt, actor_id: 99 },
                            },
                        },
                    });
                options.onFinish();
            });
        }
        expect(result.current.outcome?.status).toBe('unknown');
        await act(async () => {
            await result.current.submit('post', '/timesheets', {}, expected);
        });
        expect(post).toHaveBeenCalledTimes(1);
    },
);
it('keeps field rejection editable and permits an explicit corrected attempt', async () => {
    const { result } = renderHook(() => useTimesheetCommand('7:new'));
    await act(async () => {
        await result.current.submit('post', '/timesheets', {}, expected);
    });
    const options = post.mock.calls[0][2];
    act(() => {
        options.onError({ starts_at: 'Choose a time.' });
        options.onFinish();
    });
    expect(result.current.outcome?.status).toBe('rejected');
    await act(async () => {
        await result.current.submit('post', '/timesheets', {}, expected);
    });
    expect(post).toHaveBeenCalledTimes(2);
});
it('does not let an unrelated server error imply a safe retry', async () => {
    const { result } = renderHook(() => useTimesheetCommand('7:new'));
    await act(async () => {
        await result.current.submit('post', '/timesheets', {}, expected);
    });
    const options = post.mock.calls[0][2];
    act(() => {
        options.onError({ timesheet: 'Check current state.' });
        options.onFinish();
    });
    expect(result.current.outcome?.status).toBe('unknown');
});
it('rejects late confirmation after signed-in actor or record changes', async () => {
    const { result, rerender } = renderHook(
        ({ context }) => useTimesheetCommand(context),
        { initialProps: { context: '7:new' } },
    );
    await act(async () => {
        await result.current.submit('post', '/timesheets', {}, expected);
    });
    const options = post.mock.calls[0][2];
    rerender({ context: '8:new' });
    act(() => {
        options.onSuccess({ props: { flash: { timesheet_result: receipt } } });
        options.onFinish();
    });
    expect(result.current.outcome?.status).toBe('unknown');
});
it('keeps the first definitive callback and ignores a contradictory late result', async () => {
    const { result } = renderHook(() => useTimesheetCommand('7:new'));
    await act(async () => {
        await result.current.submit('post', '/timesheets', {}, expected);
    });
    const options = post.mock.calls[0][2];
    act(() => {
        options.onSuccess({ props: { flash: { timesheet_result: receipt } } });
        options.onFinish();
        options.onCancel();
        options.onFinish();
    });
    expect(result.current.outcome?.status).toBe('confirmed');
});

it('holds a linked creation when the selected shift now belongs to another person', () => {
    const linked = { ...expected, shiftId: 77, clientId: 4 };
    const actual = { ...receipt, shift_id: 77, client_id: 5 };
    expect(
        timesheetReceipt({ timesheet_result: actual }, linked, hash).status,
    ).toBe('unknown');
    expect(
        timesheetReceipt(
            { timesheet_result: { ...actual, client_id: 4 } },
            linked,
            hash,
        ).status,
    ).toBe('confirmed');
});

it('holds a linked result whose source-derived work flags differ from the review', () => {
    const linked = {
        ...expected,
        sourceFlags: { sleepover: false, on_call: false },
    };
    expect(
        timesheetReceipt(
            { timesheet_result: { ...receipt, sleepover: true } },
            linked,
            hash,
        ).status,
    ).toBe('unknown');
});

it.each([
    ['submit', 'submitted', 'submitted'],
    ['resubmit', 'submitted', 'submitted'],
    ['create', 'submitted', 'submitted'],
    ['approve', 'approved', 'approved'],
    ['reject', 'rejected', 'approved'],
    ['return', 'returned', 'returned'],
] as const)(
    'requires persisted actor and time for a changed %s',
    (action, status, prefix) => {
        const intent = {
            ...expected,
            action,
            status,
            submitRequested: true,
            ...(action === 'create' ? {} : { timesheetId: 51 }),
        };
        const actual = {
            ...receipt,
            action,
            status,
            submit_requested: true,
            [`${prefix}_by`]: 7,
            [`${prefix}_at`]: '2026-10-07T01:02:03.000Z',
        };
        expect(
            timesheetReceipt({ timesheet_result: actual }, intent, hash).status,
        ).toBe('confirmed');
        for (const mutation of [
            { [`${prefix}_by`]: 8 },
            { [`${prefix}_by`]: null },
            { [`${prefix}_at`]: null },
            { [`${prefix}_at`]: 'not a timestamp' },
            { [`${prefix}_at`]: undefined },
        ])
            expect(
                timesheetReceipt(
                    { timesheet_result: { ...actual, ...mutation } },
                    intent,
                    hash,
                ).status,
            ).toBe('unknown');
    },
);
it('preserves earlier decision attribution for an unchanged review', async () => {
    const unchangedHash = await timesheetHash({
        timesheet_id: 51,
        action: 'approve',
    });
    const intent = {
        ...expected,
        action: 'approve' as const,
        status: 'approved',
        timesheetId: 51,
    };
    const actual = {
        ...receipt,
        action: 'approve',
        status: 'approved',
        changed: false,
        outcome: 'already_in_state',
        values_hash: unchangedHash,
        approved_by: 9,
        approved_at: '2026-10-06T01:02:03.000Z',
    };
    const result = timesheetReceipt(
        { timesheet_result: actual },
        intent,
        hash,
        unchangedHash,
    );
    expect(result.status).toBe('confirmed');
    if (result.status === 'confirmed')
        expect(result.receipt.approved_by).toBe(9);
});
