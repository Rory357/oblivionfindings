import LeafletMap, { MapMarker } from '@/components/leaflet-map';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    Car,
    Check,
    CheckCircle,
    Clock,
    MapPin,
    Pill,
    Play,
    Square,
    User,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { formatDate, formatDateTime, formatRelativeTime } from '@/lib/fleet-utils';
import { ConfirmDialog } from '@/components/confirm-dialog';

type Resident = {
    id: number;
    client_id: number;
    client_name: string;
    transport_needs?: Record<string, boolean> | null;
    pre_check_completed: boolean;
    medication_packed: boolean;
    notes: string | null;
};

type OutingData = {
    id: number;
    title: string;
    destination: string;
    purpose: string | null;
    planned_departure: string | null;
    planned_return: string | null;
    actual_departure: string | null;
    actual_return: string | null;
    asset: { id: number; name: string; asset_tag?: string } | null;
    driver: { id: number; name: string; email?: string } | null;
    booking: { id: number; purpose: string; status: string } | null;
    created_by: { id: number; name: string } | null;
    risk_assessment: { notes?: string } | null;
    status: string;
    notes: string | null;
    residents: Resident[];
    created_at: string | null;
};

type Props = {
    outing: OutingData;
    vehicle_state: {
        lat: number;
        lng: number;
        speed_kph: number;
        last_seen_at: string | null;
    } | null;
};

const STATUS_CONFIG: Record<string, { color: string; bgColor: string; label: string }> = {
    planned: { color: 'text-blue-700 dark:text-blue-400', bgColor: 'bg-blue-50 border-blue-200 dark:bg-blue-950/30 dark:border-blue-800', label: 'Planned' },
    active: { color: 'text-purple-700 dark:text-purple-400', bgColor: 'bg-purple-50 border-purple-200 dark:bg-purple-950/30 dark:border-purple-800', label: 'Active' },
    completed: { color: 'text-slate-700 dark:text-slate-400', bgColor: 'bg-slate-50 border-slate-200 dark:bg-slate-950/30 dark:border-slate-800', label: 'Completed' },
    cancelled: { color: 'text-red-700 dark:text-red-400', bgColor: 'bg-red-50 border-red-200 dark:bg-red-950/30 dark:border-red-800', label: 'Cancelled' },
};

const PURPOSE_LABELS: Record<string, string> = {
    community: 'Community Access',
    medical: 'Medical',
    social: 'Social',
    recreational: 'Recreational',
    shopping: 'Shopping',
};

export default function OutingShow({ outing, vehicle_state }: Props) {
    const safeOuting = outing ?? ({} as OutingData);
    const safeResidents = safeOuting.residents ?? [];
    const statusConfig = STATUS_CONFIG[safeOuting.status] ?? STATUS_CONFIG.planned;

    const [showCancelDialog, setShowCancelDialog] = useState(false);

    // Auto-refresh when active
    useEffect(() => {
        if (safeOuting.status !== 'active') return;
        const interval = window.setInterval(() => {
            if (document.hidden) return;
            router.reload({ only: ['vehicle_state', 'outing'] });
        }, 30000);
        return () => window.clearInterval(interval);
    }, [safeOuting.status]);

    const markers = useMemo<MapMarker[]>(() => {
        if (!vehicle_state?.lat || !vehicle_state?.lng) return [];
        return [{
            id: `outing-v-${safeOuting.asset?.id ?? 0}`,
            lat: Number(vehicle_state.lat),
            lng: Number(vehicle_state.lng),
            title: safeOuting.asset?.name ?? 'Vehicle',
            type: 'vehicle',
            status: 'online',
            popup: `Speed: ${vehicle_state.speed_kph ?? 0} kph`,
        }];
    }, [vehicle_state, safeOuting.asset]);

    const mapCenter = useMemo(() => {
        if (vehicle_state?.lat && vehicle_state?.lng) {
            return { lat: Number(vehicle_state.lat), lng: Number(vehicle_state.lng) };
        }
        return { lat: -36.8485, lng: 174.7633 };
    }, [vehicle_state]);

    // Timeline steps
    const timelineSteps = useMemo(() => {
        const steps = [
            {
                label: 'Planned',
                time: safeOuting.planned_departure,
                completed: true,
                icon: Calendar,
            },
            {
                label: 'Departed',
                time: safeOuting.actual_departure,
                completed: !!safeOuting.actual_departure,
                icon: Play,
            },
            {
                label: 'Arrived / Completed',
                time: safeOuting.actual_return,
                completed: !!safeOuting.actual_return,
                icon: CheckCircle,
            },
        ];
        return steps;
    }, [safeOuting]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Outings', href: '/fleet-assets/outings' },
                { title: safeOuting.title ?? 'Outing', href: '#' },
            ]}
        >
            <Head title={`Outing: ${safeOuting.title ?? ''}`} />
            <PageShell>
                <PageHeader
                    title={safeOuting.title ?? 'Outing Details'}
                    backHref="/fleet-assets/outings"
                    backLabel="Back to Outings"
                    actions={
                        <div className="flex gap-2">
                            {safeOuting.status === 'planned' && (
                                <>
                                    <Button
                                        size="sm"
                                        className="bg-purple-600 hover:bg-purple-700"
                                        onClick={() => router.post(`/fleet-assets/outings/${safeOuting.id}/start`)}
                                    >
                                        <Play className="mr-2 h-4 w-4" />
                                        Start Outing
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setShowCancelDialog(true)}
                                    >
                                        <X className="mr-2 h-4 w-4" />
                                        Cancel
                                    </Button>
                                </>
                            )}
                            {safeOuting.status === 'active' && (
                                <Button
                                    size="sm"
                                    onClick={() => router.post(`/fleet-assets/outings/${safeOuting.id}/complete`)}
                                >
                                    <Square className="mr-2 h-4 w-4" />
                                    Complete Outing
                                </Button>
                            )}
                        </div>
                    }
                />

                {/* Status Banner */}
                <div className={`rounded-lg border px-4 py-3 ${statusConfig.bgColor}`}>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Badge className={`${statusConfig.color} bg-transparent border`}>
                                {statusConfig.label}
                            </Badge>
                            {safeOuting.purpose && (
                                <Badge variant="outline" className="text-xs">
                                    {PURPOSE_LABELS[safeOuting.purpose] ?? safeOuting.purpose}
                                </Badge>
                            )}
                        </div>
                        <span className="text-xs text-muted-foreground">
                            Created {formatRelativeTime(safeOuting.created_at)}
                            {safeOuting.created_by && ` by ${safeOuting.created_by.name}`}
                        </span>
                    </div>
                </div>

                {/* Main content: 2-column layout */}
                <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                    {/* Left column: details + residents */}
                    <div className="space-y-4">
                        {/* Details */}
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">Outing Details</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Destination</span>
                                    <span className="font-medium flex items-center gap-1">
                                        <MapPin className="h-3.5 w-3.5 text-muted-foreground" />
                                        {safeOuting.destination}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Planned Departure</span>
                                    <span className="font-medium">{formatDateTime(safeOuting.planned_departure)}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Planned Return</span>
                                    <span className="font-medium">{formatDateTime(safeOuting.planned_return)}</span>
                                </div>
                                {safeOuting.actual_departure && (
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Actual Departure</span>
                                        <span className="font-medium">{formatDateTime(safeOuting.actual_departure)}</span>
                                    </div>
                                )}
                                {safeOuting.actual_return && (
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Actual Return</span>
                                        <span className="font-medium">{formatDateTime(safeOuting.actual_return)}</span>
                                    </div>
                                )}
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Vehicle</span>
                                    <span className="font-medium flex items-center gap-1">
                                        {safeOuting.asset ? (
                                            <>
                                                <Car className="h-3.5 w-3.5 text-muted-foreground" />
                                                <Link href={`/fleet-assets/vehicles/${safeOuting.asset.id}`} className="text-purple-600 hover:underline dark:text-purple-400">
                                                    {safeOuting.asset.name}
                                                </Link>
                                            </>
                                        ) : '---'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Driver</span>
                                    <span className="font-medium flex items-center gap-1">
                                        {safeOuting.driver ? (
                                            <>
                                                <User className="h-3.5 w-3.5 text-muted-foreground" />
                                                {safeOuting.driver.name}
                                            </>
                                        ) : '---'}
                                    </span>
                                </div>
                                {safeOuting.booking && (
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Linked Booking</span>
                                        <Link href={`/fleet-assets/bookings/${safeOuting.booking.id}`} className="text-purple-600 hover:underline dark:text-purple-400 text-xs">
                                            Booking #{safeOuting.booking.id}
                                        </Link>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Resident Manifest */}
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm flex items-center gap-2">
                                    <Users className="h-4 w-4" />
                                    Resident Manifest ({safeResidents.length})
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {safeResidents.length > 0 ? (
                                    <div className="space-y-2">
                                        {safeResidents.map((resident) => (
                                            <div key={resident.id} className="flex items-center justify-between rounded-lg border p-3">
                                                <div>
                                                    <p className="text-sm font-medium">{resident.client_name}</p>
                                                    {resident.transport_needs && Object.values(resident.transport_needs).some(Boolean) && (
                                                        <div className="mt-1 flex flex-wrap gap-1">
                                                            {resident.transport_needs.wheelchair_ramp && <Badge variant="outline" className="text-[9px] h-4">Wheelchair</Badge>}
                                                            {resident.transport_needs.hoist && <Badge variant="outline" className="text-[9px] h-4">Hoist</Badge>}
                                                            {resident.transport_needs.child_seat && <Badge variant="outline" className="text-[9px] h-4">Child Seat</Badge>}
                                                            {resident.transport_needs.medical_storage && <Badge variant="outline" className="text-[9px] h-4">Medical</Badge>}
                                                        </div>
                                                    )}
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <div className="flex items-center gap-1.5">
                                                        <div className={`flex h-5 w-5 items-center justify-center rounded-full ${resident.pre_check_completed ? 'bg-green-100 text-green-600' : 'bg-muted'}`}>
                                                            {resident.pre_check_completed && <Check className="h-3 w-3" />}
                                                        </div>
                                                        <span className="text-[10px] text-muted-foreground">Pre-check</span>
                                                    </div>
                                                    <div className="flex items-center gap-1.5">
                                                        <div className={`flex h-5 w-5 items-center justify-center rounded-full ${resident.medication_packed ? 'bg-green-100 text-green-600' : 'bg-muted'}`}>
                                                            {resident.medication_packed && <Pill className="h-3 w-3" />}
                                                        </div>
                                                        <span className="text-[10px] text-muted-foreground">Meds</span>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-xs text-muted-foreground text-center py-4">No residents assigned to this outing.</p>
                                )}
                            </CardContent>
                        </Card>

                        {/* Risk Assessment & Notes */}
                        {(safeOuting.risk_assessment?.notes || safeOuting.notes) && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">Risk Assessment & Notes</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {safeOuting.risk_assessment?.notes && (
                                        <div>
                                            <p className="text-[10px] uppercase tracking-wider text-muted-foreground mb-1">Risk Assessment</p>
                                            <p className="text-sm whitespace-pre-line">{safeOuting.risk_assessment.notes}</p>
                                        </div>
                                    )}
                                    {safeOuting.notes && (
                                        <div>
                                            <p className="text-[10px] uppercase tracking-wider text-muted-foreground mb-1">Notes</p>
                                            <p className="text-sm whitespace-pre-line">{safeOuting.notes}</p>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Right column: map + timeline */}
                    <div className="space-y-4">
                        {/* Live Map (only when active) */}
                        {safeOuting.status === 'active' && vehicle_state && (
                            <Card className="overflow-hidden">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm flex items-center gap-2">
                                        <div className="h-2 w-2 rounded-full bg-green-500 animate-pulse" />
                                        Live Tracking
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="p-0">
                                    <LeafletMap center={mapCenter} zoom={14} markers={markers} height={300} />
                                </CardContent>
                            </Card>
                        )}

                        {/* Timeline */}
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">Timeline</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-4">
                                    {timelineSteps.map((step, i) => {
                                        const IconComp = step.icon;
                                        return (
                                            <div key={i} className="flex items-start gap-3">
                                                <div className="flex flex-col items-center">
                                                    <div className={`flex h-8 w-8 items-center justify-center rounded-full ${
                                                        step.completed
                                                            ? 'bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400'
                                                            : 'bg-muted text-muted-foreground'
                                                    }`}>
                                                        <IconComp className="h-4 w-4" />
                                                    </div>
                                                    {i < timelineSteps.length - 1 && (
                                                        <div className={`mt-1 h-8 w-0.5 ${step.completed ? 'bg-purple-300 dark:bg-purple-700' : 'bg-muted'}`} />
                                                    )}
                                                </div>
                                                <div className="pt-1">
                                                    <p className={`text-sm font-medium ${step.completed ? '' : 'text-muted-foreground'}`}>
                                                        {step.label}
                                                    </p>
                                                    {step.time && (
                                                        <p className="text-xs text-muted-foreground">{formatDateTime(step.time)}</p>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Quick Stats */}
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">Quick Info</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Total Residents</span>
                                    <span className="font-medium">{safeResidents.length}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Pre-checks Done</span>
                                    <span className="font-medium">
                                        {safeResidents.filter((r) => r.pre_check_completed).length} / {safeResidents.length}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Meds Packed</span>
                                    <span className="font-medium">
                                        {safeResidents.filter((r) => r.medication_packed).length} / {safeResidents.length}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <ConfirmDialog
                    open={showCancelDialog}
                    onClose={() => setShowCancelDialog(false)}
                    onConfirm={() => {
                        router.post(`/fleet-assets/outings/${safeOuting.id}/cancel`);
                    }}
                    title="Cancel Outing"
                    description={`Are you sure you want to cancel "${safeOuting.title}"? This will also cancel the linked vehicle booking if one exists.`}
                    confirmText="Cancel Outing"
                />
            </PageShell>
        </AppLayout>
    );
}
