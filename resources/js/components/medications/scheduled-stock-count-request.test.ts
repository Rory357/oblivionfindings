import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it, vi } from 'vitest';

import { normalizeMedicationStockQuantityInput } from '../../pages/emar/medication-stock-governance';
import {
    createScheduledStockCountReplayState,
    prepareScheduledStockCountCompletionReplayState,
    prepareScheduledStockCountReplayState,
    type ScheduledStockCountCompletePayload,
    type ScheduledStockCountCreatePayload,
} from './scheduled-stock-count-request';

const scheduledCountsSource = readFileSync(
    resolve(
        process.cwd(),
        'resources/js/components/medications/ScheduledStockCounts.tsx',
    ),
    'utf8',
);

describe('scheduled stock count request contract', () => {
    it('normalizes exact hundredths without truncating fractional stock', () => {
        expect(normalizeMedicationStockQuantityInput('9.5')).toBe('9.50');
        expect(normalizeMedicationStockQuantityInput('9.99')).toBe('9.99');
        expect(normalizeMedicationStockQuantityInput('9.999')).toBeNull();
        expect(normalizeMedicationStockQuantityInput('1e2')).toBeNull();
    });

    it('retains one UUID for an unchanged retry and rotates after the bound payload changes', () => {
        const initialUuid = vi.fn(() => 'first-scheduled-count-uuid');
        const rotatedUuid = vi.fn(() => 'rotated-scheduled-count-uuid');
        const scope = { clientId: 7, medicationId: 41 };
        const payload: ScheduledStockCountCreatePayload = {
            scheduled_date: '2026-08-27',
            scheduled_time: '09:30',
            expected_quantity: '9.50',
            notes: 'Morning count',
            client_request_uuid: 'first-scheduled-count-uuid',
        };
        const initial = createScheduledStockCountReplayState(initialUuid);
        const attempted = prepareScheduledStockCountReplayState(
            initial,
            scope,
            payload,
            rotatedUuid,
        );
        const unchangedRetry = prepareScheduledStockCountReplayState(
            attempted,
            scope,
            payload,
            rotatedUuid,
        );

        expect(attempted.uuid).toBe('first-scheduled-count-uuid');
        expect(unchangedRetry.uuid).toBe('first-scheduled-count-uuid');
        expect(rotatedUuid).not.toHaveBeenCalled();

        const corrected = prepareScheduledStockCountReplayState(
            unchangedRetry,
            scope,
            { ...payload, expected_quantity: '9.25' },
            rotatedUuid,
        );

        expect(corrected.uuid).toBe('rotated-scheduled-count-uuid');
        expect(rotatedUuid).toHaveBeenCalledOnce();
    });

    it('starts a fresh lifecycle after a successful request resets state', () => {
        expect(
            createScheduledStockCountReplayState(
                () => 'next-scheduled-count-uuid',
            ),
        ).toEqual({
            uuid: 'next-scheduled-count-uuid',
            fingerprint: null,
        });
    });

    it('retains one completion UUID for exact retries and rotates after a material correction', () => {
        const createUuid = vi.fn(() => 'rotated-completion-uuid');
        const scope = { clientId: 7, medicationId: 41, countId: 93 };
        const payload: ScheduledStockCountCompletePayload = {
            actual_quantity: '9.50',
            notes: 'Counted twice',
            witnessed_by: null,
            scan_code: 'MED-41',
            scan_source: 'scanner',
            scan_verified: true,
            scan_match_source: 'barcode',
            client_request_uuid: 'first-completion-uuid',
        };
        const initial = {
            uuid: payload.client_request_uuid,
            fingerprint: null,
        };
        const attempted = prepareScheduledStockCountCompletionReplayState(
            initial,
            scope,
            payload,
            createUuid,
        );
        const unchanged = prepareScheduledStockCountCompletionReplayState(
            attempted,
            scope,
            payload,
            createUuid,
        );

        expect(unchanged.uuid).toBe('first-completion-uuid');
        expect(createUuid).not.toHaveBeenCalled();

        const corrected = prepareScheduledStockCountCompletionReplayState(
            unchanged,
            scope,
            { ...payload, actual_quantity: '9.25' },
            createUuid,
        );

        expect(corrected.uuid).toBe('rotated-completion-uuid');
        expect(createUuid).toHaveBeenCalledOnce();
    });

    it('queues noncredential create and completion requests with their retained UUIDs', () => {
        expect(scheduledCountsSource).toContain(
            'allowQueueWhenOffline: !controlledDrug',
        );
        expect(
            scheduledCountsSource.match(
                /allowQueueWhenOffline: !controlledDrug/g,
            ),
        ).toHaveLength(2);
        expect(scheduledCountsSource).toContain(
            'const completionReplays = useRef(',
        );
        expect(scheduledCountsSource).toContain(
            'prepareScheduledStockCountCompletionReplayState(',
        );
        expect(scheduledCountsSource).toContain(
            'client_request_uuid: replay.uuid',
        );
        expect(scheduledCountsSource).toContain(
            'completionReplays.current.delete(countId)',
        );
    });
});
