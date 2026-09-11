import {
    draftRecord,
    IT_DRAFT_UUID,
    type ItDraftCommitReference,
} from './it-ticket-draft-contract';

export interface ItCommentIntent {
    actorId: number;
    ticketId: number;
    requestUuid: string;
    expectedVersion: number;
    isInternal: boolean;
    body: string;
    files: readonly File[];
    draft?: Readonly<ItDraftCommitReference>;
}

export interface ItCommentIdentity {
    actorId: number;
    ticketId: number;
    requestUuid: string;
    isInternal: boolean;
    /** Unknown after recovering an opaque command reference from a new document. */
    expectedVersion?: number;
    draft?: Readonly<ItDraftCommitReference>;
}

export interface ItCommentAccessProof {
    currentVersion: number;
    canSubmit: boolean;
    blocker: { code: string; message: string } | null;
}

export interface ItCommentCancelled {
    id: number;
    viewer_user_id: number;
    request_uuid: string;
    is_internal: boolean;
    cancelled_at: string;
    replayed: boolean;
}

/** Cancellation is an explicit serialized receipt, never inferred from a 404. */
export function readItCommentCancelled(
    value: unknown,
    identity: ItCommentIdentity,
): ItCommentCancelled | null {
    if (
        !draftRecord(value) ||
        value.status !== 'cancelled' ||
        !draftRecord(value.data)
    )
        return null;
    const data = value.data;
    if (
        !positive(identity.actorId) ||
        !positive(identity.ticketId) ||
        !IT_DRAFT_UUID.test(identity.requestUuid) ||
        data.id !== identity.ticketId ||
        data.viewer_user_id !== identity.actorId ||
        data.request_uuid !== identity.requestUuid ||
        data.is_internal !== identity.isInternal ||
        typeof data.replayed !== 'boolean' ||
        typeof data.cancelled_at !== 'string' ||
        !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/.test(
            data.cancelled_at,
        ) ||
        !Number.isFinite(Date.parse(data.cancelled_at))
    )
        return null;
    return {
        id: data.id as number,
        viewer_user_id: data.viewer_user_id as number,
        request_uuid: data.request_uuid as string,
        is_internal: data.is_internal as boolean,
        cancelled_at: data.cancelled_at,
        replayed: data.replayed,
    };
}

/** Permission-only canonical proof; it never changes the original command version. */
export function readItCommentAccessProof(
    value: unknown,
    identity: ItCommentIdentity,
    nonce: string,
): ItCommentAccessProof | null {
    const data =
        draftRecord(value) && draftRecord(value.candidate)
            ? value.candidate
            : null;
    if (
        !data ||
        data.kind !== 'memory' ||
        data.authorized !== true ||
        data.memory_uuid !== identity.requestUuid ||
        data.candidate_uuid !== nonce ||
        data.actor_user_id !== identity.actorId ||
        data.purpose !==
            (identity.isInternal ? 'internal_note' : 'public_reply') ||
        data.context_key !== `ticket:${identity.ticketId}` ||
        data.base_ticket_version !== (identity.expectedVersion ?? null) ||
        !positive(data.current_ticket_version) ||
        !draftRecord(data.capabilities) ||
        typeof data.capabilities.submit !== 'boolean' ||
        (data.blocker !== null &&
            (!draftRecord(data.blocker) ||
                typeof data.blocker.code !== 'string' ||
                typeof data.blocker.message !== 'string'))
    )
        return null;
    return {
        currentVersion: data.current_ticket_version,
        canSubmit: data.capabilities.submit,
        blocker: data.blocker as ItCommentAccessProof['blocker'],
    };
}

export function newItCommentUuid(): string {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, (value) =>
        value.toString(16).padStart(2, '0'),
    ).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

const deliveryStates = [
    'queued',
    'sending',
    'accepted',
    'delivered',
    'failed',
    'bounced',
    'retried',
] as const;
export type ItCommentDeliveryState = (typeof deliveryStates)[number];
export interface ItCommentCommitted {
    id: number;
    /** The original command stays bound to id when a governed merge moves its comment. */
    canonical_ticket_id: number;
    comment_id: number;
    viewer_user_id: number;
    request_uuid: string;
    is_internal: boolean;
    lock_version: number;
    replayed: boolean;
    delivery: {
        requested: boolean;
        attempt_statuses: Partial<Record<ItCommentDeliveryState, number>>;
    };
    draft?: {
        draft_uuid: string;
        submitted_revision: number;
        revision: number;
        state: 'consumed';
    };
}

const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && (value as number) > 0;
const natural = (value: unknown): value is number =>
    Number.isSafeInteger(value) && (value as number) >= 0;

/** A redirect, flash, unrelated comment or partial acknowledgement is never success. */
export function readItCommentCommitted(
    value: unknown,
    identity: ItCommentIdentity,
): ItCommentCommitted | null {
    if (
        !draftRecord(value) ||
        value.status !== 'committed' ||
        !draftRecord(value.data)
    )
        return null;
    const data = value.data;
    if (
        !positive(identity.actorId) ||
        !positive(identity.ticketId) ||
        !IT_DRAFT_UUID.test(identity.requestUuid) ||
        data.id !== identity.ticketId ||
        !positive(data.canonical_ticket_id) ||
        data.viewer_user_id !== identity.actorId ||
        data.request_uuid !== identity.requestUuid ||
        data.is_internal !== identity.isInternal ||
        !positive(data.comment_id) ||
        !positive(data.lock_version) ||
        (identity.expectedVersion !== undefined &&
            (!positive(identity.expectedVersion) ||
                data.lock_version <= identity.expectedVersion)) ||
        typeof data.replayed !== 'boolean' ||
        !draftRecord(data.delivery) ||
        typeof data.delivery.requested !== 'boolean' ||
        !draftRecord(data.delivery.attempt_statuses)
    )
        return null;
    const statuses: Partial<Record<ItCommentDeliveryState, number>> = {};
    for (const [status, count] of Object.entries(
        data.delivery.attempt_statuses,
    )) {
        if (
            !(deliveryStates as readonly string[]).includes(status) ||
            !positive(count)
        )
            return null;
        statuses[status as ItCommentDeliveryState] = count;
    }
    const requested = Object.keys(statuses).length > 0;
    if (
        requested !== data.delivery.requested ||
        (identity.isInternal && requested)
    )
        return null;
    let draft: ItCommentCommitted['draft'];
    if (data.draft !== undefined) {
        if (
            !draftRecord(data.draft) ||
            typeof data.draft.draft_uuid !== 'string' ||
            !IT_DRAFT_UUID.test(data.draft.draft_uuid) ||
            !natural(data.draft.submitted_revision) ||
            !natural(data.draft.revision) ||
            data.draft.revision !== data.draft.submitted_revision + 1 ||
            data.draft.state !== 'consumed'
        )
            return null;
        draft = {
            draft_uuid: data.draft.draft_uuid,
            submitted_revision: data.draft.submitted_revision,
            revision: data.draft.revision as number,
            state: 'consumed',
        };
    }
    if (
        identity.draft &&
        (identity.draft.draft_actor_user_id !== identity.actorId ||
            !draft ||
            draft.draft_uuid !== identity.draft.draft_uuid ||
            draft.submitted_revision !== identity.draft.draft_revision)
    )
        return null;
    if (identity.expectedVersion !== undefined && !identity.draft && draft)
        return null;
    return {
        id: data.id as number,
        canonical_ticket_id: data.canonical_ticket_id,
        comment_id: data.comment_id,
        viewer_user_id: data.viewer_user_id as number,
        request_uuid: data.request_uuid as string,
        is_internal: data.is_internal as boolean,
        lock_version: data.lock_version,
        replayed: data.replayed,
        delivery: { requested, attempt_statuses: statuses },
        ...(draft ? { draft } : {}),
    };
}

/** Freeze text, audience, original version and File objects before any async work. */
export function freezeItCommentIntent(
    input: ItCommentIntent,
): Readonly<ItCommentIntent> {
    if (
        !positive(input.actorId) ||
        !positive(input.ticketId) ||
        !positive(input.expectedVersion) ||
        !IT_DRAFT_UUID.test(input.requestUuid) ||
        typeof input.isInternal !== 'boolean' ||
        typeof input.body !== 'string' ||
        !input.body.trim() ||
        input.body.length > 5000 ||
        input.files.length > 5 ||
        input.files.some(
            (file) => !(file instanceof File) || file.size > 10 * 1024 * 1024,
        ) ||
        (input.draft &&
            (input.draft.draft_actor_user_id !== input.actorId ||
                !IT_DRAFT_UUID.test(input.draft.draft_uuid) ||
                !natural(input.draft.draft_revision)))
    ) {
        throw new Error(
            'Review the reply identity, text and files before submitting.',
        );
    }
    return Object.freeze({
        ...input,
        files: Object.freeze([...input.files]),
        ...(input.draft ? { draft: Object.freeze({ ...input.draft }) } : {}),
    });
}

/** Each retry reconstructs transport from the same immutable command, never newer fields. */
export function itCommentFormData(intent: Readonly<ItCommentIntent>): FormData {
    const form = new FormData();
    form.set('actor_user_id', String(intent.actorId));
    form.set('request_uuid', intent.requestUuid);
    form.set('expected_version', String(intent.expectedVersion));
    form.set('body', intent.body);
    form.set('is_internal', intent.isInternal ? '1' : '0');
    intent.files.forEach((file) =>
        form.append('attachments[]', file, file.name),
    );
    if (intent.draft) {
        form.set('draft_uuid', intent.draft.draft_uuid);
        form.set('draft_revision', String(intent.draft.draft_revision));
        form.set(
            'draft_actor_user_id',
            String(intent.draft.draft_actor_user_id),
        );
    }
    return form;
}

/** Comment commit and delivery are separate outcomes. Accepted never means delivered. */
export function itCommentCommitMessage(result: ItCommentCommitted): string {
    if (result.is_internal) return 'Internal note added.';
    const states = result.delivery.attempt_statuses;
    if (!result.delivery.requested)
        return 'Reply added. No email notification was requested.';
    if (states.failed || states.bounced)
        return 'Reply added. An email notification failed; review its delivery status.';
    if (states.queued || states.sending || states.retried)
        return 'Reply added. Its email notification was queued; check the message for delivery updates.';
    if (states.accepted)
        return 'Reply added. The email provider accepted the notification; delivery is not yet confirmed.';
    return 'Reply added. Email delivery is confirmed.';
}
