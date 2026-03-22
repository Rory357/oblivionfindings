import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { type BreadcrumbItem } from '@/types';
import { Users, TrendingDown, Clock, ShieldCheck } from 'lucide-react';
import {
    LineChart,
    Line,
    BarChart,
    Bar,
    PieChart,
    Pie,
    Cell,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
} from 'recharts';

type HeadcountPoint = { month: string; count: number };
type TurnoverRate = { rate: number; separations: number; avg_headcount: number };
type TenureBracket = { bracket: string; count: number };
type ComplianceScore = { score: number; compliant: number; total: number };
type LeaveUtilization = { type: string; approved: number; pending: number; declined: number };
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

const PIE_COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];

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
            <div className="flex flex-col gap-6 p-6">
                <h1 className="text-2xl font-bold">Workforce Analytics</h1>

                {/* KPI Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Headcount</CardTitle>
                            <Users className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{currentHeadcount}</p>
                            <p className="text-xs text-muted-foreground">Active employees</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Turnover Rate</CardTitle>
                            <TrendingDown className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{turnoverRate.rate}%</p>
                            <p className="text-xs text-muted-foreground">
                                {turnoverRate.separations} separations (12 months)
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Avg Tenure</CardTitle>
                            <Clock className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{avgTenure}</p>
                            <p className="text-xs text-muted-foreground">Average employee tenure</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Compliance</CardTitle>
                            <ShieldCheck className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{complianceScore.score}%</p>
                            <p className="text-xs text-muted-foreground">
                                {complianceScore.compliant} of {complianceScore.total} compliant
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Charts Row */}
                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Headcount Trend Line Chart */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Headcount Trend</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart data={headcountTrend}>
                                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                        <XAxis
                                            dataKey="month"
                                            tick={{ fontSize: 11 }}
                                            className="fill-muted-foreground"
                                        />
                                        <YAxis tick={{ fontSize: 11 }} className="fill-muted-foreground" />
                                        <Tooltip />
                                        <Line
                                            type="monotone"
                                            dataKey="count"
                                            stroke="#3b82f6"
                                            strokeWidth={2}
                                            dot={{ r: 3 }}
                                            name="Employees"
                                        />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Department Breakdown Bar Chart */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Department Breakdown</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={departmentBreakdown} layout="vertical">
                                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                        <XAxis type="number" tick={{ fontSize: 11 }} className="fill-muted-foreground" />
                                        <YAxis
                                            type="category"
                                            dataKey="department"
                                            width={120}
                                            tick={{ fontSize: 11 }}
                                            className="fill-muted-foreground"
                                        />
                                        <Tooltip />
                                        <Bar dataKey="count" fill="#3b82f6" radius={[0, 4, 4, 0]} name="Employees" />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Tenure Distribution Pie Chart */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Tenure Distribution</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <PieChart>
                                        <Pie
                                            data={tenureBrackets}
                                            dataKey="count"
                                            nameKey="bracket"
                                            cx="50%"
                                            cy="50%"
                                            outerRadius={90}
                                            label={({ bracket, count }) => `${bracket}: ${count}`}
                                        >
                                            {tenureBrackets.map((_, index) => (
                                                <Cell key={index} fill={PIE_COLORS[index % PIE_COLORS.length]} />
                                            ))}
                                        </Pie>
                                        <Tooltip />
                                    </PieChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Leave Utilization */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Leave Utilization (This Year)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {leaveUtilization.length > 0 ? (
                                <div className="space-y-3">
                                    {leaveUtilization.map((item) => (
                                        <div key={item.type} className="flex items-center justify-between">
                                            <span className="text-sm font-medium capitalize">
                                                {item.type.replace(/_/g, ' ')}
                                            </span>
                                            <div className="flex gap-2">
                                                <Badge variant="outline" className="border-emerald-500/30 bg-emerald-500/10 text-emerald-400">
                                                    {item.approved} approved
                                                </Badge>
                                                <Badge variant="outline" className="border-yellow-500/30 bg-yellow-500/10 text-yellow-400">
                                                    {item.pending} pending
                                                </Badge>
                                                <Badge variant="outline" className="border-red-500/30 bg-red-500/10 text-red-400">
                                                    {item.declined} declined
                                                </Badge>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="py-8 text-center text-sm text-muted-foreground">No leave data this year.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
