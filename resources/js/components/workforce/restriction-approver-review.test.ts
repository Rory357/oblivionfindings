import { describe, expect, it } from 'vitest';
import { approverCommand, type ApproverAttempt } from './restriction-approver-contract';
import { bindApproverReview, preparedApproverStillCurrent } from './restriction-approver-review';
import type { ApproverReview } from './restriction-approver-settings';

const uuid = '7d886442-95d4-4bb6-a8b5-364489f0d22d';
const read = {
    version: 1, state: 'configured', actor_id: 4,
    read_generation: 'a'.repeat(32), scope_site_ids: [2],
    checked_at: '2026-10-12T05:00:00Z', revision: 'b'.repeat(64),
    authorized_user_ids: [7],
    selected_users: [{ id: 7, name: 'Aroha', eligible: true }],
    candidate_options: [{ id: 7, name: 'Aroha' }, { id: 9, name: 'Hemi' }],
    can_configure: true,
};
const review: ApproverReview = {
    actorId: 4, readGeneration: read.read_generation,
    expectedRevision: read.revision, selectedIds: [7, 9], reason: 'Approved cover',
};
const attempt: ApproverAttempt = {
    actorId: 4,
    command: approverCommand(uuid, read.revision, [7, 9], review.reason)!,
    intentHash: 'c'.repeat(64),
};

describe('current reviewed approver preparation', () => {
    it('binds only the current complete read and returns an independent immutable command', () => {
        const command = bindApproverReview(4, read, review, uuid);
        expect(command?.authorized_user_ids).toEqual([7, 9]);
        expect(command?.expected_revision).toBe(read.revision);
        expect(command?.authorized_user_ids).not.toBe(review.selectedIds);
        expect(Object.isFrozen(command)).toBe(true);
        expect(preparedApproverStillCurrent(4, read, review, attempt)).toBe(true);
    });

    it('refuses account changes even when the new account has a lawful fresh read', () => {
        expect(bindApproverReview(5, { ...read, actor_id: 5 }, review, uuid)).toBeNull();
        expect(preparedApproverStillCurrent(5, { ...read, actor_id: 5 }, review, attempt)).toBe(false);
    });

    it('refuses refreshed generations or changed revisions after preparation', () => {
        expect(preparedApproverStillCurrent(4, { ...read, read_generation: 'd'.repeat(32) }, review, attempt)).toBe(false);
        expect(preparedApproverStillCurrent(4, { ...read, revision: 'd'.repeat(64) }, review, attempt)).toBe(false);
    });

    it('does not silently remove someone who became nonselectable while hashing', () => {
        const changed = { ...read, candidate_options: [read.candidate_options[0]] };
        expect(bindApproverReview(4, changed, review, uuid)).toBeNull();
        expect(preparedApproverStillCurrent(4, changed, review, attempt)).toBe(false);
        expect(attempt.command.authorized_user_ids).toEqual([7, 9]);
    });

    it('refuses an unavailable or incomplete refreshed list', () => {
        expect(bindApproverReview(4, { ...read, selected_users: [] }, review, uuid)).toBeNull();
        expect(bindApproverReview(4, { ...read, can_configure: false }, review, uuid)).toBeNull();
        expect(bindApproverReview(4, null, review, uuid)).toBeNull();
    });

    it('refuses substitution of a later selection or reason into a prepared command', () => {
        expect(preparedApproverStillCurrent(4, read, { ...review, selectedIds: [7] }, attempt)).toBe(false);
        expect(preparedApproverStillCurrent(4, read, { ...review, reason: 'Later reason' }, attempt)).toBe(false);
    });
});
