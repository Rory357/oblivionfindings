import { describe, expect, it } from 'vitest';
import {
    bookingOverlapsOverviewDay,
    boundedOverviewPage,
    browserViewsToImport,
    isOverviewOverdue,
    normalizeOverviewFilters,
} from './overview-model';

describe('overview filters and dates', () => {
    it('includes an overdue return from earlier today, without treating a date-only due today as overdue', () => {
        const asOf = '2026-09-27T11:00:00+13:00';
        expect(isOverviewOverdue('2026-09-27T09:00:00+13:00', asOf)).toBe(true);
        expect(isOverviewOverdue('2026-09-27', asOf)).toBe(false);
        expect(isOverviewOverdue('2026-09-26', asOf)).toBe(true);
        expect(isOverviewOverdue(null, asOf)).toBe(false);
    });

    it('restores only supported saved filters, tolerating corrupted browser storage', () => {
        const defaults = {
            view: 'overview',
            site: 'all',
            q: '',
            due: 'all',
            agendaDay: 'all',
        };
        expect(
            normalizeOverviewFilters(
                {
                    view: 'broken',
                    site: '-1',
                    q: {},
                    due: 'overdue',
                    agendaDay: '2026-09-27',
                    record: 87,
                },
                defaults,
            ),
        ).toEqual({ ...defaults, due: 'overdue', agendaDay: '2026-09-27' });
        expect(normalizeOverviewFilters(null, defaults)).toEqual(defaults);
        expect(
            normalizeOverviewFilters({ q: 'a'.repeat(140) }, defaults).q,
        ).toHaveLength(120);
    });

    it('imports browser views without replacing account versions or exceeding six', () => {
        const account = [{ name: 'My Site', filters: { site: '1' } }];
        const local = [
            { name: 'My Site', filters: { site: '2' } },
            { name: 'Overview', filters: { site: 'all' } },
        ];
        expect(browserViewsToImport(account, local)).toEqual([
            { name: 'My Site (browser)', filters: { site: '2' } },
            { name: 'Overview', filters: { site: 'all' } },
        ]);
        expect(
            browserViewsToImport(account, [
                { name: 'MY SITE', filters: { site: '1' } },
            ]),
        ).toEqual([]);
        expect(
            browserViewsToImport(
                [
                    ...account,
                    ...Array.from({ length: 5 }, (_, index) => ({
                        name: `Other ${index}`,
                        filters: { site: 'all' },
                    })),
                ],
                local,
            ),
        ).toEqual([]);
    });

    it('keeps a shortened result set on a populated page after refresh', () => {
        expect(boundedOverviewPage(4, 6)).toBe(2);
        expect(boundedOverviewPage(4, 0)).toBe(1);
        expect(boundedOverviewPage(NaN, 12)).toBe(1);
    });

    it('finds multiday bookings on each reserved Auckland day across the daylight saving boundary', () => {
        const start = '2026-09-26T12:00:00Z'; // 27 September, midnight NZST
        const end = '2026-09-28T11:00:00Z'; // 29 September, midnight NZDT
        expect(
            bookingOverlapsOverviewDay(
                start,
                end,
                '2026-09-27',
                'Pacific/Auckland',
            ),
        ).toBe(true);
        expect(
            bookingOverlapsOverviewDay(
                start,
                end,
                '2026-09-28',
                'Pacific/Auckland',
            ),
        ).toBe(true);
        expect(
            bookingOverlapsOverviewDay(
                start,
                end,
                '2026-09-29',
                'Pacific/Auckland',
            ),
        ).toBe(false);
        expect(
            bookingOverlapsOverviewDay(
                null,
                end,
                '2026-09-27',
                'Pacific/Auckland',
            ),
        ).toBe(false);
    });
});
