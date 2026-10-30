import { describe, expect, it } from 'vitest';
import {
    aucklandTimeChoices,
    canRescheduleTransport,
    proposedCalendarWindow,
} from './calendar-actions';
import type { TransportRecord } from './types';

const planned = {
    stage: 'decision',
    can: { manage: true },
    journey: null,
    booking: { status: 'pending', start: '2026-10-01T01:30:00Z' },
} as TransportRecord;

describe('Transport calendar proposals', () => {
    it('preserves departure time and overnight duration when dropped on a month date', () => {
        expect(
            proposedCalendarWindow(
                '2026-10-01T23:30:00+13:00',
                '2026-10-02T01:15:00+13:00',
                new Date('2026-10-05T00:00:00'),
            ),
        ).toMatchObject({ start: '2026-10-05T23:30', end: '2026-10-06T01:15' });
    });
    it('uses the exact proposed time and duration from week/day resizing', () => {
        expect(
            proposedCalendarWindow(
                '2026-10-01T09:00:00+13:00',
                '2026-10-01T10:00:00+13:00',
                new Date('2026-10-01T09:15:00'),
                new Date('2026-10-01T10:45:00'),
            ),
        ).toMatchObject({ start: '2026-10-01T09:15', end: '2026-10-01T10:45' });
    });
    it.each(['UTC', 'Pacific/Auckland', 'America/Los_Angeles'])(
        'retains real duration across Auckland DST in a %s browser',
        (timezone) => {
            const previous = process.env.TZ;
            process.env.TZ = timezone;
            try {
                const start = '2027-04-04T01:30:00+13:00',
                    end = '2027-04-04T03:30:00+12:00';
                expect(
                    proposedCalendarWindow(
                        start,
                        end,
                        new Date('2027-04-05T00:00:00'),
                    ),
                ).toEqual({
                    start: '2027-04-05T01:30',
                    end: '2027-04-05T04:30',
                    startOffset: '+12:00',
                    endOffset: '+12:00',
                });
                expect(
                    proposedCalendarWindow(
                        start,
                        end,
                        new Date('2027-04-04T03:30:00'),
                        new Date('2027-04-04T05:30:00'),
                        'move',
                    ),
                ).toMatchObject({
                    start: '2027-04-04T03:30',
                    end: '2027-04-04T06:30',
                });
                expect(
                    proposedCalendarWindow(
                        '2026-09-27T01:30:00+12:00',
                        '2026-09-27T03:30:00+13:00',
                        new Date('2026-09-28T00:00:00'),
                    ),
                ).toMatchObject({
                    start: '2026-09-28T01:30',
                    end: '2026-09-28T02:30',
                });
                // A normal three-hour trip moved onto the clock-change date still lasts three actual hours.
                expect(
                    proposedCalendarWindow(
                        '2027-04-05T01:30:00+12:00',
                        '2027-04-05T04:30:00+12:00',
                        new Date('2027-04-04T00:00:00'),
                    ),
                ).toEqual({
                    start: '2027-04-04T01:30',
                    end: '2027-04-04T03:30',
                    startOffset: '+13:00',
                    endOffset: '+12:00',
                });
            } finally {
                if (previous === undefined) delete process.env.TZ;
                else process.env.TZ = previous;
            }
        },
    );
    it('requires an explicit offset for repeated hours and rejects nonexistent wall times', () => {
        expect(
            aucklandTimeChoices('2027-04-04T02:30').map(
                (choice) => choice.offset,
            ),
        ).toEqual(['+13:00', '+12:00']);
        expect(aucklandTimeChoices('2026-09-27T02:30')).toEqual([]);
        expect(() =>
            proposedCalendarWindow(
                '2027-04-05T02:30:00+12:00',
                '2027-04-05T03:30:00+12:00',
                new Date('2027-04-04T00:00:00'),
            ),
        ).toThrow(/occurs twice/);
        expect(() =>
            proposedCalendarWindow(
                '2026-09-28T02:30:00+13:00',
                '2026-09-28T03:30:00+13:00',
                new Date('2026-09-27T00:00:00'),
            ),
        ).toThrow(/gap/);
    });
    it('offers dragging only for permitted future pending or approved plans', () => {
        const now = new Date('2026-09-30T00:00:00Z').getTime();
        expect(canRescheduleTransport(planned, now)).toBe(true);
        expect(
            canRescheduleTransport(
                {
                    ...planned,
                    booking: { ...planned.booking!, status: 'approved' },
                },
                now,
            ),
        ).toBe(true);
        for (const row of [
            { ...planned, can: { ...planned.can, manage: false } },
            { ...planned, booking: null },
            { ...planned, stage: 'cancelled' },
            {
                ...planned,
                journey: {
                    status: 'in_progress',
                } as TransportRecord['journey'],
            },
            ...['checked_out', 'returned', 'rejected', 'cancelled'].map(
                (status) => ({
                    ...planned,
                    booking: { ...planned.booking!, status },
                }),
            ),
        ])
            expect(canRescheduleTransport(row, now)).toBe(false);
        expect(
            canRescheduleTransport(
                planned,
                new Date(planned.booking!.start).getTime(),
            ),
        ).toBe(false);
    });
});
