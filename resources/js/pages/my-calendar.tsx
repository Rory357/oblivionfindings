import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, router } from '@inertiajs/react';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import type { EventClickArg, DatesSetArg, DateSelectArg, EventDropArg } from '@fullcalendar/core';
import type { EventResizeDoneArg } from '@fullcalendar/interaction';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    Calendar, CalendarDays, ChevronLeft, ChevronRight, Clock, ListTodo,
    MapPin, MoreHorizontal, Palmtree, Pill, Plus, Shield, X,
} from 'lucide-react';

// ── Types ─────────────────────────────────────────────────────────────────────

type ViewKey = 'dayGridMonth' | 'timeGridWeek' | 'timeGridDay' | 'listWeek';

const views: { key: ViewKey; label: string }[] = [
    { key: 'dayGridMonth', label: 'Month' },
    { key: 'timeGridWeek', label: 'Week' },
    { key: 'timeGridDay', label: 'Day' },
    { key: 'listWeek', label: 'List' },
];

const categories = [
    { bg: 'bg-blue-50 dark:bg-blue-950/40', dot: 'bg-blue-500', label: 'Shifts', icon: CalendarDays },
    { bg: 'bg-green-50 dark:bg-green-950/40', dot: 'bg-green-500', label: 'In Progress', icon: Clock },
    { bg: 'bg-amber-50 dark:bg-amber-950/40', dot: 'bg-amber-500', label: 'Medications', icon: Pill },
    { bg: 'bg-emerald-50 dark:bg-emerald-950/40', dot: 'bg-emerald-500', label: 'Leave', icon: Palmtree },
    { bg: 'bg-violet-50 dark:bg-violet-950/40', dot: 'bg-violet-500', label: 'Tasks', icon: ListTodo },
];

// ── CSRF helper ───────────────────────────────────────────────────────────────

function getCsrfToken() {
    return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content;
}

// ── CSS overrides ─────────────────────────────────────────────────────────────

const calendarStyles = `
/* ── Root ───────────────────────────────────────────────────────────────── */
.fc {
    --fc-border-color: hsl(var(--border) / 0.3);
    --fc-today-bg-color: hsl(var(--primary) / 0.02);
    --fc-neutral-bg-color: transparent;
    --fc-page-bg-color: transparent;
    --fc-non-business-color: hsl(var(--muted) / 0.08);
    font-family: inherit;
}
.fc .fc-scrollgrid { border: none !important; }
.fc th, .fc td { border-color: hsl(var(--border) / 0.2) !important; }

/* ── Column headers: large day numbers like the reference ──────────────── */
.fc .fc-col-header { border-bottom: 1px solid hsl(var(--border) / 0.3) !important; }
.fc .fc-col-header-cell { padding: 0.5rem 0; border: none !important; }
.fc .fc-col-header-cell-cushion {
    display: flex; flex-direction: column; align-items: center; gap: 2px;
    font-weight: 400; font-size: 0.7rem; text-transform: uppercase;
    letter-spacing: 0.08em; color: hsl(var(--muted-foreground));
    text-decoration: none !important;
    padding: 0.5rem 0.75rem; border-radius: 1rem;
}
.fc .fc-day-today .fc-col-header-cell-cushion {
    background: hsl(var(--primary)); color: white !important;
    border-radius: 1rem; padding: 0.5rem 1rem; font-weight: 600;
}

/* ── Time axis ─────────────────────────────────────────────────────────── */
.fc .fc-timegrid-axis-cushion,
.fc .fc-timegrid-slot-label-cushion {
    font-size: 0.7rem; font-weight: 500; color: hsl(var(--muted-foreground) / 0.7);
    padding-right: 0.5rem;
}
.fc .fc-timegrid-slot { height: 3.5em; }
.fc .fc-timegrid-slot-lane { border-top: 1px dotted hsl(var(--border) / 0.35) !important; }

/* ── Events: large rounded pastel blocks ───────────────────────────────── */
.fc .fc-event, .fc .fc-event-mirror {
    border: none !important; border-radius: 0.75rem !important;
    cursor: pointer; transition: all 0.2s ease;
    box-shadow: 0 1px 4px rgba(0,0,0,0.03);
    overflow: hidden;
}
.fc .fc-event:hover {
    box-shadow: 0 6px 20px rgba(0,0,0,0.08); transform: translateY(-1px);
    z-index: 10 !important;
}
.fc .fc-timegrid-event { border-radius: 0.75rem !important; margin: 2px 3px; }
.fc .fc-timegrid-event .fc-event-main { padding: 0.375rem 0.625rem; }
.fc .fc-daygrid-event { border-radius: 0.5rem !important; padding: 2px 8px; margin: 1px 3px; }

/* ── Select mirror (drag to create) ────────────────────────────────────── */
.fc .fc-highlight {
    background: hsl(var(--primary) / 0.08) !important;
    border: 2px dashed hsl(var(--primary) / 0.3) !important;
    border-radius: 0.75rem;
}

/* ── Now indicator ─────────────────────────────────────────────────────── */
.fc .fc-now-indicator-line {
    border-color: #ef4444 !important; border-width: 2px !important; z-index: 4;
}
.fc .fc-now-indicator-arrow {
    border-color: #ef4444 !important; border-width: 6px !important;
}

/* ── Today ──────────────────────────────────────────────────────────────── */
.fc .fc-day-today { background: hsl(var(--primary) / 0.015) !important; }

/* ── Month day numbers ─────────────────────────────────────────────────── */
.fc .fc-daygrid-day-number {
    font-weight: 700; font-size: 0.9rem; padding: 0.5rem;
    color: hsl(var(--foreground));
}
.fc .fc-day-today .fc-daygrid-day-number {
    background: hsl(var(--primary)); color: white;
    border-radius: 9999px; width: 2rem; height: 2rem;
    display: inline-flex; align-items: center; justify-content: center;
    margin: 0.25rem;
}

/* ── More link ─────────────────────────────────────────────────────────── */
.fc .fc-more-link { font-size: 0.7rem; font-weight: 600; color: hsl(var(--primary)); }

/* ── List view ─────────────────────────────────────────────────────────── */
.fc .fc-list { border: 1px solid hsl(var(--border)) !important; border-radius: 1rem; overflow: hidden; }
.fc .fc-list-event:hover td { background-color: hsl(var(--accent)); }
.fc .fc-list-day-cushion { background: hsl(var(--muted) / 0.2); font-weight: 600; }

/* ── Dark mode ─────────────────────────────────────────────────────────── */
.dark .fc .fc-event:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.25); }

/* ── Context menu ──────────────────────────────────────────────────────── */
.calendar-context-menu {
    position: fixed; z-index: 50; min-width: 180px;
    background: hsl(var(--card)); border: 1px solid hsl(var(--border));
    border-radius: 0.75rem; box-shadow: 0 8px 30px rgba(0,0,0,0.12);
    padding: 0.375rem; overflow: hidden;
}
.calendar-context-menu button {
    display: flex; align-items: center; gap: 0.5rem; width: 100%;
    padding: 0.5rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem;
    transition: background 0.1s; text-align: left; border: none; background: none;
    cursor: pointer; color: hsl(var(--foreground));
}
.calendar-context-menu button:hover { background: hsl(var(--accent)); }
.calendar-context-menu hr { margin: 0.25rem 0; border-color: hsl(var(--border)); }
`;

// ── Helpers ────────────────────────────────────────────────────────────────────

function pad2(n: number) { return String(n).padStart(2, '0'); }
function toLocalISO(d: Date) {
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}T${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

// ── Event renderer ────────────────────────────────────────────────────────────

function renderEventContent(eventInfo: { event: any; view: any; timeText: string }) {
    const props = eventInfo.event.extendedProps;
    const isTime = eventInfo.view.type.includes('timeGrid');
    const isDay = eventInfo.view.type === 'timeGridDay';

    return (
        <div className="flex h-full flex-col overflow-hidden">
            <span className={`truncate font-bold leading-tight ${isDay ? 'text-sm' : 'text-xs'}`}>
                {eventInfo.event.title}
            </span>
            {isTime && (
                <span className={`truncate opacity-70 ${isDay ? 'text-xs' : 'text-[10px]'}`}>
                    {eventInfo.timeText}
                </span>
            )}
            {isTime && props.location && (
                <span className="mt-auto flex items-center gap-0.5 truncate text-[10px] opacity-50">
                    <MapPin className="h-2.5 w-2.5 shrink-0" />{props.location}
                </span>
            )}
        </div>
    );
}

// ── Page ───────────────────────────────────────────────────────────────────────

export default function MyCalendar() {
    const calendarRef = useRef<FullCalendar>(null);
    const [currentView, setCurrentView] = useState<ViewKey>('timeGridWeek');
    const [title, setTitle] = useState('');

    // Context menu state
    const [ctxMenu, setCtxMenu] = useState<{ x: number; y: number; date: Date; endDate?: Date } | null>(null);
    // Create event dialog
    const [createOpen, setCreateOpen] = useState(false);
    const [createType, setCreateType] = useState<'shift' | 'task'>('shift');
    const [createTitle, setCreateTitle] = useState('');
    const [createStart, setCreateStart] = useState('');
    const [createEnd, setCreateEnd] = useState('');
    const [createNotes, setCreateNotes] = useState('');

    // Close context menu on any click
    useEffect(() => {
        const close = () => setCtxMenu(null);
        document.addEventListener('click', close);
        return () => document.removeEventListener('click', close);
    }, []);

    // ── Navigation ────────────────────────────────────────────────────────────
    const goToday = useCallback(() => calendarRef.current?.getApi().today(), []);
    const goPrev = useCallback(() => calendarRef.current?.getApi().prev(), []);
    const goNext = useCallback(() => calendarRef.current?.getApi().next(), []);
    const changeView = useCallback((view: ViewKey) => {
        calendarRef.current?.getApi().changeView(view);
        setCurrentView(view);
    }, []);

    // ── Callbacks ─────────────────────────────────────────────────────────────
    const handleDatesSet = useCallback((arg: DatesSetArg) => {
        setTitle(arg.view.title);
        setCurrentView(arg.view.type as ViewKey);
    }, []);

    const handleEventClick = useCallback((info: EventClickArg) => {
        const t = info.event.extendedProps.type;
        if (t === 'shift') router.visit(`/clients/${info.event.extendedProps.client_id}`);
        if (t === 'task') router.visit(`/control-room/alerts/${info.event.extendedProps.alert_id}`);
    }, []);

    // Drag to select → open context menu or create dialog
    const handleSelect = useCallback((arg: DateSelectArg) => {
        setCreateStart(toLocalISO(arg.start));
        setCreateEnd(toLocalISO(arg.end));
        setCreateTitle('');
        setCreateNotes('');
        setCreateType('shift');
        setCreateOpen(true);
        calendarRef.current?.getApi().unselect();
    }, []);

    // Right-click on calendar
    const handleDateRightClick = useCallback((e: React.MouseEvent) => {
        // Only handle right-click on the calendar grid
        const target = e.target as HTMLElement;
        if (!target.closest('.fc-timegrid-slot-lane, .fc-daygrid-day, .fc-timegrid-col')) return;
        e.preventDefault();
        setCtxMenu({ x: e.clientX, y: e.clientY, date: new Date() });
    }, []);

    // Event drag & drop (move)
    const handleEventDrop = useCallback(async (info: EventDropArg) => {
        const type = info.event.extendedProps.type;
        if (type !== 'shift') { info.revert(); return; }
        const shiftId = info.event.id.replace('shift-', '');
        try {
            const token = getCsrfToken();
            const res = await fetch(`/calendar/shifts/${shiftId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', ...(token ? { 'X-CSRF-TOKEN': token } : {}) },
                credentials: 'same-origin',
                body: JSON.stringify({
                    starts_at: info.event.start?.toISOString(),
                    ends_at: info.event.end?.toISOString(),
                }),
            });
            if (!res.ok) info.revert();
        } catch { info.revert(); }
    }, []);

    // Event resize
    const handleEventResize = useCallback(async (info: EventResizeDoneArg) => {
        const type = info.event.extendedProps.type;
        if (type !== 'shift') { info.revert(); return; }
        const shiftId = info.event.id.replace('shift-', '');
        try {
            const token = getCsrfToken();
            const res = await fetch(`/calendar/shifts/${shiftId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', ...(token ? { 'X-CSRF-TOKEN': token } : {}) },
                credentials: 'same-origin',
                body: JSON.stringify({ ends_at: info.event.end?.toISOString() }),
            });
            if (!res.ok) info.revert();
        } catch { info.revert(); }
    }, []);

    const fetchEvents = useCallback(
        async (info: any, successCallback: any, failureCallback: any) => {
            try {
                const res = await fetch(`/my-calendar/events?start=${info.startStr}&end=${info.endStr}`, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();
                successCallback(data);
            } catch (e) {
                console.error('Calendar fetch error:', e);
                failureCallback(e);
            }
        }, [],
    );

    // Open create dialog from context menu
    const openCreateFromCtx = (type: 'shift' | 'task') => {
        if (ctxMenu) {
            setCreateStart(toLocalISO(ctxMenu.date));
            const end = new Date(ctxMenu.date);
            end.setHours(end.getHours() + (type === 'shift' ? 4 : 1));
            setCreateEnd(toLocalISO(end));
        }
        setCreateType(type);
        setCreateTitle('');
        setCreateNotes('');
        setCtxMenu(null);
        setCreateOpen(true);
    };

    // ── Render ────────────────────────────────────────────────────────────────
    return (
        <AppLayout breadcrumbs={[{ title: 'My Calendar', href: '/my-calendar' }]}>
            <Head title="My Calendar" />
            <style dangerouslySetInnerHTML={{ __html: calendarStyles }} />

            <PageShell>
                <div className="flex gap-6">
                    {/* ── Sidebar ──────────────────────────────────────────── */}
                    <div className="hidden w-60 shrink-0 space-y-4 lg:block">
                        <Card className="overflow-hidden">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-semibold">My Calendars</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-0.5 pb-4">
                                {categories.map((cat) => {
                                    const Icon = cat.icon;
                                    return (
                                        <div key={cat.label} className={`flex items-center gap-3 rounded-lg px-3 py-2 ${cat.bg}`}>
                                            <span className={`h-2.5 w-2.5 rounded-full ${cat.dot}`} />
                                            <Icon className="h-3.5 w-3.5 opacity-50" />
                                            <span className="text-sm font-medium">{cat.label}</span>
                                        </div>
                                    );
                                })}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardContent className="space-y-1 pt-4">
                                <Button variant="ghost" size="sm" className="w-full justify-start gap-2 text-sm" asChild>
                                    <Link href="/my-tasks"><Clock className="h-4 w-4" />My Day</Link>
                                </Button>
                                <Button variant="ghost" size="sm" className="w-full justify-start gap-2 text-sm" asChild>
                                    <Link href="/calendar"><Calendar className="h-4 w-4" />Team Calendar</Link>
                                </Button>
                                <Button variant="ghost" size="sm" className="w-full justify-start gap-2 text-sm" asChild>
                                    <Link href="/control-room"><Shield className="h-4 w-4" />Control Room</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    </div>

                    {/* ── Main ─────────────────────────────────────────────── */}
                    <div className="min-w-0 flex-1">
                        {/* Header */}
                        <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-4">
                                <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
                                <div className="flex items-center">
                                    <button onClick={goPrev} className="inline-flex h-9 w-9 items-center justify-center rounded-full hover:bg-muted transition-colors">
                                        <ChevronLeft className="h-5 w-5" />
                                    </button>
                                    <button onClick={goNext} className="inline-flex h-9 w-9 items-center justify-center rounded-full hover:bg-muted transition-colors">
                                        <ChevronRight className="h-5 w-5" />
                                    </button>
                                </div>
                                <button onClick={goToday} className="rounded-full border px-5 py-1.5 text-sm font-semibold shadow-sm hover:bg-accent transition-colors">
                                    Today
                                </button>
                            </div>
                            <div className="inline-flex items-center gap-1 rounded-full border p-1 bg-muted/20">
                                {views.map((v) => (
                                    <button
                                        key={v.key}
                                        onClick={() => changeView(v.key)}
                                        className={`rounded-full px-4 py-1.5 text-sm font-semibold transition-all ${
                                            currentView === v.key
                                                ? 'bg-foreground text-background shadow'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        {v.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Calendar */}
                        <div className="rounded-2xl border bg-card shadow-sm overflow-hidden" onContextMenu={handleDateRightClick}>
                            <FullCalendar
                                ref={calendarRef}
                                plugins={[dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin]}
                                initialView="timeGridWeek"
                                headerToolbar={false}
                                events={fetchEvents}
                                eventClick={handleEventClick}
                                datesSet={handleDatesSet}
                                select={handleSelect}
                                eventDrop={handleEventDrop}
                                eventResize={handleEventResize}
                                height="auto"
                                timeZone="local"
                                slotMinTime="05:00:00"
                                slotMaxTime="23:00:00"
                                allDaySlot={true}
                                nowIndicator={true}
                                eventContent={renderEventContent}
                                selectable={true}
                                editable={true}
                                eventResizableFromStart={true}
                                selectMirror={true}
                                businessHours={{ daysOfWeek: [1, 2, 3, 4, 5], startTime: '08:00', endTime: '18:00' }}
                                slotDuration="00:30:00"
                                dayMaxEvents={3}
                                moreLinkContent={(args) => `+${args.num} more`}
                                expandRows={true}
                                stickyHeaderDates={true}
                                firstDay={1}
                                eventTimeFormat={{ hour: '2-digit', minute: '2-digit', meridiem: false }}
                            />
                        </div>
                    </div>
                </div>

                {/* ── Context Menu ─────────────────────────────────────────── */}
                {ctxMenu && (
                    <div
                        className="calendar-context-menu"
                        style={{ top: ctxMenu.y, left: ctxMenu.x }}
                        onClick={(e) => e.stopPropagation()}
                    >
                        <button onClick={() => openCreateFromCtx('shift')}>
                            <CalendarDays className="h-4 w-4 text-blue-500" />
                            <span>New Shift</span>
                        </button>
                        <button onClick={() => openCreateFromCtx('task')}>
                            <ListTodo className="h-4 w-4 text-violet-500" />
                            <span>New Task</span>
                        </button>
                        <hr />
                        <button onClick={() => { setCtxMenu(null); changeView('timeGridDay'); }}>
                            <Calendar className="h-4 w-4 text-muted-foreground" />
                            <span>View Day</span>
                        </button>
                        <button onClick={() => { setCtxMenu(null); router.visit('/my-tasks'); }}>
                            <Clock className="h-4 w-4 text-muted-foreground" />
                            <span>Go to My Day</span>
                        </button>
                    </div>
                )}

                {/* ── Create Event Dialog ──────────────────────────────────── */}
                <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>
                                {createType === 'shift' ? 'Create Event' : 'Create Task'}
                            </DialogTitle>
                        </DialogHeader>
                        <div className="space-y-4 py-2">
                            <div className="flex gap-2">
                                {(['shift', 'task'] as const).map((t) => (
                                    <button
                                        key={t}
                                        onClick={() => setCreateType(t)}
                                        className={`flex-1 rounded-lg border py-2 text-sm font-medium transition-colors ${
                                            createType === t
                                                ? 'border-primary bg-primary/5 text-primary'
                                                : 'text-muted-foreground hover:bg-muted'
                                        }`}
                                    >
                                        {t === 'shift' ? 'Shift / Event' : 'Task'}
                                    </button>
                                ))}
                            </div>
                            <div>
                                <Label>Title</Label>
                                <Input value={createTitle} onChange={(e) => setCreateTitle(e.target.value)} placeholder={createType === 'shift' ? 'Client name or event...' : 'Task description...'} autoFocus />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <Label>Start</Label>
                                    <Input type="datetime-local" value={createStart} onChange={(e) => setCreateStart(e.target.value)} />
                                </div>
                                <div>
                                    <Label>End</Label>
                                    <Input type="datetime-local" value={createEnd} onChange={(e) => setCreateEnd(e.target.value)} />
                                </div>
                            </div>
                            <div>
                                <Label>Notes</Label>
                                <Textarea value={createNotes} onChange={(e) => setCreateNotes(e.target.value)} rows={2} placeholder="Optional notes..." />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button variant="ghost" onClick={() => setCreateOpen(false)}>Cancel</Button>
                            <Button
                                disabled={!createTitle.trim()}
                                onClick={() => {
                                    // For now, add to calendar visually and redirect to the proper creation form
                                    if (createType === 'shift') {
                                        router.visit(`/calendar`);
                                    } else {
                                        router.visit(`/control-room/alerts`);
                                    }
                                    setCreateOpen(false);
                                }}
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                Create
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </AppLayout>
    );
}
