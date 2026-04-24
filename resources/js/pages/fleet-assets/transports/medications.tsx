import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import FleetHero from '@/components/fleet-hero';
import MedicationScanVerificationPanel from '@/components/medications/MedicationScanVerificationPanel';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import {
    emptyMedicationScanCapture,
    hasVerifiedMedicationScan,
    toMedicationScanPayload,
    type MedicationScanCapture,
    type MedicationScanVerification,
} from '@/lib/medication-scan';
import { submitEmarMutation } from '@/lib/emar-offline';
import { applyFormRequestErrors } from '@/lib/form-request-errors';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeftRight,
    Check,
    Download,
    Package,
    Pill,
    Search,
    Shield,
} from 'lucide-react';
import { useState } from 'react';
import { formatDateTime } from '@/lib/fleet-utils';

type MedLog = {
    id: number;
    transport?: {
        id: number;
        resident_name: string;
        transport_type: string;
        status: string;
        departed_at: string | null;
        arrived_at: string | null;
        asset?: { id: number; name: string; asset_tag?: string | null } | null;
    } | null;
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

type Props = {
    logs: {
        data: MedLog[];
        links: Array<{ url?: string | null; label: string; active: boolean }>;
        meta: { current_page: number; last_page: number; total: number };
    };
    filters: {
        date_from?: string;
        date_to?: string;
        client_id?: string;
        status?: string;
        transport_id?: string;
    };
    clients: Array<{ id: number; name: string }>;
    witnesses: Array<{ id: number; name: string }>;
    transport_scope?: {
        id: number;
        resident_name: string;
        transport_type: string;
        status: string;
        departed_at: string | null;
        arrived_at: string | null;
        asset?: { id: number; name: string; asset_tag?: string | null } | null;
    } | null;
    stats: {
        total_packed_today: number;
        controlled_drugs_out: number;
        awaiting_return: number;
    };
};

function statusBadge(status: string) {
    switch (status) {
        case 'packed':
            return <Badge className="bg-status-warning text-white">Packed</Badge>;
        case 'administered':
            return <Badge className="bg-status-info text-white">Administered</Badge>;
        case 'returned':
            return <Badge className="bg-primary text-white">Returned</Badge>;
        default:
            return <Badge variant="secondary">{status}</Badge>;
    }
}

export default function MedicationTransitIndex({
    logs,
    filters,
    clients,
    witnesses,
    transport_scope,
    stats,
}: Props) {
    const safeData = logs?.data ?? [];
    const safeMeta = logs?.meta ?? { current_page: 1, last_page: 1, total: 0 };
    const safeStats = stats ?? {
        total_packed_today: 0,
        controlled_drugs_out: 0,
        awaiting_return: 0,
    };
    const safeClients = clients ?? [];
    const safeWitnesses = witnesses ?? [];
    const transportScope = transport_scope ?? null;

    const [localFilters, setLocalFilters] = useState({
        date_from: filters?.date_from ?? '',
        date_to: filters?.date_to ?? '',
        client_id: filters?.client_id ?? '',
        status: filters?.status ?? '',
        transport_id: filters?.transport_id ?? '',
    });
    const [administeringLog, setAdministeringLog] = useState<MedLog | null>(null);
    const [returningLog, setReturningLog] = useState<MedLog | null>(null);
    const [administerScanCapture, setAdministerScanCapture] = useState<MedicationScanCapture>(emptyMedicationScanCapture());
    const [returnScanCapture, setReturnScanCapture] = useState<MedicationScanCapture>(emptyMedicationScanCapture());
    const [submittingAdminister, setSubmittingAdminister] = useState(false);
    const [submittingReturn, setSubmittingReturn] = useState(false);

    const administerForm = useForm({
        witnessed_by_user_id: '',
        notes: '',
        scan_code: '',
        scan_source: 'manual' as 'manual' | 'scanner',
        scan_verified: false,
        scan_match_source: '',
    });
    const returnForm = useForm({
        notes: '',
        scan_code: '',
        scan_source: 'manual' as 'manual' | 'scanner',
        scan_verified: false,
        scan_match_source: '',
    });

    const applyFilters = () => {
        const params: Record<string, string> = {};

        if (localFilters.date_from) params.date_from = localFilters.date_from;
        if (localFilters.date_to) params.date_to = localFilters.date_to;
        if (localFilters.client_id) params.client_id = localFilters.client_id;
        if (localFilters.status) params.status = localFilters.status;
        if (localFilters.transport_id) {
            params.transport_id = localFilters.transport_id;
        }

        router.get('/fleet-assets/transports/medications', params, {
            preserveState: true,
        });
    };

    const clearFilters = () => {
        setLocalFilters({
            date_from: '',
            date_to: '',
            client_id: '',
            status: '',
            transport_id: filters?.transport_id ?? '',
        });
        router.get(
            '/fleet-assets/transports/medications',
            filters?.transport_id ? { transport_id: filters.transport_id } : {},
            {
            preserveState: true,
            },
        );
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

    const openAdministerDialog = (log: MedLog) => {
        setAdministeringLog(log);
        administerForm.reset();
        administerForm.clearErrors();
        setAdministerScanCapture(emptyMedicationScanCapture());
    };

    const openReturnDialog = (log: MedLog) => {
        setReturningLog(log);
        returnForm.reset();
        returnForm.clearErrors();
        setReturnScanCapture(emptyMedicationScanCapture());
    };

    const requiresAdminWitness = !!administeringLog?.is_controlled_drug;
    const requiresAdminScan = !!administeringLog?.scan_verification;
    const requiresReturnScan = !!returningLog?.scan_verification;

    const canSubmitAdminister =
        !!administeringLog &&
        (!requiresAdminWitness || !!administerForm.data.witnessed_by_user_id) &&
        (!requiresAdminScan || hasVerifiedMedicationScan(administerScanCapture));

    const canSubmitReturn =
        !!returningLog &&
        (!requiresReturnScan || hasVerifiedMedicationScan(returnScanCapture));

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
                    ...administerForm.data,
                    witnessed_by_user_id: administerForm.data.witnessed_by_user_id
                        ? Number(administerForm.data.witnessed_by_user_id)
                        : null,
                    notes: administerForm.data.notes || null,
                    ...toMedicationScanPayload(administerScanCapture),
                },
                {
                    successMessage: 'Medication administration recorded.',
                    queuedMessage:
                        'Medication transit administration saved offline and will sync automatically when the device reconnects.',
                },
            );

            if (result.status === 'conflict') {
                return;
            }

            closeAdministerDialog();

            if (result.status !== 'queued') {
                router.reload({
                    only: ['logs', 'stats'],
                });
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
                    ...returnForm.data,
                    notes: returnForm.data.notes || null,
                    ...toMedicationScanPayload(returnScanCapture),
                },
                {
                    successMessage: 'Medication return recorded.',
                    queuedMessage:
                        'Medication return saved offline and will sync automatically when the device reconnects.',
                },
            );

            if (result.status === 'conflict') {
                return;
            }

            closeReturnDialog();

            if (result.status !== 'queued') {
                router.reload({
                    only: ['logs', 'stats'],
                });
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
                { title: 'Medication Transit', href: '#' },
            ]}
        >
            <Head title="Medication Transit" />
            <PageShell>
                <FleetHero
                    title="Medication-in-Transit"
                    description={
                        transportScope
                            ? `Medication workflow for transport #${transportScope.id}. Controlled drug audit trail stays scoped to this trip.`
                            : 'Track medications packed for resident transport. Controlled drug audit trail for NZ compliance.'
                    }
                    backHref={
                        transportScope
                            ? `/fleet-assets/transports/${transportScope.id}`
                            : '/fleet-assets/transports'
                    }
                    backLabel={
                        transportScope
                            ? 'Back to This Transport'
                            : 'Back to Transport Logs'
                    }
                />

                {transportScope && (
                    <Card className="mb-6 border-primary/20 bg-primary/5">
                        <CardContent className="flex flex-col gap-4 p-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="space-y-1">
                                <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    Scoped to Transport #{transportScope.id}
                                </div>
                                <div className="text-base font-semibold">
                                    {transportScope.resident_name || 'Resident not recorded'}
                                </div>
                                <div className="flex flex-wrap gap-2 text-sm text-muted-foreground">
                                    <span className="capitalize">
                                        {transportScope.transport_type}
                                    </span>
                                    <span>•</span>
                                    <span className="capitalize">
                                        {transportScope.status.replaceAll('_', ' ')}
                                    </span>
                                    {transportScope.asset?.name && (
                                        <>
                                            <span>•</span>
                                            <span>{transportScope.asset.name}</span>
                                        </>
                                    )}
                                    {transportScope.departed_at && (
                                        <>
                                            <span>•</span>
                                            <span>
                                                Departed {formatDateTime(transportScope.departed_at)}
                                            </span>
                                        </>
                                    )}
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button asChild size="sm" variant="outline">
                                    <Link href={`/fleet-assets/transports/${transportScope.id}`}>
                                        Open Transport
                                    </Link>
                                </Button>
                                <Button asChild size="sm" variant="outline">
                                    <Link href="/fleet-assets/transports/medications">
                                        View All Transit Logs
                                    </Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <div className="mb-6 grid gap-4 sm:grid-cols-3">
                    <FleetStatCard
                        label="Packed Today"
                        value={safeStats.total_packed_today}
                        icon={Package}
                        color="purple"
                        subtitle="Medications packed for transit today"
                    />
                    <FleetStatCard
                        label="Controlled Drugs Out"
                        value={safeStats.controlled_drugs_out}
                        icon={Shield}
                        color="red"
                        subtitle="Controlled drugs currently in transit"
                    />
                    <FleetStatCard
                        label="Awaiting Return"
                        value={safeStats.awaiting_return}
                        icon={ArrowLeftRight}
                        color="amber"
                        subtitle="Medications not yet returned to house"
                    />
                </div>

                <Card className="mb-6">
                    <CardContent className="p-4">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="min-w-[140px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                                    From
                                </label>
                                <Input
                                    type="date"
                                    value={localFilters.date_from}
                                    onChange={(event) =>
                                        setLocalFilters((current) => ({
                                            ...current,
                                            date_from: event.target.value,
                                        }))
                                    }
                                />
                            </div>
                            <div className="min-w-[140px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                                    To
                                </label>
                                <Input
                                    type="date"
                                    value={localFilters.date_to}
                                    onChange={(event) =>
                                        setLocalFilters((current) => ({
                                            ...current,
                                            date_to: event.target.value,
                                        }))
                                    }
                                />
                            </div>
                            {safeClients.length > 0 && (
                                <div className="min-w-[180px]">
                                    <label className="mb-1 block text-xs font-medium text-muted-foreground">
                                        Resident
                                    </label>
                                    <Select
                                        value={localFilters.client_id}
                                        onValueChange={(value) =>
                                            setLocalFilters((current) => ({
                                                ...current,
                                                client_id:
                                                    value === 'all'
                                                        ? ''
                                                        : value,
                                            }))
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="All Residents" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                All Residents
                                            </SelectItem>
                                            {safeClients.map((client) => (
                                                <SelectItem
                                                    key={client.id}
                                                    value={String(client.id)}
                                                >
                                                    {client.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}
                            <div className="min-w-[140px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                                    Status
                                </label>
                                <Select
                                    value={localFilters.status}
                                    onValueChange={(value) =>
                                        setLocalFilters((current) => ({
                                            ...current,
                                            status:
                                                value === 'all' ? '' : value,
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All Statuses
                                        </SelectItem>
                                        <SelectItem value="packed">
                                            Packed
                                        </SelectItem>
                                        <SelectItem value="administered">
                                            Administered
                                        </SelectItem>
                                        <SelectItem value="returned">
                                            Returned
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <Button size="sm" onClick={applyFilters}>
                                <Search className="mr-1.5 h-4 w-4" />
                                Filter
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={clearFilters}
                            >
                                Clear
                            </Button>
                            <div className="ml-auto">
                                <Button size="sm" variant="outline" asChild>
                                    <a
                                        href={`/fleet-assets/transports/medications?export=csv&${new URLSearchParams(
                                            localFilters as Record<
                                                string,
                                                string
                                            >,
                                        ).toString()}`}
                                    >
                                        <Download className="mr-1.5 h-4 w-4" />
                                        Export CSV
                                    </a>
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        {safeData.length === 0 ? (
                            <FleetEmptyState
                                icon={Pill}
                                title="No medication transit logs"
                                description="Medication transit logs will appear here when medications are packed for transport."
                            />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/30">
                                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                                Date
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                                Resident
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                                Medication
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                                Controlled?
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                                Packed By
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                                Status
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium text-muted-foreground">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {safeData.map((log) => (
                                            <tr
                                                key={log.id}
                                                className="transition-colors hover:bg-muted/20"
                                            >
                                                <td className="whitespace-nowrap px-4 py-3 text-xs">
                                                    {formatDateTime(
                                                        log.packed_at,
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 font-medium">
                                                    <div className="space-y-1">
                                                        <div>
                                                            {log.client?.name ?? '---'}
                                                        </div>
                                                        {!transportScope &&
                                                            log.transport && (
                                                                <div className="text-xs font-normal text-muted-foreground">
                                                                    Trip #{log.transport.id}
                                                                </div>
                                                            )}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="space-y-1">
                                                        <div className="font-medium">
                                                            {
                                                                log.medication_name
                                                            }
                                                        </div>
                                                        {log.scan_verification && (
                                                            <Badge
                                                                variant="outline"
                                                                className="text-[10px]"
                                                            >
                                                                Scan required
                                                            </Badge>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    {log.is_controlled_drug ? (
                                                        <Badge
                                                            variant="destructive"
                                                            className="flex w-fit items-center gap-1 text-[10px]"
                                                        >
                                                            <AlertTriangle className="h-3 w-3" />
                                                            Yes
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            No
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    <div className="space-y-1">
                                                        <div>
                                                            {log.packed_by
                                                                ?.name ??
                                                                '---'}
                                                        </div>
                                                        {log.packed_witness_name && (
                                                            <div className="text-muted-foreground">
                                                                Witness:{' '}
                                                                {
                                                                    log.packed_witness_name
                                                                }
                                                            </div>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="space-y-1">
                                                        {statusBadge(log.status)}
                                                        {log.administered_at && (
                                                            <div className="text-xs text-muted-foreground">
                                                                Administered{' '}
                                                                {formatDateTime(
                                                                    log.administered_at,
                                                                )}
                                                            </div>
                                                        )}
                                                        {log.returned_to_house_at && (
                                                            <div className="text-xs text-muted-foreground">
                                                                Returned{' '}
                                                                {formatDateTime(
                                                                    log.returned_to_house_at,
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-1">
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
                                                                className="text-xs"
                                                            >
                                                                <Check className="mr-1 h-3 w-3" />
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
                                                                className="text-xs"
                                                            >
                                                                <ArrowLeftRight className="mr-1 h-3 w-3" />
                                                                Return
                                                            </Button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {safeMeta.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                        <span>
                            Page {safeMeta.current_page} of {safeMeta.last_page}{' '}
                            ({safeMeta.total} total)
                        </span>
                        <div className="flex gap-1">
                            {(logs?.links ?? []).map((link, index) => (
                                <Button
                                    key={index}
                                    size="sm"
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(link.url, {}, {
                                            preserveState: true,
                                        })
                                    }
                                    className="text-xs"
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </PageShell>

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
                                            .witnessed_by_user_id
                                    }
                                    onValueChange={(value) => {
                                        administerForm.clearErrors(
                                            'witnessed_by_user_id',
                                        );
                                        administerForm.setData(
                                            'witnessed_by_user_id',
                                            value,
                                        );
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select witness" />
                                    </SelectTrigger>
                                    <SelectContent>
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
                                medicationId={
                                    administeringLog.medication_id
                                }
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
                                submittingAdminister ||
                                !canSubmitAdminister
                            }
                        >
                            <Check className="mr-2 h-4 w-4" />
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
                            disabled={
                                submittingReturn || !canSubmitReturn
                            }
                        >
                            <ArrowLeftRight className="mr-2 h-4 w-4" />
                            Record Return
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
