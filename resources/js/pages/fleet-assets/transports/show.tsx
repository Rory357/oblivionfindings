import { PageHero } from '@/components/page';
import LeafletMap, { type MapMarker } from '@/components/leaflet-map';
import MedicationScanVerificationPanel from '@/components/medications/MedicationScanVerificationPanel';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { submitEmarMutation } from '@/lib/emar-offline';
import { applyFormRequestErrors } from '@/lib/form-request-errors';
import { formatDateTime, formatDuration } from '@/lib/fleet-utils';
import {
    emptyMedicationScanCapture,
    hasVerifiedMedicationScan,
    toMedicationScanPayload,
    type MedicationScanCapture,
    type MedicationScanVerification,
} from '@/lib/medication-scan';
import { cn } from '@/lib/utils';
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

type CareNeed = {
    id: number;
    label: string;
    notes: string | null;
};

type TransitMedicationOption = {
    id: number;
    name: string;
    dosage: string | null;
    frequency: string | null;
    is_prn: boolean;
    controlled_drug: boolean;
    dose_times: string[] | null;
    route: string | null;
    instructions: string | null;
    scan_verification?: MedicationScanVerification | null;
};

type TransitLog = {
    id: number;
    client: { id: number; name: string } | null;
    medication_id: number | null;
    medication_name: string;
    is_controlled_drug: boolean;
    packed_witness_name?: string | null;
    packed_by: { id: number; name: string } | null;
    packed_at: string | null;
    administered_by: { id: number; name: string } | null;
    administered_at: string | null;
    witnessed_by: { id: number; name: string } | null;
    returned_to_house_at: string | null;
    status: string;
    notes: string | null;
    scan_verification?: MedicationScanVerification | null;
};

type MedicationContext = {
    client: { id: number; name: string } | null;
    available_medications: TransitMedicationOption[];
    transit_logs: TransitLog[];
    witnesses: Array<{ id: number; name: string }>;
    can_manage: boolean;
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
            return <Badge className="bg-status-warning text-white">Packed</Badge>;
        case 'administered':
            return <Badge className="bg-status-info text-white">Administered</Badge>;
        case 'returned':
            return <Badge className="bg-status-success text-white">Returned</Badge>;
        default:
            return <Badge variant="secondary">{status}</Badge>;
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
                witnesses: [],
                can_manage: false,
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
    const safeWitnesses = medicationContext.witnesses ?? [];
    const canManageMedicationTransit = !!medicationContext.can_manage;
    const refreshTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const [packDialogOpen, setPackDialogOpen] = useState(false);
    const [packingMedication, setPackingMedication] = useState(false);
    const [administeringLog, setAdministeringLog] = useState<TransitLog | null>(
        null,
    );
    const [returningLog, setReturningLog] = useState<TransitLog | null>(null);
    const [packScanCapture, setPackScanCapture] =
        useState<MedicationScanCapture>(emptyMedicationScanCapture());
    const [administerScanCapture, setAdministerScanCapture] =
        useState<MedicationScanCapture>(emptyMedicationScanCapture());
    const [returnScanCapture, setReturnScanCapture] =
        useState<MedicationScanCapture>(emptyMedicationScanCapture());
    const [submittingAdminister, setSubmittingAdminister] = useState(false);
    const [submittingReturn, setSubmittingReturn] = useState(false);

    const packClientId = medicationContext.client
        ? String(medicationContext.client.id)
        : '';

    const completeForm = useForm({
        arrived_at: new Date().toISOString().slice(0, 16),
        notes: '',
    });
    const packForm = useForm({
        client_id: packClientId,
        medication_id: '',
        witness_name: '',
        notes: '',
        scan_code: '',
    });
    const administerForm = useForm({
        witnessed_by_user_id: '',
        notes: '',
        scan_code: '',
    });
    const returnForm = useForm({
        notes: '',
        scan_code: '',
    });

    useEffect(() => {
        if (t.status === 'in_progress') {
            refreshTimerRef.current = setInterval(() => {
                router.reload({
                    only: ['vehicle_position', 'medication_context', 'completion_blockers'],
                });
            }, 15000);
        }
        return () => {
            if (refreshTimerRef.current) clearInterval(refreshTimerRef.current);
        };
    }, [t.status]);

    useEffect(() => {
        packForm.setData('client_id', packClientId);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- Keep the Inertia pack form synchronized with the selected medication client.
    }, [packClientId]);

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

    const selectedPackMedication = useMemo(
        () =>
            safeMedicationOptions.find(
                (medication) =>
                    String(medication.id) === String(packForm.data.medication_id),
            ) ?? null,
        [safeMedicationOptions, packForm.data.medication_id],
    );

    const unresolvedMedicationCount = useMemo(
        () =>
            safeTransitLogs.filter((log) => log.status !== 'returned').length,
        [safeTransitLogs],
    );

    const requiresPackWitness = !!selectedPackMedication?.controlled_drug;
    const requiresPackScan = !!selectedPackMedication?.scan_verification;
    const requiresAdminWitness = !!administeringLog?.is_controlled_drug;
    const requiresAdminScan = !!administeringLog?.scan_verification;
    const requiresReturnScan = !!returningLog?.scan_verification;

    const canSubmitPack =
        !!selectedPackMedication &&
        (!requiresPackWitness || !!packForm.data.witness_name.trim()) &&
        (!requiresPackScan || hasVerifiedMedicationScan(packScanCapture));
    const canSubmitAdminister =
        !!administeringLog &&
        (!requiresAdminWitness ||
            !!administerForm.data.witnessed_by_user_id) &&
        (!requiresAdminScan || hasVerifiedMedicationScan(administerScanCapture));
    const canSubmitReturn =
        !!returningLog &&
        (!requiresReturnScan || hasVerifiedMedicationScan(returnScanCapture));

    const refreshMedicationContext = () => {
        router.reload({
            only: ['medication_context', 'completion_blockers'],
        });
    };

    const resetPackDialog = () => {
        packForm.reset();
        packForm.clearErrors();
        packForm.setData('client_id', packClientId);
        setPackScanCapture(emptyMedicationScanCapture());
    };

    const closePackDialog = () => {
        setPackDialogOpen(false);
        resetPackDialog();
    };

    const openPackDialog = () => {
        resetPackDialog();
        setPackDialogOpen(true);
    };

    const closeAdministerDialog = () => {
        setAdministeringLog(null);
        administerForm.reset();
        administerForm.clearErrors();
        setAdministerScanCapture(emptyMedicationScanCapture());
    };

    const closeReturnDialog = () => {
        setReturningLog(null);
        returnForm.reset();
        returnForm.clearErrors();
        setReturnScanCapture(emptyMedicationScanCapture());
    };

    const openAdministerDialog = (log: TransitLog) => {
        setAdministeringLog(log);
        administerForm.reset();
        administerForm.clearErrors();
        setAdministerScanCapture(emptyMedicationScanCapture());
    };

    const openReturnDialog = (log: TransitLog) => {
        setReturningLog(log);
        returnForm.reset();
        returnForm.clearErrors();
        setReturnScanCapture(emptyMedicationScanCapture());
    };

    const handleComplete = (e: React.FormEvent) => {
        e.preventDefault();
        completeForm.post(`/fleet-assets/transports/${t.id}/complete`);
    };

    const submitPack = async () => {
        if (!medicationContext.client || !selectedPackMedication || !canSubmitPack) {
            return;
        }

        packForm.clearErrors();
        setPackingMedication(true);

        try {
            const result = await submitEmarMutation(
                `/fleet-assets/transports/${t.id}/pack-medication`,
                {
                    client_id: medicationContext.client.id,
                    medication_id: selectedPackMedication.id,
                    medication_name: selectedPackMedication.name,
                    is_controlled_drug: selectedPackMedication.controlled_drug,
                    witness_name: packForm.data.witness_name || null,
                    notes: packForm.data.notes || null,
                    ...toMedicationScanPayload(packScanCapture),
                },
                {
                    successMessage: 'Medication packed for transit.',
                    queuedMessage:
                        'Medication packing was saved offline and will sync automatically when the device reconnects.',
                },
            );

            if (result.status === 'conflict') {
                return;
            }

            closePackDialog();

            if (result.status !== 'queued') {
                refreshMedicationContext();
            }
        } catch (error: unknown) {
            applyFormRequestErrors(
                error,
                (field, value) =>
                    (
                        packForm.setError as (
                            field: string,
                            value: string,
                        ) => void
                    )(field, value),
                'Failed to pack medication for this transport.',
            );
        } finally {
            setPackingMedication(false);
        }
    };

    const submitAdminister = async () => {
        if (!administeringLog || !canSubmitAdminister) {
            return;
        }

        administerForm.clearErrors();
        setSubmittingAdminister(true);

        try {
            const result = await submitEmarMutation(
                `/fleet-assets/medication-transit/${administeringLog.id}/administer`,
                {
                    witnessed_by_user_id: administerForm.data.witnessed_by_user_id
                        ? Number(administerForm.data.witnessed_by_user_id)
                        : null,
                    notes: administerForm.data.notes || null,
                    ...toMedicationScanPayload(administerScanCapture),
                },
                {
                    successMessage: 'Medication administration recorded.',
                    queuedMessage:
                        'Medication administration was saved offline and will sync automatically when the device reconnects.',
                },
            );

            if (result.status === 'conflict') {
                return;
            }

            closeAdministerDialog();

            if (result.status !== 'queued') {
                refreshMedicationContext();
            }
        } catch (error: unknown) {
            applyFormRequestErrors(
                error,
                (field, value) =>
                    (
                        administerForm.setError as (
                            field: string,
                            value: string,
                        ) => void
                    )(field, value),
                'Failed to record transport administration.',
            );
        } finally {
            setSubmittingAdminister(false);
        }
    };

    const submitReturn = async () => {
        if (!returningLog || !canSubmitReturn) {
            return;
        }

        returnForm.clearErrors();
        setSubmittingReturn(true);

        try {
            const result = await submitEmarMutation(
                `/fleet-assets/medication-transit/${returningLog.id}/return`,
                {
                    notes: returnForm.data.notes || null,
                    ...toMedicationScanPayload(returnScanCapture),
                },
                {
                    successMessage: 'Medication return recorded.',
                    queuedMessage:
                        'Medication return was saved offline and will sync automatically when the device reconnects.',
                },
            );

            if (result.status === 'conflict') {
                return;
            }

            closeReturnDialog();

            if (result.status !== 'queued') {
                refreshMedicationContext();
            }
        } catch (error: unknown) {
            applyFormRequestErrors(
                error,
                (field, value) =>
                    (
                        returnForm.setError as (
                            field: string,
                            value: string,
                        ) => void
                    )(field, value),
                'Failed to record medication return.',
            );
        } finally {
            setSubmittingReturn(false);
        }
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
                <PageHero
                    title={`Transport #${t.id ?? ''}`}
                    backHref="/fleet-assets/transports"
                    backLabel="Back to Transport Logs"
                    actions={
                        t.status === 'in_progress' ? (
                            <div className="flex flex-wrap gap-2">
                                <Button asChild variant="outline">
                                    <Link
                                        href={`/fleet-assets/transports/medications?transport_id=${t.id}`}
                                    >
                                        <Pill className="mr-2 h-4 w-4" />
                                        Medication Transit
                                    </Link>
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href={`/fleet-assets/transports/${t.id}/pre-check`}>
                                        <ClipboardCheck className="mr-2 h-4 w-4" />
                                        Pre-Transport Check
                                    </Link>
                                </Button>
                            </div>
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
                                                Track medications packed, administered, and returned for this trip.
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
                                                safeMedicationOptions.length > 0 && (
                                                    <Button
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
                                                    {medicationContext.client.name}
                                                </div>
                                            </div>
                                            <div className="rounded-md bg-muted/40 p-3">
                                                <div className="text-xs text-muted-foreground">
                                                    Packable Medications
                                                </div>
                                                <div className="mt-1 text-sm font-medium">
                                                    {safeMedicationOptions.length}
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
                                            This transport is not linked to a resident record, so medication packing is not available on this page.
                                        </div>
                                    )}

                                    {safeTransitLogs.length === 0 ? (
                                        <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                                            No medications are currently logged against this transport.
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
                                                                    {log.medication_name}
                                                                </span>
                                                                {transitStatusBadge(
                                                                    log.status,
                                                                )}
                                                                {log.is_controlled_drug && (
                                                                    <Badge
                                                                        variant="destructive"
                                                                        className="text-[10px]"
                                                                    >
                                                                        Controlled drug
                                                                    </Badge>
                                                                )}
                                                                {log.scan_verification && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="text-[10px]"
                                                                    >
                                                                        Scan required
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {log.client?.name ??
                                                                    medicationContext.client?.name ??
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
                                                                    Witness:{' '}
                                                                    {log.witnessed_by?.name ??
                                                                        log.packed_witness_name ??
                                                                        '---'}
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
                                                        {canManageMedicationTransit &&
                                                            t.status ===
                                                                'in_progress' && (
                                                                <div className="flex flex-wrap gap-2">
                                                                    {log.status ===
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
                                                                    {(log.status ===
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
                                                        'controlled_drug_witness' && (
                                                        <ShieldCheck className="h-3.5 w-3.5 shrink-0" />
                                                    )}
                                                    <span>{blocker.message}</span>
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
                                                value={completeForm.data.arrived_at}
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
                                                disabled={completeForm.processing}
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
                                            <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
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
                                                <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
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
                                            <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
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

            <Dialog
                open={packDialogOpen}
                onOpenChange={(open) => {
                    if (!open) {
                        closePackDialog();
                    } else {
                        setPackDialogOpen(true);
                    }
                }}
            >
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Pack Medication for Transit</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="rounded-md border bg-muted/30 p-3 text-sm">
                            <div className="font-medium">
                                {medicationContext.client?.name ?? t.resident_name}
                            </div>
                            <div className="text-muted-foreground">
                                Select an active medication to add to this transport.
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label>Medication</Label>
                            <Select
                                value={packForm.data.medication_id || 'none'}
                                onValueChange={(value) => {
                                    packForm.clearErrors('medication_id');
                                    packForm.clearErrors('scan_code');
                                    packForm.setData(
                                        'medication_id',
                                        value === 'none' ? '' : value,
                                    );
                                    setPackScanCapture(
                                        emptyMedicationScanCapture(),
                                    );
                                }}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select medication" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Select medication
                                    </SelectItem>
                                    {safeMedicationOptions.map((medication) => (
                                        <SelectItem
                                            key={medication.id}
                                            value={String(medication.id)}
                                        >
                                            {medication.name}
                                            {medication.dosage
                                                ? ` ${medication.dosage}`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {packForm.errors.medication_id && (
                                <p className="text-sm text-destructive">
                                    {packForm.errors.medication_id}
                                </p>
                            )}
                        </div>

                        {selectedPackMedication && (
                            <div className="rounded-md border p-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-medium">
                                        {selectedPackMedication.name}
                                    </span>
                                    {selectedPackMedication.dosage && (
                                        <span className="text-xs text-muted-foreground">
                                            {selectedPackMedication.dosage}
                                        </span>
                                    )}
                                    {selectedPackMedication.is_prn ? (
                                        <Badge
                                            variant="secondary"
                                            className="text-[10px]"
                                        >
                                            PRN
                                        </Badge>
                                    ) : (
                                        <Badge
                                            variant="outline"
                                            className="text-[10px]"
                                        >
                                            Scheduled
                                        </Badge>
                                    )}
                                    {selectedPackMedication.controlled_drug && (
                                        <Badge
                                            variant="destructive"
                                            className="text-[10px]"
                                        >
                                            Controlled
                                        </Badge>
                                    )}
                                </div>
                                {(selectedPackMedication.route ||
                                    selectedPackMedication.instructions) && (
                                    <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                                        {selectedPackMedication.route && (
                                            <div>
                                                Route:{' '}
                                                {selectedPackMedication.route}
                                            </div>
                                        )}
                                        {selectedPackMedication.instructions && (
                                            <div>
                                                {selectedPackMedication.instructions}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}

                        {requiresPackWitness && (
                            <div className="space-y-2">
                                <Label>Witness name</Label>
                                <Input
                                    value={packForm.data.witness_name}
                                    onChange={(event) => {
                                        packForm.clearErrors('witness_name');
                                        packForm.setData(
                                            'witness_name',
                                            event.target.value,
                                        );
                                    }}
                                    placeholder="Required for controlled drugs"
                                />
                                {packForm.errors.witness_name && (
                                    <p className="text-sm text-destructive">
                                        {packForm.errors.witness_name}
                                    </p>
                                )}
                            </div>
                        )}

                        {selectedPackMedication?.scan_verification && (
                            <MedicationScanVerificationPanel
                                clientId={medicationContext.client?.id ?? null}
                                medicationId={selectedPackMedication.id}
                                scanVerification={
                                    selectedPackMedication.scan_verification
                                }
                                requirementText="Verification is required before packing this medication for transit."
                                resetKey={`pack-${selectedPackMedication.id}-${packDialogOpen}`}
                                onChange={(capture) => {
                                    packForm.clearErrors('scan_code');
                                    setPackScanCapture(capture);
                                }}
                            />
                        )}
                        {packForm.errors.scan_code && (
                            <p className="text-sm text-destructive">
                                {packForm.errors.scan_code}
                            </p>
                        )}

                        <div className="space-y-2">
                            <Label>Notes</Label>
                            <Textarea
                                value={packForm.data.notes}
                                onChange={(event) =>
                                    packForm.setData(
                                        'notes',
                                        event.target.value,
                                    )
                                }
                                placeholder="Add any chain-of-custody or handling notes..."
                            />
                            {packForm.errors.notes && (
                                <p className="text-sm text-destructive">
                                    {packForm.errors.notes}
                                </p>
                            )}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={closePackDialog}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            onClick={submitPack}
                            disabled={packingMedication || !canSubmitPack}
                        >
                            {packingMedication ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <Package className="mr-2 h-4 w-4" />
                            )}
                            Pack Medication
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={!!administeringLog}
                onOpenChange={(open) => {
                    if (!open) {
                        closeAdministerDialog();
                    }
                }}
            >
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            Record Transport Administration
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="rounded-md border bg-muted/30 p-3 text-sm">
                            <div className="font-medium">
                                {administeringLog?.medication_name ?? '---'}
                            </div>
                            <div className="text-muted-foreground">
                                {administeringLog?.client?.name ?? '---'}
                            </div>
                        </div>

                        {requiresAdminWitness && (
                            <div className="space-y-2">
                                <Label>Witness</Label>
                                <Select
                                    value={
                                        administerForm.data
                                            .witnessed_by_user_id || 'none'
                                    }
                                    onValueChange={(value) => {
                                        administerForm.clearErrors(
                                            'witnessed_by_user_id',
                                        );
                                        administerForm.setData(
                                            'witnessed_by_user_id',
                                            value === 'none' ? '' : value,
                                        );
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select witness" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            Select witness
                                        </SelectItem>
                                        {safeWitnesses.map((witness) => (
                                            <SelectItem
                                                key={witness.id}
                                                value={String(witness.id)}
                                            >
                                                {witness.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {administerForm.errors
                                    .witnessed_by_user_id && (
                                    <p className="text-sm text-destructive">
                                        {
                                            administerForm.errors
                                                .witnessed_by_user_id
                                        }
                                    </p>
                                )}
                            </div>
                        )}

                        {requiresAdminScan && administeringLog && (
                            <MedicationScanVerificationPanel
                                clientId={administeringLog.client?.id ?? null}
                                medicationId={administeringLog.medication_id}
                                scanVerification={
                                    administeringLog.scan_verification
                                }
                                requirementText="Verification is required before recording this administration."
                                resetKey={`administer-${administeringLog.id}`}
                                onChange={(capture) => {
                                    administerForm.clearErrors('scan_code');
                                    setAdministerScanCapture(capture);
                                }}
                            />
                        )}
                        {administerForm.errors.scan_code && (
                            <p className="text-sm text-destructive">
                                {administerForm.errors.scan_code}
                            </p>
                        )}

                        <div className="space-y-2">
                            <Label>Notes</Label>
                            <Textarea
                                value={administerForm.data.notes}
                                onChange={(event) =>
                                    administerForm.setData(
                                        'notes',
                                        event.target.value,
                                    )
                                }
                                placeholder="Add any transport administration notes..."
                            />
                            {administerForm.errors.notes && (
                                <p className="text-sm text-destructive">
                                    {administerForm.errors.notes}
                                </p>
                            )}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={closeAdministerDialog}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            onClick={submitAdminister}
                            disabled={
                                submittingAdminister || !canSubmitAdminister
                            }
                        >
                            {submittingAdminister ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <CheckCircle className="mr-2 h-4 w-4" />
                            )}
                            Record Administration
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={!!returningLog}
                onOpenChange={(open) => {
                    if (!open) {
                        closeReturnDialog();
                    }
                }}
            >
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Record Medication Return</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="rounded-md border bg-muted/30 p-3 text-sm">
                            <div className="font-medium">
                                {returningLog?.medication_name ?? '---'}
                            </div>
                            <div className="text-muted-foreground">
                                {returningLog?.client?.name ?? '---'}
                            </div>
                        </div>

                        {requiresReturnScan && returningLog && (
                            <MedicationScanVerificationPanel
                                clientId={returningLog.client?.id ?? null}
                                medicationId={returningLog.medication_id}
                                scanVerification={
                                    returningLog.scan_verification
                                }
                                requirementText="Verification is required before returning this medication to house stock."
                                resetKey={`return-${returningLog.id}`}
                                onChange={(capture) => {
                                    returnForm.clearErrors('scan_code');
                                    setReturnScanCapture(capture);
                                }}
                            />
                        )}
                        {returnForm.errors.scan_code && (
                            <p className="text-sm text-destructive">
                                {returnForm.errors.scan_code}
                            </p>
                        )}

                        <div className="space-y-2">
                            <Label>Return notes</Label>
                            <Textarea
                                value={returnForm.data.notes}
                                onChange={(event) =>
                                    returnForm.setData(
                                        'notes',
                                        event.target.value,
                                    )
                                }
                                placeholder="Add any hand-back or chain-of-custody notes..."
                            />
                            {returnForm.errors.notes && (
                                <p className="text-sm text-destructive">
                                    {returnForm.errors.notes}
                                </p>
                            )}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={closeReturnDialog}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            onClick={submitReturn}
                            disabled={submittingReturn || !canSubmitReturn}
                        >
                            {submittingReturn ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <ArrowLeftRight className="mr-2 h-4 w-4" />
                            )}
                            Record Return
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
