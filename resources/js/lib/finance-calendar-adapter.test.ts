import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';

import type { Decorated } from '@/pages/sites/calendar/_parts';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { visit } }));

import {
    createFinanceCalendarAdapter,
    FINANCE_CALENDAR_SOURCES,
} from './finance-calendar-adapter';

describe('Finance calendar adapter', () => {
    afterEach(() => {
        visit.mockReset();
        vi.unstubAllGlobals();
    });

    it('colours every finance source with a --src token triple in app.css', () => {
        const css = readFileSync(resolve(__dirname, '../../css/app.css'), 'utf8');

        for (const source of FINANCE_CALENDAR_SOURCES) {
            expect(css).toMatch(new RegExp(`--src-${source.key}:\\s*oklch\\(`));
            expect(css).toMatch(new RegExp(`--src-${source.key}-bg:\\s*oklch\\(`));
            expect(css).toMatch(new RegExp(`--src-${source.key}-ln:\\s*oklch\\(`));
        }
    });

    it('uses plain source names that say where an entry comes from', () => {
        expect(FINANCE_CALENDAR_SOURCES.map((s) => s.label)).toEqual([
            'Invoices due',
            'Bills due',
            'Payment runs',
            'GST returns due',
            'Payroll runs',
            'Period closes',
        ]);
        for (const source of FINANCE_CALENDAR_SOURCES) {
            expect(source.note).toBeTruthy();
            expect(source.note).not.toMatch(/obligation|manual|auto-synced/i);
        }
    });

    it('keys sources in the dashed form the --src tokens use', () => {
        for (const source of FINANCE_CALENDAR_SOURCES) {
            expect(source.key).not.toContain('_');
        }
    });

    it('runs read-only: no create affordance, no approval meter', () => {
        const adapter = createFinanceCalendarAdapter();

        expect(adapter.onCreate).toBeUndefined();
        expect(adapter.onMove).toBeUndefined();
        expect(adapter.showApprovalMeter).toBe(false);
        expect(adapter.allowSubscriptions).toBe(false);
        expect(adapter.backLink).toEqual({
            href: '/finance',
            label: 'Finance overview',
        });
        expect(adapter.exportFilename).toBe('finance-calendar.ics');
        expect(adapter.initialSources).toEqual([
            'invoice-due',
            'bill-due',
            'payment-run',
            'gst-due',
            'payroll',
            'period-close',
        ]);
    });

    it('starts with only the sources the server allowed', () => {
        const adapter = createFinanceCalendarAdapter({
            sourceFilters: FINANCE_CALENDAR_SOURCES.filter(
                (source) => source.key !== 'gst-due',
            ),
        });

        expect(adapter.initialSources).not.toContain('gst-due');
    });

    it('loads the feed for the window and keeps the server totals', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                events: [{ id: 'invoice-12', source: 'invoice-due' }],
                totals: { total: 1, overdue: 0, 'invoice-due': 1 },
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        const adapter = createFinanceCalendarAdapter();
        const result = await adapter.loadItems({
            start: new Date('2026-09-01T00:00:00Z'),
            end: new Date('2026-10-01T00:00:00Z'),
        });

        const url = String(fetchMock.mock.calls[0][0]);
        expect(url).toContain('/finance/calendar/events?');
        expect(url).toContain('start=2026-09-01');
        expect(result.events).toHaveLength(1);
        expect(result.totals).toEqual({ total: 1, overdue: 0, 'invoice-due': 1 });
    });

    it('reports a failed load with its status so the calendar can explain it', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 403 }));

        const adapter = createFinanceCalendarAdapter();

        await expect(
            adapter.loadItems({ start: new Date(), end: new Date() }),
        ).rejects.toMatchObject({ status: 403 });
    });

    it('opens an entry at its ledger record', () => {
        createFinanceCalendarAdapter().onOpenItem?.({
            link: '/finance/invoices/7',
        } as Decorated);

        expect(visit).toHaveBeenCalledWith('/finance/invoices/7');
    });
});
