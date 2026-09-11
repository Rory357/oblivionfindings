import { draftRecord, IT_DRAFT_UUID } from './it-ticket-draft-contract';
import type { ItMergePreview } from './it-ticket-merge-preview';

export interface ItMergeIdentity {
    actorId: number;
    sourceId: number;
    targetId: number;
    requestUuid: string;
}
export interface ItMergeIntent extends ItMergeIdentity {
    sourceVersion: number;
    targetVersion: number;
    reason: string;
    reviewToken: string;
}
export type ItMergeResult =
    | {
          status: 'committed';
          sourceVersion: number;
          targetVersion: number;
          url: string;
          replayed: boolean;
      }
    | { status: 'cancelled'; cancelledAt: string; replayed: boolean };

const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
export function validItMergeIdentity(identity: ItMergeIdentity): boolean {
    return (
        [identity.actorId, identity.sourceId, identity.targetId].every(
            positive,
        ) &&
        identity.sourceId !== identity.targetId &&
        IT_DRAFT_UUID.test(identity.requestUuid)
    );
}

/** A new command binds the exact review and reason. Recovery needs only opaque identities. */
export function freezeItMergeIntent(
    identity: ItMergeIdentity,
    preview: ItMergePreview,
    reason: string,
): Readonly<ItMergeIntent> | null {
    const trimmed = reason.trim();
    if (
        !validItMergeIdentity(identity) ||
        preview.source.id !== identity.sourceId ||
        preview.target.id !== identity.targetId ||
        !positive(preview.source.lock_version) ||
        !positive(preview.target.lock_version) ||
        preview.lifecycle_blockers.length > 0 ||
        trimmed.length === 0 ||
        Array.from(trimmed).length > 1000 ||
        !preview.review_token ||
        preview.review_token.length > 10000
    )
        return null;
    return Object.freeze({
        actorId: identity.actorId,
        sourceId: identity.sourceId,
        targetId: identity.targetId,
        requestUuid: identity.requestUuid,
        sourceVersion: preview.source.lock_version,
        targetVersion: preview.target.lock_version,
        reason: trimmed,
        reviewToken: preview.review_token,
    });
}

export function itMergePayload(intent: Readonly<ItMergeIntent>) {
    return {
        actor_user_id: intent.actorId,
        target_ticket_id: intent.targetId,
        request_uuid: intent.requestUuid,
        source_version: intent.sourceVersion,
        target_version: intent.targetVersion,
        reason: intent.reason,
        review_token: intent.reviewToken,
    };
}
export function itMergeReceiptPath(identity: ItMergeIdentity) {
    return `/it/tickets/${identity.sourceId}/merge-commands/${identity.requestUuid}`;
}
export function itMergeReceiptParameters(identity: ItMergeIdentity) {
    return {
        actor_user_id: identity.actorId,
        target_ticket_id: identity.targetId,
    };
}

/** Never navigate or announce success from a flash, generic 200, or another pair's receipt. */
export function readItMergeResult(
    value: unknown,
    identity: ItMergeIdentity,
    intent?: Readonly<ItMergeIntent> | null,
): ItMergeResult | null {
    if (
        !validItMergeIdentity(identity) ||
        !draftRecord(value) ||
        !draftRecord(value.data)
    )
        return null;
    const data = value.data;
    if (
        data.viewer_user_id !== identity.actorId ||
        data.source_id !== identity.sourceId ||
        data.target_id !== identity.targetId ||
        data.request_uuid !== identity.requestUuid ||
        data.operation !== 'ticket.merge' ||
        typeof data.replayed !== 'boolean'
    )
        return null;
    if (value.status === 'cancelled') {
        if (
            typeof data.cancelled_at !== 'string' ||
            !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/.test(
                data.cancelled_at,
            ) ||
            !Number.isFinite(Date.parse(data.cancelled_at))
        )
            return null;
        return {
            status: 'cancelled',
            cancelledAt: data.cancelled_at,
            replayed: data.replayed,
        };
    }
    const url = `/it/tickets/${identity.targetId}?merged_from=${identity.sourceId}`;
    if (
        value.status !== 'committed' ||
        !positive(data.source_version) ||
        !positive(data.target_version) ||
        data.url !== url ||
        (intent &&
            (data.source_version !== intent.sourceVersion + 2 ||
                data.target_version !== intent.targetVersion + 1))
    )
        return null;
    return {
        status: 'committed',
        sourceVersion: data.source_version,
        targetVersion: data.target_version,
        url,
        replayed: data.replayed,
    };
}
