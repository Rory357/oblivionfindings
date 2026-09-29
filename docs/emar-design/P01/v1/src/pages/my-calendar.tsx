/* My Calendar — the REAL shared calendar (pages/sites/calendar/SiteCalendar)
 * with the real My Calendar sources, fed by a synthetic adapter. P01 changes
 * only the "Meds" source: the medication slots on the worker's rostered
 * shifts, from the same schedule as Meds today (today it shows a round only
 * when a round is assigned to the user). Entries open Meds today; they can't
 * be dragged or edited here (care records keep their own workflow). */
import { MY_CALENDAR_SOURCES, myCalendarItem } from '@/lib/my-calendar-adapter';
import SiteCalendar, { type CalendarDataAdapter } from '@/pages/sites/calendar/SiteCalendar';
import { useMemo } from 'react';
import { PERSONAS } from '../data';
import { Shell } from '../shell';
import { useStore } from '../store';
import { DesignNote } from '../ui';
import { useMedTasks } from '../tasks-model';

const iso = (hhmm: string) => `2026-09-28T${hhmm}:00+13:00`;
const slotTime: Record<string, string> = { '8:00 am': '08:00', '9:00 am': '09:00', '12:00 pm': '12:00' };

export function MyCalendarPage() {
    const s = useStore();
    const tasks = useMedTasks();
    const persona = PERSONAS[s.route.persona];
    const key = tasks.map((t) => `${t.slot}:${t.state}:${t.done}`).join('|');
    const adapter: CalendarDataAdapter = useMemo(
        () => ({
            title: 'My Calendar',
            subline: 'Your work and personal planning · times in NZDT (Pacific/Auckland)',
            initialView: 'day',
            sourceFilters: MY_CALENDAR_SOURCES,
            allowSubscriptions: false,
            showApprovalMeter: false,
            mineLink: { href: '/my-day', label: 'Open My Day' },
            searchPlaceholder: 'Search your calendar…',
            onOpenItem: (item) => {
                if (item.source === 'medication_round') {
                    s.go('/meds/today');
                    return true;
                }
                return undefined;
            },
            loadItems: async () => {
                const owner = { id: persona.userId, name: persona.name };
                const events = [
                    { id: 'shift-1', title: 'Shift · Kōwhai House', start: iso('07:00'), end: iso('15:00'), extendedProps: { type: 'shift', status: 'scheduled', location: 'Kōwhai House' } },
                    ...tasks.map((t) => ({
                        id: `meds-${slotTime[t.slot]}`,
                        title: `Medicines ${t.slot} · ${t.total} doses · ${t.state === 'done' ? 'all recorded' : t.state === 'late' ? `${t.late} late` : t.state === 'due' ? 'due now' : 'not yet due'}`,
                        start: iso(slotTime[t.slot]),
                        end: iso(`${String(Number(slotTime[t.slot].slice(0, 2)) + 1).padStart(2, '0')}:00`),
                        extendedProps: { type: 'medication_round', status: t.state === 'done' ? 'completed' : t.state === 'late' ? 'overdue' : 'scheduled', location: 'Kōwhai House', link: '/meds/today', description: `${t.people.join(', ')} · from your rostered shift · opens Meds today` },
                    })),
                    { id: 'task-1', title: 'Walk to the library — Tama', start: iso('10:30'), end: iso('11:15'), extendedProps: { type: 'alert_task', status: 'scheduled', location: 'Kōwhai House' } },
                ];
                return { events: events.map((e) => myCalendarItem(e, owner)) };
            },
        }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [key, persona.userId],
    );
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'My Calendar' }]}>
            <SiteCalendar key={key} context="page" scope="global" canCreate={false} dataAdapter={adapter} />
            <DesignNote title="Design note — My Calendar">
                <p>This is the shared calendar itself with a synthetic adapter; only the Meds source changes. Each medication slot on a rostered shift is one entry (P00 v4/v5), from the same schedule and counts as Meds today, and it opens Meds today. Doses the worker isn’t rostered for, and controlled medicines for roles without access, never appear.</p>
            </DesignNote>
        </Shell>
    );
}
