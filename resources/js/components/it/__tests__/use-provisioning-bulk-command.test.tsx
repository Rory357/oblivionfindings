import { act, renderHook, waitFor } from '@testing-library/react';
import axios, { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    provisioningBulkStorageKey,
    readProvisioningBulkReference,
    useProvisioningBulkCommand,
    type ProvisioningBulkReference,
} from '../use-provisioning-bulk-command';

const selection = {
    operation: 'retry' as const,
    items: [
        { id: 12, version: 4 },
        { id: 13, version: 5 },
    ],
};
const response = (
    reference: ProvisioningBulkReference,
    id: number,
    status = 'committed',
) => ({
    data: {
        status,
        data: {
            viewer_user_id: reference.actorId,
            kind: 'request',
            target_id: id,
            operation: reference.operation,
            request_uuid: reference.items.find((row) => row.id === id)
                ?.requestUuid,
            ...(status === 'committed'
                ? {
                      result_id: id,
                      lock_version: 6,
                      replayed: true,
                      url: '/it/provisioning/tasks/' + id,
                  }
                : status === 'not_found'
                  ? { retry_same_command: true }
                  : {}),
        },
    },
});
const denied = () =>
    new AxiosError('Synthetic', undefined, undefined, undefined, {
        status: 401,
        statusText: 'Unauthenticated',
        data: {},
        headers: {},
        config: { headers: {} } as never,
    });
beforeEach(() => sessionStorage.clear());
afterEach(() => vi.restoreAllMocks());

it('retains no private fields and accounts for mixed recorded and unknown outcomes separately', async () => {
    const post = vi.spyOn(axios, 'post');
    const { result } = renderHook(() =>
        useProvisioningBulkCommand(3, selection),
    );
    const reference = result.current.reference!;
    post.mockResolvedValueOnce(response(reference, 12)).mockRejectedValueOnce(
        new Error('Lost response'),
    );
    act(() =>
        result.current.submit({
            reason: 'Private retry explanation',
            employee_name: 'Private name',
        }),
    );
    await waitFor(() => expect(result.current.busy).toBe(false));
    expect(result.current.rows.map((row) => row.phase)).toEqual([
        'committed',
        'unknown',
    ]);
    const saved = sessionStorage.getItem(provisioningBulkStorageKey(3))!;
    expect(saved).not.toContain('Private');
    expect(readProvisioningBulkReference(JSON.parse(saved), 3)).toEqual(
        reference,
    );
    expect(post).toHaveBeenCalledTimes(2);
    expect(post.mock.calls[0]?.[1]).toMatchObject({
        expected_version: 4,
        reason: 'Private retry explanation',
    });
    expect(post.mock.calls[1]?.[1]).toMatchObject({
        expected_version: 5,
        request_uuid: reference.items[1]?.requestUuid,
    });
    const get = vi
        .spyOn(axios, 'get')
        .mockResolvedValueOnce(response(reference, 13));
    await act(() => result.current.recover(13));
    expect(get).toHaveBeenCalledOnce();
    expect(result.current.terminal).toBe(true);
    await waitFor(() =>
        expect(
            sessionStorage.getItem(provisioningBulkStorageKey(3)),
        ).toBeNull(),
    );
});

it('restores every original identity as unknown and requires check or cancellation without a private payload', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockRejectedValue(new Error('Lost response'));
    const first = renderHook(() => useProvisioningBulkCommand(3, selection));
    act(() =>
        first.result.current.submit({ reason: 'Private original reason' }),
    );
    await waitFor(() => expect(first.result.current.busy).toBe(false));
    const reference = first.result.current.reference!;
    first.unmount();
    const restored = renderHook(() => useProvisioningBulkCommand(3));
    expect(restored.result.current.rows.map((row) => row.phase)).toEqual([
        'unknown',
        'unknown',
    ]);
    expect(restored.result.current.canSubmit).toBe(false);
    vi.spyOn(axios, 'get').mockResolvedValue(
        response(reference, 12, 'not_found'),
    );
    await act(() => restored.result.current.recover(12));
    expect(restored.result.current.canRetry(12)).toBe(false);
    const before = post.mock.calls.length;
    await act(() => restored.result.current.retry(12));
    expect(post).toHaveBeenCalledTimes(before);
    post.mockResolvedValueOnce(response(reference, 12, 'cancelled'));
    await act(() => restored.result.current.cancel(12));
    expect(restored.result.current.rows[0]?.phase).toBe('cancelled');
    expect(
        sessionStorage.getItem(provisioningBulkStorageKey(3)),
    ).not.toBeNull();
});

it('retries only the frozen original row after authoritative not-found', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockRejectedValue(new Error('Lost response'));
    const { result } = renderHook(() =>
        useProvisioningBulkCommand(3, selection),
    );
    const reference = result.current.reference!;
    const payload = { reason: 'Original reviewed reason' };
    act(() => result.current.submit(payload));
    payload.reason = 'Unreviewed mutation';
    await waitFor(() => expect(result.current.busy).toBe(false));
    expect(result.current.canRetry(12)).toBe(false);
    vi.spyOn(axios, 'get').mockResolvedValue(
        response(reference, 12, 'not_found'),
    );
    await act(() => result.current.recover(12));
    expect(result.current.canRetry(12)).toBe(true);
    post.mockResolvedValueOnce(response(reference, 12));
    await act(() => result.current.retry(12));
    expect(post.mock.lastCall?.[1]).toMatchObject({
        reason: 'Original reviewed reason',
        expected_version: 4,
        request_uuid: reference.items[0]?.requestUuid,
    });
    expect(result.current.rows[1]?.phase).toBe('unknown');
});

it('stops the remaining batch and conceals details after account access fails', async () => {
    const post = vi.spyOn(axios, 'post').mockRejectedValueOnce(denied());
    const { result } = renderHook(() =>
        useProvisioningBulkCommand(3, selection),
    );
    act(() => result.current.submit({ reason: 'Private reason' }));
    await waitFor(() => expect(result.current.busy).toBe(false));
    expect(post).toHaveBeenCalledOnce();
    expect(result.current.concealed).toBe(true);
    expect(result.current.rows.map((row) => row.phase)).toEqual([
        'denied',
        'ready',
    ]);
    expect(
        sessionStorage.getItem(provisioningBulkStorageKey(3)),
    ).not.toBeNull();
    await act(() => result.current.recover(13));
    expect(post).toHaveBeenCalledOnce();
});

it('preserves identities if close happens before React renders the dispatch', async () => {
    let resolve!: (value: unknown) => void;
    const post = vi.spyOn(axios, 'post').mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    const { result } = renderHook(() =>
        useProvisioningBulkCommand(3, selection),
    );
    const reference = result.current.reference!;
    act(() => {
        result.current.submit({ reason: 'Original reviewed reason' });
        result.current.stopWaiting();
        result.current.forgetIfSafe();
    });
    expect(
        sessionStorage.getItem(provisioningBulkStorageKey(3)),
    ).not.toBeNull();
    expect(result.current.rows[0]?.phase).toBe('unknown');
    await act(async () => resolve(response(reference, 12)));
    expect(post).toHaveBeenCalledOnce();
    expect(result.current.rows[0]?.phase).toBe('unknown');
});

it('does not continue an old batch when the account changes away and back', async () => {
    let resolve!: (value: unknown) => void;
    const post = vi.spyOn(axios, 'post').mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    const { result, rerender } = renderHook(
        ({ actor }) => useProvisioningBulkCommand(actor, selection),
        { initialProps: { actor: 3 } },
    );
    const reference = result.current.reference!;
    act(() => result.current.submit({ reason: 'Private original reason' }));
    rerender({ actor: 4 });
    rerender({ actor: 3 });
    await act(async () => resolve(response(reference, 12)));
    expect(result.current.concealed).toBe(true);
    expect(result.current.busy).toBe(false);
    expect(post).toHaveBeenCalledOnce();
    expect(result.current.rows[0]?.phase).toBe('unknown');
});
