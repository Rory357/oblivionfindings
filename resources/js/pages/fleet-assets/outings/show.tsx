import LeafletMap, { MapMarker } from '@/components/leaflet-map';
import { PageHero } from '@/components/page';
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
    UserCheck,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { formatDate, formatDateTime, formatRelativeTime } from '@/lib/fleet-utils';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';

type Resident = {
    id: number;
    client_id: number;
    client_name: string;
    transport_needs?: Record<string, boolean> | null;
    pre_check_completed: boolean;
    medication_packed: boolean;
    returned_at: string | null;
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
    can: {
        manage: boolean;
    };
};

const STATUS_CONFIG: Record<string, { color: string; bgColor: string; label: string }> = {
    planned: { color: 'text-status-info dark:text-status-info', bgColor: 'bg-status-info-bg border-status-info/30 dark:border-status-info/30', label: 'Planned' },
    active: { color: 'text-primary dark:text-primary', bgColor: 'bg-primary/10 border-primary dark:bg-primary/30 dark:border-primary/30', label: 'Active' },
    completed: { color: 'text-foreground dark:text-muted-foreground', bgColor: 'bg-muted border-border dark:bg-muted/30 dark:border-border', label: 'Completed' },
    cancelled: { color: 'text-status-critical dark:text-status-critical', bgColor: 'bg-status-critical-bg border-status-critical/30 dark:border-status-critical/30', label: 'Cancelled' },
};

const PURPOSE_LABELS: Record<string, string> = {
    community: 'Community Access',
    medical: 'Medical',
    social: 'Social',
    recreational: 'Recreational',
    shopping: 'Shopping',
};

export default function OutingShow({ outing, vehicle_state, can }: Props) {
    const safeOuting = useMemo(() => outing ?? ({} as OutingData), [outing]);
    const safeResidents = safeOuting.residents ?? [];
    const canManage = can?.manage ?? false;
    const statusConfig = STATUS_CONFIG[safeOuting.status] ?? STATUS_CONFIG.planned;

    const [showCancelDialog, setShowCancelDialog] = useState(false);
    const [showReturnAllDialog, setShowReturnAllDialog] = useState(false);

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
                <PageHero
                    title={safeOuting.title ?? 'Outing Details'}
                    backHref="/fleet-assets/outings"
                    backLabel="Back to Outings"
                    actions={
                        canManage ? (
                        <div className="flex gap-2">
                            {safeOuting.status === 'planned' && (() => {
                                const allPreChecked = safeResidents.length === 0 || safeResidents.every((r) => r.pre_check_completed);
                                return (
                                <>
                                    <Button
                                        size="sm"
                                        className="bg-primary hover:bg-primary"
                                        onClick={() => router.post(`/fleet-assets/outings/${safeOuting.id}/start`)}
                                        disabled={!allPreChecked}
                                        title={!allPreChecked ? 'All residents must complete their pre-departure check first' : undefined}
                                    >
                                        <Play className="mr-2 h-4 w-4" />
                                        {!allPreChecked ? 'Pre-checks Incomplete' : 'Start Outing'}
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
                                );
                            })()}
                            {safeOuting.status === 'active' && (() => {
                                const returnedCount = safeResidents.filter((r) => r.returned_at).length;
                                const totalCount = safeResidents.length;
                                const allReturned = totalCount === 0 || returnedCount === totalCount;
                                const unreturned = safeResidents.filter((r) => !r.returned_at);
                                return (
                                    <>
                                        {unreturned.length > 1 && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setShowReturnAllDialog(true)}
                                            >
                                                <UserCheck className="mr-2 h-4 w-4" />
                                                Return All ({unreturned.length})
                                            </Button>
                                        )}
                                        <Button
                                            size="sm"
                                            onClick={() => router.post(`/fleet-assets/outings/${safeOuting.id}/complete`)}
                                            disabled={!allReturned}
                                            title={!allReturned ? `All residents must be marked as returned first (${returnedCount} of ${totalCount} returned)` : undefined}
                                        >
                                            <Square className="mr-2 h-4 w-4" />
                                            {!allReturned ? `Complete (${returnedCount}/${totalCount} returned)` : 'Complete Outing'}
                                        </Button>
                                    </>
                                );
                            })()}
                        </div>
                        ) : undefined
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

                {!canManage && ['planned', 'active'].includes(safeOuting.status) && (
                    <p className="text-sm text-muted-foreground">
                        Outing updates are view-only for your account.
                    </p>
                )}

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
                                                <Link href={`/fleet-assets/vehicles/${safeOuting.asset.id}`} className="text-primary hover:underline dark:text-primary">
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
                                        <Link href={`/fleet-assets/bookings/${safeOuting.booking.id}`} className="text-primary hover:underline dark:text-primary text-xs">
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
                                                        <div className={`flex h-5 w-5 items-center justify-center rounded-full ${resident.pre_check_completed ? 'bg-status-success-bg text-status-success' : 'bg-muted'}`}>
                                                            {resident.pre_check_completed && <Check className="h-3 w-3" />}
                                                        </div>
                                                        <span className="text-[10px] text-muted-foreground">Pre-check</span>
                                                    </div>
                                                    <div className="flex items-center gap-1.5">
                                                        <div className={`flex h-5 w-5 items-center justify-center rounded-full ${resident.medication_packed ? 'bg-status-success-bg text-status-success' : 'bg-muted'}`}>
                                                            {resident.medication_packed && <Pill className="h-3 w-3" />}
                                                        </div>
                                                        <span className="text-[10px] text-muted-foreground">Meds</span>
                                                    </div>
                                                    {safeOuting.status === 'active' && (
                                                        resident.returned_at ? (
                                                            <Badge className="bg-status-success-bg text-status-success border-status-success/30 dark:bg-status-success-bg dark:text-status-success dark:border-status-success/30 text-[10px] h-5">
                                                                <UserCheck className="mr-1 h-3 w-3" />
                                                                Returned
                                                            </Badge>
                                                        ) : (
                                                            canManage ? (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    className="h-6 text-[10px] px-2"
                                                                    onClick={() => router.post(`/fleet-assets/outings/${safeOuting.id}/residents/${resident.id}/return`)}
                                                                >
                                                                    <UserCheck className="mr-1 h-3 w-3" />
                                                                    Mark Returned
                                                                </Button>
                                                            ) : null
                                                        )
                                                    )}
                                                    {safeOuting.status === 'completed' && resident.returned_at && (
                                                        <Badge className="bg-status-success-bg text-status-success border-status-success/30 dark:bg-status-success-bg dark:text-status-success dark:border-status-success/30 text-[10px] h-5">
                                                            <UserCheck className="mr-1 h-3 w-3" />
                                                            Returned
                                                        </Badge>
                                                    )}
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
                                        <div className="h-2 w-2 rounded-full bg-status-success animate-pulse" />
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
                                                            ? 'bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary'
                                                            : 'bg-muted text-muted-foreground'
                                                    }`}>
                                                        <IconComp className="h-4 w-4" />
                                                    </div>
                                                    {i < timelineSteps.length - 1 && (
                                                        <div className={`mt-1 h-8 w-0.5 ${step.completed ? 'bg-primary/20 dark:bg-primary' : 'bg-muted'}`} />
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
                                {(safeOuting.status === 'active' || safeOuting.status === 'completed') && (
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Returned</span>
                                        <span className="font-medium">
                                            {safeResidents.filter((r) => r.returned_at).length} / {safeResidents.length}
                                        </span>
                                    </div>
                                )}
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

                {/* Return All Residents Dialog */}
                <AlertDialog open={showReturnAllDialog} onOpenChange={(open) => { if (!open) setShowReturnAllDialog(false); }}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Return All Residents</AlertDialogTitle>
                            <AlertDialogDescription>
                                Mark all unreturned residents as returned now?
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <div className="space-y-1.5 py-2">
                            {safeResidents.filter((r) => !r.returned_at).map((r) => (
                                <div key={r.id} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                                    <User className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                                    <span className="font-medium">{r.client_name}</span>
                                </div>
                            ))}
                        </div>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() => {
                                    router.post(`/fleet-assets/outings/${safeOuting.id}/residents/return-all`);
                                    setShowReturnAllDialog(false);
                                }}
                                className="bg-status-success hover:bg-status-success"
                            >
                                <UserCheck className="mr-2 h-4 w-4" />
                                Return All ({safeResidents.filter((r) => !r.returned_at).length})
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </PageShell>
        </AppLayout>
    );
}
