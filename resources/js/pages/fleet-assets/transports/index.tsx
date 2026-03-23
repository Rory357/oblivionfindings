import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import { FLEET_COLORS, MiniBarChart } from '@/components/fleet-charts';
import PageHeader from '@/components/page-header';
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
    Car,
    Clock,
    Download,
    Plus,
    Search,
    Truck,
    User,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatDate, formatDuration } from '@/lib/fleet-utils';


type Transport = {
    id: number;
    asset: { id: number; name: string; asset_tag?: string } | null;
    driver: { id: number; name: string } | null;
    resident_name: string;
    transport_type: string;
    pickup_location: string | null;
    dropoff_location: string | null;
    departed_at: string | null;
    arrived_at: string | null;
    passengers_count: number;
    supervisor_name: string | null;
    status: string;
    duration_minutes: number | null;
    notes: string | null;
    created_at: string | null;
};

type Props = {
    transports: {
        data: Transport[];
        meta?: { current_page: number; last_page: number; total: number };
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        transport_type?: string;
        asset_id?: string;
        status?: string;
        search?: string;
        date_from?: string;
        date_to?: string;
    };
    vehicles?: Array<{ id: number; name: string; asset_tag?: string }>;
    stats: {
        total_this_month: number;
        residents_this_month: number;
        avg_duration_minutes: number;
        most_active_vehicle: string | null;
    };
};

const TRANSPORT_TYPE_COLORS: Record<string, string> = {
    medical: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    appointment: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
    social: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    shopping: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
    community: 'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-400',
    respite: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
    other: 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400',
};

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'in_progress': return 'default';
        case 'completed': return 'secondary';
        case 'cancelled': return 'destructive';
        default: return 'outline';
    }
}

// Using shared formatDuration from fleet-utils
function formatDurationMinutes(minutes: number | null): string {
    if (minutes == null) return '---';
    return formatDuration(Math.round(minutes) * 60);
}

const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export default function TransportsIndex({
    transports: rawTransports,
    filters: rawFilters,
    vehicles: rawVehicles,
    stats: rawStats,
}: Props) {
    const transports = rawTransports?.data ?? [];
    const meta = rawTransports?.meta ?? { current_page: 1, last_page: 1, total: 0 };
    const links = rawTransports?.links ?? [];
    const filters = rawFilters ?? {};
    const vehicles = rawVehicles ?? [];
    const stats = rawStats ?? { total_this_month: 0, residents_this_month: 0, avg_duration_minutes: 0, most_active_vehicle: null };

    const [searchValue, setSearchValue] = useState(filters.search ?? '');

    // Generate transports per day of week data
    const dayOfWeekData = useMemo(() => {
        const counts = [0, 0, 0, 0, 0, 0, 0];
        transports.forEach((t) => {
            if (t.departed_at) {
                const day = new Date(t.departed_at).getDay();
                // Convert Sunday=0 to index 6, Monday=1 to index 0
                const idx = day === 0 ? 6 : day - 1;
                counts[idx]++;
            }
        });
        return DAY_LABELS.map((label, i) => ({ label, value: counts[i] }));
    }, [transports]);

    const applyFilters = (newFilters: Record<string, string | undefined>) => {
        router.get('/fleet-assets/transports', {
            ...filters,
            ...newFilters,
            page: 1,
        }, { preserveState: true });
    };

    const handleSearch = () => {
        applyFilters({ search: searchValue || undefined });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Transport Logs', href: '/fleet-assets/transports' },
            ]}
        >
            <Head title="Transport Logs" />
            <PageShell>
                <PageHeader
                    title="Resident Transport Logs"
                    description="Track and manage resident transport activities."
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <a href="/fleet-assets/transports?export=csv">
                                    <Download className="mr-2 h-4 w-4" />
                                    Export CSV
                                </a>
                            </Button>
                            <Button asChild>
                                <Link href="/fleet-assets/transports/create">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Log Transport
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {/* Dark KPI Cards with icons + MiniBarChart */}
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                    <FleetStatCard label="TRANSPORTS (MTD)" value={stats.total_this_month} icon={Truck} subtitle="This month" />
                    <FleetStatCard label="RESIDENTS" value={stats.residents_this_month} icon={Users} subtitle="Transported this month" />
                    <FleetStatCard label="AVG DURATION" value={formatDurationMinutes(stats.avg_duration_minutes)} icon={Clock} subtitle="Per transport" />
                    <FleetStatCard label="MOST USED" value={stats.most_active_vehicle ?? '---'} icon={Car} subtitle="Vehicle this month" />
                    <Card className="border bg-purple-50 dark:bg-purple-950/20 transition-shadow hover:shadow-lg">
                        <CardContent className="p-4">
                            <p className="text-[10px] font-medium uppercase tracking-wider text-slate-400 mb-2">BY DAY OF WEEK</p>
                            <MiniBarChart data={dayOfWeekData} color={FLEET_COLORS.primary} height={80} />
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:flex-wrap">
                    <div className="flex items-center gap-2">
                        <Input
                            placeholder="Search resident..."
                            value={searchValue}
                            onChange={(e) => setSearchValue(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                            className="w-48"
                        />
                        <Button variant="outline" size="icon" onClick={handleSearch}>
                            <Search className="h-4 w-4" />
                        </Button>
                    </div>
                    <Select
                        value={filters.transport_type || 'all'}
                        onValueChange={(v) => applyFilters({ transport_type: v === 'all' ? undefined : v })}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All types</SelectItem>
                            <SelectItem value="medical">Medical</SelectItem>
                            <SelectItem value="appointment">Appointment</SelectItem>
                            <SelectItem value="social">Social</SelectItem>
                            <SelectItem value="shopping">Shopping</SelectItem>
                            <SelectItem value="community">Community</SelectItem>
                            <SelectItem value="respite">Respite</SelectItem>
                            <SelectItem value="other">Other</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.asset_id || 'all'}
                        onValueChange={(v) => applyFilters({ asset_id: v === 'all' ? undefined : v })}
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Vehicle" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All vehicles</SelectItem>
                            {vehicles.map((v) => (
                                <SelectItem key={v.id} value={String(v.id)}>
                                    {v.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Input
                        type="date"
                        value={filters.date_from ?? ''}
                        onChange={(e) => applyFilters({ date_from: e.target.value || undefined })}
                        className="w-36"
                        placeholder="From"
                    />
                    <Input
                        type="date"
                        value={filters.date_to ?? ''}
                        onChange={(e) => applyFilters({ date_to: e.target.value || undefined })}
                        className="w-36"
                        placeholder="To"
                    />
                </div>

                {/* Table */}
                <div className="rounded-lg border overflow-hidden">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Date/Time</th>
                                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Vehicle</th>
                                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Driver</th>
                                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Resident</th>
                                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Type</th>
                                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Pickup</th>
                                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Dropoff</th>
                                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Duration</th>
                                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {transports.length > 0 ? (
                                transports.map((t) => (
                                    <tr
                                        key={t.id}
                                        className="border-b transition-colors hover:bg-muted/30 transition-colors cursor-pointer"
                                        onClick={() => router.visit(`/fleet-assets/transports/${t.id}`)}
                                    >
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            {t.departed_at ? formatDate(t.departed_at) : '---'}
                                            <br />
                                            <span className="text-xs text-muted-foreground">
                                                {t.departed_at ? new Date(t.departed_at).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' }) : ''}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex items-center gap-1.5">
                                                <Car className="h-3.5 w-3.5 text-muted-foreground" />
                                                <span className="font-medium">{t.asset?.name ?? '---'}</span>
                                            </div>
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex items-center gap-1.5">
                                                <User className="h-3.5 w-3.5 text-muted-foreground" />
                                                <span>{t.driver?.name ?? '---'}</span>
                                            </div>
                                        </td>
                                        <td className="px-3 py-2 font-medium">{t.resident_name}</td>
                                        <td className="px-3 py-2">
                                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${TRANSPORT_TYPE_COLORS[t.transport_type] ?? TRANSPORT_TYPE_COLORS.other}`}>
                                                {t.transport_type}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground max-w-[120px] truncate">{t.pickup_location ?? '---'}</td>
                                        <td className="px-3 py-2 text-muted-foreground max-w-[120px] truncate">{t.dropoff_location ?? '---'}</td>
                                        <td className="px-3 py-2">{formatDurationMinutes(t.duration_minutes)}</td>
                                        <td className="px-3 py-2">
                                            <Badge variant={statusVariant(t.status)}>
                                                {t.status.replace(/_/g, ' ')}
                                            </Badge>
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={9} className="px-3 py-12">
                                        <FleetEmptyState icon={Users} title="No transport logs yet" description="Log a resident transport to get started." actionLabel="Log Transport" actionHref="/fleet-assets/transports/create" />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {(meta.last_page ?? 1) > 1 && (
                    <div className="flex items-center justify-center gap-1">
                        {links.map((link, i) => (
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
