import { act, cleanup, renderHook } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { draftRecord } from './it-ticket-draft-contract';
import type { ItMergePreview } from './it-ticket-merge-preview';
import {
    pendingItMergeCommands,
    useItTicketMergeCommand,
} from './use-it-ticket-merge-command';

const inventory = {
    public_comments: 0,
    internal_notes: 0,
    ticket_files: 0,
    comment_files: 0,
    watchers: 0,
    links: 0,
    tasks: 0,
    unfinished_required_tasks: 0,
    approval_requests: 0,
    pending_approval_requests: 0,
    expired_approval_requests: 0,
};
const record = (id: number, version: number) => ({
    id,
    reference: `IT-${String(id).padStart(6, '0')}`,
    title: 'Private synthetic ticket',
    lock_version: version,
    status: 'open',
    workflow_state: 'submitted',
    work_type: 'incident',
    inventory,
});
const preview: ItMergePreview = {
    source: record(41, 2),
    target: record(42, 4),
    review_token: 'private-review-proof',
    access_scope_differences: [],
    lifecycle_blockers: [],
};
const setup = () => {
    const onSettled = vi.fn();
    const onConceal = vi.fn();
    return {
        onSettled,
        onConceal,
        ...renderHook(
            ({ actorId }) =>
                useItTicketMergeCommand({
                    actorId,
                    sourceId: 41,
                    onSettled,
                    onConceal,
                }),
            { initialProps: { actorId: 7 } },
        ),
    };
};
function requestUuid(body: unknown): string {
    if (!draftRecord(body) || typeof body.request_uuid !== 'string') {
        throw new Error(
            'The actual merge request must carry its command UUID.',
        );
    }
    return body.request_uuid;
}

function response(uuid: string, cancelled = false) {
    return {
        status: 200,
        data: {
            status: cancelled ? 'cancelled' : 'committed',
            data: {
                viewer_user_id: 7,
                source_id: 41,
                target_id: 42,
                operation: 'ticket.merge',
                request_uuid: uuid,
                source_version: 4,
                target_version: 5,
                url: '/it/tickets/42?merged_from=41',
                replayed: true,
                cancelled_at: '2026-09-10T03:00:00Z',
            },
        },
    };
}
function failure(status: number, data: unknown = {}) {
    return { isAxiosError: true, response: { status, data } };
}
beforeEach(() => sessionStorage.clear());
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    sessionStorage.clear();
});

it('reports only an exact merge receipt and removes its opaque recovery reference', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (_url, body) => response(requestUuid(body)));
    const hook = setup();
    await act(() =>
        hook.result.current.send(preview, 'Private duplicate reason'),
    );
    expect(post).toHaveBeenCalledTimes(1);
    expect(hook.result.current.stage).toBe('committed');
    expect(hook.onSettled).toHaveBeenCalledWith(
        expect.objectContaining({ status: 'committed' }),
    );
    expect(pendingItMergeCommands(7, 41)).toEqual([]);
});

it('an unknown outcome retains the exact proposal for retry and stores no private text or proof', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(failure(500))
        .mockImplementationOnce(async (_url, body) =>
            response(requestUuid(body)),
        );
    const hook = setup();
    await act(() =>
        hook.result.current.send(preview, 'Private duplicate reason'),
    );
    expect(hook.result.current.stage).toBe('unknown');
    expect(hook.onSettled).not.toHaveBeenCalled();
    expect(hook.result.current.canRetry).toBe(true);
    const stored = sessionStorage.getItem(sessionStorage.key(0)!);
    expect(stored).not.toContain('Private');
    expect(stored).not.toContain('private-review-proof');
    await act(() => hook.result.current.send(preview, 'Changed reason'));
    expect(post).toHaveBeenCalledTimes(1);
    await act(() => hook.result.current.retry());
    expect(post.mock.calls[1][1]).toEqual(post.mock.calls[0][1]);
    expect(hook.result.current.stage).toBe('committed');
});

it('generic success and wrong-pair receipts remain unknown', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockResolvedValueOnce({ status: 200, data: { success: true } })
        .mockImplementationOnce(async (_url, body) => {
            const result = response(requestUuid(body));
            result.data.data.target_id = 43;
            return result;
        });
    const hook = setup();
    await act(() => hook.result.current.send(preview, 'Same duplicate'));
    expect(hook.result.current.stage).toBe('unknown');
    await act(() => hook.result.current.retry());
    expect(post).toHaveBeenCalledTimes(2);
    expect(hook.result.current.stage).toBe('unknown');
    expect(hook.onSettled).not.toHaveBeenCalled();
});

it('stop waiting ignores a late response and serialized cancellation decides the actual result', async () => {
    let resolve!: (value: ReturnType<typeof response>) => void;
    const post = vi.spyOn(axios, 'post').mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    const hook = setup();
    let sending!: Promise<void>;
    act(() => {
        sending = hook.result.current.send(preview, 'Same duplicate');
    });
    const uuid = requestUuid(post.mock.calls[0][1]);
    act(() => hook.result.current.stop());
    expect(hook.result.current.stage).toBe('unknown');
    post.mockResolvedValueOnce(response(uuid, true));
    await act(() => hook.result.current.cancel());
    expect(post.mock.calls[1][0]).toBe(
        `/it/tickets/41/merge-commands/${uuid}/cancel`,
    );
    expect(hook.result.current.stage).toBe('cancelled');
    await act(async () => {
        resolve(response(uuid));
        await sending;
    });
    expect(hook.result.current.stage).toBe('cancelled');
    expect(hook.onSettled).toHaveBeenCalledTimes(1);
});

it('a commit that wins cancellation is reported as committed', async () => {
    const post = vi.spyOn(axios, 'post').mockRejectedValueOnce(failure(500));
    const hook = setup();
    await act(() => hook.result.current.send(preview, 'Same duplicate'));
    post.mockResolvedValueOnce(
        response(pendingItMergeCommands(7, 41)[0].requestUuid),
    );
    await act(() => hook.result.current.cancel());
    expect(hook.result.current.stage).toBe('committed');
});

it.each([401, 419, 403, 404])(
    'conceals private proposal on HTTP %s but retains opaque recovery identity',
    async (status) => {
        vi.spyOn(axios, 'post').mockRejectedValue(failure(status));
        const hook = setup();
        await act(() =>
            hook.result.current.send(preview, 'Private duplicate reason'),
        );
        expect(hook.result.current.concealed).toBe(true);
        expect(hook.result.current.canRetry).toBe(false);
        expect(hook.onConceal).toHaveBeenCalledWith(
            status === 401 || status === 419 ? 'session' : 'access',
        );
        expect(pendingItMergeCommands(7, 41)).toHaveLength(1);
    },
);

it('receipt 404 is not proof of failure and conceals details while preserving explicit cancellation', async () => {
    vi.spyOn(axios, 'post').mockRejectedValueOnce(failure(500));
    vi.spyOn(axios, 'get').mockRejectedValueOnce(failure(404));
    const hook = setup();
    await act(() =>
        hook.result.current.send(preview, 'Private duplicate reason'),
    );
    await act(() => hook.result.current.check());
    expect(hook.result.current.message).toContain('does not prove');
    expect(hook.result.current.concealed).toBe(true);
    expect(hook.onSettled).not.toHaveBeenCalled();
    expect(pendingItMergeCommands(7, 41)).toHaveLength(1);
});

it('a rejected fresh review allows a new deliberate command but rejection after uncertainty does not erase it', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(
            failure(422, { errors: { review_token: ['Review changed.'] } }),
        );
    const hook = setup();
    await act(() => hook.result.current.send(preview, 'Same duplicate'));
    expect(hook.result.current.stage).toBe('rejected');
    expect(hook.result.current.message).toBe('Review changed.');
    expect(pendingItMergeCommands(7, 41)).toEqual([]);
    post.mockRejectedValueOnce(failure(500)).mockRejectedValueOnce(
        failure(422),
    );
    await act(() => hook.result.current.send(preview, 'Same duplicate'));
    await act(() => hook.result.current.retry());
    expect(hook.result.current.stage).toBe('unknown');
    expect(pendingItMergeCommands(7, 41)).toHaveLength(1);
});

it('remount recovers by opaque identity without resubmitting private content', async () => {
    const post = vi.spyOn(axios, 'post').mockRejectedValueOnce(failure(500));
    const first = setup();
    await act(() =>
        first.result.current.send(preview, 'Private duplicate reason'),
    );
    const reference = pendingItMergeCommands(7, 41)[0];
    first.unmount();
    const get = vi
        .spyOn(axios, 'get')
        .mockResolvedValueOnce(response(reference.requestUuid));
    const next = setup();
    expect(next.result.current.references).toEqual([reference]);
    expect(next.result.current.canRetry).toBe(false);
    await act(() => next.result.current.check(reference));
    expect(post).toHaveBeenCalledTimes(1);
    expect(get.mock.calls[0][1]?.params).toEqual({
        actor_user_id: 7,
        target_ticket_id: 42,
    });
    expect(next.result.current.stage).toBe('committed');
});

it('actor change suppresses old responses and stale callbacks without exposing the original reference', async () => {
    let resolve!: (value: ReturnType<typeof response>) => void;
    const post = vi.spyOn(axios, 'post').mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    const hook = setup();
    let sending!: Promise<void>;
    const staleSend = hook.result.current.send;
    act(() => {
        sending = staleSend(preview, 'Private duplicate reason');
    });
    const uuid = requestUuid(post.mock.calls[0][1]);
    hook.rerender({ actorId: 8 });
    await act(async () => {
        resolve(response(uuid));
        await sending;
        await staleSend(preview, 'Old actor');
    });
    expect(hook.onSettled).not.toHaveBeenCalled();
    expect(hook.result.current.references).toEqual([]);
    expect(post).toHaveBeenCalledTimes(1);
    expect(pendingItMergeCommands(7, 41)).toHaveLength(1);
});

it('a browser storage failure prevents a new irreversible request', async () => {
    const post = vi.spyOn(axios, 'post');
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new Error('Synthetic storage failure');
    });
    const hook = setup();
    await act(() => hook.result.current.send(preview, 'Same duplicate'));
    expect(post).not.toHaveBeenCalled();
    expect(hook.result.current.message).toContain('No new request was sent');
});

it.each(['command_conflict', 'unrecognized_conflict'])(
    'does not erase the original receipt reference on %s',
    async (code) => {
        vi.spyOn(axios, 'post').mockRejectedValueOnce(failure(409, { code }));
        const hook = setup();
        await act(() => hook.result.current.send(preview, 'Same duplicate'));
        expect(hook.result.current.stage).toBe('unknown');
        expect(pendingItMergeCommands(7, 41)).toHaveLength(1);
    },
);

it('a definite stale-ticket rejection permits a separately reviewed new command', async () => {
    vi.spyOn(axios, 'post').mockRejectedValueOnce(
        failure(409, { code: 'stale_ticket' }),
    );
    const hook = setup();
    await act(() => hook.result.current.send(preview, 'Same duplicate'));
    expect(hook.result.current.stage).toBe('rejected');
    expect(pendingItMergeCommands(7, 41)).toEqual([]);
});

it('a host callback failure cannot turn a verified receipt into an unknown merge', async () => {
    vi.spyOn(axios, 'post').mockImplementation(async (_url, body) =>
        response(requestUuid(body)),
    );
    const hook = setup();
    hook.onSettled.mockImplementation(() => {
        throw new Error('Synthetic navigation failure');
    });
    await act(() => hook.result.current.send(preview, 'Same duplicate'));
    expect(hook.result.current.stage).toBe('committed');
    expect(hook.result.current.message).toContain('merge is confirmed');
});

it('keeps the exact proposal after an authorized unconfirmed receipt and settles only a matching retry', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(failure(500))
        .mockImplementationOnce(async (_url, body) =>
            response(requestUuid(body)),
        );
    const hook = setup();
    await act(() =>
        hook.result.current.send(preview, 'Retained private reason'),
    );
    const identity = hook.result.current.references[0];
    vi.spyOn(axios, 'get').mockRejectedValue(
        failure(404, {
            code: 'merge_receipt_unconfirmed',
            viewer_user_id: 7,
            source_id: 41,
            target_id: 42,
            request_uuid: identity.requestUuid,
        }),
    );
    await act(() => hook.result.current.check(identity));
    expect(hook.result.current.concealed).toBe(false);
    expect(hook.result.current.canRetry).toBe(true);
    expect(hook.result.current.outcomeUnknown).toBe(true);
    expect(hook.result.current.reviewed()).toBe(false);
    await act(() => hook.result.current.retry());
    expect(post.mock.calls[1][1]).toEqual(post.mock.calls[0][1]);
    expect(hook.result.current.outcomeUnknown).toBe(false);
    expect(hook.result.current.settledOperationToken).toBe(1);
});
