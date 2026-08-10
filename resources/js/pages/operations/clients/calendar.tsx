import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import type { EventClickArg, DatesSetArg, DateSelectArg } from '@fullcalendar/core';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    ArrowLeft, Calendar, CalendarDays, ChevronLeft, ChevronRight, Clock,
    Heart, MapPin, Pill, Plus, Stethoscope, Users,
} from 'lucide-react';

type Props = {
    client: { id: number; first_name: string; last_name: string };
    pending_visit_count: number;
};

type ViewKey = 'dayGridMonth' | 'timeGridWeek' | 'timeGridDay' | 'listWeek';

const views: { key: ViewKey; label: string }[] = [
    { key: 'dayGridMonth', label: 'Month' },
    { key: 'timeGridWeek', label: 'Week' },
    { key: 'timeGridDay', label: 'Day' },
    { key: 'listWeek', label: 'List' },
];

const categories = [
    { dot: 'bg-blue-500', label: 'Shifts', icon: CalendarDays, bg: 'bg-blue-50 dark:bg-blue-950/40' },
    { dot: 'bg-green-500', label: 'Family Visits', icon: Users, bg: 'bg-green-50 dark:bg-green-950/40' },
    { dot: 'bg-amber-500', label: 'GP Visits', icon: Stethoscope, bg: 'bg-amber-50 dark:bg-amber-950/40' },
    { dot: 'bg-purple-500', label: 'Specialist', icon: Heart, bg: 'bg-purple-50 dark:bg-purple-950/40' },
    { dot: 'bg-pink-500', label: 'Therapy', icon: Heart, bg: 'bg-pink-50 dark:bg-pink-950/40' },
    { dot: 'bg-cyan-500', label: 'Activities', icon: Calendar, bg: 'bg-cyan-50 dark:bg-cyan-950/40' },
];

const apptTypes = [
    { value: 'gp_visit', label: 'GP Visit' },
    { value: 'specialist', label: 'Specialist' },
    { value: 'therapy', label: 'Therapy' },
    { value: 'activity', label: 'Activity' },
    { value: 'reminder', label: 'Reminder' },
    { value: 'other', label: 'Other' },
];

const calendarStyles = `
.fc { --fc-border-color: transparent; --fc-today-bg-color: transparent; --fc-neutral-bg-color: transparent; --fc-page-bg-color: transparent; --fc-non-business-color: transparent; font-family: inherit; }
.fc .fc-scrollgrid, .fc .fc-scrollgrid-section > td, .fc .fc-scrollgrid-section > th { border: none !important; }
.fc table, .fc th, .fc td { border: none !important; }
.fc .fc-col-header { margin-bottom: 0.25rem; }
.fc .fc-col-header-cell { padding: 0.75rem 0; vertical-align: middle; }
.fc .fc-col-header-cell-cushion { display: flex; flex-direction: column; align-items: center; gap: 4px; text-decoration: none !important; padding: 0.5rem 1rem; border-radius: 1rem; }
.fc .fc-col-header-cell-cushion .fc-col-header-cell-content, .fc .fc-col-header-cell-cushion { font-weight: 500; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; color: hsl(var(--muted-foreground) / 0.6); }
.fc .fc-day-today .fc-col-header-cell-cushion { background: hsl(var(--primary)); color: white !important; border-radius: 1rem; font-weight: 700; }
.fc .fc-timegrid-axis-cushion, .fc .fc-timegrid-slot-label-cushion { font-size: 0.7rem; font-weight: 500; color: hsl(var(--muted-foreground) / 0.45); padding-right: 0.75rem; }
.fc .fc-timegrid-slot { height: 3.5em; }
.fc .fc-timegrid-slot-lane { border-top: 1px dotted rgba(139, 92, 246, 0.12) !important; }
.fc .fc-timegrid-slot-minor { border-top: 1px dotted rgba(139, 92, 246, 0.06) !important; }
.fc .fc-timegrid-col { border-right: 1px dotted rgba(139, 92, 246, 0.1) !important; }
.fc .fc-timegrid-col:last-child { border-right: none !important; }
.fc .fc-timegrid-divider { display: none; }
.fc .fc-timegrid-axis { border: none !important; }
.fc .fc-timegrid-body { border: none !important; }
.fc .fc-timegrid-slots td { border: none !important; }
.fc .fc-timegrid-slots tr:not(:first-child) .fc-timegrid-slot-lane { border-top: 1px solid hsl(var(--border) / 0.1) !important; }
.fc .fc-timegrid-slot-label { border: none !important; }
.fc .fc-event, .fc .fc-event-mirror { border: none !important; border-radius: 0.625rem !important; cursor: pointer; transition: all 0.15s ease; overflow: hidden; }
.fc .fc-event:hover { transform: scale(1.01); z-index: 10 !important; box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
.fc .fc-timegrid-event { border-radius: 0.625rem !important; margin: 1px 2px; min-height: 1.5em; }
.fc .fc-timegrid-event .fc-event-main { padding: 0.375rem 0.5rem; }
.fc .fc-daygrid-event { border-radius: 0.5rem !important; padding: 2px 8px; margin: 1px 3px; }
.fc .fc-daygrid-body { border: none !important; }
.fc .fc-scrollgrid-section-header td { border-bottom: 1px solid hsl(var(--border) / 0.15) !important; }
.fc .fc-highlight { background: hsl(var(--primary) / 0.06) !important; border: 2px dashed hsl(var(--primary) / 0.25) !important; border-radius: 0.625rem; }
.fc .fc-now-indicator-line { border-color: #ef4444 !important; border-width: 2px !important; z-index: 4; }
.fc .fc-now-indicator-arrow { border-color: #ef4444 !important; border-width: 5px !important; }
.fc .fc-day-today { background: hsl(var(--primary) / 0.02) !important; }
.fc .fc-daygrid-day-number { font-weight: 700; font-size: 0.9rem; padding: 0.5rem; color: hsl(var(--foreground)); }
.fc .fc-day-today .fc-daygrid-day-number { background: hsl(var(--primary)); color: white; border-radius: 9999px; width: 2rem; height: 2rem; display: inline-flex; align-items: center; justify-content: center; margin: 0.25rem; }
.fc .fc-daygrid-day { border-right: 1px dotted rgba(139, 92, 246, 0.1) !important; border-bottom: 1px dotted rgba(139, 92, 246, 0.1) !important; }
.fc .fc-more-link { font-size: 0.7rem; font-weight: 600; color: hsl(var(--primary)); }
.fc .fc-list { border: 1px solid hsl(var(--border) / 0.2) !important; border-radius: 1rem; overflow: hidden; }
.fc .fc-list-event:hover td { background-color: hsl(var(--accent)); }
.fc .fc-list-day-cushion { background: hsl(var(--muted) / 0.15); font-weight: 600; }
.fc .fc-non-business { background: hsl(var(--muted) / 0.03) !important; }
.dark .fc .fc-event:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.3); }
.calendar-context-menu { position: fixed; z-index: 50; min-width: 180px; background: hsl(var(--card)); border: 1px solid hsl(var(--border)); border-radius: 0.75rem; box-shadow: 0 8px 30px rgba(0,0,0,0.12); padding: 0.375rem; overflow: hidden; }
.calendar-context-menu button { display: flex; align-items: center; gap: 0.5rem; width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem; transition: background 0.1s; text-align: left; border: none; background: none; cursor: pointer; color: hsl(var(--foreground)); }
.calendar-context-menu button:hover { background: hsl(var(--accent)); }
.calendar-context-menu hr { margin: 0.25rem 0; border-color: hsl(var(--border)); }
`;

function pad2(n: number) { return String(n).padStart(2, '0'); }
function toLocalISO(d: Date) {
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}T${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

function getCsrfToken() {
    return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content;
}

function renderEventContent(eventInfo: { event: any; view: any; timeText: string }) {
    const props = eventInfo.event.extendedProps;
    const isTime = eventInfo.view.type.includes('timeGrid');
    const isDay = eventInfo.view.type === 'timeGridDay';
    return (
        <div className="flex h-full flex-col overflow-hidden">
            <span className={`truncate font-bold leading-tight ${isDay ? 'text-sm' : 'text-xs'}`}>{eventInfo.event.title}</span>
            {isTime && <span className={`truncate opacity-70 ${isDay ? 'text-xs' : 'text-[10px]'}`}>{eventInfo.timeText}</span>}
            {isTime && props.location && (
                <span className="mt-auto flex items-center gap-0.5 truncate text-[10px] opacity-50">
                    <MapPin className="h-2.5 w-2.5 shrink-0" />{props.location}
                </span>
            )}
        </div>
    );
}

export default function ClientCalendar({ client, pending_visit_count }: Props) {
    const name = `${client.first_name} ${client.last_name}`.trim();
    const calendarRef = useRef<FullCalendar>(null);
    const [currentView, setCurrentView] = useState<ViewKey>('timeGridWeek');
    const [title, setTitle] = useState('');

    // Context menu
    const [ctxMenu, setCtxMenu] = useState<{ x: number; y: number; date: Date } | null>(null);
    // Create appointment dialog
    const [createOpen, setCreateOpen] = useState(false);
    const [form, setForm] = useState({ title: '', appointment_type: 'gp_visit', starts_at: '', ends_at: '', location: '', provider_name: '', description: '', share_with_family: true });
    // Event detail
    const [detail, setDetail] = useState<any>(null);

    useEffect(() => {
        const close = () => setCtxMenu(null);
        document.addEventListener('click', close);
        return () => document.removeEventListener('click', close);
    }, []);

    const goToday = useCallback(() => calendarRef.current?.getApi().today(), []);
    const goPrev = useCallback(() => calendarRef.current?.getApi().prev(), []);
    const goNext = useCallback(() => calendarRef.current?.getApi().next(), []);
    const changeView = useCallback((view: ViewKey) => { calendarRef.current?.getApi().changeView(view); setCurrentView(view); }, []);

    const handleDatesSet = useCallback((arg: DatesSetArg) => { setTitle(arg.view.title); setCurrentView(arg.view.type as ViewKey); }, []);

    const handleEventClick = useCallback((info: EventClickArg) => {
        setDetail({ title: info.event.title, start: info.event.start, end: info.event.end, ...info.event.extendedProps });
    }, []);

    const handleSelect = useCallback((arg: DateSelectArg) => {
        setForm({ ...form, starts_at: toLocalISO(arg.start), ends_at: toLocalISO(arg.end), title: '', description: '', location: '', provider_name: '', appointment_type: 'gp_visit', share_with_family: true });
        setCreateOpen(true);
        calendarRef.current?.getApi().unselect();
    }, [form]);

    const handleRightClick = useCallback((e: React.MouseEvent) => {
        const target = e.target as HTMLElement;
        if (!target.closest('.fc-timegrid-slot-lane, .fc-daygrid-day, .fc-timegrid-col')) return;
        e.preventDefault();
        setCtxMenu({ x: e.clientX, y: e.clientY, date: new Date() });
    }, []);

    const fetchEvents = useCallback(async (info: any, successCallback: any, failureCallback: any) => {
        try {
            const params = new URLSearchParams({
                start: info.startStr,
                end: info.endStr,
            });
            const res = await fetch(`/clients/${client.id}/calendar/events?${params.toString()}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            successCallback(await res.json());
        } catch (e) { failureCallback(e); }
    }, [client.id]);

    const submitAppointment = async () => {
        if (!form.title.trim() || !form.starts_at) return;
        const token = getCsrfToken();
        await fetch(`/clients/${client.id}/calendar/appointments`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(token ? { 'X-CSRF-TOKEN': token } : {}) },
            credentials: 'same-origin',
            body: JSON.stringify(form),
        });
        setCreateOpen(false);
        calendarRef.current?.getApi().refetchEvents();
    };

    const openCreateFromCtx = () => {
        if (ctxMenu) {
            const end = new Date(ctxMenu.date); end.setHours(end.getHours() + 1);
            setForm({ ...form, starts_at: toLocalISO(ctxMenu.date), ends_at: toLocalISO(end), title: '', description: '', location: '', provider_name: '', appointment_type: 'gp_visit', share_with_family: true });
        }
        setCtxMenu(null);
        setCreateOpen(true);
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Clients', href: '/clients' },
            { title: name, href: `/operations/clients/${client.id}` },
            { title: 'Calendar', href: `/operations/clients/${client.id}/calendar` },
        ]}>
            <Head title={`Calendar - ${name}`} />
            <style dangerouslySetInnerHTML={{ __html: calendarStyles }} />

            <PageShell>
                <div className="flex gap-6">
                    {/* Sidebar */}
                    <div className="hidden w-60 shrink-0 space-y-4 lg:block">
                        <Card className="overflow-hidden">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-semibold">{client.first_name}'s Calendar</CardTitle>
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
                                    <Link href={`/operations/clients/${client.id}`}><ArrowLeft className="h-4 w-4" />Back to Profile</Link>
                                </Button>
                                <Button variant="ghost" size="sm" className="w-full justify-start gap-2 text-sm" asChild>
                                    <Link href={`/operations/clients/${client.id}/visit-requests`}>
                                        <Users className="h-4 w-4" />Visit Requests
                                        {pending_visit_count > 0 && <span className="ml-auto rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700">{pending_visit_count}</span>}
                                    </Link>
                                </Button>
                                <Button variant="ghost" size="sm" className="w-full justify-start gap-2 text-sm" asChild>
                                    <Link href="/calendar"><Calendar className="h-4 w-4" />Team Calendar</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Main */}
                    <div className="min-w-0 flex-1">
                        <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-4">
                                <Button variant="outline" size="sm" className="gap-1.5" asChild>
                                    <Link href={`/operations/clients/${client.id}`}><ArrowLeft className="h-3.5 w-3.5" />Back</Link>
                                </Button>
                                <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
                                <div className="flex items-center">
                                    <button onClick={goPrev} className="inline-flex h-9 w-9 items-center justify-center rounded-full hover:bg-muted transition-colors"><ChevronLeft className="h-5 w-5" /></button>
                                    <button onClick={goNext} className="inline-flex h-9 w-9 items-center justify-center rounded-full hover:bg-muted transition-colors"><ChevronRight className="h-5 w-5" /></button>
                                </div>
                                <button onClick={goToday} className="rounded-full border px-5 py-1.5 text-sm font-semibold shadow-sm hover:bg-accent transition-colors">Today</button>
                            </div>
                            <div className="flex items-center gap-2">
                                <Button size="sm" className="gap-1.5" onClick={() => { setForm({ ...form, starts_at: toLocalISO(new Date()), ends_at: '', title: '' }); setCreateOpen(true); }}>
                                    <Plus className="h-3.5 w-3.5" />Schedule
                                </Button>
                                <div className="inline-flex items-center gap-1 rounded-full border p-1 bg-muted/20">
                                    {views.map((v) => (
                                        <button key={v.key} onClick={() => changeView(v.key)}
                                            className={`rounded-full px-4 py-1.5 text-sm font-semibold transition-all ${currentView === v.key ? 'bg-foreground text-background shadow' : 'text-muted-foreground hover:text-foreground'}`}>
                                            {v.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>

                        <div className="rounded-2xl border bg-card shadow-sm overflow-hidden" onContextMenu={handleRightClick}>
                            <FullCalendar
                                ref={calendarRef}
                                plugins={[dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin]}
                                initialView="timeGridWeek"
                                headerToolbar={false}
                                events={fetchEvents}
                                eventClick={handleEventClick}
                                datesSet={handleDatesSet}
                                select={handleSelect}
                                height="auto"
                                timeZone="local"
                                slotMinTime="05:00:00"
                                slotMaxTime="23:00:00"
                                allDaySlot={true}
                                nowIndicator={true}
                                eventContent={renderEventContent}
                                selectable={true}
                                selectMirror={true}
                                businessHours={{ daysOfWeek: [1, 2, 3, 4, 5], startTime: '08:00', endTime: '18:00' }}
                                slotDuration="00:30:00"
                                dayMaxEvents={3}
                                expandRows={true}
                                stickyHeaderDates={true}
                                firstDay={1}
                                eventTimeFormat={{ hour: '2-digit', minute: '2-digit', meridiem: false }}
                            />
                        </div>
                    </div>
                </div>

                {/* Context Menu */}
                {ctxMenu && (
                    <div className="calendar-context-menu" style={{ top: ctxMenu.y, left: ctxMenu.x }} onClick={(e) => e.stopPropagation()}>
                        <button onClick={openCreateFromCtx}><Plus className="h-4 w-4 text-primary" /><span>Schedule Appointment</span></button>
                        <hr />
                        <button onClick={() => { setCtxMenu(null); changeView('timeGridDay'); }}><Calendar className="h-4 w-4 text-muted-foreground" /><span>View Day</span></button>
                        <button onClick={() => { setCtxMenu(null); router.visit(`/operations/clients/${client.id}/visit-requests`); }}><Users className="h-4 w-4 text-muted-foreground" /><span>Visit Requests</span></button>
                    </div>
                )}

                {/* Event Detail */}
                {detail && (
                    <div className="mt-4">
                        <Card className="border-primary/20">
                            <CardContent className="p-4">
                                <div className="flex items-start justify-between">
                                    <div>
                                        <h3 className="text-sm font-semibold">{detail.title}</h3>
                                        <p className="mt-1 text-xs text-muted-foreground capitalize">{detail.type?.replace(/_/g, ' ')}{detail.appointment_type ? ` — ${detail.appointment_type.replace(/_/g, ' ')}` : ''}</p>
                                        {detail.start && <p className="mt-1 text-xs text-muted-foreground">{new Date(detail.start).toLocaleString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}{detail.end ? ` — ${new Date(detail.end).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })}` : ''}</p>}
                                        {detail.location && <p className="mt-1 text-xs"><MapPin className="inline h-3 w-3 mr-1" />{detail.location}</p>}
                                        {detail.provider_name && <p className="mt-0.5 text-xs"><Stethoscope className="inline h-3 w-3 mr-1" />{detail.provider_name}</p>}
                                        {detail.staff_name && <p className="mt-0.5 text-xs"><Users className="inline h-3 w-3 mr-1" />{detail.staff_name}</p>}
                                        {detail.description && <p className="mt-2 text-sm text-muted-foreground">{detail.description}</p>}
                                        {detail.notes && <p className="mt-2 text-sm text-muted-foreground">{detail.notes}</p>}
                                    </div>
                                    <Button size="sm" variant="ghost" onClick={() => setDetail(null)}>Close</Button>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* Create Appointment Dialog */}
                <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                    <DialogContent className="sm:max-w-lg">
                        <DialogHeader><DialogTitle>Schedule Appointment</DialogTitle></DialogHeader>
                        <div className="space-y-4 py-2">
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <Label>Title *</Label>
                                    <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="GP Visit - Dr. Patel" autoFocus />
                                </div>
                                <div>
                                    <Label>Type</Label>
                                    <Select value={form.appointment_type} onValueChange={(v) => setForm({ ...form, appointment_type: v })}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>{apptTypes.map(t => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}</SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div><Label>Start *</Label><Input type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} /></div>
                                <div><Label>End</Label><Input type="datetime-local" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} /></div>
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div><Label>Location</Label><Input value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} placeholder="Riverside Medical Centre" /></div>
                                <div><Label>Provider</Label><Input value={form.provider_name} onChange={(e) => setForm({ ...form, provider_name: e.target.value })} placeholder="Dr. Patel" /></div>
                            </div>
                            <div><Label>Notes</Label><Textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} rows={2} /></div>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={form.share_with_family} onCheckedChange={(v) => setForm({ ...form, share_with_family: !!v })} />
                                Share with family portal
                            </label>
                        </div>
                        <DialogFooter>
                            <Button variant="ghost" onClick={() => setCreateOpen(false)}>Cancel</Button>
                            <Button disabled={!form.title.trim() || !form.starts_at} onClick={submitAppointment}><Plus className="mr-2 h-4 w-4" />Create</Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </AppLayout>
    );
}
