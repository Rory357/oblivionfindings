export const deliveryStates = {
    pending: 'Awaiting IT delivery',
    failed: 'Delivery failed',
    dead_letter: 'Retry limit reached',
    unroutable: 'Source review required',
    applied: 'IT outcome recorded',
    ignored: 'No IT work required',
    unverified: 'Outcome unverified',
};
export const deliveryOutcomes: Record<string, string> = {
    ticket_created: 'Technical work created',
    ticket_updated: 'Existing technical work updated',
    recovery_recorded: 'Recovery recorded for technician verification',
    recovery_unmatched: 'No matching fault for this recovery',
    source_suppressed: 'Suppressed by source policy',
    unsupported_event: 'Source event is not supported for IT work',
    source_scope_changed: 'Source ownership or evidence changed',
    source_unavailable: 'Source is unavailable',
    technical_routing_unavailable: 'Technical routing requires review',
    canonical_evidence_unavailable: 'Canonical source evidence requires review',
    processing_failed: 'Delivery could not finish',
};
export type DeliverySource = 'device' | 'fleet';
export interface DeliveryRow {
    id: number;
    state: keyof typeof deliveryStates;
    outcome_code: string | null;
    source_delivered: boolean;
    attempts: number | null;
    attempt_limit: number | null;
    last_attempt_at: string | null;
    completed_at: string | null;
    created_at: string | null;
}
export interface DeliveryReview extends DeliveryRow {
    viewer_user_id: number;
    source: DeliverySource;
    version: string;
    can_retry: boolean;
}
export const deliveryTone = (row: DeliveryRow) =>
    row.state === 'applied'
        ? 'success'
        : ['failed', 'dead_letter', 'unroutable'].includes(row.state)
          ? 'critical'
          : row.state === 'pending'
            ? 'warning'
            : 'neutral';
export const deliveryOutcome = (row: DeliveryRow) =>
    row.outcome_code && Object.hasOwn(deliveryOutcomes, row.outcome_code)
        ? deliveryOutcomes[row.outcome_code]
        : 'No classified outcome recorded';

export function parseDeliveryReview(
    payload: unknown,
    actorId: number,
    source: DeliverySource,
    id: number,
    retry = false,
): DeliveryReview | null {
    if (!payload || typeof payload !== 'object' || !('data' in payload))
        return null;
    const data = payload.data;
    if (!data || typeof data !== 'object' || Array.isArray(data)) return null;
    const row = data as Record<string, unknown>;
    const count = (value: unknown): value is number | null =>
        value === null ||
        (typeof value === 'number' &&
            Number.isSafeInteger(value) &&
            value >= 0);
    const date = (value: unknown): value is string | null =>
        value === null ||
        (typeof value === 'string' &&
            value.length <= 40 &&
            Number.isFinite(Date.parse(value)));
    if (
        row.viewer_user_id !== actorId ||
        row.source !== source ||
        row.id !== id ||
        typeof row.version !== 'string' ||
        !/^[a-f0-9]{64}$/.test(row.version) ||
        typeof row.state !== 'string' ||
        !Object.hasOwn(deliveryStates, row.state) ||
        typeof row.can_retry !== 'boolean' ||
        typeof row.source_delivered !== 'boolean' ||
        !count(row.attempts) ||
        !count(row.attempt_limit) ||
        !date(row.created_at) ||
        !date(row.completed_at) ||
        !date(row.last_attempt_at) ||
        !(row.outcome_code === null || typeof row.outcome_code === 'string') ||
        (retry && row.retry_requested !== true) ||
        (row.can_retry &&
            (row.attempts === null ||
                row.attempt_limit === null ||
                !row.source_delivered ||
                !['failed', 'dead_letter', 'unroutable'].includes(row.state)))
    )
        return null;
    return {
        viewer_user_id: actorId,
        source,
        id,
        version: row.version,
        can_retry: row.can_retry,
        state: row.state as DeliveryRow['state'],
        source_delivered: row.source_delivered,
        attempts: row.attempts,
        attempt_limit: row.attempt_limit,
        created_at: row.created_at,
        completed_at: row.completed_at,
        last_attempt_at: row.last_attempt_at,
        outcome_code:
            typeof row.outcome_code === 'string' &&
            Object.hasOwn(deliveryOutcomes, row.outcome_code)
                ? row.outcome_code
                : null,
    };
}
