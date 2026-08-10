import { FleetEmptyState } from '@/components/fleet-empty-state';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate as formatWorkerDate, toDateInput } from '@/lib/datetime';
import { formatDate as formatDateStr } from '@/lib/fleet-utils';
import {
    FleetAttentionStrip,
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { Head, Link, router } from '@inertiajs/react';
import {
    Calendar,
    CalendarClock,
    CalendarDays,
    Car,
    ChevronLeft,
    ChevronRight,
    Download,
    List,
    Plus,
    User,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    BookVehicleWizard,
    type BookingConflict,
    type BookingWizardOptions,
    type WizardVehicleBooking,
} from './book-vehicle-wizard';

type Booking = {
    id: number;
    reference_number?: string | null;
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
    hero: {
        pending: number;
        approved_upcoming: number;
        checked_out: number;
        overdue: number;
        outings_past_return: number;
        critical_alerts: number;
    };
    filters: {
        status?: string;
        asset_id?: string;
        date_from?: string;
        date_to?: string;
        view?: string;
        week_start?: string;
        overdue?: string;
    };
    vehicles?: Vehicle[];
    calendar_bookings?: Booking[];
    week_start?: string;
    booking_options?: BookingWizardOptions | null;
    booking_conflicts?: BookingConflict[];
    booking_vehicle_status?: string | null;
    booking_vehicle_bookings?: WizardVehicleBooking[];
};

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'approved':
            return 'default';
        case 'pending':
            return 'outline';
        case 'checked_out':
            return 'default';
        case 'returned':
            return 'secondary';
        case 'rejected':
            return 'destructive';
        case 'cancelled':
            return 'secondary';
        default:
            return 'outline';
    }
}

const STATUS_COLORS: Record<string, string> = {
    pending: '#eab308',
    approved: '#3b82f6',
    checked_out: '#22c55e',
    returned: '#9ca3af',
    rejected: '#ef4444',
    cancelled: '#d1d5db',
};

function getMonday(d: Date): Date {
    const date = new Date(d);
    const day = date.getDay();
    const diff = date.getDate() - day + (day === 0 ? -6 : 1);
    date.setDate(diff);
    date.setHours(0, 0, 0, 0);
    return date;
}
function addDays(d: Date, n: number): Date {
    const date = new Date(d);
    date.setDate(date.getDate() + n);
    return date;
}
function formatDate(d: Date): string {
    return toDateInput(d);
}
function formatShortDay(d: Date): string {
    return formatWorkerDate(d);
}
function isSameDay(a: Date, b: Date): boolean {
    return toDateInput(a) === toDateInput(b);
}

function BookingCalendar({
    bookings,
    vehicles,
    weekStart,
    onWeekChange,
}: {
    bookings: Booking[];
    vehicles: Vehicle[];
    weekStart: Date;
    onWeekChange: (s: string) => void;
}) {
    const days = useMemo(
        () => Array.from({ length: 7 }, (_, i) => addDays(weekStart, i)),
        [weekStart],
    );
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const vehicleBookings = useMemo(() => {
        const map: Record<
            number,
            Array<{ booking: Booking; startCol: number; spanCols: number }>
        > = {};
        for (const v of vehicles) map[v.id] = [];
        for (const b of bookings) {
            if (!b.asset?.id || !b.starts_at || !b.ends_at) continue;
            const vId = b.asset.id;
            if (!map[vId]) map[vId] = [];
            const bStart = new Date(b.starts_at);
            bStart.setHours(0, 0, 0, 0);
            const bEnd = new Date(b.ends_at);
            bEnd.setHours(0, 0, 0, 0);
            const weekEnd = addDays(weekStart, 6);
            const visibleStart = bStart < weekStart ? weekStart : bStart;
            const visibleEnd = bEnd > weekEnd ? weekEnd : bEnd;
            if (visibleStart > weekEnd || visibleEnd < weekStart) continue;
            const startCol = Math.max(
                0,
                Math.round(
                    (visibleStart.getTime() - weekStart.getTime()) /
                        (1000 * 60 * 60 * 24),
                ),
            );
            const endCol = Math.min(
                6,
                Math.round(
                    (visibleEnd.getTime() - weekStart.getTime()) /
                        (1000 * 60 * 60 * 24),
                ),
            );
            map[vId].push({
                booking: b,
                startCol,
                spanCols: endCol - startCol + 1,
            });
        }
        return map;
    }, [bookings, vehicles, weekStart]);

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            onWeekChange(formatDate(addDays(weekStart, -7)))
                        }
                    >
                        <ChevronLeft className="h-4 w-4" />
                        Previous
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            onWeekChange(formatDate(getMonday(new Date())))
                        }
                    >
                        Today
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            onWeekChange(formatDate(addDays(weekStart, 7)))
                        }
                    >
                        Next
                        <ChevronRight className="ml-1 h-4 w-4" />
                    </Button>
                </div>
                <span className="text-sm font-medium text-muted-foreground">
                    {formatShortDay(weekStart)} &mdash;{' '}
                    {formatShortDay(addDays(weekStart, 6))}
                </span>
            </div>
            <div className="overflow-hidden rounded-lg border">
                <div className="min-w-[700px]">
                    <div className="grid grid-cols-[180px_repeat(7,1fr)] border-b bg-muted/30">
                        <div className="border-r px-3 py-2 text-xs font-medium text-muted-foreground">
                            Vehicle
                        </div>
                        {days.map((day, i) => (
                            <div
                                key={i}
                                className={`border-r px-2 py-2 text-center text-xs font-medium last:border-r-0 ${isSameDay(day, today) ? 'bg-primary/10 text-primary' : 'text-muted-foreground'}`}
                            >
                                <div>{formatWorkerDate(day)}</div>
                            </div>
                        ))}
                    </div>
                    {vehicles.length > 0 ? (
                        vehicles.map((vehicle) => (
                            <div
                                key={vehicle.id}
                                className="grid grid-cols-[180px_repeat(7,1fr)] border-b last:border-b-0"
                            >
                                <div className="flex items-center gap-2 border-r px-3 py-3">
                                    <Car className="h-3.5 w-3.5 text-muted-foreground" />
                                    <span className="truncate text-sm font-medium">
                                        {vehicle.name}
                                    </span>
                                </div>
                                <div className="relative col-span-7">
                                    <div className="grid grid-cols-7">
                                        {days.map((day, i) => (
                                            <div
                                                key={i}
                                                className={`h-12 border-r last:border-r-0 ${isSameDay(day, today) ? 'bg-primary/5' : ''}`}
                                            />
                                        ))}
                                    </div>
                                    {(vehicleBookings[vehicle.id] ?? []).map(
                                        (
                                            { booking, startCol, spanCols },
                                            idx,
                                        ) => {
                                            const color =
                                                STATUS_COLORS[booking.status] ??
                                                '#6b7280';
                                            return (
                                                <Link
                                                    key={booking.id}
                                                    href={`/fleet-assets/bookings/${booking.id}`}
                                                    className="absolute flex items-center rounded px-1.5 text-[10px] font-medium text-white shadow-sm transition-opacity hover:opacity-80"
                                                    style={{
                                                        left: `${(startCol / 7) * 100}%`,
                                                        width: `${(spanCols / 7) * 100}%`,
                                                        top: `${4 + idx * 18}px`,
                                                        height: '16px',
                                                        backgroundColor: color,
                                                    }}
                                                    title={`${booking.reference_number ?? '—'} · ${booking.user?.name ?? 'Unknown'}: ${booking.purpose ?? ''}`}
                                                >
                                                    <span className="truncate">
                                                        {booking.user?.name ??
                                                            'Booking'}
                                                    </span>
                                                </Link>
                                            );
                                        },
                                    )}
                                </div>
                            </div>
                        ))
                    ) : (
                        <div className="p-8 text-center text-sm text-muted-foreground">
                            No vehicles found.
                        </div>
                    )}
                </div>
            </div>
            <div className="flex flex-wrap gap-3 text-xs text-muted-foreground">
                {Object.entries(STATUS_COLORS).map(([status, color]) => (
                    <div key={status} className="flex items-center gap-1.5">
                        <span
                            className="inline-block h-2.5 w-2.5 rounded-sm"
                            style={{ backgroundColor: color }}
                        />
                        <span className="capitalize">
                            {status.replace(/_/g, ' ')}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function BookingsIndex({
    bookings: rawBookings,
    hero,
    filters: rawFilters,
    vehicles: rawVehicles,
    calendar_bookings: rawCalendarBookings,
    week_start: rawWeekStart,
    booking_options,
    booking_conflicts,
    booking_vehicle_status,
    booking_vehicle_bookings,
}: Props) {
    const bookings = useMemo(
        () => rawBookings?.data ?? [],
        [rawBookings?.data],
    );
    const meta = rawBookings?.meta ?? {
        current_page: 1,
        last_page: 1,
        total: 0,
    };
    const links = rawBookings?.links ?? [];
    const filters = rawFilters ?? {};
    const vehicles = rawVehicles ?? [];
    const calendarBookings = rawCalendarBookings ?? [];
    const heroStats = hero ?? {
        pending: 0,
        approved_upcoming: 0,
        checked_out: 0,
        overdue: 0,
        outings_past_return: 0,
        critical_alerts: 0,
    };

    const [viewMode, setViewMode] = useState<'list' | 'calendar'>(
        filters.view === 'calendar' ? 'calendar' : 'list',
    );
    const weekStart = useMemo(
        () => (rawWeekStart ? new Date(rawWeekStart) : getMonday(new Date())),
        [rawWeekStart],
    );

    // Open the wizard on mount when arriving via the legacy GET create route
    // (it redirects here with ?new=1).
    const [wizardOpen, setWizardOpen] = useState<boolean>(() => {
        if (typeof window === 'undefined') return false;
        return new URLSearchParams(window.location.search).has('new');
    });

    // Pre-select the vehicle when the caller passed one (dashboard per-vehicle
    // "Book" arrives as ?new=1&asset_id=…).
    const [initialVehicleId] = useState<string | null>(() => {
        if (typeof window === 'undefined') return null;
        return new URLSearchParams(window.location.search).get('asset_id');
    });

    const closeWizard = () => {
        setWizardOpen(false);
        // Strip the shim + conflict-check params so a refresh doesn't reopen
        // the modal or re-run the availability check.
        if (typeof window !== 'undefined') {
            const url = new URL(window.location.href);
            [
                'new',
                'check_asset_id',
                'check_starts_at',
                'check_ends_at',
            ].forEach((p) => url.searchParams.delete(p));
            window.history.replaceState(
                window.history.state,
                '',
                url.toString(),
            );
        }
    };

    const applyFilters = (newFilters: Record<string, string | undefined>) => {
        router.get(
            '/fleet-assets/bookings',
            { ...filters, ...newFilters, page: 1 },
            { preserveState: true },
        );
    };

    const switchView = (mode: 'list' | 'calendar') => {
        setViewMode(mode);
        if (mode === 'calendar')
            applyFilters({
                view: 'calendar',
                week_start: formatDate(weekStart),
            });
        else applyFilters({ view: undefined, week_start: undefined });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Bookings', href: '/fleet-assets/bookings' },
            ]}
        >
            <Head title="Vehicle Bookings" />
            <PageShell>
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={CalendarClock} />
                        <div className="min-w-0">
                            <HeroStatusPill>
                                Vehicle bookings · live
                            </HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">
                                Vehicle Bookings
                            </h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Manage vehicle booking requests and
                                availability.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:ml-auto lg:max-w-2xl">
                            <HeroClusterTile
                                href="/fleet-assets/bookings?status=pending"
                                label="Pending approval"
                                value={fmt(heroStats.pending)}
                                caption="awaiting a decision"
                                tone={
                                    heroStats.pending > 0
                                        ? 'warning'
                                        : 'success'
                                }
                            />
                            <HeroClusterTile
                                href="/fleet-assets/bookings?status=approved"
                                label="Approved upcoming"
                                value={fmt(heroStats.approved_upcoming)}
                                caption="ready to check out"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/fleet-assets/bookings?status=checked_out"
                                label="Checked out now"
                                value={fmt(heroStats.checked_out)}
                                caption="vehicles on the road"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/fleet-assets/bookings?overdue=1"
                                label="Overdue returns"
                                value={fmt(heroStats.overdue)}
                                caption="past their end time"
                                tone={
                                    heroStats.overdue > 0
                                        ? 'critical'
                                        : 'success'
                                }
                            />
                        </div>
                    </div>

                    {/* Org-wide escalations — same band as the fleet dashboard; the
                        overdue chip drills into this page's overdue filter. */}
                    <FleetAttentionStrip
                        overdueReturns={heroStats.overdue ?? 0}
                        outingsPastReturn={heroStats.outings_past_return ?? 0}
                        criticalAlerts={heroStats.critical_alerts ?? 0}
                        hrefs={{ overdue: '/fleet-assets/bookings?overdue=1' }}
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        {/* eslint-disable-next-line no-restricted-syntax -- on-dark hero quick action (FleetHeroAction chrome) opening the modal, not a nav link. */}
                        <button
                            type="button"
                            onClick={() => setWizardOpen(true)}
                            className="inline-flex h-[34px] items-center gap-2 rounded-lg bg-primary-foreground px-3.5 text-[12.5px] font-extrabold text-primary shadow-sm transition-colors hover:bg-primary-foreground/90 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                        >
                            <Plus className="h-[15px] w-[15px]" />
                            Book vehicle
                        </button>
                        <FleetHeroAction
                            href="/fleet-assets/bookings?export=csv"
                            icon={Download}
                            external
                        >
                            Export CSV
                        </FleetHeroAction>
                    </div>
                </HeroShell>

                {/* View Toggle + Filters */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div className="inline-flex rounded-lg border bg-muted p-0.5">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => switchView('list')}
                            className={`h-auto gap-1.5 rounded-md px-3 py-1.5 ${viewMode === 'list' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}
                        >
                            <List className="h-4 w-4" />
                            List
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => switchView('calendar')}
                            className={`h-auto gap-1.5 rounded-md px-3 py-1.5 ${viewMode === 'calendar' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}
                        >
                            <CalendarDays className="h-4 w-4" />
                            Calendar
                        </Button>
                    </div>
                    {viewMode === 'list' && (
                        <Select
                            value={filters.status || 'all'}
                            onValueChange={(v) =>
                                applyFilters({
                                    status: v === 'all' ? '' : v,
                                    overdue: undefined,
                                })
                            }
                        >
                            <SelectTrigger className="w-40">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All statuses
                                </SelectItem>
                                <SelectItem value="pending">Pending</SelectItem>
                                <SelectItem value="approved">
                                    Approved
                                </SelectItem>
                                <SelectItem value="checked_out">
                                    Checked Out
                                </SelectItem>
                                <SelectItem value="returned">
                                    Returned
                                </SelectItem>
                                <SelectItem value="rejected">
                                    Rejected
                                </SelectItem>
                                <SelectItem value="cancelled">
                                    Cancelled
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    )}
                </div>

                {viewMode === 'calendar' && (
                    <BookingCalendar
                        bookings={calendarBookings}
                        vehicles={vehicles}
                        weekStart={weekStart}
                        onWeekChange={(s) =>
                            applyFilters({ view: 'calendar', week_start: s })
                        }
                    />
                )}

                {viewMode === 'list' && (
                    <>
                        <div className="grid gap-3">
                            {bookings.length > 0 ? (
                                bookings.map((booking) => (
                                    <Link
                                        key={booking.id}
                                        href={`/fleet-assets/bookings/${booking.id}`}
                                        className="flex flex-col gap-2 rounded-lg border p-4 transition-all duration-200 hover:-translate-y-0.5 hover:bg-muted/50 hover:shadow-lg sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Car className="h-4 w-4 text-muted-foreground" />
                                                <span className="text-sm font-semibold">
                                                    {booking.asset?.name ??
                                                        'No vehicle'}
                                                </span>
                                                <span className="inline-flex items-center rounded-md border border-border bg-muted/60 px-1.5 py-0.5 font-mono text-[11px] font-medium text-muted-foreground">
                                                    {booking.reference_number ??
                                                        '—'}
                                                </span>
                                                <Badge
                                                    variant={statusVariant(
                                                        booking.status,
                                                    )}
                                                >
                                                    {booking.status.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                            </div>
                                            <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                <span className="inline-flex items-center gap-1">
                                                    <User className="h-3 w-3" />
                                                    {booking.user?.name ??
                                                        '---'}
                                                </span>
                                                <span>
                                                    {booking.purpose ?? ''}
                                                </span>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                            <Calendar className="h-3 w-3" />
                                            <span>
                                                {booking.starts_at
                                                    ? formatDateStr(
                                                          booking.starts_at,
                                                      )
                                                    : '---'}{' '}
                                                -{' '}
                                                {booking.ends_at
                                                    ? formatDateStr(
                                                          booking.ends_at,
                                                      )
                                                    : '---'}
                                            </span>
                                        </div>
                                    </Link>
                                ))
                            ) : (
                                <FleetEmptyState
                                    icon={Calendar}
                                    title="No bookings yet"
                                    description="Create a booking to reserve a vehicle for a trip or task."
                                    actionLabel="Book Vehicle"
                                    onAction={() => setWizardOpen(true)}
                                />
                            )}
                        </div>
                        {(meta.last_page ?? 1) > 1 && (
                            <div className="flex items-center justify-center gap-1">
                                {links.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        size="sm"
                                        disabled={!link.url}
                                        onClick={() =>
                                            link.url && router.get(link.url)
                                        }
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ))}
                            </div>
                        )}
                    </>
                )}

                <BookVehicleWizard
                    open={wizardOpen}
                    onClose={closeWizard}
                    initialVehicleId={initialVehicleId}
                    options={booking_options}
                    conflicts={booking_conflicts}
                    vehicleStatus={booking_vehicle_status}
                    vehicleBookings={booking_vehicle_bookings}
                />
            </PageShell>
        </AppLayout>
    );
}
