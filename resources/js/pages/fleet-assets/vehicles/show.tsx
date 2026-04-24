import { SparklineChart, ProgressRing, FLEET_COLORS } from '@/components/fleet-charts';
import LeafletMap, { MapGeofence, MapMarker } from '@/components/leaflet-map';
import FleetTimeline, { type TimelineEntry } from '@/components/fleet-timeline';
import FleetHero from '@/components/fleet-hero';
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
import AppLayout from '@/layouts/app-layout';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Battery,
    Calendar,
    Car,
    Fuel,
    Gauge,
    Home,
    MapPin,
    Route,
    User,
    Wifi,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { formatDate, formatDateTime, formatDistance, severityColor } from '@/lib/fleet-utils';


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
        trackers: Array<{
            id: number;
            vendor: string;
            device_uid: string;
            status: string;
            last_seen_at: string | null;
        }>;
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
};

function isExpiringSoon(dateStr: string | null): boolean {
    if (!dateStr) return false;
    const diff = (new Date(dateStr).getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24);
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
}: Props) {
    const canManage = can.manage;
    const canInspect = can.inspect;
    const assignDriverForm = useForm({ primary_driver_user_id: vehicle.primary_driver?.id ? String(vehicle.primary_driver.id) : '' });
    const assignHomeForm = useForm({ home_site_id: vehicle.home_site?.id ? String(vehicle.home_site.id) : '' });
    const [showRemoveDriverDialog, setShowRemoveDriverDialog] = useState(false);

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
            router.reload({ only: ['timeline'], onSuccess: () => setTimelineLoaded(true) });
        }
    }, []);

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
        return (geofences ?? []).filter((g) => g.is_active).map((gf) => {
            const shape = (gf.shape ?? {}) as Record<string, unknown>;
            return {
                id: gf.id,
                name: gf.name,
                type: gf.type as 'circle' | 'polygon',
                center: shape.center as { lat: number; lng: number } | undefined,
                radius_m: shape.radius_m as number | undefined,
                coordinates: shape.coordinates as { lat: number; lng: number }[] | undefined,
            };
        });
    }, [geofences]);

    const center = useMemo(() => {
        if (state?.lat && state?.lng) return { lat: Number(state.lat), lng: Number(state.lng) };
        return { lat: -36.8485, lng: 174.7633 };
    }, [state, vehicle.home_site]);

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
                <FleetHero
                    title={
                        <div className="flex flex-wrap items-center gap-2">
                            <Car className="h-5 w-5" />
                            <span>{vehicle.name ?? vehicle.asset_tag}</span>
                            <Badge variant={state?.status === 'online' ? 'default' : 'secondary'}>
                                {state?.status ?? 'offline'}
                            </Badge>
                        </div>
                    }
                    backHref="/fleet-assets/vehicles"
                    backLabel="Back to Vehicles"
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/fleet-assets/vehicles/${vehicle.id}/alerts-config`}>
                                    <AlertTriangle className="mr-2 h-4 w-4" />
                                    Configure Alerts
                                </Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/fleet-assets/assets/${vehicle.id}`}>
                                    View Full Profile
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {/* WOF / Rego warnings */}
                <div className="flex flex-wrap gap-2">
                    {vehicle.registration_number && (
                        <Badge variant="outline">Rego: {vehicle.registration_number}</Badge>
                    )}
                    {vehicle.wof_expires_at && (
                        <Badge variant={isExpired(vehicle.wof_expires_at) ? 'destructive' : isExpiringSoon(vehicle.wof_expires_at) ? 'default' : 'outline'}>
                            WOF: {formatDate(vehicle.wof_expires_at)}
                            {isExpired(vehicle.wof_expires_at) && ' (Expired)'}
                        </Badge>
                    )}
                    {vehicle.registration_expires_at && (
                        <Badge variant={isExpired(vehicle.registration_expires_at) ? 'destructive' : isExpiringSoon(vehicle.registration_expires_at) ? 'default' : 'outline'}>
                            Rego Exp: {formatDate(vehicle.registration_expires_at)}
                            {isExpired(vehicle.registration_expires_at) && ' (Expired)'}
                        </Badge>
                    )}
                </div>

                {/* ============================================================ */}
                {/*  SECTION 1 - Map + Status sidebar                            */}
                {/* ============================================================ */}
                <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                    <Card className="overflow-hidden">
                        <CardContent className="p-0">
                            <LeafletMap center={center} zoom={14} markers={markers} geofences={mapGeofences} height={350} />
                        </CardContent>
                    </Card>
                    <div className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                            <Card className="border bg-primary/10 dark:bg-primary/30 flex flex-col items-center justify-center py-3">
                                <ProgressRing value={state?.battery_pct ?? 0} size={70} color={FLEET_COLORS.primary} label="Battery" />
                            </Card>
                            <Card className="border bg-primary/10 dark:bg-primary/30 flex flex-col items-center justify-center py-3">
                                <ProgressRing value={Math.min(((trips ?? []).length / 30) * 100, 100)} size={70} color={FLEET_COLORS.secondary} label="Utilization" />
                            </Card>
                        </div>
                        <Card>
                            <CardContent className="p-3 space-y-1.5 text-sm">
                                <div className="flex justify-between"><span className="text-muted-foreground">Status</span><Badge variant={state?.status === 'online' ? 'default' : 'secondary'}>{state?.status ?? 'offline'}</Badge></div>
                                <div className="flex justify-between"><span className="text-muted-foreground">Speed</span><span>{state?.speed_kph ?? 0} kph</span></div>
                                <div className="flex justify-between"><span className="text-muted-foreground">Battery</span><span>{state?.battery_pct ?? 0}%</span></div>
                                <div className="flex justify-between"><span className="text-muted-foreground">Last Seen</span><span className="text-xs">{state?.last_seen_at ? formatDateTime(state.last_seen_at) : '---'}</span></div>
                                {vehicle.odometer_km != null && <div className="flex justify-between"><span className="text-muted-foreground">Odometer</span><span>{formatDistance((vehicle.odometer_km ?? 0))}</span></div>}
                                {vehicle.home_site && <div className="flex justify-between"><span className="text-muted-foreground">Home Base</span><span>{vehicle.home_site.name}</span></div>}
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {/* ============================================================ */}
                {/*  SECTION 2 - Trips + Fuel + Bookings (3 col)                 */}
                {/* ============================================================ */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2 flex flex-row items-center justify-between">
                            <CardTitle className="text-sm">Recent Trips</CardTitle>
                            <Button variant="ghost" size="sm" className="h-6 text-[10px]" asChild><Link href={`/fleet-assets/trips`}>View all</Link></Button>
                        </CardHeader>
                        <CardContent>
                            {(trips ?? []).length > 1 && (
                                <div className="mb-2 flex items-center gap-2 rounded-md border p-1.5">
                                    <span className="text-[10px] text-muted-foreground">Trend</span>
                                    <SparklineChart data={trips.slice(0, 10).reverse().map((t) => t.distance_km ?? 0)} color={FLEET_COLORS.primary} height={24} width={100} />
                                </div>
                            )}
                            {(trips ?? []).length > 0 ? (
                                <div className="space-y-1.5">
                                    {trips.slice(0, 4).map((trip) => (
                                        <div key={trip.id} className="rounded border p-1.5 text-[10px]">
                                            <div className="font-medium">{formatDateTime(trip.started_at)}</div>
                                            <div className="text-muted-foreground">{trip.distance_km ?? 0} km · {Math.round((trip.duration_s ?? 0) / 60)} min</div>
                                        </div>
                                    ))}
                                </div>
                            ) : <p className="text-xs text-muted-foreground">No trips recorded.</p>}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm">Recent Fuel</CardTitle></CardHeader>
                        <CardContent>
                            {(fuel_logs ?? []).length > 0 ? (
                                <div className="space-y-1.5">
                                    {fuel_logs.slice(0, 5).map((log) => (
                                        <div key={log.id} className="flex items-center justify-between rounded border p-1.5 text-[10px]">
                                            <span>{formatDate(log.logged_at)}</span>
                                            <span className="font-medium">{log.quantity_litres ?? 0}L - ${(log.total_cost ?? 0).toFixed(2)}</span>
                                        </div>
                                    ))}
                                </div>
                            ) : <p className="text-xs text-muted-foreground">No fuel logs.</p>}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm">Upcoming Bookings</CardTitle></CardHeader>
                        <CardContent>
                            {(bookings ?? []).length > 0 ? (
                                <div className="space-y-1.5">
                                    {(bookings ?? []).slice(0, 4).map((b) => (
                                        <Link key={String(b.id ?? '')} href={`/fleet-assets/bookings/${b.id}`} className="block rounded border p-1.5 text-[10px] hover:bg-muted/50">
                                            <div className="font-medium">{String(b.purpose ?? b.notes ?? 'Booking')}</div>
                                            <div className="text-muted-foreground">{b.start_at ? new Date(String(b.start_at)).toLocaleDateString() : '---'}</div>
                                        </Link>
                                    ))}
                                </div>
                            ) : <p className="text-xs text-muted-foreground">No upcoming bookings.</p>}
                        </CardContent>
                    </Card>
                </div>

                {/* ============================================================ */}
                {/*  SECTION 3 - Driver + Home Site + Geofences (3 col)          */}
                {/* ============================================================ */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm">Primary Driver</CardTitle></CardHeader>
                        <CardContent className="space-y-2">
                            {vehicle.primary_driver && (
                                <div className="flex items-center justify-between rounded border p-2 text-sm">
                                    <div><div className="font-medium">{vehicle.primary_driver.name}</div><div className="text-[10px] text-muted-foreground">{vehicle.primary_driver.email}</div></div>
                                    {canManage && (
                                        <Button variant="ghost" size="sm" className="h-6 text-[10px]" onClick={() => setShowRemoveDriverDialog(true)}>Remove</Button>
                                    )}
                                </div>
                            )}
                            {canManage ? (
                                eligible_drivers.length > 0 ? (
                                    <form onSubmit={(e) => { e.preventDefault(); if (!assignDriverForm.data.primary_driver_user_id) return; const sel = (eligible_drivers ?? []).find((d) => String(d.id) === assignDriverForm.data.primary_driver_user_id); if (sel?.licence_expires_at && isExpired(sel.licence_expires_at)) return; router.put(`/fleet-assets/vehicles/${vehicle.id}`, { primary_driver_user_id: Number(assignDriverForm.data.primary_driver_user_id) }, { preserveScroll: true }); }} className="flex gap-2">
                                        <Select value={assignDriverForm.data.primary_driver_user_id} onValueChange={(v) => { const d = (eligible_drivers ?? []).find((d) => String(d.id) === v); if (d?.licence_expires_at && isExpired(d.licence_expires_at)) return; assignDriverForm.setData('primary_driver_user_id', v); }}>
                                            <SelectTrigger className="flex-1 h-8 text-xs"><SelectValue placeholder="Select driver..." /></SelectTrigger>
                                            <SelectContent>
                                                {(eligible_drivers ?? []).map((driver) => { const exp = driver.licence_expires_at ? isExpired(driver.licence_expires_at) : false; return (<SelectItem key={driver.id} value={String(driver.id)} disabled={exp}><span className="flex items-center gap-1">{driver.name}{exp && <span className="text-[9px] text-red-500">(Expired)</span>}</span></SelectItem>); })}
                                            </SelectContent>
                                        </Select>
                                        <Button type="submit" size="sm" className="h-8" disabled={assignDriverForm.processing || !assignDriverForm.data.primary_driver_user_id}>Assign</Button>
                                    </form>
                                ) : (
                                    <p className="text-xs text-muted-foreground">No eligible drivers are available to assign yet.</p>
                                )
                            ) : (
                                !vehicle.primary_driver && <p className="text-xs text-muted-foreground">No primary driver assigned.</p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm">Home Site</CardTitle></CardHeader>
                        <CardContent className="space-y-2">
                            {vehicle.home_site && <div className="rounded border p-2 text-sm font-medium">{vehicle.home_site.name}</div>}
                            {canManage ? (
                                <form onSubmit={(e) => { e.preventDefault(); if (!assignHomeForm.data.home_site_id) return; router.put(`/fleet-assets/vehicles/${vehicle.id}`, { home_site_id: Number(assignHomeForm.data.home_site_id) }, { preserveScroll: true }); }} className="flex gap-2">
                                    <Select value={assignHomeForm.data.home_site_id} onValueChange={(v) => assignHomeForm.setData('home_site_id', v)}>
                                        <SelectTrigger className="flex-1 h-8 text-xs"><SelectValue placeholder="Select site..." /></SelectTrigger>
                                        <SelectContent>{(sites ?? []).map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}</SelectContent>
                                    </Select>
                                    <Button type="submit" size="sm" className="h-8" disabled={!assignHomeForm.data.home_site_id}>Set</Button>
                                </form>
                            ) : (
                                !vehicle.home_site && <p className="text-xs text-muted-foreground">No home site assigned.</p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm">Geofences</CardTitle></CardHeader>
                        <CardContent>
                            {(geofences ?? []).length > 0 ? (
                                <div className="space-y-1">{geofences.map((g) => (<div key={g.id} className="rounded border p-1.5 text-[10px] text-muted-foreground">{g.name} · {g.type} · {g.is_active ? 'Active' : 'Inactive'}</div>))}</div>
                            ) : <p className="text-xs text-muted-foreground">No geofences.</p>}
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
                            <CardTitle className="text-sm">Inspection Schedule</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid grid-cols-2 gap-3">
                                <div className="rounded-lg border p-3">
                                    <p className="text-[10px] uppercase tracking-wider text-muted-foreground mb-1">Next Inspection Due</p>
                                    {vehicle.inspection_due_at ? (
                                        <p className={`text-sm font-bold ${
                                            new Date(vehicle.inspection_due_at) < new Date() ? 'text-red-600' :
                                            new Date(vehicle.inspection_due_at) < new Date(Date.now() + 30 * 86400000) ? 'text-amber-600' :
                                            'text-primary'
                                        }`}>
                                            {formatDate(vehicle.inspection_due_at)}
                                            {new Date(vehicle.inspection_due_at) < new Date() && <Badge variant="destructive" className="ml-2 text-[9px]">Overdue</Badge>}
                                        </p>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">Not scheduled</p>
                                    )}
                                </div>
                                <div className="rounded-lg border p-3">
                                    <p className="text-[10px] uppercase tracking-wider text-muted-foreground mb-1">Set Due Date</p>
                                    {canManage ? (
                                        <form onSubmit={(e) => {
                                            e.preventDefault();
                                            const input = (e.target as HTMLFormElement).elements.namedItem('due') as HTMLInputElement;
                                            if (input?.value) {
                                                router.put(`/fleet-assets/vehicles/${vehicle.id}`, {
                                                    inspection_due_at: input.value,
                                                    requires_inspection: true,
                                                }, { preserveScroll: true });
                                            }
                                        }} className="flex gap-1.5">
                                            <Input type="date" name="due" defaultValue={vehicle.inspection_due_at?.split('T')[0] ?? ''} className="h-8 text-xs flex-1" />
                                            <Button type="submit" size="sm" className="h-8 text-xs">Set</Button>
                                        </form>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">View-only for this vehicle.</p>
                                    )}
                                </div>
                            </div>
                            <div className="flex gap-2">
                                {canInspect && (
                                    <Button variant="default" size="sm" className="h-8 text-xs bg-primary hover:bg-primary" asChild>
                                        <Link href={`/fleet-assets/inspections/create?asset_id=${vehicle.id}&type=pre-trip`}>Start Pre-Trip Inspection</Link>
                                    </Button>
                                )}
                                <Button variant="outline" size="sm" className="h-8 text-xs" asChild>
                                    <Link href={`/fleet-assets/inspections?vehicle_id=${vehicle.id}`}>View History</Link>
                                </Button>
                            </div>
                            {!canInspect && (
                                <p className="text-xs text-muted-foreground">
                                    Starting inspections requires fleet maintenance manager access.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    {/* Daily Check */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm">Daily Check</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-xs text-muted-foreground mb-3">Quick daily vehicle condition check.</p>
                            <Button variant="outline" size="sm" className="h-8 text-xs w-full" asChild>
                                <Link href="/fleet-assets/daily-check">Go to Daily Checks</Link>
                            </Button>
                        </CardContent>
                    </Card>
                    {/* Checklists */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm">Checklists</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-xs text-muted-foreground mb-3">Maintenance checklists and templates.</p>
                            <Button variant="outline" size="sm" className="h-8 text-xs w-full" asChild>
                                <Link href="/fleet-assets/maintenance/checklists">View Templates</Link>
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
                            <CardTitle className="text-sm flex items-center gap-2">
                                <Gauge className="h-4 w-4 text-primary" />
                                Predictive Maintenance
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="rounded-lg border bg-primary/10/50 p-3 dark:bg-primary/20">
                                    <p className="text-[10px] uppercase tracking-wider text-muted-foreground mb-1">Est. Next Service</p>
                                    <p className={`text-lg font-bold ${
                                        service_prediction.predicted_days != null && service_prediction.predicted_days <= 7 ? 'text-red-600' :
                                        service_prediction.predicted_days != null && service_prediction.predicted_days <= 30 ? 'text-amber-600' :
                                        'text-primary'
                                    }`}>
                                        {service_prediction.predicted_days != null
                                            ? `${service_prediction.predicted_days} days`
                                            : 'Insufficient data'}
                                    </p>
                                    <p className="text-[10px] text-muted-foreground">{service_prediction.schedule_name}</p>
                                </div>
                                <div className="rounded-lg border p-3">
                                    <p className="text-[10px] uppercase tracking-wider text-muted-foreground mb-1">Avg Daily km</p>
                                    <p className="text-lg font-bold">{service_prediction.avg_daily_km}</p>
                                    <p className="text-[10px] text-muted-foreground">Last 30 days</p>
                                </div>
                                <div className="rounded-lg border p-3">
                                    <p className="text-[10px] uppercase tracking-wider text-muted-foreground mb-1">Current Odometer</p>
                                    <p className="text-lg font-bold">{service_prediction.current_km.toLocaleString()} km</p>
                                </div>
                                <div className="rounded-lg border p-3">
                                    <p className="text-[10px] uppercase tracking-wider text-muted-foreground mb-1">Next Service At</p>
                                    <p className="text-lg font-bold">{service_prediction.next_service_km.toLocaleString()} km</p>
                                </div>
                            </div>
                            {service_prediction.km_trend.length > 1 && (
                                <div className="flex items-center gap-2 rounded-md border p-2">
                                    <span className="text-[10px] text-muted-foreground shrink-0">km Trend</span>
                                    <SparklineChart data={service_prediction.km_trend} color={FLEET_COLORS.primary} height={28} width={160} />
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
                        <CardHeader className="pb-2 flex flex-row items-center justify-between">
                            <CardTitle className="text-sm">Insurance & Documents</CardTitle>
                            <Button variant="ghost" size="sm" className="h-6 text-[10px]" asChild><Link href={`/fleet-assets/assets/${vehicle.id}`}>View All</Link></Button>
                        </CardHeader>
                        <CardContent className="space-y-1.5 text-sm">
                            <div className="flex justify-between"><span className="text-muted-foreground">Provider</span><span className="font-medium">{(vehicle as Record<string, unknown>).insurance_provider ? String((vehicle as Record<string, unknown>).insurance_provider) : '---'}</span></div>
                            <div className="flex justify-between"><span className="text-muted-foreground">Policy #</span><span className="font-medium font-mono">{(vehicle as Record<string, unknown>).insurance_policy_number ? String((vehicle as Record<string, unknown>).insurance_policy_number) : '---'}</span></div>
                            <div className="flex justify-between"><span className="text-muted-foreground">Expires</span><span className="font-medium">{(vehicle as Record<string, unknown>).insurance_expires_at ? new Date(String((vehicle as Record<string, unknown>).insurance_expires_at)).toLocaleDateString() : '---'}</span></div>
                            <div className="flex justify-between"><span className="text-muted-foreground">Type</span><span className="font-medium capitalize">{(vehicle as Record<string, unknown>).insurance_type ? String((vehicle as Record<string, unknown>).insurance_type).replace(/_/g, ' ') : '---'}</span></div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2 flex flex-row items-center justify-between">
                            <CardTitle className="text-sm">Recent Incidents</CardTitle>
                            <Button variant="ghost" size="sm" className="h-6 text-[10px]" asChild><Link href={`/fleet-assets/incidents/create?asset_id=${vehicle.id}`}>Report</Link></Button>
                        </CardHeader>
                        <CardContent>
                            {(incidents ?? []).length > 0 ? (
                                <div className="space-y-1.5">
                                    {(incidents ?? []).slice(0, 4).map((inc) => (
                                        <Link key={inc.id} href={`/fleet-assets/incidents/${inc.id}`} className="block rounded border p-1.5 text-[10px] hover:bg-muted/50">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-1"><Badge className={`${severityColor(inc.severity)} text-white text-[9px] h-4 px-1`}>{inc.severity}</Badge><span className="font-medium capitalize">{(inc.incident_type ?? '').replace(/_/g, ' ')}</span></div>
                                                <Badge variant="outline" className="text-[9px] h-4">{inc.status}</Badge>
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            ) : <p className="text-xs text-muted-foreground">No incidents recorded.</p>}
                        </CardContent>
                    </Card>
                </div>

                {/* ============================================================ */}
                {/*  SECTION 5 - Accessibility Features                          */}
                {/* ============================================================ */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm">Accessibility Features</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {([
                                { key: 'has_wheelchair_ramp', label: 'Wheelchair Ramp' },
                                { key: 'has_hoist', label: 'Hoist' },
                                { key: 'has_child_seat_anchors', label: 'Child Seat Anchors' },
                                { key: 'has_medical_storage', label: 'Medical Storage' },
                            ] as const).map((item) => (
                                <div
                                    key={item.key}
                                    className={`flex items-center justify-between rounded-xl border-2 p-3 transition-all ${
                                        (vehicle as Record<string, unknown>)[item.key]
                                            ? 'border-primary bg-primary/10/50 dark:border-primary/30 dark:bg-primary/10'
                                            : 'border-muted bg-muted/20'
                                    }`}
                                >
                                    <span className="text-sm font-medium">{item.label}</span>
                                    {canManage ? (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                router.put(`/fleet-assets/vehicles/${vehicle.id}`, {
                                                    [item.key]: !(vehicle as Record<string, unknown>)[item.key],
                                                }, { preserveScroll: true });
                                            }}
                                            className={`h-7 w-12 rounded-full transition-colors ${
                                                (vehicle as Record<string, unknown>)[item.key] ? 'bg-primary' : 'bg-slate-300'
                                            }`}
                                        >
                                            <span
                                                className={`block h-5 w-5 rounded-full bg-white shadow transition-transform ${
                                                    (vehicle as Record<string, unknown>)[item.key] ? 'translate-x-6' : 'translate-x-1'
                                                }`}
                                            />
                                        </button>
                                    ) : (
                                        <Badge variant={(vehicle as Record<string, unknown>)[item.key] ? 'default' : 'secondary'}>
                                            {(vehicle as Record<string, unknown>)[item.key] ? 'Enabled' : 'Not set'}
                                        </Badge>
                                    )}
                                </div>
                            ))}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="text-sm font-medium">Seating Capacity</label>
                                {canManage ? (
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            const input = (e.target as HTMLFormElement).elements.namedItem('seating') as HTMLInputElement;
                                            if (input?.value) {
                                                router.put(`/fleet-assets/vehicles/${vehicle.id}`, {
                                                    seating_capacity: Number(input.value),
                                                }, { preserveScroll: true });
                                            }
                                        }}
                                        className="mt-1 flex gap-2"
                                    >
                                        <Input
                                            type="number"
                                            name="seating"
                                            min="1"
                                            max="50"
                                            defaultValue={vehicle.seating_capacity ?? ''}
                                            placeholder="e.g. 7"
                                            className="h-8 text-xs w-24"
                                        />
                                        <Button type="submit" size="sm" className="h-8 text-xs">Set</Button>
                                    </form>
                                ) : (
                                    <p className="mt-1 text-xs text-muted-foreground">{vehicle.seating_capacity ? `${vehicle.seating_capacity} seats` : 'Not recorded'}</p>
                                )}
                            </div>
                            <div>
                                <label className="text-sm font-medium">Accessibility Notes</label>
                                {canManage ? (
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            const input = (e.target as HTMLFormElement).elements.namedItem('acc_notes') as HTMLTextAreaElement;
                                            router.put(`/fleet-assets/vehicles/${vehicle.id}`, {
                                                accessibility_notes: input?.value ?? '',
                                            }, { preserveScroll: true });
                                        }}
                                        className="mt-1 space-y-2"
                                    >
                                        <textarea
                                            name="acc_notes"
                                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-xs ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            rows={2}
                                            defaultValue={vehicle.accessibility_notes ?? ''}
                                            placeholder="Additional accessibility details..."
                                        />
                                        <Button type="submit" size="sm" className="h-8 text-xs">Save Notes</Button>
                                    </form>
                                ) : (
                                    <p className="mt-1 text-xs text-muted-foreground">{vehicle.accessibility_notes || 'No accessibility notes recorded.'}</p>
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

                {canManage && (
                    <ConfirmDialog
                        open={showRemoveDriverDialog}
                        onClose={() => setShowRemoveDriverDialog(false)}
                        onConfirm={() => {
                            router.put(`/fleet-assets/vehicles/${vehicle.id}`, {
                                primary_driver_user_id: null,
                            }, { preserveScroll: true });
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
