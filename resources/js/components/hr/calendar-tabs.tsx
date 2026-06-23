import { router, usePage } from '@inertiajs/react';
import { Calendar, CalendarOff } from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type CalendarTab = 'schedule' | 'timeoff';

const TAB_URLS: Record<CalendarTab, string> = {
    schedule: '/hr/calendar',
    timeoff: '/hr/calendar/time-off',
};

type HrCan = {
    calendar?: { view?: boolean };
};

/**
 * Section-level tab strip for the Calendar hub (pulled together in S9): Schedule
 * (the company event calendar, hr.calendar.view) + Time Off (who's off each day
 * — the TimeOffCalendarController is auth-only, so the tab is always shown). The
 * active tab is always rendered so the current page never hides its own tab.
 * (The Compliance hub keeps its own calendar, surfaced there as the "Renewals"
 * tab on the same cert/vetting-expiry data.)
 */
export function CalendarTabs({ active }: { active: CalendarTab }) {
    const hr = (usePage().props as { auth?: { can?: { hr?: HrCan } } }).auth?.can
        ?.hr;

    const all: Array<{ item: HrTabItem; show: boolean }> = [
        {
            item: { id: 'schedule', label: 'Schedule', icon: Calendar, tone: 'primary' },
            show: !!hr?.calendar?.view,
        },
        {
            item: { id: 'timeoff', label: 'Time Off', icon: CalendarOff, tone: 'info' },
            show: true,
        },
    ];

    const items: HrTabItem[] = all
        .filter((t) => t.show || t.item.id === active)
        .map((t) => t.item);

    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as CalendarTab]);
            }}
            items={items}
            ariaLabel="Calendar views"
            className="mb-6"
        />
    );
}

export default CalendarTabs;
