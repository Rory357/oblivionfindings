import { describe, expect, it } from 'vitest';
import {
    moveRosterShift,
    rosterDayKeys,
    rosterInstant,
    rosterWallTime,
} from './roster-time';

describe('roster duty dates', () => {
    it('uses the worker date even when UTC is still Sunday', () => {
        expect(rosterWallTime('2026-10-04T20:00:00Z')).toBe(
            '2026-10-05T09:00:00',
        );
    });
    it('preserves local start time and elapsed duty duration when moved across daylight saving', () => {
        expect(
            moveRosterShift(
                '2026-09-25T21:00:00Z',
                '2026-09-26T05:00:00Z',
                '2026-09-28',
            ),
        ).toEqual({
            starts_at: '2026-09-27T20:00:00.000Z',
            ends_at: '2026-09-28T04:00:00.000Z',
        });
    });
    it('refuses a nonexistent spring-forward time', () => {
        expect(() => rosterInstant('2026-09-27T02:30:00')).toThrow(
            'daylight-saving',
        );
    });
    it('refuses an ambiguous fall-back time', () => {
        expect(() => rosterInstant('2026-04-05T02:30:00')).toThrow(
            'daylight-saving',
        );
    });
    it('includes overnight continuation but excludes a midnight end boundary', () => {
        expect(
            rosterDayKeys('2026-10-05T09:00:00Z', '2026-10-05T18:00:00Z'),
        ).toEqual(['2026-10-05', '2026-10-06']);
        expect(
            rosterDayKeys('2026-10-05T09:00:00Z', '2026-10-05T11:00:00Z'),
        ).toEqual(['2026-10-05']);
    });
});
