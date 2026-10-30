import type { CalendarItem } from '@/lib/calendar/recur';
import type { CalendarDataAdapter } from '@/pages/sites/calendar/SiteCalendar';
import type { SourceDef } from '@/pages/sites/calendar/_parts';
import type { PersonalCalendarEntry } from './personal-calendar';

export const MY_CALENDAR_SOURCES: SourceDef[] = [
    {
        key: 'it_booking',
        label: 'IT bookings',
        short: 'IT bookings',
        group: 'auto',
        icon: 'CalendarDays',
        origin: 'Your requested and accepted ticket bookings',
    },
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
        origin: 'Medicines work on your roster',
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
            : props.description ||
              props.timed_tasks
                  ?.map((task) =>
                      `${task.scheduled_time ?? ''} ${task.label}`.trim(),
                  )
                  .join('\n') ||
              null,
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
            const first = start.getTime();
            const last = end.getTime();
            if (
                !Number.isFinite(first) ||
                !Number.isFinite(last) ||
                last <= first
            ) {
                throw new Error('Choose a valid calendar period.');
            }
            // The shared overdue rail covers 75 days. Keep each feed request
            // within the medication provider's 62-day limit without dropping
            // older work or weakening its server-side guard.
            const maxSpan = 60 * 24 * 60 * 60 * 1000;
            const events = new Map<string, MyCalendarEvent>();
            const availability: Record<string, string> = {};
            for (let cursor = first; cursor < last; cursor += maxSpan) {
                signal?.throwIfAborted();
                const params = new URLSearchParams({
                    start: new Date(cursor).toISOString(),
                    end: new Date(
                        Math.min(cursor + maxSpan, last),
                    ).toISOString(),
                });
                const response = await fetch(`/my-calendar/events?${params}`, {
                    signal,
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok)
                    throw new Error('Could not load your calendar.');
                const batch: MyCalendarEvent[] = await response.json();
                // Spanning entries and date-inclusive rounds may occur in
                // adjacent responses; their canonical IDs keep one row.
                for (const event of batch) events.set(event.id, event);
                Object.assign(
                    availability,
                    JSON.parse(
                        response.headers?.get('X-Calendar-Unavailable') || '{}',
                    ),
                );
            }
            return {
                events: [...events.values()].map((event) =>
                    myCalendarItem(event, owner),
                ),
                availability,
            };
        },
    };
}
