import { describe, expect, it } from 'vitest';
import { nzFollowupInstants } from './time';

describe('explicit NZ follow-up times', () => {
    it('requires a choice and does not invent a default', () => {
        expect(nzFollowupInstants('')).toEqual([]);
        expect(nzFollowupInstants('2026-02-30T10:00')).toEqual([]);
    });
    it('rejects the spring-forward gap', () => {
        expect(nzFollowupInstants('2026-09-27T02:30')).toEqual([]);
    });
    it('preserves both occurrences of the repeated hour', () => {
        expect(
            nzFollowupInstants('2026-04-05T02:30').map(
                (choice) => choice.value,
            ),
        ).toEqual(['2026-04-05T02:30:00+13:00', '2026-04-05T02:30:00+12:00']);
    });
    it('uses NZ time independently of the browser timezone', () => {
        expect(
            nzFollowupInstants('2026-10-03T10:15').map(
                (choice) => choice.value,
            ),
        ).toEqual(['2026-10-03T10:15:00+13:00']);
    });
});
