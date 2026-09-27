import { describe, expect, it } from 'vitest';
import {
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
                new Date('2026-10-01T23:30:00'),
                new Date('2026-10-02T01:15:00'),
                new Date('2026-10-05T00:00:00'),
            ),
        ).toEqual({ start: '2026-10-05T23:30', end: '2026-10-06T01:15' });
    });
    it('uses the exact proposed time and duration from week/day resizing', () => {
        expect(
            proposedCalendarWindow(
                new Date('2026-10-01T09:00:00'),
                new Date('2026-10-01T10:00:00'),
                new Date('2026-10-01T09:15:00'),
                new Date('2026-10-01T10:45:00'),
            ),
        ).toEqual({ start: '2026-10-01T09:15', end: '2026-10-01T10:45' });
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
