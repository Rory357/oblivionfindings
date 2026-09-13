import { expect, it } from 'vitest';
import {
    freezeItMergeIntent,
    itMergePayload,
    itMergeReceiptParameters,
    itMergeReceiptPath,
    readItMergeResult,
    type ItMergeIdentity,
} from './it-ticket-merge-contract';
import type { ItMergePreview } from './it-ticket-merge-preview';

const identity: ItMergeIdentity = {
    actorId: 7,
    sourceId: 41,
    targetId: 42,
    requestUuid: '560fda43-afaf-4a7b-afad-f928bec51111',
};
function preview(): ItMergePreview {
    const record = (id: number, version: number) => ({
        id,
        reference: `IT-${String(id).padStart(6, '0')}`,
        title: 'Synthetic duplicate',
        lock_version: version,
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
        source: record(41, 2),
        target: record(42, 4),
        review_token: 'synthetic-encrypted-proof',
        lifecycle_blockers: [],
        access_scope_differences: [],
    };
}
function committed() {
    return {
        status: 'committed',
        data: {
            viewer_user_id: 7,
            source_id: 41,
            target_id: 42,
            request_uuid: identity.requestUuid,
            operation: 'ticket.merge',
            replayed: false,
            source_version: 4,
            target_version: 5,
            url: '/it/tickets/42?merged_from=41',
        },
    };
}

it('freezes the reviewed versions reason and proof while recovery carries no private content', () => {
    const review = preview();
    const intent = freezeItMergeIntent(identity, review, '  Same incident  ')!;
    review.source.lock_version = 88;
    review.review_token = 'changed';
    expect(Object.isFrozen(intent)).toBe(true);
    expect(itMergePayload(intent)).toEqual({
        actor_user_id: 7,
        target_ticket_id: 42,
        request_uuid: identity.requestUuid,
        source_version: 2,
        target_version: 4,
        reason: 'Same incident',
        review_token: 'synthetic-encrypted-proof',
    });
    expect(itMergeReceiptPath(identity)).toBe(
        `/it/tickets/41/merge-commands/${identity.requestUuid}`,
    );
    expect(itMergeReceiptParameters(identity)).toEqual({
        actor_user_id: 7,
        target_ticket_id: 42,
    });
    expect(readItMergeResult(committed(), identity, intent)).toMatchObject({
        status: 'committed',
        sourceVersion: 4,
        targetVersion: 5,
    });
});

it.each([
    'blocked',
    'source',
    'target',
    'version',
    'proof',
    'blank reason',
    'long reason',
    'self',
    'uuid',
])('refuses an invalid new merge intent: %s', (fault) => {
    const review = preview();
    const actor = { ...identity };
    let reason = 'Same incident';
    if (fault === 'blocked')
        review.lifecycle_blockers = ['Finish the required task first.'];
    if (fault === 'source') review.source.id++;
    if (fault === 'target') review.target.id++;
    if (fault === 'version') review.target.lock_version = 0;
    if (fault === 'proof') review.review_token = '';
    if (fault === 'blank reason') reason = '  ';
    if (fault === 'long reason') reason = 'a'.repeat(1001);
    if (fault === 'self') actor.targetId = actor.sourceId;
    if (fault === 'uuid') actor.requestUuid = 'invalid';
    expect(freezeItMergeIntent(actor, review, reason)).toBeNull();
});

it.each([
    'viewer_user_id',
    'source_id',
    'target_id',
    'request_uuid',
    'operation',
    'source_version',
    'target_version',
    'url',
    'replayed',
    'status',
])('rejects a malformed or mismatched merge acknowledgement: %s', (field) => {
    const payload = committed();
    if (field === 'status') payload.status = 'ok';
    else
        Reflect.set(
            payload.data,
            field,
            field === 'url' ? 'https://elsewhere.invalid' : 'invalid',
        );
    expect(readItMergeResult(payload, identity)).toBeNull();
});

it('rejects incorrect committed version advances when the original intent is retained', () => {
    const intent = freezeItMergeIntent(identity, preview(), 'Same incident')!;
    const response = committed();
    response.data.source_version++;
    expect(readItMergeResult(response, identity, intent)).toBeNull();
    expect(readItMergeResult(response, identity)?.status).toBe('committed');
});

it('recognizes only a matching dated cancellation and never returns a navigation URL for it', () => {
    const response = {
        status: 'cancelled',
        data: { ...committed().data, cancelled_at: '2026-09-10T03:00:00Z' },
    };
    expect(readItMergeResult(response, identity)).toEqual({
        status: 'cancelled',
        cancelledAt: '2026-09-10T03:00:00Z',
        replayed: false,
    });
    response.data.cancelled_at = 'yesterday';
    expect(readItMergeResult(response, identity)).toBeNull();
});
