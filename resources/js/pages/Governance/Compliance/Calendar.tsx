import AppLayout from '@/layouts/app-layout';
import {
    createGovernanceCalendarAdapter,
    GOVERNANCE_CALENDAR_SOURCES,
} from '@/lib/governance-calendar-adapter';
import SiteCalendar from '@/pages/sites/calendar/SiteCalendar';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { useMemo } from 'react';

interface CalendarEvent {
    id: number;
    title: string;
    date: string;
    framework: string;
    status: string;
    days_remaining?: number;
    owner: string | null;
}

interface Props extends PageProps {
    events?: CalendarEvent[];
}

export default function ComplianceCalendar({ events = [] }: Props) {
    const adapter = useMemo(
        () =>
            createGovernanceCalendarAdapter({
                title: 'Compliance calendar',
                subline:
                    'Statutory obligations, reviews, and renewals across frameworks',
                initialSources: ['obligations'],
                sourceFilters: GOVERNANCE_CALENDAR_SOURCES,
            }),
        [],
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Compliance', href: '/governance/compliance' },
                { title: 'Calendar', href: '/governance/compliance/calendar' },
            ]}
        >
            <Head title="Compliance Calendar" />
            <SiteCalendar
                context="page"
                scope="global"
                canCreate={false}
                dataAdapter={adapter}
            />
        </AppLayout>
    );
}
