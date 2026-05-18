import LeafletMap, { type MapMarker } from '@/components/leaflet-map';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatRelativeTime } from '@/lib/fleet-utils';
import { Head, router } from '@inertiajs/react';
import {
    Calendar,
    Clock,
    Download,
    MapPin,
    Navigation,
    Radio,
    RotateCcw,
} from 'lucide-react';
import { useMemo, useState } from 'react';

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
    tracker: {
        id: number;
        name: string;
        serial: string | null;
        status: string;
    } | null;
    locations: Location[];
    filters: {
        date_from?: string;
        date_to?: string;
    };
};

function coordinateText(lat: number, lng: number): string {
    return `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
}

function displayLocation(location: Location): string {
    return location.display_location ?? location.coordinates ?? coordinateText(location.lat, location.lng);
}

function csvCell(value: unknown): string {
    return `"${String(value ?? '').replace(/"/g, '""')}"`;
}

export default function ResidentTrackingHistory({ client, tracker, locations, filters }: Props) {
    const [dateFrom, setDateFrom] = useState(filters?.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters?.date_to ?? '');
    const [showAllPoints, setShowAllPoints] = useState(false);
    const latestLocation = locations?.[0] ?? null;
    const firstLocation = locations && locations.length > 0 ? locations[locations.length - 1] : null;

    const polyline = useMemo(() => {
        if (!showAllPoints || !locations || locations.length < 2) return undefined;
        return [...locations].reverse().map((l) => ({ lat: l.lat, lng: l.lng }));
    }, [locations, showAllPoints]);

    const mapCenter = useMemo(() => {
        if (latestLocation) {
            return { lat: latestLocation.lat, lng: latestLocation.lng };
        }
        return { lat: -41.2865, lng: 174.7762 };
    }, [latestLocation]);

    const markers: MapMarker[] = useMemo(() => {
        const visibleLocations = showAllPoints
            ? (locations ?? [])
            : latestLocation
                ? [latestLocation]
                : [];

        return visibleLocations.map((location, index) => ({
            id: showAllPoints ? `location-${index}` : 'live-location',
            lat: location.lat,
            lng: location.lng,
            title: index === 0 ? `${client?.name ?? 'Resident'} live location` : `${client?.name ?? 'Resident'} history point`,
            type: 'default' as const,
            status: index === 0 ? 'online' : 'idle',
            color: index === 0 ? '#ef4444' : '#eab308',
            popup: `${index === 0 ? 'Live location' : 'History point'}<br/>${displayLocation(location)}${location.address && location.coordinates ? `<br/>Coordinates: ${location.coordinates}` : ''}<br/>${formatDateTime(location.timestamp)}`,
        }));
    }, [client?.name, latestLocation, locations, showAllPoints]);

    const handleFilter = () => {
        router.get(`/fleet-assets/resident-tracking/history/${client?.id}`, {
            date_from: dateFrom || undefined,
            date_to: dateTo || undefined,
        }, { preserveState: true });
    };

    const handleExport = () => {
        if (!locations || locations.length === 0) return;
        const csvHeader = 'Address,Latitude,Longitude,Timestamp,Speed,Battery,Event\n';
        const csvBody = locations
            .map((l) => [
                csvCell(l.address ?? ''),
                l.lat,
                l.lng,
                csvCell(l.timestamp ?? ''),
                l.speed ?? '',
                l.battery ?? '',
                csvCell(l.event_type ?? ''),
            ].join(','))
            .join('\n');
        const blob = new Blob([csvHeader + csvBody], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `location-history-${client?.name?.replace(/\s+/g, '-')}-${new Date().toISOString().slice(0, 10)}.csv`;
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
            <Head title={`Location History - ${client?.name ?? ''}`} />
            <PageShell>
                <FleetHero
                    title="Location History"
                    subtitle={client?.name ?? ''}
                    backHref="/fleet-assets/resident-tracking"
                    backLabel="Back to Tracking"
                    actions={
                        <Button variant="outline" onClick={handleExport} disabled={!locations || locations.length === 0}>
                            <Download className="mr-2 h-4 w-4" />
                            Export CSV
                        </Button>
                    }
                />

                {/* Resident + Tracker Info */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Card className="border bg-primary/10 dark:bg-primary/30">
                        <CardContent className="flex items-center gap-4 p-4">
                            <img
                                src={client?.photo ?? '/images/avatar-placeholder.svg'}
                                alt={client?.name ?? ''}
                                className="h-14 w-14 rounded-full object-cover border-2 border-white shadow"
                            />
                            <div>
                                <p className="text-lg font-semibold">{client?.name ?? '---'}</p>
                                <p className="text-sm text-muted-foreground">{client?.house ?? '---'}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border bg-status-info-bg">
                        <CardContent className="flex items-center gap-4 p-4">
                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-status-info-bg">
                                <Radio className="h-6 w-6 text-status-info dark:text-status-info" />
                            </div>
                            <div>
                                {tracker ? (
                                    <>
                                        <p className="font-semibold">{tracker.name}</p>
                                        <p className="text-sm text-muted-foreground">
                                            {tracker.serial ?? 'No serial'} &middot;{' '}
                                            <Badge variant="secondary" className="text-[10px] capitalize">
                                                {tracker.status}
                                            </Badge>
                                        </p>
                                    </>
                                ) : (
                                    <p className="text-sm text-muted-foreground">No tracker assigned</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Date Range Filter */}
                <Card>
                    <CardContent className="p-4">
                        <div className="flex flex-wrap items-end gap-4">
                            <div className="space-y-1">
                                <Label className="text-xs">From</Label>
                                <Input
                                    type="date"
                                    value={dateFrom}
                                    onChange={(e) => setDateFrom(e.target.value)}
                                    className="w-40"
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">To</Label>
                                <Input
                                    type="date"
                                    value={dateTo}
                                    onChange={(e) => setDateTo(e.target.value)}
                                    className="w-40"
                                />
                            </div>
                            <Button onClick={handleFilter} size="sm">
                                <Calendar className="mr-2 h-4 w-4" />
                                Filter
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setDateFrom('');
                                    setDateTo('');
                                    setShowAllPoints(false);
                                    router.get(`/fleet-assets/resident-tracking/history/${client?.id}`, {}, { preserveState: true });
                                }}
                            >
                                <RotateCcw className="mr-2 h-4 w-4" />
                                Reset
                            </Button>
                            {(locations?.length ?? 0) > 1 && (
                                <Button
                                    variant={showAllPoints ? 'secondary' : 'outline'}
                                    size="sm"
                                    onClick={() => setShowAllPoints((value) => !value)}
                                >
                                    <MapPin className="mr-2 h-4 w-4" />
                                    {showAllPoints ? 'Live point only' : 'View all points'}
                                </Button>
                            )}
                            <Badge variant={showAllPoints ? 'default' : 'secondary'} className="text-xs">
                                {showAllPoints
                                    ? `Showing all ${locations?.length ?? 0} points`
                                    : 'Showing live point'}
                            </Badge>
                            <Badge variant="secondary" className="ml-auto text-xs">
                                {locations?.length ?? 0} location points
                            </Badge>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-xs text-muted-foreground">Live point</p>
                            <p className="mt-1 text-sm font-semibold">
                                {latestLocation ? formatRelativeTime(latestLocation.timestamp) : 'Not reported'}
                            </p>
                            {latestLocation && (
                                <p className="mt-1 truncate text-xs text-muted-foreground">
                                    {displayLocation(latestLocation)}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-xs text-muted-foreground">History starts</p>
                            <p className="mt-1 text-sm font-semibold">
                                {firstLocation ? formatDateTime(firstLocation.timestamp) : 'No history'}
                            </p>
                            {firstLocation && (
                                <p className="mt-1 truncate text-xs text-muted-foreground">
                                    {displayLocation(firstLocation)}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-xs text-muted-foreground">Points in view</p>
                            <p className="mt-1 text-sm font-semibold">
                                {showAllPoints ? (locations?.length ?? 0) : latestLocation ? 1 : 0}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {showAllPoints ? 'Full history visible on map' : 'Map kept on current location'}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-xs text-muted-foreground">Latest event</p>
                            <p className="mt-1 text-sm font-semibold">
                                {latestLocation?.event_type
                                    ? latestLocation.event_type.replace(/[._]/g, ' ')
                                    : 'Location report'}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {latestLocation?.speed != null ? `${latestLocation.speed} km/h` : 'Speed not reported'}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Map + Timeline Split */}
                <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                    {/* Map with Polyline */}
                    <Card>
                        <CardContent className="p-0">
                            <LeafletMap
                                center={mapCenter}
                                zoom={locations && locations.length > 0 ? 14 : 6}
                                markers={markers}
                                polyline={polyline}
                                polylineOptions={{
                                    animated: true,
                                    showArrows: true,
                                    showEndpoints: true,
                                    color: '#7c3aed',
                                }}
                                height={480}
                            />
                        </CardContent>
                    </Card>

                    {/* Timeline */}
                    <Card className="flex flex-col">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Clock className="h-4 w-4" />
                                Timeline
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex-1 overflow-y-auto p-0" style={{ maxHeight: '420px' }}>
                            {!locations || locations.length === 0 ? (
                                <div className="p-6 text-center text-sm text-muted-foreground">
                                    No location data available for the selected period.
                                </div>
                            ) : (
                                <div className="divide-y">
                                    {locations.map((loc, i) => (
                                        <div key={i} className="flex items-start gap-3 px-4 py-3">
                                            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 dark:bg-primary/30">
                                                <MapPin className="h-4 w-4 text-primary dark:text-primary" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="text-xs text-muted-foreground">
                                                        {formatDateTime(loc.timestamp)}
                                                    </p>
                                                    {i === 0 && (
                                                        <Badge variant="default" className="text-[10px]">
                                                            Current
                                                        </Badge>
                                                    )}
                                                </div>
                                                <p className="text-sm">
                                                    {displayLocation(loc)}
                                                </p>
                                                <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                    {loc.address && loc.coordinates && (
                                                        <span>{loc.coordinates}</span>
                                                    )}
                                                    {loc.speed != null && (
                                                        <span className="flex items-center gap-1">
                                                            <Navigation className="h-3 w-3" />
                                                            {loc.speed} km/h
                                                        </span>
                                                    )}
                                                    {loc.battery != null && (
                                                        <span>{loc.battery}% battery</span>
                                                    )}
                                                    {loc.event_type && (
                                                        <Badge variant="outline" className="text-[10px]">
                                                            {loc.event_type}
                                                        </Badge>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
