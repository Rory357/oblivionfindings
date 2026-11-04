import { describe, expect, it, vi } from 'vitest';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();
    return { ...actual, router: { ...actual.router, visit } };
});

import {
    bucketLabel,
    formatMoney,
    formatRate,
    rateRows,
    reportHref,
    scopeParams,
    siteMenu,
    siteReportHref,
    type ReportFilters,
    type TrendBucket,
} from './report-kit';

const filters: ReportFilters = {
    period: '6m',
    from: '2026-05-05',
    to: '2026-11-04',
    region: null,
    today: '2026-11-04',
    unit: 'month',
};

describe('Site report scope in the address', () => {
    it('leaves the default period out of links', () => {
        expect(scopeParams(filters)).toEqual({});
        expect(reportHref('/sites/reports/houses', filters)).toBe(
            '/sites/reports/houses',
        );
    });

    it('carries the period and region to the next report', () => {
        const scoped = { ...filters, period: '30d', region: 'Auckland' };
        expect(reportHref('/sites/reports/checklist-trends', scoped)).toBe(
            '/sites/reports/checklist-trends?period=30d&region=Auckland',
        );
    });

    it('carries a custom range as dates', () => {
        const custom = {
            ...filters,
            period: 'custom',
            from: '2026-01-01',
            to: '2026-03-31',
        };
        expect(scopeParams(custom)).toEqual({
            period: 'custom',
            date_from: '2026-01-01',
            date_to: '2026-03-31',
        });
    });
});

describe('a Site in a report', () => {
    it("links to the Site's report, not its profile, keeping the period", () => {
        expect(siteReportHref(12, filters)).toBe('/sites/reports/site/12');
        expect(
            siteReportHref(12, {
                ...filters,
                period: '12m',
                region: 'Waikato',
            }),
        ).toBe('/sites/reports/site/12?period=12m');
    });

    it('offers the report first and the profile second', () => {
        visit.mockClear();
        const items = siteMenu(
            { id: 7, name: 'Kōwhai House', type: 'house', pack: 'houses' },
            filters,
        );
        expect(items.map((item) => item.label)).toEqual([
            'Open site report',
            'Open site profile',
        ]);
        items[0].onClick?.();
        items[1].onClick?.();
        expect(visit).toHaveBeenNthCalledWith(1, '/sites/reports/site/7');
        expect(visit).toHaveBeenNthCalledWith(2, '/sites/7');
    });
});

describe('numbers in words', () => {
    it('never shows a missing rate as 0%', () => {
        expect(formatRate(null)).toBeNull();
        expect(formatRate(0)).toBe('0%');
        expect(formatRate(86.4)).toBe('86%');
    });

    it('writes money as NZ dollars', () => {
        expect(formatMoney(84.6)).toBe('$84.60');
    });

    it('labels trend buckets by month or week', () => {
        const bucket = { key: '2026-05', start: '2026-05-05' } as TrendBucket;
        expect(bucketLabel(bucket, 'month')).toBe('May 2026');
        expect(bucketLabel({ ...bucket, key: '2026-05-04' }, 'week')).toBe(
            '4 May',
        );
    });

    it('keeps a bucket with nothing due as a gap', () => {
        const rows = rateRows(
            [
                {
                    key: '2026-05',
                    checks_due: 0,
                    checks_on_time: 0,
                    checks_on_time_rate: null,
                } as TrendBucket,
                {
                    key: '2026-06',
                    checks_due: 4,
                    checks_on_time: 3,
                    checks_on_time_rate: 75,
                } as TrendBucket,
            ],
            'month',
            'checks',
        );
        expect(rows.map((row) => row.rate)).toEqual([null, 75]);
        expect(rows[1]).toMatchObject({ done: 3, due: 4, label: 'Jun 2026' });
    });
});
