import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    type ItApprovalIdentity,
    type ItApprovalIntent,
    type ItApprovalOperation,
} from './it-ticket-approval-contract';
import {
    pendingItApprovalCommands,
    useItTicketApprovalCommand,
} from './use-it-ticket-approval-command';

const transport = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('axios', () => ({
    default: {
        ...transport,
        isAxiosError: (value: unknown) =>
            typeof value === 'object' && value !== null && 'response' in value,
    },
}));
const uuid = '11111111-1111-4111-8111-111111111111';
const secondUuid = '22222222-2222-4222-8222-222222222222';
const key = (
    actor = 7,
    operation = 'request',
    approval: number | null = null,
) =>
    `it.pending-approval-command.v1.${actor}:42:${operation}:${approval ?? 'collection'}`;
const error = (status: number, data: unknown = {}) => ({
    response: { status, data },
});
const original = (
    operation: ItApprovalOperation = 'request',
): ItApprovalIntent => ({
    actorId: 7,
    ticketId: 42,
    operation,
    approvalId: operation === 'request' ? null : 10,
    requestUuid: uuid,
    expectedVersion: 4,
    fields: {
        reason: 'Private approval proposal',
        ...(operation === 'decide' ? { decision: 'approve' as const } : {}),
    },
});
const committed = (
    identity: ItApprovalIdentity,
    overrides: Record<string, unknown> = {},
) => ({
    status: 'committed',
    data: {
        id: identity.ticketId,
        viewer_user_id: identity.actorId,
        request_uuid: identity.requestUuid,
        operation: `approval.${identity.operation}`,
        approval_id: identity.approvalId ?? 12,
        approval_status:
            identity.operation === 'request' ? 'pending' : 'approved',
        lock_version: (identity.expectedVersion ?? 4) + 1,
        changed: true,
        replayed: false,
        ...overrides,
    },
});
const cancelled = (identity: ItApprovalIdentity, replayed = false) => ({
    status: 'cancelled',
    data: {
        id: identity.ticketId,
        viewer_user_id: identity.actorId,
        request_uuid: identity.requestUuid,
        operation: `approval.${identity.operation}`,
        approval_id: identity.approvalId,
        cancelled_at: '2026-09-10T01:00:00Z',
        replayed,
    },
});
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => {
        resolve = done;
    });
    return { resolve, promise };
}
function setup(operation: ItApprovalOperation = 'request') {
    const onCommitted = vi.fn();
    const onAccessLost = vi.fn();
    const onSessionExpired = vi.fn();
    const hook = renderHook(
        (props: { actorId: number; ticketId: number }) =>
            useItTicketApprovalCommand({
                ...props,
                operation,
                approvalId: operation === 'request' ? null : 10,
                onCommitted,
                onAccessLost,
                onSessionExpired,
            }),
        { initialProps: { actorId: 7, ticketId: 42 } },
    );
    return { ...hook, onCommitted, onAccessLost, onSessionExpired };
}
function proof(operation: ItApprovalOperation = 'request', version = 5) {
    return {
        actorId: 7,
        ticketId: 42,
        operation,
        approvalId: operation === 'request' ? null : 10,
        currentTicketVersion: version,
    };
}
beforeEach(() => {
    sessionStorage.clear();
    transport.get.mockReset();
    transport.post.mockReset();
    vi.spyOn(crypto, 'randomUUID').mockReturnValue(uuid);
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('approval command transport', () => {
    it('recovers a committed approval cancellation under its own opaque target without rewriting other commands', async () => {
        const intent = original('withdraw');
        sessionStorage.setItem(key(7, 'withdraw', 10), JSON.stringify([uuid]));
        sessionStorage.setItem(key(), JSON.stringify([secondUuid]));
        const hook = setup('withdraw');
        transport.get.mockResolvedValueOnce({
            status: 200,
            data: committed(intent, {
                approval_status: 'cancelled',
                replayed: true,
            }),
        });
        await act(async () => {
            await hook.result.current.recover(uuid);
        });
        expect(hook.onCommitted).toHaveBeenCalledOnce();
        expect(hook.result.current.result?.approval_status).toBe('cancelled');
        expect(sessionStorage.getItem(key(7, 'withdraw', 10))).toBeNull();
        expect(sessionStorage.getItem(key())).toBe(
            JSON.stringify([secondUuid]),
        );
        expect(pendingItApprovalCommands(7, 42)).toHaveLength(1);
        expect(transport.post).not.toHaveBeenCalled();
    });
    it('journals only opaque identity before sending and strictly acknowledges one requested generation', async () => {
        const pending = deferred<unknown>();
        transport.post.mockImplementationOnce(() => {
            expect(sessionStorage.getItem(key())).toBe(JSON.stringify([uuid]));
            return pending.promise;
        });
        const hook = setup();
        const fields = { reason: 'Private approval proposal' };
        act(() => expect(hook.result.current.submit(fields, 4)).toBe(true));
        fields.reason = 'Newer local text';
        expect(hook.result.current.busy).toBe(true);
        expect(hook.result.current.canEdit).toBe(false);
        expect(hook.result.current.submit(fields, 5)).toBe(false);
        expect(sessionStorage.getItem(key())).not.toContain('Private');
        expect(transport.post).toHaveBeenCalledWith(
            '/it/tickets/42/approvals',
            {
                actor_user_id: 7,
                request_uuid: uuid,
                expected_version: 4,
                reason: 'Private approval proposal',
            },
            expect.objectContaining({
                timeout: 30000,
                signal: expect.any(AbortSignal),
            }),
        );
        await act(async () =>
            pending.resolve({ status: 201, data: committed(original()) }),
        );
        expect(hook.result.current.stage).toBe('committed');
        expect(hook.onCommitted).toHaveBeenCalledExactlyOnceWith(
            expect.objectContaining({ approval_id: 12 }),
            original(),
        );
        expect(sessionStorage.getItem(key())).toBeNull();
        expect(hook.result.current.pendingIntent).toBeNull();
        act(() => expect(hook.result.current.reset()).toBe(true));
        expect(hook.result.current.canEdit).toBe(true);
    });

    it('uses the nested exact approval endpoint and preserves decision intent during retries', async () => {
        transport.post
            .mockRejectedValueOnce(new Error('network'))
            .mockResolvedValueOnce({
                status: 200,
                data: committed(original('decide'), { replayed: true }),
            });
        const hook = setup('decide');
        act(() => hook.result.current.submit(original('decide').fields, 4));
        await waitFor(() => expect(hook.result.current.stage).toBe('unknown'));
        const first = transport.post.mock.calls[0];
        await act(async () => {
            expect(await hook.result.current.retry()).toBe(true);
        });
        expect(first[0]).toBe('/it/tickets/42/approvals/10/decide');
        expect(transport.post.mock.calls[1].slice(0, 2)).toEqual(
            first.slice(0, 2),
        );
        expect(hook.onCommitted).toHaveBeenCalledTimes(1);
    });

    it.each([
        [
            'partial result',
            { status: 201, data: { status: 'committed', data: { id: 42 } } },
        ],
        ['wrong HTTP status', { status: 200, data: committed(original()) }],
        [
            'wrong ticket',
            { status: 201, data: committed(original(), { id: 43 }) },
        ],
        [
            'wrong UUID',
            {
                status: 201,
                data: committed(original(), { request_uuid: secondUuid }),
            },
        ],
        [
            'non-advanced version',
            { status: 201, data: committed(original(), { lock_version: 4 }) },
        ],
        [
            'non-boolean changed',
            { status: 201, data: committed(original(), { changed: 1 }) },
        ],
    ])(
        'keeps a %s acknowledgement uncertain without claiming success',
        async (_label, response) => {
            transport.post.mockResolvedValueOnce(response);
            const hook = setup();
            act(() => hook.result.current.submit(original().fields, 4));
            await waitFor(() =>
                expect(hook.result.current.stage).toBe('unknown'),
            );
            expect(hook.result.current.concealed).toBe(true);
            expect(hook.result.current.result).toBeNull();
            expect(hook.onCommitted).not.toHaveBeenCalled();
            expect(sessionStorage.getItem(key())).toContain(uuid);
            expect(hook.result.current.reset()).toBe(false);
        },
    );

    it('does not acknowledge an opposite decision under the same identity', async () => {
        transport.post.mockResolvedValueOnce({
            status: 200,
            data: committed(original('decide'), {
                approval_status: 'rejected',
            }),
        });
        const hook = setup('decide');
        act(() => hook.result.current.submit(original('decide').fields, 4));
        await waitFor(() => expect(hook.result.current.stage).toBe('unknown'));
        expect(hook.onCommitted).not.toHaveBeenCalled();
    });

    it('isolates a host acknowledgement exception from the confirmed command result', async () => {
        transport.post.mockResolvedValueOnce({
            status: 201,
            data: committed(original()),
        });
        const hook = setup();
        hook.onCommitted.mockImplementation(() => {
            throw new Error('host render failure');
        });
        act(() => hook.result.current.submit(original().fields, 4));
        await waitFor(() =>
            expect(hook.result.current.stage).toBe('committed'),
        );
        expect(hook.result.current.outcomeUnknown).toBe(false);
        expect(hook.result.current.message).toMatch(/command is saved/);
        expect(sessionStorage.getItem(key())).toBeNull();
    });

    it('releases a definitive first validation rejection but only renders allowlisted field errors', async () => {
        transport.post.mockRejectedValueOnce(
            error(422, {
                errors: {
                    reason: ['Explain rejection.'],
                    form: ['Review the proposal.'],
                    hidden_subject: ['Never render this'],
                    'reason.secret': ['Never render nested'],
                },
            }),
        );
        const hook = setup();
        act(() => hook.result.current.submit(original().fields, 4));
        await waitFor(() => expect(hook.result.current.stage).toBe('rejected'));
        expect(hook.result.current.errors).toEqual({
            reason: 'Explain rejection.',
            form: 'Review the proposal.',
        });
        expect(hook.result.current.canEdit).toBe(true);
        expect(hook.result.current.references).toEqual([]);
        expect(hook.result.current.pendingIntent).toBeNull();
        expect(hook.result.current.settledOperationToken).toBe(1);
    });

    it.each([422, 409])(
        'does not treat %s after a lost response as proof the original did not commit',
        async (status) => {
            transport.post
                .mockRejectedValueOnce(new Error('lost acknowledgement'))
                .mockRejectedValueOnce(
                    error(status, {
                        code: 'stale_ticket',
                        errors: { reason: ['Later rejection'] },
                    }),
                );
            const hook = setup();
            act(() => hook.result.current.submit(original().fields, 4));
            await waitFor(() =>
                expect(hook.result.current.stage).toBe('unknown'),
            );
            await act(async () => {
                await hook.result.current.retry();
            });
            expect(hook.result.current.stage).toBe('unknown');
            expect(hook.result.current.pendingIntent).toEqual(original());
            expect(hook.result.current.errors).toEqual({});
            expect(sessionStorage.getItem(key())).toContain(uuid);
        },
    );

    it('requires explicit fresh current-version review after a definite stale command', async () => {
        transport.post.mockRejectedValueOnce(
            error(409, { code: 'stale_ticket' }),
        );
        const hook = setup();
        act(() => hook.result.current.submit(original().fields, 4));
        await waitFor(() => expect(hook.result.current.stage).toBe('conflict'));
        expect(hook.result.current.reset(5)).toBe(false);
        act(() =>
            expect(hook.result.current.confirmCurrentAccess(proof())).toBe(
                true,
            ),
        );
        expect(hook.result.current.reset(6)).toBe(false);
        act(() => expect(hook.result.current.reset(5)).toBe(true));
        expect(hook.result.current.canEdit).toBe(true);
    });

    it('does not release an idempotency conflict into a new intent', async () => {
        transport.post.mockRejectedValueOnce(
            error(409, { code: 'idempotency_conflict' }),
        );
        const hook = setup();
        act(() => hook.result.current.submit(original().fields, 4));
        await waitFor(() => expect(hook.result.current.stage).toBe('unknown'));
        expect(hook.result.current.references).toEqual([uuid]);
        expect(
            hook.result.current.submit({ reason: 'Different intent' }, 6),
        ).toBe(false);
        expect(hook.result.current.pendingIntent).toBeNull();
        expect(hook.result.current.canRestoreIntent(original())).toBe(false);
        await act(async () => {
            expect(await hook.result.current.retry()).toBe(false);
        });
        transport.get.mockResolvedValueOnce({
            status: 200,
            data: committed(original(), { replayed: true }),
        });
        await act(async () => {
            expect(await hook.result.current.recover()).toBe(true);
        });
        expect(hook.onCommitted).toHaveBeenCalledExactlyOnceWith(
            expect.objectContaining({ replayed: true }),
            null,
        );
    });

    it('stops browser waiting without cancelling the write and ignores its late callback', async () => {
        const pending = deferred<unknown>();
        transport.post.mockReturnValueOnce(pending.promise);
        const hook = setup();
        act(() => hook.result.current.submit(original().fields, 4));
        const signal = transport.post.mock.calls[0][2].signal as AbortSignal;
        act(() => hook.result.current.stopWaiting());
        expect(signal.aborted).toBe(true);
        expect(hook.result.current.stage).toBe('unknown');
        expect(hook.result.current.cancelled).toBeNull();
        await act(async () =>
            pending.resolve({ status: 201, data: committed(original()) }),
        );
        expect(hook.onCommitted).not.toHaveBeenCalled();
        expect(hook.result.current.pendingIntent).toEqual(original());
    });

    it.each(['actor', 'ticket', 'unmount'])(
        'invalidates a pending response after %s changes without exposing prior private intent',
        async (change) => {
            const pending = deferred<unknown>();
            transport.post.mockReturnValueOnce(pending.promise);
            const hook = setup();
            act(() => hook.result.current.submit(original().fields, 4));
            const signal = transport.post.mock.calls[0][2]
                .signal as AbortSignal;
            if (change === 'unmount') hook.unmount();
            else
                hook.rerender({
                    actorId: change === 'actor' ? 8 : 7,
                    ticketId: change === 'ticket' ? 43 : 42,
                });
            expect(signal.aborted).toBe(true);
            if (change !== 'unmount')
                expect(hook.result.current.pendingIntent).toBeNull();
            await act(async () =>
                pending.resolve({ status: 201, data: committed(original()) }),
            );
            expect(hook.onCommitted).not.toHaveBeenCalled();
            expect(sessionStorage.getItem(key())).toContain(uuid);
        },
    );

    it.each([401, 419])(
        'keeps %s private work concealed through receipt recovery until fresh actor-bound review',
        async (status) => {
            transport.post.mockRejectedValueOnce(error(status));
            transport.get.mockResolvedValueOnce({
                status: 200,
                data: committed(original(), { replayed: true }),
            });
            const hook = setup();
            act(() => hook.result.current.submit(original().fields, 4));
            await waitFor(() =>
                expect(hook.result.current.stage).toBe('session'),
            );
            expect(hook.result.current.pendingIntent).toBeNull();
            expect(hook.onSessionExpired).toHaveBeenCalledOnce();
            await act(async () => {
                expect(await hook.result.current.recover()).toBe(true);
            });
            expect(hook.result.current.stage).toBe('committed');
            expect(hook.result.current.concealed).toBe(true);
            expect(hook.result.current.reset()).toBe(false);
            expect(
                hook.result.current.confirmCurrentAccess({
                    ...proof(),
                    actorId: 8,
                }),
            ).toBe(false);
            expect(
                hook.result.current.confirmCurrentAccess(proof('request', 4)),
            ).toBe(false);
            act(() =>
                expect(hook.result.current.confirmCurrentAccess(proof())).toBe(
                    true,
                ),
            );
            act(() => expect(hook.result.current.reset()).toBe(true));
        },
    );

    it.each([403, 404])(
        'purges the private frozen command on direct mutation %s while keeping its opaque receipt identity',
        async (status) => {
            transport.post.mockRejectedValueOnce(error(status));
            const hook = setup();
            act(() => hook.result.current.submit(original().fields, 4));
            await waitFor(() =>
                expect(hook.result.current.stage).toBe('access'),
            );
            expect(hook.result.current.pendingIntent).toBeNull();
            expect(hook.onAccessLost).toHaveBeenCalledOnce();
            expect(hook.result.current.references).toEqual([uuid]);
            expect(hook.result.current.canEdit).toBe(false);
        },
    );

    it('externally denies access during a pending write and ignores its late private acknowledgement', async () => {
        const pending = deferred<unknown>();
        transport.post.mockReturnValueOnce(pending.promise);
        const hook = setup();
        act(() => hook.result.current.submit(original().fields, 4));
        act(() => hook.result.current.denyCurrentAccess());
        expect(hook.result.current.stage).toBe('access');
        expect(hook.result.current.pendingIntent).toBeNull();
        await act(async () =>
            pending.resolve({ status: 201, data: committed(original()) }),
        );
        expect(hook.onCommitted).not.toHaveBeenCalled();
        expect(hook.onAccessLost).not.toHaveBeenCalled();
        expect(sessionStorage.getItem(key())).toContain(uuid);
    });

    it('fails closed when the response identifies a different signed-in actor', async () => {
        transport.post.mockResolvedValueOnce({
            status: 201,
            data: committed(original(), { viewer_user_id: 8 }),
        });
        const hook = setup();
        act(() => hook.result.current.submit(original().fields, 4));
        await waitFor(() => expect(hook.result.current.stage).toBe('access'));
        expect(hook.onAccessLost).toHaveBeenCalledOnce();
        expect(hook.onCommitted).not.toHaveBeenCalled();
    });

    it('recovers an opaque reload reference without private fields and retains it on generic 404', async () => {
        sessionStorage.setItem(key(), JSON.stringify([uuid]));
        transport.get.mockRejectedValueOnce(error(404));
        const hook = setup();
        expect(hook.result.current.canEdit).toBe(false);
        await act(async () => {
            expect(await hook.result.current.recover(uuid)).toBe(false);
        });
        expect(transport.get).toHaveBeenCalledWith(
            `/it/tickets/42/approval-commands/request/${uuid}`,
            expect.objectContaining({ params: { actor_user_id: 7 } }),
        );
        expect(hook.result.current.stage).toBe('unknown');
        expect(hook.result.current.references).toEqual([uuid]);
        expect(hook.result.current.cancelled).toBeNull();
        expect(hook.result.current.reset()).toBe(false);
    });

    it('cancels an opaque original via the server tombstone then requires current review for a new intent', async () => {
        sessionStorage.setItem(key(7, 'decide', 10), JSON.stringify([uuid]));
        transport.post.mockResolvedValueOnce({
            status: 200,
            data: cancelled(original('decide')),
        });
        const hook = setup('decide');
        await act(async () => {
            expect(await hook.result.current.cancelCommand(uuid)).toBe(true);
        });
        expect(transport.post).toHaveBeenCalledWith(
            `/it/tickets/42/approval-commands/decide/${uuid}/cancel`,
            { actor_user_id: 7, approval_id: 10 },
            expect.any(Object),
        );
        expect(hook.result.current.stage).toBe('cancelled');
        expect(hook.onCommitted).not.toHaveBeenCalled();
        expect(hook.result.current.reset(5)).toBe(false);
        act(() => hook.result.current.confirmCurrentAccess(proof('decide')));
        act(() => expect(hook.result.current.reset(5)).toBe(true));
    });

    it('accepts a cancellation tombstone returned to a delayed original submission', async () => {
        transport.post.mockResolvedValueOnce({
            status: 200,
            data: cancelled(original(), true),
        });
        const hook = setup();
        act(() => hook.result.current.submit(original().fields, 4));
        await waitFor(() =>
            expect(hook.result.current.stage).toBe('cancelled'),
        );
        expect(hook.onCommitted).not.toHaveBeenCalled();
        expect(hook.result.current.outcomeUnknown).toBe(false);
    });

    it('acknowledges the committed winner when explicit cancellation loses the race', async () => {
        transport.post
            .mockRejectedValueOnce(new Error('lost response'))
            .mockResolvedValueOnce({
                status: 200,
                data: committed(original(), { replayed: true }),
            });
        const hook = setup();
        act(() => hook.result.current.submit(original().fields, 4));
        await waitFor(() => expect(hook.result.current.stage).toBe('unknown'));
        await act(async () => {
            expect(await hook.result.current.cancelCommand()).toBe(true);
        });
        expect(hook.result.current.stage).toBe('committed');
        expect(hook.result.current.cancelled).toBeNull();
        expect(hook.onCommitted).toHaveBeenCalledExactlyOnceWith(
            expect.objectContaining({ replayed: true }),
            original(),
        );
    });

    it('does not infer success from an un-replayed receipt GET', async () => {
        sessionStorage.setItem(key(), JSON.stringify([uuid]));
        transport.get.mockResolvedValueOnce({
            status: 200,
            data: committed(original()),
        });
        const hook = setup();
        await act(async () => {
            await hook.result.current.recover(uuid);
        });
        expect(hook.result.current.stage).toBe('unknown');
        expect(hook.onCommitted).not.toHaveBeenCalled();
    });

    it('fails before network activity when its opaque journal cannot be written', () => {
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('storage disabled');
        });
        const hook = setup();
        act(() =>
            expect(hook.result.current.submit(original().fields, 4)).toBe(
                false,
            ),
        );
        expect(transport.post).not.toHaveBeenCalled();
        expect(hook.result.current.message).toMatch(/Nothing was sent/);
    });

    it('preserves an unreadable existing journal and refuses a new write', () => {
        sessionStorage.setItem(key(), 'not-json');
        const hook = setup();
        act(() =>
            expect(hook.result.current.submit(original().fields, 4)).toBe(
                false,
            ),
        );
        expect(transport.post).not.toHaveBeenCalled();
        expect(sessionStorage.getItem(key())).toBe('not-json');
    });

    it('keeps failed journal removal recoverable without repeating the committed host callback', async () => {
        transport.post.mockResolvedValueOnce({
            status: 201,
            data: committed(original()),
        });
        transport.get.mockResolvedValueOnce({
            status: 200,
            data: committed(original(), { replayed: true }),
        });
        const remove = vi
            .spyOn(Storage.prototype, 'removeItem')
            .mockImplementationOnce(() => {
                throw new Error('temporary storage failure');
            });
        const hook = setup();
        act(() => hook.result.current.submit(original().fields, 4));
        await waitFor(() =>
            expect(hook.result.current.stage).toBe('committed'),
        );
        expect(hook.result.current.references).toEqual([uuid]);
        expect(hook.result.current.reset()).toBe(false);
        remove.mockRestore();
        await act(async () => {
            expect(await hook.result.current.recover(uuid)).toBe(true);
        });
        expect(hook.onCommitted).toHaveBeenCalledOnce();
        expect(sessionStorage.getItem(key())).toBeNull();
        act(() => expect(hook.result.current.reset()).toBe(true));
    });

    it.each(['committed', 'cancelled'])(
        'does not resurrect a locally known %s UUID from stale RAM after explicit reset',
        async (outcome) => {
            transport.post.mockResolvedValueOnce(
                outcome === 'committed'
                    ? { status: 201, data: committed(original()) }
                    : { status: 200, data: cancelled(original(), true) },
            );
            const hook = setup();
            act(() => hook.result.current.submit(original().fields, 4));
            await waitFor(() =>
                expect(hook.result.current.stage).toBe(outcome),
            );
            act(() => hook.result.current.confirmCurrentAccess(proof()));
            act(() => expect(hook.result.current.reset(5)).toBe(true));
            expect(hook.result.current.canRestoreIntent(original())).toBe(
                false,
            );
            expect(
                hook.result.current.restoreAuthorizedIntent(original()),
            ).toBe(false);
            expect(hook.result.current.stage).toBe('editing');
        },
    );

    it('keeps terminal and reported identities scoped to their actor and ticket after a mounted account change', async () => {
        transport.post.mockResolvedValueOnce({
            status: 201,
            data: committed(original()),
        });
        const hook = setup();
        act(() => hook.result.current.submit(original().fields, 4));
        await waitFor(() =>
            expect(hook.result.current.stage).toBe('committed'),
        );
        const next = { ...original(), actorId: 8, ticketId: 43 };
        hook.rerender({ actorId: 8, ticketId: 43 });
        await waitFor(() => expect(hook.result.current.stage).toBe('editing'));
        expect(hook.result.current.canRestoreIntent(next)).toBe(true);
        transport.post.mockResolvedValueOnce({
            status: 201,
            data: committed(next),
        });
        act(() =>
            expect(hook.result.current.submit(next.fields, 4)).toBe(true),
        );
        await waitFor(() =>
            expect(hook.result.current.stage).toBe('committed'),
        );
        expect(hook.onCommitted).toHaveBeenCalledTimes(2);
        expect(hook.onCommitted.mock.calls[1][0]).toMatchObject({
            id: 43,
            viewer_user_id: 8,
        });
    });

    it('discovers only valid actor/ticket/operation/approval opaque identities', () => {
        sessionStorage.setItem(key(), JSON.stringify([uuid]));
        sessionStorage.setItem(
            key(7, 'decide', 10),
            JSON.stringify([secondUuid]),
        );
        sessionStorage.setItem(key(8), JSON.stringify([uuid]));
        sessionStorage.setItem(key(7, 'decide'), JSON.stringify([uuid]));
        expect(pendingItApprovalCommands(7, 42)).toEqual([
            {
                actorId: 7,
                ticketId: 42,
                operation: 'request',
                approvalId: null,
                requestUuid: uuid,
            },
            {
                actorId: 7,
                ticketId: 42,
                operation: 'decide',
                approvalId: 10,
                requestUuid: secondUuid,
            },
        ]);
    });

    it('adopts only an exact freshly authorized RAM intent and does not change its original version during review', async () => {
        const hook = setup();
        expect(
            hook.result.current.canRestoreIntent({ ...original(), actorId: 8 }),
        ).toBe(false);
        act(() =>
            expect(
                hook.result.current.restoreAuthorizedIntent(original()),
            ).toBe(true),
        );
        act(() =>
            expect(
                hook.result.current.confirmCurrentAccess(proof('request', 8)),
            ).toBe(true),
        );
        expect(hook.result.current.pendingIntent?.expectedVersion).toBe(4);
        expect(
            hook.result.current.canRestoreIntent({
                ...original(),
                expectedVersion: 8,
            }),
        ).toBe(false);
        expect(hook.result.current.reset(8)).toBe(false);
        transport.post.mockResolvedValueOnce({
            status: 200,
            data: committed(original(), { replayed: true }),
        });
        await act(async () => {
            expect(await hook.result.current.retry()).toBe(true);
        });
        expect(transport.post.mock.calls[0][1].expected_version).toBe(4);
    });
});
