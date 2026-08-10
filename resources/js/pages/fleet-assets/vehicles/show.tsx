import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    FLEET_COLORS,
    ProgressRing,
    SparklineChart,
} from '@/components/fleet-charts';
import FleetTimeline, { type TimelineEntry } from '@/components/fleet-timeline';
import LeafletMap, { MapGeofence, MapMarker } from '@/components/leaflet-map';
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
import { Switch } from '@/components/ui/switch';
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import {
    formatDate,
    formatDateTime,
    formatDistance,
    severityColor,
} from '@/lib/fleet-utils';
import { FleetCompactHero } from '@/pages/fleet-assets/components/fleet-compact-hero';
import { FleetHeroAction } from '@/pages/fleet-assets/components/fleet-hero-kit';
import {
    type VehicleTechnologyProjection,
    VehicleTechnologyProjectionPanel,
} from '@/pages/fleet-assets/vehicles/vehicle-technology-projection';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Car, Cpu, Gauge } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

type EligibleDriver = {
    id: number;
    name: string;
    email: string;
    licence_status?: string | null;
    licence_expires_at?: string | null;
};

type Props = {
    asset: {
        id: number;
        name: string;
        asset_tag: string;
        category: string;
        status: string;
        registration_number: string | null;
        registration_expires_at: string | null;
        wof_expires_at: string | null;
        cof_expires_at: string | null;
        fuel_type: string | null;
        odometer_km: number | null;
        manufacturer: string | null;
        model: string | null;
        serial_number: string | null;
        home_site: { id: number; name: string } | null;
        primary_driver: { id: number; name: string; email: string } | null;
        has_wheelchair_ramp?: boolean;
        has_hoist?: boolean;
        has_child_seat_anchors?: boolean;
        has_medical_storage?: boolean;
        seating_capacity?: number | null;
        accessibility_notes?: string | null;
        inspection_due_at?: string | null;
    };
    state: {
        status: string;
        lat: number;
        lng: number;
        speed_kph: number;
        heading_deg: number | null;
        battery_pct: number;
        last_seen_at: string;
        consent_blocked: boolean;
    } | null;
    trips: Array<{
        id: number;
        started_at: string;
        ended_at: string | null;
        distance_km: number;
        duration_s: number;
        status: string;
        start_address: string | null;
        end_address: string | null;
    }>;
    geofences: Array<{
        id: number;
        name: string;
        type: string;
        breach_type: string | null;
        is_active: boolean;
        shape: unknown;
    }>;
    signals: Array<{
        id: number;
        signal_type: string;
        severity: string;
        occurred_at: string;
        payload: unknown;
    }>;
    fuel_logs: Array<{
        id: number;
        logged_at: string;
        fuel_type: string | null;
        quantity_litres: number;
        total_cost: number;
        odometer_km: number | null;
    }>;
    driver_sessions: Array<{
        id: number;
        driver: { id: number; name: string } | null;
        started_at: string;
        ended_at: string | null;
        status: string;
    }>;
    work_orders: Array<Record<string, unknown>>;
    bookings: Array<Record<string, unknown>>;
    incidents: Array<{
        id: number;
        incident_type: string;
        severity: string;
        occurred_at: string | null;
        status: string;
        location: string | null;
    }>;
    sites: Array<{ id: number; name: string }>;
    eligible_drivers: EligibleDriver[];
    can: {
        manage: boolean;
        inspect: boolean;
        view_vehicle_technology: boolean;
    };
    service_prediction: {
        predicted_days: number | null;
        avg_daily_km: number;
        current_km: number;
        next_service_km: number;
        schedule_name: string;
        km_trend: number[];
    } | null;
    timeline?: TimelineEntry[];
    vehicle_technology?: VehicleTechnologyProjection | null;
};

function isExpiringSoon(dateStr: string | null): boolean {
    if (!dateStr) return false;
    const diff =
        (new Date(dateStr).getTime() - new Date().getTime()) /
        (1000 * 60 * 60 * 24);
    return diff <= 30 && diff >= 0;
}

function isExpired(dateStr: string | null): boolean {
    if (!dateStr) return false;
    return new Date(dateStr) < new Date();
}

export default function VehicleShow({
    asset: vehicle,
    state,
    trips,
    geofences,
    signals,
    fuel_logs,
    driver_sessions,
    work_orders,
    bookings,
    incidents,
    sites,
    eligible_drivers,
    can,
    service_prediction,
    timeline,
    vehicle_technology,
}: Props) {
    const canManage = can.manage;
    const canInspect = can.inspect;
    const assignDriverForm = useForm({
        primary_driver_user_id: vehicle.primary_driver?.id
            ? String(vehicle.primary_driver.id)
            : '',
    });
    const assignHomeForm = useForm({
        home_site_id: vehicle.home_site?.id ? String(vehicle.home_site.id) : '',
    });
    const [showRemoveDriverDialog, setShowRemoveDriverDialog] = useState(false);
    const canViewVehicleTechnology = can.view_vehicle_technology;
    const [activeSection, setActiveSection] = useState<
        'operations' | 'technology'
    >(() => {
        if (typeof window === 'undefined') return 'operations';
        return new URLSearchParams(window.location.search).get('tab') ===
            'technology'
            ? 'technology'
            : 'operations';
    });
    const [technologyLoading, setTechnologyLoading] = useState(false);
    const [technologyFailed, setTechnologyFailed] = useState(false);
    const technologyRequested = useRef(false);
    const hasTechnologyProp = vehicle_technology !== undefined;

    useEffect(() => {
        if (
            activeSection !== 'technology' ||
            !canViewVehicleTechnology ||
            hasTechnologyProp ||
            technologyRequested.current
        ) {
            return;
        }

        technologyRequested.current = true;
        setTechnologyLoading(true);
        setTechnologyFailed(false);
        const stopExceptionListener = router.on('exception', () => {
            setTechnologyFailed(true);
            setTechnologyLoading(false);
        });

        router.reload({
            only: ['vehicle_technology'],
            preserveScroll: true,
            preserveState: true,
            onError: () => setTechnologyFailed(true),
            onFinish: () => setTechnologyLoading(false),
        });

        return stopExceptionListener;
    }, [activeSection, canViewVehicleTechnology, hasTechnologyProp]);

    const openSection = (section: string) => {
        const next = section === 'technology' ? 'technology' : 'operations';
        setActiveSection(next);
        if (typeof window !== 'undefined') {
            const url = new URL(window.location.href);
            if (next === 'technology')
                url.searchParams.set('tab', 'technology');
            else url.searchParams.delete('tab');
            window.history.replaceState(window.history.state, '', url);
        }
    };

    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.hidden) return;
            router.reload({ only: ['state', 'trips', 'signals'] });
        }, 30000);
        return () => window.clearInterval(interval);
    }, []);

    // Lazy-load timeline on mount
    const [timelineLoaded, setTimelineLoaded] = useState(!!timeline);
    useEffect(() => {
        if (!timelineLoaded) {
            router.reload({
                only: ['timeline'],
                onSuccess: () => setTimelineLoaded(true),
            });
        }
    }, [timelineLoaded]);

    const markers = useMemo<MapMarker[]>(() => {
        const result: MapMarker[] = [];
        if (state?.lat && state?.lng) {
            result.push({
                id: `v-${vehicle.id}`,
                lat: Number(state.lat),
                lng: Number(state.lng),
                title: vehicle.name ?? vehicle.asset_tag,
                type: 'vehicle',
                status: state.status,
                popup: `Speed: ${state.speed_kph ?? 0} kph | Battery: ${state.battery_pct ?? 0}%`,
            });
        }
        return result;
    }, [state, vehicle]);

    const mapGeofences = useMemo<MapGeofence[]>(() => {
        return (geofences ?? [])
            .filter((g) => g.is_active)
            .map((gf) => {
                const shape = (gf.shape ?? {}) as Record<string, unknown>;
                return {
                    id: gf.id,
                    name: gf.name,
                    type: gf.type as 'circle' | 'polygon',
                    center: shape.center as
                        | { lat: number; lng: number }
                        | undefined,
                    radius_m: shape.radius_m as number | undefined,
                    coordinates: shape.coordinates as
                        | { lat: number; lng: number }[]
                        | undefined,
                };
            });
    }, [geofences]);

    const center = useMemo(() => {
        if (state?.lat && state?.lng)
            return { lat: Number(state.lat), lng: Number(state.lng) };
        return { lat: -36.8485, lng: 174.7633 };
    }, [state]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Vehicles', href: '/fleet-assets/vehicles' },
                { title: vehicle.name ?? 'Vehicle', href: '#' },
            ]}
        >
            <Head title={`Vehicle: ${vehicle.name}`} />
            <PageShell>
                <FleetCompactHero
                    pill={`Vehicle · ${state?.status ?? 'offline'}`}
                    title={
                        <div className="flex flex-wrap items-center gap-2">
                            <Car className="h-5 w-5" />
                            <span>{vehicle.name ?? vehicle.asset_tag}</span>
                            <Badge
                                variant={
                                    state?.status === 'online'
                                        ? 'default'
                                        : 'secondary'
                                }
                            >
                                {state?.status ?? 'offline'}
                            </Badge>
                        </div>
                    }
                    backHref="/fleet-assets/vehicles"
                    backLabel="Vehicles"
                    actions={
                        <>
                            <FleetHeroAction
                                href={`/fleet-assets/vehicles/${vehicle.id}/alerts-config`}
                                icon={AlertTriangle}
                            >
                                Configure Alerts
                            </FleetHeroAction>
                            <FleetHeroAction
                                href={`/fleet-assets/assets/${vehicle.id}`}
                                icon={Car}
                                emphasis
                            >
                                View Full Profile
                            </FleetHeroAction>
                        </>
                    }
                />

                <Tabs value={activeSection} onValueChange={openSection}>
                    <TabsList className="h-auto flex-wrap gap-1 p-1">
                        <TabsTrigger value="operations">
                            <Car className="mr-1.5 h-3.5 w-3.5" />
                            Vehicle operations
                        </TabsTrigger>
                        <TabsTrigger value="technology">
                            <Cpu className="mr-1.5 h-3.5 w-3.5" />
                            Vehicle technology
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="operations" className="space-y-4">
                        {/* WOF / Rego warnings */}
                        <div className="flex flex-wrap gap-2">
                            {vehicle.registration_number && (
                                <Badge variant="outline">
                                    Rego: {vehicle.registration_number}
                                </Badge>
                            )}
                            {vehicle.wof_expires_at && (
                                <Badge
                                    variant={
                                        isExpired(vehicle.wof_expires_at)
                                            ? 'destructive'
                                            : isExpiringSoon(
                                                    vehicle.wof_expires_at,
                                                )
                                              ? 'default'
                                              : 'outline'
                                    }
                                >
                                    WOF: {formatDate(vehicle.wof_expires_at)}
                                    {isExpired(vehicle.wof_expires_at) &&
                                        ' (Expired)'}
                                </Badge>
                            )}
                            {vehicle.registration_expires_at && (
                                <Badge
                                    variant={
                                        isExpired(
                                            vehicle.registration_expires_at,
                                        )
                                            ? 'destructive'
                                            : isExpiringSoon(
                                                    vehicle.registration_expires_at,
                                                )
                                              ? 'default'
                                              : 'outline'
                                    }
                                >
                                    Rego Exp:{' '}
                                    {formatDate(
                                        vehicle.registration_expires_at,
                                    )}
                                    {isExpired(
                                        vehicle.registration_expires_at,
                                    ) && ' (Expired)'}
                                </Badge>
                            )}
                        </div>

                        {/* ============================================================ */}
                        {/*  SECTION 1 - Map + Status sidebar                            */}
                        {/* ============================================================ */}
                        <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                            <Card className="overflow-hidden">
                                <CardContent className="p-0">
                                    <LeafletMap
                                        center={center}
                                        zoom={14}
                                        markers={markers}
                                        geofences={mapGeofences}
                                        height={350}
                                    />
                                </CardContent>
                            </Card>
                            <div className="space-y-3">
                                <div className="grid grid-cols-2 gap-3">
                                    <Card className="flex flex-col items-center justify-center border bg-primary/10 py-3 dark:bg-primary/30">
                                        <ProgressRing
                                            value={state?.battery_pct ?? 0}
                                            size={70}
                                            color={FLEET_COLORS.primary}
                                            label="Battery"
                                        />
                                    </Card>
                                    <Card className="flex flex-col items-center justify-center border bg-primary/10 py-3 dark:bg-primary/30">
                                        <ProgressRing
                                            value={Math.min(
                                                ((trips ?? []).length / 30) *
                                                    100,
                                                100,
                                            )}
                                            size={70}
                                            color={FLEET_COLORS.secondary}
                                            label="Utilization"
                                        />
                                    </Card>
                                </div>
                                <Card>
                                    <CardContent className="space-y-1.5 p-3 text-sm">
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">
                                                Status
                                            </span>
                                            <Badge
                                                variant={
                                                    state?.status === 'online'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {state?.status ?? 'offline'}
                                            </Badge>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">
                                                Speed
                                            </span>
                                            <span>
                                                {state?.speed_kph ?? 0} kph
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">
                                                Battery
                                            </span>
                                            <span>
                                                {state?.battery_pct ?? 0}%
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">
                                                Last Seen
                                            </span>
                                            <span className="text-xs">
                                                {state?.last_seen_at
                                                    ? formatDateTime(
                                                          state.last_seen_at,
                                                      )
                                                    : '---'}
                                            </span>
                                        </div>
                                        {vehicle.odometer_km != null && (
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">
                                                    Odometer
                                                </span>
                                                <span>
                                                    {formatDistance(
                                                        vehicle.odometer_km ??
                                                            0,
                                                    )}
                                                </span>
                                            </div>
                                        )}
                                        {vehicle.home_site && (
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">
                                                    Home Base
                                                </span>
                                                <span>
                                                    {vehicle.home_site.name}
                                                </span>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>
                        </div>

                        {/* ============================================================ */}
                        {/*  SECTION 2 - Trips + Fuel + Bookings (3 col)                 */}
                        {/* ============================================================ */}
                        <div className="grid gap-4 lg:grid-cols-3">
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between pb-2">
                                    <CardTitle className="text-sm">
                                        Recent Trips
                                    </CardTitle>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="h-6 text-[10px]"
                                        asChild
                                    >
                                        <Link href={`/fleet-assets/trips`}>
                                            View all
                                        </Link>
                                    </Button>
                                </CardHeader>
                                <CardContent>
                                    {(trips ?? []).length > 1 && (
                                        <div className="mb-2 flex items-center gap-2 rounded-md border p-1.5">
                                            <span className="text-[10px] text-muted-foreground">
                                                Trend
                                            </span>
                                            <SparklineChart
                                                data={trips
                                                    .slice(0, 10)
                                                    .reverse()
                                                    .map(
                                                        (t) =>
                                                            t.distance_km ?? 0,
                                                    )}
                                                color={FLEET_COLORS.primary}
                                                height={24}
                                                width={100}
                                            />
                                        </div>
                                    )}
                                    {(trips ?? []).length > 0 ? (
                                        <div className="space-y-1.5">
                                            {trips.slice(0, 4).map((trip) => (
                                                <div
                                                    key={trip.id}
                                                    className="rounded border p-1.5 text-[10px]"
                                                >
                                                    <div className="font-medium">
                                                        {formatDateTime(
                                                            trip.started_at,
                                                        )}
                                                    </div>
                                                    <div className="text-muted-foreground">
                                                        {trip.distance_km ?? 0}{' '}
                                                        km ·{' '}
                                                        {Math.round(
                                                            (trip.duration_s ??
                                                                0) / 60,
                                                        )}{' '}
                                                        min
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">
                                            No trips recorded.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">
                                        Recent Fuel
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {(fuel_logs ?? []).length > 0 ? (
                                        <div className="space-y-1.5">
                                            {fuel_logs
                                                .slice(0, 5)
                                                .map((log) => (
                                                    <div
                                                        key={log.id}
                                                        className="flex items-center justify-between rounded border p-1.5 text-[10px]"
                                                    >
                                                        <span>
                                                            {formatDate(
                                                                log.logged_at,
                                                            )}
                                                        </span>
                                                        <span className="font-medium">
                                                            {log.quantity_litres ??
                                                                0}
                                                            L - $
                                                            {(
                                                                log.total_cost ??
                                                                0
                                                            ).toFixed(2)}
                                                        </span>
                                                    </div>
                                                ))}
                                        </div>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">
                                            No fuel logs.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">
                                        Upcoming Bookings
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {(bookings ?? []).length > 0 ? (
                                        <div className="space-y-1.5">
                                            {(bookings ?? [])
                                                .slice(0, 4)
                                                .map((b) => (
                                                    <Link
                                                        key={String(b.id ?? '')}
                                                        href={`/fleet-assets/bookings/${b.id}`}
                                                        className="block rounded border p-1.5 text-[10px] hover:bg-muted/50"
                                                    >
                                                        <div className="font-medium">
                                                            {String(
                                                                b.purpose ??
                                                                    b.notes ??
                                                                    'Booking',
                                                            )}
                                                        </div>
                                                        <div className="text-muted-foreground">
                                                            {formatDate(
                                                                b.start_at
                                                                    ? String(
                                                                          b.start_at,
                                                                      )
                                                                    : null,
                                                            )}
                                                        </div>
                                                    </Link>
                                                ))}
                                        </div>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">
                                            No upcoming bookings.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        {/* ============================================================ */}
                        {/*  SECTION 3 - Driver + Home Site + Geofences (3 col)          */}
                        {/* ============================================================ */}
                        <div className="grid gap-4 lg:grid-cols-3">
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">
                                        Primary Driver
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {vehicle.primary_driver && (
                                        <div className="flex items-center justify-between rounded border p-2 text-sm">
                                            <div>
                                                <div className="font-medium">
                                                    {
                                                        vehicle.primary_driver
                                                            .name
                                                    }
                                                </div>
                                                <div className="text-[10px] text-muted-foreground">
                                                    {
                                                        vehicle.primary_driver
                                                            .email
                                                    }
                                                </div>
                                            </div>
                                            {canManage && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-6 text-[10px]"
                                                    onClick={() =>
                                                        setShowRemoveDriverDialog(
                                                            true,
                                                        )
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                    {canManage ? (
                                        eligible_drivers.length > 0 ? (
                                            <form
                                                onSubmit={(e) => {
                                                    e.preventDefault();
                                                    if (
                                                        !assignDriverForm.data
                                                            .primary_driver_user_id
                                                    )
                                                        return;
                                                    const sel = (
                                                        eligible_drivers ?? []
                                                    ).find(
                                                        (d) =>
                                                            String(d.id) ===
                                                            assignDriverForm
                                                                .data
                                                                .primary_driver_user_id,
                                                    );
                                                    if (
                                                        sel?.licence_expires_at &&
                                                        isExpired(
                                                            sel.licence_expires_at,
                                                        )
                                                    )
                                                        return;
                                                    router.put(
                                                        `/fleet-assets/vehicles/${vehicle.id}`,
                                                        {
                                                            primary_driver_user_id:
                                                                Number(
                                                                    assignDriverForm
                                                                        .data
                                                                        .primary_driver_user_id,
                                                                ),
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }}
                                                className="flex gap-2"
                                            >
                                                <Select
                                                    value={
                                                        assignDriverForm.data
                                                            .primary_driver_user_id
                                                    }
                                                    onValueChange={(v) => {
                                                        const d = (
                                                            eligible_drivers ??
                                                            []
                                                        ).find(
                                                            (d) =>
                                                                String(d.id) ===
                                                                v,
                                                        );
                                                        if (
                                                            d?.licence_expires_at &&
                                                            isExpired(
                                                                d.licence_expires_at,
                                                            )
                                                        )
                                                            return;
                                                        assignDriverForm.setData(
                                                            'primary_driver_user_id',
                                                            v,
                                                        );
                                                    }}
                                                >
                                                    <SelectTrigger className="h-8 flex-1 text-xs">
                                                        <SelectValue placeholder="Select driver..." />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {(
                                                            eligible_drivers ??
                                                            []
                                                        ).map((driver) => {
                                                            const exp =
                                                                driver.licence_expires_at
                                                                    ? isExpired(
                                                                          driver.licence_expires_at,
                                                                      )
                                                                    : false;
                                                            return (
                                                                <SelectItem
                                                                    key={
                                                                        driver.id
                                                                    }
                                                                    value={String(
                                                                        driver.id,
                                                                    )}
                                                                    disabled={
                                                                        exp
                                                                    }
                                                                >
                                                                    <span className="flex items-center gap-1">
                                                                        {
                                                                            driver.name
                                                                        }
                                                                        {exp && (
                                                                            <span className="text-[9px] text-status-critical">
                                                                                (Expired)
                                                                            </span>
                                                                        )}
                                                                    </span>
                                                                </SelectItem>
                                                            );
                                                        })}
                                                    </SelectContent>
                                                </Select>
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    className="h-8"
                                                    disabled={
                                                        assignDriverForm.processing ||
                                                        !assignDriverForm.data
                                                            .primary_driver_user_id
                                                    }
                                                >
                                                    Assign
                                                </Button>
                                            </form>
                                        ) : (
                                            <p className="text-xs text-muted-foreground">
                                                No eligible drivers are
                                                available to assign yet.
                                            </p>
                                        )
                                    ) : (
                                        !vehicle.primary_driver && (
                                            <p className="text-xs text-muted-foreground">
                                                No primary driver assigned.
                                            </p>
                                        )
                                    )}
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">
                                        Home Site
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {vehicle.home_site && (
                                        <div className="rounded border p-2 text-sm font-medium">
                                            {vehicle.home_site.name}
                                        </div>
                                    )}
                                    {canManage ? (
                                        <form
                                            onSubmit={(e) => {
                                                e.preventDefault();
                                                if (
                                                    !assignHomeForm.data
                                                        .home_site_id
                                                )
                                                    return;
                                                router.put(
                                                    `/fleet-assets/vehicles/${vehicle.id}`,
                                                    {
                                                        home_site_id: Number(
                                                            assignHomeForm.data
                                                                .home_site_id,
                                                        ),
                                                    },
                                                    { preserveScroll: true },
                                                );
                                            }}
                                            className="flex gap-2"
                                        >
                                            <Select
                                                value={
                                                    assignHomeForm.data
                                                        .home_site_id
                                                }
                                                onValueChange={(v) =>
                                                    assignHomeForm.setData(
                                                        'home_site_id',
                                                        v,
                                                    )
                                                }
                                            >
                                                <SelectTrigger className="h-8 flex-1 text-xs">
                                                    <SelectValue placeholder="Select site..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {(sites ?? []).map((s) => (
                                                        <SelectItem
                                                            key={s.id}
                                                            value={String(s.id)}
                                                        >
                                                            {s.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <Button
                                                type="submit"
                                                size="sm"
                                                className="h-8"
                                                disabled={
                                                    !assignHomeForm.data
                                                        .home_site_id
                                                }
                                            >
                                                Set
                                            </Button>
                                        </form>
                                    ) : (
                                        !vehicle.home_site && (
                                            <p className="text-xs text-muted-foreground">
                                                No home site assigned.
                                            </p>
                                        )
                                    )}
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">
                                        Geofences
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {(geofences ?? []).length > 0 ? (
                                        <div className="space-y-1">
                                            {geofences.map((g) => (
                                                <div
                                                    key={g.id}
                                                    className="rounded border p-1.5 text-[10px] text-muted-foreground"
                                                >
                                                    {g.name} · {g.type} ·{' '}
                                                    {g.is_active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">
                                            No geofences.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        {/* ============================================================ */}
                        {/*  SECTION 3B - Inspections & Checklists                       */}
                        {/* ============================================================ */}
                        <div className="grid gap-4 lg:grid-cols-[2fr_1fr_1fr]">
                            {/* Inspection Schedule */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">
                                        Inspection Schedule
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="rounded-lg border p-3">
                                            <p className="mb-1 text-[10px] tracking-wider text-muted-foreground uppercase">
                                                Next Inspection Due
                                            </p>
                                            {vehicle.inspection_due_at ? (
                                                <p
                                                    className={`text-sm font-bold ${
                                                        new Date(
                                                            vehicle.inspection_due_at,
                                                        ) < new Date()
                                                            ? 'text-status-critical'
                                                            : new Date(
                                                                    vehicle.inspection_due_at,
                                                                ) <
                                                                new Date(
                                                                    Date.now() +
                                                                        30 *
                                                                            86400000,
                                                                )
                                                              ? 'text-status-warning'
                                                              : 'text-primary'
                                                    }`}
                                                >
                                                    {formatDate(
                                                        vehicle.inspection_due_at,
                                                    )}
                                                    {new Date(
                                                        vehicle.inspection_due_at,
                                                    ) < new Date() && (
                                                        <Badge
                                                            variant="destructive"
                                                            className="ml-2 text-[9px]"
                                                        >
                                                            Overdue
                                                        </Badge>
                                                    )}
                                                </p>
                                            ) : (
                                                <p className="text-sm text-muted-foreground">
                                                    Not scheduled
                                                </p>
                                            )}
                                        </div>
                                        <div className="rounded-lg border p-3">
                                            <p className="mb-1 text-[10px] tracking-wider text-muted-foreground uppercase">
                                                Set Due Date
                                            </p>
                                            {canManage ? (
                                                <form
                                                    onSubmit={(e) => {
                                                        e.preventDefault();
                                                        const input = (
                                                            e.target as HTMLFormElement
                                                        ).elements.namedItem(
                                                            'due',
                                                        ) as HTMLInputElement;
                                                        if (input?.value) {
                                                            router.put(
                                                                `/fleet-assets/vehicles/${vehicle.id}`,
                                                                {
                                                                    inspection_due_at:
                                                                        input.value,
                                                                    requires_inspection: true,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }
                                                    }}
                                                    className="flex gap-1.5"
                                                >
                                                    <Input
                                                        type="date"
                                                        name="due"
                                                        defaultValue={
                                                            vehicle.inspection_due_at?.split(
                                                                'T',
                                                            )[0] ?? ''
                                                        }
                                                        className="h-8 flex-1 text-xs"
                                                    />
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        className="h-8 text-xs"
                                                    >
                                                        Set
                                                    </Button>
                                                </form>
                                            ) : (
                                                <p className="text-xs text-muted-foreground">
                                                    View-only for this vehicle.
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        {canInspect && (
                                            <Button
                                                variant="default"
                                                size="sm"
                                                className="h-8 bg-primary text-xs hover:bg-primary"
                                                asChild
                                            >
                                                <Link
                                                    href={`/fleet-assets/inspections/create?asset_id=${vehicle.id}&type=pre-trip`}
                                                >
                                                    Start Pre-Trip Inspection
                                                </Link>
                                            </Button>
                                        )}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="h-8 text-xs"
                                            asChild
                                        >
                                            <Link
                                                href={`/fleet-assets/inspections?vehicle_id=${vehicle.id}`}
                                            >
                                                View History
                                            </Link>
                                        </Button>
                                    </div>
                                    {!canInspect && (
                                        <p className="text-xs text-muted-foreground">
                                            Starting inspections requires fleet
                                            maintenance manager access.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                            {/* Daily Check */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">
                                        Daily Check
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="mb-3 text-xs text-muted-foreground">
                                        Quick daily vehicle condition check.
                                    </p>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="h-8 w-full text-xs"
                                        asChild
                                    >
                                        <Link href="/fleet-assets/daily-check">
                                            Go to Daily Checks
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                            {/* Checklists */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">
                                        Checklists
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="mb-3 text-xs text-muted-foreground">
                                        Maintenance checklists and templates.
                                    </p>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="h-8 w-full text-xs"
                                        asChild
                                    >
                                        <Link href="/fleet-assets/maintenance/checklists">
                                            View Templates
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        </div>

                        {/* ============================================================ */}
                        {/*  SECTION 3C - Predictive Maintenance                        */}
                        {/* ============================================================ */}
                        {service_prediction && (
                            <Card className="border-primary dark:border-primary/30">
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm">
                                        <Gauge className="h-4 w-4 text-primary" />
                                        Predictive Maintenance
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        <div className="rounded-lg border bg-primary/10 p-3 dark:bg-primary/20">
                                            <p className="mb-1 text-[10px] tracking-wider text-muted-foreground uppercase">
                                                Est. Next Service
                                            </p>
                                            <p
                                                className={`text-lg font-bold ${
                                                    service_prediction.predicted_days !=
                                                        null &&
                                                    service_prediction.predicted_days <=
                                                        7
                                                        ? 'text-status-critical'
                                                        : service_prediction.predicted_days !=
                                                                null &&
                                                            service_prediction.predicted_days <=
                                                                30
                                                          ? 'text-status-warning'
                                                          : 'text-primary'
                                                }`}
                                            >
                                                {service_prediction.predicted_days !=
                                                null
                                                    ? `${service_prediction.predicted_days} days`
                                                    : 'Insufficient data'}
                                            </p>
                                            <p className="text-[10px] text-muted-foreground">
                                                {
                                                    service_prediction.schedule_name
                                                }
                                            </p>
                                        </div>
                                        <div className="rounded-lg border p-3">
                                            <p className="mb-1 text-[10px] tracking-wider text-muted-foreground uppercase">
                                                Avg Daily km
                                            </p>
                                            <p className="text-lg font-bold">
                                                {
                                                    service_prediction.avg_daily_km
                                                }
                                            </p>
                                            <p className="text-[10px] text-muted-foreground">
                                                Last 30 days
                                            </p>
                                        </div>
                                        <div className="rounded-lg border p-3">
                                            <p className="mb-1 text-[10px] tracking-wider text-muted-foreground uppercase">
                                                Current Odometer
                                            </p>
                                            <p className="text-lg font-bold">
                                                {service_prediction.current_km.toLocaleString()}{' '}
                                                km
                                            </p>
                                        </div>
                                        <div className="rounded-lg border p-3">
                                            <p className="mb-1 text-[10px] tracking-wider text-muted-foreground uppercase">
                                                Next Service At
                                            </p>
                                            <p className="text-lg font-bold">
                                                {service_prediction.next_service_km.toLocaleString()}{' '}
                                                km
                                            </p>
                                        </div>
                                    </div>
                                    {service_prediction.km_trend.length > 1 && (
                                        <div className="flex items-center gap-2 rounded-md border p-2">
                                            <span className="shrink-0 text-[10px] text-muted-foreground">
                                                km Trend
                                            </span>
                                            <SparklineChart
                                                data={
                                                    service_prediction.km_trend
                                                }
                                                color={FLEET_COLORS.primary}
                                                height={28}
                                                width={160}
                                            />
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* ============================================================ */}
                        {/*  SECTION 4 - Insurance + Incidents (2 col)                   */}
                        {/* ============================================================ */}
                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between pb-2">
                                    <CardTitle className="text-sm">
                                        Insurance & Documents
                                    </CardTitle>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="h-6 text-[10px]"
                                        asChild
                                    >
                                        <Link
                                            href={`/fleet-assets/assets/${vehicle.id}`}
                                        >
                                            View All
                                        </Link>
                                    </Button>
                                </CardHeader>
                                <CardContent className="space-y-1.5 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Provider
                                        </span>
                                        <span className="font-medium">
                                            {(
                                                vehicle as Record<
                                                    string,
                                                    unknown
                                                >
                                            ).insurance_provider
                                                ? String(
                                                      (
                                                          vehicle as Record<
                                                              string,
                                                              unknown
                                                          >
                                                      ).insurance_provider,
                                                  )
                                                : '---'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Policy #
                                        </span>
                                        <span className="font-mono font-medium">
                                            {(
                                                vehicle as Record<
                                                    string,
                                                    unknown
                                                >
                                            ).insurance_policy_number
                                                ? String(
                                                      (
                                                          vehicle as Record<
                                                              string,
                                                              unknown
                                                          >
                                                      ).insurance_policy_number,
                                                  )
                                                : '---'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Expires
                                        </span>
                                        <span className="font-medium">
                                            {formatDate(
                                                (
                                                    vehicle as Record<
                                                        string,
                                                        unknown
                                                    >
                                                ).insurance_expires_at
                                                    ? String(
                                                          (
                                                              vehicle as Record<
                                                                  string,
                                                                  unknown
                                                              >
                                                          )
                                                              .insurance_expires_at,
                                                      )
                                                    : null,
                                            )}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Type
                                        </span>
                                        <span className="font-medium capitalize">
                                            {(
                                                vehicle as Record<
                                                    string,
                                                    unknown
                                                >
                                            ).insurance_type
                                                ? String(
                                                      (
                                                          vehicle as Record<
                                                              string,
                                                              unknown
                                                          >
                                                      ).insurance_type,
                                                  ).replace(/_/g, ' ')
                                                : '---'}
                                        </span>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between pb-2">
                                    <CardTitle className="text-sm">
                                        Recent Incidents
                                    </CardTitle>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="h-6 text-[10px]"
                                        asChild
                                    >
                                        <Link
                                            href={`/fleet-assets/incidents?report=vehicle&asset_id=${vehicle.id}`}
                                        >
                                            Report
                                        </Link>
                                    </Button>
                                </CardHeader>
                                <CardContent>
                                    {(incidents ?? []).length > 0 ? (
                                        <div className="space-y-1.5">
                                            {(incidents ?? [])
                                                .slice(0, 4)
                                                .map((inc) => (
                                                    <Link
                                                        key={inc.id}
                                                        href={`/fleet-assets/incidents/${inc.id}`}
                                                        className="block rounded border p-1.5 text-[10px] hover:bg-muted/50"
                                                    >
                                                        <div className="flex items-center justify-between">
                                                            <div className="flex items-center gap-1">
                                                                <Badge
                                                                    className={`${severityColor(inc.severity)} h-4 px-1 text-[9px] text-white`}
                                                                >
                                                                    {
                                                                        inc.severity
                                                                    }
                                                                </Badge>
                                                                <span className="font-medium capitalize">
                                                                    {(
                                                                        inc.incident_type ??
                                                                        ''
                                                                    ).replace(
                                                                        /_/g,
                                                                        ' ',
                                                                    )}
                                                                </span>
                                                            </div>
                                                            <Badge
                                                                variant="outline"
                                                                className="h-4 text-[9px]"
                                                            >
                                                                {inc.status}
                                                            </Badge>
                                                        </div>
                                                    </Link>
                                                ))}
                                        </div>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">
                                            No incidents recorded.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        {/* ============================================================ */}
                        {/*  SECTION 5 - Accessibility Features                          */}
                        {/* ============================================================ */}
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">
                                    Accessibility Features
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    {(
                                        [
                                            {
                                                key: 'has_wheelchair_ramp',
                                                label: 'Wheelchair Ramp',
                                            },
                                            {
                                                key: 'has_hoist',
                                                label: 'Hoist',
                                            },
                                            {
                                                key: 'has_child_seat_anchors',
                                                label: 'Child Seat Anchors',
                                            },
                                            {
                                                key: 'has_medical_storage',
                                                label: 'Medical Storage',
                                            },
                                        ] as const
                                    ).map((item) => (
                                        <div
                                            key={item.key}
                                            className={`flex items-center justify-between rounded-xl border-2 p-3 transition-all ${
                                                (
                                                    vehicle as Record<
                                                        string,
                                                        unknown
                                                    >
                                                )[item.key]
                                                    ? 'border-primary bg-primary/5 dark:border-primary/30 dark:bg-primary/10'
                                                    : 'border-muted bg-muted/20'
                                            }`}
                                        >
                                            <span className="text-sm font-medium">
                                                {item.label}
                                            </span>
                                            {canManage ? (
                                                <Switch
                                                    checked={
                                                        !!(
                                                            vehicle as Record<
                                                                string,
                                                                unknown
                                                            >
                                                        )[item.key]
                                                    }
                                                    onCheckedChange={() => {
                                                        router.put(
                                                            `/fleet-assets/vehicles/${vehicle.id}`,
                                                            {
                                                                [item.key]: !(
                                                                    vehicle as Record<
                                                                        string,
                                                                        unknown
                                                                    >
                                                                )[item.key],
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }}
                                                />
                                            ) : (
                                                <Badge
                                                    variant={
                                                        (
                                                            vehicle as Record<
                                                                string,
                                                                unknown
                                                            >
                                                        )[item.key]
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {(
                                                        vehicle as Record<
                                                            string,
                                                            unknown
                                                        >
                                                    )[item.key]
                                                        ? 'Enabled'
                                                        : 'Not set'}
                                                </Badge>
                                            )}
                                        </div>
                                    ))}
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="text-sm font-medium">
                                            Seating Capacity
                                        </label>
                                        {canManage ? (
                                            <form
                                                onSubmit={(e) => {
                                                    e.preventDefault();
                                                    const input = (
                                                        e.target as HTMLFormElement
                                                    ).elements.namedItem(
                                                        'seating',
                                                    ) as HTMLInputElement;
                                                    if (input?.value) {
                                                        router.put(
                                                            `/fleet-assets/vehicles/${vehicle.id}`,
                                                            {
                                                                seating_capacity:
                                                                    Number(
                                                                        input.value,
                                                                    ),
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }
                                                }}
                                                className="mt-1 flex gap-2"
                                            >
                                                <Input
                                                    type="number"
                                                    name="seating"
                                                    min="1"
                                                    max="50"
                                                    defaultValue={
                                                        vehicle.seating_capacity ??
                                                        ''
                                                    }
                                                    placeholder="e.g. 7"
                                                    className="h-8 w-24 text-xs"
                                                />
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    className="h-8 text-xs"
                                                >
                                                    Set
                                                </Button>
                                            </form>
                                        ) : (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {vehicle.seating_capacity
                                                    ? `${vehicle.seating_capacity} seats`
                                                    : 'Not recorded'}
                                            </p>
                                        )}
                                    </div>
                                    <div>
                                        <label className="text-sm font-medium">
                                            Accessibility Notes
                                        </label>
                                        {canManage ? (
                                            <form
                                                onSubmit={(e) => {
                                                    e.preventDefault();
                                                    const input = (
                                                        e.target as HTMLFormElement
                                                    ).elements.namedItem(
                                                        'acc_notes',
                                                    ) as HTMLTextAreaElement;
                                                    router.put(
                                                        `/fleet-assets/vehicles/${vehicle.id}`,
                                                        {
                                                            accessibility_notes:
                                                                input?.value ??
                                                                '',
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }}
                                                className="mt-1 space-y-2"
                                            >
                                                <textarea
                                                    name="acc_notes"
                                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-xs ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                    rows={2}
                                                    defaultValue={
                                                        vehicle.accessibility_notes ??
                                                        ''
                                                    }
                                                    placeholder="Additional accessibility details..."
                                                />
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    className="h-8 text-xs"
                                                >
                                                    Save Notes
                                                </Button>
                                            </form>
                                        ) : (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {vehicle.accessibility_notes ||
                                                    'No accessibility notes recorded.'}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* ============================================================ */}
                        {/*  VEHICLE TIMELINE                                             */}
                        {/* ============================================================ */}
                        <FleetTimeline
                            entries={timeline ?? []}
                            title="Vehicle Activity"
                            maxVisible={25}
                        />
                    </TabsContent>

                    <TabsContent value="technology">
                        <VehicleTechnologyProjectionPanel
                            projection={
                                canViewVehicleTechnology
                                    ? vehicle_technology
                                    : null
                            }
                            loading={technologyLoading}
                            failed={technologyFailed}
                        />
                    </TabsContent>
                </Tabs>

                {canManage && (
                    <ConfirmDialog
                        open={showRemoveDriverDialog}
                        onClose={() => setShowRemoveDriverDialog(false)}
                        onConfirm={() => {
                            router.put(
                                `/fleet-assets/vehicles/${vehicle.id}`,
                                {
                                    primary_driver_user_id: null,
                                },
                                { preserveScroll: true },
                            );
                        }}
                        title="Remove Driver Assignment"
                        description={`Are you sure you want to remove ${vehicle.primary_driver?.name ?? 'the driver'} as the primary driver for this vehicle?`}
                        confirmText="Remove Driver"
                    />
                )}
            </PageShell>
        </AppLayout>
    );
}
