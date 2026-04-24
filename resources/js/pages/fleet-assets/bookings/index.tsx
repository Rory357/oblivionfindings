import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import { FLEET_COLORS } from '@/components/fleet-charts';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Calendar, CalendarDays, Car, CheckCircle, ChevronLeft, ChevronRight,
    ClipboardList, Clock, Download, List, Plus, User,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatDate as formatDateStr } from '@/lib/fleet-utils';

type Booking = {
    id: number;
    asset: { id: number; name: string; asset_tag?: string } | null;
    user: { id: number; name: string } | null;
    purpose: string;
    starts_at: string | null;
    ends_at: string | null;
    status: string;
    created_at: string | null;
};

type Vehicle = { id: number; name: string; asset_tag?: string };

type Props = {
    bookings: {
        data: Booking[];
        meta?: { current_page: number; last_page: number; total: number };
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        status?: string; asset_id?: string; date_from?: string; date_to?: string;
        view?: string; week_start?: string;
    };
    vehicles?: Vehicle[];
    calendar_bookings?: Booking[];
    week_start?: string;
};

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'approved': return 'default';
        case 'pending': return 'outline';
        case 'checked_out': return 'default';
        case 'returned': return 'secondary';
        case 'rejected': return 'destructive';
        case 'cancelled': return 'secondary';
        default: return 'outline';
    }
}

const STATUS_COLORS: Record<string, string> = {
    pending: '#eab308', approved: '#3b82f6', checked_out: '#22c55e',
    returned: '#9ca3af', rejected: '#ef4444', cancelled: '#d1d5db',
};

function getMonday(d: Date): Date {
    const date = new Date(d); const day = date.getDay();
    const diff = date.getDate() - day + (day === 0 ? -6 : 1);
    date.setDate(diff); date.setHours(0, 0, 0, 0); return date;
}
function addDays(d: Date, n: number): Date { const date = new Date(d); date.setDate(date.getDate() + n); return date; }
function formatDate(d: Date): string { return d.toISOString().split('T')[0]; }
function formatShortDay(d: Date): string { return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }); }
function isSameDay(a: Date, b: Date): boolean { return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); }

function BookingCalendar({ bookings, vehicles, weekStart, onWeekChange }: {
    bookings: Booking[]; vehicles: Vehicle[]; weekStart: Date; onWeekChange: (s: string) => void;
}) {
    const days = useMemo(() => Array.from({ length: 7 }, (_, i) => addDays(weekStart, i)), [weekStart]);
    const today = new Date(); today.setHours(0, 0, 0, 0);

    const vehicleBookings = useMemo(() => {
        const map: Record<number, Array<{ booking: Booking; startCol: number; spanCols: number }>> = {};
        for (const v of vehicles) map[v.id] = [];
        for (const b of bookings) {
            if (!b.asset?.id || !b.starts_at || !b.ends_at) continue;
            const vId = b.asset.id; if (!map[vId]) map[vId] = [];
            const bStart = new Date(b.starts_at); bStart.setHours(0, 0, 0, 0);
            const bEnd = new Date(b.ends_at); bEnd.setHours(0, 0, 0, 0);
            const weekEnd = addDays(weekStart, 6);
            const visibleStart = bStart < weekStart ? weekStart : bStart;
            const visibleEnd = bEnd > weekEnd ? weekEnd : bEnd;
            if (visibleStart > weekEnd || visibleEnd < weekStart) continue;
            const startCol = Math.max(0, Math.round((visibleStart.getTime() - weekStart.getTime()) / (1000 * 60 * 60 * 24)));
            const endCol = Math.min(6, Math.round((visibleEnd.getTime() - weekStart.getTime()) / (1000 * 60 * 60 * 24)));
            map[vId].push({ booking: b, startCol, spanCols: endCol - startCol + 1 });
        }
        return map;
    }, [bookings, vehicles, weekStart]);

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Button variant="outline" size="sm" onClick={() => onWeekChange(formatDate(addDays(weekStart, -7)))}><ChevronLeft className="h-4 w-4" />Previous</Button>
                    <Button variant="outline" size="sm" onClick={() => onWeekChange(formatDate(getMonday(new Date())))}>Today</Button>
                    <Button variant="outline" size="sm" onClick={() => onWeekChange(formatDate(addDays(weekStart, 7)))}>Next<ChevronRight className="ml-1 h-4 w-4" /></Button>
                </div>
                <span className="text-sm font-medium text-muted-foreground">{formatShortDay(weekStart)} &mdash; {formatShortDay(addDays(weekStart, 6))}</span>
            </div>
            <div className="rounded-lg border overflow-hidden">
                <div className="min-w-[700px]">
                    <div className="grid grid-cols-[180px_repeat(7,1fr)] border-b bg-muted/30">
                        <div className="border-r px-3 py-2 text-xs font-medium text-muted-foreground">Vehicle</div>
                        {days.map((day, i) => (
                            <div key={i} className={`border-r px-2 py-2 text-center text-xs font-medium last:border-r-0 ${isSameDay(day, today) ? 'bg-primary/10 text-primary' : 'text-muted-foreground'}`}>
                                <div>{day.toLocaleDateString('en-US', { weekday: 'short' })}</div>
                                <div className="text-[11px]">{day.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}</div>
                            </div>
                        ))}
                    </div>
                    {vehicles.length > 0 ? vehicles.map((vehicle) => (
                        <div key={vehicle.id} className="grid grid-cols-[180px_repeat(7,1fr)] border-b last:border-b-0">
                            <div className="flex items-center gap-2 border-r px-3 py-3">
                                <Car className="h-3.5 w-3.5 text-muted-foreground" />
                                <span className="truncate text-sm font-medium">{vehicle.name}</span>
                            </div>
                            <div className="relative col-span-7">
                                <div className="grid grid-cols-7">
                                    {days.map((day, i) => (<div key={i} className={`h-12 border-r last:border-r-0 ${isSameDay(day, today) ? 'bg-primary/5' : ''}`} />))}
                                </div>
                                {(vehicleBookings[vehicle.id] ?? []).map(({ booking, startCol, spanCols }, idx) => {
                                    const color = STATUS_COLORS[booking.status] ?? '#6b7280';
                                    return (
                                        <Link key={booking.id} href={`/fleet-assets/bookings/${booking.id}`}
                                            className="absolute flex items-center rounded px-1.5 text-[10px] font-medium text-white shadow-sm transition-opacity hover:opacity-80"
                                            style={{ left: `${(startCol / 7) * 100}%`, width: `${(spanCols / 7) * 100}%`, top: `${4 + idx * 18}px`, height: '16px', backgroundColor: color }}
                                            title={`${booking.user?.name ?? 'Unknown'}: ${booking.purpose ?? ''}`}>
                                            <span className="truncate">{booking.user?.name ?? 'Booking'}</span>
                                        </Link>
                                    );
                                })}
                            </div>
                        </div>
                    )) : (<div className="p-8 text-center text-sm text-muted-foreground">No vehicles found.</div>)}
                </div>
            </div>
            <div className="flex flex-wrap gap-3 text-xs text-muted-foreground">
                {Object.entries(STATUS_COLORS).map(([status, color]) => (
                    <div key={status} className="flex items-center gap-1.5">
                        <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: color }} />
                        <span className="capitalize">{status.replace(/_/g, ' ')}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function BookingsIndex({
    bookings: rawBookings, filters: rawFilters, vehicles: rawVehicles,
    calendar_bookings: rawCalendarBookings, week_start: rawWeekStart,
}: Props) {
    const bookings = useMemo(() => rawBookings?.data ?? [], [rawBookings?.data]);
    const meta = rawBookings?.meta ?? { current_page: 1, last_page: 1, total: 0 };
    const links = rawBookings?.links ?? [];
    const filters = rawFilters ?? {};
    const vehicles = rawVehicles ?? [];
    const calendarBookings = rawCalendarBookings ?? [];

    const totalCount = meta.total ?? bookings.length;
    const pendingCount = bookings.filter((b) => b.status === 'pending').length;
    const approvedCount = bookings.filter((b) => b.status === 'approved').length;
    const checkedOutCount = bookings.filter((b) => b.status === 'checked_out').length;

    // Generate booking trend for last 7 days from booking data
    const bookingTrend = useMemo(() => {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        return Array.from({ length: 7 }, (_, i) => {
            const day = new Date(today);
            day.setDate(day.getDate() - (6 - i));
            const dayStr = day.toISOString().split('T')[0];
            return bookings.filter((b) => b.created_at && b.created_at.startsWith(dayStr)).length;
        });
    }, [bookings]);

    const [viewMode, setViewMode] = useState<'list' | 'calendar'>(filters.view === 'calendar' ? 'calendar' : 'list');
    const weekStart = useMemo(() => rawWeekStart ? new Date(rawWeekStart) : getMonday(new Date()), [rawWeekStart]);

    const applyFilters = (newFilters: Record<string, string | undefined>) => {
        router.get('/fleet-assets/bookings', { ...filters, ...newFilters, page: 1 }, { preserveState: true });
    };

    const switchView = (mode: 'list' | 'calendar') => {
        setViewMode(mode);
        if (mode === 'calendar') applyFilters({ view: 'calendar', week_start: formatDate(weekStart) });
        else applyFilters({ view: undefined, week_start: undefined });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Fleet & Assets', href: '/fleet-assets' }, { title: 'Bookings', href: '/fleet-assets/bookings' }]}>
            <Head title="Vehicle Bookings" />
            <PageShell>
                <FleetHero
                    title="Vehicle Bookings"
                    description="Manage vehicle booking requests and availability."
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" asChild><a href="/fleet-assets/bookings?export=csv"><Download className="mr-2 h-4 w-4" />Export CSV</a></Button>
                            <Button asChild><Link href="/fleet-assets/bookings/create"><Plus className="mr-2 h-4 w-4" />Create Booking</Link></Button>
                        </div>
                    }
                />

                {/* Dark KPI Cards */}
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                    <FleetStatCard label="TOTAL" value={totalCount} icon={ClipboardList} subtitle="All bookings" trend={bookingTrend} />
                    <FleetStatCard label="PENDING" value={pendingCount} icon={Clock} color="amber" valueClassName="text-status-warning" subtitle="Awaiting approval" />
                    <FleetStatCard label="APPROVED" value={approvedCount} icon={CheckCircle} color="blue" valueClassName="text-status-info" subtitle="Ready for use" />
                    <FleetStatCard label="CHECKED OUT" value={checkedOutCount} icon={Car} color="amber" valueClassName="text-status-success" subtitle="Currently in use" />
                </div>

                {/* View Toggle + Filters */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div className="inline-flex rounded-lg border bg-muted p-0.5">
                        <Button type="button" variant="ghost" size="sm" onClick={() => switchView('list')} className={`h-auto gap-1.5 rounded-md px-3 py-1.5 ${viewMode === 'list' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}>
                            <List className="h-4 w-4" />List
                        </Button>
                        <Button type="button" variant="ghost" size="sm" onClick={() => switchView('calendar')} className={`h-auto gap-1.5 rounded-md px-3 py-1.5 ${viewMode === 'calendar' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}>
                            <CalendarDays className="h-4 w-4" />Calendar
                        </Button>
                    </div>
                    {viewMode === 'list' && (
                        <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v })}>
                            <SelectTrigger className="w-40"><SelectValue placeholder="Status" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                <SelectItem value="pending">Pending</SelectItem>
                                <SelectItem value="approved">Approved</SelectItem>
                                <SelectItem value="checked_out">Checked Out</SelectItem>
                                <SelectItem value="returned">Returned</SelectItem>
                                <SelectItem value="rejected">Rejected</SelectItem>
                                <SelectItem value="cancelled">Cancelled</SelectItem>
                            </SelectContent>
                        </Select>
                    )}
                </div>

                {viewMode === 'calendar' && <BookingCalendar bookings={calendarBookings} vehicles={vehicles} weekStart={weekStart} onWeekChange={(s) => applyFilters({ view: 'calendar', week_start: s })} />}

                {viewMode === 'list' && (
                    <>
                        <div className="grid gap-3">
                            {bookings.length > 0 ? bookings.map((booking) => (
                                <Link key={booking.id} href={`/fleet-assets/bookings/${booking.id}`} className="flex flex-col gap-2 rounded-lg border p-4 transition-all duration-200 hover:bg-muted/50 hover:shadow-lg hover:-translate-y-0.5 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Car className="h-4 w-4 text-muted-foreground" />
                                            <span className="text-sm font-semibold">{booking.asset?.name ?? 'No vehicle'}</span>
                                            <Badge variant={statusVariant(booking.status)}>{booking.status.replace(/_/g, ' ')}</Badge>
                                        </div>
                                        <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                            <span className="inline-flex items-center gap-1"><User className="h-3 w-3" />{booking.user?.name ?? '---'}</span>
                                            <span>{booking.purpose ?? ''}</span>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                        <Calendar className="h-3 w-3" />
                                        <span>{booking.starts_at ? formatDateStr(booking.starts_at) : '---'} - {booking.ends_at ? formatDateStr(booking.ends_at) : '---'}</span>
                                    </div>
                                </Link>
                            )) : (
                                <FleetEmptyState icon={Calendar} title="No bookings yet" description="Create a booking to reserve a vehicle for a trip or task." actionLabel="Create Booking" actionHref="/fleet-assets/bookings/create" />
                            )}
                        </div>
                        {(meta.last_page ?? 1) > 1 && (
                            <div className="flex items-center justify-center gap-1">
                                {links.map((link, i) => (<Button key={i} variant={link.active ? 'default' : 'outline'} size="sm" disabled={!link.url} onClick={() => link.url && router.get(link.url)} dangerouslySetInnerHTML={{ __html: link.label }} />))}
                            </div>
                        )}
                    </>
                )}
            </PageShell>
        </AppLayout>
    );
}
