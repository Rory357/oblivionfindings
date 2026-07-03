import { ConfirmDialog } from '@/components/confirm-dialog';
import LeafletMap from '@/components/leaflet-map';
import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatDuration } from '@/lib/fleet-utils';
import { Head, router } from '@inertiajs/react';
import { CheckCircle, Clock, MapPin, Route, Trash2, User } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Trip {
    id: number;
    asset_id: number;
    asset: { id: number; name: string; asset_tag: string } | null;
    driver_session_id: number | null;
    driver: { id: number; name: string } | null;
    started_at: string | null;
    ended_at: string | null;
    start_latitude: number | null;
    start_longitude: number | null;
    end_latitude: number | null;
    end_longitude: number | null;
    distance_km: number | null;
    duration_s: number | null;
    status: string;
    consent_blocked: boolean;
}

interface DriverSession {
    id: number;
    user: { id: number; name: string } | null;
    started_at: string | null;
    ended_at: string | null;
}

interface Props {
    trip: Trip;
    driver_sessions: DriverSession[];
    can: { manage: boolean };
}

export default function FleetTripPlayback({ trip, driver_sessions, can }: Props) {
    const [points, setPoints] = useState<{ lat: number; lng: number }[]>([]);
    const [selectedDriver, setSelectedDriver] = useState(
        trip.driver_session_id?.toString() || '',
    );
    const [processing, setProcessing] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);

    useEffect(() => {
        fetch(`/fleet-assets/trips/${trip.id}/playback/data`)
            .then((res) => res.json())
            .then((data) => {
                const rows = (data.points ?? [])
                    .filter((p: any) => p.lat && p.lng)
                    .map((p: any) => ({ lat: Number(p.lat), lng: Number(p.lng) }));
                setPoints(rows);
            })
            .catch(() => setPoints([]));
    }, [trip.id]);

    const center =
        points.length > 0
            ? { lat: points[0].lat, lng: points[0].lng }
            : trip.start_latitude && trip.start_longitude
              ? { lat: Number(trip.start_latitude), lng: Number(trip.start_longitude) }
              : { lat: -36.8485, lng: 174.7633 };

    const handleClose = () => {
        setProcessing(true);
        router.post(`/fleet/trips/${trip.id}/close`, {}, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    const handleDelete = () => {
        router.delete(`/fleet/trips/${trip.id}`);
    };

    const handleAssignDriver = () => {
        if (!selectedDriver) return;
        setProcessing(true);
        router.put(
            `/fleet/trips/${trip.id}`,
            { driver_session_id: parseInt(selectedDriver) },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const statusColors: Record<string, string> = {
        open: 'bg-status-info-bg text-status-info',
        closed: 'bg-status-success-bg text-status-success',
        cancelled: 'bg-muted text-foreground',
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Trips', href: '/fleet-assets/trips' },
                { title: `Trip #${trip.id}`, href: '#' },
            ]}
        >
            <Head title={`Trip #${trip.id}`} />
            <PageShell>
                <PageHero
                    icon={Route}
                    backHref="/fleet-assets/trips"
                    backLabel="Back to trips"
                    title={
                        <div className="flex items-center gap-3">
                            <span>Trip #{trip.id}</span>
                            <Badge className={statusColors[trip.status] || ''}>
                                {trip.status}
                            </Badge>
                            {trip.consent_blocked && (
                                <Badge variant="outline" className="border-status-warning/30 text-status-warning">
                                    Consent Blocked
                                </Badge>
                            )}
                        </div>
                    }
                    description={trip.asset?.name || 'Unknown Vehicle'}
                    stats={[
                        {
                            label: 'Distance',
                            value: trip.distance_km
                                ? `${trip.distance_km} km`
                                : '-',
                        },
                        {
                            label: 'Duration',
                            value: formatDuration(trip.duration_s),
                        },
                        { label: 'Route points', value: points.length },
                    ]}
                    actions={
                        <div className="flex gap-2">
                            {can.manage && trip.status === 'open' && (
                                <Button
                                    variant="default"
                                    size="sm"
                                    onClick={() => setConfirmClose(true)}
                                    disabled={processing}
                                >
                                    <CheckCircle className="mr-2 h-4 w-4" />
                                    Close Trip
                                </Button>
                            )}
                            {can.manage && (
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    onClick={() => setConfirmDelete(true)}
                                    disabled={processing}
                                >
                                    <Trash2 className="mr-2 h-4 w-4" />
                                    Delete
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-3">
                    {/* Map */}
                    <div className="lg:col-span-2">
                        <LeafletMap
                            center={center}
                            zoom={12}
                            polyline={points}
                            height={480}
                        />
                    </div>

                    {/* Trip Details */}
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>Trip Details</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <Label className="text-xs text-muted-foreground">
                                            Distance
                                        </Label>
                                        <p className="flex items-center gap-2 font-medium">
                                            <MapPin className="h-4 w-4" />
                                            {trip.distance_km ? `${trip.distance_km} km` : '-'}
                                        </p>
                                    </div>
                                    <div>
                                        <Label className="text-xs text-muted-foreground">
                                            Duration
                                        </Label>
                                        <p className="flex items-center gap-2 font-medium">
                                            <Clock className="h-4 w-4" />
                                            {formatDuration(trip.duration_s)}
                                        </p>
                                    </div>
                                </div>

                                <div>
                                    <Label className="text-xs text-muted-foreground">
                                        Started
                                    </Label>
                                    <p className="font-medium">
                                        {formatDateTime(trip.started_at)}
                                    </p>
                                </div>

                                <div>
                                    <Label className="text-xs text-muted-foreground">
                                        Ended
                                    </Label>
                                    <p className="font-medium">
                                        {formatDateTime(trip.ended_at)}
                                    </p>
                                </div>

                                {trip.driver && (
                                    <div>
                                        <Label className="text-xs text-muted-foreground">
                                            Driver
                                        </Label>
                                        <p className="flex items-center gap-2 font-medium">
                                            <User className="h-4 w-4" />
                                            {trip.driver.name}
                                        </p>
                                    </div>
                                )}

                                {trip.start_latitude && trip.start_longitude && (
                                    <div>
                                        <Label className="text-xs text-muted-foreground">
                                            Start Location
                                        </Label>
                                        <p className="text-sm text-muted-foreground">
                                            {Number(trip.start_latitude).toFixed(5)},{' '}
                                            {Number(trip.start_longitude).toFixed(5)}
                                        </p>
                                    </div>
                                )}

                                {trip.end_latitude && trip.end_longitude && (
                                    <div>
                                        <Label className="text-xs text-muted-foreground">
                                            End Location
                                        </Label>
                                        <p className="text-sm text-muted-foreground">
                                            {Number(trip.end_latitude).toFixed(5)},{' '}
                                            {Number(trip.end_longitude).toFixed(5)}
                                        </p>
                                    </div>
                                )}

                                <div>
                                    <Label className="text-xs text-muted-foreground">
                                        Route Points
                                    </Label>
                                    <p className="font-medium">{points.length} points</p>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Assign Driver */}
                        {can.manage && driver_sessions.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Assign Driver</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <Select
                                        value={selectedDriver}
                                        onValueChange={setSelectedDriver}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select driver session" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {driver_sessions.map((ds) => (
                                                <SelectItem
                                                    key={ds.id}
                                                    value={ds.id.toString()}
                                                >
                                                    {ds.user?.name || 'Unknown'} -{' '}
                                                    {formatDateTime(ds.started_at)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Button
                                        onClick={handleAssignDriver}
                                        disabled={!selectedDriver || processing}
                                        className="w-full"
                                    >
                                        <User className="mr-2 h-4 w-4" />
                                        Assign Driver
                                    </Button>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </PageShell>

            <ConfirmDialog
                open={confirmClose}
                onClose={() => setConfirmClose(false)}
                onConfirm={handleClose}
                title="Close Trip"
                description="Are you sure you want to close this trip?"
                confirmText="Close Trip"
                variant="default"
            />
            <ConfirmDialog
                open={confirmDelete}
                onClose={() => setConfirmDelete(false)}
                onConfirm={handleDelete}
                title="Delete Trip"
                description="Are you sure you want to delete this trip? This action cannot be undone."
                confirmText="Delete"
            />
        </AppLayout>
    );
}
