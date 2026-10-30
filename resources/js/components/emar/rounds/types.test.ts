import { describe, expect, it } from 'vitest';

import {
    doseStatusMeta,
    isAway,
    isRecordable,
    isRecordedStatus,
    isWaitingForCheck,
    notOwedCaption,
    roundCounts,
    type RoundCell,
    type RoundItem,
} from './types';

const cell = (status: string): RoundCell =>
    ({ status, medication_id: 1, scheduled_for: '' }) as RoundCell;

const item = (doseState: string, recorded = false): RoundItem =>
    ({
        dose_state: doseState,
        administration: recorded ? { status: doseState } : null,
    }) as RoundItem;

describe('round dose states (P01 C6j, C7)', () => {
    it('labels the projection states a round cell can carry', () => {
        expect(doseStatusMeta('overdue').label).toBe('Overdue');
        expect(doseStatusMeta('pending_check').label).toBe(
            'Waiting for the order check',
        );
        expect(doseStatusMeta('away').label).toBe('Away');
        expect(doseStatusMeta('missed').label).toBe('Missed (recorded)');
    });

    it('keeps doses waiting for the check, or due while away, out of what is still to do', () => {
        const counts = roundCounts([
            cell('given'),
            cell('due'),
            cell('overdue'),
            cell('pending_check'),
            cell('away'),
        ]);
        expect(counts.due).toBe(2);
        expect(counts.recorded).toBe(1);
        // Not owed in the round: out of the total and the percent, counted on their own.
        expect([counts.total, counts.pct, counts.waiting, counts.away]).toEqual([
            3, 33, 1, 1,
        ]);
        expect(notOwedCaption(counts.waiting, counts.away)).toBe(
            '1 waiting for the order check · 1 away',
        );
        expect(notOwedCaption(0, 0)).toBeNull();
        expect(isRecordedStatus('away')).toBe(false);
        expect(isRecordedStatus('missed')).toBe(true);
    });

    it('is 100% recorded when only doses waiting for the check or away are left', () => {
        const counts = roundCounts([
            cell('given'),
            cell('refused'),
            cell('pending_check'),
            cell('away'),
        ]);
        expect([counts.total, counts.recorded, counts.pct, counts.due]).toEqual(
            [2, 2, 100, 0],
        );
        expect(roundCounts([cell('away')]).pct).toBe(100);
    });

    it('steps the guided round only through doses that can be recorded', () => {
        expect(isRecordable(item('due'))).toBe(true);
        expect(isRecordable(item('overdue'))).toBe(true);
        expect(isRecordable(item('pending_check'))).toBe(false);
        expect(isWaitingForCheck(item('pending_check'))).toBe(true);
        expect(isRecordable(item('away'))).toBe(false);
        expect(isAway(item('away'))).toBe(true);
        expect(isRecordable(item('given', true))).toBe(false);
    });
});
