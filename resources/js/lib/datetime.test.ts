import { describe, expect, it } from 'vitest';

import {
    formatDateForFilename,
    formatDateOnly,
    formatDateTimeInZone,
    formatMonthYear,
    toDateInput,
    toDatetimeLocal,
} from './datetime';

describe('NZ date input and filename boundaries', () => {
    it('formats a date-only value without shifting it through a timezone', () => {
        expect(formatDateOnly('2026-07-21', 'Pacific/Auckland')).toBe(
            '21 Jul 2026',
        );
        expect(formatDateOnly('2026-07-21', 'America/Los_Angeles')).toBe(
            '21 Jul 2026',
        );
        expect(formatDateOnly('2026-02-31')).toBe('—');
    });

    it('uses the Auckland calendar date for a morning instant that is still the prior UTC day', () => {
        const aucklandMorning = new Date('2026-07-12T20:15:00.000Z');

        expect(toDateInput(aucklandMorning)).toBe('2026-07-13');
        expect(formatDateForFilename(aucklandMorning)).toBe('2026-07-13');
        expect(formatMonthYear(aucklandMorning)).toBe('July 2026');
    });

    it('stays on the Auckland date across the daylight-saving spring transition', () => {
        const afterSpringForward = new Date('2026-09-26T14:30:00.000Z');

        expect(toDateInput(afterSpringForward)).toBe('2026-09-27');
        expect(toDatetimeLocal(afterSpringForward)).toBe('2026-09-27T03:30');
    });

    it('returns empty values for invalid form inputs and stable filename fallback', () => {
        expect(toDateInput('not-a-date')).toBe('');
        expect(formatDateForFilename('not-a-date')).toBe('unknown-date');
        expect(formatMonthYear(null)).toBe('—');
    });
});

describe('explicit pattern timezones', () => {
    it('keeps overnight endpoints and their dates in the declared zone', () => {
        expect(
            formatDateTimeInZone('2026-10-12T09:00:00Z', 'Pacific/Auckland'),
        ).toBe('12 Oct 2026, 10:00 pm');
        expect(
            formatDateTimeInZone('2026-10-12T17:00:00Z', 'Pacific/Auckland'),
        ).toBe('13 Oct 2026, 6:00 am');
        expect(formatDateTimeInZone('2026-10-12T09:00:00Z', 'UTC')).toBe(
            '12 Oct 2026, 9:00 am',
        );
        expect(formatDateTimeInZone(null, 'UTC')).toBe('—');
    });
});
