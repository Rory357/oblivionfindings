import { HorizontalBarChart, MiniBarChart, FLEET_COLORS } from '@/components/fleet-charts';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    Clock,
    DollarSign,
    LayoutGrid,
    Wrench,
} from 'lucide-react';
import { formatDate } from '@/lib/fleet-utils';


/* ------------------------------------------------------------------ */
/*  Types                                                               */
/* ------------------------------------------------------------------ */

type Stats = {
    total_work_orders: number;
    open_work_orders: number;
    total_spend: number;
    avg_cost: number;
    overdue_schedules: number;
};

type CostByVehicle = {
    asset_id: number;
    asset_name: string;
    asset_tag?: string | null;
    total_cost: number;
};

type CostByMonth = {
    month: string;
    total_cost: number;
    count: number;
};

type CostByPriority = {
    priority: string;
    total_cost: number;
    count: number;
};

type WorkOrder = {
    id: number;
    title: string;
    status: string;
    priority: string;
    asset: { id: number; name: string } | null;
    actual_cost: number | null;
    created_at: string | null;
};

type OverdueService = {
    id: number;
    name: string;
    asset_name: string;
    asset_tag?: string | null;
    next_due_at: string | null;
    days_overdue: number;
};

type Props = {
    period: number;
    stats: Stats;
    cost_by_vehicle: CostByVehicle[];
    cost_by_month: CostByMonth[];
    cost_by_priority: CostByPriority[];
    recent_work_orders: WorkOrder[];
    overdue_services: OverdueService[];
};

/* ------------------------------------------------------------------ */
/*  DonutChart (reused pattern from dashboard.tsx)                      */
/* ------------------------------------------------------------------ */

type DonutSegment = { label: string; value: number; color: string };

function DonutChart({
    segments,
    size = 140,
    strokeWidth = 18,
    centerLabel,
    centerValue,
}: {
    segments: DonutSegment[];
    size?: number;
    strokeWidth?: number;
    centerLabel?: string;
    centerValue?: string | number;
}) {
    const radius = (size - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    const total = segments.reduce((sum, s) => sum + s.value, 0);

    if (total === 0) {
        return (
            <div className="flex flex-col items-center justify-center" style={{ width: size, height: size }}>
                <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
                    <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="currentColor" strokeWidth={strokeWidth} className="text-muted/20" />
                    <text x="50%" y="50%" textAnchor="middle" dominantBaseline="central" className="fill-muted-foreground text-xs">No data</text>
                </svg>
            </div>
        );
    }

    let offset = 0;
    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
            <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="currentColor" strokeWidth={strokeWidth} className="text-muted/10" />
            {segments.filter((s) => s.value > 0).map((segment, i) => {
                const pct = segment.value / total;
                const dashLength = pct * circumference;
                const dashGap = circumference - dashLength;
                const rotation = (offset / total) * 360 - 90;
                offset += segment.value;
                return (
                    <circle key={i} cx={size / 2} cy={size / 2} r={radius} fill="none" stroke={segment.color} strokeWidth={strokeWidth}
                        strokeDasharray={`${dashLength} ${dashGap}`} strokeLinecap="butt"
                        transform={`rotate(${rotation} ${size / 2} ${size / 2})`} />
                );
            })}
            {centerValue !== undefined && (
                <>
                    <text x="50%" y="46%" textAnchor="middle" dominantBaseline="central" className="fill-foreground text-2xl font-bold" style={{ fontSize: 22, fontWeight: 700 }}>
                        {centerValue}
                    </text>
                    {centerLabel && (
                        <text x="50%" y="64%" textAnchor="middle" dominantBaseline="central" className="fill-muted-foreground" style={{ fontSize: 10 }}>
                            {centerLabel}
                        </text>
                    )}
                </>
            )}
        </svg>
    );
}

function DonutLegend({ segments }: { segments: DonutSegment[] }) {
    const total = segments.reduce((sum, s) => sum + s.value, 0);
    return (
        <div className="mt-3 space-y-1.5">
            {segments.map((s, i) => (
                <div key={i} className="flex items-center justify-between text-xs">
                    <div className="flex items-center gap-2">
                        <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: s.color }} />
                        <span className="text-muted-foreground">{s.label}</span>
                    </div>
                    <span className="font-medium tabular-nums">
                        ${s.value.toLocaleString()}
                        {total > 0 && <span className="ml-1 text-muted-foreground">({Math.round((s.value / total) * 100)}%)</span>}
                    </span>
                </div>
            ))}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                             */
/* ------------------------------------------------------------------ */

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(value);
}

function priorityColor(priority: string): string {
    switch (priority) {
        case 'critical': return '#ef4444';
        case 'high': return '#f97316';
        case 'medium': return '#eab308';
        case 'low': return '#64748b';
        default: return '#6b7280';
    }
}

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'open': return 'outline';
        case 'in_progress': return 'default';
        case 'completed': return 'secondary';
        case 'on_hold': return 'outline';
        case 'cancelled': return 'destructive';
        default: return 'outline';
    }
}

function priorityVariant(priority: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (priority) {
        case 'critical': return 'destructive';
        case 'high': return 'default';
        case 'medium': return 'outline';
        case 'low': return 'secondary';
        default: return 'outline';
    }
}

/* ------------------------------------------------------------------ */
/*  Inline chart wrappers using shared components                       */
/* ------------------------------------------------------------------ */

function CostByVehicleChart({ data }: { data: CostByVehicle[] }) {
    if (data.length === 0) {
        return <p className="py-6 text-center text-sm text-muted-foreground">No cost data available.</p>;
    }
    return (
        <HorizontalBarChart
            items={data.map((item) => ({
                label: item.asset_name,
                value: Number(item.total_cost.toFixed(0)),
                color: FLEET_COLORS.primary,
            }))}
            color={FLEET_COLORS.primary}
        />
    );
}

function MonthlyBarChartWrapper({ data }: { data: CostByMonth[] }) {
    if (data.length === 0) {
        return <p className="py-6 text-center text-sm text-muted-foreground">No monthly data available.</p>;
    }
    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return (
        <MiniBarChart
            data={data.map((item) => {
                const monthLabel = item.month.split('-')[1] ?? item.month;
                const label = monthNames[parseInt(monthLabel, 10) - 1] ?? monthLabel;
                return { label, value: Math.round(item.total_cost) };
            })}
            color={FLEET_COLORS.primary}
            height={160}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Main Component                                                      */
/* ------------------------------------------------------------------ */

export default function MaintenanceDashboard({
    period: rawPeriod,
    stats: rawStats,
    cost_by_vehicle: rawCostByVehicle,
    cost_by_month: rawCostByMonth,
    cost_by_priority: rawCostByPriority,
    recent_work_orders: rawRecentWO,
    overdue_services: rawOverdue,
}: Props) {
    const period = rawPeriod ?? 90;
    const stats = rawStats ?? { total_work_orders: 0, open_work_orders: 0, total_spend: 0, avg_cost: 0, overdue_schedules: 0 };
    const costByVehicle = rawCostByVehicle ?? [];
    const costByMonth = rawCostByMonth ?? [];
    const costByPriority = rawCostByPriority ?? [];
    const recentWorkOrders = rawRecentWO ?? [];
    const overdueServices = rawOverdue ?? [];

    const prioritySegments: DonutSegment[] = [
        { label: 'Critical', value: costByPriority.find((p) => p.priority === 'critical')?.total_cost ?? 0, color: '#ef4444' },
        { label: 'High', value: costByPriority.find((p) => p.priority === 'high')?.total_cost ?? 0, color: '#f97316' },
        { label: 'Medium', value: costByPriority.find((p) => p.priority === 'medium')?.total_cost ?? 0, color: '#eab308' },
        { label: 'Low', value: costByPriority.find((p) => p.priority === 'low')?.total_cost ?? 0, color: '#64748b' },
    ];
    const totalPrioritySpend = prioritySegments.reduce((s, seg) => s + seg.value, 0);

    const handlePeriodChange = (val: string) => {
        router.get('/fleet-assets/maintenance/dashboard', { period: val }, { preserveState: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Maintenance', href: '/fleet-assets/maintenance/work-orders' },
                { title: 'Overview', href: '/fleet-assets/maintenance/dashboard' },
            ]}
        >
            <Head title="Maintenance Overview" />
            <PageShell>
                <FleetHero
                    title="Maintenance Overview"
                    description="Cost analytics and maintenance health at a glance."
                    actions={
                        <Select value={String(period)} onValueChange={handlePeriodChange}>
                            <SelectTrigger className="w-40">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="30">Last 30 days</SelectItem>
                                <SelectItem value="90">Last 90 days</SelectItem>
                                <SelectItem value="180">Last 6 months</SelectItem>
                                <SelectItem value="365">Last 12 months</SelectItem>
                            </SelectContent>
                        </Select>
                    }
                />

                {/* Summary Cards */}
                <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-primary/10 p-2.5">
                                <Wrench className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">{stats.total_work_orders}</p>
                                <p className="text-xs text-muted-foreground">Total Work Orders</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-status-warning p-2.5">
                                <Clock className="h-5 w-5 text-status-warning" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">{stats.open_work_orders}</p>
                                <p className="text-xs text-muted-foreground">Open Work Orders</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-primary/10 p-2.5">
                                <DollarSign className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">{formatCurrency(stats.total_spend)}</p>
                                <p className="text-xs text-muted-foreground">Total Spend</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-status-info p-2.5">
                                <DollarSign className="h-5 w-5 text-status-info" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">{formatCurrency(stats.avg_cost)}</p>
                                <p className="text-xs text-muted-foreground">Avg Cost / WO</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-status-critical p-2.5">
                                <AlertTriangle className="h-5 w-5 text-status-critical" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">{stats.overdue_schedules}</p>
                                <p className="text-xs text-muted-foreground">Overdue Schedules</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Charts Row */}
                <div className="grid gap-4 lg:grid-cols-3">
                    {/* Cost by Vehicle */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="text-base">Cost by Vehicle</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <CostByVehicleChart data={costByVehicle} />
                        </CardContent>
                    </Card>

                    {/* Cost by Priority Donut */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Cost by Priority</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col items-center">
                            <DonutChart
                                segments={prioritySegments}
                                centerValue={formatCurrency(totalPrioritySpend)}
                                centerLabel="total"
                            />
                            <DonutLegend segments={prioritySegments} />
                        </CardContent>
                    </Card>
                </div>

                {/* Monthly Chart */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Monthly Maintenance Spend</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <MonthlyBarChartWrapper data={costByMonth} />
                    </CardContent>
                </Card>

                {/* Recent Work Orders + Overdue Services */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Recent Work Orders */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Recent Work Orders</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {recentWorkOrders.length > 0 ? (
                                <div className="space-y-2">
                                    {recentWorkOrders.map((wo) => (
                                        <Link
                                            key={wo.id}
                                            href={`/fleet-assets/maintenance/work-orders/${wo.id}`}
                                            className="flex items-center justify-between rounded-md border px-3 py-2 transition-colors hover:bg-muted/50"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="truncate text-sm font-medium">{wo.title}</span>
                                                    <Badge variant={statusVariant(wo.status)} className="text-[10px]">
                                                        {wo.status.replace(/_/g, ' ')}
                                                    </Badge>
                                                    <Badge variant={priorityVariant(wo.priority)} className="text-[10px]">
                                                        {wo.priority}
                                                    </Badge>
                                                </div>
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {wo.asset?.name ?? '---'} &middot;{' '}
                                                    {wo.created_at ? formatDate(wo.created_at) : '---'}
                                                </p>
                                            </div>
                                            <span className="ml-2 text-sm font-medium tabular-nums">
                                                {wo.actual_cost != null ? formatCurrency(wo.actual_cost) : '---'}
                                            </span>
                                        </Link>
                                    ))}
                                </div>
                            ) : (
                                <p className="py-6 text-center text-sm text-muted-foreground">No recent work orders.</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Overdue Services */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AlertTriangle className="h-4 w-4 text-status-critical" />
                                Overdue Services
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {overdueServices.length > 0 ? (
                                <div className="space-y-2">
                                    {overdueServices.map((svc) => (
                                        <div
                                            key={svc.id}
                                            className="flex items-center justify-between rounded-md border border-status-critical/30 bg-status-critical-bg px-3 py-2 dark:border-status-critical/30"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">{svc.name}</p>
                                                <p className="text-xs text-muted-foreground">{svc.asset_name}</p>
                                            </div>
                                            <Badge variant="destructive" className="text-[10px]">
                                                {svc.days_overdue}d overdue
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="py-6 text-center text-sm text-muted-foreground">No overdue services.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
