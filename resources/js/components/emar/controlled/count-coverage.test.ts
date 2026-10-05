import { describe, expect, it } from 'vitest';
import {
    coverageError,
    coverageInstant,
    emptyCountCoverage,
} from './count-coverage';
describe('paper count coverage in NZ time', () => {
    it('requires a deliberate choice for the repeated clock hour and rejects the skipped hour', () => {
        expect(coverageInstant('2026-04-05T02:30', '')).toBe('');
        expect(coverageInstant('2026-04-05T02:30', '+13:00')).toBe(
            '2026-04-05T02:30:00+13:00',
        );
        expect(coverageInstant('2026-04-05T02:30', '+12:00')).toBe(
            '2026-04-05T02:30:00+12:00',
        );
        expect(coverageInstant('2026-09-27T02:30', '+12:00')).toBe('');
    });
    it('permits an ordinary count but refuses incomplete or reversed paper coverage', () => {
        expect(coverageError(emptyCountCoverage())).toBeNull();
        expect(
            coverageError({ ...emptyCountCoverage(), entries: [4] }),
        ).not.toBeNull();
        expect(
            coverageError({
                ...emptyCountCoverage(),
                entries: [4],
                start: '2026-10-05T10:00',
                end: '2026-10-05T09:00',
            }),
        ).toMatch(/follow/);
        expect(
            coverageError({
                ...emptyCountCoverage(),
                entries: [4],
                start: '2026-10-05T09:00',
                end: '2026-10-05T10:00',
            }),
        ).toBeNull();
    });
});
