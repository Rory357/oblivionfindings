import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ReportsTabs } from '@/components/hr';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Briefcase, FilePlus2, Users } from 'lucide-react';
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
        by_department: Array<{ department: string; count: number }>;
        by_employment_type: Array<{ type: string; count: number }>;
        fte_total: number;
    };
    budgetVsActual: {
        by_position: Array<{
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
    can?: { view_recruitment?: boolean };
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
    can,
}: Props) {
    const deptData = current.by_department.map((d) => ({
        name: d.department,
        count: d.count,
    }));
    const canRecruit = !!can?.view_recruitment;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Headcount Planning" />
            <PageShell>
                <PageHero category="hr"
                    icon={Users}
                    title="Headcount Planning"
                    description="Workforce planning, forecasting, and attrition analysis."
                    stats={[
                        { label: 'Headcount', value: current.total },
                        { label: 'Total FTE', value: current.fte_total },
                        {
                            label: 'Vacancies',
                            value: budgetVsActual.total_vacant,
                            tone: budgetVsActual.total_vacant > 0 ? 'warning' : 'neutral',
                        },
                        {
                            label: 'Attrition risk',
                            value: attritionRisk.length,
                            tone: attritionRisk.length > 0 ? 'critical' : 'neutral',
                        },
                    ]}
                    actions={
                        canRecruit ? (
                            <Button asChild variant="secondary">
                                <Link href="/hr/recruitment?tab=requisitions">
                                    <Briefcase className="mr-2 h-4 w-4" />
                                    Open recruitment
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />
                <ReportsTabs active="headcount" />
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
                                {current.fte_total}
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
                                        fill="var(--primary)"
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
                                        stroke="var(--primary)"
                                        strokeWidth={2}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="current"
                                        stroke="var(--muted-foreground)"
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
                                        {canRecruit && (
                                            <th className="px-4 py-3 text-right">
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {budgetVsActual.by_position.map((p) => (
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
                                            {canRecruit && (
                                                <td className="px-4 py-3 text-right">
                                                    {p.vacant > 0 && (
                                                        <Button
                                                            asChild
                                                            variant="outline"
                                                            size="sm"
                                                        >
                                                            {/* Requisition creation lives in the Recruitment
                                                                hub wizard; it doesn't read prefill params, so
                                                                this deep-links to the Requisitions tab. */}
                                                            <Link href="/hr/recruitment?tab=requisitions">
                                                                <FilePlus2 className="mr-1.5 h-3.5 w-3.5" />
                                                                Create requisition
                                                            </Link>
                                                        </Button>
                                                    )}
                                                </td>
                                            )}
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
