import AppLayout from '@/layouts/app-layout';
import { toDateInput } from '@/lib/datetime';
import {
    createGovernanceCalendarAdapter,
    GOVERNANCE_CALENDAR_SOURCES,
} from '@/lib/governance-calendar-adapter';
import SiteCalendar from '@/pages/sites/calendar/SiteCalendar';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { MeetingWizardDialog, type MeetingFormOptions } from './_dialogs';

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
    /** Store route gate + policy create ability (server-computed). */
    canCreate?: boolean;
    formOptions?: MeetingFormOptions | null;
}

/**
 * The Meetings calendar reuses the shared Site Calendar (DESIGN.md
 * "Calendars — always the Site Calendar style") through the governance data
 * adapter. Its header rail carries the five calendar views, so the hub rail
 * stays on the register pages; creating from a slot opens the same
 * scheduling wizard in place, seeded with the slot's NZ date and hour.
 */
export default function MeetingsCalendar({
    canCreate = false,
    formOptions = null,
}: Props) {
    const canSchedule = canCreate && formOptions !== null;
    const [createSeed, setCreateSeed] = useState<string | null>(null);
    const [createOpen, setCreateOpen] = useState(false);

    const adapter = useMemo(
        () =>
            createGovernanceCalendarAdapter({
                title: 'Meetings calendar',
                subline:
                    'Board and committee meetings across the operating organisation',
                initialSources: ['meetings'],
                sourceFilters: GOVERNANCE_CALENDAR_SOURCES,
                onCreate: (seed) => {
                    const date = seed?.date ? toDateInput(seed.date) : '';
                    const hour =
                        seed?.hour !== undefined && seed?.hour !== null
                            ? seed.hour
                            : 9;
                    setCreateSeed(
                        date
                            ? `${date}T${String(hour).padStart(2, '0')}:00`
                            : null,
                    );
                    setCreateOpen(true);
                },
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
                canCreate={canSchedule}
                dataAdapter={adapter}
            />
            {canSchedule && formOptions ? (
                <MeetingWizardDialog
                    isOpen={createOpen}
                    onClose={() => setCreateOpen(false)}
                    options={formOptions}
                    initialScheduledAt={createSeed}
                />
            ) : null}
        </AppLayout>
    );
}
