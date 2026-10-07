import { describe, expect, it } from 'vitest';
import { timesheetTimeRange } from './timesheet-time';

describe('timesheet worker time transport', () => {
    it('converts NZ midnight-boundary dates and exact minutes to UTC without moving the work date', () => {
        expect(
            timesheetTimeRange('2026-10-05T09:17', '2026-10-05T17:47'),
        ).toEqual({
            starts_at: '2026-10-04T20:17:00.000Z',
            ends_at: '2026-10-05T04:47:00.000Z',
            minutes: 510,
        });
    });
    it('requires an explicit later end date for overnight hours', () => {
        expect(() =>
            timesheetTimeRange('2026-10-05T23:17', '2026-10-05T07:17'),
        ).toThrow('following end date');
        expect(() =>
            timesheetTimeRange('2026-10-05T09:00', '2026-10-05T09:00'),
        ).toThrow('after start');
        expect(
            timesheetTimeRange('2026-10-05T23:17', '2026-10-06T07:17').minutes,
        ).toBe(480);
    });
    it('uses elapsed time across daylight saving and rejects nonexistent or ambiguous new clocks', () => {
        expect(
            timesheetTimeRange('2026-09-26T23:00', '2026-09-27T04:00').minutes,
        ).toBe(240);
        expect(() =>
            timesheetTimeRange('2026-09-27T02:17', '2026-09-27T04:00'),
        ).toThrow('daylight-saving');
        expect(() =>
            timesheetTimeRange('2026-04-05T02:17', '2026-04-05T04:00'),
        ).toThrow('daylight-saving');
    });
    it('preserves original precise instants and honors the configured worker timezone', () => {
        expect(
            timesheetTimeRange('2026-10-05T09:17', '2026-10-05T17:47', {
                starts_at: '2026-10-04T20:17:42+00:00',
                ends_at: '2026-10-05T04:47:42+00:00',
            }).starts_at,
        ).toBe('2026-10-04T20:17:42.000Z');
        expect(
            timesheetTimeRange(
                '2026-10-05T09:17',
                '2026-10-05T17:47',
                undefined,
                'UTC',
            ).starts_at,
        ).toBe('2026-10-05T09:17:00.000Z');
    });
});
