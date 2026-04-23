import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import { HorizontalBarChart } from '@/components/fleet-charts';
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
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Car,
    Check,
    CheckCircle,
    Clock,
    DollarSign,
    Download,
    MapPin,
    Plus,
    Route,
    Search,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { formatCurrency, formatDate, formatDistance, statusColor } from '@/lib/fleet-utils';

type PersonalTrip = {
    id: number;
    user: { id: number; name: string } | null;
    date: string | null;
    start_location: string;
    end_location: string;
    distance_km: number;
    purpose: string;
    client: { id: number; name: string } | null;
    rate_per_km: number;
    total_amount: number;
    status: string;
    approved_by: { id: number; name: string } | null;
    approved_at: string | null;
    notes: string | null;
    created_at: string | null;
};

type StaffSummary = {
    label: string;
    value: number;
    amount: number;
    trips: number;
};

type Props = {
    trips: {
        data: PersonalTrip[];
        links: any[];
        meta: { current_page: number; last_page: number; total: number };
    };
    filters: {
        date_from?: string;
        date_to?: string;
        status?: string;
        user_id?: string;
    };
    staff: Array<{ id: number; name: string }>;
    stats: {
        trips_this_month: number;
        total_distance: number;
        total_reimbursement: number;
        pending_approval: number;
    };
    staff_summary: StaffSummary[];
    is_manager?: boolean;
    can?: {
        approve: boolean;
    };
};

const _PURPOSE_LABELS: Record<string, string> = {
    client_visit: 'Client Visit',
    meeting: 'Meeting',
    training: 'Training',
    admin: 'Admin',
    other: 'Other',
};

function statusBadge(status: string) {
    switch (status) {
        case 'pending':
            return <Badge className="bg-amber-500 text-white">Pending</Badge>;
        case 'approved':
            return <Badge className="bg-primary text-white">Approved</Badge>;
        case 'rejected':
            return <Badge className="bg-red-500 text-white">Rejected</Badge>;
        case 'paid':
            return <Badge className="bg-green-600 text-white">Paid</Badge>;
        default:
            return <Badge variant="secondary">{status}</Badge>;
    }
}

export default function MileageIndex({ trips, filters, staff, stats, staff_summary, is_manager, can }: Props) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const canApprove = can?.approve ?? false;
    const PURPOSE_LABELS: Record<string, string> = {
        ..._PURPOSE_LABELS,
        client_visit: `${clientSingular} Visit`,
    };
    const safeData = trips?.data ?? [];
    const safeMeta = trips?.meta ?? { current_page: 1, last_page: 1, total: 0 };
    const safeStats = stats ?? { trips_this_month: 0, total_distance: 0, total_reimbursement: 0, pending_approval: 0 };
    const safeStaff = staff ?? [];
    const safeStaffSummary = staff_summary ?? [];

    const [localFilters, setLocalFilters] = useState({
        date_from: filters?.date_from ?? '',
        date_to: filters?.date_to ?? '',
        status: filters?.status ?? '',
        user_id: filters?.user_id ?? '',
    });

    const applyFilters = () => {
        const params: Record<string, string> = {};
        if (localFilters.date_from) params.date_from = localFilters.date_from;
        if (localFilters.date_to) params.date_to = localFilters.date_to;
        if (localFilters.status) params.status = localFilters.status;
        if (localFilters.user_id) params.user_id = localFilters.user_id;
        router.get('/fleet-assets/mileage', params, { preserveState: true });
    };

    const clearFilters = () => {
        setLocalFilters({ date_from: '', date_to: '', status: '', user_id: '' });
        router.get('/fleet-assets/mileage', {}, { preserveState: true });
    };

    const handleApprove = (tripId: number) => {
        router.post(`/fleet-assets/mileage/${tripId}/approve`, {}, { preserveScroll: true });
    };

    const handleReject = (tripId: number) => {
        router.post(`/fleet-assets/mileage/${tripId}/reject`, {}, { preserveScroll: true });
    };

    const handleMarkPaid = (tripId: number) => {
        router.post(`/fleet-assets/mileage/${tripId}/mark-paid`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Mileage Claims', href: '#' },
            ]}
        >
            <Head title="Mileage Claims" />
            <PageShell>
                <FleetHero
                    title="Staff Mileage Claims"
                    description="Personal vehicle mileage reimbursement claims. NZ IRD rate: $0.95/km."
                >
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <a href={`/fleet-assets/mileage/export?${new URLSearchParams(localFilters as Record<string, string>).toString()}`}>
                                <Download className="mr-1.5 h-4 w-4" />
                                Export CSV
                            </a>
                        </Button>
                        <Button size="sm" asChild>
                            <Link href="/fleet-assets/mileage/create">
                                <Plus className="mr-1.5 h-4 w-4" />
                                Log Personal Trip
                            </Link>
                        </Button>
                    </div>
                </FleetHero>

                {/* KPI Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
                    <FleetStatCard
                        label="Trips This Month"
                        value={safeStats.trips_this_month}
                        icon={Car}
                        color="purple"
                        subtitle="Personal vehicle trips"
                    />
                    <FleetStatCard
                        label="Total Distance"
                        value={formatDistance(safeStats.total_distance)}
                        icon={Route}
                        color="blue"
                        subtitle="This month"
                    />
                    <FleetStatCard
                        label="Total Reimbursement"
                        value={formatCurrency(safeStats.total_reimbursement)}
                        icon={DollarSign}
                        color="purple"
                        subtitle="This month"
                    />
                    <FleetStatCard
                        label="Pending Approval"
                        value={safeStats.pending_approval}
                        icon={Clock}
                        color="amber"
                        subtitle="Awaiting review"
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
                                        <SelectItem value="pending">Pending</SelectItem>
                                        <SelectItem value="approved">Approved</SelectItem>
                                        <SelectItem value="rejected">Rejected</SelectItem>
                                        <SelectItem value="paid">Paid</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            {is_manager && safeStaff.length > 0 && (
                                <div className="min-w-[180px]">
                                    <label className="mb-1 block text-xs font-medium text-muted-foreground">Staff Member</label>
                                    <Select
                                        value={localFilters.user_id}
                                        onValueChange={(v) => setLocalFilters((f) => ({ ...f, user_id: v === 'all' ? '' : v }))}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="All Staff" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Staff</SelectItem>
                                            {safeStaff.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}
                            <Button size="sm" onClick={applyFilters}>
                                <Search className="mr-1.5 h-4 w-4" />
                                Filter
                            </Button>
                            <Button size="sm" variant="outline" onClick={clearFilters}>
                                Clear
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Table */}
                    <div className="lg:col-span-2">
                        <Card>
                            <CardContent className="p-0">
                                {safeData.length === 0 ? (
                                    <FleetEmptyState
                                        icon={Car}
                                        title="No mileage claims"
                                        description="Log your first personal vehicle trip to get started."
                                        actionLabel="Log Personal Trip"
                                        actionHref="/fleet-assets/mileage/create"
                                    />
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b bg-muted/30">
                                                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Date</th>
                                                    {is_manager && <th className="px-4 py-3 text-left font-medium text-muted-foreground">Staff</th>}
                                                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Route</th>
                                                    <th className="px-4 py-3 text-right font-medium text-muted-foreground">Distance</th>
                                                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">{clientSingular}</th>
                                                    <th className="px-4 py-3 text-right font-medium text-muted-foreground">Amount</th>
                                                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                                                    {canApprove && <th className="px-4 py-3 text-right font-medium text-muted-foreground">Actions</th>}
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {safeData.map((trip) => (
                                                    <tr key={trip.id} className="hover:bg-muted/20 transition-colors">
                                                        <td className="px-4 py-3 whitespace-nowrap text-xs">
                                                            {formatDate(trip.date)}
                                                        </td>
                                                        {is_manager && (
                                                            <td className="px-4 py-3">
                                                                <span className="font-medium text-xs">{trip.user?.name ?? '---'}</span>
                                                            </td>
                                                        )}
                                                        <td className="px-4 py-3">
                                                            <div className="flex items-center gap-1 text-xs">
                                                                <MapPin className="h-3 w-3 text-muted-foreground shrink-0" />
                                                                <span className="truncate max-w-[120px]">{trip.start_location}</span>
                                                                <span className="text-muted-foreground mx-1">&rarr;</span>
                                                                <span className="truncate max-w-[120px]">{trip.end_location}</span>
                                                            </div>
                                                            <span className="text-[10px] text-muted-foreground">
                                                                {PURPOSE_LABELS[trip.purpose] ?? trip.purpose}
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-3 text-right tabular-nums text-xs">
                                                            {formatDistance(trip.distance_km)}
                                                        </td>
                                                        <td className="px-4 py-3 text-xs">
                                                            {trip.client?.name ?? '---'}
                                                        </td>
                                                        <td className="px-4 py-3 text-right tabular-nums text-xs font-medium">
                                                            {formatCurrency(trip.total_amount)}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            {statusBadge(trip.status)}
                                                        </td>
                                                        {canApprove && (
                                                            <td className="px-4 py-3 text-right">
                                                                {trip.status === 'pending' && (
                                                                    <div className="flex items-center justify-end gap-1">
                                                                        <Button
                                                                            size="sm"
                                                                            variant="outline"
                                                                            onClick={() => handleApprove(trip.id)}
                                                                            className="text-xs h-7"
                                                                        >
                                                                            <Check className="mr-1 h-3 w-3" />
                                                                            Approve
                                                                        </Button>
                                                                        <Button
                                                                            size="sm"
                                                                            variant="outline"
                                                                            onClick={() => handleReject(trip.id)}
                                                                            className="text-xs h-7 text-red-600 hover:text-red-700"
                                                                        >
                                                                            <X className="mr-1 h-3 w-3" />
                                                                            Reject
                                                                        </Button>
                                                                    </div>
                                                                )}
                                                                {trip.status === 'approved' && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        onClick={() => handleMarkPaid(trip.id)}
                                                                        className="text-xs h-7 text-green-600 hover:text-green-700"
                                                                    >
                                                                        <CheckCircle className="mr-1 h-3 w-3" />
                                                                        Mark Paid
                                                                    </Button>
                                                                )}
                                                            </td>
                                                        )}
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
                                    {(trips?.links ?? []).map((link: any, i: number) => (
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
                    </div>

                    {/* Staff Summary Sidebar */}
                    {is_manager && safeStaffSummary.length > 0 && (
                        <div>
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-sm">Staff Distance This Month</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <HorizontalBarChart
                                        items={safeStaffSummary.map((s) => ({
                                            label: s.label,
                                            value: s.value,
                                        }))}
                                    />
                                    <div className="mt-4 space-y-2">
                                        {safeStaffSummary.map((s, i) => (
                                            <div key={i} className="flex items-center justify-between text-xs">
                                                <span className="text-muted-foreground truncate max-w-[60%]">{s.label}</span>
                                                <span className="font-medium tabular-nums">{formatCurrency(s.amount)}</span>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    )}
                </div>
            </PageShell>
        </AppLayout>
    );
}
