import LeafletMap, { type MapMarker, type MapGeofence } from '@/components/leaflet-map';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { formatDateTime, formatRelativeTime } from '@/lib/fleet-utils';
import { Link, router } from '@inertiajs/react';
import {
    Battery,
    BatteryLow,
    Calendar,
    Clock,
    Download,
    ExternalLink,
    MapPin,
    Navigation,
    Radio,
    RotateCcw,
    Shield,
    ShieldOff,
    Wifi,
    WifiOff,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

type Location = {
    lat: number;
    lng: number;
    timestamp: string;
    speed: number | null;
    battery: number | null;
};

type CommandStatus = 'queued' | 'sent' | 'acked' | 'failed' | 'expired' | null;

type Geofence = {
    id: string;
    name?: string;
    type: 'circle' | 'polygon';
    center?: { lat: number; lng: number };
    radius_m?: number;
    coordinates?: { lat: number; lng: number }[];
    color?: string;
};

export type ClientLocationData = {
    tracker: {
        id: number;
        name: string;
        serial: string | null;
        mac: string | null;
        provider: string | null;
        status: string;
        last_seen_at: string | null;
        battery: number | null;
        locate_now_url?: string;
        last_command_status?: CommandStatus;
    } | null;
    currentLocation: {
        lat: number;
        lng: number;
        speed: number | null;
        heading: number | null;
        accuracy: number | null;
    } | null;
    trackingConsent: {
        status: string;
        given_at: string | null;
        expires_at: string | null;
    } | null;
    geofences: Geofence[];
};

type Props = {
    clientId: number;
    clientName: string;
    location: ClientLocationData;
};

const REFRESH_INTERVAL = 30_000;

function commandStatusLabel(status?: CommandStatus): string | null {
    switch (status) {
        case 'queued':
            return 'Queued';
        case 'sent':
            return 'Sent';
        case 'acked':
            return 'Acknowledged';
        case 'failed':
            return 'Failed';
        case 'expired':
            return 'Expired';
        default:
            return null;
    }
}

export default function ClientLocationTab({ clientId, clientName, location }: Props) {
    const { tracker, currentLocation, trackingConsent, geofences } = location;

    const [showHistory, setShowHistory] = useState(false);
    const [historyLocations, setHistoryLocations] = useState<Location[]>([]);
    const [loadingHistory, setLoadingHistory] = useState(false);
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');

    // Auto-refresh
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({ only: ['location'] });
        }, REFRESH_INTERVAL);
        return () => clearInterval(interval);
    }, []);

    const mapCenter = useMemo(() => {
        if (currentLocation) return { lat: currentLocation.lat, lng: currentLocation.lng };
        return { lat: -41.2865, lng: 174.7762 };
    }, [currentLocation]);

    const markers: MapMarker[] = useMemo(() => {
        if (!currentLocation) return [];
        return [{
            id: `client-${clientId}`,
            lat: currentLocation.lat,
            lng: currentLocation.lng,
            title: clientName,
            type: 'default',
            status: tracker?.status === 'online' ? 'online' : 'offline',
            heading: currentLocation.heading ?? undefined,
            speed: currentLocation.speed ?? undefined,
            popup: `<strong>${clientName}</strong><br/>
                ${currentLocation.speed != null ? `Speed: ${currentLocation.speed} km/h<br/>` : ''}
                ${tracker?.battery != null ? `Battery: ${tracker.battery}%<br/>` : ''}
                ${currentLocation.accuracy != null ? `Accuracy: ${currentLocation.accuracy}m<br/>` : ''}
                Last seen: ${formatRelativeTime(tracker?.last_seen_at)}`,
        }];
    }, [currentLocation, clientId, clientName, tracker]);

    const polyline = useMemo(() => {
        if (!showHistory || historyLocations.length < 2) return undefined;
        return [...historyLocations].reverse().map((l) => ({ lat: l.lat, lng: l.lng }));
    }, [showHistory, historyLocations]);

    const fetchHistory = useCallback(() => {
        setLoadingHistory(true);
        const params = new URLSearchParams();
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);

        fetch(`/operations/clients/${clientId}/location/history?${params.toString()}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((res) => res.json())
            .then((data) => {
                setHistoryLocations(data.locations ?? []);
                setShowHistory(true);
            })
            .catch(() => setHistoryLocations([]))
            .finally(() => setLoadingHistory(false));
    }, [clientId, dateFrom, dateTo]);

    const handleExport = () => {
        if (historyLocations.length === 0) return;
        const csvHeader = 'Latitude,Longitude,Timestamp,Speed,Battery\n';
        const csvBody = historyLocations
            .map((l) => `${l.lat},${l.lng},${l.timestamp ?? ''},${l.speed ?? ''},${l.battery ?? ''}`)
            .join('\n');
        const blob = new Blob([csvHeader + csvBody], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `location-${clientName.replace(/\s+/g, '-')}-${new Date().toISOString().slice(0, 10)}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    };

    const hasConsent = trackingConsent?.status === 'active' || trackingConsent?.status === 'granted';
    const hasTracker = tracker !== null;
    const isOnline = tracker?.status === 'online';
    const commandLabel = commandStatusLabel(tracker?.last_command_status);
    const locateNowUrl = tracker?.locate_now_url ?? `/operations/clients/${clientId}/location/locate-now`;
    const handleLocateNow = useCallback(() => {
        router.post(locateNowUrl, {}, { preserveScroll: true });
    }, [locateNowUrl]);

    return (
        <div className="space-y-4 mt-4">
            {/* Consent Warning */}
            {!hasConsent && (
                <Card className="border-status-warning/30 bg-status-warning-bg dark:border-status-warning/30">
                    <CardContent className="flex items-center gap-3 p-4">
                        <ShieldOff className="h-5 w-5 text-status-warning dark:text-status-warning shrink-0" />
                        <div>
                            <p className="font-medium text-status-warning dark:text-status-warning">Location Tracking Consent Not Active</p>
                            <p className="text-sm text-status-warning dark:text-status-warning">
                                Location tracking requires active consent. Update consent in the Consents tab or contact the care team.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* No Tracker */}
            {!hasTracker && (
                <Card className="border-status-info/30 bg-status-info-bg dark:border-status-info/30">
                    <CardContent className="flex items-center gap-3 p-4">
                        <Radio className="h-5 w-5 text-status-info dark:text-status-info shrink-0" />
                        <div className="flex-1">
                            <p className="font-medium text-status-info dark:text-status-info">No Personal Tracker Assigned</p>
                            <p className="text-sm text-status-info dark:text-status-info">
                                Assign a tracker device from the Fleet & Assets module to enable location tracking.
                            </p>
                        </div>
                        <Link
                            href="/fleet-assets/resident-tracking/assign"
                            className="inline-flex items-center gap-1 rounded-md border border-status-info/30 bg-card px-3 py-1.5 text-xs font-medium text-status-info hover:bg-status-info-bg"
                        >
                            Assign Tracker
                            <ExternalLink className="h-3 w-3" />
                        </Link>
                    </CardContent>
                </Card>
            )}

            {/* Status Cards */}
            {hasTracker && (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {/* Tracker Device */}
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            {isOnline ? (
                                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-status-success-bg">
                                    <Wifi className="h-5 w-5 text-status-success dark:text-status-success" />
                                </div>
                            ) : (
                                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-status-critical-bg">
                                    <WifiOff className="h-5 w-5 text-status-critical dark:text-status-critical" />
                                </div>
                            )}
                            <div>
                                <p className="text-xs text-muted-foreground">Tracker</p>
                                <p className="font-semibold text-sm">{tracker.name}</p>
                                <div className="mt-0.5 flex flex-wrap items-center gap-1">
                                    <Badge variant={isOnline ? 'default' : 'secondary'} className="text-[10px] capitalize">
                                        {tracker.status}
                                    </Badge>
                                    {commandLabel && (
                                        <Badge variant="secondary" className="text-[10px]">
                                            {commandLabel}
                                        </Badge>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Device Info */}
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted dark:bg-muted">
                                <Radio className="h-5 w-5 text-muted-foreground dark:text-muted-foreground" />
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Device</p>
                                <p className="text-sm font-medium">{tracker.serial ?? tracker.mac ?? '---'}</p>
                                {tracker.provider && (
                                    <p className="text-[10px] text-muted-foreground">{tracker.provider}</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Battery */}
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className={`flex h-10 w-10 items-center justify-center rounded-full ${
                                (tracker.battery ?? 100) < 20
                                    ? 'bg-status-critical-bg'
                                    : 'bg-status-success-bg'
                            }`}>
                                {(tracker.battery ?? 100) < 20 ? (
                                    <BatteryLow className="h-5 w-5 text-status-critical dark:text-status-critical" />
                                ) : (
                                    <Battery className="h-5 w-5 text-status-success dark:text-status-success" />
                                )}
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Battery</p>
                                <p className="text-lg font-semibold">
                                    {tracker.battery != null ? `${tracker.battery}%` : '---'}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Last Seen */}
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-status-info-bg">
                                <Clock className="h-5 w-5 text-status-info dark:text-status-info" />
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Last Seen</p>
                                <p className="font-semibold text-sm">
                                    {formatRelativeTime(tracker.last_seen_at)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Consent */}
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 dark:bg-primary/30">
                                <Shield className="h-5 w-5 text-primary dark:text-primary" />
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Consent</p>
                                {hasConsent ? (
                                    <Badge variant="default" className="text-[10px] capitalize bg-status-success">
                                        {trackingConsent?.status ?? 'Active'}
                                    </Badge>
                                ) : (
                                    <Badge variant="secondary" className="text-[10px]">Not Active</Badge>
                                )}
                                {trackingConsent?.expires_at && (
                                    <p className="text-[10px] text-muted-foreground mt-0.5">
                                        Expires {formatDateTime(trackingConsent.expires_at)}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            )}

            {/* Map */}
            {hasTracker && (
                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <MapPin className="h-4 w-4" />
                                {showHistory ? 'Movement History' : 'Current Location'}
                            </CardTitle>
                            <div className="flex flex-wrap items-center justify-end gap-3">
                                {currentLocation && (
                                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                        <span className="flex items-center gap-1">
                                            <MapPin className="h-3 w-3" />
                                            {currentLocation.lat.toFixed(5)}, {currentLocation.lng.toFixed(5)}
                                        </span>
                                        {currentLocation.speed != null && (
                                            <span className="flex items-center gap-1">
                                                <Navigation className="h-3 w-3" />
                                                {currentLocation.speed} km/h
                                            </span>
                                        )}
                                        {currentLocation.accuracy != null && (
                                            <span className="text-muted-foreground">
                                                ~{currentLocation.accuracy}m accuracy
                                            </span>
                                        )}
                                    </div>
                                )}
                                <div className="flex items-center gap-2">
                                    {commandLabel && (
                                        <Badge variant="secondary" className="text-[10px]">
                                            {commandLabel}
                                        </Badge>
                                    )}
                                    <Button
                                        type="button"
                                        size="sm"
                                        onClick={handleLocateNow}
                                        disabled={!tracker}
                                    >
                                        <MapPin className="mr-1 h-4 w-4" />
                                        Locate Now
                                    </Button>
                                </div>
                                <Link
                                    href="/fleet-assets/resident-tracking"
                                    className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                >
                                    Fleet Dashboard
                                    <ExternalLink className="h-3 w-3" />
                                </Link>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        {currentLocation ? (
                            <LeafletMap
                                center={mapCenter}
                                zoom={showHistory && historyLocations.length > 0 ? 14 : 16}
                                markers={markers}
                                polyline={polyline}
                                polylineOptions={showHistory ? {
                                    animated: true,
                                    showArrows: true,
                                    showEndpoints: true,
                                    color: '#7c3aed',
                                } : undefined}
                                geofences={geofences as MapGeofence[]}
                                height={420}
                            />
                        ) : (
                            <div className="flex h-[420px] items-center justify-center text-muted-foreground">
                                <div className="text-center">
                                    <MapPin className="mx-auto h-10 w-10 opacity-30" />
                                    <p className="mt-2 text-sm">No location data available</p>
                                    <p className="text-xs">The tracker may be offline or not yet reporting</p>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            )}

            {/* Movement History */}
            {hasTracker && (
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Clock className="h-4 w-4" />
                            Movement History
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
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
                            <Button onClick={fetchHistory} size="sm" disabled={loadingHistory}>
                                <Calendar className="mr-2 h-4 w-4" />
                                {loadingHistory ? 'Loading...' : 'Show History'}
                            </Button>
                            {showHistory && (
                                <>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setShowHistory(false);
                                            setHistoryLocations([]);
                                            setDateFrom('');
                                            setDateTo('');
                                        }}
                                    >
                                        <RotateCcw className="mr-2 h-4 w-4" />
                                        Clear
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={handleExport}
                                        disabled={historyLocations.length === 0}
                                    >
                                        <Download className="mr-2 h-4 w-4" />
                                        Export CSV
                                    </Button>
                                    <Badge variant="secondary" className="ml-auto text-xs">
                                        {historyLocations.length} points
                                    </Badge>
                                </>
                            )}
                        </div>

                        {/* History Timeline */}
                        {showHistory && historyLocations.length > 0 && (
                            <>
                                <Separator className="my-4" />
                                <div className="max-h-[300px] overflow-y-auto divide-y rounded-md border">
                                    {historyLocations.map((loc, i) => (
                                        <div key={i} className="flex items-start gap-3 px-4 py-3">
                                            <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 dark:bg-primary/30">
                                                <MapPin className="h-3.5 w-3.5 text-primary dark:text-primary" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="text-xs text-muted-foreground">
                                                    {formatDateTime(loc.timestamp)}
                                                </p>
                                                <p className="text-sm">
                                                    {loc.lat.toFixed(5)}, {loc.lng.toFixed(5)}
                                                </p>
                                                <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                                    {loc.speed != null && (
                                                        <span className="flex items-center gap-1">
                                                            <Navigation className="h-3 w-3" />
                                                            {loc.speed} km/h
                                                        </span>
                                                    )}
                                                    {loc.battery != null && (
                                                        <span>{loc.battery}% battery</span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </>
                        )}

                        {showHistory && historyLocations.length === 0 && !loadingHistory && (
                            <div className="mt-4 text-center text-sm text-muted-foreground py-8">
                                No movement data found for the selected period.
                            </div>
                        )}
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
