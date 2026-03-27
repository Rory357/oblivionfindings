import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Head, router } from '@inertiajs/react';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import type { EventClickArg, DatesSetArg } from '@fullcalendar/core';
import { useRef, useState, useCallback } from 'react';
import {
    Calendar,
    ChevronLeft,
    ChevronRight,
    Clock,
    MapPin,
} from 'lucide-react';

/* -------------------------------------------------------------------------- */
/*  Types                                                                     */
/* -------------------------------------------------------------------------- */

type ViewKey = 'dayGridMonth' | 'timeGridWeek' | 'timeGridDay' | 'listWeek';

const views: { key: ViewKey; label: string }[] = [
    { key: 'dayGridMonth', label: 'Month' },
    { key: 'timeGridWeek', label: 'Week' },
    { key: 'timeGridDay', label: 'Day' },
    { key: 'listWeek', label: 'List' },
];

const categories = [
    { color: '#dbeafe', textColor: '#1e40af', label: 'Shifts' },
    { color: '#dcfce7', textColor: '#166534', label: 'In Progress' },
    { color: '#ffedd5', textColor: '#9a3412', label: 'Medications' },
    { color: '#d1fae5', textColor: '#065f46', label: 'Leave' },
    { color: '#ede9fe', textColor: '#5b21b6', label: 'Tasks' },
];

/* -------------------------------------------------------------------------- */
/*  Calendar style overrides                                                  */
/* -------------------------------------------------------------------------- */

const calendarStyles = `
    .fc {
        --fc-border-color: hsl(var(--border));
        --fc-today-bg-color: hsl(var(--primary) / 0.04);
    }
    .fc .fc-timegrid-slot { height: 3em; }
    .fc .fc-timegrid-slot-lane { border-style: dotted; }
    .fc .fc-event {
        border-radius: 0.5rem;
        border: none !important;
        cursor: pointer;
        transition: box-shadow 0.15s;
    }
    .fc .fc-event:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .fc .fc-daygrid-event { border-radius: 0.375rem; padding: 1px 4px; }
    .fc .fc-now-indicator-line { border-color: #ef4444; border-width: 2px; }
    .fc .fc-now-indicator-arrow { border-color: #ef4444; }
    .fc .fc-col-header-cell { padding: 0.5rem 0; }
    .fc .fc-col-header-cell-cushion {
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: hsl(var(--muted-foreground));
    }
    .fc .fc-timegrid-axis-cushion {
        font-size: 0.7rem;
        color: hsl(var(--muted-foreground));
    }
    .fc .fc-scrollgrid { border: none !important; }
    .fc .fc-scrollgrid td { border-color: hsl(var(--border)); }
    .fc .fc-day-today .fc-col-header-cell-cushion { color: hsl(var(--primary)); }
    .fc .fc-list-event:hover td { background-color: hsl(var(--accent)); }
    .dark .fc .fc-event:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
`;

/* -------------------------------------------------------------------------- */
/*  Custom event renderer                                                     */
/* -------------------------------------------------------------------------- */

function renderEventContent(eventInfo: any) {
    const props = eventInfo.event.extendedProps;
    const isTimeGrid = eventInfo.view.type.includes('timeGrid');

    return (
        <div className="flex flex-col gap-0.5 overflow-hidden px-1.5 py-1">
            <span className="truncate text-xs font-semibold">
                {eventInfo.event.title}
            </span>
            {isTimeGrid && (
                <span className="truncate text-[10px] opacity-75">
                    {eventInfo.timeText}
                </span>
            )}
            {isTimeGrid && props.location && (
                <span className="flex items-center gap-0.5 truncate text-[10px] opacity-60">
                    <MapPin className="h-2.5 w-2.5" />
                    {props.location}
                </span>
            )}
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/*  Page component                                                            */
/* -------------------------------------------------------------------------- */

export default function MyCalendar() {
    const calendarRef = useRef<FullCalendar>(null);
    const [currentView, setCurrentView] = useState<ViewKey>('timeGridWeek');
    const [title, setTitle] = useState('');

    /* ----- navigation helpers ----- */

    const goToday = useCallback(() => {
        calendarRef.current?.getApi().today();
    }, []);

    const goPrev = useCallback(() => {
        calendarRef.current?.getApi().prev();
    }, []);

    const goNext = useCallback(() => {
        calendarRef.current?.getApi().next();
    }, []);

    const changeView = useCallback((view: ViewKey) => {
        calendarRef.current?.getApi().changeView(view);
        setCurrentView(view);
    }, []);

    /* ----- callbacks ----- */

    const handleDatesSet = useCallback((arg: DatesSetArg) => {
        setTitle(arg.view.title);
        setCurrentView(arg.view.type as ViewKey);
    }, []);

    const handleEventClick = useCallback((info: EventClickArg) => {
        const type = info.event.extendedProps.type;
        if (type === 'shift') {
            router.visit(`/clients/${info.event.extendedProps.client_id}`);
        }
        if (type === 'task') {
            router.visit(`/control-room/alerts/${info.event.extendedProps.alert_id}`);
        }
    }, []);

    const fetchEvents = useCallback(
        (
            info: { startStr: string; endStr: string },
            successCb: (events: any[]) => void,
            failureCb: (error: any) => void,
        ) => {
            fetch(`/my-calendar/events?start=${info.startStr}&end=${info.endStr}`)
                .then((r) => r.json())
                .then(successCb)
                .catch(failureCb);
        },
        [],
    );

    /* ----- render ----- */

    return (
        <AppLayout
            breadcrumbs={[{ title: 'My Calendar', href: '/my-calendar' }]}
        >
            <Head title="My Calendar" />
            <style dangerouslySetInnerHTML={{ __html: calendarStyles }} />

            <PageShell>
                <div className="flex gap-6">
                    {/* ---- Left sidebar ---- */}
                    <div className="hidden w-72 shrink-0 space-y-4 lg:block">
                        {/* Categories legend */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm">Categories</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2.5">
                                {categories.map((cat) => (
                                    <div
                                        key={cat.label}
                                        className="flex items-center gap-2.5"
                                    >
                                        <span
                                            className="h-3 w-3 rounded-sm"
                                            style={{
                                                backgroundColor: cat.color,
                                                border: `1px solid ${cat.textColor}30`,
                                            }}
                                        />
                                        <span className="text-sm text-muted-foreground">
                                            {cat.label}
                                        </span>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        {/* Quick links */}
                        <Card>
                            <CardContent className="space-y-2 pt-6">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="w-full justify-start"
                                    asChild
                                >
                                    <a href="/my-tasks">
                                        <Clock className="mr-2 h-4 w-4" />
                                        My Day
                                    </a>
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="w-full justify-start"
                                    asChild
                                >
                                    <a href="/calendar">
                                        <Calendar className="mr-2 h-4 w-4" />
                                        Team Calendar
                                    </a>
                                </Button>
                            </CardContent>
                        </Card>
                    </div>

                    {/* ---- Main calendar area ---- */}
                    <div className="min-w-0 flex-1">
                        {/* Custom header toolbar */}
                        <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            {/* Left: title + nav arrows */}
                            <div className="flex items-center gap-3">
                                <h1 className="text-xl font-bold tracking-tight">
                                    {title}
                                </h1>
                                <div className="flex items-center gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="h-8 w-8"
                                        onClick={goPrev}
                                    >
                                        <ChevronLeft className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="h-8 w-8"
                                        onClick={goNext}
                                    >
                                        <ChevronRight className="h-4 w-4" />
                                    </Button>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={goToday}
                                >
                                    Today
                                </Button>
                            </div>

                            {/* Right: view switcher */}
                            <div className="inline-flex items-center gap-0.5 rounded-lg border bg-muted/40 p-0.5">
                                {views.map((v) => (
                                    <button
                                        key={v.key}
                                        onClick={() => changeView(v.key)}
                                        className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                            currentView === v.key
                                                ? 'bg-background text-foreground shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        {v.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Calendar */}
                        <Card>
                            <CardContent className="p-2 sm:p-4">
                                <FullCalendar
                                    ref={calendarRef}
                                    plugins={[
                                        dayGridPlugin,
                                        timeGridPlugin,
                                        listPlugin,
                                        interactionPlugin,
                                    ]}
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
                                    businessHours={{
                                        daysOfWeek: [1, 2, 3, 4, 5],
                                        startTime: '08:00',
                                        endTime: '18:00',
                                    }}
                                    slotDuration="00:30:00"
                                    dayMaxEvents={3}
                                    moreLinkContent={(args) =>
                                        `+${args.num} more`
                                    }
                                />
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
