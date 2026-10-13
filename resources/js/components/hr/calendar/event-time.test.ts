import { describe, expect, it } from 'vitest';
import { eventTimeInput, eventTimeInstant } from './event-time';
describe('calendar editor times', () => {
    it('round-trips NZ summer and winter inputs through UTC without shifting the displayed time', () => {
        expect(eventTimeInstant('2026-10-13T05:00', false)).toBe(
            '2026-10-12T16:00:00.000Z',
        );
        expect(eventTimeInput('2026-10-12T16:00:00Z', false)).toBe(
            '2026-10-13T05:00',
        );
        expect(eventTimeInstant('2026-07-13T05:00', false)).toBe(
            '2026-07-12T17:00:00.000Z',
        );
        expect(eventTimeInput('2026-10-13T05:00', false)).toBe(
            '2026-10-13T05:00',
        );
    });
    it('preserves date-only events without converting them into instants', () => {
        expect(eventTimeInstant('2026-10-13', true)).toBe('2026-10-13');
        expect(eventTimeInput('2026-10-13T00:00:00Z', true)).toBe('2026-10-13');
    });
    it('rejects skipped or ambiguous new times and preserves an existing repeated-hour instant', () => {
        expect(() => eventTimeInstant('2026-09-27T02:30', false)).toThrow(
            'does not exist',
        );
        expect(() => eventTimeInstant('2026-04-05T02:30', false)).toThrow(
            'occurs twice',
        );
        expect(
            eventTimeInstant('2026-04-05T02:30', false, '2026-04-04T14:30:00Z'),
        ).toBe('2026-04-04T14:30:00.000Z');
    });
});
