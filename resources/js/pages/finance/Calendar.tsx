import AppLayout from '@/layouts/app-layout';
import {
    createFinanceCalendarAdapter,
    FINANCE_CALENDAR_SOURCES,
} from '@/lib/finance-calendar-adapter';
import SiteCalendar from '@/pages/sites/calendar/SiteCalendar';
import type { SourceDef } from '@/pages/sites/calendar/_parts';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { useMemo } from 'react';

interface Props extends PageProps {
    /** Sources the viewer may see (server-filtered — GST needs finance.tax.view). */
    sources?: SourceDef[];
    initialSources?: string[];
}

/**
 * Finance calendar — the shared Site Calendar (DESIGN.md "Calendars — always
 * the Site Calendar style") fed by the finance obligation adapter. Every entry
 * is derived from a ledger record and links back to it; nothing is created or
 * moved here, so the calendar runs read-only.
 */
export default function FinanceCalendar({ sources, initialSources }: Props) {
    const adapter = useMemo(
        () =>
            createFinanceCalendarAdapter({
                title: 'Calendar',
                initialSources,
                sourceFilters: sources ?? FINANCE_CALENDAR_SOURCES,
            }),
        [sources, initialSources],
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Finance', href: '/finance' },
                { title: 'Calendar', href: '/finance/calendar' },
            ]}
        >
            <Head title="Calendar" />
            <SiteCalendar
                context="page"
                scope="global"
                canCreate={false}
                dataAdapter={adapter}
            />
        </AppLayout>
    );
}
