import { toDateInput } from '@/lib/datetime';
import { describe, expect, it } from 'vitest';
import {
    historyPeriodEnding,
    historyQuery,
    historyRange,
    shiftHistoryDate,
    type HistoryFilters,
} from './audit-history-period';

const filters: HistoryFilters = {
    client_id: 42,
    date_from: '2026-09-27',
    date_to: '2026-10-03',
    event_types: ['dose_administered', 'correction_approved'],
};

describe('Clinical history NZ calendar requests', () => {
    it.each([
        ['2026-04-05', '2026-03-30'], // NZ's 25-hour day
        ['2026-09-27', '2026-09-21'], // NZ's 23-hour day
        ['2028-03-01', '2028-02-24'], // leap day
        ['2026-01-01', '2025-12-26'],
    ])('requests seven inclusive calendar dates ending %s', (end, start) => {
        expect(historyPeriodEnding(end, 7)).toEqual({
            date_from: start,
            date_to: end,
        });
    });

    it('uses the NZ calendar day while UTC is still the previous date', () => {
        const nzToday = toDateInput('2026-09-26T12:05:00Z');
        expect(nzToday).toBe('2026-09-27');
        expect(historyPeriodEnding(nzToday, 7).date_from).toBe('2026-09-21');
    });

    it('distinguishes server all-history, presets and a custom period', () => {
        expect(historyRange(filters)).toBe('7');
        expect(
            historyRange({ ...filters, date_from: null, date_to: null }),
        ).toBe('all');
        expect(historyRange({ ...filters, date_from: '2026-09-28' })).toBe(
            'custom',
        );
        expect(historyRange({ ...filters, date_to: null })).toBe('custom');
    });

    it('preserves person, Site, event types and relevant URL filters on a new server period', () => {
        expect(
            historyQuery(
                '/emar/reports/history?client_id=999&site_id=999&event_types[0]=stale&history_staff=Jo&history_view=table',
                filters,
                8,
                historyPeriodEnding('2026-04-05', 7),
            ),
        ).toEqual({
            client_id: 42,
            site_id: 8,
            event_types: filters.event_types,
            date_from: '2026-03-30',
            date_to: '2026-04-05',
            history_staff: 'Jo',
            history_view: 'table',
        });
    });

    it('keeps the current dates when changing Site or person and explicitly clears bounds for all history', () => {
        expect(
            historyQuery('', filters, 8, { site_id: 9, client_id: 43 }),
        ).toMatchObject({
            site_id: 9,
            client_id: 43,
            date_from: filters.date_from,
            date_to: filters.date_to,
        });
        const cleared = historyQuery('', filters, 8, {
            date_from: null,
            date_to: null,
        });
        expect(cleared).not.toHaveProperty('date_from');
        expect(cleared).not.toHaveProperty('date_to');
        expect(cleared).toMatchObject({
            site_id: 8,
            client_id: 42,
            event_types: filters.event_types,
        });
    });

    it('rejects impossible dates before calendar stepping', () => {
        expect(shiftHistoryDate('2026-02-30', -1)).toBe('');
    });
});
