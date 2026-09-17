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

/**
 * Compliance calendar — the canonical Site Calendar (DESIGN.md "Calendars —
 * always the Site Calendar style") fed through the Governance data adapter.
 * SiteCalendar owns the Event Horizon header, its five-view rail and source
 * filters; this page only supplies the adapter and the Home-rooted trail.
 */
export default function ComplianceCalendar({ auth }: Props) {
    const canManage = Boolean(auth?.can?.governance?.compliance?.manage);

    const adapter = useMemo(
        () => ({
            ...createGovernanceCalendarAdapter({
                title: 'Compliance calendar',
                subline:
                    'When legal, standards and funding requirements are due',
                initialSources: ['obligations'],
                sourceFilters: GOVERNANCE_CALENDAR_SOURCES,
            }),
            searchPlaceholder: 'Search requirements…',
            // The requirement wizard lives on the register; this deep link opens it.
            primaryAction: canManage
                ? {
                      href: '/governance/compliance?create=1',
                      label: 'Add requirement',
                  }
                : undefined,
        }),
        [canManage],
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
            <Head title="Compliance calendar" />
            <SiteCalendar
                context="page"
                scope="global"
                canCreate={false}
                dataAdapter={adapter}
            />
        </AppLayout>
    );
}
