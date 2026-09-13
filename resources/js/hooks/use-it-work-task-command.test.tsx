import { taskReadiness } from '@/test/it-work-task-fixtures';
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    freezeItWorkTaskIntent,
    itWorkTaskMutation,
    readItWorkTaskCancelled,
    readItWorkTaskCommitted,
    type ItWorkTaskIdentity,
    type ItWorkTaskOperation,
} from './it-work-task-command';
import { useItWorkTaskCommand } from './use-it-work-task-command';

const transport = vi.hoisted(() => ({
    request: vi.fn(),
    get: vi.fn(),
    post: vi.fn(),
}));
vi.mock('axios', () => ({
    default: {
        ...transport,
        isAxiosError: (value: unknown) =>
            typeof value === 'object' && value !== null && 'response' in value,
    },
}));
const uuid = '11111111-1111-4111-8111-111111111111';
const identity: ItWorkTaskIdentity = {
    actorId: 7,
    ticketId: 42,
    taskId: 10,
    operation: 'update',
    requestUuid: uuid,
    expectedVersion: 4,
};
const error = (status: number, data: unknown = {}) => ({
    response: { status, data },
});
const task = {
    id: 10,
    title: 'Check access point',
    description: 'Private operational detail',
    status: 'pending',
    due_at: null,
    is_required: true,
    evidence_required: false,
    evidence: null,
    completion_note: null,
    completed_at: null,
    sort_order: 1,
    team: null,
    assignee: null,
    completed_by: null,
    dependencies: [],
    approval: null,
    current_completion_id: null,
    readiness: taskReadiness(),
};
const review = (version = 5) => ({
    status: 200,
    data: {
        viewer_user_id: 7,
        ticket: {
            id: 42,
            lock_version: version,
            status: 'open',
            merged_into: null,
        },
        can: { manage: true },
        linked_context: { tasks: [task] },
        assignees: [{ id: 8, name: 'Eligible technician' }],
        teamOptions: [{ id: 3, name: 'Service desk' }],
        approvals: [],
    },
});
const acknowledgement = (
    original: ItWorkTaskIdentity,
    overrides: Record<string, unknown> = {},
) => ({
    status: 'committed',
    data: {
        id: original.ticketId,
        viewer_user_id: original.actorId,
        request_uuid: original.requestUuid,
        operation: `task.${original.operation}`,
        task_id: original.operation === 'create' ? 12 : original.taskId,
        lock_version: (original.expectedVersion ?? 4) + 1,
        changed: true,
        replayed: false,
        ...overrides,
    },
});
const cancelled = (original: ItWorkTaskIdentity) => ({
    status: 'cancelled',
    data: {
        id: original.ticketId,
        viewer_user_id: original.actorId,
        request_uuid: original.requestUuid,
        operation: `task.${original.operation}`,
        task_id: original.taskId,
        cancelled_at: '2026-09-09T10:00:00Z',
        replayed: false,
    },
});
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}
function setup(
    operation: ItWorkTaskOperation = 'update',
    taskId: number | null = 10,
) {
    const onCommitted = vi.fn();
    const onAccessLost = vi.fn();
    const hook = renderHook(
        (props: { actorId: number }) =>
            useItWorkTaskCommand({
                ...props,
                ticketId: 42,
                operation,
                taskId,
                onCommitted,
                onAccessLost,
            }),
        { initialProps: { actorId: 7 } },
    );
    return { ...hook, onCommitted, onAccessLost };
}
const originalFromRequest = (): ItWorkTaskIdentity => {
    const config = transport.request.mock.calls.at(-1)![0] as {
        data: {
            actor_user_id: number;
            request_uuid: string;
            expected_version: number;
        };
    };
    return {
        ...identity,
        requestUuid: config.data.request_uuid,
        expectedVersion: config.data.expected_version,
    };
};
beforeEach(() => {
    sessionStorage.clear();
    vi.clearAllMocks();
});
afterEach(cleanup);

describe('canonical task command contracts', () => {
    it('binds every mutation to its canonical route without passing context fields as task data', () => {
        for (const operation of [
            'create',
            'update',
            'complete',
            'reopen',
            'reorder',
        ] as const) {
            const fields =
                operation === 'create'
                    ? { title: 'New task' }
                    : operation === 'reopen'
                      ? { reason: 'Verify again' }
                      : operation === 'reorder'
                        ? { ordered_ids: [10, 12] }
                        : {};
            const intent = freezeItWorkTaskIntent({
                ...identity,
                operation,
                taskId:
                    operation === 'create' || operation === 'reorder'
                        ? null
                        : 10,
                fields,
            })!;
            expect(intent).not.toBeNull();
            const mutation = itWorkTaskMutation(intent);
            expect(mutation.url).toBe(
                `/it/tickets/42/tasks${operation === 'create' ? '' : operation === 'reorder' ? '/reorder' : operation === 'update' ? '/10' : `/10/${operation}`}`,
            );
            expect(mutation.data).toMatchObject({
                actor_user_id: 7,
                expected_version: 4,
                request_uuid: uuid,
            });
            expect(mutation.data).not.toHaveProperty('taskId');
        }
    });
    it('freezes ordered dependencies and rejects forged transport or completion state fields', () => {
        const dependencies = [9, 8];
        const intent = freezeItWorkTaskIntent({
            ...identity,
            fields: { dependency_ids: dependencies },
        })!;
        dependencies.push(7);
        expect(intent.fields.dependency_ids).toEqual([9, 8]);
        expect(Object.isFrozen(intent.fields.dependency_ids)).toBe(true);
        expect(
            freezeItWorkTaskIntent({
                ...identity,
                fields: { actor_user_id: 99 },
            }),
        ).toBeNull();
        expect(
            freezeItWorkTaskIntent({
                ...identity,
                fields: { status: 'completed' },
            }),
        ).toBeNull();
        expect(
            freezeItWorkTaskIntent({
                ...identity,
                fields: { dependency_ids: [9, 9] },
            }),
        ).toBeNull();
    });
    it('requires exact actor, task, operation and original version even on replay, with genuine no-op acknowledgement', () => {
        expect(
            readItWorkTaskCommitted(acknowledgement(identity), identity),
        ).not.toBeNull();
        for (const replacement of [
            { viewer_user_id: 8 },
            { task_id: 11 },
            { operation: 'task.complete' },
            { lock_version: 7 },
            { request_uuid: '22222222-2222-4222-8222-222222222222' },
        ])
            expect(
                readItWorkTaskCommitted(
                    acknowledgement(identity, replacement),
                    identity,
                ),
            ).toBeNull();
        expect(
            readItWorkTaskCommitted(
                acknowledgement(identity, { changed: false, lock_version: 4 }),
                identity,
            )?.changed,
        ).toBe(false);
        expect(
            readItWorkTaskCommitted(
                acknowledgement(identity, { replayed: true }),
                identity,
            )?.lock_version,
        ).toBe(5);
    });
    it('accepts only an explicit exact cancellation tombstone', () => {
        expect(
            readItWorkTaskCancelled(cancelled(identity), identity),
        ).not.toBeNull();
        expect(
            readItWorkTaskCancelled(
                {
                    ...cancelled(identity),
                    data: { ...cancelled(identity).data, task_id: 12 },
                },
                identity,
            ),
        ).toBeNull();
        expect(readItWorkTaskCancelled({}, identity)).toBeNull();
    });
});

describe('task command recovery', () => {
    it('announces success only after an exact committed result and clears only its opaque reference', async () => {
        transport.request.mockImplementation(
            async (config: { data: { request_uuid: string } }) => ({
                status: 200,
                data: acknowledgement({
                    ...identity,
                    requestUuid: config.data.request_uuid,
                }),
            }),
        );
        const { result, onCommitted } = setup();
        act(() => {
            result.current.submit({ description: 'Changed detail' }, 4);
        });
        await waitFor(() => expect(result.current.stage).toBe('committed'));
        expect(onCommitted).toHaveBeenCalledOnce();
        expect(result.current.references).toEqual([]);
        expect(sessionStorage.length).toBe(0);
    });
    it('keeps unknown payload and UUID exact across retry without adopting a newer task version', async () => {
        transport.request.mockRejectedValueOnce(error(503));
        const { result } = setup();
        act(() => {
            result.current.submit({ description: 'Original proposal' }, 4);
        });
        await waitFor(() => expect(result.current.stage).toBe('unknown'));
        const original = originalFromRequest();
        transport.request.mockResolvedValueOnce({
            status: 200,
            data: acknowledgement(original, { replayed: true }),
        });
        act(() => {
            result.current.retry();
        });
        await waitFor(() => expect(result.current.stage).toBe('committed'));
        expect(transport.request.mock.calls[1][0].data).toEqual(
            transport.request.mock.calls[0][0].data,
        );
    });
    it('retains an unknown original after current review; adoption cannot abandon the pending write', async () => {
        transport.request.mockRejectedValueOnce(error(503));
        const { result } = setup();
        act(() => {
            result.current.submit({ title: 'Proposal' }, 4);
        });
        await waitFor(() => expect(result.current.stage).toBe('unknown'));
        transport.get.mockResolvedValueOnce(review(4));
        await act(() => result.current.reviewCurrent());
        expect(result.current.stage).toBe('reviewed');
        expect(result.current.adoptReview()).toBeNull();
        expect(result.current.pendingIntent?.fields.title).toBe('Proposal');
        expect(result.current.references).toHaveLength(1);
    });
    it('uses receipt 404 as unknown, then explicit serialized cancellation permits a separate reviewed proposal', async () => {
        transport.request.mockRejectedValueOnce(error(503));
        const { result } = setup();
        act(() => {
            result.current.submit({ title: 'Proposal' }, 4);
        });
        await waitFor(() => expect(result.current.stage).toBe('unknown'));
        const original = originalFromRequest();
        transport.get.mockRejectedValueOnce(error(404));
        act(() => {
            result.current.recover();
        });
        await waitFor(() => expect(result.current.concealed).toBe(true));
        expect(result.current.references).toEqual([original.requestUuid]);
        transport.post.mockResolvedValueOnce({
            status: 200,
            data: cancelled(original),
        });
        act(() => {
            result.current.cancelCommand();
        });
        await waitFor(() => expect(result.current.cancelled).not.toBeNull());
        expect(transport.post.mock.calls[0][1]).toEqual({
            actor_user_id: 7,
            task_id: 10,
        });
        expect(result.current.canEdit).toBe(false);
        transport.get.mockResolvedValueOnce(review(6));
        await act(() => result.current.reviewCurrent());
        act(() => {
            expect(result.current.adoptReview()?.version).toBe(6);
        });
        expect(result.current.canEdit).toBe(true);
        expect(transport.request).toHaveBeenCalledOnce();
    });
    it('definitive validation is editable while a validation error after unknown outcome keeps the frozen intent', async () => {
        transport.request.mockRejectedValueOnce(
            error(422, {
                errors: {
                    title: ['Provide a title.'],
                    provider_output: ['private provider data'],
                },
            }),
        );
        const { result } = setup();
        act(() => {
            result.current.submit({ title: 'Title' }, 4);
        });
        await waitFor(() => expect(result.current.stage).toBe('rejected'));
        expect(result.current.canEdit).toBe(true);
        expect(result.current.errors).toEqual({ title: 'Provide a title.' });
        expect(result.current.settledOperationToken).toBe(1);
        transport.request.mockRejectedValueOnce(error(503));
        act(() => {
            result.current.submit({ title: 'Corrected title' }, 4);
        });
        await waitFor(() => expect(result.current.stage).toBe('unknown'));
        transport.request.mockRejectedValueOnce(error(422));
        act(() => {
            result.current.retry();
        });
        await waitFor(() => expect(transport.request).toHaveBeenCalledTimes(3));
        await waitFor(() => expect(result.current.stage).toBe('unknown'));
        expect(result.current.canEdit).toBe(false);
        expect(result.current.pendingIntent?.fields.title).toBe(
            'Corrected title',
        );
    });
    it('requires explicit current review and adoption after a known stale rejection without auto-submit', async () => {
        transport.request.mockRejectedValueOnce(
            error(409, {
                code: 'stale_ticket',
                errors: { expected_version: ['Ticket changed.'] },
            }),
        );
        const { result } = setup();
        act(() => {
            result.current.submit({ title: 'My proposal' }, 4);
        });
        await waitFor(() => expect(result.current.stage).toBe('conflict'));
        expect(result.current.references).toEqual([]);
        transport.get.mockResolvedValueOnce(review(8));
        await act(() => result.current.reviewCurrent());
        expect(result.current.canEdit).toBe(false);
        act(() => {
            expect(result.current.adoptReview()?.version).toBe(8);
        });
        expect(transport.request).toHaveBeenCalledOnce();
        expect(result.current.canEdit).toBe(true);
    });
    it('conceals session-expired private work until exact current proof; actor mismatch purges it', async () => {
        transport.request.mockRejectedValueOnce(error(419));
        const { result, onAccessLost } = setup();
        act(() => {
            result.current.submit({ title: 'Private proposal' }, 4);
        });
        await waitFor(() => expect(result.current.stage).toBe('session'));
        expect(result.current.concealed).toBe(true);
        expect(result.current.pendingIntent).not.toBeNull();
        expect(onAccessLost).not.toHaveBeenCalled();
        const changedActor = review();
        changedActor.data.viewer_user_id = 8;
        transport.get.mockResolvedValueOnce(changedActor);
        await act(() => result.current.reviewCurrent());
        expect(result.current.stage).toBe('access');
        expect(result.current.pendingIntent).toBeNull();
        expect(onAccessLost).toHaveBeenCalledOnce();
    });
    it('ignores a cancelled wait response and retains the original command', async () => {
        const pending = deferred<{ status: number; data: unknown }>();
        transport.request.mockReturnValueOnce(pending.promise);
        const { result, onCommitted } = setup();
        act(() => {
            result.current.submit({ title: 'Waiting' }, 4);
        });
        const original = originalFromRequest();
        act(() => {
            result.current.cancelWait();
        });
        await act(async () =>
            pending.resolve({ status: 200, data: acknowledgement(original) }),
        );
        expect(result.current.stage).toBe('unknown');
        expect(result.current.references).toEqual([original.requestUuid]);
        expect(onCommitted).not.toHaveBeenCalled();
    });
    it('ignores late review after cancellation without exposing an adoptable version', async () => {
        const pending = deferred<ReturnType<typeof review>>();
        transport.get.mockReturnValueOnce(pending.promise);
        const { result } = setup();
        let checking!: Promise<void>;
        act(() => {
            checking = result.current.reviewCurrent();
        });
        act(() => {
            result.current.cancelWait();
        });
        await act(async () => {
            pending.resolve(review());
            await checking;
        });
        expect(result.current.review).toBeNull();
        expect(result.current.adoptReview()).toBeNull();
    });
    it('hard-remount recovers opaque identity without reading or fabricating a missing private body', async () => {
        transport.request.mockRejectedValueOnce(error(503));
        const first = setup();
        act(() => {
            first.result.current.submit(
                { description: 'Secret operational proposal' },
                4,
            );
        });
        await waitFor(() => expect(first.result.current.stage).toBe('unknown'));
        const original = originalFromRequest();
        expect(sessionStorage.getItem(sessionStorage.key(0)!)).toBe(
            JSON.stringify([original.requestUuid]),
        );
        first.unmount();
        const second = setup();
        expect(second.result.current.pendingIntent).toBeNull();
        transport.get.mockResolvedValueOnce({
            status: 200,
            data: acknowledgement(original, { replayed: true }),
        });
        act(() => {
            second.result.current.recover(original.requestUuid);
        });
        await waitFor(() =>
            expect(second.result.current.stage).toBe('committed'),
        );
        expect(transport.get.mock.calls.at(-1)?.[1].params).toEqual({
            actor_user_id: 7,
            task_id: 10,
        });
    });
});
