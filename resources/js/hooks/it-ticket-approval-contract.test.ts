import { describe, expect, it } from 'vitest';
import {
    freezeItApprovalIntent,
    itApprovalMutation,
    itApprovalReceiptParameters,
    itApprovalReceiptPath,
    readItApprovalCancelled,
    readItApprovalCommitted,
    type ItApprovalIdentity,
} from './it-ticket-approval-contract';

const identity: ItApprovalIdentity & { expectedVersion: number } = {
    actorId: 3,
    ticketId: 7,
    operation: 'request',
    approvalId: null,
    requestUuid: '2d78911c-e32c-4dc3-b7a5-68d5f3a0a211',
    expectedVersion: 4,
};
const ack = () => ({
    status: 'committed',
    data: {
        id: 7,
        viewer_user_id: 3,
        request_uuid: identity.requestUuid,
        operation: 'approval.request',
        approval_id: 11,
        approval_status: 'pending',
        lock_version: 5,
        changed: true,
        replayed: false,
    },
});

describe('approval command evidence', () => {
    it('freezes responsibility and zoned timing without changing a later replay', () => {
        const fields = {
            reason: null,
            primary_approver_user_id: 6,
            cover_approver_user_id: 8,
            expires_at: '2026-09-10T14:00:00+12:00',
            remind_at: '2026-09-10T13:00:00+12:00',
        };
        const intent = freezeItApprovalIntent(identity, fields)!;
        fields.primary_approver_user_id = 9;
        expect(intent.fields).toEqual({
            reason: null,
            primary_approver_user_id: 6,
            cover_approver_user_id: 8,
            expires_at: '2026-09-10T02:00:00.000Z',
            remind_at: '2026-09-10T01:00:00.000Z',
        });
        expect(itApprovalMutation(intent).data.primary_approver_user_id).toBe(
            6,
        );
        for (const invalid of [
            { primary_approver_user_id: 3 },
            { cover_approver_user_id: 6 },
            { expires_at: '2026-09-10 14:00' },
            { remind_at: '2026-09-10T14:00:00+12:00' },
        ]) {
            expect(
                freezeItApprovalIntent(identity, {
                    ...intent.fields,
                    ...invalid,
                }),
            ).toBeNull();
        }
    });

    it('distinguishes cancellation of the approval from cancellation of its command', () => {
        const target = {
            ...identity,
            operation: 'withdraw' as const,
            approvalId: 11,
        };
        expect(freezeItApprovalIntent(target, { reason: '  ' })).toBeNull();
        const intent = freezeItApprovalIntent(target, {
            reason: 'Revised scope needs a new request',
        })!;
        expect(itApprovalMutation(intent).url).toBe(
            '/it/tickets/7/approvals/11/withdraw',
        );
        expect(itApprovalReceiptParameters(intent)).toEqual({
            actor_user_id: 3,
            approval_id: 11,
        });
        const response = ack();
        response.data.operation = 'approval.withdraw';
        response.data.approval_status = 'cancelled';
        expect(readItApprovalCommitted(response, intent)?.approval_status).toBe(
            'cancelled',
        );
        expect(readItApprovalCancelled(response, intent)).toBeNull();
        expect(
            readItApprovalCommitted(
                { ...response, data: { ...response.data, approval_id: 12 } },
                intent,
            ),
        ).toBeNull();
        expect(
            readItApprovalCommitted(
                {
                    ...response,
                    data: { ...response.data, approval_status: 'approved' },
                },
                intent,
            ),
        ).toBeNull();
    });
    it('freezes a normalized allowlisted proposal without sharing mutable caller fields', () => {
        const fields = {
            reason: '  Please review the access request.  ',
            status: 'approved',
        };
        const intent = freezeItApprovalIntent(identity, fields)!;
        fields.reason = 'A later draft';
        expect(intent.fields).toEqual({
            reason: 'Please review the access request.',
        });
        expect(Object.isFrozen(intent)).toBe(true);
        expect(Object.isFrozen(intent.fields)).toBe(true);
        expect(itApprovalMutation(intent)).toEqual({
            url: '/it/tickets/7/approvals',
            data: {
                actor_user_id: 3,
                request_uuid: identity.requestUuid,
                expected_version: 4,
                reason: 'Please review the access request.',
            },
        });
    });

    it('requires an exact target and reason for rejection while preserving deliberate empty approval notes', () => {
        const decision = {
            ...identity,
            operation: 'decide' as const,
            approvalId: 11,
        };
        expect(
            freezeItApprovalIntent(decision, {
                decision: 'reject',
                reason: '  ',
            }),
        ).toBeNull();
        expect(
            freezeItApprovalIntent(
                { ...decision, approvalId: null },
                { decision: 'approve', reason: null },
            ),
        ).toBeNull();
        const intent = freezeItApprovalIntent(decision, {
            decision: 'approve',
            reason: null,
        })!;
        expect(itApprovalMutation(intent).url).toBe(
            '/it/tickets/7/approvals/11/decide',
        );
        expect(itApprovalReceiptParameters(intent)).toEqual({
            actor_user_id: 3,
            approval_id: 11,
        });
        expect(itApprovalReceiptParameters(identity)).toEqual({
            actor_user_id: 3,
        });
        expect(itApprovalReceiptPath(identity)).toBe(
            `/it/tickets/7/approval-commands/request/${identity.requestUuid}`,
        );
    });

    it('rejects invalid identities and oversized Unicode reasons before issuing a mutation', () => {
        for (const change of [
            { actorId: 0 },
            { ticketId: NaN },
            { expectedVersion: 0 },
            { requestUuid: '../other' },
            { approvalId: 11 },
        ]) {
            expect(
                freezeItApprovalIntent(
                    { ...identity, ...change },
                    { reason: null },
                ),
            ).toBeNull();
        }
        expect(
            freezeItApprovalIntent(identity, { reason: '😀'.repeat(1000) }),
        ).not.toBeNull();
        expect(
            freezeItApprovalIntent(identity, { reason: '😀'.repeat(1001) }),
        ).toBeNull();
        expect(
            freezeItApprovalIntent(identity, {
                reason: null,
                decision: 'approve',
            }),
        ).toBeNull();
    });

    it.each([
        { id: 8 },
        { viewer_user_id: 4 },
        { operation: 'approval.decide' },
        { request_uuid: '6d78911c-e32c-4dc3-b7a5-68d5f3a0a211' },
        { approval_id: null },
        { approval_status: 'approved' },
        { lock_version: 4 },
        { lock_version: '5' },
        { changed: false },
        { replayed: undefined },
    ])('does not acknowledge unrelated or malformed result %j', (change) => {
        const value = ack();
        Object.assign(value.data, change);
        expect(readItApprovalCommitted(value, identity)).toBeNull();
    });

    it('allows recovery of the original request after later decisions without claiming current approval state', () => {
        const response = ack();
        response.data.replayed = true;
        expect(
            readItApprovalCommitted(response, identity)?.approval_status,
        ).toBe('pending');
        const { expectedVersion: _originalVersion, ...reference } = identity;
        expect(readItApprovalCommitted(response, reference)?.lock_version).toBe(
            5,
        );
        expect(
            readItApprovalCommitted(response, {
                ...identity,
                expectedVersion: 8,
            }),
        ).toBeNull();
    });

    it('matches the exact decision generation and submitted decision before success', () => {
        const decision = {
            ...identity,
            operation: 'decide' as const,
            approvalId: 11,
        };
        const intent = freezeItApprovalIntent(decision, {
            decision: 'reject',
            reason: 'Permission not justified.',
        })!;
        const response = ack();
        response.data.operation = 'approval.decide';
        response.data.approval_status = 'approved';
        expect(readItApprovalCommitted(response, intent)).toBeNull();
        response.data.approval_status = 'rejected';
        expect(readItApprovalCommitted(response, intent)?.approval_id).toBe(11);
        expect(
            readItApprovalCommitted(response, { ...intent, approvalId: 12 }),
        ).toBeNull();
        expect(
            readItApprovalCommitted(response, decision)?.approval_status,
        ).toBe('rejected');
    });

    it('accepts only an exact server cancellation receipt and keeps business approval state separate', () => {
        const response = {
            status: 'cancelled',
            data: {
                id: 7,
                viewer_user_id: 3,
                request_uuid: identity.requestUuid,
                operation: 'approval.request',
                approval_id: null,
                cancelled_at: '2026-09-09T12:00:00.000000Z',
                replayed: false,
            },
        };
        expect(
            readItApprovalCancelled(response, identity)?.approval_id,
        ).toBeNull();
        for (const change of [
            { viewer_user_id: 4 },
            { id: 8 },
            { approval_id: 11 },
            { cancelled_at: 'today' },
            { replayed: undefined },
        ]) {
            expect(
                readItApprovalCancelled(
                    { ...response, data: { ...response.data, ...change } },
                    identity,
                ),
            ).toBeNull();
        }
        const decision = {
            ...identity,
            operation: 'decide' as const,
            approvalId: 11,
        };
        const cancelledDecision = {
            ...response,
            data: {
                ...response.data,
                operation: 'approval.decide',
                approval_id: 11,
            },
        };
        expect(
            readItApprovalCancelled(cancelledDecision, decision)?.approval_id,
        ).toBe(11);
        expect(
            readItApprovalCancelled(cancelledDecision, {
                ...decision,
                approvalId: 12,
            }),
        ).toBeNull();
        for (const unknown of [
            null,
            { status: 404 },
            { status: 'cancelled' },
            '<html>Sign in</html>',
            ack(),
        ]) {
            expect(readItApprovalCancelled(unknown, identity)).toBeNull();
        }
    });
});
