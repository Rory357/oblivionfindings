import LeafletMap, {
    type MapGeofence,
    type MapMarker,
} from '@/components/leaflet-map';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { usePersonalLocationPrivacy } from '@/hooks/use-personal-location-privacy';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatRelativeTime } from '@/lib/fleet-utils';
import { Head, router } from '@inertiajs/react';
import {
    Battery,
    BatteryLow,
    Calendar,
    Clock,
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

type Geofence = {
    id: string;
    name?: string;
    type: 'circle' | 'polygon';
    center?: { lat: number; lng: number };
    radius_m?: number;
    coordinates?: { lat: number; lng: number }[];
    color?: string;
};

type Props = {
    client: {
        id: number;
        first_name: string;
        last_name: string;
        preferred_name?: string | null;
        profile_photo_url?: string | null;
        house?: string;
    };
    tracker: {
        id: number;
        name: string;
        serial: string | null;
        status: string;
        last_seen_at: string | null;
        battery: number | null;
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
    privacyStatusUrl: string;
    retentionDays: number;
};

// Auto-refresh interval (30 seconds)
const REFRESH_INTERVAL = 30_000;

export default function PortalLocation({
    client,
    tracker,
    currentLocation,
    trackingConsent,
    geofences,
    privacyStatusUrl,
}: Props) {
    const clientName =
        client.preferred_name || `${client.first_name} ${client.last_name}`;

    // History state
    const [showHistory, setShowHistory] = useState(false);
    const [historyLocations, setHistoryLocations] = useState<Location[]>([]);
    const [loadingHistory, setLoadingHistory] = useState(false);
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const {
        active: privacyActive,
        checking: privacyChecking,
        message: privacyMessage,
        endAccess,
    } = usePersonalLocationPrivacy({
        statusUrl: privacyStatusUrl,
        onAccessEnded: () => {
            setShowHistory(false);
            setHistoryLocations([]);
        },
    });

    // Auto-refresh current location
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({ only: ['tracker', 'currentLocation'] });
        }, REFRESH_INTERVAL);
        return () => clearInterval(interval);
    }, []);

    // Map center
    const mapCenter = useMemo(() => {
        if (currentLocation)
            return { lat: currentLocation.lat, lng: currentLocation.lng };
        // Default to NZ
        return { lat: -41.2865, lng: 174.7762 };
    }, [currentLocation]);

    // Map markers for current position
    const markers: MapMarker[] = useMemo(() => {
        if (!currentLocation) return [];
        return [
            {
                id: `client-${client.id}`,
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
                Last seen: ${formatRelativeTime(tracker?.last_seen_at)}`,
            },
        ];
    }, [currentLocation, client.id, clientName, tracker]);

    // Polyline from history
    const polyline = useMemo(() => {
        if (!showHistory || historyLocations.length < 2) return undefined;
        return [...historyLocations]
            .reverse()
            .map((l) => ({ lat: l.lat, lng: l.lng }));
    }, [showHistory, historyLocations]);

    // Fetch history
    const fetchHistory = useCallback(() => {
        setLoadingHistory(true);
        const params = new URLSearchParams();
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);

        fetch(
            `/portal/clients/${client.id}/location/history?${params.toString()}`,
            {
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        )
            .then((res) => {
                if (res.status === 403) {
                    endAccess('Location access has ended.');
                    throw new Error('Location access ended');
                }
                if (!res.ok)
                    throw new Error('Location history could not be loaded');

                return res.json();
            })
            .then((data) => {
                setHistoryLocations(data.locations ?? []);
                setShowHistory(true);
            })
            .catch(() => setHistoryLocations([]))
            .finally(() => setLoadingHistory(false));
    }, [client.id, dateFrom, dateTo, endAccess]);

    const hasConsent =
        trackingConsent?.status === 'active' ||
        trackingConsent?.status === 'granted';
    const hasTracker = tracker !== null;
    const isOnline = tracker?.status === 'online';

    if (!privacyActive) {
        return (
            <AppLayout>
                <Head title={`Location - ${clientName}`} />
                <PageLayout
                    hero={
                        <PageHero
                            icon={MapPin}
                            title="Location"
                            description={`Live location and movement history for ${clientName}`}
                        />
                    }
                >
                    <Card>
                        <CardContent className="flex items-start gap-3 p-5">
                            <ShieldOff className="mt-0.5 h-5 w-5 shrink-0 text-status-warning" />
                            <div>
                                <p className="font-medium">
                                    {privacyChecking
                                        ? 'Checking location access'
                                        : 'Location access is not active'}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {privacyChecking
                                        ? 'Current location and movement history stay hidden until access is confirmed.'
                                        : `${privacyMessage ?? 'Tracking consent or the personal-tracker assignment is not active.'} Cached location data has been removed from this view.`}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </PageLayout>
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title={`Location - ${clientName}`} />

            <PageLayout
                hero={
                    <PageHero
                        icon={MapPin}
                        title="Location"
                        description={`Live location and movement history for ${clientName}`}
                        stats={
                            hasTracker && tracker
                                ? [
                                      {
                                          label: 'Status',
                                          value: tracker.status,
                                      },
                                      {
                                          label: 'Battery',
                                          value:
                                              tracker.battery != null
                                                  ? `${tracker.battery}%`
                                                  : '--',
                                      },
                                  ]
                                : undefined
                        }
                    />
                }
            >
                {/* Consent Warning */}
                {!hasConsent && (
                    <Card className="border-status-warning/30 bg-status-warning-bg dark:border-status-warning/30">
                        <CardContent className="flex items-center gap-3 p-4">
                            <ShieldOff className="h-5 w-5 shrink-0 text-status-warning dark:text-status-warning" />
                            <div>
                                <p className="font-medium text-status-warning dark:text-status-warning">
                                    Location Tracking Consent Not Active
                                </p>
                                <p className="text-sm text-status-warning dark:text-status-warning">
                                    Location tracking requires active consent.
                                    Please contact the care team to update
                                    consent status.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* No Tracker */}
                {hasConsent && !hasTracker && (
                    <Card className="border-status-info/30 bg-status-info-bg dark:border-status-info/30">
                        <CardContent className="flex items-center gap-3 p-4">
                            <Radio className="h-5 w-5 shrink-0 text-status-info dark:text-status-info" />
                            <div>
                                <p className="font-medium text-status-info dark:text-status-info">
                                    No Tracker Assigned
                                </p>
                                <p className="text-sm text-status-info dark:text-status-info">
                                    A personal tracker has not been assigned
                                    yet. Please contact the care team to set up
                                    a tracker device.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Status Cards */}
                {hasTracker && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {/* Tracker Status */}
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
                                    <p className="text-xs text-muted-foreground">
                                        Tracker
                                    </p>
                                    <p className="font-semibold">
                                        {tracker.name}
                                    </p>
                                    <Badge
                                        variant={
                                            isOnline ? 'default' : 'secondary'
                                        }
                                        className="mt-0.5 text-[10px] capitalize"
                                    >
                                        {tracker.status}
                                    </Badge>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Battery */}
                        <Card>
                            <CardContent className="flex items-center gap-3 p-4">
                                <div
                                    className={`flex h-10 w-10 items-center justify-center rounded-full ${
                                        (tracker.battery ?? 100) < 20
                                            ? 'bg-status-critical-bg'
                                            : 'bg-status-success-bg'
                                    }`}
                                >
                                    {(tracker.battery ?? 100) < 20 ? (
                                        <BatteryLow className="h-5 w-5 text-status-critical dark:text-status-critical" />
                                    ) : (
                                        <Battery className="h-5 w-5 text-status-success dark:text-status-success" />
                                    )}
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        Battery
                                    </p>
                                    <p className="text-lg font-semibold">
                                        {tracker.battery != null
                                            ? `${tracker.battery}%`
                                            : '---'}
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
                                    <p className="text-xs text-muted-foreground">
                                        Last Seen
                                    </p>
                                    <p className="text-sm font-semibold">
                                        {formatRelativeTime(
                                            tracker.last_seen_at,
                                        )}
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
                                    <p className="text-xs text-muted-foreground">
                                        Consent
                                    </p>
                                    <Badge
                                        variant="default"
                                        className="bg-status-success text-[10px] capitalize"
                                    >
                                        {trackingConsent?.status ?? 'Active'}
                                    </Badge>
                                    {trackingConsent?.expires_at && (
                                        <p className="mt-0.5 text-[10px] text-muted-foreground">
                                            Expires{' '}
                                            {formatDateTime(
                                                trackingConsent.expires_at,
                                            )}
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
                                    {showHistory
                                        ? 'Movement History'
                                        : 'Current Location'}
                                </CardTitle>
                                {currentLocation && (
                                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                        <span className="flex items-center gap-1">
                                            <MapPin className="h-3 w-3" />
                                            {currentLocation.lat.toFixed(
                                                5,
                                            )}, {currentLocation.lng.toFixed(5)}
                                        </span>
                                        {currentLocation.speed != null && (
                                            <span className="flex items-center gap-1">
                                                <Navigation className="h-3 w-3" />
                                                {currentLocation.speed} km/h
                                            </span>
                                        )}
                                    </div>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            {currentLocation ? (
                                <LeafletMap
                                    center={mapCenter}
                                    zoom={
                                        showHistory &&
                                        historyLocations.length > 0
                                            ? 14
                                            : 16
                                    }
                                    markers={markers}
                                    polyline={polyline}
                                    polylineOptions={
                                        showHistory
                                            ? {
                                                  animated: true,
                                                  showArrows: true,
                                                  showEndpoints: true,
                                                  color: '#7c3aed',
                                              }
                                            : undefined
                                    }
                                    geofences={geofences as MapGeofence[]}
                                    height={420}
                                />
                            ) : (
                                <div className="flex h-[420px] items-center justify-center text-muted-foreground">
                                    <div className="text-center">
                                        <MapPin className="mx-auto h-10 w-10 opacity-30" />
                                        <p className="mt-2 text-sm">
                                            No location data available
                                        </p>
                                        <p className="text-xs">
                                            The tracker may be offline or not
                                            yet reporting
                                        </p>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Movement History Section */}
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
                                        onChange={(e) =>
                                            setDateFrom(e.target.value)
                                        }
                                        className="w-40"
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">To</Label>
                                    <Input
                                        type="date"
                                        value={dateTo}
                                        onChange={(e) =>
                                            setDateTo(e.target.value)
                                        }
                                        className="w-40"
                                    />
                                </div>
                                <Button
                                    onClick={fetchHistory}
                                    size="sm"
                                    disabled={loadingHistory}
                                >
                                    <Calendar className="mr-2 h-4 w-4" />
                                    {loadingHistory
                                        ? 'Loading...'
                                        : 'Show History'}
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
                                        <Badge
                                            variant="secondary"
                                            className="ml-auto text-xs"
                                        >
                                            {historyLocations.length} points
                                        </Badge>
                                    </>
                                )}
                            </div>

                            {/* History Timeline */}
                            {showHistory && historyLocations.length > 0 && (
                                <>
                                    <Separator className="my-4" />
                                    <div className="max-h-[300px] divide-y overflow-y-auto rounded-md border">
                                        {historyLocations.map((loc, i) => (
                                            <div
                                                key={i}
                                                className="flex items-start gap-3 px-4 py-3"
                                            >
                                                <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 dark:bg-primary/30">
                                                    <MapPin className="h-3.5 w-3.5 text-primary dark:text-primary" />
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-xs text-muted-foreground">
                                                        {formatDateTime(
                                                            loc.timestamp,
                                                        )}
                                                    </p>
                                                    <p className="text-sm">
                                                        {loc.lat.toFixed(5)},{' '}
                                                        {loc.lng.toFixed(5)}
                                                    </p>
                                                    <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                                        {loc.speed != null && (
                                                            <span className="flex items-center gap-1">
                                                                <Navigation className="h-3 w-3" />
                                                                {loc.speed} km/h
                                                            </span>
                                                        )}
                                                        {loc.battery !=
                                                            null && (
                                                            <span>
                                                                {loc.battery}%
                                                                battery
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </>
                            )}

                            {showHistory &&
                                historyLocations.length === 0 &&
                                !loadingHistory && (
                                    <div className="mt-4 py-8 text-center text-sm text-muted-foreground">
                                        No movement data found for the selected
                                        period.
                                    </div>
                                )}
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
