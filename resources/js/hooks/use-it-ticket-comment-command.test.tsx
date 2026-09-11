import { act, cleanup, renderHook } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { ItCommentIntent } from './it-ticket-comment-contract';
import { useItTicketCommentCommand } from './use-it-ticket-comment-command';

const key = 'it.pending-comment-command.v1.7:9:public';

it('external audience denial drops frozen content, retains only its recovery reference and ignores a late commit', async () => {
    const request = pending<ReturnType<typeof committed>>();
    vi.mocked(axios.post).mockReturnValueOnce(request.promise);
    const { result, onCommitted, onAccessLost } = setup();
    act(() => {
        result.current.submit(fields());
    });
    const uuid = result.current.requestUuid!;
    act(() => result.current.denyCurrentAccess());
    expect(result.current.concealed).toBe(true);
    expect(result.current.frozenIntent).toBeNull();
    expect(vi.mocked(axios.post).mock.calls[0][2]?.signal?.aborted).toBe(true);
    expect(sessionStorage.getItem(key)).toBe(JSON.stringify([uuid]));
    expect(onAccessLost).not.toHaveBeenCalled();
    await act(async () => request.resolve(committed(uuid)));
    expect(onCommitted).not.toHaveBeenCalled();
    expect(result.current.stage).toBe('access');
    vi.mocked(axios.post).mockImplementationOnce(proof);
    await act(async () => {
        expect(await result.current.checkCurrentAccess()).toBe(true);
    });
    expect(result.current.stage).toBe('unknown');
    expect(result.current.canRetryExact).toBe(false);
    expect(result.current.submit(fields())).toBe(false);
});

it('an empty externally denied composer needs fresh ephemeral proof without creating a submission marker', async () => {
    const { result } = setup();
    act(() => result.current.denyCurrentAccess());
    expect(result.current.submit(fields())).toBe(false);
    vi.mocked(axios.post).mockImplementationOnce(proof);
    await act(async () => {
        expect(await result.current.checkCurrentAccess()).toBe(true);
    });
    expect(result.current.concealed).toBe(false);
    expect(result.current.stage).toBe('editing');
    expect(result.current.requestUuid).toBeNull();
    expect(result.current.reviewedVersion).toBe(8);
    expect(sessionStorage.getItem(key)).toBeNull();
    expect(vi.mocked(axios.post).mock.calls[0][0]).toBe(
        '/it/drafts/validate-local-candidate',
    );
});

it('the listed recovery control checks the same frozen command without dropping its strict identity', async () => {
    vi.mocked(axios.post).mockRejectedValueOnce(fail());
    const { result } = setup();
    await act(async () => {
        result.current.submit(fields());
    });
    const uuid = result.current.requestUuid!;
    vi.mocked(axios.get).mockResolvedValueOnce(
        committed(uuid, 200, { lock_version: 4 }),
    );
    await act(async () => result.current.recoverReference(uuid));
    expect(axios.get).toHaveBeenCalledOnce();
    expect(result.current.stage).toBe('unknown');
    expect(result.current.frozenIntent?.expectedVersion).toBe(4);
    vi.mocked(axios.get).mockResolvedValueOnce(committed(uuid, 200));
    await act(async () => result.current.recoverReference(uuid));
    expect(result.current.stage).toBe('committed');
});
const draftUuid = '50bab5e1-ab7e-4f30-bd4c-8454d805bf12';
const fields = () => ({
    body: 'Original public reply',
    expectedVersion: 4,
    files: [
        new File(['original bytes'], 'evidence.txt', { type: 'text/plain' }),
    ],
});
const fail = (status?: number, data: unknown = {}) => ({
    isAxiosError: true,
    response: status ? { status, data } : undefined,
});
function pending<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
}
function committed(
    uuid: string,
    status = 201,
    extra: Record<string, unknown> = {},
) {
    return {
        status,
        data: {
            status: 'committed',
            data: {
                id: 9,
                canonical_ticket_id: 9,
                comment_id: 13,
                viewer_user_id: 7,
                request_uuid: uuid,
                is_internal: false,
                lock_version: 7,
                replayed: status === 200,
                delivery: { requested: false, attempt_statuses: {} },
                ...extra,
            },
        },
    };
}
const proof = (_url: unknown, raw: unknown) => {
    const data = raw as Record<string, unknown>;
    return Promise.resolve({
        status: 200,
        data: {
            candidate: {
                kind: 'memory',
                memory_uuid: data.memory_uuid,
                candidate_uuid: data.candidate_uuid,
                actor_user_id: data.actor_user_id,
                purpose: data.purpose,
                context_key: `ticket:${data.ticket_id}`,
                base_ticket_version: data.base_ticket_version,
                current_ticket_version: 8,
                authorized: true,
                capabilities: { submit: true },
                blocker: null,
            },
        },
    });
};
function setup(
    changes: Partial<Parameters<typeof useItTicketCommentCommand>[0]> = {},
) {
    const onCommitted = vi.fn();
    const onAccessLost = vi.fn();
    return {
        onCommitted,
        onAccessLost,
        ...renderHook((props) => useItTicketCommentCommand(props), {
            initialProps: {
                actorId: 7,
                ticketId: 9,
                isInternal: false,
                onCommitted,
                onAccessLost,
                ...changes,
            },
        }),
    };
}
beforeEach(() => {
    sessionStorage.clear();
    vi.spyOn(axios, 'post').mockRejectedValue(
        new Error('Unexpected synthetic POST'),
    );
    vi.spyOn(axios, 'get').mockRejectedValue(
        new Error('Unexpected synthetic GET'),
    );
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

it('records only the opaque UUID before sending and suppresses a double submit', async () => {
    const request = pending<ReturnType<typeof committed>>();
    vi.mocked(axios.post).mockReturnValueOnce(request.promise);
    const { result, onCommitted } = setup();
    act(() => {
        expect(result.current.submit(fields())).toBe(true);
        expect(result.current.submit(fields())).toBe(false);
    });
    expect(sessionStorage.getItem(key)).toBe(
        JSON.stringify([result.current.requestUuid]),
    );
    expect(sessionStorage.getItem(key)).not.toContain('Original');
    await act(async () =>
        request.resolve(committed(result.current.requestUuid!)),
    );
    expect(result.current.stage).toBe('committed');
    expect(onCommitted).toHaveBeenCalledTimes(1);
    expect(sessionStorage.getItem(key)).toBeNull();
});

it.each([202, 206])(
    'rejects a committed-shaped HTTP%s without clearing the original journal',
    async (status) => {
        const request = pending<ReturnType<typeof committed>>();
        vi.mocked(axios.post).mockReturnValueOnce(request.promise);
        const { result, onCommitted } = setup();
        act(() => {
            result.current.submit(fields());
        });
        await act(async () =>
            request.resolve(committed(result.current.requestUuid!, status)),
        );
        expect(result.current.stage).toBe('unknown');
        expect(result.current.concealed).toBe(true);
        expect(onCommitted).not.toHaveBeenCalled();
        expect(sessionStorage.getItem(key)).toContain(
            result.current.requestUuid,
        );
    },
);

it('retains the exact text files version and UUID across timeout and retry', async () => {
    const data = fields();
    vi.mocked(axios.post).mockRejectedValueOnce(fail());
    const { result } = setup();
    await act(async () => {
        result.current.submit(data);
    });
    const uuid = result.current.requestUuid!;
    data.body = 'Later private text';
    data.expectedVersion = 99;
    data.files = [];
    const retry = pending<ReturnType<typeof committed>>();
    vi.mocked(axios.post).mockReturnValueOnce(retry.promise);
    act(() => result.current.retryExact());
    const sent = vi.mocked(axios.post).mock.calls[1][1] as FormData;
    expect(sent.get('request_uuid')).toBe(uuid);
    expect(sent.get('body')).toBe('Original public reply');
    expect(sent.get('expected_version')).toBe('4');
    expect(await (sent.get('attachments[]') as File).text()).toBe(
        'original bytes',
    );
    await act(async () => retry.resolve(committed(uuid, 200)));
    expect(result.current.stage).toBe('committed');
});

it('stopping wait ignores a late success without claiming rollback', async () => {
    const request = pending<ReturnType<typeof committed>>();
    vi.mocked(axios.post).mockReturnValueOnce(request.promise);
    const { result, onCommitted } = setup();
    act(() => {
        result.current.submit(fields());
    });
    const uuid = result.current.requestUuid!;
    act(() => result.current.stopWaiting());
    expect(vi.mocked(axios.post).mock.calls[0][2]?.signal?.aborted).toBe(true);
    await act(async () => request.resolve(committed(uuid)));
    expect(result.current.stage).toBe('unknown');
    expect(result.current.frozenIntent?.requestUuid).toBe(uuid);
    expect(onCommitted).not.toHaveBeenCalled();
});

it('session concealment survives pending recovery and 404 until fresh canonical permission proof', async () => {
    vi.mocked(axios.post).mockRejectedValueOnce(fail(419));
    const { result } = setup();
    await act(async () => {
        result.current.submit(fields());
    });
    expect(result.current.concealed).toBe(true);
    const lookup = pending<never>();
    vi.mocked(axios.get).mockReturnValueOnce(lookup.promise);
    act(() => result.current.recover());
    expect(result.current.stage).toBe('recovering');
    expect(result.current.concealed).toBe(true);
    await act(async () => lookup.reject(fail(404)));
    expect(result.current.concealed).toBe(true);
    expect(result.current.canRetryExact).toBe(false);
    vi.mocked(axios.post).mockImplementationOnce(proof);
    await act(async () => {
        expect(await result.current.checkCurrentAccess()).toBe(true);
    });
    expect(result.current.reviewedVersion).toBe(8);
    expect(result.current.concealed).toBe(false);
    expect(result.current.canRetryExact).toBe(true);
    expect(result.current.frozenIntent?.expectedVersion).toBe(4);
});

it('an opaque-reference 404 never permits a duplicate new intent', async () => {
    sessionStorage.setItem(key, JSON.stringify([draftUuid]));
    vi.mocked(axios.get).mockRejectedValueOnce(fail(404));
    const { result } = setup();
    await act(async () => result.current.recoverReference(draftUuid));
    expect(result.current.stage).toBe('unknown');
    expect(result.current.releaseKnownRejection(8)).toBe(false);
    expect(result.current.submit(fields())).toBe(false);
    expect(sessionStorage.getItem(key)).toContain(draftUuid);
});

it('only an explicit exact fresh version releases a known 422 rejection', async () => {
    vi.mocked(axios.post).mockRejectedValueOnce(
        fail(422, { errors: { body: ['Correct this reply.'] } }),
    );
    const { result } = setup();
    await act(async () => {
        result.current.submit(fields());
    });
    expect(result.current.errors.body).toBe('Correct this reply.');
    expect(result.current.releaseKnownRejection(8)).toBe(false);
    vi.mocked(axios.post).mockImplementationOnce(proof);
    await act(async () => {
        await result.current.checkCurrentAccess();
    });
    act(() => {
        expect(result.current.releaseKnownRejection(9)).toBe(false);
        expect(result.current.releaseKnownRejection(8)).toBe(true);
    });
    expect(result.current.stage).toBe('editing');
    expect(result.current.settledOperationToken).toBe(1);
    expect(sessionStorage.getItem(key)).toBeNull();
});

it('idempotency conflict cannot release a journal or retry a changed intent', async () => {
    vi.mocked(axios.post).mockRejectedValueOnce(
        fail(409, { code: 'idempotency_conflict' }),
    );
    const { result } = setup();
    await act(async () => {
        result.current.submit(fields());
    });
    vi.mocked(axios.post).mockImplementationOnce(proof);
    await act(async () => {
        await result.current.checkCurrentAccess();
    });
    act(() => result.current.retryExact());
    expect(axios.post).toHaveBeenCalledTimes(2);
    expect(result.current.releaseKnownRejection(8)).toBe(false);
    expect(result.current.canRetryExact).toBe(false);
    expect(sessionStorage.getItem(key)).toContain(result.current.requestUuid);
});

it('a committed callback failure preserves commit and a second reply needs explicit prepareNext', async () => {
    const request = pending<ReturnType<typeof committed>>();
    vi.mocked(axios.post).mockReturnValueOnce(request.promise);
    const { result } = setup({
        onCommitted: () => {
            throw new Error('Synthetic host failure');
        },
    });
    act(() => {
        result.current.submit(fields());
    });
    const first = result.current.requestUuid!;
    await act(async () => request.resolve(committed(first)));
    expect(result.current.stage).toBe('committed');
    expect(result.current.submit(fields())).toBe(false);
    act(() => {
        expect(result.current.prepareNext()).toBe(true);
    });
    vi.mocked(axios.post).mockRejectedValueOnce(fail());
    await act(async () => {
        expect(
            result.current.submit({
                ...fields(),
                body: 'Second reply',
                files: [],
            }),
        ).toBe(true);
    });
    expect(result.current.requestUuid).not.toBe(first);
    expect(result.current.frozenIntent?.body).toBe('Second reply');
});

it('actor or audience changes abort pending work and suppress old callbacks', async () => {
    const request = pending<ReturnType<typeof committed>>();
    vi.mocked(axios.post).mockReturnValueOnce(request.promise);
    const hook = setup();
    act(() => {
        hook.result.current.submit(fields());
    });
    const uuid = hook.result.current.requestUuid!;
    hook.rerender({
        actorId: 8,
        ticketId: 9,
        isInternal: true,
        onCommitted: hook.onCommitted,
        onAccessLost: hook.onAccessLost,
    });
    await act(async () => request.resolve(committed(uuid)));
    expect(hook.result.current.frozenIntent).toBeNull();
    expect(hook.onCommitted).not.toHaveBeenCalled();
    expect(sessionStorage.getItem(key)).toContain(uuid);
});

it('revocation purges the remembered payload even when host cleanup throws', async () => {
    vi.mocked(axios.post).mockRejectedValueOnce(fail(403));
    const { result } = setup({
        onAccessLost: () => {
            throw new Error('Host failure');
        },
    });
    await act(async () => {
        result.current.submit(fields());
    });
    expect(result.current.stage).toBe('access');
    expect(result.current.concealed).toBe(true);
    expect(result.current.frozenIntent).toBeNull();
});

it('a RAM intent needs the matching journal and fresh nonce proof before exact adoption', async () => {
    const candidate: ItCommentIntent = {
        actorId: 7,
        ticketId: 9,
        requestUuid: draftUuid,
        isInternal: false,
        ...fields(),
    };
    const { result } = setup();
    await act(async () => {
        expect(await result.current.adoptAuthorizedIntent(candidate)).toBe(
            false,
        );
    });
    expect(axios.post).not.toHaveBeenCalled();
    sessionStorage.setItem(key, JSON.stringify([draftUuid]));
    vi.mocked(axios.post).mockImplementationOnce(proof);
    await act(async () => {
        expect(await result.current.adoptAuthorizedIntent(candidate)).toBe(
            true,
        );
    });
    expect(result.current.frozenIntent?.files[0]).toBe(candidate.files[0]);
    expect(result.current.frozenIntent?.expectedVersion).toBe(4);
    expect(result.current.canRetryExact).toBe(true);
});

it('storage failure stops submission before any HTTP request', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new Error('Storage unavailable');
    });
    const { result } = setup();
    act(() => {
        expect(result.current.submit(fields())).toBe(false);
    });
    expect(axios.post).not.toHaveBeenCalled();
});

const cancelled = (uuid: string, extra: Record<string, unknown> = {}) => ({
    status: 200,
    data: {
        status: 'cancelled',
        data: {
            id: 9,
            viewer_user_id: 7,
            request_uuid: uuid,
            is_internal: false,
            cancelled_at: '2026-09-09T19:30:00Z',
            replayed: false,
            ...extra,
        },
    },
});

it('explicit cancellation closes an opaque unknown reference but requires fresh review before another intent', async () => {
    sessionStorage.setItem(key, JSON.stringify([draftUuid]));
    const { result, onCommitted } = setup();
    vi.mocked(axios.post).mockResolvedValueOnce(cancelled(draftUuid));
    await act(async () => {
        expect(await result.current.cancelReference(draftUuid)).toBe(true);
    });
    expect(axios.post).toHaveBeenCalledWith(
        `/it/tickets/9/comment-commands/${draftUuid}/cancel`,
        { actor_user_id: 7, is_internal: false },
        expect.objectContaining({ signal: expect.any(AbortSignal) }),
    );
    expect(result.current.cancelled?.request_uuid).toBe(draftUuid);
    expect(result.current.stage).toBe('rejected');
    expect(onCommitted).not.toHaveBeenCalled();
    expect(result.current.submit(fields())).toBe(false);
    expect(result.current.releaseKnownRejection(8)).toBe(false);
    vi.mocked(axios.post).mockImplementationOnce(proof);
    await act(async () => {
        await result.current.checkCurrentAccess();
    });
    act(() => {
        expect(result.current.releaseKnownRejection(8)).toBe(true);
    });
    expect(result.current.stage).toBe('editing');
    expect(sessionStorage.getItem(key)).toBeNull();
});

it('cancellation which loses the write race acknowledges the original committed comment and canonical location', async () => {
    sessionStorage.setItem(key, JSON.stringify([draftUuid]));
    vi.mocked(axios.post).mockResolvedValueOnce(
        committed(draftUuid, 200, { canonical_ticket_id: 22 }),
    );
    const { result, onCommitted } = setup();
    await act(async () => {
        expect(await result.current.cancelReference(draftUuid)).toBe(true);
    });
    expect(result.current.result).toMatchObject({
        id: 9,
        canonical_ticket_id: 22,
        lock_version: 7,
    });
    expect(result.current.cancelled).toBeNull();
    expect(onCommitted).toHaveBeenCalledOnce();
});

it.each(['recovery', 'delayed submit'])(
    'accepts a cancelled receipt from %s without claiming a comment was added',
    async (mode) => {
        const { result, onCommitted } = setup();
        const request = pending<ReturnType<typeof cancelled>>();
        vi.mocked(axios.post).mockReturnValueOnce(request.promise);
        act(() => {
            result.current.submit(fields());
        });
        const uuid = result.current.requestUuid!;
        if (mode === 'recovery') {
            act(() => result.current.stopWaiting());
            vi.mocked(axios.get).mockResolvedValueOnce(
                cancelled(uuid, { replayed: true }),
            );
            await act(async () => result.current.recover());
        } else
            await act(async () =>
                request.resolve(cancelled(uuid, { replayed: true })),
            );
        expect(result.current.stage).toBe('rejected');
        expect(result.current.frozenIntent).toBeNull();
        expect(result.current.cancelled?.request_uuid).toBe(uuid);
        expect(onCommitted).not.toHaveBeenCalled();
    },
);

it('stopping a cancellation wait retains its journal and ignores its late tombstone', async () => {
    sessionStorage.setItem(key, JSON.stringify([draftUuid]));
    const request = pending<ReturnType<typeof cancelled>>();
    vi.mocked(axios.post).mockReturnValueOnce(request.promise);
    const { result } = setup();
    let completion: Promise<boolean>;
    act(() => {
        completion = result.current.cancelReference(draftUuid);
    });
    act(() => result.current.stopWaiting());
    await act(async () => {
        request.resolve(cancelled(draftUuid));
        expect(await completion).toBe(false);
    });
    expect(result.current.cancelled).toBeNull();
    expect(result.current.stage).toBe('unknown');
    expect(sessionStorage.getItem(key)).toContain(draftUuid);
});

it('a cancellation with wrong audience cannot release the reference or reveal prior content', async () => {
    sessionStorage.setItem(key, JSON.stringify([draftUuid]));
    vi.mocked(axios.post).mockResolvedValueOnce(
        cancelled(draftUuid, { is_internal: true }),
    );
    const { result } = setup();
    await act(async () => {
        expect(await result.current.cancelReference(draftUuid)).toBe(false);
    });
    expect(result.current.concealed).toBe(true);
    expect(sessionStorage.getItem(key)).toContain(draftUuid);
});
