import AppLayout from '@/layouts/app-layout';
import {
    createGovernanceCalendarAdapter,
    GOVERNANCE_CALENDAR_SOURCES,
} from '@/lib/governance-calendar-adapter';
import SiteCalendar from '@/pages/sites/calendar/SiteCalendar';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { useMemo } from 'react';

interface Props extends PageProps {
    initialSources?: string[];
    canCreate: boolean;
    committeeOptions?: { value: string; label: string }[];
}

export default function GovernanceCalendarIndex({
    initialSources,
    canCreate,
    committeeOptions,
}: Props) {
    const adapter = useMemo(
        () =>
            createGovernanceCalendarAdapter({
                initialSources,
                sourceFilters: GOVERNANCE_CALENDAR_SOURCES,
                committeeOptions,
            }),
        [initialSources, committeeOptions],
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Calendar', href: '/governance/calendar' },
            ]}
        >
            <Head title="Governance Calendar" />
            <SiteCalendar
                context="page"
                scope="global"
                canCreate={canCreate}
                dataAdapter={adapter}
            />
        </AppLayout>
    );
}
