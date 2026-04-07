import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import { MiniBarChart, FLEET_COLORS } from '@/components/fleet-charts';
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
    Calendar,
    Car,
    Clock,
    List,
    MapPin,
    Plus,
    Search,
    User,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatDate, formatDateTime, formatDurationMinutes } from '@/lib/fleet-utils';

type Outing = {
    id: number;
    title: string;
    destination: string;
    purpose: string | null;
    planned_departure: string | null;
    planned_return: string | null;
    actual_departure: string | null;
    actual_return: string | null;
    asset: { id: number; name: string; asset_tag?: string } | null;
    driver: { id: number; name: string } | null;
    resident_count: number;
    status: string;
    created_at: string | null;
};

type ChartItem = { label: string; value: number };

type Props = {
    outings: {
        data: Outing[];
        meta?: { current_page: number; last_page: number; total: number };
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        status?: string;
        date_from?: string;
        date_to?: string;
        search?: string;
    };
    stats: {
        outings_this_week: number;
        residents_this_week: number;
        avg_duration_minutes: number;
        upcoming: number;
    };
    chart_data: ChartItem[];
};

const STATUS_COLORS: Record<string, string> = {
    planned: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
    active: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
    completed: 'bg-slate-100 text-slate-800 dark:bg-slate-900/30 dark:text-slate-400',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
};

const PURPOSE_LABELS: Record<string, string> = {
    community: 'Community',
    medical: 'Medical',
    social: 'Social',
    recreational: 'Recreational',
    shopping: 'Shopping',
};

export default function OutingsIndex({ outings, filters, stats, chart_data }: Props) {
    const safeOutings = outings?.data ?? [];
    const safeMeta = outings?.meta ?? { current_page: 1, last_page: 1, total: 0 };
    const safeLinks = outings?.links ?? [];
    const safeStats = stats ?? { outings_this_week: 0, residents_this_week: 0, avg_duration_minutes: 0, upcoming: 0 };
    const safeChartData = chart_data ?? [];

    const [search, setSearch] = useState(filters?.search ?? '');

    const applyFilters = (newFilters: Record<string, string>) => {
        router.get('/fleet-assets/outings', {
            ...filters,
            ...newFilters,
        }, { preserveState: true, preserveScroll: true });
    };

    const chartValues = useMemo(() => safeChartData.map((d) => d.value), [safeChartData]);
    const chartLabels = useMemo(() => safeChartData.map((d) => d.label), [safeChartData]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Outings', href: '#' },
            ]}
        >
            <Head title="Community Outings" />
            <PageShell>
                <FleetHero
                    title="Community Outings"
                    description="Plan and manage resident outings and community access trips."
                    actions={
                        <Button asChild>
                            <Link href="/fleet-assets/outings/create">
                                <Plus className="mr-2 h-4 w-4" />
                                Plan Outing
                            </Link>
                        </Button>
                    }
                />

                {/* KPI Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FleetStatCard
                        label="Outings This Week"
                        value={safeStats.outings_this_week}
                        icon={Calendar}
                        color="purple"
                    />
                    <FleetStatCard
                        label="Residents This Week"
                        value={safeStats.residents_this_week}
                        icon={Users}
                        color="blue"
                    />
                    <FleetStatCard
                        label="Avg Duration"
                        value={safeStats.avg_duration_minutes > 0 ? formatDurationMinutes(safeStats.avg_duration_minutes) : '---'}
                        icon={Clock}
                        color="amber"
                    />
                    <FleetStatCard
                        label="Upcoming"
                        value={safeStats.upcoming}
                        icon={MapPin}
                        color="cyan"
                    />
                </div>

                {/* Chart + Filters */}
                <div className="grid gap-4 lg:grid-cols-[2fr_3fr]">
                    {chartValues.some((v) => v > 0) && (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">Outings by Day of Week</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <MiniBarChart
                                    data={chartValues}
                                    labels={chartLabels}
                                    color={FLEET_COLORS.primary}
                                    height={120}
                                />
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm">Filters</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="relative sm:col-span-2">
                                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') applyFilters({ search });
                                        }}
                                        placeholder="Search outings..."
                                        className="pl-9"
                                    />
                                </div>
                                <Select
                                    value={filters?.status ?? 'all'}
                                    onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v })}
                                >
                                    <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Statuses</SelectItem>
                                        <SelectItem value="planned">Planned</SelectItem>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="completed">Completed</SelectItem>
                                        <SelectItem value="cancelled">Cancelled</SelectItem>
                                    </SelectContent>
                                </Select>
                                <div className="flex gap-2">
                                    <Input
                                        type="date"
                                        value={filters?.date_from ?? ''}
                                        onChange={(e) => applyFilters({ date_from: e.target.value })}
                                        className="text-xs"
                                    />
                                    <Input
                                        type="date"
                                        value={filters?.date_to ?? ''}
                                        onChange={(e) => applyFilters({ date_to: e.target.value })}
                                        className="text-xs"
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Outings Table */}
                {safeOutings.length > 0 ? (
                    <Card>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/30">
                                            <th className="px-4 py-3 text-left font-medium">Date</th>
                                            <th className="px-4 py-3 text-left font-medium">Title</th>
                                            <th className="px-4 py-3 text-left font-medium">Destination</th>
                                            <th className="px-4 py-3 text-left font-medium">Residents</th>
                                            <th className="px-4 py-3 text-left font-medium">Vehicle</th>
                                            <th className="px-4 py-3 text-left font-medium">Driver</th>
                                            <th className="px-4 py-3 text-left font-medium">Status</th>
                                            <th className="px-4 py-3 text-left font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {safeOutings.map((outing) => (
                                            <tr key={outing.id} className="border-b last:border-0 hover:bg-muted/20">
                                                <td className="px-4 py-3 text-xs whitespace-nowrap">
                                                    {formatDate(outing.planned_departure)}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Link href={`/fleet-assets/outings/${outing.id}`} className="font-medium text-purple-700 hover:underline dark:text-purple-400">
                                                        {outing.title}
                                                    </Link>
                                                    {outing.purpose && (
                                                        <p className="text-[10px] text-muted-foreground mt-0.5">
                                                            {PURPOSE_LABELS[outing.purpose] ?? outing.purpose}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-xs">{outing.destination}</td>
                                                <td className="px-4 py-3">
                                                    <Badge variant="outline" className="text-[10px]">
                                                        <Users className="mr-1 h-3 w-3" />
                                                        {outing.resident_count}
                                                    </Badge>
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    {outing.asset ? (
                                                        <span className="flex items-center gap-1">
                                                            <Car className="h-3 w-3 text-muted-foreground" />
                                                            {outing.asset.name}
                                                        </span>
                                                    ) : '---'}
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    {outing.driver ? (
                                                        <span className="flex items-center gap-1">
                                                            <User className="h-3 w-3 text-muted-foreground" />
                                                            {outing.driver.name}
                                                        </span>
                                                    ) : '---'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge className={`text-[10px] ${STATUS_COLORS[outing.status] ?? STATUS_COLORS.planned}`}>
                                                        {outing.status}
                                                    </Badge>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Button variant="ghost" size="sm" className="h-7 text-xs" asChild>
                                                        <Link href={`/fleet-assets/outings/${outing.id}`}>View</Link>
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {/* Pagination */}
                            {safeMeta.last_page > 1 && (
                                <div className="flex items-center justify-between border-t px-4 py-3">
                                    <p className="text-xs text-muted-foreground">
                                        Page {safeMeta.current_page} of {safeMeta.last_page} ({safeMeta.total} total)
                                    </p>
                                    <div className="flex gap-1">
                                        {safeLinks.map((link, i) => (
                                            <Button
                                                key={i}
                                                variant={link.active ? 'default' : 'outline'}
                                                size="sm"
                                                className="h-7 text-xs"
                                                disabled={!link.url}
                                                onClick={() => link.url && router.get(link.url)}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        ))}
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent>
                            <FleetEmptyState
                                icon={MapPin}
                                title="No Outings Yet"
                                description="Plan community outings, medical appointments, and social activities for your residents."
                                actionLabel="Plan First Outing"
                                actionHref="/fleet-assets/outings/create"
                            />
                        </CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
