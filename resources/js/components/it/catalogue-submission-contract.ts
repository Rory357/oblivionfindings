/** Identity retained for recovery contains no request values or files. */
export interface CatalogueSubmissionIdentity {
    actorId: number;
    itemId: number;
    schemaVersion: number;
    requestUuid: string;
}

export interface CatalogueSubmissionResult {
    status: 'committed';
    submissionId: number;
    resultId: number;
    resultType: 'ticket' | 'provisioning';
    reference: string | null;
    url: string;
    replayed: boolean;
}

export type CatalogueSubmissionOutcome =
    | CatalogueSubmissionResult
    | { status: 'cancelled' }
    | { status: 'not_found'; retrySameCommand: true };

const record = (value: unknown): value is Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value);
const positive = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;

export function isCatalogueSubmissionIdentity(
    value: unknown,
): value is CatalogueSubmissionIdentity {
    return (
        record(value) &&
        positive(value.actorId) &&
        positive(value.itemId) &&
        positive(value.schemaVersion) &&
        typeof value.requestUuid === 'string' &&
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
            value.requestUuid,
        )
    );
}

/** Never treat a redirect, flash message or a different request as a save. */
export function readCatalogueSubmissionOutcome(
    value: unknown,
    identity: CatalogueSubmissionIdentity,
): CatalogueSubmissionOutcome | null {
    if (
        !isCatalogueSubmissionIdentity(identity) ||
        !record(value) ||
        !record(value.data)
    )
        return null;
    const data = value.data;
    if (
        data.viewer_user_id !== identity.actorId ||
        data.catalog_item_id !== identity.itemId ||
        data.request_uuid !== identity.requestUuid
    )
        return null;
    if (value.status === 'cancelled') {
        return data.cancelled === true &&
            data.id === undefined &&
            data.submission_id === undefined
            ? { status: 'cancelled' }
            : null;
    }
    if (value.status === 'not_found') {
        return data.retry_same_command === true &&
            data.id === undefined &&
            data.submission_id === undefined
            ? { status: 'not_found', retrySameCommand: true }
            : null;
    }
    if (
        value.status !== 'committed' ||
        data.schema_version !== identity.schemaVersion ||
        !positive(data.submission_id) ||
        !positive(data.id) ||
        typeof data.replayed !== 'boolean'
    )
        return null;
    const ticket = data.result_type === 'ticket';
    if (!ticket && data.result_type !== 'provisioning') return null;
    if (
        data.url !==
            `${ticket ? '/it/tickets/' : '/it/provisioning/'}${data.id}` ||
        (ticket
            ? typeof data.reference !== 'string' ||
              !/^IT-\d{6,}$/.test(data.reference)
            : data.reference !== null)
    )
        return null;
    return {
        status: 'committed',
        submissionId: data.submission_id,
        resultId: data.id,
        resultType: ticket ? 'ticket' : 'provisioning',
        reference: data.reference as string | null,
        url: data.url as string,
        replayed: data.replayed,
    };
}
