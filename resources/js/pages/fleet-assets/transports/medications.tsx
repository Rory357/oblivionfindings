import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
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
import { Head, Link, router } from '@inertiajs/react';
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
import { formatDate, formatDateTime } from '@/lib/fleet-utils';

type MedLog = {
    id: number;
    client: { id: number; name: string } | null;
    medication_name: string;
    is_controlled_drug: boolean;
    packed_by: { id: number; name: string } | null;
    packed_at: string | null;
    administered_by: { id: number; name: string } | null;
    administered_at: string | null;
    witnessed_by: { id: number; name: string } | null;
    returned_to_house_at: string | null;
    status: string;
    notes: string | null;
};

type Props = {
    logs: {
        data: MedLog[];
        links: any[];
        meta: { current_page: number; last_page: number; total: number };
    };
    filters: {
        date_from?: string;
        date_to?: string;
        client_id?: string;
        status?: string;
    };
    clients: Array<{ id: number; name: string }>;
    stats: {
        total_packed_today: number;
        controlled_drugs_out: number;
        awaiting_return: number;
    };
};

function statusBadge(status: string) {
    switch (status) {
        case 'packed':
            return <Badge className="bg-amber-500 text-white">Packed</Badge>;
        case 'administered':
            return <Badge className="bg-blue-600 text-white">Administered</Badge>;
        case 'returned':
            return <Badge className="bg-purple-600 text-white">Returned</Badge>;
        default:
            return <Badge variant="secondary">{status}</Badge>;
    }
}

export default function MedicationTransitIndex({ logs, filters, clients, stats }: Props) {
    const safeData = logs?.data ?? [];
    const safeMeta = logs?.meta ?? { current_page: 1, last_page: 1, total: 0 };
    const safeStats = stats ?? { total_packed_today: 0, controlled_drugs_out: 0, awaiting_return: 0 };
    const safeClients = clients ?? [];

    const [localFilters, setLocalFilters] = useState({
        date_from: filters?.date_from ?? '',
        date_to: filters?.date_to ?? '',
        client_id: filters?.client_id ?? '',
        status: filters?.status ?? '',
    });

    const applyFilters = () => {
        const params: Record<string, string> = {};
        if (localFilters.date_from) params.date_from = localFilters.date_from;
        if (localFilters.date_to) params.date_to = localFilters.date_to;
        if (localFilters.client_id) params.client_id = localFilters.client_id;
        if (localFilters.status) params.status = localFilters.status;
        router.get('/fleet-assets/transports/medications', params, { preserveState: true });
    };

    const clearFilters = () => {
        setLocalFilters({ date_from: '', date_to: '', client_id: '', status: '' });
        router.get('/fleet-assets/transports/medications', {}, { preserveState: true });
    };

    const handleAdminister = (logId: number) => {
        router.post(`/fleet-assets/medication-transit/${logId}/administer`, {}, { preserveScroll: true });
    };

    const handleReturn = (logId: number) => {
        router.post(`/fleet-assets/medication-transit/${logId}/return`, {}, { preserveScroll: true });
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
                    description="Track medications packed for resident transport. Controlled drug audit trail for NZ compliance."
                    backHref="/fleet-assets/transports"
                    backLabel="Back to Transport Logs"
                />

                {/* KPI Cards */}
                <div className="grid gap-4 sm:grid-cols-3 mb-6">
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

                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="p-4">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="min-w-[140px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">From</label>
                                <Input
                                    type="date"
                                    value={localFilters.date_from}
                                    onChange={(e) => setLocalFilters((f) => ({ ...f, date_from: e.target.value }))}
                                />
                            </div>
                            <div className="min-w-[140px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">To</label>
                                <Input
                                    type="date"
                                    value={localFilters.date_to}
                                    onChange={(e) => setLocalFilters((f) => ({ ...f, date_to: e.target.value }))}
                                />
                            </div>
                            {safeClients.length > 0 && (
                                <div className="min-w-[180px]">
                                    <label className="mb-1 block text-xs font-medium text-muted-foreground">Resident</label>
                                    <Select
                                        value={localFilters.client_id}
                                        onValueChange={(v) => setLocalFilters((f) => ({ ...f, client_id: v === 'all' ? '' : v }))}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="All Residents" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Residents</SelectItem>
                                            {safeClients.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>
                                                    {c.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}
                            <div className="min-w-[140px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">Status</label>
                                <Select
                                    value={localFilters.status}
                                    onValueChange={(v) => setLocalFilters((f) => ({ ...f, status: v === 'all' ? '' : v }))}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Statuses</SelectItem>
                                        <SelectItem value="packed">Packed</SelectItem>
                                        <SelectItem value="administered">Administered</SelectItem>
                                        <SelectItem value="returned">Returned</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <Button size="sm" onClick={applyFilters}>
                                <Search className="mr-1.5 h-4 w-4" />
                                Filter
                            </Button>
                            <Button size="sm" variant="outline" onClick={clearFilters}>
                                Clear
                            </Button>
                            <div className="ml-auto">
                                <Button size="sm" variant="outline" asChild>
                                    <a href={`/fleet-assets/transports/medications?export=csv&${new URLSearchParams(localFilters as Record<string, string>).toString()}`}>
                                        <Download className="mr-1.5 h-4 w-4" />
                                        Export CSV
                                    </a>
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
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
                                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">Date</th>
                                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">Resident</th>
                                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">Medication</th>
                                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">Controlled?</th>
                                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">Packed By</th>
                                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                                            <th className="px-4 py-3 text-right font-medium text-muted-foreground">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {safeData.map((log) => (
                                            <tr key={log.id} className="hover:bg-muted/20 transition-colors">
                                                <td className="px-4 py-3 whitespace-nowrap text-xs">
                                                    {formatDateTime(log.packed_at)}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className="font-medium">{log.client?.name ?? '---'}</span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    {log.medication_name}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {log.is_controlled_drug ? (
                                                        <Badge variant="destructive" className="text-[10px] flex items-center gap-1 w-fit">
                                                            <AlertTriangle className="h-3 w-3" />
                                                            Yes
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-muted-foreground">No</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    {log.packed_by?.name ?? '---'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {statusBadge(log.status)}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        {log.status === 'packed' && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => handleAdminister(log.id)}
                                                                className="text-xs"
                                                            >
                                                                <Check className="mr-1 h-3 w-3" />
                                                                Administer
                                                            </Button>
                                                        )}
                                                        {(log.status === 'packed' || log.status === 'administered') && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => handleReturn(log.id)}
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

                {/* Pagination */}
                {safeMeta.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                        <span>
                            Page {safeMeta.current_page} of {safeMeta.last_page} ({safeMeta.total} total)
                        </span>
                        <div className="flex gap-1">
                            {(logs?.links ?? []).map((link: any, i: number) => (
                                <Button
                                    key={i}
                                    size="sm"
                                    variant={link.active ? 'default' : 'outline'}
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                    className="text-xs"
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
