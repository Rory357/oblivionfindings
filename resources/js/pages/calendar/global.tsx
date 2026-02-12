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
import { CalendarDays, Plus, Filter, List, Grid3X3 } from 'lucide-react';
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
    head_office: 'bg-blue-500/10 text-blue-400 border-blue-500/30',
    house: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
    facility: 'bg-amber-500/10 text-amber-400 border-amber-500/30',
};

export default function GlobalCalendar({ sites, events, filters, eventTypes }: Props) {
    const [viewMode, setViewMode] = useState<'list' | 'grid'>('list');
    const [siteFilter, setSiteFilter] = useState<string>(filters.site_type || 'all');
    const [typeFilter, setTypeFilter] = useState<string>((filters.event_types?.[0]) || 'all');
    const [statusFilter, setStatusFilter] = useState<string>(filters.status || 'all');

    const filteredEvents = useMemo(() => {
        return events.filter(event => {
            if (siteFilter !== 'all' && event.site_type !== siteFilter) return false;
            if (typeFilter !== 'all' && event.event_type !== typeFilter) return false;
            if (statusFilter !== 'all' && event.status !== statusFilter) return false;
            return true;
        });
    }, [events, siteFilter, typeFilter, statusFilter]);

    const getEventTypeColor = (type: string) => {
        const eventType = eventTypes.find(t => t.key === type);
        return eventType?.color || '#6366f1';
    };

    const getEventTypeLabel = (type: string) => {
        const eventType = eventTypes.find(t => t.key === type);
        return eventType?.label || type;
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Calendar', href: '/sites/calendar' }]}>
            <Head title="Global Calendar" />

            <div className="m-4 space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <CalendarDays className="w-5 h-5" />
                            Global Calendar
                        </h1>
                        <p className="text-sm text-slate-400">
                            All sites and events in one view
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/sites">
                            <Plus className="w-4 h-4 mr-1" />
                            Add Event
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
                        <div className="grid gap-4 sm:grid-cols-4">
                            <div>
                                <Label className="text-xs">Site Type</Label>
                                <Select value={siteFilter} onValueChange={setSiteFilter}>
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
                            <div className="flex items-end">
                                <div className="flex gap-1">
                                    <Button
                                        variant={viewMode === 'list' ? 'default' : 'outline'}
                                        size="sm"
                                        onClick={() => setViewMode('list')}
                                    >
                                        <List className="w-4 h-4" />
                                    </Button>
                                    <Button
                                        variant={viewMode === 'grid' ? 'default' : 'outline'}
                                        size="sm"
                                        onClick={() => setViewMode('grid')}
                                    >
                                        <Grid3X3 className="w-4 h-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Events List */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Upcoming Events ({filteredEvents.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {filteredEvents.length === 0 ? (
                            <div className="text-center py-8 text-slate-400">
                                <CalendarDays className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p>No events match your filters</p>
                            </div>
                        ) : viewMode === 'list' ? (
                            <div className="space-y-2">
                                {filteredEvents.map(event => (
                                    <div
                                        key={event.id}
                                        className="flex items-center justify-between p-3 rounded-lg border hover:bg-muted/50"
                                    >
                                        <div className="flex items-center gap-3">
                                            <div
                                                className="w-3 h-3 rounded-full"
                                                style={{ backgroundColor: getEventTypeColor(event.event_type) }}
                                            />
                                            <div>
                                                <div className="font-medium">{event.title}</div>
                                                <div className="text-sm text-slate-400">
                                                    {event.site_name} - {getEventTypeLabel(event.event_type)}
                                                </div>
                                                <div className="text-xs text-slate-500">
                                                    {new Date(event.start_at).toLocaleString()}
                                                    {event.end_at && ` - ${new Date(event.end_at).toLocaleTimeString()}`}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge variant="outline" className={typeColors[event.site_type]}>
                                                {event.site_type === 'head_office' ? 'Head Office' : 
                                                 event.site_type === 'house' ? 'House' : 'Facilities'}
                                            </Badge>
                                            <Badge variant="outline">{event.status}</Badge>
                                            <Button asChild variant="ghost" size="sm">
                                                <Link href={`/sites/${event.site_id}/calendar`}>
                                                    View
                                                </Link>
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {filteredEvents.map(event => (
                                    <Card key={event.id} className="hover:bg-muted/50 transition-colors">
                                        <CardContent className="p-4">
                                            <div
                                                className="w-full h-1 rounded-full mb-3"
                                                style={{ backgroundColor: getEventTypeColor(event.event_type) }}
                                            />
                                            <div className="font-medium mb-1">{event.title}</div>
                                            <div className="text-sm text-slate-400 mb-2">{event.site_name}</div>
                                            <div className="text-xs text-slate-500 mb-3">
                                                {new Date(event.start_at).toLocaleString()}
                                            </div>
                                            <div className="flex gap-1">
                                                <Badge variant="outline" className="text-xs">
                                                    {getEventTypeLabel(event.event_type)}
                                                </Badge>
                                                <Badge variant="outline" className="text-xs">{event.status}</Badge>
                                            </div>
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
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
