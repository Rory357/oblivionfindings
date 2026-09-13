import { describe, expect, it } from 'vitest';
import {
    freezeItCommentIntent,
    itCommentCommitMessage,
    itCommentFormData,
    readItCommentCancelled,
    readItCommentCommitted,
    type ItCommentIntent,
} from './it-ticket-comment-contract';

const uuid = 'fa1466c3-12b0-424d-b721-9f264bc5d251';
const draftUuid = '50bab5e1-ab7e-4f30-bd4c-8454d805bf12';
const identity = {
    actorId: 7,
    ticketId: 9,
    requestUuid: uuid,
    expectedVersion: 4,
    isInternal: false,
};
const ack = () => ({
    status: 'committed',
    data: {
        id: 9,
        canonical_ticket_id: 9,
        comment_id: 13,
        viewer_user_id: 7,
        request_uuid: uuid,
        is_internal: false,
        lock_version: 7,
        replayed: false,
        delivery: { requested: true, attempt_statuses: { queued: 1 } },
    },
});

describe('canonical reply acknowledgement', () => {
    it('preserves the immutable command ticket when a governed merge relocates the comment', () => {
        const response = ack();
        response.data.canonical_ticket_id = 21;
        expect(readItCommentCommitted(response, identity)).toMatchObject({
            id: 9,
            canonical_ticket_id: 21,
            comment_id: 13,
        });
    });
    it('requires an exact explicit cancellation receipt rather than absence or an unrelated tombstone', () => {
        const response = {
            status: 'cancelled',
            data: {
                id: 9,
                viewer_user_id: 7,
                request_uuid: uuid,
                is_internal: false,
                cancelled_at: '2026-09-09T19:30:00.000000Z',
                replayed: false,
            },
        };
        expect(readItCommentCancelled(response, identity)?.request_uuid).toBe(
            uuid,
        );
        for (const change of [
            { id: 21 },
            { viewer_user_id: 8 },
            { is_internal: true },
            { request_uuid: draftUuid },
            { cancelled_at: 'yesterday' },
            { replayed: undefined },
        ]) {
            expect(
                readItCommentCancelled(
                    { ...response, data: { ...response.data, ...change } },
                    identity,
                ),
            ).toBeNull();
        }
        expect(readItCommentCancelled({ status: 404 }, identity)).toBeNull();
    });
    it('accepts multiple aggregate version advances and the exact committed replay version', () => {
        expect(readItCommentCommitted(ack(), identity)?.lock_version).toBe(7);
        const replay = ack();
        replay.data.replayed = true;
        expect(readItCommentCommitted(replay, identity)?.replayed).toBe(true);
    });
    it.each([
        ['wrong ticket', { id: 10 }],
        ['missing canonical ticket', { canonical_ticket_id: undefined }],
        ['invalid canonical ticket', { canonical_ticket_id: -1 }],
        ['wrong actor', { viewer_user_id: 8 }],
        ['wrong command', { request_uuid: draftUuid }],
        ['wrong audience', { is_internal: true }],
        ['missing comment', { comment_id: 0 }],
        ['unchanged version', { lock_version: 4 }],
        ['string version', { lock_version: '7' }],
        ['missing replay identity', { replayed: undefined }],
    ])('rejects %s without acknowledging the reply', (_label, change) => {
        const response = ack();
        Object.assign(response.data, change);
        expect(readItCommentCommitted(response, identity)).toBeNull();
    });
    it.each([
        null,
        '<html>login</html>',
        { flash: { success: 'Reply sent.' } },
        { status: 'success' },
        { status: 'committed' },
    ])(
        'never converts a redirect, flash or incomplete envelope into a commit: %j',
        (value) => {
            expect(readItCommentCommitted(value, identity)).toBeNull();
        },
    );
    it('binds exact draft identity and consumed revision, including revision zero', () => {
        const submitted = {
            ...identity,
            draft: {
                draft_uuid: draftUuid,
                draft_revision: 0,
                draft_actor_user_id: 7,
            },
        };
        const response = {
            ...ack(),
            data: {
                ...ack().data,
                draft: {
                    draft_uuid: draftUuid,
                    submitted_revision: 0,
                    revision: 1,
                    state: 'consumed',
                },
            },
        };
        expect(
            readItCommentCommitted(response, submitted)?.draft?.revision,
        ).toBe(1);
        expect(readItCommentCommitted(ack(), submitted)).toBeNull();
        expect(readItCommentCommitted(response, identity)).toBeNull();
        response.data.draft.revision = 2;
        expect(readItCommentCommitted(response, submitted)).toBeNull();
    });
    it('permits receipt-only recovery without pretending the latest display version was submitted', () => {
        const { expectedVersion: _version, ...recoveredIdentity } = identity;
        expect(
            readItCommentCommitted(ack(), recoveredIdentity)?.comment_id,
        ).toBe(13);
    });
    it('separates internal-note commit from nonexistent public delivery', () => {
        const internal = { ...identity, isInternal: true };
        const response = {
            ...ack(),
            data: {
                ...ack().data,
                is_internal: true,
                delivery: { requested: false, attempt_statuses: {} },
            },
        };
        const result = readItCommentCommitted(response, internal);
        expect(result).not.toBeNull();
        expect(itCommentCommitMessage(result!)).toBe('Internal note added.');
        expect(
            readItCommentCommitted(
                { ...ack(), data: { ...ack().data, is_internal: true } },
                internal,
            ),
        ).toBeNull();
    });
    it.each([
        { requested: true, attempt_statuses: {} },
        { requested: false, attempt_statuses: { accepted: 1 } },
        { requested: true, attempt_statuses: { fabricated: 1 } },
        { requested: true, attempt_statuses: { queued: -1 } },
        { requested: true, attempt_statuses: { delivered: 0 } },
    ])('rejects inconsistent or unsupported delivery state %j', (delivery) => {
        expect(
            readItCommentCommitted(
                { ...ack(), data: { ...ack().data, delivery } },
                identity,
            ),
        ).toBeNull();
    });
    it('never labels provider acceptance or partial failed delivery as sent', () => {
        const result = readItCommentCommitted(ack(), identity)!;
        result.delivery.attempt_statuses = { accepted: 1 };
        expect(itCommentCommitMessage(result)).toContain(
            'delivery is not yet confirmed',
        );
        result.delivery.attempt_statuses = { delivered: 1, bounced: 1 };
        expect(itCommentCommitMessage(result)).toContain('notification failed');
        result.delivery.attempt_statuses = { delivered: 2 };
        expect(itCommentCommitMessage(result)).toContain(
            'delivery is confirmed',
        );
    });
});

describe('immutable reply submission', () => {
    it('retries original text, audience, version, draft and exact selected bytes despite later edits', async () => {
        const file = new File(['original evidence'], 'evidence.txt', {
            type: 'text/plain',
        });
        const input: ItCommentIntent = {
            ...identity,
            body: 'Original public reply',
            files: [file],
            draft: {
                draft_uuid: draftUuid,
                draft_revision: 3,
                draft_actor_user_id: 7,
            },
        };
        const frozen = freezeItCommentIntent(input);
        input.body = 'New private note';
        input.isInternal = true;
        input.expectedVersion = 20;
        input.files = [];
        input.draft = { ...input.draft!, draft_revision: 4 };
        const retry = itCommentFormData(frozen);
        expect(retry.get('body')).toBe('Original public reply');
        expect(retry.get('is_internal')).toBe('0');
        expect(retry.get('expected_version')).toBe('4');
        expect(retry.get('draft_revision')).toBe('3');
        expect(frozen.files[0]).toBe(file);
        expect(await (retry.get('attachments[]') as File).text()).toBe(
            'original evidence',
        );
    });
    it('rejects over-limit files without silently dropping the sixth selection', () => {
        const files = Array.from(
            { length: 6 },
            (_, n) => new File(['safe'], `${n}.txt`),
        );
        expect(() =>
            freezeItCommentIntent({ ...identity, body: 'Reply', files }),
        ).toThrow();
    });
    it('rejects a draft attributed to a different original actor', () => {
        expect(() =>
            freezeItCommentIntent({
                ...identity,
                body: 'Reply',
                files: [],
                draft: {
                    draft_uuid: draftUuid,
                    draft_revision: 2,
                    draft_actor_user_id: 8,
                },
            }),
        ).toThrow();
    });
});
