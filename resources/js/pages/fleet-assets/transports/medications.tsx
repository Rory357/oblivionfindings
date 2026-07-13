import { FleetEmptyState } from '@/components/fleet-empty-state';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import {
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowLeftRight,
    Check,
    Download,
    Pill,
    Search,
} from 'lucide-react';
import { useState } from 'react';
import { formatDateTime } from '@/lib/fleet-utils';
import {
    AdministerTransportMedicationWizard,
    ReturnTransportMedicationWizard,
    type TransportMedicationLog as MedLog,
} from './components/transport-medication-dialogs';

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
    };

    const closeReturnDialog = () => {
        setReturningLog(null);
    };

    const openAdministerDialog = (log: MedLog) => {
        setAdministeringLog(log);
    };

    const openReturnDialog = (log: MedLog) => {
        setReturningLog(log);
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
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={Pill} />
                        <div className="min-w-0">
                            <HeroStatusPill>
                                {transportScope
                                    ? `Medication transit · transport #${transportScope.id}`
                                    : 'Medication transit · all trips'}
                            </HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">Medication-in-Transit</h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                {transportScope
                                    ? `Medication workflow for transport #${transportScope.id}. Controlled drug audit trail stays scoped to this trip.`
                                    : 'Track medications packed for resident transport. Controlled drug audit trail for NZ compliance.'}
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-1 gap-2 sm:grid-cols-3 lg:ml-auto lg:max-w-xl">
                            <HeroClusterTile
                                label="Packed today"
                                value={fmt(safeStats.total_packed_today)}
                                caption="for transit today"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                label="Controlled out"
                                value={fmt(safeStats.controlled_drugs_out)}
                                caption="CDs in transit"
                                tone={safeStats.controlled_drugs_out > 0 ? 'critical' : 'success'}
                            />
                            <HeroClusterTile
                                label="Awaiting return"
                                value={fmt(safeStats.awaiting_return)}
                                caption="not back at house"
                                tone={safeStats.awaiting_return > 0 ? 'warning' : 'success'}
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <FleetHeroAction
                            href={
                                transportScope
                                    ? `/fleet-assets/transports/${transportScope.id}`
                                    : '/fleet-assets/transports'
                            }
                            icon={ArrowLeft}
                        >
                            {transportScope ? 'Back to this transport' : 'Back to transport logs'}
                        </FleetHeroAction>
                    </div>
                </HeroShell>

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

            <AdministerTransportMedicationWizard
                log={administeringLog}
                witnesses={safeWitnesses}
                onClose={closeAdministerDialog}
                onCompleted={(queued) => {
                    if (!queued) {
                        router.reload({ only: ['logs', 'stats'] });
                    }
                }}
            />
            <ReturnTransportMedicationWizard
                log={returningLog}
                onClose={closeReturnDialog}
                onCompleted={(queued) => {
                    if (!queued) {
                        router.reload({ only: ['logs', 'stats'] });
                    }
                }}
            />
        </AppLayout>
    );
}
