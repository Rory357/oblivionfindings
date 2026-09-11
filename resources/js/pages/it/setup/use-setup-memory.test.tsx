import { act, renderHook } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, expect, it, vi } from 'vitest';
import {
    clearSetupMemory,
    useSetupMemory,
    type SetupWork,
} from './use-setup-memory';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));
const post = vi.mocked(axios.post);
const work = (description = 'The last character!'): SetupWork => ({
    configuration_version: 'a'.repeat(64),
    base_fields: {
        name: 'Existing',
        description: 'Original',
        manager_user_id: 2,
    },
    fields: { name: 'Existing', description, manager_user_id: null },
    step_index: 1,
    outcomeUnknown: false,
});
const options = (
    changes: Partial<Parameters<typeof useSetupMemory>[0]> = {},
): Parameters<typeof useSetupMemory>[0] => ({
    active: true,
    actorId: 8,
    resource: 'teams',
    recordId: 1,
    work: work(),
    dirty: true,
    ...changes,
});
function authorize(changes: Record<string, unknown> = {}) {
    post.mockImplementation(async (_url, raw) => {
        const input = raw as Record<string, unknown>;
        return {
            status: 200,
            data: {
                candidate: {
                    candidate_uuid: input.candidate_uuid,
                    actor_user_id: input.actor_user_id,
                    resource: input.resource,
                    record_id: input.record_id,
                    context_uuid: input.context_uuid,
                    configuration_version: input.configuration_version,
                    current_configuration_version: 'b'.repeat(64),
                    authorized: true,
                    capabilities: { submit: false },
                    blocker: 'configuration_changed',
                    ...changes,
                },
            },
        };
    });
}
beforeEach(() => {
    clearSetupMemory();
    post.mockReset();
    authorize();
});

it('retains latest text and original base only in RAM until explicit fresh-authorized Resume', async () => {
    const first = renderHook((props) => useSetupMemory(props), {
        initialProps: options(),
    });
    first.rerender(options({ work: work('Newest text!') }));
    first.unmount();
    const next = renderHook(() => useSetupMemory(options({ dirty: false })));
    expect(next.result.current.notices).toHaveLength(1);
    expect(JSON.stringify(next.result.current.notices)).not.toContain('Newest');
    expect(post).not.toHaveBeenCalled();
    let restored: Awaited<ReturnType<typeof next.result.current.resume>> = null;
    await act(async () => {
        restored = await next.result.current.resume(
            next.result.current.notices[0].id,
        );
    });
    expect(restored).toMatchObject({
        work: {
            fields: { description: 'Newest text!' },
            base_fields: { description: 'Original' },
            configuration_version: 'a'.repeat(64),
            step_index: 1,
        },
        proof: { capabilities: { submit: false } },
    });
    const input = post.mock.calls[0][1] as { bound_scopes: unknown[] };
    expect(input.bound_scopes).toContainEqual(
        expect.objectContaining({ user_ids: [2] }),
    );
    expect(next.result.current.notices).toHaveLength(0);
});

it('conceals expired sessions and rejects mismatched nonce without consuming a retained form', async () => {
    const first = renderHook(() => useSetupMemory(options()));
    first.unmount();
    const next = renderHook(() => useSetupMemory(options({ dirty: false })));
    const id = next.result.current.notices[0].id;
    post.mockResolvedValueOnce({ status: 419, data: {} });
    await act(async () => {
        expect(await next.result.current.resume(id)).toBeNull();
    });
    expect(next.result.current.failure).toBe('session_expired');
    authorize({ candidate_uuid: crypto.randomUUID() });
    await act(async () => {
        expect(await next.result.current.resume(id)).toBeNull();
    });
    expect(next.result.current.notices).toHaveLength(1);
});

it('revoked access purges only the denied copy and actor switches cannot recover prior private work', async () => {
    const first = renderHook(() => useSetupMemory(options()));
    first.unmount();
    const next = renderHook((props) => useSetupMemory(props), {
        initialProps: options({ dirty: false }),
    });
    post.mockResolvedValueOnce({ status: 403, data: {} });
    await act(async () => {
        expect(
            await next.result.current.resume(next.result.current.notices[0].id),
        ).toBeNull();
    });
    expect(next.result.current.notices).toHaveLength(0);
    next.rerender(options({ work: work('Old account work'), dirty: true }));
    next.unmount();
    const changedActor = renderHook(() =>
        useSetupMemory(options({ actorId: 9, dirty: false })),
    );
    expect(changedActor.result.current.notices).toHaveLength(0);
});

it('current-form discard cannot erase an independent sibling and cannot resurrect on unmount', () => {
    const sibling = renderHook(() =>
        useSetupMemory(options({ work: work('Sibling') })),
    );
    sibling.unmount();
    const current = renderHook(() =>
        useSetupMemory(options({ work: work('Current') })),
    );
    act(() => current.result.current.clearOwned());
    current.unmount();
    const next = renderHook(() => useSetupMemory(options({ dirty: false })));
    expect(next.result.current.notices).toHaveLength(1);
});

it('cancelled and late successful recovery responses cannot hydrate work', async () => {
    const first = renderHook(() => useSetupMemory(options()));
    first.unmount();
    const next = renderHook(() => useSetupMemory(options({ dirty: false })));
    let resolve!: (value: unknown) => void;
    post.mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    let pending!: ReturnType<typeof next.result.current.resume>;
    act(() => {
        pending = next.result.current.resume(next.result.current.notices[0].id);
    });
    act(() => next.result.current.cancel());
    await act(async () => {
        resolve({ status: 200, data: {} });
        expect(await pending).toBeNull();
    });
    expect(next.result.current.notices).toHaveLength(1);
    expect(next.result.current.busy).toBe(false);
});

it('only a definitive host outcome can release an unknown mounted save while preserving editable text', async () => {
    const pending = {
        ...work(),
        outcomeUnknown: true,
        submitted: { name: 'Existing', description: 'Exact submitted text' },
    };
    const first = renderHook((props) => useSetupMemory(props), {
        initialProps: options({ work: pending }),
    });
    first.rerender(options({ work: work('Correction') }));
    expect(first.result.current.warning).toContain('still unconfirmed');
    first.rerender(options({ work: work('Correction'), settledToken: 1 }));
    first.unmount();
    const next = renderHook(() => useSetupMemory(options({ dirty: false })));
    await act(async () => {
        expect(
            await next.result.current.resume(next.result.current.notices[0].id),
        ).toMatchObject({
            work: {
                fields: { description: 'Correction' },
                outcomeUnknown: false,
            },
        });
    });
});

it('recovery refuses to overwrite a form edited while access was being checked', async () => {
    const first = renderHook(() => useSetupMemory(options()));
    first.unmount();
    const next = renderHook((props) => useSetupMemory(props), {
        initialProps: options({ dirty: false }),
    });
    let finish!: () => void;
    const actual = post.getMockImplementation()!;
    post.mockImplementationOnce(async (...args) => {
        await new Promise<void>((done) => {
            finish = done;
        });
        return actual(...args);
    });
    let pending!: ReturnType<typeof next.result.current.resume>;
    act(() => {
        pending = next.result.current.resume(next.result.current.notices[0].id);
    });
    next.rerender(options({ work: work('Current edit'), dirty: true }));
    await act(async () => {
        finish();
        expect(await pending).toBeNull();
    });
    expect(next.result.current.notices).toHaveLength(1);
});

it('reverting the current form to its base cannot resurrect old edits after leaving', () => {
    const first = renderHook((props) => useSetupMemory(props), {
        initialProps: options(),
    });
    first.rerender(
        options({
            dirty: false,
            work: { ...work(), fields: work().base_fields },
        }),
    );
    first.unmount();
    const next = renderHook(() => useSetupMemory(options({ dirty: false })));
    expect(next.result.current.notices).toHaveLength(0);
});

it('changing records starts a new independent binding history', async () => {
    const first = renderHook((props) => useSetupMemory(props), {
        initialProps: options(),
    });
    const otherWork = {
        ...work(),
        base_fields: {},
        fields: { name: 'Other record', manager_user_id: 3 },
    };
    first.rerender(options({ recordId: 2, dirty: false, work: otherWork }));
    first.rerender(options({ recordId: 2, dirty: true, work: otherWork }));
    first.unmount();
    const next = renderHook(() =>
        useSetupMemory(options({ recordId: 2, dirty: false })),
    );
    await act(async () => {
        expect(
            await next.result.current.resume(next.result.current.notices[0].id),
        ).not.toBeNull();
    });
    const input = post.mock.calls[0][1] as {
        bound_scopes: Array<{ user_ids: number[] }>;
    };
    expect(input.bound_scopes.flatMap((scope) => scope.user_ids)).not.toContain(
        2,
    );
});

it('an unknown save keeps its exact submission and original version until a definitive outcome', async () => {
    const pending = {
        ...work(),
        outcomeUnknown: true,
        submitted: { description: 'First submission' },
    };
    const first = renderHook((props) => useSetupMemory(props), {
        initialProps: options({ work: pending }),
    });
    first.rerender(
        options({
            work: {
                ...pending,
                configuration_version: 'b'.repeat(64),
                submitted: { description: 'Replacement' },
            },
        }),
    );
    expect(first.result.current.warning).toContain('still unconfirmed');
    first.unmount();
    const next = renderHook(() => useSetupMemory(options({ dirty: false })));
    await act(async () => {
        expect(
            await next.result.current.resume(next.result.current.notices[0].id),
        ).toMatchObject({
            work: {
                configuration_version: 'a'.repeat(64),
                submitted: { description: 'First submission' },
                outcomeUnknown: true,
            },
        });
    });
});

it('warns before a full reload while abandoned RAM work exists and removes the warning after discard', () => {
    const first = renderHook(() => useSetupMemory(options()));
    first.unmount();
    const blocked = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(blocked);
    expect(blocked.defaultPrevented).toBe(true);
    const next = renderHook(() => useSetupMemory(options({ dirty: false })));
    act(() => next.result.current.discard(next.result.current.notices[0].id));
    const clear = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(clear);
    expect(clear.defaultPrevented).toBe(false);
});

it('closing and reopening the same form invalidates a pending recovery lifecycle', async () => {
    const first = renderHook(() => useSetupMemory(options()));
    first.unmount();
    const next = renderHook((props) => useSetupMemory(props), {
        initialProps: options({ dirty: false }),
    });
    const actual = post.getMockImplementation()!;
    let finish!: () => void;
    post.mockImplementationOnce(async (...args) => {
        await new Promise<void>((done) => {
            finish = done;
        });
        return actual(...args);
    });
    let pending!: ReturnType<typeof next.result.current.resume>;
    act(() => {
        pending = next.result.current.resume(next.result.current.notices[0].id);
    });
    next.rerender(options({ active: false, dirty: false }));
    next.rerender(options({ active: true, dirty: false }));
    await act(async () => {
        finish();
        expect(await pending).toBeNull();
    });
    expect(next.result.current.notices).toHaveLength(1);
    expect(next.result.current.busy).toBe(false);
});

it('a mounted concealed form checks all existing bindings before showing work after session expiry', async () => {
    const current = renderHook(() => useSetupMemory(options()));
    post.mockResolvedValueOnce({ status: 419, data: {} });
    await act(async () => {
        expect(await current.result.current.checkCurrentAccess()).toBeNull();
    });
    expect(current.result.current.failure).toBe('session_expired');
    await act(async () => {
        expect(await current.result.current.checkCurrentAccess()).toMatchObject(
            { current_configuration_version: 'b'.repeat(64) },
        );
    });
    post.mockResolvedValueOnce({ status: 403, data: {} });
    await act(async () => {
        expect(await current.result.current.checkCurrentAccess()).toBe(
            'denied',
        );
    });
    current.unmount();
    const next = renderHook(() => useSetupMemory(options({ dirty: false })));
    expect(next.result.current.notices).toHaveLength(0);
});
