import type { CalendarItem } from '@/lib/calendar/recur';
import type { CalendarDataAdapter } from '@/pages/sites/calendar/SiteCalendar';
import type { SourceDef } from '@/pages/sites/calendar/_parts';
import type { PersonalCalendarEntry } from './personal-calendar';

export const MY_CALENDAR_SOURCES: SourceDef[] = [
    ...(['task', 'meeting', 'appointment', 'reminder'] as const).map(
        (kind) => ({
            key: `personal_${kind}`,
            label:
                kind === 'task'
                    ? 'Personal tasks'
                    : `${kind[0].toUpperCase()}${kind.slice(1)}s`,
            short:
                kind === 'task'
                    ? 'Personal tasks'
                    : `${kind[0].toUpperCase()}${kind.slice(1)}s`,
            group: 'manual' as const,
            icon: kind === 'task' ? 'CheckSquare' : 'CalendarDays',
            origin: 'Your private calendar entries',
        }),
    ),
    {
        key: 'shift',
        label: 'Shifts',
        short: 'Shifts',
        group: 'auto',
        icon: 'CalendarDays',
        origin: 'Your assigned shifts',
    },
    {
        key: 'medication_round',
        label: 'Meds',
        short: 'Meds',
        group: 'auto',
        icon: 'Pill',
        origin: 'Your medication rounds',
    },
    {
        key: 'leave',
        label: 'Leave',
        short: 'Leave',
        group: 'auto',
        icon: 'CalendarDays',
        origin: 'Your approved leave',
    },
    {
        key: 'alert_task',
        label: 'Tasks',
        short: 'Tasks',
        group: 'auto',
        icon: 'CheckSquare',
        origin: 'Your assigned tasks',
    },
];

export interface MyCalendarEvent {
    id: string;
    title: string;
    start: string;
    end?: string | null;
    allDay?: boolean;
    extendedProps: {
        type: string;
        status?: string | null;
        location?: string | null;
        link?: string | null;
        priority?: string | null;
        description?: string | null;
        personal_entry?: PersonalCalendarEntry;
        timed_tasks?: { scheduled_time?: string | null; label: string }[];
    };
}

export function myCalendarItem(
    event: MyCalendarEvent,
    owner: { id: number; name: string },
): CalendarItem {
    const props = event.extendedProps;
    const personal = props.personal_entry;
    return {
        id: event.id,
        source: props.type,
        group: personal ? 'manual' : 'auto',
        title: event.title,
        start: event.start,
        end: event.end ?? null,
        allDay: event.allDay ?? false,
        status:
            props.type === 'leave' ? 'approved' : (props.status ?? 'scheduled'),
        owner,
        room: props.location ?? null,
        ref: null,
        site: null,
        link: props.link ?? null,
        editable: !!personal,
        recordId: personal?.id,
        version: personal?.version,
        eventType: personal?.kind,
        priority: props.priority ?? null,
        desc: personal
            ? personal.description
            : props.timed_tasks
                  ?.map((task) =>
                      `${task.scheduled_time ?? ''} ${task.label}`.trim(),
                  )
                  .join('\n') || null,
    };
}

export function createMyCalendarAdapter(
    owner: { id: number; name: string },
    syncStatus?: string,
): CalendarDataAdapter {
    return {
        title: 'My Calendar',
        subline: `Your work and personal planning${syncStatus ? ` · ${syncStatus}` : ''}`,
        initialView: 'month',
        sourceFilters: MY_CALENDAR_SOURCES,
        allowSubscriptions: false,
        showApprovalMeter: false,
        mineLink: { href: '/my-day', label: 'Open My Day' },
        exportFilename: 'my-calendar.ics',
        searchPlaceholder: 'Search your calendar…',
        loadItems: async ({ start, end, signal }) => {
            const params = new URLSearchParams({
                start: start.toISOString(),
                end: end.toISOString(),
            });
            const response = await fetch(`/my-calendar/events?${params}`, {
                signal,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error('Could not load your calendar.');
            const events: MyCalendarEvent[] = await response.json();
            return {
                events: events.map((event) => myCalendarItem(event, owner)),
                availability: JSON.parse(
                    response.headers?.get('X-Calendar-Unavailable') || '{}',
                ) as Record<string, string>,
            };
        },
    };
}
