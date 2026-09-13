import { canDoGovernance } from '@/lib/governance-permissions';
import AppLayout from '@/layouts/app-layout';
import {
    createGovernanceCalendarAdapter,
    GOVERNANCE_CALENDAR_SOURCES,
} from '@/lib/governance-calendar-adapter';
import SiteCalendar from '@/pages/sites/calendar/SiteCalendar';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { useMemo } from 'react';

interface MeetingItem {
    id: number;
    title: string;
    meeting_type: string;
    scheduled_at: string;
    duration_minutes: number;
    location: string | null;
    status: string;
    quorum_met: boolean;
    chair: { name: string } | null;
    secretary: { name: string } | null;
}

interface MeetingTypeOption {
    value: string;
    label: string;
}

interface Props extends PageProps {
    month?: string;
    monthLabel?: string;
    previousMonth?: string;
    nextMonth?: string;
    selectedDate?: string;
    selectedMeetingType?: string;
    meetingTypes?: MeetingTypeOption[];
    meetings?: MeetingItem[];
}

export default function MeetingsCalendar({
    auth,
    meetings = [],
    selectedMeetingType,
}: Props) {
    const permissions =
        (auth as { can?: { governance?: Record<string, unknown> } })?.can
            ?.governance ?? null;
    const canCreate = canDoGovernance(permissions, 'meetings', 'manage');

    const adapter = useMemo(
        () =>
            createGovernanceCalendarAdapter({
                title: 'Meetings calendar',
                subline:
                    'Board and committee meetings across the operating organisation',
                initialSources: ['meetings'],
                sourceFilters: GOVERNANCE_CALENDAR_SOURCES,
            }),
        [],
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Meetings', href: '/governance/meetings' },
                { title: 'Calendar', href: '/governance/meetings/calendar' },
            ]}
        >
            <Head title="Meetings Calendar" />
            <SiteCalendar
                context="page"
                scope="global"
                canCreate={canCreate}
                dataAdapter={adapter}
            />
        </AppLayout>
    );
}
