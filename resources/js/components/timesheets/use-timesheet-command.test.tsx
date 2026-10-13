import { TimesheetWriteScope } from './timesheet-write-scope';
import { act, renderHook } from '@testing-library/react';
import { flushSync } from 'react-dom';
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
const { post, put, on, removeBefore, beforeListeners, transport } = vi.hoisted(() => {
    const beforeListeners = new Set<(event: CustomEvent<{ visit: object }>) => void>();
    const removeBefore = vi.fn();
    return { post: vi.fn(), put: vi.fn(), transport: vi.fn(), beforeListeners, removeBefore,
        on: vi.fn((_name: string, callback: (event: CustomEvent<{ visit: object }>) => void) => {
            beforeListeners.add(callback);
            return () => { beforeListeners.delete(callback); removeBefore(); };
        }),
    };
});
vi.mock('@inertiajs/react', () => ({ router: { post, put, on } }));
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
    beforeListeners.clear();
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


// Bounded Inertia protocol fixture, not a real HTTP or browser acceptance test.
type HarnessVisit = { method: 'post' | 'put'; url: string };
type HarnessOptions = {
    onBefore: (visit: HarnessVisit) => void;
    onStart: () => void;
    onSuccess: (page: { props: { flash: unknown } }) => void;
    onError: (errors: Record<string, string>) => void;
    onCancel: () => void;
    onFinish: (visit?: HarnessVisit) => void;
};
function nextVisit(method: 'post' | 'put', run: (
    visit: HarnessVisit, options: HarnessOptions,
    event: CustomEvent<{ visit: object }>,
) => void) {
    (method === 'post' ? post : put).mockImplementationOnce((url: string, _payload: unknown, options: HarnessOptions) => {
        const visit = { method, url };
        options.onBefore(visit);
        const event = new CustomEvent('inertia:before', { cancelable: true, detail: { visit } });
        run(visit, options, event);
    });
}
function observeBefore(event: CustomEvent<{ visit: object }>) {
    for (const listener of beforeListeners) listener(event);
}

it.each(['post', 'put'] as const)('releases a proved %s before veto without sending or automatic replay', async (method) => {
    const intent = method === 'post' ? expected : { ...expected, action: 'update' as const, timesheetId: 51 };
    const { result } = renderHook(() => useTimesheetCommand('7:51'));
    nextVisit(method, (_visit, _options, event) => {
        observeBefore(event);
        // A later synchronous listener vetoes after our observer has seen it.
        event.preventDefault();
        if (!event.defaultPrevented) transport();
    });
    await act(async () => { await result.current.submit(method, '/timesheets/51', {}, intent); });
    expect(transport).not.toHaveBeenCalled();
    expect(result.current.pending).toBe(false);
    expect(result.current.busy.current).toBe(false);
    expect(result.current.held.current).toBe(false);
    expect(result.current.outcome?.status).toBe('rejected');
    expect(result.current.outcome).toMatchObject({ message: expect.stringContaining('cancelled before sending') });
    expect(removeBefore).toHaveBeenCalledOnce();
    expect(beforeListeners.size).toBe(0);
    expect(method === 'post' ? post : put).toHaveBeenCalledOnce();
    await act(async () => { await result.current.submit(method, '/timesheets/51', {}, intent); });
    expect(method === 'post' ? post : put).toHaveBeenCalledTimes(2);
    expect(result.current.pending).toBe(true);
});

it('keeps an admitted command pending until its real finish callback and never treats a visit argument as no-send', async () => {
    let callbacks: HarnessOptions | null = null, original: HarnessVisit | null = null;
    const { result } = renderHook(() => useTimesheetCommand('7:new'));
    nextVisit('post', (visit, options, event) => {
        original = visit; callbacks = options;
        observeBefore(event);
        transport(); options.onStart();
    });
    await act(async () => { await result.current.submit('post', '/timesheets', {}, expected); });
    expect(transport).toHaveBeenCalledOnce();
    expect(result.current.pending).toBe(true);
    expect(result.current.outcome).toBeNull();
    expect(beforeListeners.size).toBe(0);
    act(() => { callbacks!.onFinish(original!); });
    expect(result.current.pending).toBe(false);
    expect(result.current.outcome?.status).toBe('unknown');
    expect(result.current.held.current).toBe(true);
    await act(async () => { await result.current.submit('post', '/timesheets', {}, expected); });
    expect(post).toHaveBeenCalledOnce();
});

it.each(['missing', 'foreign', 'equal-looking'] as const)('does not borrow a %s before veto as no-send proof', async (kind) => {
    let callbacks: HarnessOptions | null = null, original: HarnessVisit | null = null;
    const { result } = renderHook(() => useTimesheetCommand('7:new'));
    nextVisit('post', (visit, options) => {
        original = visit; callbacks = options;
        if (kind !== 'missing') {
            const other = kind === 'foreign' ? { method: 'post', url: '/other' } : { ...visit };
            const foreign = new CustomEvent('inertia:before', { cancelable: true, detail: { visit: other } });
            foreign.preventDefault(); observeBefore(foreign);
        }
        transport(); options.onStart();
    });
    await act(async () => { await result.current.submit('post', '/timesheets', {}, expected); });
    expect(result.current.pending).toBe(true);
    expect(transport).toHaveBeenCalledOnce();
    act(() => { callbacks!.onCancel(); callbacks!.onFinish(original!); });
    expect(result.current.outcome?.status).toBe('unknown');
    expect(result.current.held.current).toBe(true);
    expect(removeBefore).toHaveBeenCalledOnce();
    await act(async () => { await result.current.submit('post', '/timesheets', {}, expected); });
    expect(post).toHaveBeenCalledOnce();
});

it('settles exact no-send despite actor context changing and being restored inside the synchronous before dispatch', async () => {
    const { result, rerender } = renderHook(({ context }) => useTimesheetCommand(context), { initialProps: { context: '7:new' } });
    nextVisit('post', (_visit, _options, event) => {
        observeBefore(event);
        flushSync(() => rerender({ context: '8:new' }));
        flushSync(() => rerender({ context: '7:new' }));
        event.preventDefault();
    });
    await act(async () => { await result.current.submit('post', '/timesheets', {}, expected); });
    expect(transport).not.toHaveBeenCalled();
    expect(result.current.pending).toBe(false);
    expect(result.current.outcome?.status).toBe('rejected');
    expect(result.current.held.current).toBe(false);
    rerender({ context: '8:new' });
    expect(result.current.outcome?.status).toBe('rejected');
    expect(result.current.held.current).toBe(false);
    expect(post).toHaveBeenCalledOnce();
});

it.each(['started', 'success', 'error', 'finish', 'cancel', 'throw'] as const)('holds a veto with contradictory %s evidence instead of granting a retry', async (contradiction) => {
    const { result } = renderHook(() => useTimesheetCommand('7:new'));
    nextVisit('post', (visit, options, event) => {
        observeBefore(event); event.preventDefault();
        if (contradiction === 'started') options.onStart();
        if (contradiction === 'success') options.onSuccess({ props: { flash: { timesheet_result: receipt } } });
        if (contradiction === 'error') options.onError({ starts_at: 'Choose a time.' });
        if (contradiction === 'finish') options.onFinish(visit);
        if (contradiction === 'cancel') options.onCancel();
        if (contradiction === 'throw') throw new Error('dispatch completion unavailable');
    });
    await act(async () => { await result.current.submit('post', '/timesheets', {}, expected); });
    expect(result.current.pending).toBe(false);
    expect(result.current.outcome?.status).toBe('unknown');
    expect(result.current.held.current).toBe(true);
    expect(removeBefore).toHaveBeenCalledOnce();
    expect(beforeListeners.size).toBe(0);
    await act(async () => { await result.current.submit('post', '/timesheets', {}, expected); });
    expect(post).toHaveBeenCalledOnce();
});

it('ignores an old vetoed attempt callback while a new deliberate command is pending', async () => {
    let old: HarnessOptions | null = null;
    const { result } = renderHook(() => useTimesheetCommand('7:new'));
    nextVisit('post', (_visit, options, event) => { old = options; observeBefore(event); event.preventDefault(); });
    await act(async () => { await result.current.submit('post', '/timesheets', {}, expected); });
    await act(async () => { await result.current.submit('post', '/timesheets', {}, expected); });
    act(() => {
        old!.onSuccess({ props: { flash: { timesheet_result: receipt } } });
        old!.onError({ starts_at: 'Old field error' }); old!.onCancel(); old!.onFinish();
    });
    expect(result.current.pending).toBe(true);
    expect(result.current.busy.current).toBe(true);
    expect(result.current.outcome).toBeNull();
    expect(post).toHaveBeenCalledTimes(2);
});

it('conservatively holds observer registration failure without dispatch or a fabricated no-send result', async () => {
    on.mockImplementationOnce(() => { throw new Error('observer unavailable'); });
    const { result } = renderHook(() => useTimesheetCommand('7:new'));
    await act(async () => { await result.current.submit('post', '/timesheets', {}, expected); });
    expect(post).not.toHaveBeenCalled();
    expect(result.current.pending).toBe(false);
    expect(result.current.outcome?.status).toBe('unknown');
    expect(result.current.held.current).toBe(true);
    expect(beforeListeners.size).toBe(0);
});


it.each(['changed', 'restored', 'hash-error', 'unmounted'] as const)('settles %s preparation without sending or retaining a fictitious uncertain request', async (kind) => {
    let resolveDigest: ((value: ArrayBuffer) => void) | null = null;
    let rejectDigest: ((reason: Error) => void) | null = null;
    const digest = vi.fn(() => new Promise<ArrayBuffer>((resolve, reject) => { resolveDigest = resolve; rejectDigest = reject; }));
    vi.stubGlobal('crypto', { subtle: { digest } });
    const { result, rerender, unmount } = renderHook(({ context }) => useTimesheetCommand(context), { initialProps: { context: '7:51:allowed' } });
    let preparation: Promise<void> | null = null;
    act(() => { preparation = result.current.submit('post', '/timesheets/51', {}, expected); });
    expect(digest).toHaveBeenCalledOnce();
    expect(result.current.pending).toBe(true);
    const busy = result.current.busy, held = result.current.held;
    if (kind === 'unmounted') unmount();
    else {
        rerender({ context: '7:51:denied' });
        if (kind === 'restored') rerender({ context: '7:51:allowed' });
    }
    await act(async () => {
        if (kind === 'hash-error') rejectDigest!(new Error('digest unavailable'));
        else resolveDigest!(new ArrayBuffer(32));
        await preparation;
    });
    expect(post).not.toHaveBeenCalled(); expect(put).not.toHaveBeenCalled();
    expect(on).not.toHaveBeenCalled();
    expect(busy.current).toBe(false); expect(held.current).toBe(false);
    if (kind !== 'unmounted') {
        expect(result.current.pending).toBe(false);
        expect(result.current.outcome?.status).toBe('rejected');
        expect(result.current.outcome).toMatchObject({ message: expect.stringContaining('No request was sent') });
        rerender({ context: '7:51:another-frame' });
        expect(result.current.held.current).toBe(false);
    }
});

it('coordinates two actual hook preparations before asynchronous hash work', async () => {
    const capture: { resolve: ((value: ArrayBuffer) => void) | null } = { resolve: null };
    const digest = vi.fn(() => new Promise<ArrayBuffer>((resolve) => { capture.resolve = resolve; }));
    vi.stubGlobal('crypto', { subtle: { digest } });
    const scope = new TimesheetWriteScope(() => 7);
    const first = renderHook(() => useTimesheetCommand('7:51', { scope }));
    const second = renderHook(() => useTimesheetCommand('7:52', { scope }));
    let preparation: Promise<void> | null = null;
    act(() => { preparation = first.result.current.submit('post', '/timesheets', {}, expected); });
    expect(scope.snapshot()).toBe('busy'); expect(digest).toHaveBeenCalledOnce();
    await act(async () => { await second.result.current.submit('post', '/timesheets', {}, expected); });
    expect(digest).toHaveBeenCalledOnce(); expect(post).not.toHaveBeenCalled();
    expect(second.result.current.pending).toBe(false);
    await act(async () => { capture.resolve!(new ArrayBuffer(32)); await preparation; });
    expect(post).toHaveBeenCalledOnce(); expect(scope.snapshot()).toBe('busy');
});
it('releases shared ownership after a hash failure without sending', async () => {
    const digest = vi.fn().mockRejectedValueOnce(new Error('unavailable')).mockResolvedValue(new ArrayBuffer(32));
    vi.stubGlobal('crypto', { subtle: { digest } });
    const scope = new TimesheetWriteScope(() => 7);
    const first = renderHook(() => useTimesheetCommand('7:51', { scope }));
    const second = renderHook(() => useTimesheetCommand('7:52', { scope }));
    await act(async () => { await first.result.current.submit('post', '/timesheets', {}, expected); });
    expect(scope.snapshot()).toBe('idle'); expect(post).not.toHaveBeenCalled();
    expect(first.result.current.held.current).toBe(false);
    await act(async () => { await second.result.current.submit('post', '/timesheets', {}, expected); });
    expect(post).toHaveBeenCalledOnce(); expect(scope.snapshot()).toBe('busy');
});
it('releases the shared owner only after its exact synchronous before veto', async () => {
    const scope = new TimesheetWriteScope(() => 7);
    const first = renderHook(() => useTimesheetCommand('7:51', { scope }));
    const second = renderHook(() => useTimesheetCommand('7:52', { scope }));
    nextVisit('post', (_visit, _options, event) => { observeBefore(event); event.preventDefault(); });
    await act(async () => { await first.result.current.submit('post', '/timesheets', {}, expected); });
    expect(scope.snapshot()).toBe('idle'); expect(first.result.current.outcome?.status).toBe('rejected');
    await act(async () => { await second.result.current.submit('post', '/timesheets', {}, expected); });
    expect(post).toHaveBeenCalledTimes(2); expect(scope.snapshot()).toBe('busy');
});
it('releases shared ownership on the original matching callback in the current context', async () => {
    const scope = new TimesheetWriteScope(() => 7);
    const first = renderHook(() => useTimesheetCommand('7:51', { scope }));
    const second = renderHook(() => useTimesheetCommand('7:52', { scope }));
    await act(async () => { await first.result.current.submit('post', '/timesheets', {}, expected); });
    const options = post.mock.calls[0][2];
    act(() => { options.onSuccess({ props: { flash: { timesheet_result: receipt } } }); options.onFinish(); });
    expect(first.result.current.outcome?.status).toBe('confirmed'); expect(scope.snapshot()).toBe('idle');
    await act(async () => { await second.result.current.submit('post', '/timesheets', {}, expected); });
    expect(post).toHaveBeenCalledTimes(2); expect(scope.snapshot()).toBe('busy');
});
it('keeps an invoked owner held after its hook unmounts despite a late receipt', async () => {
    const scope = new TimesheetWriteScope(() => 7);
    const first = renderHook(() => useTimesheetCommand('7:51', { scope }));
    const second = renderHook(() => useTimesheetCommand('7:52', { scope }));
    await act(async () => { await first.result.current.submit('post', '/timesheets', {}, expected); });
    const options = post.mock.calls[0][2]; first.unmount();
    expect(scope.snapshot()).toBe('needs_check');
    act(() => { options.onSuccess({ props: { flash: { timesheet_result: receipt } } }); options.onFinish(); });
    await act(async () => { await second.result.current.submit('post', '/timesheets', {}, expected); });
    expect(post).toHaveBeenCalledOnce(); expect(scope.snapshot()).toBe('needs_check');
});
it('retains field errors without treating their error bag as shared no-commit proof', async () => {
    const scope = new TimesheetWriteScope(() => 7);
    const first = renderHook(() => useTimesheetCommand('7:51', { scope }));
    const second = renderHook(() => useTimesheetCommand('7:52', { scope }));
    await act(async () => { await first.result.current.submit('post', '/timesheets', {}, expected); });
    const options = post.mock.calls[0][2];
    act(() => { options.onError({ starts_at: 'Choose a time.' }); options.onFinish(); });
    expect(first.result.current.outcome).toMatchObject({ status: 'unknown', errors: { starts_at: 'Choose a time.' } });
    expect(scope.snapshot()).toBe('needs_check'); expect(first.result.current.held.current).toBe(true);
    await act(async () => { await second.result.current.submit('post', '/timesheets', {}, expected); });
    expect(post).toHaveBeenCalledOnce();
});
it('does not release a dispatched shared owner after the actor changes', async () => {
    const actor = { id: 7 };
    const scope = new TimesheetWriteScope(() => actor.id);
    const first = renderHook(({ context }) => useTimesheetCommand(context, { scope }), { initialProps: { context: '7:51' } });
    await act(async () => { await first.result.current.submit('post', '/timesheets', {}, expected); });
    const options = post.mock.calls[0][2]; actor.id = 8; first.rerender({ context: '8:51' });
    act(() => { options.onSuccess({ props: { flash: { timesheet_result: receipt } } }); options.onFinish(); });
    expect(scope.snapshot()).toBe('needs_check'); expect(first.result.current.outcome?.status).toBe('unknown');
    expect(scope.tryPrepare(8)).toBeNull();
});
it('denies an explicitly missing owning scope before hashing or transport', async () => {
    const digest = vi.fn(); vi.stubGlobal('crypto', { subtle: { digest } });
    const { result } = renderHook(() => useTimesheetCommand('7:51', { scope: null }));
    await act(async () => { await result.current.submit('post', '/timesheets', {}, expected); });
    expect(digest).not.toHaveBeenCalled(); expect(on).not.toHaveBeenCalled(); expect(post).not.toHaveBeenCalled();
    expect(result.current.pending).toBe(false); expect(result.current.held.current).toBe(false);
});
it('proves coordinated registration failure before invocation did not send', async () => {
    const scope = new TimesheetWriteScope(() => 7);
    on.mockImplementationOnce(() => { throw new Error('observer unavailable'); });
    const { result } = renderHook(() => useTimesheetCommand('7:51', { scope }));
    await act(async () => { await result.current.submit('post', '/timesheets', {}, expected); });
    expect(post).not.toHaveBeenCalled(); expect(scope.snapshot()).toBe('idle');
    expect(result.current.outcome).toMatchObject({ status: 'rejected', message: expect.stringContaining('No request was sent') });
});
it('retires unmounted preparation without letting its late continuation disturb a new owner', async () => {
    const capture: { resolve: ((value: ArrayBuffer) => void) | null } = { resolve: null };
    const digest = vi.fn(() => new Promise<ArrayBuffer>((resolve) => { capture.resolve = resolve; }));
    vi.stubGlobal('crypto', { subtle: { digest } });
    const scope = new TimesheetWriteScope(() => 7);
    const first = renderHook(() => useTimesheetCommand('7:51', { scope }));
    let preparation: Promise<void> | null = null;
    act(() => { preparation = first.result.current.submit('post', '/timesheets', {}, expected); });
    first.unmount(); expect(scope.snapshot()).toBe('idle');
    const next = scope.tryPrepare(7)!; expect(next).not.toBeNull();
    await act(async () => { capture.resolve!(new ArrayBuffer(32)); await preparation; });
    expect(post).not.toHaveBeenCalled(); expect(scope.snapshot()).toBe('busy'); expect(scope.canDispatch(next)).toBe(true);
});
it('releases only a never-sent preparation after source context changes and returns', async () => {
    const capture: { resolve: ((value: ArrayBuffer) => void) | null } = { resolve: null };
    vi.stubGlobal('crypto', { subtle: { digest: vi.fn(() => new Promise<ArrayBuffer>((resolve) => { capture.resolve = resolve; })) } });
    const scope = new TimesheetWriteScope(() => 7);
    const first = renderHook(({ context }) => useTimesheetCommand(context, { scope }), { initialProps: { context: '7:51:allowed' } });
    let preparation: Promise<void> | null = null;
    act(() => { preparation = first.result.current.submit('post', '/timesheets', {}, expected); });
    first.rerender({ context: '7:51:denied' }); first.rerender({ context: '7:51:allowed' });
    await act(async () => { capture.resolve!(new ArrayBuffer(32)); await preparation; });
    expect(post).not.toHaveBeenCalled(); expect(scope.snapshot()).toBe('idle');
    expect(first.result.current.held.current).toBe(false); expect(first.result.current.outcome?.status).toBe('rejected');
});

it('does not adopt context change and restoration from an acquisition subscriber', async () => {
    const digest = vi.fn().mockResolvedValue(new ArrayBuffer(32));
    vi.stubGlobal('crypto', { subtle: { digest } });
    const scope = new TimesheetWriteScope(() => 7);
    const first = renderHook(({ context }) => useTimesheetCommand(context, { scope }), { initialProps: { context: '7:51:allowed' } });
    let once = true;
    const unsubscribe = scope.subscribe(() => {
        if (!once || scope.snapshot() !== 'busy') return;
        once = false;
        flushSync(() => first.rerender({ context: '7:51:denied' }));
        flushSync(() => first.rerender({ context: '7:51:allowed' }));
    });
    await act(async () => { await first.result.current.submit('post', '/timesheets', {}, expected); });
    expect(digest).not.toHaveBeenCalled(); expect(post).not.toHaveBeenCalled();
    expect(scope.snapshot()).toBe('idle'); expect(first.result.current.held.current).toBe(false);
    expect(first.result.current.outcome?.status).toBe('rejected'); unsubscribe();
});
it('immediately retires a returned preparation when acquisition notification unmounts its hook', async () => {
    const digest = vi.fn().mockResolvedValue(new ArrayBuffer(32));
    vi.stubGlobal('crypto', { subtle: { digest } });
    const scope = new TimesheetWriteScope(() => 7);
    const first = renderHook(() => useTimesheetCommand('7:51', { scope }));
    let once = true;
    const unsubscribe = scope.subscribe(() => {
        if (!once || scope.snapshot() !== 'busy') return;
        once = false; first.unmount();
    });
    await act(async () => { await first.result.current.submit('post', '/timesheets', {}, expected); });
    expect(digest).not.toHaveBeenCalled(); expect(post).not.toHaveBeenCalled(); expect(on).not.toHaveBeenCalled();
    expect(scope.snapshot()).toBe('idle'); expect(first.result.current.busy.current).toBe(false);
    unsubscribe(); expect(scope.tryPrepare(7)).not.toBeNull();
});
it('blocks same-hook reentry during synchronous scope acquisition', async () => {
    const scope = new TimesheetWriteScope(() => 7);
    const first = renderHook(() => useTimesheetCommand('7:51', { scope }));
    const reentrant: Promise<void>[] = [];
    let once = true;
    const unsubscribe = scope.subscribe(() => {
        if (!once || scope.snapshot() !== 'busy') return;
        once = false; reentrant.push(first.result.current.submit('post', '/timesheets', {}, expected));
    });
    await act(async () => { await first.result.current.submit('post', '/timesheets', {}, expected); await Promise.all(reentrant); });
    expect(post).toHaveBeenCalledOnce(); expect(first.result.current.pending).toBe(true);
    expect(scope.snapshot()).toBe('busy'); unsubscribe();
});
it('does not overwrite a changed frame with confirmed after a release subscriber runs', async () => {
    const scope = new TimesheetWriteScope(() => 7);
    const first = renderHook(({ context }) => useTimesheetCommand(context, { scope }), { initialProps: { context: '7:51' } });
    await act(async () => { await first.result.current.submit('post', '/timesheets', {}, expected); });
    const options = post.mock.calls[0][2];
    let once = true;
    const unsubscribe = scope.subscribe(() => {
        if (!once || scope.snapshot() !== 'idle') return;
        once = false; flushSync(() => first.rerender({ context: '7:52' }));
    });
    act(() => { options.onSuccess({ props: { flash: { timesheet_result: receipt } } }); options.onFinish(); });
    expect(first.result.current.outcome?.status).toBe('unknown');
    expect(first.result.current.held.current).toBe(true); expect(scope.snapshot()).toBe('idle');
    await act(async () => { await first.result.current.submit('post', '/timesheets', {}, expected); });
    expect(post).toHaveBeenCalledOnce(); unsubscribe();
});
it('keeps a proved no-send result truthful when its release notification changes context', async () => {
    vi.stubGlobal('crypto', { subtle: { digest: vi.fn().mockRejectedValue(new Error('unavailable')) } });
    const scope = new TimesheetWriteScope(() => 7);
    const first = renderHook(({ context }) => useTimesheetCommand(context, { scope }), { initialProps: { context: '7:51' } });
    let once = true;
    const unsubscribe = scope.subscribe(() => {
        if (!once || scope.snapshot() !== 'idle') return;
        once = false; flushSync(() => first.rerender({ context: '7:52' }));
    });
    await act(async () => { await first.result.current.submit('post', '/timesheets', {}, expected); });
    expect(post).not.toHaveBeenCalled(); expect(scope.snapshot()).toBe('idle');
    expect(first.result.current.outcome).toMatchObject({ status: 'rejected', message: expect.stringContaining('No request was sent') });
    expect(first.result.current.held.current).toBe(false); unsubscribe();
});
it('keeps the same hook busy through matching release notification', async () => {
    const scope = new TimesheetWriteScope(() => 7);
    const first = renderHook(() => useTimesheetCommand('7:51', { scope }));
    await act(async () => { await first.result.current.submit('post', '/timesheets', {}, expected); });
    const options = post.mock.calls[0][2];
    const reentrant: Promise<void>[] = [];
    const unsubscribe = scope.subscribe(() => {
        if (scope.snapshot() === 'idle') reentrant.push(first.result.current.submit('post', '/timesheets', {}, expected));
    });
    act(() => { options.onSuccess({ props: { flash: { timesheet_result: receipt } } }); options.onFinish(); });
    await act(async () => { await Promise.all(reentrant); });
    expect(post).toHaveBeenCalledOnce(); expect(first.result.current.outcome?.status).toBe('confirmed');
    expect(first.result.current.pending).toBe(false); expect(scope.snapshot()).toBe('idle'); unsubscribe();
});
