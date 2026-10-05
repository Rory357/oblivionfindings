import { describe, expect, it } from 'vitest';
import { openHoursInPeriod } from './period-hours';
import type { OpenSession } from './shared';
const session: OpenSession = {
    id: 1,
    clock_in_at: '2026-10-04T10:00:00Z',
    shift_id: null,
    shift_starts_at: null,
    shift_ends_at: null,
    shift_location: null,
    client_name: null,
    client_id: null,
    timesheet_id: null,
    on_break: false,
    break_started_at: null,
    break_minutes: 0,
    breaks: [],
};
const start = '2026-10-04T11:00:00Z',
    end = '2026-10-05T11:00:00Z';
const now = new Date('2026-10-04T13:00:00Z');
describe('attendance open time displayed for a selected period', () => {
    it('clips overnight time at the NZ day boundary and excludes a spanning break', () => {
        expect(
            openHoursInPeriod(
                {
                    ...session,
                    break_minutes: 60,
                    breaks: [
                        {
                            id: 1,
                            started_at: '2026-10-04T10:30:00Z',
                            ended_at: '2026-10-04T11:30:00Z',
                            minutes: 60,
                        },
                    ],
                },
                start,
                end,
                now,
            ),
        ).toBe(1.5);
    });
    it('does not add the current open shift to a previous week', () => {
        expect(
            openHoursInPeriod(
                session,
                '2026-09-27T11:00:00Z',
                '2026-10-04T00:00:00Z',
                now,
            ),
        ).toBe(0);
    });
    it('deduplicates an active break represented in both event and current status', () => {
        expect(
            openHoursInPeriod(
                {
                    ...session,
                    on_break: true,
                    break_started_at: '2026-10-04T12:00:00Z',
                    breaks: [
                        {
                            id: 2,
                            started_at: '2026-10-04T12:00:00Z',
                            ended_at: null,
                            minutes: null,
                        },
                    ],
                },
                start,
                end,
                now,
            ),
        ).toBe(1);
    });
    it('does not guess the date of aggregate-only legacy breaks', () => {
        expect(
            openHoursInPeriod(
                { ...session, break_minutes: 30 },
                start,
                end,
                now,
            ),
        ).toBeNull();
        expect(
            openHoursInPeriod(
                { ...session, break_minutes: 30 },
                '2026-10-04T00:00:00Z',
                end,
                now,
            ),
        ).toBe(2.5);
    });
    it('uses exact supplied DST bounds and rejects unknown periods', () => {
        expect(
            openHoursInPeriod(
                { ...session, clock_in_at: '2026-09-26T12:00:00Z' },
                '2026-09-26T12:00:00Z',
                '2026-09-27T11:00:00Z',
                new Date('2026-09-27T12:00:00Z'),
            ),
        ).toBe(23);
        expect(openHoursInPeriod(session, undefined, end, now)).toBeNull();
        expect(openHoursInPeriod(null, start, end, now)).toBe(0);
    });
});
