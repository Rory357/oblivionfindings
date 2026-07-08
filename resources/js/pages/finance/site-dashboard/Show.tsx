import { PageHero } from '@/components/page';
import { FleetStatCard } from '@/components/fleet-stat-card';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/components/finance/money';
import { chartColor } from '@/components/finance/chart-palette';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Building2,
    DollarSign,
    TrendingUp,
    Users,
    Wallet,
} from 'lucide-react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type Insight = { type: string; severity: string; message: string; data: Record<string, any> };
type VarianceLine = { category: string; label: string; planned: string; actual: string; variance: string; variance_pct: string; status: string };

type Props = {
    site: { id: number; name: string; type: string };
    dashboard: {
        hero_cards: { total_cost: string; cost_per_resident: string; avg_residents: string };
        staffing: { wages: string; employer_oncost: string; total_staffing_cost: string; oncost_pct_of_wages: string; staffing_pct_of_total: string };
        breakdown: { categories: Record<string, { amount: string; label: string; count: number }>; chart: Array<{ label: string; value: number; type: string }> };
        trend: { monthly_cost: Record<string, string>; cost_per_resident: Record<string, { total_cost: string; avg_residents: string; cost_per_resident: string }> };
    };
    variance: { lines: VarianceLine[]; totals: { planned: string; actual: string; variance: string; variance_pct: string; status: string } };
    insights: Insight[];
    filters: { from: string; to: string };
};

const severityColor: Record<string, string> = {
    critical: 'bg-status-critical-bg border-status-critical/30 text-status-critical dark:bg-status-critical-bg dark:border-status-critical/30 dark:text-status-critical',
    warning: 'bg-status-warning-bg border-status-warning/30 text-status-warning dark:bg-status-warning-bg dark:border-status-warning/30 dark:text-status-warning',
    info: 'bg-status-info-bg border-status-info/30 text-status-info dark:bg-status-info-bg dark:border-status-info/30 dark:text-status-info',
};

const varianceBadge = (status: string) => {
    switch (status) {
        case 'over_budget': return <Badge variant="destructive">Over Budget</Badge>;
        case 'approaching': return <Badge className="bg-status-warning text-white border-0">Approaching</Badge>;
        case 'under_budget': return <Badge variant="secondary">Under Budget</Badge>;
        default: return <Badge variant="outline">{status}</Badge>;
    }
};

const $ = (v: string | number) => formatMoney(Number(v));
const pct = (v: string | number) => `${Number(v).toFixed(1)}%`;

export default function SiteFinancialDashboard({ site, dashboard, variance, insights, filters }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sites', href: '/sites' },
        { title: site.name, href: `/sites/${site.id}` },
        { title: 'Financial Dashboard' },
    ];

    const trendData = Object.entries(dashboard.trend.monthly_cost).map(([month, cost]) => ({
        month: month.slice(5), // 'MM' from 'YYYY-MM'
        cost: Number(cost),
    }));

    const cprTrendData = Object.entries(dashboard.trend.cost_per_resident).map(([month, d]) => ({
        month: month.slice(5),
        cpr: Number(d.cost_per_resident),
    }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Financial Dashboard - ${site.name}`} />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero */}
                <PageHero category="finance"
                    title="Financial Dashboard"
                    description={`Operational financial overview for ${site.name}`}
                    icon={<DollarSign className="h-7 w-7 text-white" />}
                    backHref={`/sites/${site.id}`}
                    backLabel={site.name}
                    stats={[
                        { label: 'Period', value: `${filters.from.slice(5)} to ${filters.to.slice(5)}` },
                    ]}
                />

                {/* Hero Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FleetStatCard
                        label="Total Cost"
                        value={$(dashboard.hero_cards.total_cost)}
                        icon={DollarSign}
                        color="purple"
                        subtitle={`${pct(dashboard.staffing.staffing_pct_of_total)} staffing`}
                        trend={trendData.map(d => d.cost)}
                    />
                    <FleetStatCard
                        label="Cost per Resident"
                        value={$(dashboard.hero_cards.cost_per_resident)}
                        icon={Users}
                        color="blue"
                        subtitle={`${dashboard.hero_cards.avg_residents} avg residents`}
                        trend={cprTrendData.map(d => d.cpr)}
                    />
                    <FleetStatCard
                        label="Staffing Cost"
                        value={$(dashboard.staffing.total_staffing_cost)}
                        icon={Wallet}
                        color="cyan"
                        subtitle={`${pct(dashboard.staffing.oncost_pct_of_wages)} on-costs`}
                    />
                    <FleetStatCard
                        label="Budget Status"
                        value={variance.totals.status === 'over_budget' ? 'Over Budget' : variance.totals.status === 'approaching' ? 'Approaching' : 'On Track'}
                        icon={BarChart3}
                        color={variance.totals.status === 'over_budget' ? 'red' : variance.totals.status === 'approaching' ? 'amber' : 'purple'}
                        subtitle={`${pct(variance.totals.variance_pct)} variance`}
                    />
                </div>

                {/* Insights */}
                {insights.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider">Alerts & Insights</h2>
                        <div className="space-y-2">
                            {insights.map((insight, i) => (
                                <div key={i} className={`flex items-start gap-3 rounded-lg border p-3 ${severityColor[insight.severity] || severityColor.info}`}>
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                    <span className="text-sm">{insight.message}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Cost Breakdown + Trend Charts */}
                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Pie Chart */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Cost Breakdown</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {dashboard.breakdown.chart.length > 0 ? (
                                <ResponsiveContainer width="100%" height={280}>
                                    <PieChart>
                                        <Pie
                                            data={dashboard.breakdown.chart}
                                            cx="50%"
                                            cy="50%"
                                            innerRadius={60}
                                            outerRadius={100}
                                            dataKey="value"
                                            nameKey="label"
                                            paddingAngle={2}
                                        >
                                            {dashboard.breakdown.chart.map((_, idx) => (
                                                <Cell key={idx} fill={chartColor(idx)} />
                                            ))}
                                        </Pie>
                                        <Tooltip formatter={(v?: number) => $(v ?? 0)} />
                                    </PieChart>
                                </ResponsiveContainer>
                            ) : (
                                <div className="flex h-[280px] items-center justify-center text-sm text-muted-foreground">No cost data for this period</div>
                            )}
                            {/* Legend */}
                            <div className="mt-4 grid grid-cols-2 gap-2">
                                {dashboard.breakdown.chart.map((item, idx) => (
                                    <div key={item.type} className="flex items-center gap-2 text-xs">
                                        <div className="h-2.5 w-2.5 rounded-full shrink-0" style={{ backgroundColor: chartColor(idx) }} />
                                        <span className="text-muted-foreground truncate">{item.label}</span>
                                        <span className="ml-auto font-medium tabular-nums">{$(item.value)}</span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Area Trend Chart */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Cost Trend (6 months)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {trendData.length > 1 ? (
                                <ResponsiveContainer width="100%" height={280}>
                                    <AreaChart data={trendData}>
                                        <defs>
                                            <linearGradient id="costGradient" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor={chartColor(0)} stopOpacity={0.15} />
                                                <stop offset="95%" stopColor={chartColor(0)} stopOpacity={0} />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                        <XAxis dataKey="month" className="text-xs" />
                                        <YAxis tickFormatter={(v) => `$${(v / 1000).toFixed(0)}k`} className="text-xs" />
                                        <Tooltip formatter={(v?: number) => $(v ?? 0)} />
                                        <Area type="monotone" dataKey="cost" stroke={chartColor(0)} fill="url(#costGradient)" strokeWidth={2} />
                                    </AreaChart>
                                </ResponsiveContainer>
                            ) : (
                                <div className="flex h-[280px] items-center justify-center text-sm text-muted-foreground">Not enough data for trend</div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Staffing Cost Block */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Staffing Costs</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-4">
                            <div className="rounded-lg bg-primary/10 p-4 dark:bg-primary/30">
                                <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Wages</p>
                                <p className="mt-1 text-xl font-bold text-primary dark:text-primary/70">{$(dashboard.staffing.wages)}</p>
                            </div>
                            <div className="rounded-lg bg-status-info-bg p-4">
                                <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Employer On-Costs</p>
                                <p className="mt-1 text-xl font-bold text-status-info dark:text-status-info">{$(dashboard.staffing.employer_oncost)}</p>
                                <p className="mt-0.5 text-[10px] text-muted-foreground">{pct(dashboard.staffing.oncost_pct_of_wages)} of wages</p>
                            </div>
                            <div className="rounded-lg bg-status-info-bg p-4">
                                <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Total Staffing</p>
                                <p className="mt-1 text-xl font-bold text-status-info dark:text-status-info">{$(dashboard.staffing.total_staffing_cost)}</p>
                            </div>
                            <div className="rounded-lg bg-muted p-4 dark:bg-muted/30">
                                <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">% of Total Cost</p>
                                <p className="mt-1 text-xl font-bold text-foreground dark:text-muted-foreground">{pct(dashboard.staffing.staffing_pct_of_total)}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Budget vs Actual Table */}
                {variance.lines.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Budget vs Actual</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Category</TableHead>
                                        <TableHead className="text-right">Planned</TableHead>
                                        <TableHead className="text-right">Actual</TableHead>
                                        <TableHead className="text-right">Variance</TableHead>
                                        <TableHead className="text-right">%</TableHead>
                                        <TableHead className="text-right">Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {variance.lines.map((line) => (
                                        <TableRow key={line.category}>
                                            <TableCell className="font-medium">{line.label}</TableCell>
                                            <TableCell className="text-right tabular-nums">{$(line.planned)}</TableCell>
                                            <TableCell className="text-right tabular-nums">{$(line.actual)}</TableCell>
                                            <TableCell className={`text-right tabular-nums ${Number(line.variance) > 0 ? 'text-status-critical' : 'text-status-success'}`}>
                                                {$(line.variance)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">{pct(line.variance_pct)}</TableCell>
                                            <TableCell className="text-right">{varianceBadge(line.status)}</TableCell>
                                        </TableRow>
                                    ))}
                                    {/* Totals row */}
                                    <TableRow className="border-t-2 font-semibold">
                                        <TableCell>Total</TableCell>
                                        <TableCell className="text-right tabular-nums">{$(variance.totals.planned)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{$(variance.totals.actual)}</TableCell>
                                        <TableCell className={`text-right tabular-nums ${Number(variance.totals.variance) > 0 ? 'text-status-critical' : 'text-status-success'}`}>
                                            {$(variance.totals.variance)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{pct(variance.totals.variance_pct)}</TableCell>
                                        <TableCell className="text-right">{varianceBadge(variance.totals.status)}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
