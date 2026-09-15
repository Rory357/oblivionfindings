import AppLayout from '@/layouts/app-layout';
import {
    createGovernanceCalendarAdapter,
    GOVERNANCE_CALENDAR_SOURCES,
} from '@/lib/governance-calendar-adapter';
import SiteCalendar from '@/pages/sites/calendar/SiteCalendar';
import type { SourceDef } from '@/pages/sites/calendar/_parts';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { useMemo } from 'react';

interface Props extends PageProps {
    /** Sources the viewer may see (server-filtered by register permission). */
    sources?: SourceDef[];
    initialSources?: string[];
    canCreate: boolean;
    committeeOptions?: { value: string; label: string }[];
}

/**
 * Governance calendar — the shared Site Calendar (DESIGN.md "Calendars —
 * always the Site Calendar style") fed by the Governance adapter. Only the
 * sources whose registers the viewer can open are offered; the header keeps
 * a way back to Governance Home because its rail carries the calendar views.
 */
export default function GovernanceCalendarIndex({
    sources,
    initialSources,
    canCreate,
    committeeOptions,
}: Props) {
    const adapter = useMemo(
        () =>
            createGovernanceCalendarAdapter({
                title: 'Calendar',
                initialSources,
                sourceFilters: sources ?? GOVERNANCE_CALENDAR_SOURCES,
                committeeOptions,
                backLink: {
                    href: '/governance/dashboard',
                    label: 'Governance home',
                },
            }),
        [sources, initialSources, committeeOptions],
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Calendar', href: '/governance/calendar' },
            ]}
        >
            <Head title="Calendar" />
            <SiteCalendar
                context="page"
                scope="global"
                canCreate={canCreate}
                dataAdapter={adapter}
            />
        </AppLayout>
    );
}
