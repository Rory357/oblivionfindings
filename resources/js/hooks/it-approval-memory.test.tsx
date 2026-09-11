import { act, cleanup, renderHook } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    draftAudience,
    draftContextKey,
    readDraftMetadata,
    type ItDraftContext,
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
    purpose: 'approval_work',
    ticketId: 42,
    operation: 'request',
    approvalId: null,
};
const uuid = '11111111-1111-4111-8111-111111111111';
const pending = {
    actorId: 7,
    ticketId: 42,
    operation: 'request' as const,
    approvalId: null,
    requestUuid: '22222222-2222-4222-8222-222222222222',
    expectedVersion: 4,
    fields: {
        reason: 'Original private approval proposal',
        primary_approver_user_id: 8,
        cover_approver_user_id: 9,
    },
};
const candidate = (): ItLocalDraftMemoryCandidate => ({
    kind: 'memory',
    memoryUuid: uuid,
    actorId: 7,
    context,
    revision: 0,
    snapshot: {
        fields: { reason: 'Later private draft', primary_approver_user_id: 8 },
        step_index: 1,
        base_ticket_version: 4,
    },
    pendingApproval: pending,
    outcomeUnknown: true,
});
const options = (
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
beforeEach(() => {
    clearItTicketDraftMemory();
    post.mockReset();
});
afterEach(cleanup);

it('keeps approval content internal and rejects persisted draft metadata or foreign operation fields', () => {
    expect(draftAudience('approval_work')).toBe('internal');
    expect(readDraftMetadata({ purpose: 'approval_work' }, context)).toBeNull();
    const memory = createItDraftMemoryStore({ maxEntries: 4, maxBytes: 64000 });
    expect(
        memory.retain({
            ...candidate(),
            snapshot: {
                ...candidate().snapshot,
                fields: { body: 'Public text' },
            },
        }).status,
    ).toBe('invalid');
    expect(
        memory.retain({
            ...candidate(),
            pendingApproval: { ...pending, ticketId: 43 },
        }).status,
    ).toBe('invalid');
    expect(
        memory.retain({
            ...candidate(),
            context: { ...context, operation: 'withdraw', approvalId: 10 },
        }).status,
    ).toBe('invalid');
    expect(
        memory.retain({
            ...candidate(),
            selectedFiles: [new File(['private'], 'private.txt')],
        }).status,
    ).toBe('invalid');
});

it('keeps unknown original approval intent immutable and exposes only scoped recovery metadata', async () => {
    const memory = createItDraftMemoryStore({ maxEntries: 4, maxBytes: 64000 });
    const result = memory.retain(candidate());
    expect(result.status).toBe('stored');
    if (result.status !== 'stored') throw new Error('Missing retained fixture');
    expect(JSON.stringify(memory.discoverApprovalWork(7, 42))).not.toContain(
        'private',
    );
    expect(memory.discoverApprovalWork(8, 42)).toEqual([]);
    expect(memory.discoverApprovalWork(7, 43)).toEqual([]);
    expect(
        memory.retain(
            {
                ...candidate(),
                pendingApproval: {
                    ...pending,
                    fields: { ...pending.fields, primary_approver_user_id: 10 },
                },
            },
            result.notice.bufferId,
        ).status,
    ).toBe('frozen');
    const binding = { actorId: 7, context };
    expect(
        await memory.authorizeResume(
            result.notice.bufferId,
            binding,
            async (copy) => ({
                status: 'response',
                httpStatus: 200,
                body: {
                    candidate: {
                        kind: 'memory',
                        memory_uuid: uuid,
                        candidate_uuid: copy.candidateUuid,
                        actor_user_id: 8,
                        purpose: 'approval_work',
                        context_key: draftContextKey(context),
                        base_ticket_version: 4,
                        current_ticket_version: 4,
                        authorized: true,
                        capabilities: { submit: true },
                        blocker: null,
                    },
                },
            }),
        ),
    ).toEqual({ status: 'failed' });
    expect(memory.discoverApprovalWork(7, 42)).toHaveLength(1);
    expect(
        await memory.authorizeResume(
            result.notice.bufferId,
            binding,
            async () => ({ status: 'denied' }),
        ),
    ).toEqual({ status: 'denied' });
    expect(memory.discoverApprovalWork(7, 42)).toHaveLength(0);
});

it('checks all historical selections and the original pending intent before explicit Resume', async () => {
    const original = options({
        workingDirty: true,
        workingSnapshot: candidate().snapshot,
    });
    const mounted = renderHook(useItTicketDraftMemory, {
        initialProps: original,
    });
    mounted.rerender({
        ...original,
        workingSnapshot: {
            ...candidate().snapshot,
            fields: {
                reason: 'Later private draft',
                primary_approver_user_id: 10,
                cover_approver_user_id: 11,
            },
        },
    });
    mounted.rerender({
        ...original,
        pendingApproval: pending,
        outcomeUnknown: true,
        workingSnapshot: {
            ...candidate().snapshot,
            fields: {
                reason: 'Last local draft',
                primary_approver_user_id: null,
            },
        },
    });
    mounted.unmount();
    const back = renderHook(() => useItTicketDraftMemory(options()));
    expect(back.result.current.notices).toHaveLength(1);
    expect(post).not.toHaveBeenCalled();
    post.mockImplementation(async (_url, raw) => {
        const input = raw as Record<string, unknown>;
        return {
            status: 200,
            data: {
                candidate: {
                    kind: 'memory',
                    memory_uuid: input.memory_uuid,
                    candidate_uuid: input.candidate_uuid,
                    actor_user_id: 7,
                    purpose: 'approval_work',
                    context_key: draftContextKey(context),
                    base_ticket_version: 4,
                    current_ticket_version: 6,
                    authorized: true,
                    capabilities: { submit: false },
                    blocker: {
                        code: 'ticket_changed',
                        message: 'Review current approval work.',
                    },
                },
            },
        };
    });
    await act(async () => {
        const result = await back.result.current.resume(
            back.result.current.notices[0].bufferId,
        );
        expect(
            result?.candidate.pendingApproval?.fields.primary_approver_user_id,
        ).toBe(8);
        expect(result?.candidate.snapshot.fields.reason).toBe(
            'Last local draft',
        );
    });
    expect(post.mock.calls[0][0]).toBe(
        '/it/tickets/42/approval-candidates/validate',
    );
    expect(post.mock.calls[0][1]).toMatchObject({
        approval_id: null,
        operation: 'request',
        pending_approval: {
            actor_user_id: 7,
            ticket_id: 42,
            expected_version: 4,
            fields: { primary_approver_user_id: 8, cover_approver_user_id: 9 },
        },
        bound_scopes: expect.arrayContaining([
            { primary_approver_user_id: 8 },
            { primary_approver_user_id: 10, cover_approver_user_id: 11 },
        ]),
    });
});

it('purges retained approval content when the authenticated actor changes', () => {
    const current = renderHook(useItTicketDraftMemory, {
        initialProps: options({
            workingDirty: true,
            workingSnapshot: candidate().snapshot,
        }),
    });
    current.unmount();
    const other = renderHook(() =>
        useItTicketDraftMemory(options({ actorId: 8 })),
    );
    expect(other.result.current.notices).toEqual([]);
    other.unmount();
    const original = renderHook(() => useItTicketDraftMemory(options()));
    expect(original.result.current.notices).toEqual([]);
});
