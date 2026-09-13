import { act, cleanup, renderHook } from '@testing-library/react';
import axios, { type AxiosRequestConfig } from 'axios';
import { afterEach, expect, it, vi } from 'vitest';
import { useItTicketMergePreview } from './use-it-ticket-merge-preview';

const selection = {
    actorId: 7,
    sourceId: 41,
    targetId: 42,
    sourceVersion: 2,
    targetVersion: 4,
};
function result(config: AxiosRequestConfig) {
    const params = config.params as Record<string, number | string>;
    const record = (id: number, version: number) => ({
        id,
        reference: `IT-${String(id).padStart(6, '0')}`,
        lock_version: version,
        title: 'Synthetic preview',
        status: 'open',
        workflow_state: 'submitted',
        work_type: 'incident',
        inventory: {
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
        },
    });
    return {
        status: 200,
        data: {
            status: 'reviewed',
            data: {
                review_token: 'synthetic-encrypted-review',
                viewer_user_id: params.actor_user_id,
                review_nonce: params.review_nonce,
                source: record(41, Number(params.source_version)),
                target: record(
                    Number(params.target_ticket_id),
                    Number(params.target_version),
                ),
                access_scope_differences: [],
                lifecycle_blockers: [],
            },
        },
    };
}
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

it('reads only on demand and sends the exact actor, pair and versions', async () => {
    const get = vi
        .spyOn(axios, 'get')
        .mockImplementation(async (_url, config) => result(config!));
    const hook = renderHook(() => useItTicketMergePreview(selection));
    expect(get).not.toHaveBeenCalled();
    await act(async () => {
        await hook.result.current.load();
    });
    expect(get).toHaveBeenCalledWith(
        '/it/tickets/41/merge-preview',
        expect.objectContaining({
            params: expect.objectContaining({
                actor_user_id: 7,
                target_ticket_id: 42,
                source_version: 2,
                target_version: 4,
            }),
        }),
    );
    expect(hook.result.current.preview?.target.id).toBe(42);
});

it.each([
    [419, 'session'],
    [403, 'access'],
    [404, 'access'],
    [409, 'stale'],
    [500, 'failed'],
] as const)(
    'conceals failed review data for HTTP %s and allows a separate retry',
    async (status, failure) => {
        const get = vi.spyOn(axios, 'get').mockRejectedValueOnce({
            isAxiosError: true,
            response: { status },
        });
        const hook = renderHook(() => useItTicketMergePreview(selection));
        await act(async () => {
            await hook.result.current.load();
        });
        expect(hook.result.current.failure).toBe(failure);
        expect(hook.result.current.preview).toBeNull();
        get.mockImplementationOnce(async (_url, config) => result(config!));
        await act(async () => {
            await hook.result.current.load();
        });
        expect(hook.result.current.preview?.source.id).toBe(41);
    },
);

it.each(['cancel', 'actor', 'target', 'version', 'unmount'] as const)(
    'ignores a delayed result after %s and aborts its old request',
    async (change) => {
        let finish!: (value: unknown) => void;
        const get = vi.spyOn(axios, 'get').mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const hook = renderHook(useItTicketMergePreview, {
            initialProps: selection,
        });
        let loading!: ReturnType<typeof hook.result.current.load>;
        act(() => {
            loading = hook.result.current.load();
        });
        const config = get.mock.calls[0][1]!;
        if (change === 'cancel') act(() => hook.result.current.cancel());
        else if (change === 'unmount') hook.unmount();
        else
            hook.rerender({
                ...selection,
                ...(change === 'actor'
                    ? { actorId: 8 }
                    : change === 'target'
                      ? { targetId: 43 }
                      : { sourceVersion: 3 }),
            });
        expect(config.signal?.aborted).toBe(true);
        await act(async () => {
            finish(result(config));
            await loading;
        });
        if (change !== 'unmount')
            expect(hook.result.current.preview).toBeNull();
    },
);
