import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Head, Link, router } from '@inertiajs/react';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import type { EventClickArg, DatesSetArg } from '@fullcalendar/core';
import { useRef, useState, useCallback } from 'react';
import {
    Calendar,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Clock,
    MapPin,
    Pill,
    Shield,
    Palmtree,
    ListTodo,
} from 'lucide-react';

type ViewKey = 'dayGridMonth' | 'timeGridWeek' | 'timeGridDay' | 'listWeek';

const views: { key: ViewKey; label: string }[] = [
    { key: 'dayGridMonth', label: 'Month' },
    { key: 'timeGridWeek', label: 'Week' },
    { key: 'timeGridDay', label: 'Day' },
    { key: 'listWeek', label: 'List' },
];

const categories = [
    { bg: 'bg-blue-100 dark:bg-blue-900/40', dot: 'bg-blue-500', label: 'Shifts', icon: CalendarDays },
    { bg: 'bg-green-100 dark:bg-green-900/40', dot: 'bg-green-500', label: 'In Progress', icon: Clock },
    { bg: 'bg-orange-100 dark:bg-orange-900/40', dot: 'bg-orange-500', label: 'Medications', icon: Pill },
    { bg: 'bg-emerald-100 dark:bg-emerald-900/40', dot: 'bg-emerald-500', label: 'Leave', icon: Palmtree },
    { bg: 'bg-violet-100 dark:bg-violet-900/40', dot: 'bg-violet-500', label: 'Tasks', icon: ListTodo },
];

/* ── Aggressive CSS overrides to match the modern reference design ────────── */
const calendarStyles = `
/* Root variables */
.fc {
    --fc-border-color: transparent;
    --fc-today-bg-color: hsl(var(--primary) / 0.03);
    --fc-neutral-bg-color: transparent;
    --fc-page-bg-color: transparent;
    --fc-non-business-color: hsl(var(--muted) / 0.15);
    font-family: inherit;
}

/* Remove ALL borders from the scrollgrid */
.fc .fc-scrollgrid,
.fc .fc-scrollgrid td,
.fc .fc-scrollgrid th,
.fc table,
.fc th,
.fc td {
    border: none !important;
}

/* ── Column headers: big rounded day pills like the reference ────────────── */
.fc .fc-col-header-cell {
    padding: 0.75rem 0.25rem;
    vertical-align: middle;
}
.fc .fc-col-header-cell-cushion {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.125rem;
    font-weight: 700;
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: hsl(var(--muted-foreground));
    text-decoration: none !important;
    padding: 0.25rem 0.5rem;
    border-radius: 9999px;
    transition: background 0.15s;
}
/* Today's column header gets primary filled pill */
.fc .fc-day-today .fc-col-header-cell-cushion {
    background: hsl(var(--primary));
    color: white;
    border-radius: 9999px;
    padding: 0.35rem 0.75rem;
}

/* ── Time axis labels (left side) ────────────────────────────────────────── */
.fc .fc-timegrid-axis-cushion,
.fc .fc-timegrid-slot-label-cushion {
    font-size: 0.65rem;
    font-weight: 500;
    color: hsl(var(--muted-foreground));
    padding-right: 0.75rem;
}

/* ── Time grid slots: subtle dotted lines ────────────────────────────────── */
.fc .fc-timegrid-slot {
    height: 3.5em;
}
.fc .fc-timegrid-slot-lane {
    border-top: 1px dotted hsl(var(--border)) !important;
}
.fc .fc-timegrid-slot-minor .fc-timegrid-slot-lane {
    border-top: 1px dotted hsl(var(--border) / 0.4) !important;
}

/* ── Events: rounded pastel blocks with no border ────────────────────────── */
.fc .fc-event,
.fc .fc-event-mirror {
    border: none !important;
    border-radius: 0.625rem !important;
    padding: 0.25rem 0.125rem;
    cursor: pointer;
    transition: all 0.15s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    overflow: hidden;
}
.fc .fc-event:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    transform: translateY(-1px);
}
.fc .fc-timegrid-event {
    border-radius: 0.625rem !important;
    margin: 1px 2px;
}
.fc .fc-timegrid-event .fc-event-main {
    padding: 0.25rem 0.5rem;
}

/* Day grid events (month view) */
.fc .fc-daygrid-event {
    border-radius: 0.5rem !important;
    padding: 2px 6px;
    margin: 1px 2px;
}
.fc .fc-daygrid-dot-event {
    padding: 2px 6px;
}

/* ── Now indicator: bold red line ────────────────────────────────────────── */
.fc .fc-now-indicator-line {
    border-color: #ef4444 !important;
    border-width: 2px !important;
    z-index: 4;
}
.fc .fc-now-indicator-arrow {
    border-color: #ef4444 !important;
    border-width: 6px !important;
}

/* ── Today highlight ─────────────────────────────────────────────────────── */
.fc .fc-day-today {
    background: hsl(var(--primary) / 0.02) !important;
}

/* ── All-day row ─────────────────────────────────────────────────────────── */
.fc .fc-daygrid-body-natural .fc-daygrid-day-events {
    margin-bottom: 0.25rem;
}

/* ── List view ───────────────────────────────────────────────────────────── */
.fc .fc-list {
    border: 1px solid hsl(var(--border)) !important;
    border-radius: 0.75rem;
    overflow: hidden;
}
.fc .fc-list-event:hover td {
    background-color: hsl(var(--accent));
}
.fc .fc-list-day-cushion {
    background: hsl(var(--muted) / 0.3);
    font-weight: 600;
}

/* ── Month view day numbers ──────────────────────────────────────────────── */
.fc .fc-daygrid-day-number {
    font-weight: 700;
    font-size: 0.875rem;
    padding: 0.5rem;
}
.fc .fc-day-today .fc-daygrid-day-number {
    background: hsl(var(--primary));
    color: white;
    border-radius: 9999px;
    width: 2rem;
    height: 2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0.25rem;
}

/* ── More link ───────────────────────────────────────────────────────────── */
.fc .fc-more-link {
    font-size: 0.7rem;
    font-weight: 600;
    color: hsl(var(--primary));
}

/* ── Dark mode adjustments ───────────────────────────────────────────────── */
.dark .fc .fc-event:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}
.dark .fc .fc-timegrid-slot-lane {
    border-top-color: hsl(var(--border) / 0.5) !important;
}
`;

/* ── Custom event renderer ───────────────────────────────────────────────── */
function renderEventContent(eventInfo: { event: any; view: any; timeText: string }) {
    const props = eventInfo.event.extendedProps;
    const isTimeGrid = eventInfo.view.type.includes('timeGrid');
    const isDay = eventInfo.view.type === 'timeGridDay';

    return (
        <div className="flex flex-col gap-0.5 overflow-hidden">
            <span className={`truncate font-bold ${isDay ? 'text-sm' : 'text-xs'}`}>
                {eventInfo.event.title}
            </span>
            {isTimeGrid && (
                <span className={`truncate opacity-70 ${isDay ? 'text-xs' : 'text-[10px]'}`}>
                    {eventInfo.timeText}
                </span>
            )}
            {isTimeGrid && props.location && (
                <span className="flex items-center gap-0.5 truncate text-[10px] opacity-50">
                    <MapPin className="h-2.5 w-2.5 shrink-0" />
                    {props.location}
                </span>
            )}
            {isTimeGrid && props.status && (
                <span className="mt-auto flex items-center gap-1 text-[9px] opacity-60 capitalize">
                    <span className={`inline-block h-1.5 w-1.5 rounded-full ${
                        props.status === 'in_progress' ? 'bg-green-500' :
                        props.status === 'completed' ? 'bg-gray-400' : 'bg-blue-500'
                    }`} />
                    {props.status.replace('_', ' ')}
                </span>
            )}
        </div>
    );
}

/* ── Page component ──────────────────────────────────────────────────────── */
export default function MyCalendar() {
    const calendarRef = useRef<FullCalendar>(null);
    const [currentView, setCurrentView] = useState<ViewKey>('timeGridWeek');
    const [title, setTitle] = useState('');

    const goToday = useCallback(() => calendarRef.current?.getApi().today(), []);
    const goPrev = useCallback(() => calendarRef.current?.getApi().prev(), []);
    const goNext = useCallback(() => calendarRef.current?.getApi().next(), []);
    const changeView = useCallback((view: ViewKey) => {
        calendarRef.current?.getApi().changeView(view);
        setCurrentView(view);
    }, []);

    const handleDatesSet = useCallback((arg: DatesSetArg) => {
        setTitle(arg.view.title);
        setCurrentView(arg.view.type as ViewKey);
    }, []);

    const handleEventClick = useCallback((info: EventClickArg) => {
        const type = info.event.extendedProps.type;
        if (type === 'shift') router.visit(`/clients/${info.event.extendedProps.client_id}`);
        if (type === 'task') router.visit(`/control-room/alerts/${info.event.extendedProps.alert_id}`);
    }, []);

    const fetchEvents = useCallback(
        (info: { startStr: string; endStr: string }, successCb: (events: unknown[]) => void, failureCb: (error: unknown) => void) => {
            fetch(`/my-calendar/events?start=${info.startStr}&end=${info.endStr}`)
                .then((r) => r.json())
                .then(successCb)
                .catch(failureCb);
        }, [],
    );

    return (
        <AppLayout breadcrumbs={[{ title: 'My Calendar', href: '/my-calendar' }]}>
            <Head title="My Calendar" />
            <style dangerouslySetInnerHTML={{ __html: calendarStyles }} />

            <PageShell>
                <div className="flex gap-6">
                    {/* ── Left sidebar ────────────────────────────────────── */}
                    <div className="hidden w-64 shrink-0 space-y-4 lg:block">
                        {/* Categories */}
                        <Card className="overflow-hidden">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-semibold">My Calendars</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-1 pb-4">
                                {categories.map((cat) => {
                                    const Icon = cat.icon;
                                    return (
                                        <div key={cat.label} className={`flex items-center gap-3 rounded-lg px-3 py-2 ${cat.bg}`}>
                                            <span className={`h-2.5 w-2.5 rounded-full ${cat.dot}`} />
                                            <Icon className="h-3.5 w-3.5 opacity-60" />
                                            <span className="text-sm font-medium">{cat.label}</span>
                                        </div>
                                    );
                                })}
                            </CardContent>
                        </Card>

                        {/* Quick links */}
                        <Card>
                            <CardContent className="space-y-1.5 pt-5">
                                <Button variant="ghost" size="sm" className="w-full justify-start" asChild>
                                    <Link href="/my-tasks"><Clock className="mr-2 h-4 w-4" />My Day</Link>
                                </Button>
                                <Button variant="ghost" size="sm" className="w-full justify-start" asChild>
                                    <Link href="/calendar"><Calendar className="mr-2 h-4 w-4" />Team Calendar</Link>
                                </Button>
                                <Button variant="ghost" size="sm" className="w-full justify-start" asChild>
                                    <Link href="/control-room"><Shield className="mr-2 h-4 w-4" />Control Room</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    </div>

                    {/* ── Main calendar ───────────────────────────────────── */}
                    <div className="min-w-0 flex-1">
                        {/* Custom header */}
                        <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-4">
                                <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
                                <div className="flex items-center gap-1">
                                    <button onClick={goPrev} className="inline-flex h-8 w-8 items-center justify-center rounded-full hover:bg-muted transition-colors">
                                        <ChevronLeft className="h-5 w-5" />
                                    </button>
                                    <button onClick={goNext} className="inline-flex h-8 w-8 items-center justify-center rounded-full hover:bg-muted transition-colors">
                                        <ChevronRight className="h-5 w-5" />
                                    </button>
                                </div>
                                <button onClick={goToday} className="rounded-full border bg-background px-4 py-1.5 text-sm font-medium shadow-sm transition-colors hover:bg-accent">
                                    Today
                                </button>
                            </div>

                            {/* View switcher - pill buttons */}
                            <div className="inline-flex items-center gap-1 rounded-full border bg-muted/30 p-1">
                                {views.map((v) => (
                                    <button
                                        key={v.key}
                                        onClick={() => changeView(v.key)}
                                        className={`rounded-full px-4 py-1.5 text-sm font-medium transition-all ${
                                            currentView === v.key
                                                ? 'bg-foreground text-background shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        {v.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* FullCalendar */}
                        <div className="rounded-2xl border bg-card p-1 shadow-sm sm:p-3">
                            <FullCalendar
                                ref={calendarRef}
                                plugins={[dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin]}
                                initialView="timeGridWeek"
                                headerToolbar={false}
                                events={fetchEvents}
                                eventClick={handleEventClick}
                                datesSet={handleDatesSet}
                                height="auto"
                                slotMinTime="06:00:00"
                                slotMaxTime="22:00:00"
                                allDaySlot={true}
                                nowIndicator={true}
                                eventContent={renderEventContent}
                                businessHours={{ daysOfWeek: [1, 2, 3, 4, 5], startTime: '08:00', endTime: '18:00' }}
                                slotDuration="00:30:00"
                                dayMaxEvents={3}
                                moreLinkContent={(args) => `+${args.num} more`}
                                expandRows={true}
                                stickyHeaderDates={true}
                                firstDay={1}
                            />
                        </div>
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
