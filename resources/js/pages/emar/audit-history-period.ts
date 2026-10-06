import { formatDateOnly } from '@/lib/datetime';

export type HistoryFilters = {
    client_id: number | null;
    date_from: string | null;
    date_to: string | null;
    event_types: string[];
};

/** Calendar arithmetic only: DST and the browser's timezone cannot move a day. */
export function shiftHistoryDate(value: string, days: number): string {
    if (formatDateOnly(value, '') === '') return '';
    const [year, month, day] = value.split('-').map(Number);
    const calendar = new Date(Date.UTC(year, month - 1, day));
    calendar.setUTCDate(calendar.getUTCDate() + days);
    return calendar.toISOString().slice(0, 10);
}

export function historyPeriodEnding(dateTo: string, days: number) {
    return {
        date_from: shiftHistoryDate(dateTo, 1 - days),
        date_to: dateTo,
    };
}

export function historyRange(filters: HistoryFilters): string {
    if (!filters.date_from && !filters.date_to) return 'all';
    if (filters.date_from && filters.date_to) {
        for (const days of [7, 30, 90]) {
            if (
                historyPeriodEnding(filters.date_to, days).date_from ===
                filters.date_from
            )
                return String(days);
        }
    }
    return 'custom';
}

/** Carry approved scope and event types through every period/Site/person visit. */
export function historyQuery(
    url: string,
    filters: HistoryFilters,
    siteId: number | null,
    changes: Partial<HistoryFilters> & { site_id?: number | null } = {},
): Record<string, string | number | string[]> {
    const params = new URLSearchParams(url.split('?')[1] ?? '');
    const query: Record<string, string | number | string[]> = {};
    for (const [key, value] of params) {
        if (
            ![
                'date_from',
                'date_to',
                'client_id',
                'site_id',
                'event_types',
            ].includes(key) &&
            !key.startsWith('event_types[')
        )
            query[key] = value;
    }
    const next = { ...filters, site_id: siteId, ...changes };
    if (next.client_id) query.client_id = next.client_id;
    if (next.site_id) query.site_id = next.site_id;
    if (next.date_from) query.date_from = next.date_from;
    if (next.date_to) query.date_to = next.date_to;
    if (next.event_types.length) query.event_types = next.event_types;
    return query;
}
