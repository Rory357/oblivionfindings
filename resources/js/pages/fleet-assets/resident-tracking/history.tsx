import LeafletMap, { type MapMarker } from '@/components/leaflet-map';
import PageShell from '@/components/page-shell';
import { FleetCompactHero } from '@/pages/fleet-assets/components/fleet-compact-hero';
import ResidentSidebar from '@/components/resident-tracking/resident-sidebar';
import type { Resident } from '@/components/resident-tracking/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatRelativeTime } from '@/lib/fleet-utils';
import { Head, router } from '@inertiajs/react';
import {
    Battery,
    BatteryLow,
    Download,
    Filter,
    MapPin,
    Maximize2,
    Minimize2,
    Navigation,
    Plug,
    Radio,
    ShieldAlert,
    Zap,
} from 'lucide-react';
import { useCallback, useMemo, useRef, useState } from 'react';

type Location = {
    lat: number;
    lng: number;
    address?: string | null;
    coordinates?: string | null;
    display_location?: string | null;
    timestamp: string;
    speed: number | null;
    battery: number | null;
    event_type: string | null;
};

type Props = {
    client: {
        id: number;
        name: string;
        house: string;
        photo: string | null;
    };
    resident: Resident | null;
    tracker: {
        id: number;
        device_uid: string | null;
        name: string;
        serial: string | null;
        status: string;
        detail_url: string;
    } | null;
    locations: Location[];
    available_event_types: string[];
    filters: {
        range: string;
        date_from?: string | null;
        date_to?: string | null;
        event_types: string[];
    };
};

const RANGE_PILLS = [
    { value: 'today', label: 'Today' },
    { value: '24h', label: '24h' },
    { value: '7d', label: '7d' },
    { value: '30d', label: '30d' },
    { value: 'custom', label: 'Custom' },
];

const SAFETY_EVENTS = ['vehicle_sos', 'sos', 'man_down', 'battery_low', 'tamper'];
const IMPORTANT_MAP_EVENTS = new Set([
    ...SAFETY_EVENTS,
    'power_on',
    'power_off',
    'charging_started',
    'charging_stopped',
    'charge_full',
]);

type MapPinMode = 'important' | 'balanced' | 'all';

const MAP_PIN_OPTIONS: Array<{ value: MapPinMode; label: string; description: string }> = [
    {
        value: 'important',
        label: 'Important pins',
        description: 'Live, start, end, and safety or battery events',
    },
    {
        value: 'balanced',
        label: 'More pins',
        description: 'Important pins plus regular samples',
    },
    {
        value: 'all',
        label: 'All pins',
        description: 'Every loaded point',
    },
];

function eventTypeMeta(t: string | null): {
    label: string;
    icon: typeof MapPin;
    tone: 'default' | 'safety' | 'battery' | 'power';
} {
    switch (t) {
        case 'vehicle_sos':
        case 'sos':
            return { label: 'SOS', icon: ShieldAlert, tone: 'safety' };
        case 'man_down':
            return { label: 'Man down', icon: ShieldAlert, tone: 'safety' };
        case 'battery_low':
            return { label: 'Battery low', icon: BatteryLow, tone: 'battery' };
        case 'tamper':
            return { label: 'Tamper', icon: ShieldAlert, tone: 'safety' };
        case 'power_on':
            return { label: 'Power on', icon: Zap, tone: 'power' };
        case 'power_off':
            return { label: 'Power off', icon: Zap, tone: 'power' };
        case 'heartbeat':
            return { label: 'Heartbeat', icon: Radio, tone: 'default' };
        case 'location_report':
            return { label: 'Location', icon: MapPin, tone: 'default' };
        default:
            return { label: (t ?? 'Event').replace(/_/g, ' '), icon: MapPin, tone: 'default' };
    }
}

function toneClasses(tone: 'default' | 'safety' | 'battery' | 'power'): string {
    switch (tone) {
        case 'safety':
            return 'border-status-critical/30 bg-status-critical-bg text-status-critical';
        case 'battery':
            return 'border-status-warning/30 bg-status-warning-bg text-status-warning';
        case 'power':
            return 'border-status-info/30 bg-status-info-bg text-status-info';
        default:
            return 'border-border bg-muted/30 text-muted-foreground';
    }
}

function coordinateText(lat: number, lng: number): string {
    return `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
}

function displayLocation(location: Location): string {
    return (
        location.display_location ??
        location.coordinates ??
        coordinateText(location.lat, location.lng)
    );
}

function csvCell(value: unknown): string {
    return `"${String(value ?? '').replace(/"/g, '""')}"`;
}

function chargingStatusLabel(status?: string | null, externalPower?: boolean | null): string | null {
    if (status === 'charging' || externalPower === true) return 'Charging';
    if (status === 'not_charging') return 'Not charging';
    if (status === 'stopped_charging') return 'Stopped charging';
    if (status === 'charge_full') return 'Charge full';

    return null;
}

function uniqueSortedIndices(indices: number[], total: number): number[] {
    return [...new Set(indices.filter((index) => index >= 0 && index < total))].sort(
        (a, b) => a - b,
    );
}

function importantLocationIndices(locations: Location[]): number[] {
    const lastIndex = locations.length - 1;
    const important = [0, lastIndex];

    locations.forEach((location, index) => {
        if (location.event_type && IMPORTANT_MAP_EVENTS.has(location.event_type)) {
            important.push(index);
        }
    });

    return uniqueSortedIndices(important, locations.length);
}

function sampledLocationIndices(locations: Location[], sampleEvery = 10): number[] {
    const important = importantLocationIndices(locations);
    const sampled = locations.map((_, index) => index).filter((index) => index % sampleEvery === 0);

    return uniqueSortedIndices([...important, ...sampled], locations.length);
}

function mapPinIndices(locations: Location[], mode: MapPinMode): number[] {
    if (locations.length === 0) return [];
    if (mode === 'all') return locations.map((_, index) => index);
    if (mode === 'balanced') return sampledLocationIndices(locations);

    return importantLocationIndices(locations);
}

function mapPathIndices(locations: Location[], mode: MapPinMode): number[] {
    if (locations.length === 0) return [];
    if (mode === 'all') return locations.map((_, index) => index);

    const maxPathPoints = mode === 'balanced' ? 120 : 80;
    const step = Math.max(1, Math.ceil(locations.length / maxPathPoints));
    const sampled = locations.map((_, index) => index).filter((index) => index % step === 0);

    return uniqueSortedIndices([...sampled, ...importantLocationIndices(locations)], locations.length);
}

export default function ResidentTrackingHistory({
    client,
    resident,
    tracker,
    locations,
    available_event_types,
    filters,
}: Props) {
    const safeLocations = useMemo(() => locations ?? [], [locations]);
    const safeAvailableTypes = available_event_types ?? [];
    const availableSafetyTypes = SAFETY_EVENTS.filter((t) => safeAvailableTypes.includes(t));

    const [activeRange, setActiveRange] = useState<string>(filters.range ?? '24h');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const [selectedEventTypes, setSelectedEventTypes] = useState<string[]>(
        filters.event_types ?? [],
    );
    const [timelineEventTypes, setTimelineEventTypes] = useState<string[]>([]);
    const [mapPinMode, setMapPinMode] = useState<MapPinMode>('important');
    const [hoveredLocationIdx, setHoveredLocationIdx] = useState<number | null>(null);
    const [activeLocationIdx, setActiveLocationIdx] = useState<number | null>(null);
    const [mapExpanded, setMapExpanded] = useState(false);
    const timelineRef = useRef<HTMLDivElement | null>(null);

    const latestLocation = safeLocations[0] ?? null;
    const firstLocation = safeLocations.length > 0 ? safeLocations[safeLocations.length - 1] : null;

    const applyRange = useCallback(
        (
            range: string,
            overrides: Partial<{ date_from: string; date_to: string; event_types: string[] }> = {},
        ) => {
            const params: Record<string, string> = { range };
            const eventTypes = overrides.event_types ?? selectedEventTypes;
            if (eventTypes.length > 0) {
                params.event_types = eventTypes.join(',');
            }
            if (range === 'custom') {
                const from = overrides.date_from ?? dateFrom;
                const to = overrides.date_to ?? dateTo;
                if (from) params.date_from = from;
                if (to) params.date_to = to;
            }
            router.get(`/fleet-assets/resident-tracking/history/${client.id}`, params, {
                preserveState: true,
                preserveScroll: true,
            });
        },
        [client.id, dateFrom, dateTo, selectedEventTypes],
    );

    const handleRangeClick = (value: string) => {
        setActiveRange(value);
        if (value !== 'custom') {
            applyRange(value);
        }
    };

    const handleEventTypeToggle = (type: string) => {
        const next = selectedEventTypes.includes(type)
            ? selectedEventTypes.filter((t) => t !== type)
            : [...selectedEventTypes, type];
        setSelectedEventTypes(next);
        applyRange(activeRange, { event_types: next });
    };

    const handleSafetyOnly = () => {
        setSelectedEventTypes(availableSafetyTypes);
        applyRange(activeRange, { event_types: availableSafetyTypes });
    };

    const handleClearEventTypes = () => {
        setSelectedEventTypes([]);
        applyRange(activeRange, { event_types: [] });
    };

    const handleTimelineEventTypeToggle = (type: string) => {
        setTimelineEventTypes((current) =>
            current.includes(type)
                ? current.filter((t) => t !== type)
                : [...current, type],
        );
    };

    const handleTimelineSafetyOnly = (checked: boolean | 'indeterminate') => {
        setTimelineEventTypes(checked === true ? availableSafetyTypes : []);
    };

    const handleTimelineClear = () => {
        setTimelineEventTypes([]);
    };

    const mapPointIndices = useMemo(
        () => mapPinIndices(safeLocations, mapPinMode),
        [mapPinMode, safeLocations],
    );

    const mapPathPointIndices = useMemo(
        () => mapPathIndices(safeLocations, mapPinMode),
        [mapPinMode, safeLocations],
    );

    const polyline = useMemo(() => {
        if (mapPathPointIndices.length < 2) return undefined;
        return [...mapPathPointIndices]
            .reverse()
            .map((index) => safeLocations[index])
            .filter(Boolean)
            .map((l) => ({ lat: l.lat, lng: l.lng }));
    }, [mapPathPointIndices, safeLocations]);

    const mapCenter = useMemo(() => {
        if (activeLocationIdx !== null && safeLocations[activeLocationIdx]) {
            return {
                lat: safeLocations[activeLocationIdx].lat,
                lng: safeLocations[activeLocationIdx].lng,
            };
        }
        if (latestLocation) {
            return { lat: latestLocation.lat, lng: latestLocation.lng };
        }
        return { lat: -41.2865, lng: 174.7762 };
    }, [activeLocationIdx, latestLocation, safeLocations]);

    const markers: MapMarker[] = useMemo(() => {
        if (mapPointIndices.length === 0) return [];
        return mapPointIndices.map((index) => {
            const location = safeLocations[index];
            const meta = eventTypeMeta(location.event_type);
            const isLive = index === 0;
            const isHovered = hoveredLocationIdx === index;
            const isActive = activeLocationIdx === index;
            let color = '#eab308';
            if (isLive) color = '#ef4444';
            else if (meta.tone === 'safety') color = '#dc2626';
            else if (meta.tone === 'battery') color = '#f59e0b';
            return {
                id: index,
                lat: location.lat,
                lng: location.lng,
                title: isLive ? 'Live point' : `History point ${index}`,
                type: 'default' as const,
                status: isLive ? 'online' : 'idle',
                color: isHovered || isActive ? '#7c3aed' : color,
                popup: `${meta.label} · ${formatDateTime(location.timestamp)}<br/>${displayLocation(location)}${location.speed != null ? `<br/>Speed: ${location.speed} km/h` : ''}${location.battery != null ? `<br/>Battery: ${location.battery}%` : ''}`,
            };
        });
    }, [activeLocationIdx, hoveredLocationIdx, mapPointIndices, safeLocations]);

    const timelineLocations = useMemo(() => {
        return safeLocations
            .map((location, index) => ({ location, index }))
            .filter(({ location }) => {
                if (timelineEventTypes.length === 0) return true;

                return location.event_type !== null && timelineEventTypes.includes(location.event_type);
            });
    }, [safeLocations, timelineEventTypes]);

    const timelineSafetyOnlySelected =
        availableSafetyTypes.length > 0 &&
        timelineEventTypes.length === availableSafetyTypes.length &&
        availableSafetyTypes.every((t) => timelineEventTypes.includes(t));
    const residentChargingStatus = resident
        ? chargingStatusLabel(resident.charging_status, resident.external_power)
        : null;

    const handleMarkerClick = useCallback((id: string | number) => {
        const idx = typeof id === 'number' ? id : parseInt(String(id), 10);
        setActiveLocationIdx(idx);
        const row = timelineRef.current?.querySelector(`[data-location-idx="${idx}"]`);
        if (row && row instanceof HTMLElement) {
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, []);

    const handleExport = () => {
        if (safeLocations.length === 0) return;
        const csvHeader = 'Address,Latitude,Longitude,Timestamp,Speed,Battery,Event\n';
        const csvBody = safeLocations
            .map((l) =>
                [
                    csvCell(l.address ?? ''),
                    l.lat,
                    l.lng,
                    csvCell(l.timestamp ?? ''),
                    l.speed ?? '',
                    l.battery ?? '',
                    csvCell(l.event_type ?? ''),
                ].join(','),
            )
            .join('\n');
        const blob = new Blob([csvHeader + csvBody], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `location-history-${client?.name?.replace(/\s+/g, '-')}-${new Date()
            .toISOString()
            .slice(0, 10)}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Resident Tracking', href: '/fleet-assets/resident-tracking' },
                { title: client?.name ?? 'History', href: '#' },
            ]}
        >
            <Head title={`Location History — ${client?.name ?? ''}`} />
            <PageShell>
                <FleetCompactHero
                    pill={`Resident tracking · ${client?.name ?? 'location history'}`}
                    title="Location History"
                    backHref="/fleet-assets/resident-tracking"
                    backLabel="Tracking"
                />

                {/* Header strip */}
                <Card unstyled className="flex flex-wrap items-center gap-3 rounded-lg border bg-card p-3">
                    <div className="flex items-center gap-3">
                        <img
                            src={client?.photo ?? '/images/avatar-placeholder.svg'}
                            alt={client?.name ?? ''}
                            className="h-10 w-10 rounded-full border object-cover"
                        />
                        <div className="leading-tight">
                            <div className="text-sm font-semibold">{client?.name ?? '—'}</div>
                            <div className="text-xs text-muted-foreground">{client?.house ?? '—'}</div>
                        </div>
                    </div>
                    <span className="hidden h-8 border-l sm:block" />
                    {tracker ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="flex items-center gap-1.5 text-sm">
                                <Radio className="h-3.5 w-3.5 text-status-info" />
                                <span className="font-medium">{tracker.name}</span>
                            </div>
                            <Badge variant="outline" className="text-[10px] capitalize">
                                {tracker.status}
                            </Badge>
                            {tracker.serial && (
                                <span className="text-xs text-muted-foreground">{tracker.serial}</span>
                            )}
                        </div>
                    ) : (
                        <span className="text-sm text-muted-foreground">No tracker assigned</span>
                    )}
                    {resident && (
                        <div className="ml-auto flex flex-wrap items-center gap-2 text-xs">
                            {resident.battery != null && (
                                <span className="flex items-center gap-1">
                                    <Battery className="h-3.5 w-3.5" />
                                    {resident.battery}%
                                </span>
                            )}
                            {resident.battery_voltage_mv != null && (
                                <span className="text-muted-foreground">
                                    {(resident.battery_voltage_mv / 1000).toFixed(2)} V
                                </span>
                            )}
                            {residentChargingStatus && (
                                <Badge
                                    variant="outline"
                                    aria-label={`Charging status: ${residentChargingStatus}`}
                                    className={`text-[10px] ${
                                        residentChargingStatus === 'Charging'
                                            ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                            : ''
                                    }`}
                                >
                                    <Plug className="mr-1 h-3 w-3" />
                                    {residentChargingStatus}
                                </Badge>
                            )}
                            <Badge variant="outline" className="text-[10px]">
                                {resident.geofence_status === 'in_zone'
                                    ? 'In Zone'
                                    : resident.geofence_status === 'outside_zone'
                                      ? 'Outside'
                                      : 'Zone Unknown'}
                            </Badge>
                            <span className="text-muted-foreground">
                                Last seen {formatRelativeTime(resident.last_seen_at)}
                            </span>
                        </div>
                    )}
                </Card>

                {/* Summary line */}
                <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">{safeLocations.length}</span>
                    <span>points</span>
                    {firstLocation && latestLocation && (
                        <>
                            <span>·</span>
                            <span>
                                from {formatDateTime(firstLocation.timestamp)} to{' '}
                                {formatDateTime(latestLocation.timestamp)}
                            </span>
                        </>
                    )}
                    {latestLocation && (
                        <>
                            <span>·</span>
                            <span>Live point {formatRelativeTime(latestLocation.timestamp)}</span>
                        </>
                    )}
                    {safeLocations.length > 0 && (
                        <>
                            <span>·</span>
                            <span>
                                {markers.length} of {safeLocations.length} pins shown
                            </span>
                        </>
                    )}
                </div>

                {/* Quick-range pills + event filter + export */}
                <div className="flex flex-wrap items-center gap-2">
                    {RANGE_PILLS.map((pill) => (
                        <Button
                            key={pill.value}
                            type="button"
                            variant={activeRange === pill.value ? 'default' : 'outline'}
                            size="sm"
                            className="h-8"
                            onClick={() => handleRangeClick(pill.value)}
                        >
                            {pill.label}
                        </Button>
                    ))}
                    {activeRange === 'custom' && (
                        <div className="flex items-center gap-2">
                            <Label className="sr-only" htmlFor="hist-date-from">
                                From
                            </Label>
                            <Input
                                id="hist-date-from"
                                type="date"
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                                className="h-8 w-36"
                            />
                            <Label className="sr-only" htmlFor="hist-date-to">
                                To
                            </Label>
                            <Input
                                id="hist-date-to"
                                type="date"
                                value={dateTo}
                                onChange={(e) => setDateTo(e.target.value)}
                                className="h-8 w-36"
                            />
                            <Button size="sm" className="h-8" onClick={() => applyRange('custom')}>
                                Apply
                            </Button>
                        </div>
                    )}

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" size="sm" className="h-8 gap-1">
                                <Filter className="h-3.5 w-3.5" />
                                {selectedEventTypes.length === 0
                                    ? 'All events'
                                    : `${selectedEventTypes.length} event type${selectedEventTypes.length > 1 ? 's' : ''}`}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent className="w-56">
                            <DropdownMenuLabel className="flex items-center justify-between">
                                Event types
                                {selectedEventTypes.length > 0 && (
                                    <Button unstyled
                                        type="button"
                                        onClick={handleClearEventTypes}
                                        className="text-[10px] text-muted-foreground hover:underline"
                                    >
                                        Clear
                                    </Button>
                                )}
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuCheckboxItem
                                checked={SAFETY_EVENTS.every((t) => selectedEventTypes.includes(t))}
                                onCheckedChange={handleSafetyOnly}
                            >
                                <ShieldAlert className="mr-2 h-3.5 w-3.5 text-status-critical" />
                                Safety only
                            </DropdownMenuCheckboxItem>
                            <DropdownMenuSeparator />
                            {safeAvailableTypes.length === 0 && (
                                <div className="px-2 py-1.5 text-xs text-muted-foreground">
                                    No event types in this range
                                </div>
                            )}
                            {safeAvailableTypes.map((t) => {
                                const meta = eventTypeMeta(t);
                                const Icon = meta.icon;
                                return (
                                    <DropdownMenuCheckboxItem
                                        key={t}
                                        checked={selectedEventTypes.includes(t)}
                                        onCheckedChange={() => handleEventTypeToggle(t)}
                                    >
                                        <Icon className="mr-2 h-3.5 w-3.5" />
                                        {meta.label}
                                    </DropdownMenuCheckboxItem>
                                );
                            })}
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <Card unstyled className="flex flex-wrap items-center gap-1 rounded-md border bg-background p-1">
                        <span className="px-2 text-xs font-medium text-muted-foreground">
                            Map pins
                        </span>
                        {MAP_PIN_OPTIONS.map((option) => (
                            <Button
                                key={option.value}
                                type="button"
                                variant={mapPinMode === option.value ? 'default' : 'ghost'}
                                size="sm"
                                className="h-7 px-2 text-xs"
                                aria-pressed={mapPinMode === option.value}
                                title={option.description}
                                onClick={() => setMapPinMode(option.value)}
                            >
                                {option.label}
                            </Button>
                        ))}
                    </Card>

                    <Button
                        variant="outline"
                        size="sm"
                        className="ml-auto h-8 gap-1"
                        onClick={handleExport}
                        disabled={safeLocations.length === 0}
                    >
                        <Download className="h-3.5 w-3.5" />
                        Export CSV
                    </Button>
                </div>

                {/* Map + timeline grid */}
                <div className={`grid gap-4 ${mapExpanded ? '' : 'lg:grid-cols-[3fr_2fr]'}`}>
                    <Card className="relative overflow-hidden">
                        <CardContent className="p-0">
                            {safeLocations.length === 0 ? (
                                <div className="flex h-[600px] flex-col items-center justify-center text-muted-foreground">
                                    <MapPin className="h-10 w-10 opacity-30" />
                                    <p className="mt-2 text-sm">No movement in this range</p>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="mt-3"
                                        onClick={() => handleRangeClick('7d')}
                                    >
                                        Try 7-day range
                                    </Button>
                                </div>
                            ) : (
                                <LeafletMap
                                    center={mapCenter}
                                    zoom={activeLocationIdx !== null ? 17 : 15}
                                    markers={markers}
                                    polyline={polyline}
                                    polylineOptions={{
                                        animated: true,
                                        showArrows: true,
                                        showEndpoints: true,
                                        color: '#7c3aed',
                                    }}
                                    height={600}
                                    onMarkerClick={handleMarkerClick}
                                />
                            )}
                            <Button unstyled
                                type="button"
                                onClick={() => setMapExpanded((v) => !v)}
                                className="absolute right-3 top-3 z-[400] rounded-md border bg-background/90 p-1.5 text-muted-foreground shadow-sm backdrop-blur hover:text-foreground"
                                title={mapExpanded ? 'Restore' : 'Expand'}
                            >
                                {mapExpanded ? (
                                    <Minimize2 className="h-3.5 w-3.5" />
                                ) : (
                                    <Maximize2 className="h-3.5 w-3.5" />
                                )}
                            </Button>
                        </CardContent>
                    </Card>

                    {!mapExpanded && (
                        <Card className="flex flex-col">
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                                <div>
                                    <div className="text-sm font-medium">Timeline</div>
                                    {safeLocations.length > 0 && (
                                        <div className="text-xs text-muted-foreground">
                                            {timelineLocations.length} of {safeLocations.length} shown
                                        </div>
                                    )}
                                </div>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="outline" size="sm" className="h-8 gap-1">
                                            <Filter className="h-3.5 w-3.5" />
                                            {timelineEventTypes.length === 0
                                                ? 'Timeline events'
                                                : `${timelineEventTypes.length} timeline type${timelineEventTypes.length > 1 ? 's' : ''}`}
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent className="w-56">
                                        <DropdownMenuLabel className="flex items-center justify-between">
                                            Timeline events
                                            {timelineEventTypes.length > 0 && (
                                                <Button unstyled
                                                    type="button"
                                                    onClick={handleTimelineClear}
                                                    className="text-[10px] text-muted-foreground hover:underline"
                                                >
                                                    Clear
                                                </Button>
                                            )}
                                        </DropdownMenuLabel>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuCheckboxItem
                                            checked={timelineSafetyOnlySelected}
                                            onCheckedChange={handleTimelineSafetyOnly}
                                        >
                                            <ShieldAlert className="mr-2 h-3.5 w-3.5 text-status-critical" />
                                            Safety only
                                        </DropdownMenuCheckboxItem>
                                        <DropdownMenuSeparator />
                                        {safeAvailableTypes.length === 0 && (
                                            <div className="px-2 py-1.5 text-xs text-muted-foreground">
                                                No event types in this range
                                            </div>
                                        )}
                                        {safeAvailableTypes.map((t) => {
                                            const meta = eventTypeMeta(t);
                                            const Icon = meta.icon;

                                            return (
                                                <DropdownMenuCheckboxItem
                                                    key={t}
                                                    checked={timelineEventTypes.includes(t)}
                                                    onCheckedChange={() =>
                                                        handleTimelineEventTypeToggle(t)
                                                    }
                                                >
                                                    <Icon className="mr-2 h-3.5 w-3.5" />
                                                    {meta.label}
                                                </DropdownMenuCheckboxItem>
                                            );
                                        })}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                            <div
                                ref={timelineRef}
                                className="flex-1 overflow-y-auto"
                                style={{ maxHeight: 600 }}
                            >
                                {safeLocations.length === 0 ? (
                                    <div className="p-6 text-center text-sm text-muted-foreground">
                                        No events to display.
                                    </div>
                                ) : timelineLocations.length === 0 ? (
                                    <div className="p-6 text-center text-sm text-muted-foreground">
                                        No events match this timeline filter.
                                    </div>
                                ) : (
                                    <div className="divide-y">
                                        {timelineLocations.map(({ location, index: idx }) => {
                                            const meta = eventTypeMeta(location.event_type);
                                            const Icon = meta.icon;
                                            const isLive = idx === 0;
                                            const isActive = activeLocationIdx === idx;
                                            return (
                                                <Button unstyled
                                                    key={`${location.timestamp}-${idx}`}
                                                    type="button"
                                                    data-location-idx={idx}
                                                    onMouseEnter={() => setHoveredLocationIdx(idx)}
                                                    onMouseLeave={() =>
                                                        setHoveredLocationIdx((cur) =>
                                                            cur === idx ? null : cur,
                                                        )
                                                    }
                                                    onClick={() =>
                                                        setActiveLocationIdx(isActive ? null : idx)
                                                    }
                                                    className={`flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/40 ${
                                                        isActive ? 'bg-primary/5' : ''
                                                    }`}
                                                >
                                                    <div
                                                        className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full border ${toneClasses(meta.tone)}`}
                                                    >
                                                        <Icon className="h-3.5 w-3.5" />
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                                            <span>{formatDateTime(location.timestamp)}</span>
                                                            {isLive && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="border-status-success/30 text-[10px] text-status-success"
                                                                >
                                                                    Live
                                                                </Badge>
                                                            )}
                                                            <Badge
                                                                variant="outline"
                                                                className={`text-[10px] ${toneClasses(meta.tone)}`}
                                                            >
                                                                {meta.label}
                                                            </Badge>
                                                        </div>
                                                        <p className="mt-0.5 truncate text-sm">
                                                            {displayLocation(location)}
                                                        </p>
                                                        <div className="mt-0.5 flex items-center gap-3 text-[11px] text-muted-foreground">
                                                            {location.address && location.coordinates && (
                                                                <span>{location.coordinates}</span>
                                                            )}
                                                            {location.speed != null && (
                                                                <span className="flex items-center gap-1">
                                                                    <Navigation className="h-3 w-3" />
                                                                    {location.speed} km/h
                                                                </span>
                                                            )}
                                                            {location.battery != null && (
                                                                <span>{location.battery}% battery</span>
                                                            )}
                                                        </div>
                                                    </div>
                                                </Button>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>
                        </Card>
                    )}
                </div>

                {/* Condensed Device & Battery panel below the map */}
                {resident && (
                    <Card>
                        <CardContent className="p-4">
                            <ResidentSidebar
                                resident={resident}
                                variant="profile-detail"
                                canManage={false}
                            />
                        </CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
