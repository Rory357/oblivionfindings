import { describe, expect, it } from 'vitest';

import { doseTiming } from './emar-dose-window';

const scheduled = '2026-09-29T08:00:00+13:00';
const serverDefault = { early_minutes: 30, late_minutes: 60 };

function minutesFromScheduled(offsetMinutes: number): string {
    return new Date(
        Date.parse(scheduled) + offsetMinutes * 60000,
    ).toISOString();
}

describe('doseTiming', () => {
    it.each([
        [-45, 'early'],
        [-31, 'early'],
        [-30, 'on_time'],
        [0, 'on_time'],
        [45, 'on_time'],
        [60, 'on_time'],
        [61, 'late'],
    ] as const)(
        'places a dose given %i minutes from the scheduled time as %s under the server default window',
        (offset, expected) => {
            expect(
                doseTiming(
                    scheduled,
                    minutesFromScheduled(offset),
                    serverDefault,
                ),
            ).toBe(expected);
        },
    );

    it('follows an org-configured window rather than the default', () => {
        const window = { early_minutes: 90, late_minutes: 15 };

        expect(doseTiming(scheduled, minutesFromScheduled(-45), window)).toBe(
            'on_time',
        );
        expect(doseTiming(scheduled, minutesFromScheduled(45), window)).toBe(
            'late',
        );
    });

    it('returns null when the timing cannot be worked out', () => {
        expect(doseTiming(null, minutesFromScheduled(0), serverDefault)).toBe(
            null,
        );
        expect(doseTiming(scheduled, '', serverDefault)).toBe(null);
        expect(doseTiming(scheduled, 'not-a-date', serverDefault)).toBe(null);
        expect(doseTiming(scheduled, minutesFromScheduled(-45), null)).toBe(
            null,
        );
    });
});
