import { describe, expect, it } from 'vitest';

import {
    addCalendarDays,
    ageOnWorkerDay,
    formatDateForFilename,
    formatDateOnly,
    formatDateTimeInZone,
    formatMonthYear,
    timeOffsetInZone,
    timeOffsetsInZone,
    toDateInput,
    toDatetimeLocal,
    workerDayRange,
    workerTimeOffsets,
} from './datetime';

describe('age on the NZ calendar day', () => {
    it('changes on the birthday, including before the UTC day changes', () => {
        expect(
            ageOnWorkerDay('1984-10-04', new Date('2026-10-03T10:59:59Z')),
        ).toBe(41);
        expect(
            ageOnWorkerDay('1984-10-04', new Date('2026-10-03T11:00:00Z')),
        ).toBe(42);
        expect(
            ageOnWorkerDay('1984-10-04', new Date('2026-10-04T07:15:00Z')),
        ).toBe(42);
    });

    it('counts completed years across a leap-day birthday', () => {
        expect(ageOnWorkerDay('2000-02-29', '2026-02-28T00:00:00Z')).toBe(25);
        expect(ageOnWorkerDay('2000-02-29', '2026-03-01T00:00:00Z')).toBe(26);
    });

    it('does not invent an age for missing, invalid or future dates', () => {
        for (const birthDate of [
            undefined,
            null,
            '',
            'not-a-date',
            '2026-02-31',
            '2026-10-05',
        ]) {
            expect(
                ageOnWorkerDay(birthDate, '2026-10-04T00:00:00Z'),
            ).toBeNull();
        }
        expect(ageOnWorkerDay('1984-10-04', 'not-a-date')).toBeNull();
    });
});

describe('NZ date input and filename boundaries', () => {
    it('selects inclusive New Zealand reporting days before UTC midnight and across DST', () => {
        expect(workerDayRange(7, '2026-09-27T11:30:00Z')).toEqual({
            from: '2026-09-22',
            to: '2026-09-28',
        });
        expect(workerDayRange(30, '2026-10-06T12:00:00Z')).toEqual({
            from: '2026-09-08',
            to: '2026-10-07',
        });
        expect(workerDayRange(1, '2026-04-04T14:30:00Z')).toEqual({
            from: '2026-04-05',
            to: '2026-04-05',
        });
        expect(workerDayRange(0)).toEqual({ from: '', to: '' });
        expect(workerDayRange(7, 'invalid')).toEqual({ from: '', to: '' });
    });

    it('walks calendar days from the Auckland date, across month ends and daylight saving', () => {
        const today = toDateInput(new Date('2026-09-26T20:00:00.000Z'));

        expect(today).toBe('2026-09-27');
        expect(addCalendarDays(today, 0)).toBe('2026-09-27');
        expect(addCalendarDays(today, 4)).toBe('2026-10-01');
        expect(addCalendarDays('2026-12-31', 1)).toBe('2027-01-01');
        expect(addCalendarDays('2028-03-01', -1)).toBe('2028-02-29');
        expect(addCalendarDays('not-a-date', 1)).toBe('');
    });

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

describe('selected-zone maintenance clocks', () => {
    it('keeps the NZ default and supports UTC and the Chatham quarter-hour offset', () => {
        expect(toDatetimeLocal('2026-09-26T21:00:00Z')).toBe(
            '2026-09-27T10:00',
        );
        expect(toDatetimeLocal('2026-09-26T21:00:00Z', 'UTC')).toBe(
            '2026-09-26T21:00',
        );
        expect(
            timeOffsetInZone('2026-09-26T21:00:00Z', 'Pacific/Chatham'),
        ).toBe('+13:45');
        expect(
            timeOffsetsInZone('2026-09-27T10:45', 'Pacific/Chatham'),
        ).toEqual(['+13:45']);
        expect(timeOffsetsInZone('2026-09-27T10:45', 'UTC')).toEqual([
            '+00:00',
        ]);
    });
    it('offers both NZ repeated instants and no fabricated missing clock', () => {
        expect(timeOffsetsInZone('2026-04-05T02:15')).toEqual([
            '+13:00',
            '+12:00',
        ]);
        expect(workerTimeOffsets('2026-04-05T02:15')).toEqual([
            '+13:00',
            '+12:00',
        ]);
        expect(timeOffsetsInZone('2026-09-27T02:15')).toEqual([]);
        expect(timeOffsetsInZone('2026-02-30T09:00')).toEqual([]);
        expect(timeOffsetsInZone('2026-09-27T09:00', 'Invalid/Zone')).toEqual(
            [],
        );
    });
});
