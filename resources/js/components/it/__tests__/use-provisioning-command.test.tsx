import { act, renderHook } from '@testing-library/react';
import axios, { AxiosError } from 'axios';
import { StrictMode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    readProvisioningOutcome,
    useProvisioningCommand,
    type ProvisioningCommandIdentity,
} from '../use-provisioning-command';

const context = {
    actorId: 3,
    kind: 'request' as const,
    targetId: 12,
    operation: 'fulfil',
};
const storageKey = 'it.provisioning.pending.v1.3.request.12.fulfil';
const fields = {
    expected_version: 4,
    evidence_summary: 'Private fulfilment evidence',
    external_ref: 'Provider receipt',
};
const outcome = (
    identity: ProvisioningCommandIdentity,
    status: 'committed' | 'not_found' | 'cancelled' = 'committed',
) => ({
    status,
    data: {
        viewer_user_id: identity.actorId,
        kind: identity.kind,
        target_id: identity.targetId,
        operation: identity.operation,
        request_uuid: identity.requestUuid,
        ...(status === 'committed'
            ? {
                  result_id: 12,
                  lock_version: 5,
                  replayed: true,
                  url: '/it/provisioning/tasks/12',
              }
            : status === 'not_found'
              ? { retry_same_command: true }
              : {}),
    },
});
const error = (status: number) =>
    new AxiosError('Synthetic response', undefined, undefined, undefined, {
        status,
        statusText: 'Synthetic',
        data: { errors: { evidence_summary: ['Explain the result.'] } },
        headers: {},
        config: { headers: {} } as never,
    });
beforeEach(() => sessionStorage.clear());
afterEach(() => vi.restoreAllMocks());

it('requires the complete original identity and a canonical result URL', () => {
    const identity = {
        ...context,
        requestUuid: 'ed4b8c20-e89b-4121-827a-bfca22d736fe',
    };
    const response = outcome(identity);
    expect(readProvisioningOutcome(response, identity)?.status).toBe(
        'committed',
    );
    for (const mismatch of [
        { viewer_user_id: 4 },
        { kind: 'workflow' },
        { target_id: 13 },
        { operation: 'cancel' },
        { request_uuid: 'other' },
        { url: 'https://example.com' },
        { url: '/it/provisioning/tasks/13' },
        { result_id: 13, url: '/it/provisioning/tasks/13' },
        { lock_version: 0 },
        { replayed: 'true' },
    ]) {
        expect(
            readProvisioningOutcome(
                { ...response, data: { ...response.data, ...mismatch } },
                identity,
            ),
        ).toBeNull();
    }
});

it('retains only the UUID and retries the frozen original after authoritative not-found', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(new Error('Lost response'));
    const get = vi.spyOn(axios, 'get');
    const { result } = renderHook(() => useProvisioningCommand(context));
    const original = { ...fields };
    await act(async () => result.current.submit(original));
    expect(result.current.phase).toBe('unknown');
    expect(sessionStorage.getItem(storageKey)).toBe(
        result.current.identity.requestUuid,
    );
    expect(sessionStorage.length).toBe(1);
    original.evidence_summary = 'Changed after send';
    await act(async () => {
        await result.current.retry();
    });
    expect(post).toHaveBeenCalledTimes(1);
    get.mockResolvedValueOnce({
        data: outcome(result.current.identity, 'not_found'),
    });
    await act(async () => {
        await result.current.recover();
    });
    expect(result.current.canRetry).toBe(true);
    post.mockResolvedValueOnce({ data: outcome(result.current.identity) });
    await act(async () => {
        await result.current.retry();
    });
    expect(post.mock.calls[1][1]).toEqual(post.mock.calls[0][1]);
    expect(result.current.phase).toBe('committed');
    expect(sessionStorage.getItem(storageKey)).toBeNull();
});

it('recovers after reload without resending private values that are no longer in memory', async () => {
    sessionStorage.setItem(storageKey, 'ed4b8c20-e89b-4121-827a-bfca22d736fe');
    const post = vi.spyOn(axios, 'post');
    const get = vi.spyOn(axios, 'get');
    const { result } = renderHook(() => useProvisioningCommand(context));
    expect(result.current.phase).toBe('unknown');
    get.mockResolvedValueOnce({
        data: outcome(result.current.identity, 'not_found'),
    });
    await act(async () => {
        await result.current.recover();
    });
    expect(result.current.canRetry).toBe(false);
    await act(async () => {
        result.current.submit(fields);
        await result.current.retry();
    });
    expect(post).not.toHaveBeenCalled();
    post.mockResolvedValueOnce({
        data: outcome(result.current.identity, 'cancelled'),
    });
    await act(async () => {
        await result.current.cancel();
    });
    expect(result.current.phase).toBe('cancelled');
    expect(post.mock.calls[0][0]).toBe(
        '/it/provisioning/commands/request/12/fulfil/cancel',
    );
});

it('suppresses late responses and further writes after an account switch', async () => {
    let resolve!: (value: unknown) => void;
    const post = vi.spyOn(axios, 'post').mockReturnValueOnce(
        new Promise((accept) => {
            resolve = accept;
        }),
    );
    const { result, rerender } = renderHook(
        (actorId: number) => useProvisioningCommand({ ...context, actorId }),
        { initialProps: 3 },
    );
    act(() => result.current.submit(fields));
    const original = result.current.identity;
    rerender(4);
    expect(result.current.concealed).toBe(true);
    await act(async () => {
        resolve({ data: outcome(original) });
    });
    expect(result.current.outcome).toBeNull();
    await act(async () => {
        result.current.submit(fields);
        await result.current.cancel();
    });
    expect(post).toHaveBeenCalledTimes(1);
});

it('keeps a mismatched success response unknown until the original receipt confirms it', async () => {
    const post = vi.spyOn(axios, 'post');
    const { result } = renderHook(() => useProvisioningCommand(context));
    post.mockResolvedValueOnce({
        data: outcome({ ...result.current.identity, targetId: 13 }),
    });
    await act(async () => result.current.submit(fields));
    expect(result.current.phase).toBe('unknown');
    expect(result.current.outcome).toBeNull();
    expect(result.current.canEdit).toBe(false);
});

it.each([401, 419, 403, 404])(
    'conceals private state after HTTP %s and prevents a new send',
    async (status) => {
        const post = vi
            .spyOn(axios, 'post')
            .mockRejectedValueOnce(error(status));
        const { result } = renderHook(() => useProvisioningCommand(context));
        await act(async () => result.current.submit(fields));
        expect(result.current.concealed).toBe(true);
        expect(result.current.canEdit).toBe(false);
        act(() => result.current.submit(fields));
        expect(post).toHaveBeenCalledTimes(1);
    },
);

it('allows correction after validation while retaining the original reference', async () => {
    vi.spyOn(axios, 'post').mockRejectedValueOnce(error(422));
    const { result } = renderHook(() => useProvisioningCommand(context));
    const identity = result.current.identity;
    await act(async () => result.current.submit(fields));
    expect(result.current.phase).toBe('validation');
    expect(result.current.canEdit).toBe(true);
    expect(result.current.errors.evidence_summary).toBe('Explain the result.');
    expect(result.current.identity).toEqual(identity);
});

it('does not send when the browser cannot retain a recovery reference', async () => {
    const post = vi.spyOn(axios, 'post');
    const { result } = renderHook(() => useProvisioningCommand(context));
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new Error('Storage unavailable');
    });
    await act(async () => result.current.submit(fields));
    expect(result.current.phase).toBe('unavailable');
    expect(post).not.toHaveBeenCalled();
});

it('survives the StrictMode effect check and ignores completion after unmount', async () => {
    let resolve!: (value: unknown) => void;
    const post = vi.spyOn(axios, 'post').mockReturnValueOnce(
        new Promise((accept) => {
            resolve = accept;
        }),
    );
    const { result, unmount } = renderHook(
        () => useProvisioningCommand(context),
        { wrapper: StrictMode },
    );
    act(() => result.current.submit(fields));
    expect(post).toHaveBeenCalledTimes(1);
    const identity = result.current.identity;
    unmount();
    await act(async () => {
        resolve({ data: outcome(identity) });
    });
    expect(sessionStorage.getItem(storageKey)).toBe(identity.requestUuid);
});
