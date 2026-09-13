import { approvalRecord } from '@/test/it-approval-fixtures';
import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    readItApprovalHistory,
    useItApprovalHistory,
} from './use-it-approval-history';

const transport = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('axios', () => ({
    default: {
        ...transport,
        isAxiosError: (value: unknown) =>
            typeof value === 'object' && value !== null && 'response' in value,
    },
}));
const nonce = '11111111-1111-4111-8111-111111111111';
const identity = { actorId: 7, ticketId: 42, nonce, page: 1 };
const response = (page = 1, version = 4) => ({
    status: 200,
    data: {
        viewer_user_id: 7,
        ticket_id: 42,
        review_nonce: nonce,
        lock_version: version,
        history: {
            page,
            per_page: 10,
            total: 12,
            next_page: page === 1 ? 2 : null,
            records: Array.from({ length: page === 1 ? 10 : 2 }, (_, index) =>
                approvalRecord({ id: 12 - (page - 1) * 10 - index }),
            ),
        },
    },
});
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}
const setup = () => {
    const onAccessLost = vi.fn();
    const onSessionExpired = vi.fn();
    return {
        ...renderHook(
            ({ actorId, enabled }) =>
                useItApprovalHistory({
                    actorId,
                    ticketId: 42,
                    enabled,
                    onAccessLost,
                    onSessionExpired,
                }),
            { initialProps: { actorId: 7, enabled: true } },
        ),
        onAccessLost,
        onSessionExpired,
    };
};
beforeEach(() => {
    transport.get.mockReset();
    vi.spyOn(crypto, 'randomUUID').mockReturnValue(nonce);
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});
it('requires exact actor ticket nonce page and version and rejects incomplete or duplicate private records', () => {
    expect(
        readItApprovalHistory(response().data, identity)?.records,
    ).toHaveLength(10);
    for (const changed of [
        { viewer_user_id: 8 },
        { ticket_id: 99 },
        { review_nonce: 'wrong' },
        { lock_version: 0 },
    ])
        expect(
            readItApprovalHistory({ ...response().data, ...changed }, identity),
        ).toBeNull();
    expect(
        readItApprovalHistory(response(2, 5).data, {
            ...identity,
            page: 2,
            version: 4,
        }),
    ).toBeNull();
    const repeated = response().data;
    repeated.history.records[1] = repeated.history.records[0];
    expect(readItApprovalHistory(repeated, identity)).toBeNull();
    const missing = response().data;
    missing.history.records.pop();
    expect(readItApprovalHistory(missing, identity)).toBeNull();
});

it('accepts a resolved historical page only when it contains the exact requested approval', () => {
    const data = { ...response(2).data, target_approval_id: 1 };
    expect(
        readItApprovalHistory(data, { ...identity, targetId: 1 })?.page,
    ).toBe(2);
    expect(
        readItApprovalHistory(data, { ...identity, targetId: 2 }),
    ).toBeNull();
    expect(
        readItApprovalHistory(
            { ...data, target_approval_id: 3 },
            { ...identity, targetId: 3 },
        ),
    ).toBeNull();
    expect(readItApprovalHistory(data, identity)).toBeNull();
});

it('binds historical lookup to its target and ignores a late response after that target changes', async () => {
    const onAccessLost = vi.fn();
    const hook = renderHook(
        ({ targetId }) =>
            useItApprovalHistory({
                actorId: 7,
                ticketId: 42,
                enabled: true,
                targetId,
                onAccessLost,
            }),
        { initialProps: { targetId: 1 } },
    );
    const pending = deferred<ReturnType<typeof response>>();
    transport.get.mockReturnValueOnce(pending.promise);
    let waiting!: Promise<void>;
    act(() => {
        waiting = hook.result.current.load();
    });
    expect(transport.get.mock.calls[0][1].params.approval_id).toBe(1);
    hook.rerender({ targetId: 2 });
    await act(async () => {
        pending.resolve({
            ...response(2),
            data: { ...response(2).data, target_approval_id: 1 },
        } as ReturnType<typeof response>);
        await waiting;
    });
    expect(hook.result.current.page).toBeNull();
    transport.get.mockResolvedValueOnce({
        ...response(2),
        data: { ...response(2).data, target_approval_id: 2 },
    });
    await act(async () => hook.result.current.load());
    expect(transport.get.mock.calls[1][1].params.approval_id).toBe(2);
    expect(hook.result.current.page?.page).toBe(2);
    expect(onAccessLost).not.toHaveBeenCalled();
});
it('uses an actor-bound read and retains the original reviewed version for later pages', async () => {
    const hook = setup();
    transport.get.mockResolvedValueOnce(response());
    await act(async () => hook.result.current.load());
    expect(hook.result.current.page?.records[0].id).toBe(12);
    transport.get.mockResolvedValueOnce(response(2));
    await act(async () => hook.result.current.load(true));
    expect(transport.get.mock.calls[1][1].params).toEqual({
        actor_user_id: 7,
        review_nonce: nonce,
        page: 2,
        expected_version: 4,
    });
    expect(
        hook.result.current.page?.records.map((record) => record.id),
    ).toEqual([2, 1]);
});
it('hides previous private records immediately while refreshing and ignores a late response after cancellation', async () => {
    const hook = setup();
    transport.get.mockResolvedValueOnce(response());
    await act(async () => hook.result.current.load());
    const pending = deferred<ReturnType<typeof response>>();
    transport.get.mockReturnValueOnce(pending.promise);
    let waiting!: Promise<void>;
    act(() => {
        waiting = hook.result.current.load();
    });
    expect(hook.result.current.page).toBeNull();
    expect(hook.result.current.busy).toBe(true);
    act(() => hook.result.current.cancel());
    await act(async () => {
        pending.resolve(response());
        await waiting;
    });
    expect(hook.result.current.page).toBeNull();
    expect(hook.result.current.error).toMatch(/cancelled/i);
});
it('removes records on permission loss and does not accept another actor response', async () => {
    const hook = setup();
    transport.get.mockResolvedValueOnce({
        ...response(),
        data: { ...response().data, viewer_user_id: 8 },
    });
    await act(async () => hook.result.current.load());
    expect(hook.onAccessLost).toHaveBeenCalledTimes(1);
    expect(hook.result.current.page).toBeNull();
    transport.get.mockRejectedValueOnce({ response: { status: 404 } });
    await act(async () => hook.result.current.load());
    expect(hook.onAccessLost).toHaveBeenCalledTimes(2);
    expect(hook.result.current.page).toBeNull();
});
it('treats a changed page or expired session as unavailable and retries from the first page', async () => {
    const hook = setup();
    transport.get.mockResolvedValueOnce(response());
    await act(async () => hook.result.current.load());
    transport.get.mockRejectedValueOnce({ response: { status: 409 } });
    await act(async () => hook.result.current.load(true));
    expect(hook.result.current.page).toBeNull();
    expect(hook.result.current.error).toMatch(/first page/);
    transport.get.mockRejectedValueOnce({ response: { status: 419 } });
    await act(async () => hook.result.current.load());
    expect(hook.onSessionExpired).toHaveBeenCalledTimes(1);
    expect(hook.result.current.page).toBeNull();
    transport.get.mockResolvedValueOnce(response());
    await act(async () => hook.result.current.load());
    expect(transport.get.mock.calls[3][1].params).not.toHaveProperty(
        'expected_version',
    );
    expect(hook.result.current.page?.page).toBe(1);
});
it('ignores an old account read after its scope or expanded history changes', async () => {
    const hook = setup();
    const pending = deferred<ReturnType<typeof response>>();
    transport.get.mockReturnValueOnce(pending.promise);
    let waiting!: Promise<void>;
    act(() => {
        waiting = hook.result.current.load();
    });
    hook.rerender({ actorId: 8, enabled: false });
    await act(async () => {
        pending.resolve(response());
        await waiting;
    });
    expect(hook.result.current.page).toBeNull();
    expect(hook.onAccessLost).not.toHaveBeenCalled();
});
