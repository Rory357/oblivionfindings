import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { CalendarDays, Plus, Filter, List, Calendar } from 'lucide-react';
import { useState, useMemo } from 'react';

type Site = {
    id: number;
    name: string;
    type: 'head_office' | 'house' | 'facility';
};

type CalendarEvent = {
    id: number;
    site_id: number;
    site_name: string;
    site_type: string;
    event_type: string;
    title: string;
    start_at: string;
    end_at: string | null;
    status: string;
    owner_name?: string;
};

type Props = {
    sites: Site[];
    events: CalendarEvent[];
    filters: {
        site_ids?: number[];
        site_type?: string;
        event_types?: string[];
        status?: string;
        my_events_only?: boolean;
    };
    eventTypes: Array<{ key: string; label: string; color: string }>;
};

const typeColors: Record<string, string> = {
    head_office: 'bg-status-info-bg text-status-info border-status-info/30',
    house: 'bg-status-success-bg text-status-success border-status-success/30',
    facility: 'bg-status-warning-bg text-status-warning border-status-warning/30',
};

type CalendarViewProps = {
    currentDate: Date;
    setCurrentDate: (date: Date) => void;
    events: CalendarEvent[];
    getEventTypeColor: (type: string) => string;
    typeColors: Record<string, string>;
};

function CalendarView({ currentDate, setCurrentDate, events, getEventTypeColor, typeColors }: CalendarViewProps) {
    const monthName = currentDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    const daysInMonth = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 0).getDate();
    const firstDayOfMonth = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1).getDay();

    const prevMonth = () => setCurrentDate(new Date(currentDate.getFullYear(), currentDate.getMonth() - 1, 1));
    const nextMonth = () => setCurrentDate(new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 1));

    const getEventsForDay = (day: number) => {
        const dateStr = new Date(currentDate.getFullYear(), currentDate.getMonth(), day).toDateString();
        return events.filter(e => new Date(e.start_at).toDateString() === dateStr);
    };

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between mb-4">
                <Button variant="outline" size="sm" onClick={prevMonth}>
                    Previous
                </Button>
                <span className="font-medium">{monthName}</span>
                <Button variant="outline" size="sm" onClick={nextMonth}>
                    Next
                </Button>
            </div>

            <div className="grid grid-cols-7 gap-1">
                {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(day => (
                    <div key={day} className="text-center text-sm font-medium text-muted-foreground py-2">
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
                                isToday ? 'bg-primary/10 border-primary/30' : 'border-border'
                            }`}
                        >
                            <div className={`text-sm font-medium mb-1 ${isToday ? 'text-primary' : 'text-muted-foreground'}`}>
                                {day}
                            </div>
                            <div className="space-y-1">
                                {dayEvents.slice(0, 3).map(event => (
                                    <div
                                        key={event.id}
                                        className="text-xs p-1 rounded truncate text-white"
                                        style={{ backgroundColor: getEventTypeColor(event.event_type) }}
                                        title={event.title}
                                    >
                                        {event.title}
                                    </div>
                                ))}
                                {dayEvents.length > 3 && (
                                    <div className="text-xs text-muted-foreground">+{dayEvents.length - 3} more</div>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

export default function GlobalCalendar({ sites, events, filters, eventTypes }: Props) {
    const [viewMode, setViewMode] = useState<'list' | 'calendar'>('list');
    const [selectedSites, setSelectedSites] = useState<number[]>(filters.site_ids || []);
    const [siteTypeFilter, setSiteTypeFilter] = useState<string>(filters.site_type || 'all');
    const [typeFilter, setTypeFilter] = useState<string>((filters.event_types?.[0]) || 'all');
    const [statusFilter, setStatusFilter] = useState<string>(filters.status || 'all');
    const [myEventsOnly, setMyEventsOnly] = useState<boolean>(filters.my_events_only || false);
    const [currentDate, setCurrentDate] = useState(new Date());

    const filteredEvents = useMemo(() => {
        return events.filter(event => {
            if (selectedSites.length > 0 && !selectedSites.includes(event.site_id)) return false;
            if (siteTypeFilter !== 'all' && event.site_type !== siteTypeFilter) return false;
            if (typeFilter !== 'all' && event.event_type !== typeFilter) return false;
            if (statusFilter !== 'all' && event.status !== statusFilter) return false;
            return true;
        });
    }, [events, selectedSites, siteTypeFilter, typeFilter, statusFilter]);

    const getEventTypeColor = (type: string) => {
        const eventType = eventTypes.find(t => t.key === type);
        return eventType?.color || '#6366f1';
    };

    const getEventTypeLabel = (type: string) => {
        const eventType = eventTypes.find(t => t.key === type);
        return eventType?.label || type;
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Calendar', href: '/calendar' }]}>
            <Head title="Global Calendar" />

            <div className="m-4 space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <CalendarDays className="w-5 h-5" />
                            Global Calendar
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            All sites and events in one view
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/calendar?action=add">
                            <Plus className="w-4 h-4 mr-1" />
                            New Event
                        </Link>
                    </Button>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm flex items-center gap-2">
                            <Filter className="w-4 h-4" />
                            Filters
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <Label className="text-xs">Sites</Label>
                                    <div className="border rounded-md p-2 max-h-[200px] overflow-y-auto space-y-2">
                                        {sites.length === 0 ? (
                                            <p className="text-xs text-muted-foreground">No sites available</p>
                                        ) : (
                                            sites.map(site => (
                                                <label key={site.id} className="flex items-center gap-2 text-xs cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedSites.includes(site.id)}
                                                        onChange={(e) => {
                                                            if (e.target.checked) {
                                                                setSelectedSites([...selectedSites, site.id]);
                                                            } else {
                                                                setSelectedSites(selectedSites.filter(id => id !== site.id));
                                                            }
                                                        }}
                                                        className="rounded"
                                                    />
                                                    <span>{site.name}</span>
                                                </label>
                                            ))
                                        )}
                                    </div>
                                </div>
                                <div>
                                    <Label className="text-xs">Site Type</Label>
                                    <Select value={siteTypeFilter} onValueChange={setSiteTypeFilter}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Types</SelectItem>
                                            <SelectItem value="head_office">Head Office</SelectItem>
                                            <SelectItem value="house">Houses</SelectItem>
                                            <SelectItem value="facility">Facilities</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label className="text-xs">Event Type</Label>
                                    <Select value={typeFilter} onValueChange={setTypeFilter}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Types</SelectItem>
                                            {eventTypes.map(type => (
                                                <SelectItem key={type.key} value={type.key}>
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label className="text-xs">Status</Label>
                                    <Select value={statusFilter} onValueChange={setStatusFilter}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Status</SelectItem>
                                            <SelectItem value="draft">Draft</SelectItem>
                                            <SelectItem value="pending">Pending</SelectItem>
                                            <SelectItem value="approved">Approved</SelectItem>
                                            <SelectItem value="completed">Completed</SelectItem>
                                            <SelectItem value="cancelled">Cancelled</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="flex items-center justify-between pt-2 border-t">
                                <div className="flex items-center gap-2">
                                    <Switch
                                        checked={myEventsOnly}
                                        onCheckedChange={setMyEventsOnly}
                                    />
                                    <Label className="text-xs cursor-pointer">Show only my events</Label>
                                </div>

                                <div className="flex gap-1">
                                    <Button
                                        variant={viewMode === 'list' ? 'default' : 'outline'}
                                        size="sm"
                                        onClick={() => setViewMode('list')}
                                        title="List view"
                                    >
                                        <List className="w-4 h-4" />
                                    </Button>
                                    <Button
                                        variant={viewMode === 'calendar' ? 'default' : 'outline'}
                                        size="sm"
                                        onClick={() => setViewMode('calendar')}
                                        title="Calendar view"
                                    >
                                        <Calendar className="w-4 h-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Events Display */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Events ({filteredEvents.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {filteredEvents.length === 0 ? (
                            <div className="text-center py-8 text-muted-foreground">
                                <CalendarDays className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p>No events match your filters</p>
                            </div>
                        ) : viewMode === 'list' ? (
                            <div className="space-y-2">
                                {filteredEvents.sort((a, b) => new Date(a.start_at).getTime() - new Date(b.start_at).getTime()).map(event => (
                                    <div
                                        key={event.id}
                                        className="flex items-start justify-between p-3 rounded-lg border hover:bg-muted-foreground/80/10 transition-colors"
                                    >
                                        <div className="flex items-start gap-3 flex-1">
                                            <div
                                                className="w-3 h-3 rounded-full mt-1 flex-shrink-0"
                                                style={{ backgroundColor: getEventTypeColor(event.event_type) }}
                                            />
                                            <div className="flex-1">
                                                <div className="font-medium">{event.title}</div>
                                                <div className="text-sm text-muted-foreground">
                                                    {event.site_name} - {getEventTypeLabel(event.event_type)}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {new Date(event.start_at).toLocaleString()}
                                                    {event.end_at && ` - ${new Date(event.end_at).toLocaleTimeString()}`}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2 ml-4">
                                            <Badge variant="outline" className={typeColors[event.site_type]}>
                                                {event.site_type === 'head_office' ? 'Head Office' :
                                                 event.site_type === 'house' ? 'House' : 'Facilities'}
                                            </Badge>
                                            <Badge variant="outline">{event.status}</Badge>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <CalendarView currentDate={currentDate} setCurrentDate={setCurrentDate} events={filteredEvents} getEventTypeColor={getEventTypeColor} typeColors={typeColors} />
                        )}
                    </CardContent>
                </Card>

                {/* Quick Links */}
                <div className="grid gap-4 sm:grid-cols-3">
                    {sites.slice(0, 6).map(site => (
                        <Button
                            key={site.id}
                            asChild
                            variant="outline"
                            className="justify-start"
                        >
                            <Link href={`/sites/${site.id}/calendar`}>
                                <CalendarDays className="w-4 h-4 mr-2" />
                                {site.name}
                            </Link>
                        </Button>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
