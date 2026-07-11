import type { MapMarker } from '@/components/leaflet-map';
import ResidentMap from '@/components/resident-tracking/resident-map';
import ResidentSidebar from '@/components/resident-tracking/resident-sidebar';
import type {
    CommandStatus,
    Geofence,
    GeofenceStatus,
    Resident,
} from '@/components/resident-tracking/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { formatDateTime, formatRelativeTime } from '@/lib/fleet-utils';
import { Link, router } from '@inertiajs/react';
import {
    Calendar,
    Clock,
    Download,
    ExternalLink,
    MapPin,
    Navigation,
    Radio,
    RotateCcw,
    ShieldOff,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

type HistoryPoint = {
    lat: number;
    lng: number;
    address?: string | null;
    coordinates?: string | null;
    display_location?: string | null;
    timestamp: string;
    speed: number | null;
    battery: number | null;
};

export type ClientLocationData = {
    trackingRestricted?: boolean;
    canManage: boolean;
    tracker: {
        id: number;
        device_uid?: string | null;
        name: string;
        serial: string | null;
        mac: string | null;
        imei?: string | null;
        model?: string | null;
        manufacturer?: string | null;
        firmware_version?: string | null;
        hardware_version?: string | null;
        ble_firmware?: string | null;
        ble_mac?: string | null;
        sim_iccid?: string | null;
        imsi?: string | null;
        network_type?: string | null;
        rsrp?: number | string | null;
        band?: string | null;
        mcc?: string | null;
        mnc?: string | null;
        cell_id?: string | null;
        lac?: string | null;
        satellites?: number | null;
        last_frame_at?: string | null;
        last_location_at?: string | null;
        config_snapshot?: Record<string, unknown> | null;
        provider: string | null;
        status: string;
        health_status?: string;
        last_seen_at: string | null;
        battery: number | null;
        battery_status?: 'low' | 'normal' | 'unknown' | string | null;
        battery_voltage_mv?: number | null;
        battery_low_threshold?: number | null;
        battery_updated_at?: string | null;
        charging_status?: string | null;
        external_power?: boolean | null;
        last_power_event?: string | null;
        last_safety_event?: string | null;
        last_safety_event_at?: string | null;
        panic_active?: boolean;
        locate_now_url?: string;
        acknowledge_panic_url?: string;
        fleet_dashboard_url?: string;
        history_url?: string;
        last_command_status?: CommandStatus;
        detail_url?: string;
    } | null;
    currentLocation: {
        lat: number;
        lng: number;
        address?: string | null;
        coordinates?: string | null;
        display_location?: string | null;
        speed: number | null;
        heading: number | null;
        accuracy: number | null;
        altitude?: number | null;
    } | null;
    trackingConsent: {
        status: string;
        given_at: string | null;
        expires_at: string | null;
    } | null;
    geofences: Geofence[];
    geofenceStatus?: GeofenceStatus;
};

type Props = {
    clientId: number;
    clientName: string;
    clientHouse?: string;
    clientPhoto?: string | null;
    location: ClientLocationData;
};

const REFRESH_INTERVAL = 30_000;

function csvCell(value: unknown): string {
    return `"${String(value ?? '').replace(/"/g, '""')}"`;
}

function displayLocation(loc: {
    lat: number;
    lng: number;
    display_location?: string | null;
    coordinates?: string | null;
}): string {
    return (
        loc.display_location ??
        loc.coordinates ??
        `${loc.lat.toFixed(6)}, ${loc.lng.toFixed(6)}`
    );
}

export default function ClientLocationTab({
    clientId,
    clientName,
    clientHouse,
    clientPhoto,
    location,
}: Props) {
    const {
        tracker,
        currentLocation,
        trackingConsent,
        geofences,
        geofenceStatus,
        trackingRestricted = false,
    } = location;

    const [showHistory, setShowHistory] = useState(false);
    const [historyLocations, setHistoryLocations] = useState<HistoryPoint[]>(
        [],
    );
    const [loadingHistory, setLoadingHistory] = useState(false);
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [lastUpdatedAt, setLastUpdatedAt] = useState<string>(
        new Date().toISOString(),
    );

    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({
                only: ['location'],
                onSuccess: () => setLastUpdatedAt(new Date().toISOString()),
            });
        }, REFRESH_INTERVAL);
        return () => clearInterval(interval);
    }, []);

    const hasConsent =
        trackingConsent?.status === 'active' ||
        trackingConsent?.status === 'given' ||
        trackingConsent?.status === 'granted';
    const hasTracker = tracker !== null;
    const hasLocation = currentLocation !== null;

    const resident: Resident | null = useMemo(() => {
        if (!tracker) return null;
        return {
            id: tracker.id,
            device_uid: tracker.device_uid,
            client_id: clientId,
            name: clientName,
            preferred_name: null,
            house: clientHouse ?? '',
            site_id: null,
            photo: clientPhoto ?? null,
            tracker_name: tracker.name,
            tracker_serial: tracker.serial,
            status: tracker.status,
            health_status: tracker.health_status,
            last_seen_at: tracker.last_seen_at,
            lat: currentLocation?.lat ?? null,
            lng: currentLocation?.lng ?? null,
            address: currentLocation?.address ?? null,
            coordinates: currentLocation?.coordinates ?? null,
            display_location: currentLocation?.display_location ?? null,
            battery: tracker.battery,
            battery_status: tracker.battery_status,
            battery_voltage_mv: tracker.battery_voltage_mv,
            battery_low_threshold: tracker.battery_low_threshold,
            battery_updated_at: tracker.battery_updated_at,
            charging_status: tracker.charging_status,
            external_power: tracker.external_power,
            last_power_event: tracker.last_power_event,
            last_safety_event: tracker.last_safety_event,
            last_safety_event_at: tracker.last_safety_event_at,
            panic_active: tracker.panic_active,
            speed: currentLocation?.speed ?? null,
            heading: currentLocation?.heading ?? null,
            accuracy: currentLocation?.accuracy ?? null,
            altitude: currentLocation?.altitude ?? null,
            motion: null,
            imei: tracker.imei,
            mac: tracker.mac,
            model: tracker.model,
            manufacturer: tracker.manufacturer,
            firmware_version: tracker.firmware_version,
            provider: tracker.provider,
            hardware_version: tracker.hardware_version,
            ble_firmware: tracker.ble_firmware,
            ble_mac: tracker.ble_mac,
            sim_iccid: tracker.sim_iccid,
            imsi: tracker.imsi,
            network_type: tracker.network_type,
            rsrp: tracker.rsrp,
            band: tracker.band,
            mcc: tracker.mcc,
            mnc: tracker.mnc,
            cell_id: tracker.cell_id,
            lac: tracker.lac,
            satellites: tracker.satellites,
            last_frame_at: tracker.last_frame_at,
            last_location_at: tracker.last_location_at,
            config_snapshot: tracker.config_snapshot,
            geofence_status: geofenceStatus ?? 'unknown',
            on_outing: false,
            house_geofence: geofences[0] ?? null,
            locate_now_url: tracker.locate_now_url,
            acknowledge_panic_url: tracker.acknowledge_panic_url,
            profile_url: undefined,
            history_url: tracker.history_url,
            detail_url: tracker.detail_url,
            last_command_status: tracker.last_command_status,
        };
    }, [
        tracker,
        currentLocation,
        geofences,
        geofenceStatus,
        clientId,
        clientName,
        clientHouse,
        clientPhoto,
    ]);

    const mapCenter = useMemo(() => {
        if (currentLocation)
            return { lat: currentLocation.lat, lng: currentLocation.lng };
        if (geofences[0]?.center) return geofences[0].center;
        return { lat: -41.2865, lng: 174.7762 };
    }, [currentLocation, geofences]);

    const markers: MapMarker[] = useMemo(() => {
        if (!currentLocation) return [];
        return [
            {
                id: `client-${clientId}`,
                lat: currentLocation.lat,
                lng: currentLocation.lng,
                title: clientName,
                type: 'default',
                status: tracker?.status === 'online' ? 'online' : 'offline',
                heading: currentLocation.heading ?? undefined,
                speed: currentLocation.speed ?? undefined,
                popup: `<strong>${clientName}</strong><br/>
                    ${displayLocation(currentLocation)}<br/>
                    ${currentLocation.speed != null ? `Speed: ${currentLocation.speed} km/h<br/>` : ''}
                    Last seen: ${formatRelativeTime(tracker?.last_seen_at)}`,
            },
        ];
    }, [currentLocation, clientId, clientName, tracker]);

    const polyline = useMemo(() => {
        if (!showHistory || historyLocations.length < 2) return undefined;
        return [...historyLocations]
            .reverse()
            .map((l) => ({ lat: l.lat, lng: l.lng }));
    }, [showHistory, historyLocations]);

    const fetchHistory = useCallback(() => {
        setLoadingHistory(true);
        const params = new URLSearchParams();
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);

        fetch(
            `/operations/clients/${clientId}/location/history?${params.toString()}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        )
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
        const csvHeader =
            'Address,Latitude,Longitude,Timestamp,Speed,Battery\n';
        const csvBody = historyLocations
            .map((l) =>
                [
                    csvCell(l.address ?? ''),
                    l.lat,
                    l.lng,
                    csvCell(l.timestamp ?? ''),
                    l.speed ?? '',
                    l.battery ?? '',
                ].join(','),
            )
            .join('\n');
        const blob = new Blob([csvHeader + csvBody], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `location-${clientName.replace(/\s+/g, '-')}-${new Date()
            .toISOString()
            .slice(0, 10)}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    };

    const handleLocateNow = useCallback(() => {
        if (!tracker?.locate_now_url) return;

        router.post(tracker.locate_now_url, {}, { preserveScroll: true });
    }, [tracker?.locate_now_url]);

    const handleAcknowledgePanic = useCallback(() => {
        if (!tracker?.acknowledge_panic_url) return;

        router.post(
            tracker.acknowledge_panic_url,
            {},
            {
                preserveScroll: true,
            },
        );
    }, [tracker?.acknowledge_panic_url]);

    return (
        <div className="mt-4 space-y-4">
            {/* Consent banner */}
            {!hasConsent && (
                <Card className="border-status-warning/30 bg-status-warning-bg">
                    <CardContent className="flex items-center gap-3 p-4">
                        <ShieldOff className="h-5 w-5 shrink-0 text-status-warning" />
                        <div>
                            <p className="font-medium text-status-warning">
                                Location Tracking Consent Not Active
                            </p>
                            <p className="text-sm text-status-warning">
                                Location tracking requires active consent.
                                Update consent in the Consents tab or contact
                                the care team.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* No tracker assigned */}
            {!hasTracker && !trackingRestricted && (
                <Card className="border-status-info/30 bg-status-info-bg">
                    <CardContent className="flex items-center gap-3 p-4">
                        <Radio className="h-5 w-5 shrink-0 text-status-info" />
                        <div className="flex-1">
                            <p className="font-medium text-status-info">
                                No Personal Tracker Assigned
                            </p>
                            <p className="text-sm text-status-info">
                                Assign a tracker device from the Fleet & Assets
                                module to enable location tracking.
                            </p>
                        </div>
                        {location.canManage && (
                            <Link
                                href="/fleet-assets/resident-tracking?new=1"
                                className="inline-flex items-center gap-1 rounded-md border border-status-info/30 bg-card px-3 py-1.5 text-xs font-medium text-status-info hover:bg-status-info-bg"
                            >
                                Assign Tracker
                                <ExternalLink className="h-3 w-3" />
                            </Link>
                        )}
                    </CardContent>
                </Card>
            )}

            {/* Main map + sidebar grid */}
            {hasTracker && resident && (
                <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                    {/* Map */}
                    <Card className="overflow-hidden">
                        <CardHeader className="flex flex-row items-center justify-between gap-3 space-y-0 pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Navigation className="h-4 w-4" />
                                Current location
                            </CardTitle>
                            <Link
                                href={
                                    tracker.fleet_dashboard_url ??
                                    `/fleet-assets/resident-tracking?focus=${clientId}`
                                }
                                className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                            >
                                Open in Fleet Dashboard
                                <ExternalLink className="h-3 w-3" />
                            </Link>
                        </CardHeader>
                        <CardContent className="p-0">
                            {hasLocation ? (
                                <ResidentMap
                                    center={mapCenter}
                                    zoom={
                                        showHistory &&
                                        historyLocations.length > 0
                                            ? 14
                                            : 16
                                    }
                                    markers={markers}
                                    geofences={geofences}
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
                                    height={520}
                                    updatedAt={lastUpdatedAt}
                                />
                            ) : (
                                <div className="flex h-[520px] items-center justify-center text-muted-foreground">
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

                    {/* Sidebar */}
                    <Card className="flex flex-col">
                        <CardContent className="flex h-full flex-col p-4">
                            <ResidentSidebar
                                resident={resident}
                                variant="profile-detail"
                                canManage={location.canManage}
                                onLocateNow={
                                    location.canManage && tracker.locate_now_url
                                        ? handleLocateNow
                                        : undefined
                                }
                                onAcknowledgePanic={
                                    location.canManage &&
                                    tracker.acknowledge_panic_url
                                        ? handleAcknowledgePanic
                                        : undefined
                                }
                            />
                        </CardContent>
                    </Card>
                </div>
            )}

            {/* Movement history */}
            {hasTracker && (
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Clock className="h-4 w-4" />
                            Movement history
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap items-end gap-3">
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
                                    onChange={(e) => setDateTo(e.target.value)}
                                    className="w-40"
                                />
                            </div>
                            <Button
                                onClick={fetchHistory}
                                size="sm"
                                disabled={loadingHistory}
                            >
                                <Calendar className="mr-2 h-4 w-4" />
                                {loadingHistory ? 'Loading...' : 'Show history'}
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
                                    <Badge
                                        variant="secondary"
                                        className="ml-auto text-xs"
                                    >
                                        {historyLocations.length} points
                                    </Badge>
                                </>
                            )}
                        </div>

                        {showHistory && historyLocations.length > 0 && (
                            <>
                                <Separator className="my-4" />
                                <div className="max-h-[300px] divide-y overflow-y-auto rounded-md border">
                                    {historyLocations.map((loc, i) => (
                                        <div
                                            key={i}
                                            className="flex items-start gap-3 px-4 py-3"
                                        >
                                            <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                                <MapPin className="h-3.5 w-3.5 text-primary" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="text-xs text-muted-foreground">
                                                    {formatDateTime(
                                                        loc.timestamp,
                                                    )}
                                                </p>
                                                <p className="text-sm">
                                                    {displayLocation(loc)}
                                                </p>
                                                <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                                    {loc.address &&
                                                        loc.coordinates && (
                                                            <span>
                                                                {
                                                                    loc.coordinates
                                                                }
                                                            </span>
                                                        )}
                                                    {loc.speed != null && (
                                                        <span className="flex items-center gap-1">
                                                            <Navigation className="h-3 w-3" />
                                                            {loc.speed} km/h
                                                        </span>
                                                    )}
                                                    {loc.battery != null && (
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
        </div>
    );
}
