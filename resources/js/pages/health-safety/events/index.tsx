import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { useState, useCallback, useRef } from 'react';
import { Shield, AlertTriangle, Search, ChevronRight, Clock, ShieldAlert } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Health & Safety', href: '/health-safety' },
    { title: 'Events', href: '/health-safety/events' },
];

const CATEGORY_LABELS: Record<string, string> = {
    incident: 'Incident',
    near_miss: 'Near Miss',
    hazard: 'Hazard',
    injury: 'Injury',
    exposure: 'Exposure',
    restraint: 'Restraint',
    safeguarding: 'Safeguarding',
    vehicle_incident: 'Vehicle Incident',
    drill_failure: 'Drill Failure',
    inspection_failure: 'Inspection Failure',
    equipment_fault: 'Equipment Fault',
};

interface HsEventRow {
    id: number;
    reference_number: string;
    event_category: string;
    severity: string;
    status: string;
    occurred_at: string | null;
    reported_at: string | null;
    site_name: string | null;
    client_name: string | null;
    staff_name: string | null;
    worksafe_notifiable: boolean;
    investigation_required: boolean;
    has_investigation: boolean;
    has_open_actions: boolean;
}

interface Props {
    events: {
        data: HsEventRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: {
        status?: string | null;
        severity?: string | null;
        category?: string | null;
    };
}

export default function HsEventsIndex({ events, filters }: Props) {
    const [search, setSearch] = useState('');
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const applyFilter = useCallback((key: string, value: string | null) => {
        router.get('/health-safety/events', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }, [filters]);

    const formatDate = (iso: string | null) => {
        if (!iso) return '-';
        return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="H&S Events" />
            <div className="mx-auto max-w-[1400px] space-y-6">

                {/* Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-600 text-white">
                            <Shield className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">H&S Events</h1>
                            <p className="text-sm text-muted-foreground">{events.total} event{events.total !== 1 ? 's' : ''} recorded</p>
                        </div>
                    </div>
                </div>

                {/* Filters */}
                <Card>
                    <CardContent className="flex flex-wrap items-center gap-3 py-4">
                        <div className="relative w-64">
                            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Search events..."
                                className="pl-9"
                                value={search}
                                onChange={e => {
                                    setSearch(e.target.value);
                                    if (timer.current) clearTimeout(timer.current);
                                    timer.current = setTimeout(() => applyFilter('q', e.target.value || null), 300);
                                }}
                            />
                        </div>
                        <Select value={filters.status ?? '__none__'} onValueChange={v => applyFilter('status', v === '__none__' ? null : v)}>
                            <SelectTrigger className="w-44"><SelectValue placeholder="All statuses" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">All statuses</SelectItem>
                                <SelectItem value="open">Open</SelectItem>
                                <SelectItem value="investigating">Investigating</SelectItem>
                                <SelectItem value="corrective_action">Corrective Action</SelectItem>
                                <SelectItem value="monitoring">Monitoring</SelectItem>
                                <SelectItem value="closed">Closed</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select value={filters.severity ?? '__none__'} onValueChange={v => applyFilter('severity', v === '__none__' ? null : v)}>
                            <SelectTrigger className="w-36"><SelectValue placeholder="All severity" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">All severity</SelectItem>
                                <SelectItem value="critical">Critical</SelectItem>
                                <SelectItem value="high">High</SelectItem>
                                <SelectItem value="medium">Medium</SelectItem>
                                <SelectItem value="low">Low</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select value={filters.category ?? '__none__'} onValueChange={v => applyFilter('category', v === '__none__' ? null : v)}>
                            <SelectTrigger className="w-48"><SelectValue placeholder="All categories" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">All categories</SelectItem>
                                {Object.entries(CATEGORY_LABELS).map(([k, v]) => (
                                    <SelectItem key={k} value={k}>{v}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Reference</th>
                                    <th className="px-4 py-3 text-left font-medium">Category</th>
                                    <th className="px-4 py-3 text-left font-medium">Severity</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-left font-medium">Site / Client</th>
                                    <th className="px-4 py-3 text-left font-medium">Reported</th>
                                    <th className="px-4 py-3 text-left font-medium">Flags</th>
                                    <th className="w-10" />
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {events.data.map(event => (
                                    <tr key={event.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <Link href={`/health-safety/events/${event.id}`} className="font-medium text-blue-600 hover:underline">
                                                {event.reference_number}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {CATEGORY_LABELS[event.event_category] ?? event.event_category}
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={event.severity} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={event.status} />
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {event.site_name ?? event.client_name ?? '-'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {formatDate(event.reported_at)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex gap-1.5">
                                                {event.worksafe_notifiable && (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 border border-red-200" title="WorkSafe Notifiable">
                                                        <ShieldAlert className="h-3 w-3" /> WorkSafe
                                                    </span>
                                                )}
                                                {event.has_open_actions && (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 border border-amber-200" title="Has open corrective actions">
                                                        <Clock className="h-3 w-3" /> Actions
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link href={`/health-safety/events/${event.id}`}>
                                                <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                                {events.data.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-12 text-center text-muted-foreground">
                                            <Shield className="mx-auto mb-3 h-12 w-12 opacity-30" />
                                            <p className="text-base font-medium">No events found</p>
                                            <p className="mt-1 text-sm">Adjust your filters or check back later.</p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {events.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {events.from}–{events.to} of {events.total} events
                        </p>
                        <LaravelPagination links={events.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
