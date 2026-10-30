import { describe, expect, it } from 'vitest';
import {
    attendanceDurationError,
    attendanceEnd,
    attendanceSeed,
    attendanceTimeOptions,
    clockOutBreaks,
    correctionBreaks,
} from './attendance-time';

describe('attendance dates and actual break duration', () => {
    it('uses the configured clock for an overnight date, independent of the device', () => {
        expect(
            attendanceSeed('2026-10-05T11:20:00Z', 'Pacific/Auckland'),
        ).toEqual({
            date: '2026-10-06',
            time: '00:20',
            original: '2026-10-05T11:20:00Z',
        });
        expect(
            attendanceEnd('2026-10-06T00:20', 'Pacific/Auckland').instant,
        ).toBe('2026-10-05T11:20:00.000Z');
        expect(
            attendanceEnd('2026-10-06T00:20', 'Australia/Perth').instant,
        ).toBe('2026-10-05T16:20:00.000Z');
    });
    it('rejects missing, malformed and nonexistent calendar values', () => {
        for (const wall of [
            '',
            '2026-02-30T09:00',
            '2026-10-06T25:00',
            '2026-10-06T',
            '2026-09-27T02:30',
        ])
            expect(attendanceEnd(wall).instant).toBeNull();
    });
    it('requires an explicit choice for newly entered repeated times', () => {
        const choices = attendanceTimeOptions('2026-04-05T02:30');
        expect(choices).toEqual([
            '2026-04-04T13:30:00.000Z',
            '2026-04-04T14:30:00.000Z',
        ]);
        expect(attendanceEnd('2026-04-05T02:30').error).toMatch(
            /first or second/,
        );
        expect(
            attendanceEnd('2026-04-05T02:30', undefined, null, choices[1])
                .instant,
        ).toBe(choices[1]);
        expect(
            attendanceEnd(
                '2026-04-05T02:30',
                undefined,
                null,
                '2026-04-04T15:30:00.000Z',
            ).instant,
        ).toBeNull();
    });
    it('preserves the recorded repeated-time occurrence and seconds until edited', () => {
        const original = '2026-04-05T02:30:47+12:00';
        expect(
            attendanceEnd('2026-04-05T02:30', undefined, original).instant,
        ).toBe('2026-04-04T14:30:47.000Z');
        expect(
            attendanceEnd('2026-04-05T02:31', undefined, original).instant,
        ).toBeNull();
    });
    it('validates the actual end against start and future tolerance without inventing a one-day limit', () => {
        const now = Date.parse('2026-10-06T11:00:00Z');
        const start = '2026-10-04T11:00:00Z';
        expect(attendanceDurationError(start, start, now)).toMatch(
            /after clock-in/,
        );
        expect(
            attendanceDurationError(start, '2026-10-06T11:02:00Z', now),
        ).toBe('');
        expect(
            attendanceDurationError(start, '2026-10-06T11:02:01Z', now),
        ).toMatch(/future/);
    });
    it('explains a sub-minute end without blaming a zero-minute break', () => {
        expect(
            attendanceDurationError(
                '2026-10-05T20:00:30Z',
                '2026-10-05T20:01:00Z',
                Date.parse('2026-10-05T20:02:00Z'),
            ),
        ).toMatch(/at least one minute/);
    });
    it('uses the saved aggregate and entered end for a running break', () => {
        const session = {
            clock_in_at: '2026-10-05T20:00:00Z',
            break_minutes: 25,
            break_started_at: '2026-10-06T00:00:20Z',
        };
        expect(clockOutBreaks(session, '2026-10-06T00:31:19Z', '5')).toEqual({
            tracked: 55,
            total: 60,
            error: '',
            payload: 60,
        });
    });
    it('omits a zero-extra total so long tracked breaks remain server-derived', () => {
        const session = {
            clock_in_at: '2026-10-05T08:00:00Z',
            break_minutes: 250,
            break_started_at: null,
        };
        expect(clockOutBreaks(session, '2026-10-06T00:00:00Z', '0')).toEqual({
            tracked: 250,
            total: 250,
            error: '',
            payload: undefined,
        });
        expect(
            clockOutBreaks(session, '2026-10-06T00:00:00Z', '1').error,
        ).toMatch(/240-minute/);
    });
    it.each(['', '-1', '1.5', 'abc', '241'])(
        'rejects invalid break entry %s',
        (value) => {
            const session = {
                clock_in_at: '2026-10-05T20:00:00Z',
                break_minutes: 0,
                break_started_at: null,
            };
            expect(
                clockOutBreaks(session, '2026-10-06T00:00:00Z', value).error,
            ).not.toBe('');
            expect(
                correctionBreaks(
                    session.clock_in_at,
                    '2026-10-06T00:00:00Z',
                    value,
                ).error,
            ).not.toBe('');
        },
    );
    it('requires a positive worked whole-minute duration and treats correction as replacement', () => {
        expect(
            correctionBreaks(
                '2026-10-05T20:00:30Z',
                '2026-10-05T20:30:29Z',
                '29',
            ).error,
        ).toMatch(/shorter/);
        expect(
            correctionBreaks(
                '2026-10-05T20:00:00Z',
                '2026-10-06T00:00:00Z',
                '10',
            ),
        ).toEqual({ total: 10, error: '' });
    });
});
