import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router, useForm } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import { Plus } from 'lucide-react';
import { useState, useMemo } from 'react';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';

type BreadcrumbItem = { title: string; href: string };

interface CalendarEvent {
    id: number;
    title: string;
    description: string | null;
    event_type: string;
    starts_at: string;
    ends_at: string;
    is_all_day: boolean;
    location: string | null;
    department: string | null;
    site_id: number | null;
    site: { id: number; name: string } | null;
    creator: { id: number; name: string } | null;
}

interface LeaveEvent {
    id: string;
    title: string;
    start: string;
    end: string;
    allDay: boolean;
    event_type: string;
    color: string;
}

interface Site {
    id: number;
    name: string;
}

interface Props {
    events: CalendarEvent[];
    leaveEvents: LeaveEvent[];
    sites: Site[];
    filters: { start: string; end: string };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Calendar', href: '/hr/calendar' },
];

const eventTypeColors: Record<string, string> = {
    company: '#3b82f6',
    team: '#10b981',
    training: '#f59e0b',
    social: '#8b5cf6',
    holiday: '#ef4444',
    leave: '#94a3b8',
};

const eventTypeLabels: Record<string, string> = {
    company: 'Company',
    team: 'Team',
    training: 'Training',
    social: 'Social',
    holiday: 'Holiday',
    leave: 'Leave',
};

export default function CalendarIndex({ events, leaveEvents, sites, can }: Props) {
    const [showCreateDialog, setShowCreateDialog] = useState(false);
    const [selectedDate, setSelectedDate] = useState<string | null>(null);

    const form = useForm({
        title: '',
        description: '',
        event_type: 'company',
        starts_at: '',
        ends_at: '',
        is_all_day: false,
        location: '',
        department: '',
        site_id: '',
    });

    const calendarEvents = useMemo(() => {
        const mapped = events.map((e) => ({
            id: String(e.id),
            title: e.title,
            start: e.starts_at,
            end: e.ends_at,
            allDay: e.is_all_day,
            backgroundColor: eventTypeColors[e.event_type] ?? '#6b7280',
            borderColor: eventTypeColors[e.event_type] ?? '#6b7280',
            extendedProps: {
                event_type: e.event_type,
                location: e.location,
                department: e.department,
                description: e.description,
            },
        }));

        const leave = leaveEvents.map((e) => ({
            id: e.id,
            title: e.title,
            start: e.start,
            end: e.end,
            allDay: e.allDay,
            backgroundColor: eventTypeColors.leave,
            borderColor: eventTypeColors.leave,
            extendedProps: { event_type: 'leave' },
        }));

        return [...mapped, ...leave];
    }, [events, leaveEvents]);

    function handleDateClick(info: { dateStr: string }) {
        if (!can.manage) return;
        setSelectedDate(info.dateStr);
        form.setData({
            ...form.data,
            starts_at: info.dateStr + 'T09:00',
            ends_at: info.dateStr + 'T17:00',
            is_all_day: false,
        });
        setShowCreateDialog(true);
    }

    function handleDatesSet(info: { startStr: string; endStr: string }) {
        router.get(
            '/hr/calendar',
            { start: info.startStr.substring(0, 10), end: info.endStr.substring(0, 10) },
            { preserveState: true, preserveScroll: true, only: ['events', 'leaveEvents'] },
        );
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/hr/calendar', {
            preserveScroll: true,
            onSuccess: () => {
                setShowCreateDialog(false);
                form.reset();
            },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Company Calendar" />

            <PageShell>
                <PageHeader title="Company Calendar" description="View company events, training, and approved leave.">
                    {can.manage && (
                        <Button size="sm" onClick={() => setShowCreateDialog(true)}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            New Event
                        </Button>
                    )}
                </PageHeader>

                {/* Legend */}
                <div className="mb-4 flex flex-wrap items-center gap-3">
                    {Object.entries(eventTypeLabels).map(([key, label]) => (
                        <div key={key} className="flex items-center gap-1.5 text-xs">
                            <span
                                className="inline-block h-3 w-3 rounded-full"
                                style={{ backgroundColor: eventTypeColors[key] }}
                            />
                            {label}
                        </div>
                    ))}
                </div>

                <Card>
                    <CardContent className="p-4">
                        <FullCalendar
                            plugins={[dayGridPlugin, interactionPlugin]}
                            initialView="dayGridMonth"
                            events={calendarEvents}
                            dateClick={handleDateClick}
                            datesSet={handleDatesSet}
                            headerToolbar={{
                                left: 'prev,next today',
                                center: 'title',
                                right: 'dayGridMonth,dayGridWeek',
                            }}
                            height="auto"
                            eventDisplay="block"
                        />
                    </CardContent>
                </Card>

                {/* Create Event Dialog */}
                <Dialog open={showCreateDialog} onOpenChange={setShowCreateDialog}>
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Create Calendar Event</DialogTitle>
                        </DialogHeader>

                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <Label>Title</Label>
                                <Input
                                    value={form.data.title}
                                    onChange={(e) => form.setData('title', e.target.value)}
                                />
                                {form.errors.title && <p className="mt-1 text-xs text-status-critical">{form.errors.title}</p>}
                            </div>

                            <div>
                                <Label>Event Type</Label>
                                <Select
                                    value={form.data.event_type}
                                    onValueChange={(v) => form.setData('event_type', v)}
                                >
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="company">Company</SelectItem>
                                        <SelectItem value="team">Team</SelectItem>
                                        <SelectItem value="training">Training</SelectItem>
                                        <SelectItem value="social">Social</SelectItem>
                                        <SelectItem value="holiday">Holiday</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <Label>Starts At</Label>
                                    <Input
                                        type="datetime-local"
                                        value={form.data.starts_at}
                                        onChange={(e) => form.setData('starts_at', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <Label>Ends At</Label>
                                    <Input
                                        type="datetime-local"
                                        value={form.data.ends_at}
                                        onChange={(e) => form.setData('ends_at', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    id="is_all_day"
                                    checked={form.data.is_all_day}
                                    onChange={(e) => form.setData('is_all_day', e.target.checked)}
                                    className="rounded border-border"
                                />
                                <Label htmlFor="is_all_day">All Day Event</Label>
                            </div>

                            <div>
                                <Label>Description</Label>
                                <Textarea
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    rows={3}
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <Label>Location</Label>
                                    <Input
                                        value={form.data.location}
                                        onChange={(e) => form.setData('location', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <Label>Department</Label>
                                    <Input
                                        value={form.data.department}
                                        onChange={(e) => form.setData('department', e.target.value)}
                                    />
                                </div>
                            </div>

                            {sites.length > 0 && (
                                <div>
                                    <Label>Site</Label>
                                    <Select
                                        value={form.data.site_id}
                                        onValueChange={(v) => form.setData('site_id', v)}
                                    >
                                        <SelectTrigger><SelectValue placeholder="Select site (optional)" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="">None</SelectItem>
                                            {sites.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setShowCreateDialog(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={form.processing}>
                                    Create Event
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </AppLayout>
    );
}
