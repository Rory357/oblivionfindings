import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import { MiniBarChart, FLEET_COLORS } from '@/components/fleet-charts';
import PageShell from '@/components/page-shell';
import {
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
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
    hero?: {
        planned_today: number;
        active_now: number;
        residents_out_now: number;
        completed_7d: number;
    };
    chart_data: ChartItem[];
    can: {
        manage: boolean;
    };
};

const STATUS_COLORS: Record<string, string> = {
    planned: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
    active: 'bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary',
    completed: 'bg-muted text-foreground dark:bg-muted/30 dark:text-muted-foreground',
    cancelled: 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
};

const PURPOSE_LABELS: Record<string, string> = {
    community: 'Community',
    medical: 'Medical',
    social: 'Social',
    recreational: 'Recreational',
    shopping: 'Shopping',
};

export default function OutingsIndex({ outings, filters, stats, hero, chart_data, can }: Props) {
    const safeOutings = outings?.data ?? [];
    const safeMeta = outings?.meta ?? { current_page: 1, last_page: 1, total: 0 };
    const safeLinks = outings?.links ?? [];
    const safeStats = stats ?? { outings_this_week: 0, residents_this_week: 0, avg_duration_minutes: 0, upcoming: 0 };
    const safeHero = hero ?? { planned_today: 0, active_now: 0, residents_out_now: 0, completed_7d: 0 };
    const safeChartData = chart_data ?? [];

    const [search, setSearch] = useState(filters?.search ?? '');

    const applyFilters = (newFilters: Record<string, string>) => {
        router.get('/fleet-assets/outings', {
            ...filters,
            ...newFilters,
        }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Outings', href: '#' },
            ]}
        >
            <Head title="Community Outings" />
            <PageShell>
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={MapPin} />
                        <div className="min-w-0">
                            <HeroStatusPill>Community access · outings</HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">
                                Community Outings
                            </h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Plan and manage resident outings and community access trips.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:ml-auto lg:max-w-2xl">
                            <HeroClusterTile
                                label="Planned today"
                                value={fmt(safeHero.planned_today)}
                                caption="departing today"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                label="Active now"
                                value={fmt(safeHero.active_now)}
                                caption="out in the community"
                                tone={safeHero.active_now > 0 ? 'warning' : 'success'}
                            />
                            <HeroClusterTile
                                label="Residents out now"
                                value={fmt(safeHero.residents_out_now)}
                                caption="not yet returned"
                                tone={safeHero.residents_out_now > 0 ? 'warning' : 'success'}
                            />
                            <HeroClusterTile
                                label="Completed 7d"
                                value={fmt(safeHero.completed_7d)}
                                caption="this week"
                                tone="neutral"
                            />
                        </div>
                    </div>
                    {can.manage && (
                        <div className="flex flex-wrap items-center gap-2">
                            <FleetHeroAction
                                href="/fleet-assets/outings/create"
                                icon={Plus}
                                emphasis
                            >
                                Plan outing
                            </FleetHeroAction>
                        </div>
                    )}
                </HeroShell>

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
                    {safeChartData.some((entry) => entry.value > 0) && (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">Outings by Day of Week</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <MiniBarChart
                                    data={safeChartData}
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
                                                    <Link href={`/fleet-assets/outings/${outing.id}`} className="font-medium text-primary hover:underline dark:text-primary">
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
                                actionLabel={can.manage ? 'Plan First Outing' : undefined}
                                actionHref={can.manage ? '/fleet-assets/outings/create' : undefined}
                            />
                        </CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
