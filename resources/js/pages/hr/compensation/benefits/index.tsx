import { CompensationTabs } from '@/components/hr';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Heart, Pencil, Plus, ShieldCheck } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface BenefitPlan {
    id: number;
    name: string;
    type: string;
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

const todayIso = () => new Date().toISOString().slice(0, 10);

const emptyEnrollForm = {
    employee_profile_id: '',
    benefit_plan_id: '',
    enrollment_date: todayIso(),
    employee_contribution_rate: '',
    employer_contribution_rate: '',
    notes: '',
};

const emptyEditForm = {
    status: 'active',
    employee_contribution_rate: '',
    employer_contribution_rate: '',
    notes: '',
};

export default function BenefitsIndex({
    enrollments,
    plans,
    employees,
    summary,
    filters,
    can,
}: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState(emptyEnrollForm);
    const [editId, setEditId] = useState<number | null>(null);
    const [editForm, setEditForm] = useState(emptyEditForm);

    const fieldError = (field: string) =>
        errors?.[field] ? (
            <p className="mt-1 text-xs text-status-critical">{errors[field]}</p>
        ) : null;

    const openEdit = (enrollment: Enrollment) => {
        setEditId(enrollment.id);
        setEditForm({
            status: enrollment.status,
            employee_contribution_rate: enrollment.employee_contribution_rate ?? '',
            employer_contribution_rate: enrollment.employer_contribution_rate ?? '',
            notes: enrollment.notes ?? '',
        });
    };

    const setEdit = (key: string, value: string) =>
        setEditForm((prev) => ({ ...prev, [key]: value }));

    const submitEdit = (e: FormEvent) => {
        e.preventDefault();
        if (editId === null) return;
        router.put(`/hr/compensation/benefits/enrollments/${editId}`, editForm, {
            preserveScroll: true,
            onSuccess: () => setEditId(null),
        });
    };

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/compensation/benefits',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.post('/hr/compensation/benefits/enroll', form, {
            onSuccess: () => {
                setOpen(false);
                setForm(emptyEnrollForm);
            },
        });
    };

    const set = (key: string, value: string) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const totalEnrolled = Object.values(summary).reduce(
        (sum, data) => sum + data.total_enrolled,
        0,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Benefits Enrollments" />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={Heart}
                        title="Benefits Enrollments"
                        description="Overview of employee benefit plan enrollments"
                        stats={[
                            { label: 'Total enrolled', value: totalEnrolled },
                            { label: 'Plans', value: plans.length },
                            {
                                label: 'Active',
                                value: enrollments.data.filter((e) => e.status === 'active').length,
                            },
                        ]}
                        actions={
                            can.manage && (
                                <Button size="sm" onClick={() => setOpen(true)}>
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Enroll Employee
                                </Button>
                            )
                        }
                    />
                }
            >
                <CompensationTabs active="benefits" />

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
                                                        openEdit(enrollment)
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

            {/* Enroll Dialog */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            Enroll Employee in Benefit Plan
                        </DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <Label htmlFor="employee_profile_id">Employee</Label>
                            <Select
                                value={form.employee_profile_id}
                                onValueChange={(val) =>
                                    set('employee_profile_id', val)
                                }
                            >
                                <SelectTrigger id="employee_profile_id">
                                    <SelectValue placeholder="Select an employee" />
                                </SelectTrigger>
                                <SelectContent>
                                    {employees.map((emp) => (
                                        <SelectItem
                                            key={emp.id}
                                            value={String(emp.id)}
                                        >
                                            {emp.user?.name ??
                                                `Profile #${emp.id}`}
                                            {emp.position_title
                                                ? ` — ${emp.position_title}`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {fieldError('employee_profile_id')}
                        </div>
                        <div>
                            <Label htmlFor="benefit_plan_id">
                                Benefit Plan
                            </Label>
                            <Select
                                value={form.benefit_plan_id}
                                onValueChange={(val) =>
                                    set('benefit_plan_id', val)
                                }
                            >
                                <SelectTrigger id="benefit_plan_id">
                                    <SelectValue placeholder="Select a plan" />
                                </SelectTrigger>
                                <SelectContent>
                                    {plans.map((plan) => (
                                        <SelectItem
                                            key={plan.id}
                                            value={String(plan.id)}
                                        >
                                            {plan.name} (
                                            {typeLabels[plan.type] || plan.type}
                                            )
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {fieldError('benefit_plan_id')}
                        </div>
                        <div>
                            <Label htmlFor="enrollment_date">
                                Enrollment Date
                            </Label>
                            <Input
                                id="enrollment_date"
                                type="date"
                                value={form.enrollment_date}
                                onChange={(e) =>
                                    set('enrollment_date', e.target.value)
                                }
                                required
                            />
                            {fieldError('enrollment_date')}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="employee_contribution_rate">
                                    Employee Rate (%)
                                </Label>
                                <Input
                                    id="employee_contribution_rate"
                                    type="number"
                                    step="0.01"
                                    value={form.employee_contribution_rate}
                                    onChange={(e) =>
                                        set(
                                            'employee_contribution_rate',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                                {fieldError('employee_contribution_rate')}
                            </div>
                            <div>
                                <Label htmlFor="employer_contribution_rate">
                                    Employer Rate (%)
                                </Label>
                                <Input
                                    id="employer_contribution_rate"
                                    type="number"
                                    step="0.01"
                                    value={form.employer_contribution_rate}
                                    onChange={(e) =>
                                        set(
                                            'employer_contribution_rate',
                                            e.target.value,
                                        )
                                    }
                                />
                                {fieldError('employer_contribution_rate')}
                            </div>
                        </div>
                        <div>
                            <Label htmlFor="enroll_notes">Notes</Label>
                            <Textarea
                                id="enroll_notes"
                                value={form.notes}
                                onChange={(e) => set('notes', e.target.value)}
                            />
                            {fieldError('notes')}
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit">Enroll</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Edit Enrollment Dialog */}
            <Dialog
                open={editId !== null}
                onOpenChange={(o) => !o && setEditId(null)}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Update Enrollment</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitEdit} className="space-y-4">
                        <div>
                            <Label htmlFor="edit_status">Status</Label>
                            <Select
                                value={editForm.status}
                                onValueChange={(val) => setEdit('status', val)}
                            >
                                <SelectTrigger id="edit_status">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
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
                            {fieldError('status')}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="edit_employee_rate">
                                    Employee Rate (%)
                                </Label>
                                <Input
                                    id="edit_employee_rate"
                                    type="number"
                                    step="0.01"
                                    value={editForm.employee_contribution_rate}
                                    onChange={(e) =>
                                        setEdit(
                                            'employee_contribution_rate',
                                            e.target.value,
                                        )
                                    }
                                />
                                {fieldError('employee_contribution_rate')}
                            </div>
                            <div>
                                <Label htmlFor="edit_employer_rate">
                                    Employer Rate (%)
                                </Label>
                                <Input
                                    id="edit_employer_rate"
                                    type="number"
                                    step="0.01"
                                    value={editForm.employer_contribution_rate}
                                    onChange={(e) =>
                                        setEdit(
                                            'employer_contribution_rate',
                                            e.target.value,
                                        )
                                    }
                                />
                                {fieldError('employer_contribution_rate')}
                            </div>
                        </div>
                        <div>
                            <Label htmlFor="edit_notes">Notes</Label>
                            <Textarea
                                id="edit_notes"
                                value={editForm.notes}
                                onChange={(e) => setEdit('notes', e.target.value)}
                            />
                            {fieldError('notes')}
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditId(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit">Save</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
