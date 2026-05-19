import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { AlertTriangle, Briefcase, Users } from 'lucide-react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type Props = {
    current: {
        total: number;
        by_department: Record<string, number>;
        by_employment_type: Record<string, number>;
        total_fte: number;
    };
    budgetVsActual: {
        positions: Array<{
            id: number;
            title: string;
            department: string;
            budgeted: number;
            filled: number;
            vacant: number;
            fill_rate: number;
        }>;
        total_budgeted: number;
        total_filled: number;
        total_vacant: number;
    };
    forecast: Array<{ month: string; projected: number; current: number }>;
    attritionRisk: Array<{
        id: number;
        name: string;
        position: string;
        department: string;
        tenure_months: number;
        milestone: string;
        risk_level: string;
    }>;
};

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Headcount Planning', href: '/hr/headcount' },
];

export default function HeadcountIndex({
    current,
    budgetVsActual,
    forecast,
    attritionRisk,
}: Props) {
    const deptData = Object.entries(current.by_department).map(
        ([name, count]) => ({ name, count }),
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Headcount Planning" />
            <PageShell>
                <PageHero
                    icon={Users}
                    title="Headcount Planning"
                    description="Workforce planning, forecasting, and attrition analysis."
                    stats={[
                        { label: 'Headcount', value: current.total },
                        { label: 'Total FTE', value: current.total_fte },
                        { label: 'Vacancies', value: budgetVsActual.total_vacant },
                        { label: 'Attrition risk', value: attritionRisk.length },
                    ]}
                />
                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-1 text-sm text-muted-foreground">
                                <Users className="h-4 w-4" />
                                Headcount
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">
                                {current.total}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Active employees
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">
                                Total FTE
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">
                                {current.total_fte}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-1 text-sm text-muted-foreground">
                                <Briefcase className="h-4 w-4" />
                                Vacancies
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">
                                {budgetVsActual.total_vacant}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                of {budgetVsActual.total_budgeted} budgeted
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-1 text-sm text-muted-foreground">
                                <AlertTriangle className="h-4 w-4" />
                                Attrition Risk
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">
                                {attritionRisk.length}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                At milestone points
                            </p>
                        </CardContent>
                    </Card>
                </div>
                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Headcount by Department</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ResponsiveContainer width="100%" height={250}>
                                <BarChart data={deptData}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="name" fontSize={12} />
                                    <YAxis />
                                    <Tooltip />
                                    <Bar
                                        dataKey="count"
                                        fill="#6366f1"
                                        radius={[4, 4, 0, 0]}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>12-Month Forecast</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ResponsiveContainer width="100%" height={250}>
                                <LineChart data={forecast}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="month" fontSize={12} />
                                    <YAxis />
                                    <Tooltip />
                                    <Line
                                        type="monotone"
                                        dataKey="projected"
                                        stroke="#6366f1"
                                        strokeWidth={2}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="current"
                                        stroke="#94a3b8"
                                        strokeDasharray="5 5"
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Budget vs Actual</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left">
                                            Position
                                        </th>
                                        <th className="px-4 py-3 text-left">
                                            Department
                                        </th>
                                        <th className="px-4 py-3 text-center">
                                            Budgeted
                                        </th>
                                        <th className="px-4 py-3 text-center">
                                            Filled
                                        </th>
                                        <th className="px-4 py-3 text-center">
                                            Vacant
                                        </th>
                                        <th className="px-4 py-3 text-center">
                                            Fill Rate
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {budgetVsActual.positions.map((p) => (
                                        <tr key={p.id} className="border-b">
                                            <td className="px-4 py-3 font-medium">
                                                {p.title}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {p.department}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                {p.budgeted}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                {p.filled}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                {p.vacant > 0 ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-status-warning/30 text-status-warning"
                                                    >
                                                        {p.vacant}
                                                    </Badge>
                                                ) : (
                                                    '0'
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                {p.fill_rate}%
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
                {attritionRisk.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Attrition Risk</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left">
                                            Employee
                                        </th>
                                        <th className="px-4 py-3 text-left">
                                            Position
                                        </th>
                                        <th className="px-4 py-3 text-center">
                                            Tenure
                                        </th>
                                        <th className="px-4 py-3 text-center">
                                            Milestone
                                        </th>
                                        <th className="px-4 py-3 text-center">
                                            Risk
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {attritionRisk.map((r, i) => (
                                        <tr key={i} className="border-b">
                                            <td className="px-4 py-3 font-medium">
                                                {r.name}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {r.position}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                {r.tenure_months}mo
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                {r.milestone}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        r.risk_level === 'high'
                                                            ? 'border-status-critical/30 text-status-critical'
                                                            : r.risk_level ===
                                                                'medium'
                                                              ? 'border-status-warning/30 text-status-warning'
                                                              : 'border-status-success/30 text-status-success'
                                                    }
                                                >
                                                    {r.risk_level}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
