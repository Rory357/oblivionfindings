import { describe, expect, it } from 'vitest';
import { calendarDate, calendarLocal } from './calendar-time';

describe('Fleet calendar Auckland wall time', () => {
    it('keeps Auckland hours when the browser uses another timezone', () => {
        expect(calendarLocal(calendarDate('2026-09-27T20:00:00Z'))).toBe(
            '2026-09-28T09:00',
        );
        expect(calendarLocal(calendarDate('2026-04-05T21:15:00Z'))).toBe(
            '2026-04-06T09:15',
        );
    });
    it('preserves wall times on both sides of the repeated autumn hour', () => {
        expect(calendarLocal(calendarDate('2026-04-05T02:30:00+13:00'))).toBe(
            '2026-04-05T02:30',
        );
        expect(calendarLocal(calendarDate('2026-04-05T02:30:00+12:00'))).toBe(
            '2026-04-05T02:30',
        );
    });
});
