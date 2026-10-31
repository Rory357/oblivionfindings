import { afterEach, expect, it, vi } from 'vitest';
import { procedureReviewFlag } from './procedure-kit';
afterEach(() => vi.useRealTimers());
it('uses the NZ calendar day rather than UTC for due today', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-07T11:00:00Z'));
    expect(procedureReviewFlag('2026-10-08')).toEqual({
        label: 'Review due today',
        variant: 'warning',
    });
    expect(procedureReviewFlag('2026-10-07')?.label).toBe(
        'Review overdue · 1d',
    );
});
it('keeps null and invalid dates unknown and calendar arithmetic stable through DST', () => {
    expect(procedureReviewFlag(null, '2026-09-27')).toBeNull();
    expect(procedureReviewFlag('2026-02-30', '2026-09-27')).toBeNull();
    expect(procedureReviewFlag('2026-09-28', '2026-09-27')?.label).toBe(
        'Review due in 1d',
    );
});
