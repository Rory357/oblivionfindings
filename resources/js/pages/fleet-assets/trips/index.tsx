import {
    FLEET_COLORS,
    HorizontalBarChart,
    MiniBarChart,
    SparklineChart,
} from '@/components/fleet-charts';
import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import {
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import LeafletMap from '@/components/leaflet-map';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import {
    formatDateTime,
    formatDistance,
    formatDuration,
    formatTime,
} from '@/lib/fleet-utils';
import { toDateInput } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    Car,
    ChevronDown,
    ChevronRight,
    ChevronUp,
    ChevronsUpDown,
    Clock,
    Download,
    Gauge,
    MapPin,
    Play,
    Route,
    Search,
    User,
    UserX,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

type TripSegment = {
    id: number;
    seq: number;
    started_at: string | null;
    ended_at: string | null;
    distance_km: number;
    duration_s: number;
    polyline: { lat: number; lng: number }[] | null;
};

type Trip = {
    id: number;
    asset: { id: number; name: string; asset_tag: string } | null;
    driver: { id: number; name: string } | null;
    started_at: string | null;
    ended_at: string | null;
    distance_km: number;
    duration_s: number;
    max_speed_kph: number | null;
    start_address: string | null;
    end_address: string | null;
    start_latitude: number | null;
    start_longitude: number | null;
    end_latitude: number | null;
    end_longitude: number | null;
    status: string;
    is_personal: boolean;
    segments: TripSegment[];
};

type Vehicle = {
    id: number;
    name: string;
};

type Props = {
    trips: {
        data: Trip[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        meta: {
            current_page: number;
            last_page: number;
            total: number;
        };
    };
    vehicles: Vehicle[];
    filters: {
        date_from?: string;
        date_to?: string;
        vehicle_id?: string;
        status?: string;
        search?: string;
    };
    summary: {
        total_trips: number;
        total_distance_km: number;
        total_duration_s: number;
        avg_speed_kph: number;
        avg_distance_km: number;
        active_trips: number;
    };
    hero?: {
        trips_today: number;
        distance_today_km: number;
        active_now: number;
        after_hours_7d: number;
    };
    trips_by_day?: Array<{ label: string; value: number }>;
    top_vehicles?: Array<{ label: string; value: number }>;
    distance_trend?: number[];
    can?: {
        manage: boolean;
    };
};

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'completed':
            return 'default';
        case 'in_progress':
            return 'secondary';
        case 'open':
            return 'outline';
        default:
            return 'outline';
    }
}

// Using shared formatDuration from fleet-utils

export default function TripsIndex({
    trips: rawTrips,
    vehicles: rawVehicles,
    filters: rawFilters,
    summary: rawSummary,
    hero: rawHero,
    trips_by_day: rawTripsByDay,
    top_vehicles: rawTopVehicles,
    distance_trend: rawDistanceTrend,
    can,
}: Props) {
    const tripsData = rawTrips?.data ?? [];
    const meta = rawTrips?.meta ?? { current_page: 1, last_page: 1, total: 0 };
    const links = rawTrips?.links ?? [];
    const vehicles = rawVehicles ?? [];
    const filters = rawFilters ?? {};
    const summary = rawSummary ?? {
        total_trips: 0,
        total_distance_km: 0,
        total_duration_s: 0,
        avg_speed_kph: 0,
        avg_distance_km: 0,
        active_trips: 0,
    };
    const tripsByDay = rawTripsByDay ?? [];
    const topVehicles = rawTopVehicles ?? [];
    const distanceTrend = rawDistanceTrend ?? [];
    const canManage = can?.manage ?? false;
    const hero = rawHero ?? {
        trips_today: 0,
        distance_today_km: 0,
        active_now: 0,
        after_hours_7d: 0,
    };

    // Local-date strings for the hero tile drill-down links.
    const localDay = (offset = 0) => {
        const d = new Date();
        d.setDate(d.getDate() - offset);
        return toDateInput(d);
    };
    const todayHref = `/fleet-assets/trips?date_from=${localDay()}&date_to=${localDay()}`;
    const weekHref = `/fleet-assets/trips?date_from=${localDay(7)}`;

    const [expandedTrip, setExpandedTrip] = useState<number | null>(null);
    const [searchValue, setSearchValue] = useState(filters.search ?? '');
    const [sortField, setSortField] = useState<string>('');
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');

    function handleSort(field: string) {
        const newDir =
            sortField === field && sortDir === 'asc' ? 'desc' : 'asc';
        setSortField(field);
        setSortDir(newDir);
        router.get(
            window.location.pathname,
            { ...filters, sort: field, direction: newDir },
            { preserveState: true },
        );
    }

    const renderSortHeader = (
        field: string,
        children: React.ReactNode,
        className?: string,
    ) => {
        const active = sortField === field;
        return (
            <th
                className={`cursor-pointer px-4 py-3 font-medium select-none hover:bg-muted/50 ${className ?? 'text-left'}`}
                onClick={() => handleSort(field)}
            >
                <div className="flex items-center gap-1">
                    {children}
                    {active ? (
                        sortDir === 'asc' ? (
                            <ChevronUp className="h-3 w-3" />
                        ) : (
                            <ChevronDown className="h-3 w-3" />
                        )
                    ) : (
                        <ChevronsUpDown className="h-3 w-3 text-muted-foreground/50" />
                    )}
                </div>
            </th>
        );
    };

    const applyFilters = (newFilters: Partial<typeof filters>) => {
        router.get(
            '/fleet-assets/trips',
            {
                ...filters,
                ...newFilters,
                page: 1,
            },
            { preserveState: true },
        );
    };

    const handleSearch = () => {
        applyFilters({ search: searchValue || undefined });
    };

    const csvHref = () => {
        const params = new URLSearchParams();
        if (filters.date_from) params.set('date_from', filters.date_from);
        if (filters.date_to) params.set('date_to', filters.date_to);
        if (filters.vehicle_id) params.set('vehicle_id', filters.vehicle_id);
        if (filters.status) params.set('status', filters.status);
        if (filters.search) params.set('search', filters.search);
        params.set('export', 'csv');
        return `/fleet-assets/trips?${params.toString()}`;
    };

    const toggleTrip = (tripId: number) => {
        setExpandedTrip(expandedTrip === tripId ? null : tripId);
    };

    const getPolylineForTrip = (trip: Trip): { lat: number; lng: number }[] => {
        const points: { lat: number; lng: number }[] = [];
        (trip.segments ?? []).forEach((seg) => {
            (seg.polyline ?? []).forEach((p) => {
                points.push({ lat: p.lat, lng: p.lng });
            });
        });
        if (points.length === 0) {
            if (trip.start_latitude && trip.start_longitude) {
                points.push({
                    lat: trip.start_latitude,
                    lng: trip.start_longitude,
                });
            }
            if (trip.end_latitude && trip.end_longitude) {
                points.push({
                    lat: trip.end_latitude,
                    lng: trip.end_longitude,
                });
            }
        }
        return points;
    };

    const getMapCenter = (trip: Trip) => {
        if (trip.start_latitude && trip.start_longitude) {
            return { lat: trip.start_latitude, lng: trip.start_longitude };
        }
        return { lat: -36.85, lng: 174.76 };
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Trips', href: '/fleet-assets/trips' },
            ]}
        >
            <Head title="Trip History" />
            <PageShell>
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={Route} />
                        <div className="min-w-0">
                            <HeroStatusPill>Trip history · live feed</HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">
                                Trip History
                            </h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                View and analyse all vehicle trips across your
                                fleet.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:ml-auto lg:max-w-2xl">
                            <HeroClusterTile
                                href={todayHref}
                                label="Trips today"
                                value={fmt(hero.trips_today)}
                                caption="journeys logged"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href={todayHref}
                                label="Distance today"
                                value={fmt(hero.distance_today_km, ' km')}
                                caption="kilometres driven"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                label="Active now"
                                value={fmt(hero.active_now)}
                                caption="open or in progress"
                                tone={hero.active_now > 0 ? 'success' : 'neutral'}
                            />
                            <HeroClusterTile
                                href={weekHref}
                                label="After-hours 7d"
                                value={fmt(hero.after_hours_7d)}
                                caption="before 8am / after 6pm"
                                tone={hero.after_hours_7d > 0 ? 'warning' : 'success'}
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <FleetHeroAction href={csvHref()} icon={Download} external>
                            Export CSV
                        </FleetHeroAction>
                    </div>
                </HeroShell>

                {/* KPI Row */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
                    <FleetStatCard
                        label="TOTAL TRIPS"
                        value={summary.total_trips ?? 0}
                        icon={Route}
                        color="purple"
                        subtitle="For selected period"
                    />
                    <FleetStatCard
                        label="TOTAL DISTANCE"
                        value={formatDistance(summary.total_distance_km ?? 0)}
                        icon={Car}
                        color="blue"
                        subtitle="Kilometres travelled"
                    />
                    <FleetStatCard
                        label="TOTAL DURATION"
                        value={formatDuration(summary.total_duration_s ?? 0)}
                        icon={Clock}
                        color="cyan"
                        subtitle="Time on road"
                    />
                    <FleetStatCard
                        label="AVG SPEED"
                        value={
                            summary.avg_speed_kph
                                ? `${summary.avg_speed_kph} km/h`
                                : '---'
                        }
                        icon={Gauge}
                        color="amber"
                        subtitle="Average max speed"
                    />
                    <FleetStatCard
                        label="AVG TRIP DIST"
                        value={formatDistance(summary.avg_distance_km ?? 0)}
                        icon={Activity}
                        color="purple"
                        subtitle="Average per trip"
                    />
                    <FleetStatCard
                        label="ACTIVE TRIPS"
                        value={summary.active_trips ?? 0}
                        icon={Zap}
                        color="red"
                        subtitle="Open or in progress"
                    />
                </div>

                {/* Charts Row */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Trips by Day
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <MiniBarChart
                                data={tripsByDay}
                                color={FLEET_COLORS.primary}
                                height={120}
                            />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Top Vehicles
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <HorizontalBarChart
                                items={topVehicles.map((v) => ({
                                    label: v.label,
                                    value: v.value,
                                }))}
                                color={FLEET_COLORS.secondary}
                            />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Distance Trend
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex items-center justify-center">
                            {distanceTrend.length > 1 ? (
                                <SparklineChart
                                    data={distanceTrend}
                                    color={FLEET_COLORS.accent}
                                    height={80}
                                    width={260}
                                />
                            ) : (
                                <p className="py-4 text-center text-sm text-muted-foreground">
                                    Not enough data yet.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <div className="relative">
                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search vehicle or address..."
                            className="w-64 pl-9"
                            value={searchValue}
                            onChange={(e) => setSearchValue(e.target.value)}
                            onKeyDown={(e) =>
                                e.key === 'Enter' && handleSearch()
                            }
                        />
                    </div>
                    <Input
                        type="date"
                        className="w-40"
                        value={filters.date_from ?? ''}
                        onChange={(e) =>
                            applyFilters({
                                date_from: e.target.value || undefined,
                            })
                        }
                        placeholder="From date"
                    />
                    <Input
                        type="date"
                        className="w-40"
                        value={filters.date_to ?? ''}
                        onChange={(e) =>
                            applyFilters({
                                date_to: e.target.value || undefined,
                            })
                        }
                        placeholder="To date"
                    />
                    <Select
                        value={filters.vehicle_id ?? 'all'}
                        onValueChange={(v) =>
                            applyFilters({
                                vehicle_id: v === 'all' ? undefined : v,
                            })
                        }
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="All vehicles" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All vehicles</SelectItem>
                            {vehicles.map((v) => (
                                <SelectItem key={v.id} value={String(v.id)}>
                                    {v.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.status ?? 'all'}
                        onValueChange={(v) =>
                            applyFilters({
                                status: v === 'all' ? undefined : v,
                            })
                        }
                    >
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="in_progress">
                                In Progress
                            </SelectItem>
                            <SelectItem value="open">Open</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Trip Table */}
                {tripsData.length > 0 ? (
                    <div className="overflow-hidden rounded-lg border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-muted/50 text-xs tracking-wider text-muted-foreground uppercase">
                                    <th className="w-8 px-2 py-3" />
                                    <th className="px-4 py-3 text-left font-medium">
                                        Vehicle
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Driver
                                    </th>
                                    {renderSortHeader(
                                        'started_at',
                                        'Start Time',
                                    )}
                                    <th className="px-4 py-3 text-left font-medium">
                                        End Time
                                    </th>
                                    {renderSortHeader(
                                        'distance_km',
                                        'Distance',
                                    )}
                                    {renderSortHeader('duration_s', 'Duration')}
                                    <th className="px-4 py-3 text-right font-medium">
                                        Max Speed
                                    </th>
                                    {renderSortHeader('status', 'Status')}
                                    <th className="px-4 py-3 text-center font-medium">
                                        Type
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {tripsData.map((trip) => (
                                    <TripRow
                                        key={trip.id}
                                        trip={trip}
                                        expanded={expandedTrip === trip.id}
                                        onToggle={() => toggleTrip(trip.id)}
                                        getPolyline={getPolylineForTrip}
                                        getCenter={getMapCenter}
                                        canManage={canManage}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <FleetEmptyState
                        icon={Route}
                        title="No trips found"
                        description="No trips match your current filters. Try adjusting the date range or vehicle selection."
                    />
                )}

                {/* Pagination */}
                {(meta.last_page ?? 1) > 1 && (
                    <div className="flex items-center justify-center gap-1">
                        {links.map((link, i) => (
                            <Button
                                key={i}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url)}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}

function TripRow({
    trip,
    expanded,
    onToggle,
    getPolyline,
    getCenter,
    canManage,
}: {
    trip: Trip;
    expanded: boolean;
    onToggle: () => void;
    getPolyline: (trip: Trip) => { lat: number; lng: number }[];
    getCenter: (trip: Trip) => { lat: number; lng: number };
    canManage: boolean;
}) {
    const polyline = getPolyline(trip);
    const center = getCenter(trip);
    const segments = trip.segments ?? [];
    const isActive = trip.status === 'open' || trip.status === 'in_progress';

    return (
        <>
            <tr
                className={`cursor-pointer border-b transition-colors hover:bg-muted/30 ${isActive ? 'border-l-2 border-l-purple-500' : ''}`}
                onClick={onToggle}
            >
                <td className="px-2 py-3 text-center">
                    {expanded ? (
                        <ChevronDown className="h-4 w-4 text-muted-foreground" />
                    ) : (
                        <ChevronRight className="h-4 w-4 text-muted-foreground" />
                    )}
                </td>
                <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                        <Route className="h-4 w-4 text-muted-foreground" />
                        <span className="font-medium">
                            {trip.asset?.name ?? '---'}
                        </span>
                    </div>
                </td>
                <td className="px-4 py-3">{trip.driver?.name ?? '---'}</td>
                <td className="px-4 py-3">
                    {trip.started_at ? formatDateTime(trip.started_at) : '---'}
                </td>
                <td className="px-4 py-3">
                    {trip.ended_at ? formatDateTime(trip.ended_at) : '---'}
                </td>
                <td className="px-4 py-3 text-right">
                    {formatDistance(trip.distance_km ?? 0)}
                </td>
                <td className="px-4 py-3 text-right">
                    {formatDuration(trip.duration_s ?? 0)}
                </td>
                <td className="px-4 py-3 text-right">
                    {trip.max_speed_kph != null
                        ? `${trip.max_speed_kph} km/h`
                        : '---'}
                </td>
                <td className="px-4 py-3">
                    <Badge variant={statusVariant(trip.status ?? 'unknown')}>
                        {trip.status ?? 'unknown'}
                    </Badge>
                </td>
                <td className="px-4 py-3 text-center">
                    <div className="flex items-center justify-center gap-1.5">
                        {trip.is_personal && (
                            <Badge className="bg-status-warning text-[10px] text-white">
                                Personal
                            </Badge>
                        )}
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 w-7 p-0"
                            title="Trip playback"
                            asChild
                            onClick={(e) => e.stopPropagation()}
                        >
                            <Link
                                href={`/fleet-assets/trips/${trip.id}/playback`}
                            >
                                <Play className="h-4 w-4 text-muted-foreground" />
                            </Link>
                        </Button>
                        {canManage && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-7 w-7 p-0"
                                title={
                                    trip.is_personal
                                        ? 'Mark as business'
                                        : 'Mark as personal'
                                }
                                onClick={(e) => {
                                    e.stopPropagation();
                                    router.post(
                                        `/fleet-assets/trips/${trip.id}/toggle-personal`,
                                        {},
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                {trip.is_personal ? (
                                    <UserX className="h-4 w-4 text-status-warning" />
                                ) : (
                                    <User className="h-4 w-4 text-muted-foreground" />
                                )}
                            </Button>
                        )}
                    </div>
                </td>
            </tr>
            {expanded && (
                <tr className="border-b bg-muted/10">
                    <td colSpan={10} className="p-4">
                        <div className="grid gap-4 lg:grid-cols-2">
                            {/* Route Map */}
                            <div>
                                <h4 className="mb-2 text-sm font-medium">
                                    Route Map
                                </h4>
                                {polyline.length > 0 ? (
                                    <LeafletMap
                                        center={center}
                                        zoom={13}
                                        polyline={polyline}
                                        markers={[
                                            ...(trip.start_latitude &&
                                            trip.start_longitude
                                                ? [
                                                      {
                                                          id: `start-${trip.id}`,
                                                          lat: trip.start_latitude,
                                                          lng: trip.start_longitude,
                                                          title: 'Start',
                                                          popup:
                                                              trip.start_address ??
                                                              'Trip start',
                                                      },
                                                  ]
                                                : []),
                                            ...(trip.end_latitude &&
                                            trip.end_longitude
                                                ? [
                                                      {
                                                          id: `end-${trip.id}`,
                                                          lat: trip.end_latitude,
                                                          lng: trip.end_longitude,
                                                          title: 'End',
                                                          popup:
                                                              trip.end_address ??
                                                              'Trip end',
                                                      },
                                                  ]
                                                : []),
                                        ]}
                                        height={300}
                                    />
                                ) : (
                                    <div className="flex h-[300px] items-center justify-center rounded-lg border bg-muted/20 text-sm text-muted-foreground">
                                        No route data available
                                    </div>
                                )}
                            </div>

                            {/* Trip Details */}
                            <div className="space-y-4">
                                {/* Addresses */}
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <div className="rounded-md border p-3">
                                        <p className="text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                                            Start Address
                                        </p>
                                        <p className="mt-1 flex items-center gap-1 text-sm">
                                            <MapPin className="h-3 w-3 shrink-0 text-status-success" />
                                            {trip.start_address ?? '---'}
                                        </p>
                                    </div>
                                    <div className="rounded-md border p-3">
                                        <p className="text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                                            End Address
                                        </p>
                                        <p className="mt-1 flex items-center gap-1 text-sm">
                                            <MapPin className="h-3 w-3 shrink-0 text-status-critical" />
                                            {trip.end_address ?? '---'}
                                        </p>
                                    </div>
                                </div>

                                {/* Trip Segments Timeline */}
                                <div>
                                    <h4 className="mb-2 text-sm font-medium">
                                        Trip Segments
                                    </h4>
                                    {segments.length > 0 ? (
                                        <div className="max-h-[200px] space-y-2 overflow-y-auto">
                                            {segments.map((seg, i) => (
                                                <div
                                                    key={seg.id ?? i}
                                                    className="flex items-start gap-3 rounded-md border p-2 text-xs"
                                                >
                                                    <div className="flex flex-col items-center">
                                                        <div className="h-3 w-3 rounded-full bg-primary" />
                                                        {i <
                                                            segments.length -
                                                                1 && (
                                                            <div className="h-full w-px bg-border" />
                                                        )}
                                                    </div>
                                                    <div className="flex-1">
                                                        <div className="font-medium">
                                                            Segment{' '}
                                                            {seg.seq ?? i + 1}
                                                        </div>
                                                        <div className="text-muted-foreground">
                                                            {formatTime(seg.started_at)}
                                                            {' - '}
                                                            {formatTime(seg.ended_at)}
                                                        </div>
                                                        <div className="text-muted-foreground">
                                                            {formatDistance(
                                                                seg.distance_km ??
                                                                    0,
                                                            )}{' '}
                                                            &middot;{' '}
                                                            {formatDuration(
                                                                seg.duration_s ??
                                                                    0,
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">
                                            No segment data available.
                                        </p>
                                    )}
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}
