import { describe, expect, it } from 'vitest';

import { approvalQueueGridColumns, formatHours } from './approvals';

describe('timesheet approval presentation helpers', () => {
    it('uses total_hours as the canonical approval queue duration', () => {
        expect(
            formatHours({
                id: 1,
                status: 'submitted',
                total_hours: 7.5,
                duration_minutes: null,
                hours: null,
            }),
        ).toBe('7.50 hrs');
    });

    it('falls back to legacy hours and duration_minutes when total_hours is absent', () => {
        expect(
            formatHours({
                id: 2,
                status: 'submitted',
                hours: 3.25,
            }),
        ).toBe('3.25 hrs');

        expect(
            formatHours({
                id: 3,
                status: 'submitted',
                duration_minutes: 90,
            }),
        ).toBe('1.50 hrs');
    });

    it('uses Tailwind arbitrary grid syntax that produces real desktop columns', () => {
        expect(approvalQueueGridColumns).toBe(
            'md:grid-cols-[40px_1.3fr_1fr_1fr_0.9fr_120px]',
        );
        expect(approvalQueueGridColumns).not.toContain(',');
    });
});
