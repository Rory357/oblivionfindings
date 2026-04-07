import LeafletMap from '@/components/leaflet-map';
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

export default function ResidentTrackingHistory({ client, tracker, locations, filters }: Props) {
    const [dateFrom, setDateFrom] = useState(filters?.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters?.date_to ?? '');

    const polyline = useMemo(() => {
        if (!locations || locations.length < 2) return undefined;
        // Reverse to get chronological order for polyline
        return [...locations].reverse().map((l) => ({ lat: l.lat, lng: l.lng }));
    }, [locations]);

    const mapCenter = useMemo(() => {
        if (locations && locations.length > 0) {
            const first = locations[0];
            return { lat: first.lat, lng: first.lng };
        }
        return { lat: -41.2865, lng: 174.7762 };
    }, [locations]);

    const handleFilter = () => {
        router.get(`/fleet-assets/resident-tracking/history/${client?.id}`, {
            date_from: dateFrom || undefined,
            date_to: dateTo || undefined,
        }, { preserveState: true });
    };

    const handleExport = () => {
        if (!locations || locations.length === 0) return;
        const csvHeader = 'Latitude,Longitude,Timestamp,Speed,Battery,Event\n';
        const csvBody = locations
            .map((l) => `${l.lat},${l.lng},${l.timestamp ?? ''},${l.speed ?? ''},${l.battery ?? ''},${l.event_type ?? ''}`)
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
                    <Card className="border bg-purple-50 dark:bg-purple-950/30">
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
                    <Card className="border bg-blue-50 dark:bg-blue-950/30">
                        <CardContent className="flex items-center gap-4 p-4">
                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/40">
                                <Radio className="h-6 w-6 text-blue-600 dark:text-blue-400" />
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
                                    router.get(`/fleet-assets/resident-tracking/history/${client?.id}`, {}, { preserveState: true });
                                }}
                            >
                                <RotateCcw className="mr-2 h-4 w-4" />
                                Reset
                            </Button>
                            <Badge variant="secondary" className="ml-auto text-xs">
                                {locations?.length ?? 0} location points
                            </Badge>
                        </div>
                    </CardContent>
                </Card>

                {/* Map + Timeline Split */}
                <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                    {/* Map with Polyline */}
                    <Card>
                        <CardContent className="p-0">
                            <LeafletMap
                                center={mapCenter}
                                zoom={locations && locations.length > 0 ? 14 : 6}
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
                                            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-purple-100 dark:bg-purple-900/30">
                                                <MapPin className="h-4 w-4 text-purple-600 dark:text-purple-400" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="text-xs text-muted-foreground">
                                                    {formatDateTime(loc.timestamp)}
                                                </p>
                                                <p className="text-sm">
                                                    {loc.lat.toFixed(5)}, {loc.lng.toFixed(5)}
                                                </p>
                                                <div className="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
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
