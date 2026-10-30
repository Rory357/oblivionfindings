import { describe, expect, it } from 'vitest';
import { restartTimeUtc } from './restart-schedule';
describe('New Zealand restart scheduling', () => {
    it('converts the selected local time independently of browser timezone', () => {
        expect(restartTimeUtc('2026-10-08T16:00')).toBe('2026-10-08T03:00:00Z');
        expect(restartTimeUtc('2026-07-08T16:00:30')).toBe(
            '2026-07-08T04:00:30Z',
        );
    });
    it('rejects impossible or ambiguous local dates rather than shifting the restart', () => {
        for (const value of [
            '',
            '2026-02-30T12:00',
            '2026-04-05T02:30',
            '2026-09-27T02:30',
            '2026-10-08T24:00',
            '2026-10-08T16:00Z',
        ])
            expect(restartTimeUtc(value)).toBeNull();
    });
});
