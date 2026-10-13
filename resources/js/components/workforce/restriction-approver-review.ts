import {
    approverCommand,
    approverIntentJson,
    parseApproverRead,
    type ApproverAttempt,
    type ApproverCommand,
} from './restriction-approver-contract';
import type { ApproverReview } from './restriction-approver-settings';

/** Bind the reviewed choices to the actual current account and complete read.
 * Call before hashing and again immediately before the owning transport sends.
 * The owner must also permanently invalidate its preparation lease whenever
 * committed account/read access changes; a restored old frame is insufficient.
 * This local guard does not replace the server's current authority checks. */
export function bindApproverReview(
    currentActorId: number,
    currentRead: unknown,
    review: ApproverReview,
    commandId: string,
): ApproverCommand | null {
    const read = parseApproverRead(currentRead, currentActorId);
    if (!read || !read.can_configure || read.state === 'unavailable' ||
        review.actorId !== currentActorId ||
        review.readGeneration !== read.read_generation ||
        review.expectedRevision !== read.revision) return null;

    const command = approverCommand(
        commandId, review.expectedRevision, review.selectedIds, review.reason,
    );
    const candidates = new Set(read.candidate_options.map((person) => person.id));
    if (!command || command.authorized_user_ids.some((id) => !candidates.has(id))) return null;
    return command;
}

/** Hashing may finish after an account, permission, Site or list refresh.
 * Never dispatch that stale preparation or silently substitute fresh choices. */
export function preparedApproverStillCurrent(
    currentActorId: number,
    currentRead: unknown,
    review: ApproverReview,
    attempt: ApproverAttempt,
): boolean {
    if (attempt.actorId !== currentActorId) return false;
    const current = bindApproverReview(
        currentActorId, currentRead, review, attempt.command.command_id,
    );
    return current !== null && approverIntentJson(current) === approverIntentJson(attempt.command);
}
