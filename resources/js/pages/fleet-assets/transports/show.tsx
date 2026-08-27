import LeafletMap, { type MapMarker } from '@/components/leaflet-map';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { toDatetimeLocal } from '@/lib/datetime';
import { formatDateTime, formatDuration } from '@/lib/fleet-utils';
import { cn } from '@/lib/utils';
import { FleetCompactHero } from '@/pages/fleet-assets/components/fleet-compact-hero';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeftRight,
    Calendar,
    Car,
    CheckCircle,
    ClipboardCheck,
    Clock,
    Loader2,
    MapPin,
    Package,
    Pill,
    ShieldCheck,
    User,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    AdministerTransportMedicationWizard,
    CorrectPackingAttestationWizard,
    PackMedicationWizard,
    ReturnTransportMedicationWizard,
    type TransportMedicationLog as TransitLog,
    type TransportMedicationOption as TransitMedicationOption,
} from './components/transport-medication-dialogs';

type CareNeed = {
    id: number;
    label: string;
    notes: string | null;
};

type MedicationContext = {
    client: { id: number; name: string } | null;
    available_medications: TransitMedicationOption[];
    transit_logs: TransitLog[];
    packing_attestation_history: Array<{
        id: number;
        state: string;
        medication_name: string;
        actor_name: string | null;
        witness_name: string | null;
        occurred_at: string | null;
        reason: string | null;
        supersedes_event_id: number | null;
    }>;
    witnesses: Array<{ id: number; name: string }>;
    can_manage: boolean;
    can_administer: boolean;
    can_record_controlled: boolean;
};

type Props = {
    transport: {
        id: number;
        resident_id: number | null;
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
    medication_context?: MedicationContext | null;
};

const TRANSPORT_TYPE_BANNER: Record<string, string> = {
    medical:
        'bg-status-critical-bg border-status-critical/30 text-status-critical dark:bg-status-critical-bg dark:border-status-critical/30 dark:text-status-critical',
    appointment:
        'bg-status-info-bg border-status-info/30 text-status-info dark:bg-status-info-bg dark:border-status-info/30 dark:text-status-info',
    social: 'bg-status-success-bg border-status-success/30 text-status-success dark:bg-status-success-bg dark:border-status-success/30 dark:text-status-success',
    shopping:
        'bg-primary/10 border-primary text-primary dark:bg-primary/30 dark:border-primary/30 dark:text-primary/70',
    community:
        'bg-status-info-bg border-status-info/30 text-status-info dark:bg-status-info-bg dark:border-status-info/30 dark:text-status-info',
    respite:
        'bg-status-warning-bg border-status-warning/30 text-status-warning dark:bg-status-warning-bg dark:border-status-warning/30 dark:text-status-warning',
    other: 'bg-muted border-border text-foreground dark:bg-muted/30 dark:border-border dark:text-foreground',
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

function transitStatusBadge(status: string) {
    switch (status) {
        case 'packed':
            return (
                <Badge className="bg-status-warning text-white">Packed</Badge>
            );
        case 'administered':
            return (
                <Badge className="bg-status-info text-white">
                    Administered
                </Badge>
            );
        case 'returned':
            return (
                <Badge className="bg-status-success text-white">Returned</Badge>
            );
        default:
            return <Badge variant="secondary">{status}</Badge>;
    }
}

function packingAttestationLabel(state: string): string {
    switch (state) {
        case 'accepted':
            return 'Accepted';
        case 'refused':
            return 'Declined';
        case 'unavailable':
            return 'Unavailable';
        case 'corrected':
            return 'Corrected';
        default:
            return state.replace(/_/g, ' ');
    }
}

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
    medication_context,
}: Props) {
    const t = transport ?? ({} as Props['transport']);
    const medicationContext = useMemo<MedicationContext>(
        () =>
            medication_context ?? {
                client: null,
                available_medications: [],
                transit_logs: [],
                packing_attestation_history: [],
                witnesses: [],
                can_manage: false,
                can_administer: false,
                can_record_controlled: false,
            },
        [medication_context],
    );
    const safeMedicationOptions = useMemo(
        () => medicationContext.available_medications ?? [],
        [medicationContext.available_medications],
    );
    const safeTransitLogs = useMemo(
        () => medicationContext.transit_logs ?? [],
        [medicationContext.transit_logs],
    );
    const safePackingAttestationHistory =
        medicationContext.packing_attestation_history ?? [];
    const safeWitnesses = medicationContext.witnesses ?? [];
    const canManageMedicationTransit = !!medicationContext.can_manage;
    const canAdministerMedicationTransit = !!medicationContext.can_administer;
    const canRecordControlledMedication =
        !!medicationContext.can_record_controlled;
    const refreshTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const packDialogTriggerRef = useRef<HTMLButtonElement>(null);

    const [packDialogOpen, setPackDialogOpen] = useState(false);
    const [administeringLog, setAdministeringLog] = useState<TransitLog | null>(
        null,
    );
    const [returningLog, setReturningLog] = useState<TransitLog | null>(null);
    const [correctingPackingLog, setCorrectingPackingLog] =
        useState<TransitLog | null>(null);

    const completeForm = useForm({
        arrived_at: toDatetimeLocal(new Date()),
        notes: '',
        client_request_uuid: crypto.randomUUID(),
    });

    useEffect(() => {
        if (t.status === 'in_progress') {
            refreshTimerRef.current = setInterval(() => {
                router.reload({
                    only: [
                        'vehicle_position',
                        'medication_context',
                        'completion_blockers',
                    ],
                });
            }, 15000);
        }
        return () => {
            if (refreshTimerRef.current) clearInterval(refreshTimerRef.current);
        };
    }, [t.status]);

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

    const unresolvedMedicationCount = useMemo(
        () => safeTransitLogs.filter((log) => log.status !== 'returned').length,
        [safeTransitLogs],
    );

    const refreshMedicationContext = () => {
        router.reload({
            only: ['medication_context', 'completion_blockers'],
        });
    };

    const closePackDialog = () => {
        setPackDialogOpen(false);
        window.requestAnimationFrame(() =>
            packDialogTriggerRef.current?.focus(),
        );
    };

    const openPackDialog = () => {
        setPackDialogOpen(true);
    };

    const closeAdministerDialog = () => {
        setAdministeringLog(null);
    };

    const closeReturnDialog = () => {
        setReturningLog(null);
    };

    const closePackingCorrectionDialog = () => {
        setCorrectingPackingLog(null);
    };

    const openAdministerDialog = (log: TransitLog) => {
        setAdministeringLog(log);
    };

    const openReturnDialog = (log: TransitLog) => {
        setReturningLog(log);
    };

    const openPackingCorrectionDialog = (log: TransitLog) => {
        setCorrectingPackingLog(log);
    };

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
                <FleetCompactHero
                    pill={`Resident transports · ${(t.status ?? 'record').replace(/_/g, ' ')}`}
                    title={`Transport #${t.id ?? ''}`}
                    backHref="/fleet-assets/transports"
                    backLabel="Transport Logs"
                    actions={
                        t.status === 'in_progress' ? (
                            <>
                                <Link
                                    href={`/fleet-assets/transports/medications?transport_id=${t.id}`}
                                    className="inline-flex h-[30px] items-center gap-1.5 rounded-lg border border-primary-foreground/25 bg-primary-foreground/10 px-2.5 text-[12px] font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                                >
                                    <Pill className="h-3.5 w-3.5" />
                                    Medication Transit
                                </Link>
                                <Link
                                    href={`/fleet-assets/transports/${t.id}/pre-check`}
                                    className="inline-flex h-[30px] items-center gap-1.5 rounded-lg border border-primary-foreground/25 bg-primary-foreground/10 px-2.5 text-[12px] font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                                >
                                    <ClipboardCheck className="h-3.5 w-3.5" />
                                    Pre-Transport Check
                                </Link>
                            </>
                        ) : undefined
                    }
                />

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
                                            ? 'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success'
                                            : 'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
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

                {t.status === 'in_progress' &&
                    vehicle_position?.lat &&
                    vehicle_position?.lng && (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <MapPin className="h-4 w-4" />
                                    Live Vehicle Position
                                    <span className="ml-auto flex items-center gap-1.5 text-xs font-normal text-muted-foreground">
                                        <span className="h-2 w-2 animate-pulse rounded-full bg-status-success" />
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

                {(care_needs ?? []).length > 0 && (
                    <Card className="border bg-primary/10 dark:bg-primary/30">
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

                <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    <div className="space-y-4">
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
                                        <div className="rounded-md bg-status-info-bg p-3">
                                            <dt className="text-xs text-status-info dark:text-status-info">
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

                        {(medicationContext.client ||
                            safeTransitLogs.length > 0 ||
                            canManageMedicationTransit) && (
                            <Card>
                                <CardHeader className="gap-3">
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <CardTitle className="flex items-center gap-2 text-base">
                                                <Pill className="h-4 w-4" />
                                                Medication Transit
                                            </CardTitle>
                                            <p className="text-sm text-muted-foreground">
                                                Track medications packed,
                                                administered, and returned for
                                                this trip.
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="outline"
                                            >
                                                <Link
                                                    href={`/fleet-assets/transports/medications?transport_id=${t.id}`}
                                                >
                                                    Transit Board
                                                </Link>
                                            </Button>
                                            {canManageMedicationTransit &&
                                                t.status === 'in_progress' &&
                                                medicationContext.client &&
                                                safeMedicationOptions.length >
                                                    0 && (
                                                    <Button
                                                        ref={
                                                            packDialogTriggerRef
                                                        }
                                                        size="sm"
                                                        onClick={openPackDialog}
                                                    >
                                                        <Package className="mr-2 h-4 w-4" />
                                                        Pack Medication
                                                    </Button>
                                                )}
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {medicationContext.client ? (
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <div className="rounded-md bg-muted/40 p-3">
                                                <div className="text-xs text-muted-foreground">
                                                    Resident
                                                </div>
                                                <div className="mt-1 text-sm font-medium">
                                                    {
                                                        medicationContext.client
                                                            .name
                                                    }
                                                </div>
                                            </div>
                                            <div className="rounded-md bg-muted/40 p-3">
                                                <div className="text-xs text-muted-foreground">
                                                    Packable Medications
                                                </div>
                                                <div className="mt-1 text-sm font-medium">
                                                    {
                                                        safeMedicationOptions.length
                                                    }
                                                </div>
                                            </div>
                                            <div className="rounded-md bg-muted/40 p-3">
                                                <div className="text-xs text-muted-foreground">
                                                    Open Medication Actions
                                                </div>
                                                <div className="mt-1 text-sm font-medium">
                                                    {unresolvedMedicationCount}
                                                </div>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                                            This transport is not linked to a
                                            resident record, so medication
                                            packing is not available on this
                                            page.
                                        </div>
                                    )}

                                    {safePackingAttestationHistory.length >
                                    0 ? (
                                        <div className="space-y-2 rounded-lg border p-4">
                                            <div className="flex items-center gap-2 text-sm font-semibold">
                                                <ShieldCheck className="h-4 w-4" />
                                                Packing second-checker history
                                            </div>
                                            <div className="space-y-2">
                                                {safePackingAttestationHistory.map(
                                                    (entry) => (
                                                        <div
                                                            key={entry.id}
                                                            className="rounded-md bg-muted/40 px-3 py-2 text-xs"
                                                        >
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <span className="font-medium">
                                                                    {
                                                                        entry.medication_name
                                                                    }
                                                                </span>
                                                                <Badge
                                                                    variant="outline"
                                                                    className="text-[10px]"
                                                                >
                                                                    {packingAttestationLabel(
                                                                        entry.state,
                                                                    )}
                                                                </Badge>
                                                                <span className="text-muted-foreground">
                                                                    {entry.occurred_at
                                                                        ? formatDateTime(
                                                                              entry.occurred_at,
                                                                          )
                                                                        : 'Time unavailable'}
                                                                </span>
                                                            </div>
                                                            <div className="mt-1 text-muted-foreground">
                                                                {entry.witness_name
                                                                    ? `Second checker: ${entry.witness_name}`
                                                                    : 'No second checker was available'}
                                                                {entry.actor_name
                                                                    ? ` · Recorded by ${entry.actor_name}`
                                                                    : ''}
                                                            </div>
                                                            {entry.reason ? (
                                                                <div className="mt-1 text-muted-foreground">
                                                                    {
                                                                        entry.reason
                                                                    }
                                                                </div>
                                                            ) : null}
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        </div>
                                    ) : null}

                                    {safeTransitLogs.length === 0 ? (
                                        <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                                            No medications are currently logged
                                            against this transport.
                                        </div>
                                    ) : (
                                        <div className="space-y-3">
                                            {safeTransitLogs.map((log) => (
                                                <div
                                                    key={log.id}
                                                    className="rounded-lg border p-4"
                                                >
                                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                        <div className="space-y-3">
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <span className="text-sm font-semibold">
                                                                    {
                                                                        log.medication_name
                                                                    }
                                                                </span>
                                                                {transitStatusBadge(
                                                                    log.status,
                                                                )}
                                                                {log.is_controlled_drug && (
                                                                    <Badge
                                                                        variant="destructive"
                                                                        className="text-[10px]"
                                                                    >
                                                                        Controlled
                                                                        drug
                                                                    </Badge>
                                                                )}
                                                                {log.scan_verification && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="text-[10px]"
                                                                    >
                                                                        Scan
                                                                        required
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {log.client
                                                                    ?.name ??
                                                                    medicationContext
                                                                        .client
                                                                        ?.name ??
                                                                    t.resident_name}
                                                            </div>
                                                            <div className="grid gap-2 text-xs text-muted-foreground sm:grid-cols-2">
                                                                <div>
                                                                    Packed:{' '}
                                                                    {log.packed_at
                                                                        ? `${formatDateTime(log.packed_at)} by ${log.packed_by?.name ?? '---'}`
                                                                        : '---'}
                                                                </div>
                                                                <div>
                                                                    Administration:{' '}
                                                                    {log.administered_at
                                                                        ? `${formatDateTime(log.administered_at)} by ${log.administered_by?.name ?? '---'}`
                                                                        : 'Pending'}
                                                                </div>
                                                                <div>
                                                                    Packing
                                                                    second
                                                                    checker:{' '}
                                                                    {log
                                                                        .packed_witness
                                                                        ?.name ??
                                                                        (log.packed_witness_name
                                                                            ? `${log.packed_witness_name} (legacy label only)`
                                                                            : 'Correction required')}
                                                                </div>
                                                                <div>
                                                                    Returned:{' '}
                                                                    {log.returned_to_house_at
                                                                        ? formatDateTime(
                                                                              log.returned_to_house_at,
                                                                          )
                                                                        : 'Pending'}
                                                                </div>
                                                            </div>
                                                            {log.notes && (
                                                                <div className="rounded-md bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                                                                    {log.notes}
                                                                </div>
                                                            )}
                                                        </div>
                                                        {(canManageMedicationTransit ||
                                                            canAdministerMedicationTransit) &&
                                                            t.status ===
                                                                'in_progress' && (
                                                                <div className="flex flex-wrap gap-2">
                                                                    {canAdministerMedicationTransit &&
                                                                        (!log.is_controlled_drug ||
                                                                            canRecordControlledMedication) &&
                                                                        log.status ===
                                                                            'packed' && (
                                                                            <Button
                                                                                size="sm"
                                                                                variant="outline"
                                                                                onClick={() =>
                                                                                    openAdministerDialog(
                                                                                        log,
                                                                                    )
                                                                                }
                                                                            >
                                                                                <CheckCircle className="mr-2 h-4 w-4" />
                                                                                Administer
                                                                            </Button>
                                                                        )}
                                                                    {canManageMedicationTransit &&
                                                                        (log.status ===
                                                                            'packed' ||
                                                                            log.status ===
                                                                                'administered') && (
                                                                            <Button
                                                                                size="sm"
                                                                                variant="outline"
                                                                                onClick={() =>
                                                                                    openReturnDialog(
                                                                                        log,
                                                                                    )
                                                                                }
                                                                            >
                                                                                <ArrowLeftRight className="mr-2 h-4 w-4" />
                                                                                Return
                                                                            </Button>
                                                                        )}
                                                                    {canManageMedicationTransit &&
                                                                        (log.witness_required ||
                                                                            log.is_controlled_drug) && (
                                                                            <Button
                                                                                size="sm"
                                                                                variant="ghost"
                                                                                onClick={() =>
                                                                                    openPackingCorrectionDialog(
                                                                                        log,
                                                                                    )
                                                                                }
                                                                            >
                                                                                <ShieldCheck className="mr-2 h-4 w-4" />
                                                                                Correct
                                                                                witness
                                                                            </Button>
                                                                        )}
                                                                </div>
                                                            )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {t.status === 'in_progress' &&
                            (completion_blockers ?? []).length > 0 && (
                                <Card className="border-status-warning/30 bg-status-warning-bg dark:border-status-warning/30">
                                    <CardContent className="space-y-2 p-4">
                                        <div className="flex items-center gap-2 text-sm font-semibold text-status-warning dark:text-status-warning">
                                            <AlertTriangle className="h-4 w-4" />
                                            Cannot complete transport yet
                                        </div>
                                        {(completion_blockers ?? []).map(
                                            (blocker, index) => (
                                                <div
                                                    key={index}
                                                    className="flex items-center gap-2 rounded-md bg-status-warning-bg px-3 py-2 text-xs text-status-warning dark:bg-status-warning-bg dark:text-status-warning"
                                                >
                                                    {blocker.type ===
                                                        'unresolved_medications' && (
                                                        <Pill className="h-3.5 w-3.5 shrink-0" />
                                                    )}
                                                    {blocker.type ===
                                                        'controlled_drug_witness' ||
                                                    blocker.type ===
                                                        'medication_packing_attestation' ? (
                                                        <ShieldCheck className="h-3.5 w-3.5 shrink-0" />
                                                    ) : null}
                                                    <span>
                                                        {blocker.message}
                                                    </span>
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-auto border-status-warning/30 text-[9px] text-status-warning"
                                                    >
                                                        {blocker.count}
                                                    </Badge>
                                                </div>
                                            ),
                                        )}
                                    </CardContent>
                                </Card>
                            )}

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
                                                onChange={(event) =>
                                                    completeForm.setData(
                                                        'arrived_at',
                                                        event.target.value,
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
                                                onChange={(event) =>
                                                    completeForm.setData(
                                                        'notes',
                                                        event.target.value,
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

                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Trip Timeline
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-6">
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

            <PackMedicationWizard
                open={packDialogOpen}
                transportId={t.id}
                client={medicationContext.client}
                residentName={t.resident_name}
                medications={safeMedicationOptions}
                witnesses={safeWitnesses}
                onClose={closePackDialog}
                onCompleted={(queued) => {
                    if (!queued) refreshMedicationContext();
                }}
            />
            {canManageMedicationTransit ? (
                <>
                    <CorrectPackingAttestationWizard
                        log={correctingPackingLog}
                        witnesses={safeWitnesses}
                        onClose={closePackingCorrectionDialog}
                        onCompleted={() => refreshMedicationContext()}
                    />
                    <ReturnTransportMedicationWizard
                        log={returningLog}
                        onClose={closeReturnDialog}
                        onCompleted={(queued) => {
                            if (!queued) refreshMedicationContext();
                        }}
                    />
                </>
            ) : null}
            {canAdministerMedicationTransit &&
            (!administeringLog?.is_controlled_drug ||
                canRecordControlledMedication) ? (
                <AdministerTransportMedicationWizard
                    log={administeringLog}
                    witnesses={safeWitnesses}
                    onClose={closeAdministerDialog}
                    onCompleted={(queued) => {
                        if (!queued) refreshMedicationContext();
                    }}
                />
            ) : null}
        </AppLayout>
    );
}
