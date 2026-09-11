import { expect, it } from 'vitest';
import {
    readItMergePreview,
    type ItMergePreviewIdentity,
} from './it-ticket-merge-preview';

const identity: ItMergePreviewIdentity = {
    actorId: 7,
    sourceId: 41,
    targetId: 42,
    sourceVersion: 2,
    targetVersion: 4,
    nonce: '560fda43-afaf-4a7b-afad-f928bec51111',
};
const record = (id: number, version: number) => ({
    id,
    lock_version: version,
    reference: `IT-${String(id).padStart(6, '0')}`,
    title: 'Synthetic duplicate',
    status: 'open',
    workflow_state: 'submitted',
    work_type: 'incident',
    inventory: {
        public_comments: 1,
        internal_notes: 2,
        ticket_files: 1,
        comment_files: 1,
        watchers: 1,
        links: 0,
        tasks: 2,
        unfinished_required_tasks: 1,
        approval_requests: 3,
        pending_approval_requests: 1,
        expired_approval_requests: 1,
    },
});
const response = () => ({
    status: 'reviewed',
    data: {
        review_token: 'synthetic-encrypted-review',
        viewer_user_id: 7,
        review_nonce: identity.nonce,
        source: record(41, 2),
        target: record(42, 4),
        lifecycle_blockers: [],
        access_scope_differences: [{ field: 'site_id', source: 1, target: 2 }],
    },
});

it('reads an actor, nonce and two-version bound inventory without treating it as write permission', () => {
    const payload = response();
    const preview = readItMergePreview(payload, identity);
    expect(preview?.source.inventory.comment_files).toBe(1);
    expect(preview?.access_scope_differences).toEqual([
        { field: 'site_id', source: 1, target: 2 },
    ]);
    expect(preview).not.toHaveProperty('can_merge');
    payload.data.source.inventory.comment_files = 99;
    expect(preview?.source.inventory.comment_files).toBe(1);
});

it.each([
    'actor',
    'nonce',
    'source',
    'target',
    'source version',
    'target version',
    'negative count',
    'fractional count',
    'missing count',
    'required count',
    'approval count',
    'scope field',
    'scope value',
    'duplicate scope',
    'same scope',
    'unsafe reference',
    'wrong status',
    'missing review token',
    'empty review token',
])('rejects a mismatched or malformed merge preview: %s', (fault) => {
    const payload = response();
    switch (fault) {
        case 'missing review token':
            Reflect.deleteProperty(payload.data, 'review_token');
            break;
        case 'empty review token':
            payload.data.review_token = '';
            break;
        case 'actor':
            payload.data.viewer_user_id++;
            break;
        case 'nonce':
            payload.data.review_nonce = crypto.randomUUID();
            break;
        case 'source':
            payload.data.source.id++;
            break;
        case 'target':
            payload.data.target.id++;
            break;
        case 'source version':
            payload.data.source.lock_version++;
            break;
        case 'target version':
            payload.data.target.lock_version++;
            break;
        case 'negative count':
            payload.data.source.inventory.public_comments = -1;
            break;
        case 'fractional count':
            payload.data.target.inventory.watchers = 0.5;
            break;
        case 'missing count':
            Reflect.deleteProperty(
                payload.data.source.inventory,
                'ticket_files',
            );
            break;
        case 'required count':
            payload.data.source.inventory.unfinished_required_tasks = 3;
            break;
        case 'approval count':
            payload.data.source.inventory.pending_approval_requests = 3;
            break;
        case 'scope field':
            payload.data.access_scope_differences[0].field = 'arbitrary_url';
            break;
        case 'scope value':
            payload.data.access_scope_differences[0].source = -1;
            break;
        case 'duplicate scope':
            payload.data.access_scope_differences.push({
                ...payload.data.access_scope_differences[0],
            });
            break;
        case 'same scope':
            payload.data.access_scope_differences[0].target = 1;
            break;
        case 'unsafe reference':
            payload.data.source.reference = 'https://elsewhere.invalid';
            break;
        case 'wrong status':
            payload.status = 'committed';
            break;
    }
    expect(readItMergePreview(payload, identity)).toBeNull();
});
