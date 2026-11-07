import { toDatetimeLocal } from '@/lib/datetime';
import { describe, expect, it } from 'vitest';
import { fmtDate, fmtRange } from './shared';

// Respite times are stored as UTC. A Mon 5 Oct 2026 10:00 NZDT arrival is
// 2026-10-04T21:00Z, so reading the UTC day would show the 4th.
describe('respite date display round trip', () => {
    it('shows the NZ day of a booking stored as UTC', () => {
        expect(fmtDate('2026-10-04T21:00:00+00:00')).toBe('5 Oct');
        expect(
            fmtRange('2026-10-04T21:00:00+00:00', '2026-10-06T21:00:00+00:00'),
        ).toBe('5 Oct → 7 Oct');
    });

    it('shows the NZ day across both daylight-saving changes', () => {
        // Sun 5 Apr 2026 10:00 NZST (+12) and Sun 27 Sep 2026 10:00 NZDT (+13).
        expect(fmtDate('2026-04-04T22:00:00+00:00')).toBe('5 Apr');
        // en-NZ writes September as "Sept" in current ICU data, "Sep" in older.
        expect(fmtDate('2026-09-26T21:00:00+00:00')).toMatch(/^27 Sept?$/);
    });

    it('gives back the NZ wall-clock the coordinator typed', () => {
        expect(toDatetimeLocal('2026-10-04T21:00:00+00:00')).toBe(
            '2026-10-05T10:00',
        );
        expect(toDatetimeLocal('2026-04-04T22:00:00+00:00')).toBe(
            '2026-04-05T10:00',
        );
        expect(toDatetimeLocal('2026-09-26T21:00:00+00:00')).toBe(
            '2026-09-27T10:00',
        );
    });

    it('keeps the em dash for missing or invalid values', () => {
        expect(fmtDate(null)).toBe('—');
        expect(fmtDate('not-a-date')).toBe('—');
    });
});
