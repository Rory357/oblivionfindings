import { act, renderHook } from '@testing-library/react';
import { webcrypto } from 'node:crypto';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    shiftSaveHash,
    shiftSaveReceipt,
    useShiftSaveCommand,
    type ShiftSaveExpectation,
    type ShiftSaveReceipt,
} from './use-shift-save-command';
const transport = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: transport }));
const expected: ShiftSaveExpectation = {
    actorId: 1,
    siteId: 2,
    source: {
        shift_id: 55,
        client_id: 10,
        site_id: 2,
        user_id: 7,
        service_context_id: 3,
        shift_series_id: null,
        status: 'scheduled',
    },
    values: {
        client_id: 10,
        service_context_id: 3,
        user_id: 7,
        starts_at: '2026-10-04T20:17:00.000Z',
        ends_at: '2026-10-05T04:47:00.000Z',
        location: 'Kowhai',
        notes: 'Keep notes',
        status: 'scheduled',
        shift_type: 'standard',
        is_sleepover: false,
        is_on_call: false,
        is_lone_worker: true,
        expected_break_minutes: 45,
        coverage_roles: ['driver'],
        required_licence_class: '2',
        required_licence_endorsements: ['P'],
        tasks: [{ id: 31, label: 'Check notes', scheduled_time: '10:17' }],
    },
};
let hash: string;
async function receipt(input = expected): Promise<ShiftSaveReceipt> {
    return {
        action: input.source ? 'update' : 'create',
        actor_id: input.actorId,
        shift_id: input.source?.shift_id ?? 56,
        scope: 'single',
        source: input.source,
        client_id: input.values.client_id,
        site_id: input.siteId,
        user_id: input.values.user_id,
        service_context_id: input.values.service_context_id,
        status: input.values.status,
        changed: true,
        outcome: 'saved',
        starts_at: input.values.starts_at,
        ends_at: input.values.ends_at,
        values_hash: await shiftSaveHash(input.values),
    };
}
beforeEach(async () => {
    vi.clearAllMocks();
    vi.stubGlobal('crypto', webcrypto);
    hash = await shiftSaveHash(expected.values);
});
afterEach(() => vi.unstubAllGlobals());
it('matches exact current actor, source tuple, submitted values, effective site and persisted instants', async () => {
    const valid = await receipt();
    expect(
        shiftSaveReceipt({ shift_result: valid }, expected, hash).status,
    ).toBe('confirmed');
    for (const mismatch of [
        { actor_id: 2 },
        { scope: 'future' },
        { shift_id: 56 },
        { client_id: 11 },
        { site_id: 3 },
        { user_id: 8 },
        { service_context_id: 4 },
        { status: 'draft' },
        { starts_at: '2026-10-04T20:18:00.000Z' },
        { ends_at: '2026-10-05T04:48:00.000Z' },
        { changed: false },
        { values_hash: 'a'.repeat(64) },
        { source: { ...expected.source, shift_series_id: 4 } },
        { source: { ...expected.source, user_id: 8 } },
    ])
        expect(
            shiftSaveReceipt(
                { shift_result: { ...valid, ...mismatch } },
                expected,
                hash,
            ).status,
        ).toBe('unknown');
    for (const flash of [
        { success: 'Shift updated.' },
        { warning: 'Review eligibility' },
        { error: 'Not saved', shift_result: valid },
        {},
    ])
        expect(shiftSaveReceipt(flash, expected, hash).status).toBe('unknown');
});
it('accepts unchanged values and nullable original source fields without inventing saved changes', async () => {
    const input = {
        ...expected,
        source: {
            ...expected.source!,
            site_id: null,
            service_context_id: null,
        },
    };
    const valid = {
        ...(await receipt(input)),
        changed: false,
        outcome: 'unchanged',
    };
    expect(shiftSaveReceipt({ shift_result: valid }, input, hash).status).toBe(
        'confirmed',
    );
});
it('allows server default service context and informational warnings on a committed create', async () => {
    const input = {
        ...expected,
        source: null,
        values: { ...expected.values, service_context_id: null, tasks: [] },
    };
    const valid = await receipt(input);
    expect(
        shiftSaveReceipt(
            {
                shift_result: { ...valid, service_context_id: 9 },
                warning: 'An advisory remains',
            },
            input,
            valid.values_hash,
        ).status,
    ).toBe('confirmed');
    expect(
        shiftSaveReceipt(
            {
                shift_result: {
                    ...valid,
                    changed: false,
                    outcome: 'unchanged',
                },
            },
            input,
            valid.values_hash,
        ).status,
    ).toBe('unknown');
});
it('requires exact typed not-saved evidence before permitting a warning retry and validates warning data', () => {
    const rejected = {
        action: 'update',
        scope: 'single',
        actor_id: 1,
        shift_id: 55,
        source: expected.source,
        values_hash: hash,
        changed: false,
        outcome: 'not_saved',
        reason: 'eligibility_warning',
    };
    const feedback = {
        warning_reasons: ['Review workload'],
        overrideable_warnings: [
            {
                rule: 'workload',
                message: 'Review workload',
                overrideable: true,
            },
        ],
    };
    expect(
        shiftSaveReceipt(
            { shift_result: rejected, eligibility_result: feedback },
            expected,
            hash,
        ),
    ).toMatchObject({ status: 'rejected', eligibility: feedback });
    expect(
        shiftSaveReceipt(
            {
                shift_result: { ...rejected, values_hash: 'old' },
                eligibility_result: feedback,
            },
            expected,
            hash,
        ).status,
    ).toBe('unknown');
    expect(
        shiftSaveReceipt(
            {
                shift_result: rejected,
                eligibility_result: { warning_reasons: [{}] },
            },
            expected,
            hash,
        ),
    ).toMatchObject({ status: 'rejected', eligibility: undefined });
});
it('snapshots the intent and transport before asynchronous hashing and prevents duplicate submissions', async () => {
    const input = structuredClone(expected),
        payload = { notes: 'Keep notes' };
    const hook = renderHook(() => useShiftSaveCommand('1:55'));
    await act(async () => {
        const sent = hook.result.current.submit(input, payload);
        input.values.notes = 'Changed while hashing';
        payload.notes = 'Changed while hashing';
        await hook.result.current.submit(expected, {});
        await sent;
    });
    expect(transport.put).toHaveBeenCalledTimes(1);
    expect(transport.put.mock.calls[0][1]).toEqual({ notes: 'Keep notes' });
    expect(transport.put.mock.calls[0][2].headers).toEqual({
        'X-Shift-Result': 'committed-v1',
    });
    await act(async () => {
        transport.put.mock.calls[0][2].onSuccess({
            props: { flash: { shift_result: await receipt() } },
        });
        transport.put.mock.calls[0][2].onFinish();
    });
    expect(hook.result.current.outcome?.status).toBe('confirmed');
    await act(async () => hook.result.current.submit(expected, {}));
    expect(transport.put).toHaveBeenCalledTimes(1);
});
it('retains uncertainty and never resends after cancel, a missing receipt or synchronous transport failure', async () => {
    for (const fault of ['cancel', 'finish', 'throw']) {
        transport.put.mockReset();
        if (fault === 'throw')
            transport.put.mockImplementationOnce(() => {
                throw Error('transport');
            });
        const hook = renderHook(() => useShiftSaveCommand('1:55'));
        await act(async () => hook.result.current.submit(expected, {}));
        if (fault !== 'throw')
            act(() => {
                const options = transport.put.mock.calls[0][2];
                if (fault === 'cancel') options.onCancel();
                options.onFinish();
            });
        expect(hook.result.current.outcome?.status).toBe('unknown');
        expect(hook.result.current.pending).toBe(false);
        await act(async () => hook.result.current.submit(expected, {}));
        expect(transport.put).toHaveBeenCalledTimes(1);
        hook.unmount();
    }
});
it('permits a deliberate corrected retry after validation rejection and binds it to the new values', async () => {
    const hook = renderHook(() => useShiftSaveCommand('1:55'));
    await act(async () => hook.result.current.submit(expected, {}));
    act(() => {
        const options = transport.put.mock.calls[0][2];
        options.onError({ ends_at: 'Choose a later end' });
        options.onFinish();
    });
    expect(hook.result.current.outcome?.status).toBe('rejected');
    const corrected = {
        ...expected,
        values: { ...expected.values, notes: 'Corrected draft' },
    };
    await act(async () => hook.result.current.submit(corrected, {}));
    expect(transport.put).toHaveBeenCalledTimes(2);
    await act(async () => {
        const options = transport.put.mock.calls[1][2];
        options.onSuccess({
            props: { flash: { shift_result: await receipt(corrected) } },
        });
        options.onFinish();
    });
    expect(hook.result.current.outcome?.status).toBe('confirmed');
});
it('quarantines a late result if the signed-in actor or edited record changes and returns', async () => {
    const hook = renderHook(({ context }) => useShiftSaveCommand(context), {
        initialProps: { context: '1:55' },
    });
    await act(async () => hook.result.current.submit(expected, {}));
    hook.rerender({ context: '2:55' });
    hook.rerender({ context: '1:55' });
    await act(async () => {
        const options = transport.put.mock.calls[0][2];
        options.onSuccess({
            props: { flash: { shift_result: await receipt() } },
        });
        options.onFinish();
    });
    expect(hook.result.current.outcome?.status).toBe('unknown');
});
it('cannot confirm late callbacks after an unmounted dialog or an already-finished response', async () => {
    const hook = renderHook(() => useShiftSaveCommand('1:55'));
    await act(async () => hook.result.current.submit(expected, {}));
    const options = transport.put.mock.calls[0][2];
    act(() => {
        options.onCancel();
        options.onFinish();
    });
    await act(async () => {
        options.onSuccess({
            props: { flash: { shift_result: await receipt() } },
        });
        options.onFinish();
    });
    expect(hook.result.current.outcome?.status).toBe('unknown');
    hook.unmount();
    await act(async () => {
        options.onError({ notes: 'late' });
        options.onFinish();
    });
    expect(transport.put).toHaveBeenCalledTimes(1);
});
