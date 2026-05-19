import { FleetStatCard } from '@/components/fleet-stat-card';
import { FLEET_COLORS, ProgressRing } from '@/components/fleet-charts';
import { Card, CardContent } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    ArrowLeftRight,
    Check,
    Clock,
    Eye,
    Plus,
    XCircle,
} from 'lucide-react';
import { formatDate } from '@/lib/fleet-utils';


type Handover = {
    id: number;
    asset: { id: number; name: string; registration_number?: string | null } | null;
    outgoing_user: { id: number; name: string } | null;
    incoming_user: { id: number; name: string } | null;
    odometer_km: number | null;
    fuel_level: string | null;
    exterior_condition: string;
    interior_condition: string;
    status: string;
    handed_over_at: string | null;
    accepted_at: string | null;
};

type Vehicle = { id: number; name: string };

type PaginatedHandovers = {
    data: Handover[];
    links?: Array<{ url: string | null; label: string; active: boolean }>;
    meta?: { current_page: number; last_page: number; total: number };
};

type Props = {
    handovers: Handover[] | PaginatedHandovers;
    vehicles: Vehicle[];
    filters: {
        vehicle_id?: string;
        status?: string;
        date_from?: string;
        date_to?: string;
    };
    can: {
        manage: boolean;
    };
};

const STATUS_COLORS: Record<string, string> = {
    accepted: 'text-status-success',
    disputed: 'text-status-critical',
    pending_acceptance: 'text-status-warning',
};

function conditionBadge(condition: string) {
    switch (condition) {
        case 'good':
        case 'clean':
            return <Badge variant="default" className="bg-status-success">{condition.replace(/_/g, ' ')}</Badge>;
        case 'minor_damage':
        case 'acceptable':
            return <Badge variant="default" className="bg-status-warning">{condition.replace(/_/g, ' ')}</Badge>;
        case 'significant_damage':
        case 'needs_cleaning':
            return <Badge variant="destructive">{condition.replace(/_/g, ' ')}</Badge>;
        default:
            return <Badge variant="outline">{condition.replace(/_/g, ' ')}</Badge>;
    }
}

function statusBadge(status: string) {
    switch (status) {
        case 'accepted':
            return <Badge variant="default" className="bg-status-success"><Check className="mr-1 h-3 w-3" />Accepted</Badge>;
        case 'disputed':
            return <Badge variant="destructive"><XCircle className="mr-1 h-3 w-3" />Disputed</Badge>;
        case 'pending_acceptance':
            return <Badge variant="outline">Pending</Badge>;
        default:
            return <Badge variant="outline">{status.replace(/_/g, ' ')}</Badge>;
    }
}

function fuelLabel(level: string | null) {
    if (!level) return '---';
    const labels: Record<string, string> = { full: 'Full', '3/4': '3/4', '1/2': '1/2', '1/4': '1/4', empty: 'Empty' };
    return labels[level] ?? level;
}

export default function HandoverIndex({ handovers: rawHandovers, vehicles, filters, can }: Props) {
    const allHandovers = Array.isArray(rawHandovers) ? rawHandovers : (rawHandovers?.data ?? []);
    const paginationLinks = !Array.isArray(rawHandovers) ? rawHandovers?.links ?? [] : [];
    const paginationMeta = !Array.isArray(rawHandovers)
        ? rawHandovers?.meta ?? { current_page: 1, last_page: 1, total: 0 }
        : { current_page: 1, last_page: 1, total: 0 };
    const totalCount = allHandovers.length;
    const pendingCount = allHandovers.filter((h) => h.status === 'pending_acceptance').length;
    const acceptedCount = allHandovers.filter((h) => h.status === 'accepted').length;
    const disputedCount = allHandovers.filter((h) => h.status === 'disputed').length;
    const acceptanceRate = totalCount > 0 ? Math.round((acceptedCount / totalCount) * 100) : 0;

    const applyFilter = (key: string, value: string) => {
        router.get('/fleet-assets/handovers', { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Shift Handovers', href: '#' },
            ]}
        >
            <Head title="Shift Handovers" />
            <PageShell>
                <PageHero
                    title="Shift Handovers"
                    actions={can.manage ? (
                        <Button asChild>
                            <Link href="/fleet-assets/handovers/create">
                                <Plus className="mr-2 h-4 w-4" />
                                New Handover
                            </Link>
                        </Button>
                    ) : undefined}
                />

                {/* Dark KPI Cards */}
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                    <FleetStatCard label="TOTAL HANDOVERS" value={totalCount} icon={ArrowLeftRight} subtitle="All records" />
                    <FleetStatCard label="PENDING" value={pendingCount} icon={Clock} color="amber" valueClassName="text-status-warning" subtitle="Awaiting acceptance" />
                    <FleetStatCard label="ACCEPTED" value={acceptedCount} icon={Check} color="amber" valueClassName="text-status-success" subtitle="Completed handovers" />
                    <FleetStatCard label="DISPUTED" value={disputedCount} icon={XCircle} color="red" valueClassName="text-status-critical" subtitle="Requires review" />
                    <Card className="border bg-primary/10 dark:bg-primary/20 sm:col-span-2 md:col-span-3 lg:col-span-4">
                        <CardContent className="flex items-center gap-6 p-4">
                            <ProgressRing value={acceptanceRate} size={80} color={FLEET_COLORS.success} label="Acceptance Rate" />
                            <div>
                                <p className="text-sm font-medium">Handover Acceptance Rate</p>
                                <p className="text-xs text-muted-foreground mt-1">{acceptedCount} of {totalCount} handovers accepted</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-end gap-3">
                    <div className="min-w-[160px]">
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">Vehicle</label>
                        <Select
                            value={filters.vehicle_id || '__all__'}
                            onValueChange={(v) => applyFilter('vehicle_id', v === '__all__' ? '' : v)}
                        >
                            <SelectTrigger><SelectValue placeholder="All vehicles" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All vehicles</SelectItem>
                                {(vehicles ?? []).map((v) => (
                                    <SelectItem key={v.id} value={String(v.id)}>{v.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="min-w-[140px]">
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">Status</label>
                        <Select
                            value={filters.status || '__all__'}
                            onValueChange={(v) => applyFilter('status', v === '__all__' ? '' : v)}
                        >
                            <SelectTrigger><SelectValue placeholder="All statuses" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All statuses</SelectItem>
                                <SelectItem value="pending_acceptance">Pending</SelectItem>
                                <SelectItem value="accepted">Accepted</SelectItem>
                                <SelectItem value="disputed">Disputed</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">From</label>
                        <Input type="date" value={filters.date_from ?? ''} onChange={(e) => applyFilter('date_from', e.target.value)} className="w-[150px]" />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">To</label>
                        <Input type="date" value={filters.date_to ?? ''} onChange={(e) => applyFilter('date_to', e.target.value)} className="w-[150px]" />
                    </div>
                </div>

                {/* Table with status color coding */}
                <div className="rounded-lg border overflow-hidden">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                <th className="px-3 py-2 text-left font-medium">Date</th>
                                <th className="px-3 py-2 text-left font-medium">Vehicle</th>
                                <th className="px-3 py-2 text-left font-medium">Outgoing Staff</th>
                                <th className="px-3 py-2 text-left font-medium">Incoming Staff</th>
                                <th className="px-3 py-2 text-left font-medium">Fuel</th>
                                <th className="px-3 py-2 text-left font-medium">Condition</th>
                                <th className="px-3 py-2 text-left font-medium">Status</th>
                                <th className="px-3 py-2 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {allHandovers.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="px-3 py-8 text-center text-muted-foreground">
                                        No handovers found.
                                    </td>
                                </tr>
                            )}
                            {allHandovers.map((h) => (
                                <tr key={h.id} className="border-b transition-colors hover:bg-muted/30 transition-colors">
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {h.handed_over_at ? formatDate(h.handed_over_at) : '---'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="font-medium">{h.asset?.name ?? '---'}</div>
                                        {h.asset?.registration_number && (
                                            <div className="text-xs text-muted-foreground">{h.asset.registration_number}</div>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">{h.outgoing_user?.name ?? '---'}</td>
                                    <td className="px-3 py-2">{h.incoming_user?.name ?? '---'}</td>
                                    <td className="px-3 py-2">{fuelLabel(h.fuel_level)}</td>
                                    <td className="px-3 py-2">
                                        <div className="flex gap-1">
                                            {conditionBadge(h.exterior_condition)}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2">{statusBadge(h.status)}</td>
                                    <td className="px-3 py-2 text-right">
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={`/fleet-assets/handovers/${h.id}`}>
                                                <Eye className="mr-1 h-3.5 w-3.5" />
                                                View
                                            </Link>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {(paginationMeta.last_page ?? 1) > 1 && paginationLinks.length > 0 && (
                    <div className="flex items-center justify-center gap-1 pt-4">
                        {paginationLinks.map((link, i) => (
                            <Button
                                key={i}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url)}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
