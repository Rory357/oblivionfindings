import { act, cleanup, renderHook } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    draftContextKey,
    readDraftMetadata,
    type ItDraftContext,
    type ItDraftSnapshot,
} from './it-ticket-draft-contract';
import {
    createItDraftMemoryStore,
    type ItLocalDraftMemoryCandidate,
} from './it-ticket-draft-memory';
import {
    clearItTicketDraftMemory,
    useItTicketDraftMemory,
} from './use-it-ticket-draft-memory';

vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
        isAxiosError: (value: unknown) =>
            typeof value === 'object' && value !== null && 'response' in value,
    },
}));
const post = vi.mocked(axios.post);
const context: ItDraftContext = {
    purpose: 'task_work',
    ticketId: 42,
    taskId: 10,
    operation: 'update',
};
const uuid = '11111111-1111-4111-8111-111111111111';
const command = {
    actorId: 7,
    ticketId: 42,
    taskId: 10,
    operation: 'update' as const,
    requestUuid: '22222222-2222-4222-8222-222222222222',
    expectedVersion: 4,
    fields: { description: 'Exact pending proposal', assigned_to_user_id: 8 },
};
const snapshot: ItDraftSnapshot = {
    fields: {
        description: 'Unsaved task proposal',
        assigned_to_user_id: 8,
        dependency_ids: [9],
    },
    step_index: 3,
    base_ticket_version: 4,
};
const candidate = (): ItLocalDraftMemoryCandidate => ({
    kind: 'memory',
    memoryUuid: uuid,
    actorId: 7,
    context,
    revision: 0,
    snapshot,
    outcomeUnknown: false,
});
const opts = (
    changes: Partial<Parameters<typeof useItTicketDraftMemory>[0]> = {},
): Parameters<typeof useItTicketDraftMemory>[0] => ({
    enabled: true,
    persistenceEnabled: false,
    actorId: 7,
    context,
    draft: null,
    outcomeUnknown: false,
    ...changes,
});
const proof = (
    input: Record<string, unknown>,
    changes: Record<string, unknown> = {},
) => ({
    status: 200,
    data: {
        candidate: {
            kind: 'memory',
            memory_uuid: input.memory_uuid,
            candidate_uuid: input.candidate_uuid,
            actor_user_id: input.actor_user_id,
            purpose: 'task_work',
            context_key: draftContextKey(context),
            base_ticket_version: input.base_ticket_version,
            current_ticket_version: 6,
            authorized: true,
            capabilities: { submit: false },
            blocker: {
                code: 'stale_ticket',
                message: 'Review the current task.',
            },
            ...changes,
        },
    },
});
beforeEach(() => {
    clearItTicketDraftMemory();
    post.mockReset();
});
afterEach(cleanup);

describe('task work in the existing bounded RAM inventory', () => {
    it('authorizes prior, current and pending approval generations before returning recovered work', async () => {
        const original = opts({
            workingSnapshot: {
                ...snapshot,
                fields: { ...snapshot.fields, approval_id: 20 },
            },
            workingDirty: true,
        });
        const mounted = renderHook(useItTicketDraftMemory, {
            initialProps: original,
        });
        mounted.rerender({
            ...original,
            workingSnapshot: {
                ...snapshot,
                fields: { ...snapshot.fields, approval_id: 21 },
            },
        });
        mounted.rerender({
            ...original,
            workingSnapshot: {
                ...snapshot,
                fields: { ...snapshot.fields, approval_id: null },
            },
            outcomeUnknown: true,
            pendingTask: {
                ...command,
                fields: { ...command.fields, approval_id: 21 },
            },
        });
        mounted.unmount();
        const back = renderHook(() => useItTicketDraftMemory(opts()));
        expect(back.result.current.notices).toHaveLength(1);
        post.mockImplementation(async (_url, raw) =>
            proof(raw as Record<string, unknown>),
        );
        await act(async () => {
            await back.result.current.resume(
                back.result.current.notices[0].bufferId,
            );
        });
        const body = post.mock.calls[0][1] as Record<string, unknown>;
        expect(post.mock.calls[0][0]).toBe(
            '/it/tickets/42/task-candidates/validate',
        );
        expect(body).toMatchObject({
            fields: { approval_id: null },
            pending_task: { fields: { approval_id: 21 } },
        });
        expect(body.bound_scopes).toEqual(
            expect.arrayContaining([
                expect.objectContaining({ approval_ids: [20] }),
                expect.objectContaining({ approval_ids: [21] }),
            ]),
        );
    });

    it('requires retention for a new pending command even when it matches a deliberately cleared snapshot', () => {
        const original = opts({
            workingSnapshot: snapshot,
            workingDirty: true,
        });
        const current = renderHook(useItTicketDraftMemory, {
            initialProps: original,
        });
        act(() => current.result.current.clearOwnedWork());
        act(() =>
            expect(current.result.current.ensureLatestRetained()).toEqual({
                status: 'not_needed',
            }),
        );
        current.rerender({
            ...original,
            pendingTask: command,
            outcomeUnknown: true,
        });
        act(() =>
            expect(current.result.current.ensureLatestRetained()).toEqual({
                status: 'retained',
            }),
        );
        current.unmount();
        const back = renderHook(() => useItTicketDraftMemory(opts()));
        expect(back.result.current.notices).toHaveLength(1);
        expect(back.result.current.notices[0]).toMatchObject({
            hasPendingTask: true,
            outcomeUnknown: true,
        });
    });

    it('blocks retaining a new historical selection at the limit and keeps the earlier exact copy', async () => {
        const original = opts({
            workingSnapshot: {
                fields: {
                    description: 'Earlier retained task work',
                    assigned_to_user_id: 1,
                },
                step_index: 1,
                base_ticket_version: 4,
            },
            workingDirty: true,
        });
        const current = renderHook(useItTicketDraftMemory, {
            initialProps: original,
        });
        for (let person = 2; person <= 100; person++)
            current.rerender({
                ...original,
                workingSnapshot: {
                    ...original.workingSnapshot!,
                    fields: {
                        description: 'Earlier retained task work',
                        assigned_to_user_id: person,
                    },
                },
            });
        act(() =>
            expect(current.result.current.ensureLatestRetained()).toEqual({
                status: 'retained',
            }),
        );
        current.rerender({
            ...original,
            workingSnapshot: {
                ...original.workingSnapshot!,
                fields: {
                    description: 'Latest proposal remains in form',
                    assigned_to_user_id: 101,
                },
            },
        });
        act(() =>
            expect(current.result.current.ensureLatestRetained()).toMatchObject(
                { status: 'blocked', reason: 'bindings' },
            ),
        );
        expect(current.result.current.warning).toMatch(/reference limit/);
        current.unmount();
        const back = renderHook(() => useItTicketDraftMemory(opts()));
        expect(back.result.current.notices).toHaveLength(1);
        post.mockImplementation(async (_url, raw) =>
            proof(raw as Record<string, unknown>),
        );
        let recovered!: Awaited<ReturnType<typeof back.result.current.resume>>;
        await act(async () => {
            recovered = await back.result.current.resume(
                back.result.current.notices[0].bufferId,
            );
        });
        expect(recovered?.candidate.snapshot.fields).toEqual({
            description: 'Earlier retained task work',
            assigned_to_user_id: 100,
        });
        expect(recovered?.candidate.boundScopes).toHaveLength(100);
    });

    it('separates different nested tasks and operations and never recognizes a persisted task draft', () => {
        const memory = createItDraftMemoryStore({
            maxEntries: 3,
            maxBytes: 100000,
        });
        expect(memory.retain(candidate()).status).toBe('stored');
        expect(memory.discover(7, context)).toHaveLength(1);
        expect(memory.discover(7, { ...context, taskId: 11 })).toEqual([]);
        expect(
            memory.discover(7, { ...context, operation: 'complete' }),
        ).toEqual([]);
        expect(memory.discover(8, context)).toEqual([]);
        expect(readDraftMetadata({ purpose: 'task_work' }, context)).toBeNull();
        const {
            memoryUuid: _memoryUuid,
            kind: _kind,
            selectedFiles: _selectedFiles,
            ...rest
        } = candidate();
        expect(
            memory.retain({ ...rest, kind: 'persisted', draftUuid: uuid })
                .status,
        ).toBe('invalid');
    });
    it('rejects cross-operation fields, file inventory and mismatched pending identity', () => {
        const memory = createItDraftMemoryStore({
            maxEntries: 3,
            maxBytes: 100000,
        });
        expect(
            memory.retain({
                ...candidate(),
                snapshot: { ...snapshot, fields: { body: 'A public reply' } },
            }).status,
        ).toBe('invalid');
        expect(
            memory.retain({
                ...candidate(),
                selectedFiles: [new File(['x'], 'file.txt')],
            }).status,
        ).toBe('invalid');
        expect(
            memory.retain({
                ...candidate(),
                pendingTask: { ...command, taskId: 11 },
                outcomeUnknown: true,
            }).status,
        ).toBe('invalid');
    });
    it('freezes pending UUID/version/body while newer working text can remain recoverable', () => {
        const memory = createItDraftMemoryStore({
            maxEntries: 3,
            maxBytes: 100000,
        });
        const value = {
            ...candidate(),
            pendingTask: command,
            outcomeUnknown: true,
        };
        const retained = memory.retain(value);
        if (retained.status !== 'stored')
            throw new Error('Expected retained task');
        expect(retained.notice.hasPendingTask).toBe(true);
        expect(JSON.stringify(retained.notice)).not.toContain('proposal');
        expect(
            memory.retain(
                {
                    ...value,
                    snapshot: {
                        ...snapshot,
                        fields: { description: 'Newest local text' },
                    },
                },
                retained.notice.bufferId,
            ).status,
        ).toBe('stored');
        expect(
            memory.retain(
                {
                    ...value,
                    pendingTask: {
                        ...command,
                        fields: { description: 'A different command' },
                    },
                },
                retained.notice.bufferId,
            ).status,
        ).toBe('frozen');
        expect(
            memory.retain(
                { ...value, pendingTask: { ...command, expectedVersion: 5 } },
                retained.notice.bufferId,
            ).status,
        ).toBe('invalid');
    });
    it('retains the latest form across unmount and sends exact task context plus all historical selections before disclosure', async () => {
        const first = renderHook(useItTicketDraftMemory, {
            initialProps: opts({
                workingSnapshot: snapshot,
                workingDirty: true,
            }),
        });
        first.rerender(
            opts({
                workingSnapshot: {
                    ...snapshot,
                    fields: {
                        description: 'Newest proposal',
                        assigned_to_user_id: 12,
                        team_id: 3,
                        dependency_ids: [11],
                    },
                },
                workingDirty: true,
            }),
        );
        first.rerender(
            opts({
                workingSnapshot: {
                    ...snapshot,
                    fields: {
                        description: 'Newest proposal',
                        assigned_to_user_id: null,
                        dependency_ids: [],
                    },
                },
                workingDirty: true,
            }),
        );
        first.unmount();
        const back = renderHook(() => useItTicketDraftMemory(opts()));
        expect(back.result.current.notices).toHaveLength(1);
        post.mockImplementation(async (_url, raw) =>
            proof(raw as Record<string, unknown>),
        );
        let restored!: Awaited<ReturnType<typeof back.result.current.resume>>;
        await act(async () => {
            restored = await back.result.current.resume(
                back.result.current.notices[0].bufferId,
            );
        });
        expect(post).toHaveBeenCalledWith(
            '/it/tickets/42/task-candidates/validate',
            expect.objectContaining({
                actor_user_id: 7,
                ticket_id: 42,
                purpose: 'task_work',
                operation: 'update',
                task_id: 10,
                step_index: 3,
                base_ticket_version: 4,
                bound_scopes: expect.arrayContaining([
                    { assigned_to_user_id: 8, dependency_ids: [9] },
                    {
                        assigned_to_user_id: 12,
                        team_id: 3,
                        dependency_ids: [11],
                    },
                ]),
            }),
            expect.anything(),
        );
        expect(restored?.candidate.snapshot.fields.description).toBe(
            'Newest proposal',
        );
        expect(restored?.candidate.snapshot.base_ticket_version).toBe(4);
        expect(restored?.localAuthorization?.capabilities.submit).toBe(false);
        expect(restored?.serverResumed).toBeNull();
    });
    it('requires nonce-bound proof for exact nested context; malformed proof retains the concealed copy', async () => {
        const first = renderHook(() =>
            useItTicketDraftMemory(
                opts({ workingSnapshot: snapshot, workingDirty: true }),
            ),
        );
        first.unmount();
        const back = renderHook(() => useItTicketDraftMemory(opts()));
        post.mockImplementation(async (_url, raw) =>
            proof(raw as Record<string, unknown>, {
                context_key: 'ticket:42:task:11:operation:update',
            }),
        );
        await act(async () => {
            expect(
                await back.result.current.resume(
                    back.result.current.notices[0].bufferId,
                ),
            ).toBeNull();
        });
        expect(back.result.current.notices).toHaveLength(1);
    });
    it('includes exact pending command in fresh proof and retains a handoff until its command client accepts it', async () => {
        const first = renderHook(() =>
            useItTicketDraftMemory(
                opts({
                    workingSnapshot: snapshot,
                    workingDirty: true,
                    pendingTask: command,
                    outcomeUnknown: true,
                }),
            ),
        );
        first.unmount();
        const back = renderHook(useItTicketDraftMemory, {
            initialProps: opts(),
        });
        post.mockImplementation(async (_url, raw) =>
            proof(raw as Record<string, unknown>),
        );
        await act(async () => {
            const restored = await back.result.current.resume(
                back.result.current.notices[0].bufferId,
            );
            expect(restored?.candidate.pendingTask).toEqual(command);
        });
        expect(post.mock.calls[0][1]).toMatchObject({
            pending_task: {
                actor_user_id: 7,
                ticket_id: 42,
                request_uuid: command.requestUuid,
                operation: 'update',
                task_id: 10,
                expected_version: 4,
                fields: command.fields,
            },
        });
        back.unmount();
        const again = renderHook(() => useItTicketDraftMemory(opts()));
        expect(again.result.current.notices).toHaveLength(1);
        expect(again.result.current.notices[0].hasPendingTask).toBe(true);
    });
    it('removes denied work but retains concealed session-expired recovery', async () => {
        const first = renderHook(() =>
            useItTicketDraftMemory(
                opts({ workingSnapshot: snapshot, workingDirty: true }),
            ),
        );
        first.unmount();
        const back = renderHook(() => useItTicketDraftMemory(opts()));
        post.mockRejectedValueOnce({ response: { status: 419 } });
        await act(async () => {
            expect(
                await back.result.current.resume(
                    back.result.current.notices[0].bufferId,
                ),
            ).toBeNull();
        });
        expect(back.result.current.notices).toHaveLength(1);
        post.mockRejectedValueOnce({ response: { status: 403 } });
        await act(async () => {
            expect(
                await back.result.current.resume(
                    back.result.current.notices[0].bufferId,
                ),
            ).toBeNull();
        });
        expect(back.result.current.notices).toHaveLength(0);
    });
});
