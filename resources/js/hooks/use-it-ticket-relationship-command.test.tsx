import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { type RelationshipIdentity } from './it-ticket-relationship-contract';
import { useItTicketRelationshipCommand } from './use-it-ticket-relationship-command';

const choice = {
    targetId: 42,
    sourceVersion: 3,
    targetVersion: 5,
    relationship: 'related_ticket' as const,
    action: 'add' as const,
};
const storageKey = 'it.pending-relationship.v1.7:41';
function receipt(identity: RelationshipIdentity, status = 'committed') {
    return {
        data: {
            status,
            data: {
                viewer_user_id: identity.actorId,
                source_id: identity.sourceId,
                target_id: identity.targetId,
                request_uuid: identity.requestUuid,
                operation: 'ticket.relationship',
                action: identity.action,
                relationship: identity.relationship,
                replayed: true,
                changed: true,
                source_version: 4,
                target_version: 6,
                cancelled_at: '2026-09-10T08:00:00Z',
            },
        },
    };
}
beforeEach(() => sessionStorage.clear());
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    sessionStorage.clear();
});

it('retains only an opaque identity across a lost acknowledgement and remount, then recovers without resubmitting', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockRejectedValue(new Error('Synthetic response loss'));
    const first = renderHook(() => useItTicketRelationshipCommand(7, 41));
    act(() => first.result.current.send(choice));
    await waitFor(() => expect(first.result.current.stage).toBe('unknown'));
    const identity = first.result.current.identity!;
    expect(JSON.parse(sessionStorage.getItem(storageKey)!)).toEqual(identity);
    expect(Object.keys(identity).sort()).toEqual([
        'action',
        'actorId',
        'relationship',
        'requestUuid',
        'sourceId',
        'targetId',
    ]);
    first.unmount();
    vi.spyOn(axios, 'get').mockResolvedValue(receipt(identity));
    const restored = renderHook(() => useItTicketRelationshipCommand(7, 41));
    await waitFor(() => expect(restored.result.current.stage).toBe('unknown'));
    expect(restored.result.current.canRetry).toBe(false);
    act(() => restored.result.current.check());
    await waitFor(() => expect(restored.result.current.stage).toBe('settled'));
    expect(restored.result.current.result?.status).toBe('committed');
    expect(post).toHaveBeenCalledTimes(1);
    expect(sessionStorage.getItem(storageKey)).toBeNull();
});

it('ignores a late response after stopping the wait and reports committed if server cancellation loses the race', async () => {
    let resolve: (value: unknown) => void = () => {};
    const post = vi.spyOn(axios, 'post').mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    const { result } = renderHook(() => useItTicketRelationshipCommand(7, 41));
    act(() => result.current.send(choice));
    await waitFor(() => expect(result.current.stage).toBe('sending'));
    const identity = result.current.identity!;
    act(() => result.current.stopWaiting());
    await act(async () => resolve(receipt(identity)));
    expect(result.current.stage).toBe('unknown');
    expect(sessionStorage.getItem(storageKey)).not.toBeNull();
    post.mockResolvedValueOnce(receipt(identity));
    act(() => result.current.cancel());
    await waitFor(() => expect(result.current.stage).toBe('settled'));
    expect(result.current.result?.status).toBe('committed');
    expect(post.mock.calls[1][0]).toBe(
        `/it/tickets/41/relationship-commands/${identity.requestUuid}/cancel`,
    );
});

it('rejects an unrelated receipt and preserves the exact request on retry', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(new Error('Response lost'));
    const { result } = renderHook(() => useItTicketRelationshipCommand(7, 41));
    act(() => result.current.send(choice));
    await waitFor(() => expect(result.current.stage).toBe('unknown'));
    const identity = result.current.identity!;
    vi.spyOn(axios, 'get').mockResolvedValue(
        receipt({ ...identity, actorId: 99 }),
    );
    act(() => result.current.check());
    await waitFor(() => expect(result.current.stage).toBe('unknown'));
    expect(sessionStorage.getItem(storageKey)).not.toBeNull();
    post.mockResolvedValueOnce(receipt(identity));
    act(() => result.current.retry());
    await waitFor(() => expect(result.current.stage).toBe('settled'));
    expect(post.mock.calls[1][1]).toEqual(post.mock.calls[0][1]);
});

it('requires renewed review after a version conflict and sends no request when recovery storage is unavailable', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockRejectedValue({
            isAxiosError: true,
            response: { status: 409, data: { code: 'stale_ticket' } },
        });
    const { result } = renderHook(() => useItTicketRelationshipCommand(7, 41));
    act(() => result.current.send(choice));
    await waitFor(() => expect(result.current.stage).toBe('rejected'));
    expect(sessionStorage.getItem(storageKey)).toBeNull();
    act(() => result.current.retry());
    expect(post).toHaveBeenCalledTimes(1);
    act(() => result.current.reset());
    expect(result.current.stage).toBe('editing');
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new Error('Blocked storage');
    });
    act(() => result.current.send({ ...choice, sourceVersion: 4 }));
    expect(result.current.stage).toBe('unavailable');
    expect(post).toHaveBeenCalledTimes(1);
});

it('conceals denied work and ignores an in-flight result after the actor changes', async () => {
    let resolve: (value: unknown) => void = () => {};
    vi.spyOn(axios, 'post')
        .mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        )
        .mockRejectedValueOnce({
            isAxiosError: true,
            response: { status: 403 },
        });
    const hook = renderHook(
        ({ actor }) => useItTicketRelationshipCommand(actor, 41),
        { initialProps: { actor: 7 } },
    );
    act(() => hook.result.current.send(choice));
    await waitFor(() => expect(hook.result.current.stage).toBe('sending'));
    const identity = hook.result.current.identity!;
    hook.rerender({ actor: 8 });
    await act(async () => resolve(receipt(identity)));
    expect(hook.result.current.result).toBeNull();
    expect(sessionStorage.getItem(storageKey)).not.toBeNull();
    act(() => hook.result.current.send(choice));
    await waitFor(() => expect(hook.result.current.stage).toBe('access'));
    expect(hook.result.current.canRetry).toBe(false);
});
