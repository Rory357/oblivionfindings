import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn(), visit: vi.fn(), reload: vi.fn(), replace: vi.fn() },
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({ props: { flash: {} }, url: '/governance/ceo-reports/1' }),
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        errors: {},
        processing: false,
        setData: vi.fn(),
        transform: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
    }),
}));

import {
    ceoReportChip,
    ceoReportDefaultsFromMeeting,
    formatWallTime,
} from './_dialogs';
import { keyFigures } from './Show';

describe('CEO report key figures', () => {
    it('never shows a green 0 when a source failed or had no data', () => {
        const figures = keyFigures({
            captured_at: '2026-09-10T02:00:00Z',
            top_risks: null,
            compliance_calendar: null,
            incidents: null,
            financial: { variance: null },
            workforce: null,
            safeguarding: null,
            decisions_required: null,
        });

        for (const figure of figures) {
            expect(figure.value).toBe('Not available');
            expect(figure.tone).toBe('neutral');
        }
    });

    it('reads real numbers plainly', () => {
        const figures = Object.fromEntries(
            keyFigures({
                top_risks: { critical: 0, above_appetite: 2 },
                compliance_calendar: [{ days_remaining: -3 }, { days_remaining: 10 }],
                incidents: { by_severity: { critical: 1 } },
                financial: { variance: 6.24 },
                workforce: { training_compliance: 96 },
                safeguarding: { open_concerns: 0 },
                decisions_required: { count: 3 },
            }).map((figure) => [figure.label, figure]),
        );

        expect(figures['Critical risks']).toMatchObject({ value: '0', tone: 'success' });
        expect(figures["Risks above the board's limit"]).toMatchObject({ value: '2', tone: 'warning' });
        expect(figures['Overdue requirements'].value).toBe('1');
        expect(figures['Budget position'].value).toBe('Over budget by 6.2%');
        expect(figures['Staff up to date with training'].value).toBe('96%');
    });
});

describe('CEO report dates and status', () => {
    it('shows a datetime-local wall time the way people read it', () => {
        expect(formatWallTime('2026-09-10T17:00')).toBe('10 Sep 2026, 5:00 pm');
        expect(formatWallTime('2026-09-10T00:30')).toBe('10 Sep 2026, 12:30 am');
        expect(formatWallTime('')).toBeNull();
    });

    it('builds defaults from the meeting in NZ time, not UTC', () => {
        // 9:00 am NZDT on 21 October 2026 is 20:00 UTC on 20 October.
        const defaults = ceoReportDefaultsFromMeeting({
            id: 1,
            title: 'October board meeting',
            scheduled_at: '2026-10-20T20:00:00Z',
        });

        expect(defaults.period_end).toBe('2026-10-20');
        expect(defaults.period_start).toBe('2026-09-21');
        expect(defaults.deadline).toBe('2026-10-18T09:00');
    });

    it('uses the shared labels, with overdue drafts called out', () => {
        expect(ceoReportChip('submitted', false).label).toBe('Waiting for the board');
        expect(ceoReportChip('draft', true)).toEqual({ label: 'Overdue', variant: 'critical' });
    });
});
