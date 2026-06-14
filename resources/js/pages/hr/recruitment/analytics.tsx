import PageShell from '@/components/page-shell';
import { KpiCard } from '@/components/recruitment/kpi-card';
import {
    stageLabels,
    statusConfig,
} from '@/components/recruitment/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import { RecruitmentTabs } from '@/components/hr';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, BarChart3, Briefcase, Clock, TrendingUp, Users } from 'lucide-react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Legend,
    Line,
    LineChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type TimeToHireEntry = { month: string; avg_days: number; count: number };
type SourceEntry = {
    source: string;
    total: number;
    hired: number;
    active: number;
    conversion_rate: number;
};
type PipelineEntry = { stage: string; count: number; percentage: number };
type PositionEntry = {
    position_title: string;
    applications: number;
    days_open: number;
};
type VelocityEntry = { month: string; count: number };
type BottleneckEntry = { stage: string; avg_days: number; count: number };
type TrendEntry = { month: string; count: number };

type Props = {
    timeToHire: TimeToHireEntry[];
    sourceEffectiveness: SourceEntry[];
    pipelineConversion: PipelineEntry[];
    openPositions: PositionEntry[];
    hiringVelocity: VelocityEntry[];
    stageBottlenecks: BottleneckEntry[];
    monthlyTrend: TrendEntry[];
};

const CHART_COLORS = [
    '#3b82f6',
    '#6366f1',
    '#f59e0b',
    '#f97316',
    '#a855f7',
    '#06b6d4',
    '#14b8a6',
    '#10b981',
    '#84cc16',
    '#22c55e',
    '#64748b',
    '#ef4444',
];

function EmptyState({ message }: { message: string }) {
    return (
        <div className="flex h-48 items-center justify-center text-sm text-muted-foreground">
            {message}
        </div>
    );
}

export default function RecruitmentAnalytics({
    timeToHire,
    sourceEffectiveness,
    pipelineConversion,
    openPositions,
    hiringVelocity,
    stageBottlenecks,
    monthlyTrend,
}: Props) {
    const totalCandidates = pipelineConversion.reduce(
        (sum, p) => sum + p.count,
        0,
    );
    const avgTimeToHire =
        timeToHire.length > 0
            ? Math.round(
                  timeToHire.reduce((sum, t) => sum + t.avg_days, 0) /
                      timeToHire.length,
              )
            : 0;
    const totalHired =
        pipelineConversion.find((p) => p.stage === 'hired')?.count ?? 0;
    const conversionRate =
        totalCandidates > 0
            ? Math.round((totalHired / totalCandidates) * 100)
            : 0;
    const activePositions = openPositions.length;

    const activePipeline = pipelineConversion.filter(
        (p) => !['withdrawn', 'rejected'].includes(p.stage),
    );
    const maxPipelineCount = Math.max(...activePipeline.map((p) => p.count), 1);

    const sourceChartData = sourceEffectiveness.map((s, i) => ({
        name: s.source.replace(/_/g, ' '),
        total: s.total,
        hired: s.hired,
        fill: CHART_COLORS[i % CHART_COLORS.length],
    }));

    const sourceDonutData = sourceEffectiveness.map((s, i) => ({
        name: s.source.replace(/_/g, ' '),
        value: s.total,
        fill: CHART_COLORS[i % CHART_COLORS.length],
    }));

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Recruitment', href: '/hr/recruitment' },
                { title: 'Analytics', href: '/hr/recruitment/analytics' },
            ]}
        >
            <Head title="Recruitment Analytics" />
            <PageShell>
                <PageHero category="hr"
                    icon={BarChart3}
                    title="Recruitment Analytics"
                    description="Insights into your recruitment pipeline performance."
                    stats={[
                        { label: 'Total candidates', value: totalCandidates },
                        { label: 'Avg time to hire', value: `${avgTimeToHire}d` },
                        { label: 'Conversion', value: `${conversionRate}%` },
                        { label: 'Open positions', value: activePositions },
                    ]}
                    actions={
                        <Button
                            variant="outline"
                            size="sm"
                            className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            asChild
                        >
                            <Link href="/hr/recruitment">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to Pipeline
                            </Link>
                        </Button>
                    }
                />

                <RecruitmentTabs active="analytics" />

                {/* KPI Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <KpiCard
                        label="Total Candidates"
                        value={totalCandidates}
                        icon={Users}
                        color="bg-status-info-bg text-status-info"
                    />
                    <KpiCard
                        label="Avg Time to Hire"
                        value={avgTimeToHire}
                        icon={Clock}
                        suffix=" days"
                        color="bg-status-warning-bg text-status-warning"
                    />
                    <KpiCard
                        label="Conversion Rate"
                        value={conversionRate}
                        icon={TrendingUp}
                        suffix="%"
                        color="bg-status-success-bg text-status-success"
                    />
                    <KpiCard
                        label="Active Positions"
                        value={activePositions}
                        icon={Briefcase}
                        color="bg-primary/10 text-primary"
                    />
                </div>

                <Tabs defaultValue="overview" className="space-y-6">
                    <TabsList>
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="pipeline">Pipeline</TabsTrigger>
                        <TabsTrigger value="sources">Sources</TabsTrigger>
                        <TabsTrigger value="time">Time Metrics</TabsTrigger>
                    </TabsList>

                    {/* Overview */}
                    <TabsContent value="overview" className="space-y-6">
                        <div className="grid gap-6 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Monthly Applications
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {monthlyTrend.length === 0 ? (
                                        <EmptyState message="No application data yet." />
                                    ) : (
                                        <div className="h-64">
                                            <ResponsiveContainer
                                                width="100%"
                                                height="100%"
                                            >
                                                <AreaChart data={monthlyTrend}>
                                                    <CartesianGrid
                                                        strokeDasharray="3 3"
                                                        className="stroke-muted"
                                                    />
                                                    <XAxis
                                                        dataKey="month"
                                                        className="text-xs"
                                                        tick={{
                                                            fill: 'currentColor',
                                                        }}
                                                    />
                                                    <YAxis
                                                        className="text-xs"
                                                        tick={{
                                                            fill: 'currentColor',
                                                        }}
                                                    />
                                                    <Tooltip />
                                                    <defs>
                                                        <linearGradient
                                                            id="colorApps"
                                                            x1="0"
                                                            y1="0"
                                                            x2="0"
                                                            y2="1"
                                                        >
                                                            <stop
                                                                offset="5%"
                                                                stopColor="#3b82f6"
                                                                stopOpacity={
                                                                    0.3
                                                                }
                                                            />
                                                            <stop
                                                                offset="95%"
                                                                stopColor="#3b82f6"
                                                                stopOpacity={0}
                                                            />
                                                        </linearGradient>
                                                    </defs>
                                                    <Area
                                                        type="monotone"
                                                        dataKey="count"
                                                        stroke="#3b82f6"
                                                        fillOpacity={1}
                                                        fill="url(#colorApps)"
                                                        name="Applications"
                                                    />
                                                </AreaChart>
                                            </ResponsiveContainer>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Hiring Velocity
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {hiringVelocity.length === 0 ? (
                                        <EmptyState message="No hiring data yet." />
                                    ) : (
                                        <div className="h-64">
                                            <ResponsiveContainer
                                                width="100%"
                                                height="100%"
                                            >
                                                <BarChart data={hiringVelocity}>
                                                    <CartesianGrid
                                                        strokeDasharray="3 3"
                                                        className="stroke-muted"
                                                    />
                                                    <XAxis
                                                        dataKey="month"
                                                        className="text-xs"
                                                        tick={{
                                                            fill: 'currentColor',
                                                        }}
                                                    />
                                                    <YAxis
                                                        className="text-xs"
                                                        tick={{
                                                            fill: 'currentColor',
                                                        }}
                                                    />
                                                    <Tooltip />
                                                    <Bar
                                                        dataKey="count"
                                                        fill="#10b981"
                                                        radius={[4, 4, 0, 0]}
                                                        name="Hires"
                                                    />
                                                </BarChart>
                                            </ResponsiveContainer>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Pipeline Funnel
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {activePipeline.length === 0 ? (
                                        <EmptyState message="No pipeline data." />
                                    ) : (
                                        <div className="space-y-2">
                                            {activePipeline.map((entry) => {
                                                const config =
                                                    statusConfig[entry.stage];
                                                return (
                                                    <div
                                                        key={entry.stage}
                                                        className="flex items-center gap-3"
                                                    >
                                                        <span className="w-24 truncate text-xs">
                                                            {stageLabels[
                                                                entry.stage
                                                            ] ?? entry.stage}
                                                        </span>
                                                        <div className="h-6 flex-1 overflow-hidden rounded-full bg-muted/30">
                                                            <div
                                                                className={`flex h-full items-center rounded-full px-2.5 ${config?.bgClass ?? 'bg-muted'}/30`}
                                                                style={{
                                                                    width: `${Math.max((entry.count / maxPipelineCount) * 100, 5)}%`,
                                                                }}
                                                            >
                                                                <span className="text-xs font-medium">
                                                                    {
                                                                        entry.count
                                                                    }
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <span className="w-12 text-right text-xs text-muted-foreground">
                                                            {entry.percentage}%
                                                        </span>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Source Distribution
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {sourceDonutData.length === 0 ? (
                                        <EmptyState message="No source data." />
                                    ) : (
                                        <>
                                            <div className="h-48">
                                                <ResponsiveContainer
                                                    width="100%"
                                                    height="100%"
                                                >
                                                    <PieChart>
                                                        <Pie
                                                            data={
                                                                sourceDonutData
                                                            }
                                                            dataKey="value"
                                                            nameKey="name"
                                                            cx="50%"
                                                            cy="50%"
                                                            innerRadius={40}
                                                            outerRadius={65}
                                                            paddingAngle={2}
                                                        >
                                                            {sourceDonutData.map(
                                                                (entry, i) => (
                                                                    <Cell
                                                                        key={i}
                                                                        fill={
                                                                            entry.fill
                                                                        }
                                                                    />
                                                                ),
                                                            )}
                                                        </Pie>
                                                        <Tooltip />
                                                    </PieChart>
                                                </ResponsiveContainer>
                                            </div>
                                            <div className="mt-2 space-y-1">
                                                {sourceDonutData.map(
                                                    (entry, i) => (
                                                        <div
                                                            key={i}
                                                            className="flex items-center justify-between text-xs"
                                                        >
                                                            <span className="flex items-center gap-2">
                                                                <span
                                                                    className="h-2.5 w-2.5 shrink-0 rounded-full"
                                                                    style={{
                                                                        backgroundColor:
                                                                            entry.fill,
                                                                    }}
                                                                />
                                                                <span className="capitalize">
                                                                    {entry.name}
                                                                </span>
                                                            </span>
                                                            <span className="font-medium">
                                                                {entry.value}
                                                            </span>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        </>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* Pipeline */}
                    <TabsContent value="pipeline" className="space-y-6">
                        <div className="grid gap-6 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Pipeline Conversion
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {pipelineConversion.map((entry, i) => {
                                        const prev =
                                            i > 0
                                                ? pipelineConversion[i - 1]
                                                : null;
                                        const dropOff =
                                            prev && prev.count > 0
                                                ? Math.round(
                                                      ((prev.count -
                                                          entry.count) /
                                                          prev.count) *
                                                          100,
                                                  )
                                                : null;
                                        return (
                                            <div
                                                key={entry.stage}
                                                className="flex items-center gap-3"
                                            >
                                                <span className="w-28 truncate text-xs font-medium">
                                                    {stageLabels[entry.stage] ??
                                                        entry.stage}
                                                </span>
                                                <div className="h-6 flex-1 overflow-hidden rounded-full bg-muted/30">
                                                    <div
                                                        className="flex h-full items-center rounded-full bg-primary/30 px-2.5"
                                                        style={{
                                                            width: `${Math.max(entry.percentage, 3)}%`,
                                                        }}
                                                    >
                                                        <span className="text-xs font-medium">
                                                            {entry.count}
                                                        </span>
                                                    </div>
                                                </div>
                                                <span className="w-10 text-right text-xs text-muted-foreground">
                                                    {entry.percentage}%
                                                </span>
                                                {dropOff !== null &&
                                                    dropOff > 0 && (
                                                        <span className="w-16 text-right text-xs text-status-critical">
                                                            -{dropOff}%
                                                        </span>
                                                    )}
                                            </div>
                                        );
                                    })}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Stage Bottlenecks
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {stageBottlenecks.length === 0 ? (
                                        <EmptyState message="No bottleneck data." />
                                    ) : (
                                        <div className="h-64">
                                            <ResponsiveContainer
                                                width="100%"
                                                height="100%"
                                            >
                                                <BarChart
                                                    data={stageBottlenecks.map(
                                                        (b) => ({
                                                            ...b,
                                                            label:
                                                                stageLabels[
                                                                    b.stage
                                                                ] ?? b.stage,
                                                        }),
                                                    )}
                                                    layout="vertical"
                                                >
                                                    <CartesianGrid
                                                        strokeDasharray="3 3"
                                                        className="stroke-muted"
                                                    />
                                                    <XAxis
                                                        type="number"
                                                        className="text-xs"
                                                        tick={{
                                                            fill: 'currentColor',
                                                        }}
                                                    />
                                                    <YAxis
                                                        type="category"
                                                        dataKey="label"
                                                        width={100}
                                                        className="text-xs"
                                                        tick={{
                                                            fill: 'currentColor',
                                                        }}
                                                    />
                                                    <Tooltip
                                                        formatter={(
                                                            val?: number,
                                                        ) => `${val ?? 0} days`}
                                                    />
                                                    <Bar
                                                        dataKey="avg_days"
                                                        name="Avg Days"
                                                        radius={[0, 4, 4, 0]}
                                                    >
                                                        {stageBottlenecks.map(
                                                            (entry, i) => (
                                                                <Cell
                                                                    key={i}
                                                                    fill={
                                                                        entry.avg_days >
                                                                        14
                                                                            ? '#ef4444'
                                                                            : entry.avg_days >
                                                                                7
                                                                              ? '#f59e0b'
                                                                              : '#10b981'
                                                                    }
                                                                />
                                                            ),
                                                        )}
                                                    </Bar>
                                                </BarChart>
                                            </ResponsiveContainer>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* Sources */}
                    <TabsContent value="sources" className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Source Comparison
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {sourceChartData.length === 0 ? (
                                    <EmptyState message="No source data." />
                                ) : (
                                    <div className="h-72">
                                        <ResponsiveContainer
                                            width="100%"
                                            height="100%"
                                        >
                                            <BarChart data={sourceChartData}>
                                                <CartesianGrid
                                                    strokeDasharray="3 3"
                                                    className="stroke-muted"
                                                />
                                                <XAxis
                                                    dataKey="name"
                                                    className="text-xs"
                                                    tick={{
                                                        fill: 'currentColor',
                                                    }}
                                                />
                                                <YAxis
                                                    className="text-xs"
                                                    tick={{
                                                        fill: 'currentColor',
                                                    }}
                                                />
                                                <Tooltip />
                                                <Legend />
                                                <Bar
                                                    dataKey="total"
                                                    fill="#3b82f6"
                                                    radius={[4, 4, 0, 0]}
                                                    name="Total"
                                                />
                                                <Bar
                                                    dataKey="hired"
                                                    fill="#10b981"
                                                    radius={[4, 4, 0, 0]}
                                                    name="Hired"
                                                />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Source Conversion Rates
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {sourceEffectiveness.length === 0 ? (
                                    <EmptyState message="No source data." />
                                ) : (
                                    <div className="overflow-hidden rounded-lg border">
                                        <table className="w-full text-sm">
                                            <thead className="bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-2 text-left font-medium">
                                                        Source
                                                    </th>
                                                    <th className="px-4 py-2 text-right font-medium">
                                                        Total
                                                    </th>
                                                    <th className="px-4 py-2 text-right font-medium">
                                                        Active
                                                    </th>
                                                    <th className="px-4 py-2 text-right font-medium">
                                                        Hired
                                                    </th>
                                                    <th className="px-4 py-2 text-left font-medium">
                                                        Conversion
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {sourceEffectiveness.map(
                                                    (source) => (
                                                        <tr
                                                            key={source.source}
                                                            className="border-t hover:bg-muted/30"
                                                        >
                                                            <td className="px-4 py-2 font-medium capitalize">
                                                                {source.source.replace(
                                                                    /_/g,
                                                                    ' ',
                                                                )}
                                                            </td>
                                                            <td className="px-4 py-2 text-right">
                                                                {source.total}
                                                            </td>
                                                            <td className="px-4 py-2 text-right">
                                                                {source.active}
                                                            </td>
                                                            <td className="px-4 py-2 text-right">
                                                                {source.hired}
                                                            </td>
                                                            <td className="px-4 py-2">
                                                                <div className="flex items-center gap-2">
                                                                    <div className="h-2 max-w-[100px] flex-1 overflow-hidden rounded-full bg-muted/30">
                                                                        <div
                                                                            className="h-full rounded-full bg-status-success"
                                                                            style={{
                                                                                width: `${source.conversion_rate}%`,
                                                                            }}
                                                                        />
                                                                    </div>
                                                                    <span className="text-xs text-muted-foreground">
                                                                        {
                                                                            source.conversion_rate
                                                                        }
                                                                        %
                                                                    </span>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Time Metrics */}
                    <TabsContent value="time" className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Time to Hire Trend
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {timeToHire.length === 0 ? (
                                    <EmptyState message="No hire data yet." />
                                ) : (
                                    <div className="h-64">
                                        <ResponsiveContainer
                                            width="100%"
                                            height="100%"
                                        >
                                            <LineChart data={timeToHire}>
                                                <CartesianGrid
                                                    strokeDasharray="3 3"
                                                    className="stroke-muted"
                                                />
                                                <XAxis
                                                    dataKey="month"
                                                    className="text-xs"
                                                    tick={{
                                                        fill: 'currentColor',
                                                    }}
                                                />
                                                <YAxis
                                                    className="text-xs"
                                                    tick={{
                                                        fill: 'currentColor',
                                                    }}
                                                />
                                                <Tooltip
                                                    formatter={(val?: number) =>
                                                        `${val ?? 0} days`
                                                    }
                                                />
                                                <Line
                                                    type="monotone"
                                                    dataKey="avg_days"
                                                    stroke="#f59e0b"
                                                    strokeWidth={2}
                                                    dot={{
                                                        fill: '#f59e0b',
                                                        r: 4,
                                                    }}
                                                    name="Avg Days"
                                                />
                                            </LineChart>
                                        </ResponsiveContainer>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Open Positions
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {openPositions.length === 0 ? (
                                    <EmptyState message="No open positions." />
                                ) : (
                                    <div className="overflow-hidden rounded-lg border">
                                        <table className="w-full text-sm">
                                            <thead className="bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-2 text-left font-medium">
                                                        Position
                                                    </th>
                                                    <th className="px-4 py-2 text-right font-medium">
                                                        Applications
                                                    </th>
                                                    <th className="px-4 py-2 text-right font-medium">
                                                        Days Open
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {openPositions.map((pos, i) => (
                                                    <tr
                                                        key={i}
                                                        className="border-t hover:bg-muted/30"
                                                    >
                                                        <td className="px-4 py-2 font-medium">
                                                            {pos.position_title}
                                                        </td>
                                                        <td className="px-4 py-2 text-right">
                                                            <Badge variant="secondary">
                                                                {
                                                                    pos.applications
                                                                }
                                                            </Badge>
                                                        </td>
                                                        <td className="px-4 py-2 text-right">
                                                            <span
                                                                className={
                                                                    pos.days_open >
                                                                    30
                                                                        ? 'font-medium text-status-critical'
                                                                        : pos.days_open >
                                                                            14
                                                                          ? 'text-status-warning'
                                                                          : 'text-muted-foreground'
                                                                }
                                                            >
                                                                {pos.days_open}d
                                                            </span>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </PageShell>
        </AppLayout>
    );
}
