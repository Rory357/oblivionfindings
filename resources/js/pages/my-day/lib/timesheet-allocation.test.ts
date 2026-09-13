import { describe, expect, it } from 'vitest';

import {
    allocationErrorForRow,
    allocationTimeChoices,
    isAllocationBalanced,
    splitHoursEvenly,
} from './timesheet-allocation';

describe('my-day timesheet allocation helpers', () => {
    it('rejects a missing hundredth instead of silently losing paid time', () => {
        expect(isAllocationBalanced('manual', 7.99, 8)).toBe(false);
    });

    it('resolves overnight NZ dates independently of the desktop timezone', () => {
        expect(allocationTimeChoices('2026-09-13T00:30')).toEqual([
            '2026-09-12T12:30:00.000Z',
        ]);
    });

    it('exposes repeated daylight-saving times and rejects nonexistent times', () => {
        expect(allocationTimeChoices('2026-04-05T02:30')).toEqual([
            '2026-04-04T13:30:00.000Z',
            '2026-04-04T14:30:00.000Z',
        ]);
        expect(allocationTimeChoices('2026-09-27T02:30')).toEqual([]);
    });
    it('splits hours at two decimals without losing the remainder', () => {
        const split = splitHoursEvenly(10, 3);

        expect(split).toEqual(['3.33', '3.33', '3.34']);
        expect(
            split.reduce((sum, value) => sum + Number.parseFloat(value), 0),
        ).toBeCloseTo(10, 2);
    });

    it('requires residential house allocations to balance like other methods', () => {
        expect(isAllocationBalanced('residential_house', 6, 8)).toBe(false);
        expect(isAllocationBalanced('residential_house', 8, 8)).toBe(true);
    });

    it('maps backend allocation errors by array index before legacy client id keys', () => {
        const errors = {
            'client_allocations.0.hours': 'The segment duration is 1.00h.',
            'client_allocations.42.hours': 'Legacy client keyed error.',
        };

        expect(allocationErrorForRow(errors, 0, 42, 'hours')).toBe(
            'The segment duration is 1.00h.',
        );
    });
});
