import { describe, expect, it } from 'vitest';

import {
    formatDateForFilename,
    formatMonthYear,
    toDateInput,
    toDatetimeLocal,
} from './datetime';

describe('NZ date input and filename boundaries', () => {
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
