import {
    afterAll,
    afterEach,
    beforeAll,
    describe,
    expect,
    it,
    vi,
} from 'vitest';
import { whenLabel } from './shared';

// Days are NZ calendar days whatever the browser's timezone. The browser runs
// in UTC here, where 7:30 am NZDT on Wed 21 Oct 2026 is still 20 Oct.
describe('restraint whenLabel', () => {
    const originalTz = process.env.TZ;

    beforeAll(() => {
        process.env.TZ = 'UTC';
    });

    afterAll(() => {
        if (originalTz === undefined) delete process.env.TZ;
        else process.env.TZ = originalTz;
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    function at(nowIso: string) {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(nowIso));
    }

    it('counts Today and Yesterday on the NZ calendar', () => {
        at('2026-10-20T18:30:00Z'); // 7:30 am NZDT, Wed 21 Oct

        expect(whenLabel('2026-10-20T12:00:00Z')).toBe('Today, 1:00 am');
        expect(whenLabel('2026-10-21T01:00:00Z')).toBe('Today, 2:00 pm');
        // 11 pm NZ on the 20th: the same UTC day as "now", but yesterday in NZ.
        expect(whenLabel('2026-10-20T10:00:00Z')).toBe('Yesterday, 11:00 pm');
    });

    it('counts whole NZ days, not elapsed 24-hour blocks', () => {
        at('2026-10-20T18:30:00Z');

        // 11:30 pm NZ on Sat 17 Oct: 80 hours ago, but four NZ days.
        expect(whenLabel('2026-10-17T10:30:00Z')).toBe('4 days ago, 11:30 pm');
        // A week back shows the date.
        expect(whenLabel('2026-10-13T23:00:00Z')).toBe('Wed 14 Oct, 12:00 pm');
    });

    it('keeps the em dash for missing or invalid values', () => {
        expect(whenLabel(null)).toBe('—');
        expect(whenLabel(undefined)).toBe('—');
        expect(whenLabel('not-a-date')).toBe('—');
    });
});
