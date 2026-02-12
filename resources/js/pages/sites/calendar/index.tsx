import AppLayout from '@/layouts/app-layout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Calendar as CalendarIcon, Plus, ChevronLeft, ChevronRight } from 'lucide-react';
import { useState, useEffect, type FormEvent } from 'react';

type Site = {
    id: number;
    name: string;
    type: string;
};

type Event = {
    id: number;
    title: string;
    event_type: string;
    start_at: string;
    end_at?: string;
    status: string;
    approval_status: string;
};

type Props = {
    site: Site;
    canCreate: boolean;
};

function toLocalDateTimeInputValue(date: Date): string {
    const adjusted = new Date(date.getTime() - (date.getTimezoneOffset() * 60_000));
    return adjusted.toISOString().slice(0, 16);
}

function defaultStartAt(): string {
    const nextHour = new Date();
    nextHour.setMinutes(0, 0, 0);
    nextHour.setHours(nextHour.getHours() + 1);
    return toLocalDateTimeInputValue(nextHour);
}

function defaultEndAt(): string {
    const inTwoHours = new Date();
    inTwoHours.setMinutes(0, 0, 0);
    inTwoHours.setHours(inTwoHours.getHours() + 2);
    return toLocalDateTimeInputValue(inTwoHours);
}

export default function SiteCalendar({ site, canCreate }: Props) {
    const page = usePage();
    const [currentDate, setCurrentDate] = useState(new Date());
    const [events, setEvents] = useState<Event[]>([]);
    const [loading, setLoading] = useState(true);
    const [createOpen, setCreateOpen] = useState(false);

    const form = useForm({
        event_type: 'event',
        title: '',
        description: '',
        start_at: defaultStartAt(),
        end_at: defaultEndAt(),
    });

    useEffect(() => {
        fetchEvents();
    }, [currentDate]);

    useEffect(() => {
        if (!canCreate) return;

        const [, queryString = ''] = page.url.split('?');
        const params = new URLSearchParams(queryString);
        if (params.get('action') === 'add') {
            setCreateOpen(true);
        }
    }, [page.url, canCreate]);

    const fetchEvents = async () => {
        setLoading(true);
        const start = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1).toISOString();
        const end = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 0).toISOString();
        
        try {
            const response = await fetch(`/sites/${site.id}/calendar/events?start=${start}&end=${end}`);
            const data = await response.json();
            setEvents(data.events || []);
        } catch (error) {
            console.error('Failed to fetch events:', error);
        } finally {
            setLoading(false);
        }
    };

    const monthName = currentDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

    const daysInMonth = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 0).getDate();
    const firstDayOfMonth = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1).getDay();

    const prevMonth = () => setCurrentDate(new Date(currentDate.getFullYear(), currentDate.getMonth() - 1, 1));
    const nextMonth = () => setCurrentDate(new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 1));
    const openCreateDialog = () => setCreateOpen(true);

    const resetCreateForm = () => {
        form.reset();
        form.clearErrors();
        form.setData({
            event_type: 'event',
            title: '',
            description: '',
            start_at: defaultStartAt(),
            end_at: defaultEndAt(),
        });
    };

    const stripAddActionFromUrl = () => {
        if (typeof window === 'undefined') return;

        const url = new URL(window.location.href);
        if (url.searchParams.get('action') !== 'add') return;

        url.searchParams.delete('action');
        const nextUrl = `${url.pathname}${url.search ? `?${url.searchParams.toString()}` : ''}`;
        window.history.replaceState({}, '', nextUrl);
    };

    const handleCreateOpenChange = (open: boolean) => {
        setCreateOpen(open);
        if (!open) {
            stripAddActionFromUrl();
        }
    };

    const handleCreateSubmit = (e: FormEvent) => {
        e.preventDefault();

        form.post(`/sites/${site.id}/calendar/events`, {
            preserveScroll: true,
            onSuccess: () => {
                handleCreateOpenChange(false);
                resetCreateForm();
                fetchEvents();
            },
        });
    };

    const getEventsForDay = (day: number) => {
        const dateStr = new Date(currentDate.getFullYear(), currentDate.getMonth(), day).toDateString();
        return events.filter(e => new Date(e.start_at).toDateString() === dateStr);
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: site.name, href: `/sites/${site.id}` }, { title: 'Calendar', href: `/sites/${site.id}/calendar` }]}>
            <Head title={`${site.name} - Calendar`} />

            <div className="m-4 space-y-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <CalendarIcon className="w-5 h-5" />
                            Site Calendar
                        </h1>
                        <p className="text-sm text-slate-400">{site.name}</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" onClick={prevMonth}>
                            <ChevronLeft className="w-4 h-4" />
                        </Button>
                        <span className="font-medium min-w-[140px] text-center">{monthName}</span>
                        <Button variant="outline" size="sm" onClick={nextMonth}>
                            <ChevronRight className="w-4 h-4" />
                        </Button>
                        {canCreate && (
                            <Button className="ml-4" onClick={openCreateDialog}>
                                <Plus className="w-4 h-4 mr-1" />
                                Add Event
                            </Button>
                        )}
                    </div>
                </div>

                <Card>
                    <CardContent className="p-4">
                        {/* Calendar Grid */}
                        <div className="grid grid-cols-7 gap-1">
                            {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(day => (
                                <div key={day} className="text-center text-sm font-medium text-slate-400 py-2">
                                    {day}
                                </div>
                            ))}
                            
                            {Array.from({ length: firstDayOfMonth }).map((_, i) => (
                                <div key={`empty-${i}`} className="min-h-[100px]" />
                            ))}
                            
                            {Array.from({ length: daysInMonth }).map((_, i) => {
                                const day = i + 1;
                                const dayEvents = getEventsForDay(day);
                                const isToday = new Date().toDateString() === new Date(currentDate.getFullYear(), currentDate.getMonth(), day).toDateString();
                                
                                return (
                                    <div
                                        key={day}
                                        className={`min-h-[100px] border rounded-lg p-2 ${
                                            isToday ? 'bg-indigo-500/10 border-indigo-500/30' : 'border'
                                        }`}
                                    >
                                        <div className={`text-sm font-medium mb-1 ${isToday ? 'text-indigo-400' : ''}`}>
                                            {day}
                                        </div>
                                        <div className="space-y-1">
                                            {dayEvents.slice(0, 3).map(event => (
                                                <div
                                                    key={event.id}
                                                    className={`text-xs p-1 rounded truncate ${
                                                        event.event_type === 'maintenance' ? 'bg-amber-500/20 text-amber-300' :
                                                        event.event_type === 'inspection' ? 'bg-purple-500/20 text-purple-300' :
                                                        event.event_type === 'site_visit' ? 'bg-emerald-500/20 text-emerald-300' :
                                                        'bg-slate-700 text-slate-300'
                                                    }`}
                                                >
                                                    {event.title}
                                                </div>
                                            ))}
                                            {dayEvents.length > 3 && (
                                                <div className="text-xs text-slate-500">+{dayEvents.length - 3} more</div>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {/* Event Type Legend */}
                <div className="flex flex-wrap gap-3 text-sm">
                    <div className="flex items-center gap-1">
                        <div className="w-3 h-3 rounded bg-amber-500/30" />
                        <span className="text-slate-400">Maintenance</span>
                    </div>
                    <div className="flex items-center gap-1">
                        <div className="w-3 h-3 rounded bg-purple-500/30" />
                        <span className="text-slate-400">Inspection</span>
                    </div>
                    <div className="flex items-center gap-1">
                        <div className="w-3 h-3 rounded bg-emerald-500/30" />
                        <span className="text-slate-400">Site Visit</span>
                    </div>
                    <div className="flex items-center gap-1">
                        <div className="w-3 h-3 rounded bg-blue-500/30" />
                        <span className="text-slate-400">Contractor</span>
                    </div>
                </div>

                {loading && (
                    <div className="text-center py-4 text-slate-400">
                        Loading events...
                    </div>
                )}
            </div>

            <Dialog open={createOpen} onOpenChange={handleCreateOpenChange}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add Event</DialogTitle>
                        <DialogDescription>
                            Create a calendar event for {site.name}.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleCreateSubmit} className="space-y-4">
                        <div>
                            <Label htmlFor="event_title">Title</Label>
                            <Input
                                id="event_title"
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                placeholder="Event title"
                                required
                            />
                            {form.errors.title && <p className="text-sm text-red-500 mt-1">{form.errors.title}</p>}
                        </div>

                        <div>
                            <Label htmlFor="event_type">Event Type</Label>
                            <select
                                id="event_type"
                                value={form.data.event_type}
                                onChange={(e) => form.setData('event_type', e.target.value)}
                                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                            >
                                <option value="event">Event</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="inspection">Inspection</option>
                                <option value="site_visit">Site Visit</option>
                                <option value="contractor">Contractor</option>
                            </select>
                            {form.errors.event_type && <p className="text-sm text-red-500 mt-1">{form.errors.event_type}</p>}
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="event_start_at">Start</Label>
                                <Input
                                    id="event_start_at"
                                    type="datetime-local"
                                    value={form.data.start_at}
                                    onChange={(e) => form.setData('start_at', e.target.value)}
                                    required
                                />
                                {form.errors.start_at && <p className="text-sm text-red-500 mt-1">{form.errors.start_at}</p>}
                            </div>
                            <div>
                                <Label htmlFor="event_end_at">End</Label>
                                <Input
                                    id="event_end_at"
                                    type="datetime-local"
                                    value={form.data.end_at}
                                    onChange={(e) => form.setData('end_at', e.target.value)}
                                />
                                {form.errors.end_at && <p className="text-sm text-red-500 mt-1">{form.errors.end_at}</p>}
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="event_description">Description</Label>
                            <Textarea
                                id="event_description"
                                rows={3}
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="Optional details"
                            />
                            {form.errors.description && <p className="text-sm text-red-500 mt-1">{form.errors.description}</p>}
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => handleCreateOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Saving...' : 'Create Event'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
