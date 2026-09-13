import { draftRecord, IT_DRAFT_UUID } from './it-ticket-draft-contract';

export interface ItMergePreviewIdentity {
    actorId: number;
    sourceId: number;
    targetId: number;
    sourceVersion: number;
    targetVersion: number;
    nonce: string;
}

const inventoryFields = [
    'public_comments',
    'internal_notes',
    'ticket_files',
    'comment_files',
    'watchers',
    'links',
    'tasks',
    'unfinished_required_tasks',
    'approval_requests',
    'pending_approval_requests',
    'expired_approval_requests',
] as const;
export type ItMergeInventory = Record<(typeof inventoryFields)[number], number>;

export interface ItMergePreviewRecord {
    id: number;
    reference: string | null;
    title: string;
    lock_version: number;
    status: string;
    workflow_state: string | null;
    work_type: string;
    inventory: ItMergeInventory;
}

const accessFields = [
    'site_id',
    'is_organisation_wide',
    'is_sensitive',
    'requester_user_id',
    'requested_for_user_id',
    'assigned_to_user_id',
    'owner_user_id',
    'team_id',
    'queue_id',
] as const;
type AccessField = (typeof accessFields)[number];
export interface ItMergePreview {
    review_token: string;
    source: ItMergePreviewRecord;
    target: ItMergePreviewRecord;
    access_scope_differences: {
        field: AccessField;
        source: number | boolean | null;
        target: number | boolean | null;
    }[];
    lifecycle_blockers: string[];
}

const natural = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) >= 0;
const positive = (value: unknown): value is number =>
    natural(value) && value > 0;

function readRecord(
    value: unknown,
    id: number,
    version: number,
): ItMergePreviewRecord | null {
    if (
        !draftRecord(value) ||
        value.id !== id ||
        value.lock_version !== version ||
        (value.reference !== null &&
            (typeof value.reference !== 'string' ||
                !/^IT-\d{6,}$/.test(value.reference))) ||
        typeof value.title !== 'string' ||
        value.title.length > 500 ||
        !['open', 'in_progress', 'waiting', 'resolved', 'closed'].includes(
            String(value.status),
        ) ||
        (value.workflow_state !== null &&
            (typeof value.workflow_state !== 'string' ||
                value.workflow_state.length > 80)) ||
        typeof value.work_type !== 'string' ||
        value.work_type.length > 80 ||
        !draftRecord(value.inventory)
    )
        return null;
    const inventory = value.inventory;
    if (!inventoryFields.every((field) => natural(inventory[field])))
        return null;
    const counts = Object.fromEntries(
        inventoryFields.map((field) => [field, inventory[field]]),
    ) as ItMergeInventory;
    if (
        counts.unfinished_required_tasks > counts.tasks ||
        counts.pending_approval_requests + counts.expired_approval_requests >
            counts.approval_requests
    )
        return null;
    return {
        id,
        reference: value.reference,
        title: value.title,
        lock_version: version,
        status: String(value.status),
        workflow_state: value.workflow_state as string | null,
        work_type: value.work_type,
        inventory: counts,
    };
}

/** A matching read is evidence for review only. A later command must revalidate both records. */
export function readItMergePreview(
    value: unknown,
    identity: ItMergePreviewIdentity,
): ItMergePreview | null {
    if (
        ![
            identity.actorId,
            identity.sourceId,
            identity.targetId,
            identity.sourceVersion,
            identity.targetVersion,
        ].every(positive) ||
        !IT_DRAFT_UUID.test(identity.nonce) ||
        !draftRecord(value) ||
        value.status !== 'reviewed' ||
        !draftRecord(value.data) ||
        value.data.viewer_user_id !== identity.actorId ||
        value.data.review_nonce !== identity.nonce
    )
        return null;
    const data = value.data;
    const source = readRecord(
        data.source,
        identity.sourceId,
        identity.sourceVersion,
    );
    const target = readRecord(
        data.target,
        identity.targetId,
        identity.targetVersion,
    );
    if (
        !source ||
        !target ||
        typeof data.review_token !== 'string' ||
        data.review_token.length < 1 ||
        data.review_token.length > 10000 ||
        !Array.isArray(data.lifecycle_blockers) ||
        data.lifecycle_blockers.length > 8 ||
        !data.lifecycle_blockers.every(
            (entry) =>
                typeof entry === 'string' &&
                entry.length > 0 &&
                entry.length <= 1000,
        ) ||
        !Array.isArray(data.access_scope_differences) ||
        data.access_scope_differences.length > accessFields.length
    )
        return null;
    const differences: ItMergePreview['access_scope_differences'] = [];
    for (const difference of data.access_scope_differences) {
        if (
            !draftRecord(difference) ||
            !accessFields.includes(difference.field as AccessField) ||
            differences.some((entry) => entry.field === difference.field) ||
            difference.source === difference.target
        )
            return null;
        const field = difference.field as AccessField;
        const validValue = (entry: unknown) =>
            entry === null ||
            (field.startsWith('is_')
                ? typeof entry === 'boolean'
                : positive(entry));
        if (!validValue(difference.source) || !validValue(difference.target))
            return null;
        differences.push({
            field,
            source: difference.source as number | boolean | null,
            target: difference.target as number | boolean | null,
        });
    }
    return {
        review_token: data.review_token,
        source,
        target,
        access_scope_differences: differences,
        lifecycle_blockers: [...data.lifecycle_blockers],
    };
}
