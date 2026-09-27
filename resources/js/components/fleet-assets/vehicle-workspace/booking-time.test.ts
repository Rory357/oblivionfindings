import { describe, expect, it } from 'vitest';
import { bookingOffset, bookingOffsets } from './booking-time';
describe('Auckland booking clock occurrences', () => {
    it('requires an explicit occurrence during the repeated autumn hour', () => {
        expect(bookingOffsets('2027-04-04T02:30')).toEqual([
            '+13:00',
            '+12:00',
        ]);
        expect(bookingOffset('2027-04-03T14:30:00Z')).toBe('+12:00');
    });
    it('rejects skipped spring wall times and resolves normal times', () => {
        expect(bookingOffsets('2026-09-27T02:30')).toEqual([]);
        expect(bookingOffsets('2026-09-28T09:00')).toEqual(['+13:00']);
        expect(bookingOffsets('2026-06-01T09:00')).toEqual(['+12:00']);
        expect(bookingOffsets('invalid')).toEqual([]);
    });
});
