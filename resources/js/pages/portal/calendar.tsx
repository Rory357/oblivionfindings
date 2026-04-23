import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { DatesSetArg, EventClickArg } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import listPlugin from '@fullcalendar/list';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';
import { Head, router, useForm } from '@inertiajs/react';
import {
    Calendar,
    CalendarDays,
    CalendarPlus,
    ChevronLeft,
    ChevronRight,
    Heart,
    MapPin,
    Stethoscope,
    Users,
    Video,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

type VisitRequest = {
    id: number;
    requested_date: string;
    preferred_time_start?: string | null;
    preferred_time_end?: string | null;
    visit_type: string;
    notes?: string | null;
    status: string;
    review_notes?: string | null;
};

type Props = {
    client: { id: number; first_name: string; last_name: string };
    visitRequests: VisitRequest[];
};

type ViewKey = 'dayGridMonth' | 'timeGridWeek' | 'timeGridDay' | 'listWeek';

const viewOptions: { key: ViewKey; label: string }[] = [
    { key: 'dayGridMonth', label: 'Month' },
    { key: 'timeGridWeek', label: 'Week' },
    { key: 'timeGridDay', label: 'Day' },
    { key: 'listWeek', label: 'List' },
];

const categories = [
    {
        dot: 'bg-blue-500',
        label: 'Support Visits',
        icon: CalendarDays,
        bg: 'bg-blue-50 dark:bg-blue-950/40',
    },
    {
        dot: 'bg-green-500',
        label: 'Family Visits',
        icon: Users,
        bg: 'bg-green-50 dark:bg-green-950/40',
    },
    {
        dot: 'bg-amber-500',
        label: 'GP Visits',
        icon: Stethoscope,
        bg: 'bg-amber-50 dark:bg-amber-950/40',
    },
    {
        dot: 'bg-primary',
        label: 'Specialist',
        icon: Heart,
        bg: 'bg-primary/10 dark:bg-primary/40',
    },
    {
        dot: 'bg-pink-500',
        label: 'Therapy',
        icon: Heart,
        bg: 'bg-pink-50 dark:bg-pink-950/40',
    },
    {
        dot: 'bg-cyan-500',
        label: 'Activities',
        icon: Calendar,
        bg: 'bg-cyan-50 dark:bg-cyan-950/40',
    },
];

const visitTypeLabels: Record<
    string,
    { label: string; icon: typeof Calendar }
> = {
    in_person: { label: 'In Person', icon: Users },
    video_call: { label: 'Video Call', icon: Video },
    outing: { label: 'Outing', icon: MapPin },
};

const statusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-emerald-100 text-emerald-800',
    declined: 'bg-red-100 text-red-800',
    cancelled: 'bg-muted text-muted-foreground',
};

const calendarStyles = `
.fc { --fc-border-color: transparent; --fc-today-bg-color: transparent; --fc-neutral-bg-color: transparent; --fc-page-bg-color: transparent; --fc-non-business-color: transparent; font-family: inherit; }
.fc .fc-scrollgrid, .fc .fc-scrollgrid-section > td, .fc .fc-scrollgrid-section > th { border: none !important; }
.fc table, .fc th, .fc td { border: none !important; }
.fc .fc-col-header { margin-bottom: 0.25rem; }
.fc .fc-col-header-cell { padding: 0.75rem 0; }
.fc .fc-col-header-cell-cushion { display: flex; flex-direction: column; align-items: center; gap: 4px; text-decoration: none !important; padding: 0.5rem 1rem; border-radius: 1rem; font-weight: 500; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; color: hsl(var(--muted-foreground) / 0.6); }
.fc .fc-day-today .fc-col-header-cell-cushion { background: hsl(var(--primary)); color: white !important; border-radius: 1rem; font-weight: 700; }
.fc .fc-timegrid-axis-cushion, .fc .fc-timegrid-slot-label-cushion { font-size: 0.7rem; font-weight: 500; color: hsl(var(--muted-foreground) / 0.45); padding-right: 0.75rem; }
.fc .fc-timegrid-slot { height: 3.5em; }
.fc .fc-timegrid-slot-lane { border-top: 1px dotted rgba(139, 92, 246, 0.12) !important; }
.fc .fc-timegrid-col { border-right: 1px dotted rgba(139, 92, 246, 0.1) !important; }
.fc .fc-timegrid-col:last-child { border-right: none !important; }
.fc .fc-timegrid-divider, .fc .fc-timegrid-axis, .fc .fc-timegrid-body { border: none !important; }
.fc .fc-timegrid-slots td, .fc .fc-timegrid-slot-label { border: none !important; }
.fc .fc-event, .fc .fc-event-mirror { border: none !important; border-radius: 0.625rem !important; cursor: pointer; transition: all 0.15s ease; }
.fc .fc-event:hover { transform: scale(1.01); z-index: 10 !important; box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
.fc .fc-timegrid-event { border-radius: 0.625rem !important; margin: 1px 2px; }
.fc .fc-timegrid-event .fc-event-main { padding: 0.375rem 0.5rem; }
.fc .fc-daygrid-event { border-radius: 0.5rem !important; padding: 2px 8px; margin: 1px 3px; }
.fc .fc-daygrid-body { border: none !important; }
.fc .fc-now-indicator-line { border-color: #ef4444 !important; border-width: 2px !important; }
.fc .fc-day-today { background: hsl(var(--primary) / 0.02) !important; }
.fc .fc-daygrid-day-number { font-weight: 700; font-size: 0.9rem; padding: 0.5rem; }
.fc .fc-day-today .fc-daygrid-day-number { background: hsl(var(--primary)); color: white; border-radius: 9999px; width: 2rem; height: 2rem; display: inline-flex; align-items: center; justify-content: center; margin: 0.25rem; }
.fc .fc-daygrid-day { border-right: 1px dotted rgba(139,92,246,0.1) !important; border-bottom: 1px dotted rgba(139,92,246,0.1) !important; }
.fc .fc-list { border: 1px solid hsl(var(--border) / 0.2) !important; border-radius: 1rem; overflow: hidden; }
.fc .fc-list-event:hover td { background-color: hsl(var(--accent)); }
.fc .fc-list-day-cushion { background: hsl(var(--muted) / 0.15); font-weight: 600; }
.calendar-context-menu { position: fixed; z-index: 50; min-width: 180px; background: hsl(var(--card)); border: 1px solid hsl(var(--border)); border-radius: 0.75rem; box-shadow: 0 8px 30px rgba(0,0,0,0.12); padding: 0.375rem; }
.calendar-context-menu button { display: flex; align-items: center; gap: 0.5rem; width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem; transition: background 0.1s; text-align: left; border: none; background: none; cursor: pointer; color: hsl(var(--foreground)); }
.calendar-context-menu button:hover { background: hsl(var(--accent)); }
.calendar-context-menu hr { margin: 0.25rem 0; border-color: hsl(var(--border)); }
`;

function renderEventContent(eventInfo: {
    event: any;
    view: any;
    timeText: string;
}) {
    const isTime = eventInfo.view.type.includes('timeGrid');
    const isDay = eventInfo.view.type === 'timeGridDay';
    const props = eventInfo.event.extendedProps;
    return (
        <div className="flex h-full flex-col overflow-hidden">
            <span
                className={`truncate leading-tight font-bold ${isDay ? 'text-sm' : 'text-xs'}`}
            >
                {eventInfo.event.title}
            </span>
            {isTime && (
                <span
                    className={`truncate opacity-70 ${isDay ? 'text-xs' : 'text-[10px]'}`}
                >
                    {eventInfo.timeText}
                </span>
            )}
            {isTime && props.location && (
                <span className="mt-auto flex items-center gap-0.5 truncate text-[10px] opacity-50">
                    <MapPin className="h-2.5 w-2.5 shrink-0" />
                    {props.location}
                </span>
            )}
        </div>
    );
}

export default function PortalCalendar({ client, visitRequests }: Props) {
    const clientName = `${client.first_name} ${client.last_name}`.trim();
    const calendarRef = useRef<FullCalendar>(null);
    const [currentView, setCurrentView] = useState<ViewKey>('timeGridWeek');
    const [calTitle, setCalTitle] = useState('');
    const [detail, setDetail] = useState<any>(null);
    const [bookingOpen, setBookingOpen] = useState(false);

    const form = useForm({
        requested_date: '',
        preferred_time_start: '',
        preferred_time_end: '',
        visit_type: 'in_person' as string,
        notes: '',
    });

    const goToday = useCallback(
        () => calendarRef.current?.getApi().today(),
        [],
    );
    const goPrev = useCallback(() => calendarRef.current?.getApi().prev(), []);
    const goNext = useCallback(() => calendarRef.current?.getApi().next(), []);
    const changeView = useCallback((v: ViewKey) => {
        calendarRef.current?.getApi().changeView(v);
        setCurrentView(v);
    }, []);
    const handleDatesSet = useCallback((arg: DatesSetArg) => {
        setCalTitle(arg.view.title);
        setCurrentView(arg.view.type as ViewKey);
    }, []);
    const handleEventClick = useCallback((info: EventClickArg) => {
        setDetail({
            title: info.event.title,
            start: info.event.start,
            end: info.event.end,
            ...info.event.extendedProps,
        });
    }, []);

    const [ctxMenu, setCtxMenu] = useState<{ x: number; y: number } | null>(
        null,
    );

    useEffect(() => {
        const close = () => setCtxMenu(null);
        document.addEventListener('click', close);
        return () => document.removeEventListener('click', close);
    }, []);

    const handleRightClick = useCallback((e: React.MouseEvent) => {
        const target = e.target as HTMLElement;
        if (
            !target.closest(
                '.fc-timegrid-slot-lane, .fc-daygrid-day, .fc-timegrid-col',
            )
        )
            return;
        e.preventDefault();
        setCtxMenu({ x: e.clientX, y: e.clientY });
    }, []);

    const fetchEvents = useCallback(
        async (info: any, successCallback: any, failureCallback: any) => {
            try {
                const params = new URLSearchParams({
                    start: info.startStr,
                    end: info.endStr,
                });
                const res = await fetch(
                    `/portal/clients/${client.id}/calendar/events?${params.toString()}`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    },
                );
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                successCallback(await res.json());
            } catch (e) {
                failureCallback(e);
            }
        },
        [client.id],
    );

    const submitVisit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/portal/clients/${client.id}/visit-requests`, {
            preserveScroll: true,
            onSuccess: () => {
                setBookingOpen(false);
                form.reset();
                toast.success('Visit request submitted!');
            },
            onError: () => toast.error('Please check the form and try again.'),
        });
    };

    const cancelVisit = (visitId: number) => {
        router.post(
            `/portal/clients/${client.id}/visit-requests/${visitId}/cancel`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Visit request cancelled.'),
            },
        );
    };

    const pendingCount = visitRequests.filter(
        (v) => v.status === 'pending',
    ).length;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                {
                    title: clientName,
                    href: `/portal/clients/${client.id}/dashboard`,
                },
                {
                    title: 'Calendar',
                    href: `/portal/clients/${client.id}/calendar`,
                },
            ]}
        >
            <Head title={`${clientName} - Calendar`} />
            <style dangerouslySetInnerHTML={{ __html: calendarStyles }} />

            <PageShell>
                <div className="flex gap-6">
                    {/* Sidebar */}
                    <div className="hidden w-60 shrink-0 space-y-4 lg:block">
                        <Card className="overflow-hidden">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-semibold">
                                    {client.first_name}'s Calendar
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-0.5 pb-4">
                                {categories.map((cat) => {
                                    const Icon = cat.icon;
                                    return (
                                        <div
                                            key={cat.label}
                                            className={`flex items-center gap-3 rounded-lg px-3 py-2 ${cat.bg}`}
                                        >
                                            <span
                                                className={`h-2.5 w-2.5 rounded-full ${cat.dot}`}
                                            />
                                            <Icon className="h-3.5 w-3.5 opacity-50" />
                                            <span className="text-sm font-medium">
                                                {cat.label}
                                            </span>
                                        </div>
                                    );
                                })}
                            </CardContent>
                        </Card>

                        {/* Visit Requests Summary */}
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center justify-between text-sm font-semibold">
                                    Your Visits
                                    {pendingCount > 0 && (
                                        <span className="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700">
                                            {pendingCount} pending
                                        </span>
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 pb-4">
                                {visitRequests.slice(0, 4).map((v) => {
                                    const vt =
                                        visitTypeLabels[v.visit_type] ??
                                        visitTypeLabels.in_person!;
                                    return (
                                        <div
                                            key={v.id}
                                            className="flex items-center justify-between rounded-lg border p-2"
                                        >
                                            <div className="min-w-0">
                                                <p className="text-xs font-medium">
                                                    {new Date(
                                                        v.requested_date +
                                                            'T00:00:00',
                                                    ).toLocaleDateString(
                                                        'en-NZ',
                                                        {
                                                            weekday: 'short',
                                                            day: 'numeric',
                                                            month: 'short',
                                                        },
                                                    )}
                                                </p>
                                                <p className="text-[10px] text-muted-foreground">
                                                    {vt.label}
                                                    {v.preferred_time_start
                                                        ? ` · ${v.preferred_time_start}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-1">
                                                <Badge
                                                    className={`border-0 text-[9px] ${statusColors[v.status]}`}
                                                >
                                                    {v.status}
                                                </Badge>
                                                {v.status === 'pending' && (
                                                    <button
                                                        onClick={() =>
                                                            cancelVisit(v.id)
                                                        }
                                                        className="text-muted-foreground hover:text-red-500"
                                                    >
                                                        <X className="h-3 w-3" />
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                                <Dialog
                                    open={bookingOpen}
                                    onOpenChange={setBookingOpen}
                                >
                                    <DialogTrigger asChild>
                                        <Button
                                            size="sm"
                                            className="mt-1 w-full gap-1.5"
                                        >
                                            <CalendarPlus className="h-3.5 w-3.5" />
                                            Request a Visit
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent className="sm:max-w-md">
                                        <DialogHeader>
                                            <DialogTitle>
                                                Request a Visit
                                            </DialogTitle>
                                            <DialogDescription>
                                                Submit a visit request to see{' '}
                                                {client.first_name}.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <form
                                            onSubmit={submitVisit}
                                            className="space-y-4"
                                        >
                                            <div>
                                                <Label>Date *</Label>
                                                <Input
                                                    type="date"
                                                    value={
                                                        form.data.requested_date
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'requested_date',
                                                            e.target.value,
                                                        )
                                                    }
                                                    min={
                                                        new Date()
                                                            .toISOString()
                                                            .split('T')[0]
                                                    }
                                                />
                                            </div>
                                            <div className="grid grid-cols-2 gap-3">
                                                <div>
                                                    <Label>From</Label>
                                                    <Input
                                                        type="time"
                                                        value={
                                                            form.data
                                                                .preferred_time_start
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'preferred_time_start',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <div>
                                                    <Label>To</Label>
                                                    <Input
                                                        type="time"
                                                        value={
                                                            form.data
                                                                .preferred_time_end
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'preferred_time_end',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </div>
                                            </div>
                                            <div>
                                                <Label>Visit Type *</Label>
                                                <div className="mt-2 grid grid-cols-3 gap-2">
                                                    {(
                                                        [
                                                            'in_person',
                                                            'video_call',
                                                            'outing',
                                                        ] as const
                                                    ).map((type) => {
                                                        const visitType =
                                                            visitTypeLabels[
                                                                type
                                                            ] ??
                                                            visitTypeLabels.in_person!;
                                                        const {
                                                            label,
                                                            icon: Icon,
                                                        } = visitType;
                                                        const selected =
                                                            form.data
                                                                .visit_type ===
                                                            type;
                                                        return (
                                                            <button
                                                                key={type}
                                                                type="button"
                                                                onClick={() =>
                                                                    form.setData(
                                                                        'visit_type',
                                                                        type,
                                                                    )
                                                                }
                                                                className={`flex flex-col items-center gap-1.5 rounded-lg border-2 p-3 text-xs font-medium transition-all ${selected ? 'border-primary bg-primary/5 text-primary' : 'border-border text-muted-foreground hover:border-primary/30'}`}
                                                            >
                                                                <Icon className="h-5 w-5" />
                                                                {label}
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                            <div>
                                                <Label>Notes</Label>
                                                <textarea
                                                    className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                                    rows={3}
                                                    value={form.data.notes}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'notes',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Any special requests..."
                                                />
                                            </div>
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setBookingOpen(false)
                                                    }
                                                >
                                                    Cancel
                                                </Button>
                                                <Button
                                                    type="submit"
                                                    disabled={
                                                        form.processing ||
                                                        !form.data
                                                            .requested_date
                                                    }
                                                >
                                                    Submit Request
                                                </Button>
                                            </div>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Main Calendar */}
                    <div className="min-w-0 flex-1">
                        <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-4">
                                <h1 className="text-2xl font-bold tracking-tight">
                                    {calTitle}
                                </h1>
                                <div className="flex items-center">
                                    <button
                                        onClick={goPrev}
                                        className="inline-flex h-9 w-9 items-center justify-center rounded-full transition-colors hover:bg-muted"
                                    >
                                        <ChevronLeft className="h-5 w-5" />
                                    </button>
                                    <button
                                        onClick={goNext}
                                        className="inline-flex h-9 w-9 items-center justify-center rounded-full transition-colors hover:bg-muted"
                                    >
                                        <ChevronRight className="h-5 w-5" />
                                    </button>
                                </div>
                                <button
                                    onClick={goToday}
                                    className="rounded-full border px-5 py-1.5 text-sm font-semibold shadow-sm transition-colors hover:bg-accent"
                                >
                                    Today
                                </button>
                            </div>
                            <div className="flex items-center gap-2">
                                <Button
                                    size="sm"
                                    className="gap-1.5 lg:hidden"
                                    onClick={() => setBookingOpen(true)}
                                >
                                    <CalendarPlus className="h-3.5 w-3.5" />
                                    Request Visit
                                </Button>
                                <div className="inline-flex items-center gap-1 rounded-full border bg-muted/20 p-1">
                                    {viewOptions.map((v) => (
                                        <button
                                            key={v.key}
                                            onClick={() => changeView(v.key)}
                                            className={`rounded-full px-4 py-1.5 text-sm font-semibold transition-all ${currentView === v.key ? 'bg-foreground text-background shadow' : 'text-muted-foreground hover:text-foreground'}`}
                                        >
                                            {v.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>

                        <div
                            className="overflow-hidden rounded-2xl border bg-card shadow-sm"
                            onContextMenu={handleRightClick}
                        >
                            <FullCalendar
                                ref={calendarRef}
                                plugins={[
                                    dayGridPlugin,
                                    timeGridPlugin,
                                    listPlugin,
                                ]}
                                initialView="timeGridWeek"
                                headerToolbar={false}
                                events={fetchEvents}
                                eventClick={handleEventClick}
                                datesSet={handleDatesSet}
                                height="auto"
                                timeZone="local"
                                slotMinTime="05:00:00"
                                slotMaxTime="23:00:00"
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
                                expandRows={true}
                                stickyHeaderDates={true}
                                firstDay={1}
                                eventTimeFormat={{
                                    hour: '2-digit',
                                    minute: '2-digit',
                                    meridiem: false,
                                }}
                            />
                        </div>

                        {/* Event Detail */}
                        {detail && (
                            <Card className="mt-4 border-primary/20">
                                <CardContent className="p-4">
                                    <div className="flex items-start justify-between">
                                        <div>
                                            <h3 className="text-sm font-semibold">
                                                {detail.title}
                                            </h3>
                                            <p className="mt-1 text-xs text-muted-foreground capitalize">
                                                {detail.type?.replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                                {detail.appointment_type
                                                    ? ` — ${detail.appointment_type.replace(/_/g, ' ')}`
                                                    : ''}
                                            </p>
                                            {detail.start && (
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {new Date(
                                                        detail.start,
                                                    ).toLocaleString('en-NZ', {
                                                        weekday: 'short',
                                                        day: 'numeric',
                                                        month: 'short',
                                                        hour: '2-digit',
                                                        minute: '2-digit',
                                                    })}
                                                    {detail.end
                                                        ? ` — ${new Date(detail.end).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })}`
                                                        : ''}
                                                </p>
                                            )}
                                            {detail.shift_type && (
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    Shift type:{' '}
                                                    {String(
                                                        detail.shift_type,
                                                    ).replace(/_/g, ' ')}
                                                </p>
                                            )}
                                            {detail.service_context && (
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    Service:{' '}
                                                    {detail.service_context}
                                                </p>
                                            )}
                                            {detail.location && (
                                                <p className="mt-1 text-xs">
                                                    <MapPin className="mr-1 inline h-3 w-3" />
                                                    {detail.location}
                                                </p>
                                            )}
                                            {detail.provider_name && (
                                                <p className="mt-0.5 text-xs">
                                                    <Stethoscope className="mr-1 inline h-3 w-3" />
                                                    {detail.provider_name}
                                                </p>
                                            )}
                                            {detail.staff_name && (
                                                <p className="mt-0.5 text-xs">
                                                    <Users className="mr-1 inline h-3 w-3" />
                                                    {detail.staff_name}
                                                </p>
                                            )}
                                            {(detail.is_sleepover ||
                                                detail.is_on_call ||
                                                detail.expected_break_minutes !=
                                                    null) && (
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {detail.is_sleepover
                                                        ? 'Sleepover'
                                                        : ''}
                                                    {detail.is_sleepover &&
                                                    detail.is_on_call
                                                        ? ' · '
                                                        : ''}
                                                    {detail.is_on_call
                                                        ? 'On-call'
                                                        : ''}
                                                    {(detail.is_sleepover ||
                                                        detail.is_on_call) &&
                                                    detail.expected_break_minutes !=
                                                        null
                                                        ? ' · '
                                                        : ''}
                                                    {detail.expected_break_minutes !=
                                                    null
                                                        ? `Break ${detail.expected_break_minutes} min`
                                                        : ''}
                                                </p>
                                            )}
                                            {detail.description && (
                                                <p className="mt-2 text-sm text-muted-foreground">
                                                    {detail.description}
                                                </p>
                                            )}
                                            {detail.notes && (
                                                <p className="mt-2 text-sm text-muted-foreground">
                                                    {detail.notes}
                                                </p>
                                            )}
                                        </div>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setDetail(null)}
                                        >
                                            Close
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>

                {/* Context Menu */}
                {ctxMenu && (
                    <div
                        className="calendar-context-menu"
                        style={{ top: ctxMenu.y, left: ctxMenu.x }}
                        onClick={(e) => e.stopPropagation()}
                    >
                        <button
                            onClick={() => {
                                setCtxMenu(null);
                                setBookingOpen(true);
                            }}
                        >
                            <CalendarPlus className="h-4 w-4 text-green-500" />
                            <span>Request a Visit</span>
                        </button>
                        <hr />
                        <button
                            onClick={() => {
                                setCtxMenu(null);
                                changeView('timeGridDay');
                            }}
                        >
                            <Calendar className="h-4 w-4 text-muted-foreground" />
                            <span>View Day</span>
                        </button>
                        <button
                            onClick={() => {
                                setCtxMenu(null);
                                changeView('listWeek');
                            }}
                        >
                            <CalendarDays className="h-4 w-4 text-muted-foreground" />
                            <span>View List</span>
                        </button>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
