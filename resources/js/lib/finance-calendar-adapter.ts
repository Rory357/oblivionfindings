import { router } from '@inertiajs/react';

import type { CalendarItem } from '@/lib/calendar/recur';
import type {
    CalendarDataAdapter,
    CalView,
} from '@/pages/sites/calendar/SiteCalendar';
import type { Decorated, SourceDef } from '@/pages/sites/calendar/_parts';

/**
 * The finance calendar sources in plain words. Mirrors SOURCE_LABELS in
 * FinanceCalendarController — the finance calendar page receives these from the
 * server, already filtered to what the viewer may see. Colours come from the
 * `--src-invoice-due` / `--src-bill-due` / `--src-payment-run` /
 * `--src-gst-due` / `--src-payroll` / `--src-period-close` token triples in
 * app.css.
 *
 * Keys are dashed because the shared calendar builds `var(--src-{key})`
 * straight from them; the server's obligation providers use the underscored
 * equivalents and map on the way out.
 */
export const FINANCE_CALENDAR_SOURCES: SourceDef[] = [
    {
        key: 'invoice-due',
        label: 'Invoices due',
        short: 'Invoices',
        group: 'auto',
        icon: 'Receipt',
        origin: 'Invoices',
        note: 'When a customer invoice falls due',
    },
    {
        key: 'bill-due',
        label: 'Bills due',
        short: 'Bills',
        group: 'auto',
        icon: 'FileText',
        origin: 'Bills',
        note: 'When a supplier bill falls due',
    },
    {
        key: 'payment-run',
        label: 'Payment runs',
        short: 'Payments',
        group: 'auto',
        icon: 'Banknote',
        origin: 'Payment runs',
        note: 'When a scheduled payment run is paid',
    },
    {
        key: 'gst-due',
        label: 'GST returns due',
        short: 'GST',
        group: 'auto',
        icon: 'Percent',
        origin: 'GST returns',
        note: 'When a GST return must be filed and paid',
    },
    {
        key: 'payroll',
        label: 'Payroll runs',
        short: 'Payroll',
        group: 'auto',
        icon: 'Users',
        origin: 'Payroll',
        note: 'When a pay period is paid',
    },
    {
        key: 'period-close',
        label: 'Period closes',
        short: 'Periods',
        group: 'auto',
        icon: 'CalendarRange',
        origin: 'Fiscal periods',
        note: 'When a fiscal period closes',
    },
];

export interface FinanceCalendarAdapterOptions {
    title?: string;
    subline?: string;
    initialSources?: string[];
    initialView?: CalView;
    sourceFilters?: SourceDef[];
    onOpenItem?: (item: Decorated) => void;
}

/**
 * Feeds the shared SiteCalendar from the finance obligation feed. Obligations
 * are derived from the ledgers and never created or moved here, so the adapter
 * has no `onCreate`/`onMove` and the page passes `canCreate={false}`.
 */
export function createFinanceCalendarAdapter(
    options?: FinanceCalendarAdapterOptions,
): CalendarDataAdapter {
    const sourceFilters = options?.sourceFilters ?? FINANCE_CALENDAR_SOURCES;

    return {
        title: options?.title ?? 'Calendar',
        subline:
            options?.subline ??
            'Invoice and bill due dates, payment runs and GST deadlines',
        allowSubscriptions: false,
        // Finance obligations are never signed off on the calendar, so a
        // "To approve" meter would always read 0 — leave it out.
        showApprovalMeter: false,
        mineLink: { href: '/finance', label: 'Open Finance overview' },
        backLink: { href: '/finance', label: 'Finance overview' },
        searchPlaceholder: 'Search invoices, bills, payment runs…',
        exportFilename: 'finance-calendar.ics',
        initialSources:
            options?.initialSources ?? sourceFilters.map((source) => source.key),
        initialView: options?.initialView ?? 'month',
        sourceFilters,
        loadItems: async ({ start, end, signal }) => {
            const params = new URLSearchParams({
                start: start.toISOString(),
                end: end.toISOString(),
            });
            const res = await fetch(`/finance/calendar/events?${params}`, {
                signal,
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) {
                throw Object.assign(
                    new Error("The calendar couldn't be loaded."),
                    { status: res.status },
                );
            }
            const data = (await res.json()) as {
                events?: CalendarItem[];
                totals?: Record<string, number>;
            };
            return { events: data.events ?? [], totals: data.totals };
        },
        onOpenItem: (item: Decorated) => {
            if (options?.onOpenItem) {
                options.onOpenItem(item);
                return;
            }
            if (item.link) {
                router.visit(item.link);
            }
        },
    };
}
