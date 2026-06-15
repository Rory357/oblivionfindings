import { ReportsTabs } from '@/components/hr';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import {
    BarChart2,
    BarChart3,
    Clock,
    ShieldCheck,
    TrendingDown,
    Users,
} from 'lucide-react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Line,
    LineChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type HeadcountPoint = { month: string; count: number };
type TurnoverRate = {
    rate: number;
    separations: number;
    avg_headcount: number;
};
type TenureBracket = { bracket: string; count: number };
type ComplianceScore = { score: number; compliant: number; total: number };
type LeaveUtilization = {
    type: string;
    approved: number;
    pending: number;
    declined: number;
};
type DepartmentBreakdown = { department: string; count: number };

type Props = {
    headcountTrend: HeadcountPoint[];
    currentHeadcount: number;
    turnoverRate: TurnoverRate;
    tenureBrackets: TenureBracket[];
    avgTenure: string;
    complianceScore: ComplianceScore;
    leaveUtilization: LeaveUtilization[];
    departmentBreakdown: DepartmentBreakdown[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Analytics', href: '/hr/analytics' },
];

/**
 * Chart colour palette using CSS custom properties so charts respect the active
 * theme.  The tokens map to Tailwind's default palette via hsl() values set on
 * :root / .dark in the global stylesheet.  We fall back to hard-coded hex values
 * that match Tailwind's default blue-500, emerald-500, amber-500, etc. so charts
 * still render correctly if the custom properties are absent.
 */
const CHART_COLORS = [
    'hsl(var(--chart-1, 217 91% 60%))', // blue-500
    'hsl(var(--chart-2, 160 84% 39%))', // emerald-500
    'hsl(var(--chart-3, 38 92% 50%))', // amber-500
    'hsl(var(--chart-4, 0 84% 60%))', // red-500
    'hsl(var(--chart-5, 258 90% 66%))', // violet-500
    'hsl(var(--chart-6, 330 81% 60%))', // pink-500
    'hsl(var(--chart-7, 189 94% 43%))', // cyan-500
    'hsl(var(--chart-8, 84 81% 44%))', // lime-500
];

function ChartEmptyState({ message }: { message: string }) {
    return (
        <div className="flex h-64 flex-col items-center justify-center gap-2 text-muted-foreground">
            <BarChart2 className="h-10 w-10 opacity-30" />
            <p className="text-sm">{message}</p>
        </div>
    );
}

export default function AnalyticsDashboard({
    headcountTrend,
    currentHeadcount,
    turnoverRate,
    tenureBrackets,
    avgTenure,
    complianceScore,
    leaveUtilization,
    departmentBreakdown,
}: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Workforce Analytics" />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={BarChart3}
                        title="Workforce Analytics"
                        description="Showing last 12 months of workforce trends and compliance metrics."
                        stats={[
                            { label: 'Headcount', value: currentHeadcount },
                            { label: 'Turnover', value: `${turnoverRate.rate}%` },
                            { label: 'Avg Tenure', value: avgTenure },
                            { label: 'Compliance', value: `${complianceScore.score}%` },
                        ]}
                        actions={
                            <Badge
                                variant="outline"
                                className="border-primary-foreground/30 bg-primary-foreground/10 text-xs font-normal text-primary-foreground backdrop-blur-sm"
                            >
                                Showing last 12 months
                            </Badge>
                        }
                    />
                }
            >
                <ReportsTabs active="analytics" />
                {/* KPI Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Headcount
                            </CardTitle>
                            <Users className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">
                                {currentHeadcount}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Active employees
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Turnover Rate
                            </CardTitle>
                            <TrendingDown className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">
                                {turnoverRate.rate}%
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {turnoverRate.separations} separations (12
                                months)
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Avg Tenure
                            </CardTitle>
                            <Clock className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{avgTenure}</p>
                            <p className="text-xs text-muted-foreground">
                                Average employee tenure
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Compliance
                            </CardTitle>
                            <ShieldCheck className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">
                                {complianceScore.score}%
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {complianceScore.compliant} of{' '}
                                {complianceScore.total} compliant
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Charts Row */}
                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Headcount Trend Line Chart */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Headcount Trend
                            </CardTitle>
                            <CardDescription>
                                Monthly active employee count
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {headcountTrend.length > 0 ? (
                                <div className="h-64">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <LineChart data={headcountTrend}>
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                className="stroke-muted"
                                            />
                                            <XAxis
                                                dataKey="month"
                                                tick={{ fontSize: 11 }}
                                                className="fill-muted-foreground"
                                            />
                                            <YAxis
                                                tick={{ fontSize: 11 }}
                                                className="fill-muted-foreground"
                                            />
                                            <Tooltip />
                                            <Line
                                                type="monotone"
                                                dataKey="count"
                                                stroke={CHART_COLORS[0]}
                                                strokeWidth={2}
                                                dot={{ r: 3 }}
                                                name="Employees"
                                            />
                                        </LineChart>
                                    </ResponsiveContainer>
                                </div>
                            ) : (
                                <ChartEmptyState message="No headcount data recorded yet." />
                            )}
                        </CardContent>
                    </Card>

                    {/* Department Breakdown Bar Chart */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Department Breakdown
                            </CardTitle>
                            <CardDescription>
                                Employees per department
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {departmentBreakdown.length > 0 ? (
                                <div className="h-64">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <BarChart
                                            data={departmentBreakdown}
                                            layout="vertical"
                                        >
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                className="stroke-muted"
                                            />
                                            <XAxis
                                                type="number"
                                                tick={{ fontSize: 11 }}
                                                className="fill-muted-foreground"
                                            />
                                            <YAxis
                                                type="category"
                                                dataKey="department"
                                                width={120}
                                                tick={{ fontSize: 11 }}
                                                className="fill-muted-foreground"
                                            />
                                            <Tooltip />
                                            <Bar
                                                dataKey="count"
                                                fill={CHART_COLORS[0]}
                                                radius={[0, 4, 4, 0]}
                                                name="Employees"
                                            />
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            ) : (
                                <ChartEmptyState message="No department data available." />
                            )}
                        </CardContent>
                    </Card>

                    {/* Tenure Distribution Pie Chart */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Tenure Distribution
                            </CardTitle>
                            <CardDescription>
                                Years of service breakdown
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {tenureBrackets.length > 0 ? (
                                <div className="h-64">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <PieChart>
                                            <Pie
                                                data={tenureBrackets}
                                                dataKey="count"
                                                nameKey="bracket"
                                                cx="50%"
                                                cy="50%"
                                                outerRadius={90}
                                                label={(props: any) =>
                                                    `${props.name}: ${props.value}`
                                                }
                                            >
                                                {tenureBrackets.map(
                                                    (_, index) => (
                                                        <Cell
                                                            key={index}
                                                            fill={
                                                                CHART_COLORS[
                                                                    index %
                                                                        CHART_COLORS.length
                                                                ]
                                                            }
                                                        />
                                                    ),
                                                )}
                                            </Pie>
                                            <Tooltip />
                                        </PieChart>
                                    </ResponsiveContainer>
                                </div>
                            ) : (
                                <ChartEmptyState message="No tenure data available." />
                            )}
                        </CardContent>
                    </Card>

                    {/* Leave Utilization */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Leave Utilization
                            </CardTitle>
                            <CardDescription>
                                Requests by type for the current year
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {leaveUtilization.length > 0 ? (
                                <div className="space-y-3">
                                    {leaveUtilization.map((item) => (
                                        <div
                                            key={item.type}
                                            className="flex items-center justify-between"
                                        >
                                            <span className="text-sm font-medium capitalize">
                                                {item.type.replace(/_/g, ' ')}
                                            </span>
                                            <div className="flex gap-2">
                                                <Badge
                                                    variant="outline"
                                                    className="border-status-success/30 bg-status-success-bg text-status-success"
                                                >
                                                    {item.approved} approved
                                                </Badge>
                                                <Badge
                                                    variant="outline"
                                                    className="border-status-warning/30 bg-status-warning-bg text-status-warning"
                                                >
                                                    {item.pending} pending
                                                </Badge>
                                                <Badge
                                                    variant="outline"
                                                    className="border-status-critical/30 bg-status-critical-bg text-status-critical"
                                                >
                                                    {item.declined} declined
                                                </Badge>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="py-8 text-center text-sm text-muted-foreground">
                                    No leave data this year.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
