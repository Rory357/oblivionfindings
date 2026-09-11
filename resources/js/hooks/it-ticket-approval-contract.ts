import { draftRecord, IT_DRAFT_UUID } from './it-ticket-draft-contract';

export type ItApprovalOperation = 'request' | 'decide' | 'withdraw';
export type ItApprovalDecision = 'approve' | 'reject';
export type ItApprovalStatus =
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'expired'
    | 'cancelled';
export interface ItApprovalFields {
    reason: string | null;
    decision?: ItApprovalDecision;
    primary_approver_user_id?: number | null;
    cover_approver_user_id?: number | null;
    expires_at?: string | null;
    remind_at?: string | null;
}
export const approvalFieldNames: Record<
    ItApprovalOperation,
    readonly string[]
> = {
    request: [
        'reason',
        'primary_approver_user_id',
        'cover_approver_user_id',
        'expires_at',
        'remind_at',
    ],
    decide: ['reason', 'decision'],
    withdraw: ['reason'],
};
export interface ItApprovalIdentity {
    actorId: number;
    ticketId: number;
    operation: ItApprovalOperation;
    approvalId: number | null;
    requestUuid: string;
    expectedVersion?: number;
}
export interface ItApprovalIntent extends ItApprovalIdentity {
    expectedVersion: number;
    fields: ItApprovalFields;
}
export interface ItApprovalCommitted {
    id: number;
    viewer_user_id: number;
    request_uuid: string;
    operation: `approval.${ItApprovalOperation}`;
    approval_id: number;
    approval_status: ItApprovalStatus;
    lock_version: number;
    changed: true;
    replayed: boolean;
}
export interface ItApprovalCancelled {
    id: number;
    viewer_user_id: number;
    request_uuid: string;
    operation: `approval.${ItApprovalOperation}`;
    approval_id: number | null;
    cancelled_at: string;
    replayed: boolean;
}
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;

function validIdentity(identity: ItApprovalIdentity): boolean {
    return (
        positive(identity.actorId) &&
        positive(identity.ticketId) &&
        IT_DRAFT_UUID.test(identity.requestUuid) &&
        (identity.operation === 'request'
            ? identity.approvalId === null
            : ['decide', 'withdraw'].includes(identity.operation) &&
              positive(identity.approvalId)) &&
        (identity.expectedVersion === undefined ||
            positive(identity.expectedVersion))
    );
}

/** The original proposal owns its version and UUID; review never silently replaces either. */
export function freezeItApprovalIntent(
    identity: ItApprovalIdentity & { expectedVersion: number },
    fields: ItApprovalFields,
): ItApprovalIntent | null {
    if (!validIdentity(identity) || !positive(identity.expectedVersion))
        return null;
    if (fields.reason !== null && typeof fields.reason !== 'string')
        return null;
    const reason = fields.reason?.trim() || null;
    if (reason !== null && Array.from(reason).length > 1000) return null;
    if (identity.operation === 'withdraw' && reason === null) return null;
    if (
        identity.operation === 'decide'
            ? !['approve', 'reject'].includes(fields.decision ?? '') ||
              (fields.decision === 'reject' && reason === null)
            : fields.decision !== undefined
    )
        return null;
    const responsibility: Partial<ItApprovalFields> = {};
    if (
        [
            'primary_approver_user_id',
            'cover_approver_user_id',
            'expires_at',
            'remind_at',
        ].some((key) => key in fields)
    ) {
        if (
            identity.operation !== 'request' ||
            !positive(fields.primary_approver_user_id) ||
            fields.primary_approver_user_id === identity.actorId
        )
            return null;
        const cover = fields.cover_approver_user_id ?? null;
        if (
            cover !== null &&
            (!positive(cover) ||
                cover === identity.actorId ||
                cover === fields.primary_approver_user_id)
        )
            return null;
        responsibility.primary_approver_user_id =
            fields.primary_approver_user_id;
        responsibility.cover_approver_user_id = cover;
        for (const field of ['expires_at', 'remind_at'] as const) {
            const value = fields[field] ?? null;
            if (
                value !== null &&
                (typeof value !== 'string' ||
                    !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/.test(
                        value,
                    ) ||
                    !Number.isFinite(Date.parse(value)))
            )
                return null;
            // No current-time comparison: a retry retains the original proposal after its deadline.
            responsibility[field] =
                value === null ? null : new Date(value).toISOString();
        }
        if (
            responsibility.remind_at &&
            responsibility.expires_at &&
            Date.parse(responsibility.remind_at) >=
                Date.parse(responsibility.expires_at)
        )
            return null;
    }
    return Object.freeze({
        actorId: identity.actorId,
        ticketId: identity.ticketId,
        operation: identity.operation,
        approvalId: identity.approvalId,
        requestUuid: identity.requestUuid,
        expectedVersion: identity.expectedVersion,
        fields: Object.freeze({
            reason,
            ...responsibility,
            ...(identity.operation === 'decide'
                ? { decision: fields.decision }
                : {}),
        }),
    });
}

function matches(data: Record<string, unknown>, identity: ItApprovalIdentity) {
    return (
        validIdentity(identity) &&
        data.id === identity.ticketId &&
        data.viewer_user_id === identity.actorId &&
        data.request_uuid === identity.requestUuid &&
        data.operation === `approval.${identity.operation}` &&
        typeof data.replayed === 'boolean'
    );
}

/** Receipt state describes the original command, not the approval's current state. */
export function readItApprovalCommitted(
    value: unknown,
    identity: ItApprovalIdentity | ItApprovalIntent,
): ItApprovalCommitted | null {
    if (!draftRecord(value) || value.status !== 'committed') return null;
    const data = value.data;
    if (
        !draftRecord(data) ||
        !matches(data, identity) ||
        !positive(data.approval_id) ||
        !positive(data.lock_version) ||
        data.changed !== true ||
        (identity.expectedVersion !== undefined &&
            data.lock_version <= identity.expectedVersion)
    )
        return null;
    if (
        identity.operation === 'request'
            ? data.approval_status !== 'pending'
            : data.approval_id !== identity.approvalId ||
              !(
                  identity.operation === 'withdraw'
                      ? ['cancelled']
                      : ['approved', 'rejected']
              ).includes(String(data.approval_status))
    )
        return null;
    if (
        identity.operation === 'decide' &&
        'fields' in identity &&
        data.approval_status !==
            (identity.fields.decision === 'approve' ? 'approved' : 'rejected')
    )
        return null;
    return {
        id: data.id as number,
        viewer_user_id: data.viewer_user_id as number,
        request_uuid: data.request_uuid as string,
        operation: data.operation as ItApprovalCommitted['operation'],
        approval_id: data.approval_id,
        approval_status: data.approval_status as ItApprovalStatus,
        lock_version: data.lock_version,
        changed: true,
        replayed: data.replayed as boolean,
    };
}

/** A 404, timeout or cancelled browser request is never a server cancellation receipt. */
export function readItApprovalCancelled(
    value: unknown,
    identity: ItApprovalIdentity,
): ItApprovalCancelled | null {
    if (!draftRecord(value) || value.status !== 'cancelled') return null;
    const data = value.data;
    if (
        !draftRecord(data) ||
        !matches(data, identity) ||
        data.approval_id !== identity.approvalId ||
        typeof data.cancelled_at !== 'string' ||
        !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(
            data.cancelled_at,
        ) ||
        !Number.isFinite(Date.parse(data.cancelled_at))
    )
        return null;
    return {
        id: data.id as number,
        viewer_user_id: data.viewer_user_id as number,
        request_uuid: data.request_uuid as string,
        operation: data.operation as ItApprovalCancelled['operation'],
        approval_id: identity.approvalId,
        cancelled_at: data.cancelled_at,
        replayed: data.replayed as boolean,
    };
}

export function itApprovalMutation(intent: ItApprovalIntent) {
    const frozen = freezeItApprovalIntent(intent, intent.fields);
    if (!frozen) throw new Error('The approval proposal could not be checked.');
    return {
        url:
            frozen.operation === 'request'
                ? `/it/tickets/${frozen.ticketId}/approvals`
                : `/it/tickets/${frozen.ticketId}/approvals/${frozen.approvalId}/${frozen.operation}`,
        data: {
            actor_user_id: frozen.actorId,
            request_uuid: frozen.requestUuid,
            expected_version: frozen.expectedVersion,
            ...frozen.fields,
        },
    };
}

export function itApprovalReceiptPath(identity: ItApprovalIdentity): string {
    if (!validIdentity(identity))
        throw new Error('The approval reference could not be checked.');
    return `/it/tickets/${identity.ticketId}/approval-commands/${identity.operation}/${identity.requestUuid}`;
}

export function itApprovalReceiptParameters(identity: ItApprovalIdentity) {
    if (!validIdentity(identity))
        throw new Error('The approval reference could not be checked.');
    return {
        actor_user_id: identity.actorId,
        ...(identity.operation !== 'request'
            ? { approval_id: identity.approvalId }
            : {}),
    };
}
