import { CompensationHero, CompensationTabs, type CompensationHeroStats } from '@/components/hr';
import { BenefitsEnrollDialog } from '@/components/hr/benefits-enroll-dialog';
import { PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Heart, Pencil, Plus, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

interface BenefitPlan {
    id: number;
    name: string;
    type: string;
    employer_contribution_rate?: string | number | null;
}

interface Enrollment {
    id: number;
    enrollment_date: string;
    status: string;
    employee_contribution_rate: string;
    employer_contribution_rate: string;
    opt_out_date: string | null;
    notes: string | null;
    employee_profile: {
        id: number;
        user: { id: number; name: string };
    };
    benefit_plan: BenefitPlan;
}

interface PlanSummaryItem {
    plan_name: string;
    enrolled_count: number;
    avg_employee_rate: number;
    avg_employer_rate: number;
}

interface Summary {
    [type: string]: {
        total_enrolled: number;
        plans: PlanSummaryItem[];
    };
}

interface Employee {
    id: number;
    user: { id: number; name: string } | null;
    position_title: string | null;
}

interface Props {
    enrollments: { data: Enrollment[]; links: any[] };
    plans: BenefitPlan[];
    employees: Employee[];
    summary: Summary;
    filters: { status: string | null; plan_id: string | null };
    stats: CompensationHeroStats;
    annualSalaryByProfileId?: Record<number, number | string | null>;
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Benefits', href: '/hr/compensation/benefits' },
    { title: 'Enrollments', href: '/hr/compensation/benefits' },
];

const statusColors: Record<string, string> = {
    active: 'bg-status-success-bg text-status-success',
    opted_out: 'bg-muted text-foreground',
    suspended: 'bg-status-warning-bg text-status-warning',
    terminated: 'bg-status-critical-bg text-status-critical',
};

const typeLabels: Record<string, string> = {
    kiwisaver: 'KiwiSaver',
    health_insurance: 'Health Insurance',
    life_insurance: 'Life Insurance',
    other: 'Other',
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

export default function BenefitsIndex({
    enrollments,
    plans,
    employees,
    summary,
    filters,
    stats,
    annualSalaryByProfileId,
    can,
}: Props) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Enrollment | null>(null);

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/compensation/benefits',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    const totalEnrolled = Object.values(summary).reduce(
        (sum, data) => sum + data.total_enrolled,
        0,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Benefits Enrollments" />

            <PageLayout hero={<CompensationHero stats={stats} />}>
                <CompensationTabs active="benefits" />

                {can.manage ? (
                    <div className="flex justify-end">
                        <Button size="sm" onClick={() => setOpen(true)}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            Enroll employee
                        </Button>
                    </div>
                ) : null}

                {/* Summary Cards */}
                {Object.keys(summary).length > 0 && (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {Object.entries(summary).map(([type, data]) => (
                            <Card key={type}>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium text-muted-foreground">
                                        {typeLabels[type] || type}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-2xl font-bold">
                                        {data.total_enrolled}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        active enrollments
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Status
                            </Label>
                            <Select
                                value={filters.status || 'all'}
                                onValueChange={(val) =>
                                    onFilter({
                                        status: val === 'all' ? null : val,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Statuses
                                    </SelectItem>
                                    <SelectItem value="active">
                                        Active
                                    </SelectItem>
                                    <SelectItem value="opted_out">
                                        Opted Out
                                    </SelectItem>
                                    <SelectItem value="suspended">
                                        Suspended
                                    </SelectItem>
                                    <SelectItem value="terminated">
                                        Terminated
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Benefit Plan
                            </Label>
                            <Select
                                value={filters.plan_id || 'all'}
                                onValueChange={(val) =>
                                    onFilter({
                                        plan_id: val === 'all' ? null : val,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All plans" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Plans
                                    </SelectItem>
                                    {plans.map((plan) => (
                                        <SelectItem
                                            key={plan.id}
                                            value={String(plan.id)}
                                        >
                                            {plan.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Enrollments Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Plan</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Employee Rate</TableHead>
                                    <TableHead>Employer Rate</TableHead>
                                    <TableHead>Enrolled</TableHead>
                                    <TableHead>Status</TableHead>
                                    {can.manage && (
                                        <TableHead className="text-right">
                                            Actions
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {enrollments.data.map((enrollment) => (
                                    <TableRow key={enrollment.id}>
                                        <TableCell className="font-medium">
                                            {enrollment.employee_profile?.user
                                                ?.name ?? '-'}
                                        </TableCell>
                                        <TableCell>
                                            {enrollment.benefit_plan?.name}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline">
                                                {typeLabels[
                                                    enrollment.benefit_plan
                                                        ?.type
                                                ] ||
                                                    enrollment.benefit_plan
                                                        ?.type}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {
                                                enrollment.employee_contribution_rate
                                            }
                                            %
                                        </TableCell>
                                        <TableCell>
                                            {
                                                enrollment.employer_contribution_rate
                                            }
                                            %
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {formatDate(
                                                enrollment.enrollment_date,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[enrollment.status] ?? ''}`}
                                            >
                                                {enrollment.status.replace(
                                                    '_',
                                                    ' ',
                                                )}
                                            </span>
                                        </TableCell>
                                        {can.manage && (
                                            <TableCell className="text-right">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        setEditing(enrollment)
                                                    }
                                                >
                                                    <Pencil className="mr-1 h-3 w-3" />
                                                    Edit
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                                {!enrollments.data.length && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={can.manage ? 8 : 7}
                                            className="py-12 text-center"
                                        >
                                            <div className="flex flex-col items-center gap-2">
                                                <ShieldCheck className="h-10 w-10 text-muted-foreground" />
                                                <p className="text-sm font-medium text-muted-foreground">
                                                    No enrollments found
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {filters.status ||
                                                    filters.plan_id
                                                        ? 'Try adjusting your filters to see more results.'
                                                        : 'Get started by enrolling an employee in a benefit plan.'}
                                                </p>
                                                {can.manage &&
                                                    !filters.status &&
                                                    !filters.plan_id && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="mt-2"
                                                            onClick={() =>
                                                                setOpen(true)
                                                            }
                                                        >
                                                            <Plus className="mr-1.5 h-4 w-4" />
                                                            Enroll Employee
                                                        </Button>
                                                    )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {enrollments?.links?.length ? (
                    <LaravelPagination links={enrollments.links} />
                ) : null}
            </PageLayout>

            {/* Guided enroll wizard (create) */}
            <BenefitsEnrollDialog
                open={open}
                onClose={() => setOpen(false)}
                plans={plans}
                employees={employees}
                annualSalaryByProfileId={annualSalaryByProfileId}
            />

            {/* Guided enroll wizard (edit existing enrollment) */}
            <BenefitsEnrollDialog
                open={editing !== null}
                onClose={() => setEditing(null)}
                plans={plans}
                employees={employees}
                annualSalaryByProfileId={annualSalaryByProfileId}
                edit={editing}
            />
        </AppLayout>
    );
}
