import { describe, expect, it } from 'vitest';
import {
    calendarDate,
    calendarLocal,
    calendarMoveChoices,
} from './calendar-time';

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

describe.each(['UTC', 'Pacific/Auckland'])(
    'Fleet moves with browser timezone %s',
    (zone) => {
        const move = (start: string, end: string, target: string) => {
            const before = process.env.TZ;
            process.env.TZ = zone;
            try {
                return calendarMoveChoices(start, end, target);
            } finally {
                if (before === undefined) delete process.env.TZ;
                else process.env.TZ = before;
            }
        };
        it.each([
            [
                '2027-04-04T01:30:00+13:00',
                '2027-04-04T03:30:00+12:00',
                '2027-04-05T01:30',
                '2027-04-05T04:30',
                '+12:00',
            ],
            [
                '2026-09-27T01:30:00+12:00',
                '2026-09-27T03:30:00+13:00',
                '2026-09-28T01:30',
                '2026-09-28T02:30',
                '+13:00',
            ],
            [
                '2027-04-03T01:30:00+13:00',
                '2027-04-03T03:30:00+13:00',
                '2027-04-04T01:30',
                '2027-04-04T02:30',
                '+12:00',
            ],
            [
                '2026-09-26T01:30:00+12:00',
                '2026-09-26T03:30:00+12:00',
                '2026-09-27T01:30',
                '2026-09-27T04:30',
                '+13:00',
            ],
        ])(
            'preserves elapsed time from %s when moved to %s',
            (start, end, target, expectedEnd, offset) => {
                const [proposal] = move(start, end, target);
                expect(proposal.end).toBe(expectedEnd);
                expect(proposal.endOffset).toBe(offset);
                expect(
                    Date.parse(`${proposal.end}:00${proposal.endOffset}`) -
                        Date.parse(
                            `${proposal.start}:00${proposal.startOffset}`,
                        ),
                ).toBe(Date.parse(end) - Date.parse(start));
            },
        );
        it('offers both repeated start occurrences with matching ends', () => {
            const choices = move(
                '2027-04-03T02:30:00+13:00',
                '2027-04-03T04:30:00+13:00',
                '2027-04-04T02:30',
            );
            expect(choices).toEqual([
                {
                    start: '2027-04-04T02:30',
                    end: '2027-04-04T03:30',
                    startOffset: '+13:00',
                    endOffset: '+12:00',
                },
                {
                    start: '2027-04-04T02:30',
                    end: '2027-04-04T04:30',
                    startOffset: '+12:00',
                    endOffset: '+12:00',
                },
            ]);
        });
        it('does not normalise skipped spring times into a different hour', () => {
            expect(
                move(
                    '2026-09-26T02:30:00+12:00',
                    '2026-09-26T03:30:00+12:00',
                    '2026-09-27T02:30',
                ),
            ).toEqual([]);
        });
    },
);
