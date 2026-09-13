export type QuarantineRecord = {
    id: number;
    version: number;
    status:
        | 'pending'
        | 'processed'
        | 'quarantined'
        | 'unmatched'
        | 'rejected'
        | 'duplicate';
    reason: string | null;
    received_at: string | null;
    retry_requested_at: string | null;
    can_retry: boolean;
    guidance: string;
};
export type QuarantinePage = {
    connection_id: number;
    connection_version: number;
    records: QuarantineRecord[];
    next_before_id: number | null;
};
const integer = (value: unknown, minimum = 1): value is number =>
    Number.isSafeInteger(value) && (value as number) >= minimum;
const date = (value: unknown) =>
    value === null ||
    (typeof value === 'string' &&
        value.length <= 40 &&
        Number.isFinite(Date.parse(value)));
export function validQuarantineRecord(
    value: unknown,
): value is QuarantineRecord {
    if (!value || typeof value !== 'object') return false;
    const record = value as QuarantineRecord;
    return (
        integer(record.id) &&
        integer(record.version, 0) &&
        [
            'pending',
            'processed',
            'quarantined',
            'unmatched',
            'rejected',
            'duplicate',
        ].includes(record.status) &&
        (record.reason === null ||
            (typeof record.reason === 'string' &&
                record.reason.length <= 200)) &&
        date(record.received_at) &&
        date(record.retry_requested_at) &&
        typeof record.can_retry === 'boolean' &&
        (!record.can_retry || record.status === 'quarantined') &&
        typeof record.guidance === 'string' &&
        record.guidance.length > 0 &&
        record.guidance.length <= 500
    );
}
export function validQuarantinePage(value: unknown): value is QuarantinePage {
    if (!value || typeof value !== 'object') return false;
    const page = value as QuarantinePage;
    return (
        integer(page.connection_id) &&
        integer(page.connection_version) &&
        Array.isArray(page.records) &&
        page.records.length <= 25 &&
        page.records.every(validQuarantineRecord) &&
        new Set(page.records.map((record) => record.id)).size ===
            page.records.length &&
        (page.next_before_id === null ||
            (integer(page.next_before_id) &&
                page.records.length === 25 &&
                page.next_before_id === page.records.at(-1)?.id))
    );
}
