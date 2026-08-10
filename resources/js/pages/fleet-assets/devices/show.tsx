import LeafletMap, { MapMarker } from '@/components/leaflet-map';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    MapPin,
    Radio,
    Wifi,
    WifiOff,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatDateTime } from '@/lib/fleet-utils';


type TelemetrySnapshot = {
    id: number;
    created_at: string | null;
    data: Record<string, any> | null;
};

type Props = {
    tracker: {
        id: number;
        vendor: string;
        device_uid: string;
        imei: string | null;
        serial_number: string | null;
        device_status: string;
        link_status: 'paired' | 'unpaired';
        paired_at: string | null;
        unpaired_at: string | null;
        last_seen_at: string | null;
        vendor_metadata: Record<string, any> | null;
        asset: {
            id: number;
            name: string;
            asset_tag: string | null;
            category: string | null;
            status: string | null;
        } | null;
        telemetry_snapshots: TelemetrySnapshot[];
    };
};

const statusBannerColors: Record<string, string> = {
    paired: 'bg-purple-50 border-purple-200 text-purple-900 dark:bg-purple-950/30 dark:border-purple-800 dark:text-purple-200',
    unpaired: 'bg-slate-50 border-slate-200 text-slate-900 dark:bg-slate-950/30 dark:border-slate-800 dark:text-slate-200',
    offline: 'bg-red-50 border-red-200 text-red-900 dark:bg-red-950/30 dark:border-red-800 dark:text-red-200',
};

export default function DeviceShow({ tracker }: Props) {
    const device = tracker ?? {} as Props['tracker'];
    const snapshots = device.telemetry_snapshots ?? [];
    const [showUnpairDialog, setShowUnpairDialog] = useState(false);

    // Try to extract lat/lng from the latest snapshot data
    const latestData = snapshots.length > 0 ? (snapshots[0]?.data ?? null) : null;
    const lat = latestData?.lat ?? latestData?.latitude ?? null;
    const lng = latestData?.lng ?? latestData?.longitude ?? null;

    const markers = useMemo<MapMarker[]>(() => {
        if (lat && lng) {
            return [{
                id: device.id,
                lat: Number(lat),
                lng: Number(lng),
                title: `${device.vendor ?? ''} - ${device.device_uid ?? ''}`,
                type: 'vehicle' as const,
                status: device.device_status,
            }];
        }
        return [];
    }, [device, lat, lng]);

    const center = useMemo(() => {
        if (lat && lng) return { lat: Number(lat), lng: Number(lng) };
        return { lat: -36.8485, lng: 174.7633 };
    }, [lat, lng]);

    const isOperational = device.device_status === 'active' || device.device_status === 'degraded';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Devices', href: '/fleet-assets/devices' },
                { title: device.device_uid ?? 'Device', href: '#' },
            ]}
        >
            <Head title={`Device: ${device.device_uid ?? ''}`} />
            <PageShell>
                <FleetHero
                    title={`${device.vendor} - ${device.device_uid}`}
                    backHref="/fleet-assets/devices"
                    backLabel="Back to Devices"
                />

                {/* Status Banner */}
                <div className={cn('rounded-lg border px-5 py-4', statusBannerColors[device.link_status] ?? statusBannerColors.unpaired)}>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            {isOperational ? <Wifi className="h-5 w-5" /> : <WifiOff className="h-5 w-5" />}
                            <div>
                                <span className="font-medium">{device.vendor} - {device.device_uid}</span>
                            </div>
                            <Badge variant={device.link_status === 'paired' ? 'default' : 'secondary'}>{device.link_status}</Badge>
                            <Badge variant={isOperational ? 'secondary' : 'outline'}>{device.device_status ?? 'unknown'}</Badge>
                        </div>
                        {device.asset && device.link_status === 'paired' && (
                            <Button
                                variant="destructive"
                                size="sm"
                                onClick={() => setShowUnpairDialog(true)}
                            >
                                Unpair Device
                            </Button>
                        )}
                    </div>
                    {device.last_seen_at && (
                        <div className="mt-2 text-xs opacity-70">
                            Last seen: {formatDateTime(device.last_seen_at)}
                        </div>
                    )}
                </div>

                {/* 2-Column: Device info (left), Telemetry/Map (right) */}
                <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    {/* Left: Device Details */}
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Device Details</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="space-y-2 text-sm">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="rounded-md bg-muted/40 p-3">
                                            <dt className="text-xs text-muted-foreground">Vendor</dt>
                                            <dd className="mt-1 font-medium">{device.vendor ?? '---'}</dd>
                                        </div>
                                        <div className="rounded-md bg-muted/40 p-3">
                                            <dt className="text-xs text-muted-foreground">Device UID</dt>
                                            <dd className="mt-1 font-mono font-medium">{device.device_uid ?? '---'}</dd>
                                        </div>
                                    </div>
                                    {device.imei && (
                                        <div className="rounded-md bg-muted/40 p-3">
                                            <dt className="text-xs text-muted-foreground">IMEI</dt>
                                            <dd className="mt-1 font-mono font-medium">{device.imei}</dd>
                                        </div>
                                    )}
                                    {device.serial_number && (
                                        <div className="rounded-md bg-muted/40 p-3">
                                            <dt className="text-xs text-muted-foreground">Serial Number</dt>
                                            <dd className="mt-1 font-mono font-medium">{device.serial_number}</dd>
                                        </div>
                                    )}
                                    <div className="rounded-md bg-muted/40 p-3">
                                        <dt className="text-xs text-muted-foreground">Paired Asset</dt>
                                        <dd className="mt-1">
                                            {device.asset ? (
                                                <Link href={`/fleet-assets/assets/${device.asset.id}`} className="text-primary hover:underline font-medium">
                                                    {device.asset.name}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">Not paired</span>
                                            )}
                                        </dd>
                                    </div>
                                    {device.paired_at && (
                                        <div className="rounded-md bg-muted/40 p-3">
                                            <dt className="text-xs text-muted-foreground">Paired At</dt>
                                            <dd className="mt-1 font-medium">{formatDateTime(device.paired_at)}</dd>
                                        </div>
                                    )}
                                </dl>
                            </CardContent>
                        </Card>

                        {/* Telemetry History */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Recent Telemetry
                                    {snapshots.length > 0 && <Badge variant="secondary" className="ml-2 text-xs">{snapshots.length}</Badge>}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {snapshots.length > 0 ? (
                                    <div className="space-y-2">
                                        {snapshots.map((s) => (
                                            <div key={s.id} className="flex items-center justify-between rounded-md border p-3 text-xs hover:bg-muted/30 transition-colors">
                                                <div className="flex items-center gap-2">
                                                    <Radio className="h-3.5 w-3.5 text-primary" />
                                                    <span className="text-muted-foreground">
                                                        {s.created_at ? formatDateTime(s.created_at) : '---'}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-3 text-muted-foreground">
                                                    {s.data ? (
                                                        <span className="font-mono truncate max-w-xs">
                                                            {JSON.stringify(s.data).substring(0, 80)}
                                                            {JSON.stringify(s.data).length > 80 ? '...' : ''}
                                                        </span>
                                                    ) : (
                                                        <span>No data</span>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">No telemetry history.</p>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right: Latest Telemetry + Map */}
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Latest Data</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {latestData ? (
                                    <dl className="space-y-2 text-sm">
                                        {Object.entries(latestData).map(([key, value]) => (
                                            <div key={key} className="flex justify-between rounded-md bg-muted/40 px-3 py-2">
                                                <dt className="text-muted-foreground capitalize">{key.replace(/_/g, ' ')}</dt>
                                                <dd className="font-medium font-mono">{value != null ? String(value) : '---'}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                ) : (
                                    <p className="text-sm text-muted-foreground">No telemetry data available.</p>
                                )}
                            </CardContent>
                        </Card>

                        {/* Map */}
                        {markers.length > 0 && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <MapPin className="h-4 w-4" />
                                        Location
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <LeafletMap
                                        center={center}
                                        zoom={15}
                                        markers={markers}
                                        height={300}
                                    />
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>

                <ConfirmDialog
                    open={showUnpairDialog}
                    onClose={() => setShowUnpairDialog(false)}
                    onConfirm={() => router.post(`/fleet-assets/devices/${device.id}/unpair`)}
                    title="Unpair Device"
                    description={`Are you sure you want to unpair this device from ${device.asset?.name ?? 'the asset'}? The device will stop tracking.`}
                    confirmText="Unpair"
                />
            </PageShell>
        </AppLayout>
    );
}
