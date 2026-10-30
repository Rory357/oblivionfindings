import { describe, expect, it } from 'vitest';
import { actualEventInstant } from './actual-event-time-field';

describe('actual event New Zealand time', () => {
    it('records an explicit offset independently of the browser timezone', () => {
        expect(actualEventInstant('2026-10-06T10:15', '')).toBe(
            '2026-10-06T10:15:00+13:00',
        );
        expect(actualEventInstant('2026-07-06T10:15', '')).toBe(
            '2026-07-06T10:15:00+12:00',
        );
    });
    it('requires an explicit choice for a repeated clock reading', () => {
        expect(actualEventInstant('2026-04-05T02:30', '')).toBe('');
        expect(actualEventInstant('2026-04-05T02:30', '+13:00')).toBe(
            '2026-04-05T02:30:00+13:00',
        );
        expect(actualEventInstant('2026-04-05T02:30', '+12:00')).toBe(
            '2026-04-05T02:30:00+12:00',
        );
        expect(actualEventInstant('2026-04-05T02:30', '+11:00')).toBe('');
    });
    it('rejects skipped and invalid dates without inventing an instant', () => {
        for (const value of [
            '',
            '2026-09-27T02:30',
            '2026-02-30T10:15',
            '2026-10-06T25:10',
        ])
            expect(actualEventInstant(value, '')).toBe('');
    });
});
