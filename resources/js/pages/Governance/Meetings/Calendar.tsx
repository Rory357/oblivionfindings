import AppLayout from '@/layouts/app-layout';
import { toDateInput } from '@/lib/datetime';
import {
    createGovernanceCalendarAdapter,
    GOVERNANCE_CALENDAR_SOURCES,
} from '@/lib/governance-calendar-adapter';
import { canDoGovernance } from '@/lib/governance-permissions';
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
    /** Calendar sources whose registers this viewer can open (server-filtered). */
    calendarSources?: string[];
    /** Store route gate + policy create ability (server-computed). */
    canCreate?: boolean;
    formOptions?: MeetingFormOptions | null;
}

/**
 * The Meetings calendar reuses the shared Site Calendar (DESIGN.md
 * "Calendars — always the Site Calendar style") through the governance data
 * adapter. Its header rail carries the calendar views, so members — who have
 * no Meetings hub in the sidebar — get a way back to Governance home. Only
 * the sources the viewer can open are offered, and creating from a slot
 * opens the scheduling wizard in place, seeded with the slot's NZ date and
 * hour.
 */
export default function MeetingsCalendar({
    auth,
    calendarSources,
    canCreate = false,
    formOptions = null,
}: Props) {
    const canSchedule = canCreate && formOptions !== null;
    const [createSeed, setCreateSeed] = useState<string | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const governancePermissions =
        (auth as { can?: { governance?: Record<string, unknown> } } | undefined)?.can
            ?.governance ?? null;
    const showHomeLink = !canDoGovernance(governancePermissions, 'meetings', 'manage');
    const sourceKey = (calendarSources ?? []).join(',');

    const adapter = useMemo(() => {
        const allowed = calendarSources
            ? GOVERNANCE_CALENDAR_SOURCES.filter((source) => calendarSources.includes(source.key))
            : GOVERNANCE_CALENDAR_SOURCES;

        return createGovernanceCalendarAdapter({
            title: 'Meetings calendar',
            subline: 'Board and committee meetings',
            initialSources: ['meetings'],
            sourceFilters: allowed.length > 0 ? allowed : GOVERNANCE_CALENDAR_SOURCES.slice(0, 1),
            backLink: showHomeLink
                ? { href: '/governance/dashboard', label: 'Governance home' }
                : undefined,
            onCreate: (seed) => {
                const date = seed?.date ? toDateInput(seed.date) : '';
                const hour = seed?.hour !== undefined && seed?.hour !== null ? seed.hour : 9;
                setCreateSeed(date ? `${date}T${String(hour).padStart(2, '0')}:00` : null);
                setCreateOpen(true);
            },
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sourceKey, showHomeLink]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Meetings', href: '/governance/meetings' },
                { title: 'Calendar', href: '/governance/meetings/calendar' },
            ]}
        >
            <Head title="Meetings calendar" />
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
