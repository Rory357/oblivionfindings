import { createOfflineRequestUuid } from '@/lib/offline-queue';

export type ScheduledStockCountCreatePayload = {
    scheduled_date: string;
    scheduled_time: string | null;
    expected_quantity: string | null;
    notes: string | null;
    client_request_uuid: string;
};

export type ScheduledStockCountCompletePayload = {
    actual_quantity: string;
    notes: string | null;
    witnessed_by: number | null;
    scan_code: string | null;
    scan_source: string | null;
    scan_verified: boolean;
    scan_match_source: string | null;
    client_request_uuid: string;
};

export type ScheduledStockCountReplayState = {
    uuid: string;
    fingerprint: string | null;
};

export function newScheduledStockCountRequestUuid(): string {
    return createOfflineRequestUuid();
}

export function createScheduledStockCountReplayState(
    createUuid: () => string = newScheduledStockCountRequestUuid,
): ScheduledStockCountReplayState {
    return { uuid: createUuid(), fingerprint: null };
}

export function prepareScheduledStockCountReplayState(
    current: ScheduledStockCountReplayState,
    scope: { clientId: number; medicationId: number },
    payload: ScheduledStockCountCreatePayload,
    createUuid: () => string = newScheduledStockCountRequestUuid,
): ScheduledStockCountReplayState {
    const fingerprint = JSON.stringify({
        client_id: scope.clientId,
        client_medication_id: scope.medicationId,
        scheduled_date: payload.scheduled_date,
        scheduled_time: payload.scheduled_time,
        expected_quantity: payload.expected_quantity,
        notes: payload.notes,
    });

    if (current.fingerprint !== null && current.fingerprint !== fingerprint) {
        return { uuid: createUuid(), fingerprint };
    }

    return { uuid: current.uuid, fingerprint };
}

export function prepareScheduledStockCountCompletionReplayState(
    current: ScheduledStockCountReplayState,
    scope: { clientId: number; medicationId: number; countId: number },
    payload: ScheduledStockCountCompletePayload,
    createUuid: () => string = newScheduledStockCountRequestUuid,
): ScheduledStockCountReplayState {
    const fingerprint = JSON.stringify({
        client_id: scope.clientId,
        client_medication_id: scope.medicationId,
        scheduled_stock_count_id: scope.countId,
        actual_quantity: payload.actual_quantity,
        notes: payload.notes,
        witnessed_by: payload.witnessed_by,
        scan_code: payload.scan_code,
        scan_source: payload.scan_source,
        scan_verified: payload.scan_verified,
        scan_match_source: payload.scan_match_source,
    });

    if (current.fingerprint !== null && current.fingerprint !== fingerprint) {
        return { uuid: createUuid(), fingerprint };
    }

    return { uuid: current.uuid, fingerprint };
}
