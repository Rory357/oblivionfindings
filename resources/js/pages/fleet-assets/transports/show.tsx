import LeafletMap, { type MapMarker } from '@/components/leaflet-map';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDuration } from '@/lib/fleet-utils';
import { cn } from '@/lib/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    Car,
    CheckCircle,
    ClipboardCheck,
    Clock,
    Loader2,
    MapPin,
    Pill,
    ShieldCheck,
    User,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useRef } from 'react';

type CareNeed = {
    id: number;
    label: string;
    notes: string | null;
};

type Props = {
    transport: {
        id: number;
        asset: { id: number; name: string; asset_tag?: string } | null;
        driver: { id: number; name: string; email?: string } | null;
        booking: { id: number; purpose: string } | null;
        shift?: {
            id: number;
            starts_at?: string | null;
            ends_at?: string | null;
            shift_type?: string | null;
            location?: string | null;
            service_context?: string | null;
            staff_name?: string | null;
        } | null;
        service_context?: string | null;
        resident_name: string;
        transport_type: string;
        pickup_location: string | null;
        dropoff_location: string | null;
        departed_at: string | null;
        arrived_at: string | null;
        passengers_count: number;
        supervisor_name: string | null;
        notes: string | null;
        status: string;
        duration_minutes: number | null;
        created_at: string | null;
    };
    vehicle_position?: {
        lat: number;
        lng: number;
        heading?: number;
        speed?: number;
    } | null;
    pre_check_status?: 'completed' | 'pending' | null;
    care_needs?: CareNeed[];
    completion_blockers?: Array<{
        type: string;
        count: number;
        message: string;
    }>;
};

const TRANSPORT_TYPE_BANNER: Record<string, string> = {
    medical:
        'bg-red-50 border-red-200 text-red-900 dark:bg-red-950/30 dark:border-red-800 dark:text-red-200',
    appointment:
        'bg-blue-50 border-blue-200 text-blue-900 dark:bg-blue-950/30 dark:border-blue-800 dark:text-blue-200',
    social: 'bg-green-50 border-green-200 text-green-900 dark:bg-green-950/30 dark:border-green-800 dark:text-green-200',
    shopping:
        'bg-purple-50 border-purple-200 text-purple-900 dark:bg-purple-950/30 dark:border-purple-800 dark:text-purple-200',
    community:
        'bg-teal-50 border-teal-200 text-teal-900 dark:bg-teal-950/30 dark:border-teal-800 dark:text-teal-200',
    respite:
        'bg-orange-50 border-orange-200 text-orange-900 dark:bg-orange-950/30 dark:border-orange-800 dark:text-orange-200',
    other: 'bg-gray-50 border-gray-200 text-gray-900 dark:bg-gray-950/30 dark:border-gray-800 dark:text-gray-200',
};

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'in_progress':
            return 'default';
        case 'completed':
            return 'secondary';
        case 'cancelled':
            return 'destructive';
        default:
            return 'outline';
    }
}

// Using shared formatDuration from fleet-utils
function formatDurationMinutes(minutes: number | null): string {
    if (minutes == null) return '---';
    return formatDuration(Math.round(minutes) * 60);
}

export default function TransportShow({
    transport,
    vehicle_position,
    pre_check_status,
    care_needs,
    completion_blockers,
}: Props) {
    const t = transport ?? ({} as Props['transport']);
    const refreshTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

    // Auto-refresh vehicle position for active transports
    useEffect(() => {
        if (t.status === 'in_progress') {
            refreshTimerRef.current = setInterval(() => {
                router.reload({ only: ['vehicle_position'] });
            }, 15000);
        }
        return () => {
            if (refreshTimerRef.current) clearInterval(refreshTimerRef.current);
        };
    }, [t.status]);

    // Map markers for vehicle position
    const vehicleMarkers: MapMarker[] = useMemo(() => {
        if (!vehicle_position?.lat || !vehicle_position?.lng) return [];
        return [
            {
                id: 'vehicle',
                lat: vehicle_position.lat,
                lng: vehicle_position.lng,
                title: t.asset?.name ?? 'Vehicle',
                type: 'vehicle' as const,
                status: 'moving' as const,
                heading: vehicle_position.heading,
                speed: vehicle_position.speed,
            },
        ];
    }, [vehicle_position, t.asset]);

    const mapCenter = useMemo(() => {
        if (vehicle_position?.lat && vehicle_position?.lng) {
            return { lat: vehicle_position.lat, lng: vehicle_position.lng };
        }
        return { lat: -41.2865, lng: 174.7762 };
    }, [vehicle_position]);

    const completeForm = useForm({
        arrived_at: new Date().toISOString().slice(0, 16),
        notes: '',
    });

    const handleComplete = (e: React.FormEvent) => {
        e.preventDefault();
        completeForm.post(`/fleet-assets/transports/${t.id}/complete`);
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Transport Logs', href: '/fleet-assets/transports' },
                { title: `Transport #${t.id ?? ''}`, href: '#' },
            ]}
        >
            <Head title={`Transport #${t.id ?? ''}`} />
            <PageShell>
                <FleetHero
                    title={`Transport #${t.id ?? ''}`}
                    backHref="/fleet-assets/transports"
                    backLabel="Back to Transport Logs"
                    actions={
                        t.status === 'in_progress' ? (
                            <Button asChild variant="outline">
                                <Link
                                    href={`/fleet-assets/transports/${t.id}/pre-check`}
                                >
                                    <ClipboardCheck className="mr-2 h-4 w-4" />
                                    Pre-Transport Check
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                {/* Transport Type Colored Banner */}
                <div
                    className={cn(
                        'rounded-lg border px-5 py-4',
                        TRANSPORT_TYPE_BANNER[t.transport_type] ??
                            TRANSPORT_TYPE_BANNER.other,
                    )}
                >
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <Badge className="text-sm capitalize">
                                {t.transport_type}
                            </Badge>
                            <span className="font-medium">
                                Transport #{t.id}
                            </span>
                            <span className="opacity-50">|</span>
                            <span className="font-medium">
                                {t.resident_name ?? '---'}
                            </span>
                        </div>
                        <div className="flex items-center gap-2">
                            {/* Pre-Check Status Badge */}
                            {pre_check_status && (
                                <Badge
                                    variant={
                                        pre_check_status === 'completed'
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                    className={cn(
                                        'text-xs',
                                        pre_check_status === 'completed'
                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                            : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                    )}
                                >
                                    <ShieldCheck className="mr-1 h-3 w-3" />
                                    Pre-Check:{' '}
                                    {pre_check_status === 'completed'
                                        ? 'Done'
                                        : 'Pending'}
                                </Badge>
                            )}
                            <Badge
                                variant={statusVariant(t.status ?? '')}
                                className="text-xs"
                            >
                                {(t.status ?? '').replace(/_/g, ' ')}
                            </Badge>
                        </div>
                    </div>
                </div>

                {/* Live Map for active transports */}
                {t.status === 'in_progress' &&
                    vehicle_position?.lat &&
                    vehicle_position?.lng && (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <MapPin className="h-4 w-4" />
                                    Live Vehicle Position
                                    <span className="ml-auto flex items-center gap-1.5 text-xs font-normal text-muted-foreground">
                                        <span className="h-2 w-2 animate-pulse rounded-full bg-green-500" />
                                        Live
                                    </span>
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <LeafletMap
                                    center={mapCenter}
                                    zoom={15}
                                    markers={vehicleMarkers}
                                    height={280}
                                />
                            </CardContent>
                        </Card>
                    )}

                {/* Care Needs Summary */}
                {(care_needs ?? []).length > 0 && (
                    <Card className="border bg-purple-50 dark:bg-purple-950/30">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <User className="h-4 w-4" />
                                Care Needs Summary
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {(care_needs ?? []).map((need) => (
                                    <div
                                        key={need.id}
                                        className="rounded-md bg-white/60 p-3 dark:bg-black/20"
                                    >
                                        <p className="text-sm font-medium">
                                            {need.label}
                                        </p>
                                        {need.notes && (
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {need.notes}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* 2-Column: Trip Details (left), Timeline (right) */}
                <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    {/* Left: Details */}
                    <div className="space-y-4">
                        {/* Transport Details */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Transport Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="space-y-3 text-sm">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                            <Car className="h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Vehicle
                                                </dt>
                                                <dd className="font-medium">
                                                    {t.asset ? (
                                                        <Link
                                                            href={`/fleet-assets/vehicles/${t.asset.id}`}
                                                            className="text-primary hover:underline"
                                                        >
                                                            {t.asset.name}
                                                            {t.asset.asset_tag
                                                                ? ` (${t.asset.asset_tag})`
                                                                : ''}
                                                        </Link>
                                                    ) : (
                                                        '---'
                                                    )}
                                                </dd>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                            <User className="h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Driver
                                                </dt>
                                                <dd className="font-medium">
                                                    {t.driver?.name ?? '---'}
                                                </dd>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                            <Users className="h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Resident
                                                </dt>
                                                <dd className="font-medium">
                                                    {t.resident_name ?? '---'}
                                                </dd>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                            <Users className="h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Passengers
                                                </dt>
                                                <dd className="font-medium">
                                                    {t.passengers_count ?? 1}
                                                </dd>
                                            </div>
                                        </div>
                                    </div>
                                    {t.supervisor_name && (
                                        <div className="rounded-md bg-muted/40 p-3">
                                            <dt className="text-xs text-muted-foreground">
                                                Supervisor
                                            </dt>
                                            <dd className="mt-1 font-medium">
                                                {t.supervisor_name}
                                            </dd>
                                        </div>
                                    )}
                                    {t.booking && (
                                        <div className="rounded-md bg-muted/40 p-3">
                                            <dt className="text-xs text-muted-foreground">
                                                Linked Booking
                                            </dt>
                                            <dd className="mt-1">
                                                <Link
                                                    href={`/fleet-assets/bookings/${t.booking.id}`}
                                                    className="font-medium text-primary hover:underline"
                                                >
                                                    Booking #{t.booking.id} -{' '}
                                                    {t.booking.purpose}
                                                </Link>
                                            </dd>
                                        </div>
                                    )}
                                    {t.shift && (
                                        <div className="rounded-md bg-blue-50/60 p-3 dark:bg-blue-950/20">
                                            <dt className="text-xs text-blue-700 dark:text-blue-300">
                                                Linked Shift
                                            </dt>
                                            <dd className="mt-1">
                                                <Link
                                                    href={`/operations/shifts/${t.shift.id}`}
                                                    className="font-medium text-primary hover:underline"
                                                >
                                                    Shift #{t.shift.id}
                                                </Link>
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {(
                                                        t.shift.shift_type ??
                                                        'standard'
                                                    ).replace(/_/g, ' ')}
                                                    {t.shift.service_context
                                                        ? ` · ${t.shift.service_context}`
                                                        : ''}
                                                    {t.shift.staff_name
                                                        ? ` · ${t.shift.staff_name}`
                                                        : ''}
                                                    {t.shift.location
                                                        ? ` · ${t.shift.location}`
                                                        : ''}
                                                </div>
                                            </dd>
                                        </div>
                                    )}
                                    <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                        <Calendar className="h-4 w-4 text-muted-foreground" />
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Logged At
                                            </dt>
                                            <dd className="font-medium">
                                                {t.created_at
                                                    ? new Date(
                                                          t.created_at,
                                                      ).toLocaleString('en-NZ')
                                                    : '---'}
                                            </dd>
                                        </div>
                                    </div>
                                    {t.notes && (
                                        <div className="rounded-md bg-muted/40 p-3">
                                            <dt className="text-xs text-muted-foreground">
                                                Notes
                                            </dt>
                                            <dd className="mt-1">{t.notes}</dd>
                                        </div>
                                    )}
                                </dl>
                            </CardContent>
                        </Card>

                        {/* Completion Blockers */}
                        {t.status === 'in_progress' && (completion_blockers ?? []).length > 0 && (
                            <Card className="border-amber-300 bg-amber-50/50 dark:border-amber-800 dark:bg-amber-950/20">
                                <CardContent className="p-4 space-y-2">
                                    <div className="flex items-center gap-2 text-sm font-semibold text-amber-800 dark:text-amber-300">
                                        <AlertTriangle className="h-4 w-4" />
                                        Cannot complete transport yet
                                    </div>
                                    {(completion_blockers ?? []).map((b, i) => (
                                        <div key={i} className="flex items-center gap-2 rounded-md bg-amber-100/50 dark:bg-amber-900/20 px-3 py-2 text-xs text-amber-900 dark:text-amber-200">
                                            {b.type === 'unresolved_medications' && <Pill className="h-3.5 w-3.5 shrink-0" />}
                                            {b.type === 'controlled_drug_witness' && <ShieldCheck className="h-3.5 w-3.5 shrink-0" />}
                                            <span>{b.message}</span>
                                            <Badge variant="outline" className="ml-auto text-[9px] border-amber-400 text-amber-700">{b.count}</Badge>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        )}

                        {/* Mark Complete */}
                        {t.status === 'in_progress' && (
                            <Card className="border-2 border-dashed border-primary/30">
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Mark as Completed
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <form
                                        onSubmit={handleComplete}
                                        className="grid gap-3 sm:grid-cols-2"
                                    >
                                        <div>
                                            <label className="text-sm font-medium">
                                                Arrival Time
                                            </label>
                                            <Input
                                                type="datetime-local"
                                                value={
                                                    completeForm.data.arrived_at
                                                }
                                                onChange={(e) =>
                                                    completeForm.setData(
                                                        'arrived_at',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <label className="text-sm font-medium">
                                                Additional Notes
                                            </label>
                                            <Input
                                                value={completeForm.data.notes}
                                                onChange={(e) =>
                                                    completeForm.setData(
                                                        'notes',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Any notes about the trip..."
                                            />
                                        </div>
                                        <div className="sm:col-span-2">
                                            <Button
                                                type="submit"
                                                size="lg"
                                                disabled={
                                                    completeForm.processing
                                                }
                                                className="w-full"
                                            >
                                                {completeForm.processing ? (
                                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                ) : (
                                                    <CheckCircle className="mr-2 h-4 w-4" />
                                                )}
                                                Mark Complete
                                            </Button>
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Right: Timeline */}
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Trip Timeline
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-6">
                                    {/* Departure */}
                                    <div className="flex gap-4">
                                        <div className="flex flex-col items-center">
                                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-sm">
                                                <MapPin className="h-5 w-5" />
                                            </div>
                                            <div
                                                className={cn(
                                                    'mt-2 w-0.5 flex-1',
                                                    t.arrived_at
                                                        ? 'bg-primary'
                                                        : 'bg-muted',
                                                )}
                                            />
                                        </div>
                                        <div className="pb-6">
                                            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                Departed
                                            </p>
                                            <p className="mt-1 text-sm font-medium">
                                                {t.departed_at
                                                    ? new Date(
                                                          t.departed_at,
                                                      ).toLocaleString('en-NZ')
                                                    : '---'}
                                            </p>
                                            {t.pickup_location && (
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {t.pickup_location}
                                                </p>
                                            )}
                                        </div>
                                    </div>

                                    {/* Duration */}
                                    {t.duration_minutes != null && (
                                        <div className="flex gap-4">
                                            <div className="flex flex-col items-center">
                                                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                                    <Clock className="h-5 w-5" />
                                                </div>
                                                <div
                                                    className={cn(
                                                        'mt-2 w-0.5 flex-1',
                                                        t.arrived_at
                                                            ? 'bg-primary'
                                                            : 'bg-muted',
                                                    )}
                                                />
                                            </div>
                                            <div className="pb-6">
                                                <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Duration
                                                </p>
                                                <p className="mt-1 text-lg font-bold">
                                                    {formatDurationMinutes(
                                                        t.duration_minutes,
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                    )}

                                    {/* Arrival */}
                                    <div className="flex gap-4">
                                        <div className="flex flex-col items-center">
                                            <div
                                                className={cn(
                                                    'flex h-10 w-10 items-center justify-center rounded-full shadow-sm',
                                                    t.arrived_at
                                                        ? 'bg-primary text-primary-foreground'
                                                        : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {t.arrived_at ? (
                                                    <CheckCircle className="h-5 w-5" />
                                                ) : (
                                                    <Clock className="h-5 w-5" />
                                                )}
                                            </div>
                                        </div>
                                        <div>
                                            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                Arrived
                                            </p>
                                            <p className="mt-1 text-sm font-medium">
                                                {t.arrived_at
                                                    ? new Date(
                                                          t.arrived_at,
                                                      ).toLocaleString('en-NZ')
                                                    : 'In progress...'}
                                            </p>
                                            {t.dropoff_location && (
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {t.dropoff_location}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Locations */}
                        {(t.pickup_location || t.dropoff_location) && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <MapPin className="h-4 w-4" />
                                        Locations
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-3">
                                        {t.pickup_location && (
                                            <div className="rounded-md bg-muted/40 p-3">
                                                <div className="text-xs text-muted-foreground">
                                                    Pickup
                                                </div>
                                                <div className="mt-1 text-sm font-medium">
                                                    {t.pickup_location}
                                                </div>
                                            </div>
                                        )}
                                        {t.dropoff_location && (
                                            <div className="rounded-md bg-muted/40 p-3">
                                                <div className="text-xs text-muted-foreground">
                                                    Dropoff
                                                </div>
                                                <div className="mt-1 text-sm font-medium">
                                                    {t.dropoff_location}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
