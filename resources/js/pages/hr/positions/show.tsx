import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Briefcase, Edit, GitBranch, Users } from 'lucide-react';

type Employee = {
    id: number;
    name: string;
    email: string | null;
    position_title: string | null;
};

type LinkedPosition = {
    id: number;
    title: string;
    code: string;
};

type Position = {
    id: number;
    title: string;
    code: string;
    department: string | null;
    team: string | null;
    description: string | null;
    requirements: string | null;
    employment_type: string;
    fte: number;
    headcount_budget: number;
    current_headcount: number;
    is_active: boolean;
    reports_to: LinkedPosition | null;
    direct_reports: LinkedPosition[];
    employees: Employee[];
};

type Props = {
    position: Position;
    can: {
        manage?: boolean;
    };
};

const employmentTypeLabels: Record<string, string> = {
    full_time: 'Full Time',
    part_time: 'Part Time',
    casual: 'Casual',
    fixed_term: 'Fixed Term',
};

export default function ShowPosition({ position, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Positions', href: '/hr/positions' },
        { title: position.title, href: `/hr/positions/${position.id}` },
    ];

    const vacancies = Math.max(
        0,
        position.headcount_budget - position.current_headcount,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={position.title} />
            <div className="mx-auto flex max-w-4xl flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <Link href="/hr/positions">
                            <Button variant="outline" size="icon">
                                <ArrowLeft className="h-4 w-4" />
                            </Button>
                        </Link>
                        <div>
                            <h1 className="text-2xl font-bold">
                                {position.title}
                            </h1>
                            <p className="font-mono text-sm text-muted-foreground">
                                {position.code}
                            </p>
                        </div>
                        {position.is_active ? (
                            <Badge
                                variant="outline"
                                className="border-status-success/30 bg-status-success text-status-success"
                            >
                                Active
                            </Badge>
                        ) : (
                            <Badge
                                variant="outline"
                                className="border-status-critical/30 bg-status-critical text-status-critical"
                            >
                                Inactive
                            </Badge>
                        )}
                    </div>
                    {can.manage && (
                        <Button asChild>
                            <Link href={`/hr/positions/${position.id}/edit`}>
                                <Edit className="mr-2 h-4 w-4" />
                                Edit Position
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="grid gap-6 md:grid-cols-3">
                    {/* Main Details */}
                    <Card className="md:col-span-2">
                        <CardHeader>
                            <CardTitle>Position Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Department
                                    </p>
                                    <p className="font-medium">
                                        {position.department ?? '-'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Team
                                    </p>
                                    <p className="font-medium">
                                        {position.team ?? '-'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Employment Type
                                    </p>
                                    <p className="font-medium">
                                        {employmentTypeLabels[
                                            position.employment_type
                                        ] ?? position.employment_type}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        FTE
                                    </p>
                                    <p className="font-medium">
                                        {position.fte}
                                    </p>
                                </div>
                            </div>

                            {position.description && (
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Description
                                    </p>
                                    <p className="mt-1 text-sm whitespace-pre-wrap">
                                        {position.description}
                                    </p>
                                </div>
                            )}

                            {position.requirements && (
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Requirements
                                    </p>
                                    <p className="mt-1 text-sm whitespace-pre-wrap">
                                        {position.requirements}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Headcount Card */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Users className="h-4 w-4" />
                                Headcount
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Budget
                                </p>
                                <p className="text-2xl font-bold">
                                    {position.headcount_budget}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Current
                                </p>
                                <p className="text-2xl font-bold">
                                    {position.current_headcount}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Vacancies
                                </p>
                                <p className="text-2xl font-bold">
                                    {vacancies > 0 ? (
                                        <span className="text-status-info">
                                            {vacancies}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            0
                                        </span>
                                    )}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Reporting Structure */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <GitBranch className="h-4 w-4" />
                            Reporting Structure
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div>
                            <p className="mb-2 text-sm text-muted-foreground">
                                Reports To
                            </p>
                            {position.reports_to ? (
                                <Link
                                    href={`/hr/positions/${position.reports_to.id}`}
                                    className="inline-flex items-center gap-2 text-sm font-medium hover:underline"
                                >
                                    <Briefcase className="h-3.5 w-3.5" />
                                    {position.reports_to.title} (
                                    {position.reports_to.code})
                                </Link>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No parent position
                                </p>
                            )}
                        </div>

                        {position.direct_reports.length > 0 && (
                            <div>
                                <p className="mb-2 text-sm text-muted-foreground">
                                    Direct Report Positions (
                                    {position.direct_reports.length})
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    {position.direct_reports.map((report) => (
                                        <Link
                                            key={report.id}
                                            href={`/hr/positions/${report.id}`}
                                            className="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm transition-colors hover:bg-muted/50"
                                        >
                                            <Briefcase className="h-3 w-3" />
                                            {report.title}
                                            <span className="font-mono text-xs text-muted-foreground">
                                                {report.code}
                                            </span>
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Employees in Position */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Users className="h-4 w-4" />
                            Employees ({position.employees.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {position.employees.length === 0 ? (
                            <div className="py-8 text-center text-muted-foreground">
                                <Users className="mx-auto mb-2 h-10 w-10 opacity-50" />
                                <p>No employees assigned to this position.</p>
                            </div>
                        ) : (
                            <div className="overflow-hidden rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-muted/5">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Name
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Email
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Position Title
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {position.employees.map((employee) => (
                                            <tr
                                                key={employee.id}
                                                className="border-b last:border-b-0 hover:bg-muted/50"
                                            >
                                                <td className="px-4 py-3 font-medium">
                                                    {employee.name}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {employee.email ?? '-'}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {employee.position_title ??
                                                        '-'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
