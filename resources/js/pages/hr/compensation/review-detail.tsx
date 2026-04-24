import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Play, X } from 'lucide-react';
import { FormEvent, useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

type ReviewItem = {
    id: number;
    employee_profile_id: number;
    employee_profile: {
        id: number;
        user: { id: number; name: string };
        position_title: string;
    };
    current_salary: string;
    proposed_salary: string;
    change_percentage: number;
    justification: string | null;
    status: string;
    approver: { id: number; name: string } | null;
};

type CompensationReview = {
    id: number;
    title: string;
    review_cycle: string;
    effective_date: string;
    status: string;
    budget_amount: string | null;
    notes: string | null;
    items: ReviewItem[];
    creator: { id: number; name: string } | null;
};

type Employee = {
    id: number;
    user_id: number;
    user: { id: number; name: string };
    position_title: string;
    annual_salary: string;
    hourly_rate: string;
};

type ReviewCycleOption = { value: string; label: string };

type Props = {
    review: CompensationReview | null;
    employees: Employee[];
    reviewCycles: ReviewCycleOption[];
    can: { manage: boolean };
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-GB', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

const formatCurrency = (value: string | null) => {
    if (!value) return '-';
    const num = parseFloat(value);
    if (Number.isNaN(num)) return value;
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(num);
};

const getStatusColor = (status: string) => {
    switch (status) {
        case 'planning':
            return 'bg-muted text-foreground border-border';
        case 'in_progress':
            return 'bg-status-warning-bg text-status-warning border-status-warning/30';
        case 'approved':
            return 'bg-status-success-bg text-status-success border-status-success/30';
        case 'applied':
            return 'bg-status-info-bg text-status-info border-status-info/30';
        case 'pending':
            return 'bg-status-warning-bg text-status-warning border-status-warning/30';
        case 'rejected':
            return 'bg-status-critical-bg text-status-critical border-status-critical/30';
        default:
            return 'bg-muted text-foreground border-border';
    }
};

const getCycleLabel = (cycle: string) => {
    switch (cycle) {
        case 'annual':
            return 'Annual';
        case 'mid_year':
            return 'Mid-Year';
        case 'ad_hoc':
            return 'Ad Hoc';
        default:
            return cycle;
    }
};

export default function CompensationReviewDetail({
    review,
    employees,
    reviewCycles,
    can,
}: Props) {
    const isNew = !review;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Compensation', href: '/hr/compensation/bands' },
        { title: 'Reviews', href: '/hr/compensation/reviews' },
        {
            title: isNew ? 'New Review' : review.title,
            href: isNew
                ? '/hr/compensation/reviews/create'
                : `/hr/compensation/reviews/${review.id}`,
        },
    ];

    const [form, setForm] = useState({
        title: review?.title ?? '',
        review_cycle: review?.review_cycle ?? 'annual',
        effective_date: review?.effective_date ?? '',
        budget_amount: review?.budget_amount ?? '',
        notes: review?.notes ?? '',
        items: [] as Array<{
            employee_profile_id: string;
            current_salary: string;
            proposed_salary: string;
            change_percentage: string;
            justification: string;
        }>,
    });

    const set = (key: string, value: any) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const addItem = () => {
        setForm((prev) => ({
            ...prev,
            items: [
                ...prev.items,
                {
                    employee_profile_id: '',
                    current_salary: '',
                    proposed_salary: '',
                    change_percentage: '',
                    justification: '',
                },
            ],
        }));
    };

    const updateItem = (idx: number, key: string, value: string) => {
        setForm((prev) => {
            const items = [...prev.items];
            items[idx] = { ...items[idx], [key]: value };

            // Auto-calculate change percentage
            if (key === 'proposed_salary' || key === 'current_salary') {
                const current = parseFloat(items[idx].current_salary);
                const proposed = parseFloat(items[idx].proposed_salary);
                if (current > 0 && proposed > 0) {
                    items[idx].change_percentage = (
                        ((proposed - current) / current) *
                        100
                    ).toFixed(2);
                }
            }

            return { ...prev, items };
        });
    };

    const removeItem = (idx: number) => {
        setForm((prev) => ({
            ...prev,
            items: prev.items.filter((_, i) => i !== idx),
        }));
    };

    const onEmployeeSelect = (idx: number, profileId: string) => {
        const employee = employees.find((e) => String(e.id) === profileId);
        updateItem(idx, 'employee_profile_id', profileId);
        if (employee) {
            updateItem(idx, 'current_salary', employee.annual_salary ?? '0');
        }
    };

    const submitCreate = (e: FormEvent) => {
        e.preventDefault();
        router.post('/hr/compensation/reviews', form);
    };

    const applyReview = () => {
        if (
            review &&
            confirm(
                'Apply this compensation review? This will update all approved employee salaries.',
            )
        ) {
            router.post(`/hr/compensation/reviews/${review.id}/apply`);
        }
    };

    // Create mode
    if (isNew) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="New Compensation Review" />
                <div className="space-y-4">
                    <div className="flex items-center gap-2">
                        <Link href="/hr/compensation/reviews">
                            <Button size="sm" variant="outline">
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back
                            </Button>
                        </Link>
                        <h1 className="text-lg font-semibold">
                            New Compensation Review
                        </h1>
                    </div>

                    <form onSubmit={submitCreate} className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Review Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Title</Label>
                                        <Input
                                            value={form.title}
                                            onChange={(e) =>
                                                set('title', e.target.value)
                                            }
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Review Cycle</Label>
                                        <Select
                                            value={form.review_cycle}
                                            onValueChange={(v) =>
                                                set('review_cycle', v)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {reviewCycles.map((c) => (
                                                    <SelectItem
                                                        key={c.value}
                                                        value={c.value}
                                                    >
                                                        {c.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Effective Date</Label>
                                        <Input
                                            type="date"
                                            value={form.effective_date}
                                            onChange={(e) =>
                                                set(
                                                    'effective_date',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Budget Amount</Label>
                                        <Input
                                            type="number"
                                            step="0.01"
                                            value={form.budget_amount}
                                            onChange={(e) =>
                                                set(
                                                    'budget_amount',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                                <div>
                                    <Label>Notes</Label>
                                    <Textarea
                                        value={form.notes}
                                        onChange={(e) =>
                                            set('notes', e.target.value)
                                        }
                                        rows={3}
                                    />
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle className="text-base">
                                    Employee Adjustments
                                </CardTitle>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={addItem}
                                >
                                    Add Employee
                                </Button>
                            </CardHeader>
                            <CardContent>
                                {form.items.length === 0 && (
                                    <p className="py-4 text-center text-sm text-muted-foreground">
                                        No employees added yet. Click "Add
                                        Employee" to begin.
                                    </p>
                                )}
                                {form.items.map((item, idx) => (
                                    <div
                                        key={idx}
                                        className="mb-4 grid grid-cols-6 gap-3 rounded-md border p-3"
                                    >
                                        <div className="col-span-2">
                                            <Label className="text-xs">
                                                Employee
                                            </Label>
                                            <Select
                                                value={item.employee_profile_id}
                                                onValueChange={(v) =>
                                                    onEmployeeSelect(idx, v)
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {employees.map((emp) => (
                                                        <SelectItem
                                                            key={emp.id}
                                                            value={String(
                                                                emp.id,
                                                            )}
                                                        >
                                                            {emp.user.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div>
                                            <Label className="text-xs">
                                                Current Salary
                                            </Label>
                                            <Input
                                                type="number"
                                                step="0.01"
                                                value={item.current_salary}
                                                onChange={(e) =>
                                                    updateItem(
                                                        idx,
                                                        'current_salary',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label className="text-xs">
                                                Proposed Salary
                                            </Label>
                                            <Input
                                                type="number"
                                                step="0.01"
                                                value={item.proposed_salary}
                                                onChange={(e) =>
                                                    updateItem(
                                                        idx,
                                                        'proposed_salary',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label className="text-xs">
                                                Change %
                                            </Label>
                                            <Input
                                                type="number"
                                                step="0.01"
                                                value={item.change_percentage}
                                                readOnly
                                                className="bg-muted"
                                            />
                                        </div>
                                        <div className="flex items-end">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => removeItem(idx)}
                                            >
                                                <X className="h-4 w-4" />
                                            </Button>
                                        </div>
                                        <div className="col-span-6">
                                            <Label className="text-xs">
                                                Justification
                                            </Label>
                                            <Input
                                                value={item.justification}
                                                onChange={(e) =>
                                                    updateItem(
                                                        idx,
                                                        'justification',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Reason for change..."
                                            />
                                        </div>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        <div className="flex justify-end">
                            <Button type="submit">Create Review</Button>
                        </div>
                    </form>
                </div>
            </AppLayout>
        );
    }

    // View/detail mode
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={review.title} />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <Link href="/hr/compensation/reviews">
                            <Button size="sm" variant="outline">
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back
                            </Button>
                        </Link>
                        <div>
                            <h1 className="text-lg font-semibold">
                                {review.title}
                            </h1>
                            <div className="mt-0.5 flex items-center gap-2 text-sm text-muted-foreground">
                                <span>
                                    {getCycleLabel(review.review_cycle)}
                                </span>
                                <span>&middot;</span>
                                <span>
                                    Effective{' '}
                                    {formatDate(review.effective_date)}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Badge className={getStatusColor(review.status)}>
                            {review.status.replace(/_/g, ' ')}
                        </Badge>
                        {can.manage && review.status === 'approved' && (
                            <Button size="sm" onClick={applyReview}>
                                <Play className="mr-1 h-4 w-4" />
                                Apply Review
                            </Button>
                        )}
                    </div>
                </div>

                {(review.budget_amount || review.notes) && (
                    <Card>
                        <CardContent className="pt-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {review.budget_amount && (
                                    <div>
                                        <span className="text-sm text-muted-foreground">
                                            Budget Amount
                                        </span>
                                        <div className="text-lg font-semibold">
                                            {formatCurrency(
                                                review.budget_amount,
                                            )}
                                        </div>
                                    </div>
                                )}
                                {review.creator && (
                                    <div>
                                        <span className="text-sm text-muted-foreground">
                                            Created By
                                        </span>
                                        <div className="text-sm font-medium">
                                            {review.creator.name}
                                        </div>
                                    </div>
                                )}
                            </div>
                            {review.notes && (
                                <div className="mt-3">
                                    <span className="text-sm text-muted-foreground">
                                        Notes
                                    </span>
                                    <p className="text-sm">{review.notes}</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Employee Adjustments ({review.items.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Current Salary</TableHead>
                                    <TableHead>Proposed Salary</TableHead>
                                    <TableHead>Change %</TableHead>
                                    <TableHead>Justification</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Approved By</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {review.items.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell className="font-medium">
                                            {item.employee_profile?.user
                                                ?.name ?? 'Unknown'}
                                        </TableCell>
                                        <TableCell>
                                            {formatCurrency(
                                                item.current_salary,
                                            )}
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {formatCurrency(
                                                item.proposed_salary,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className={
                                                    item.change_percentage > 0
                                                        ? 'text-status-success'
                                                        : item.change_percentage <
                                                            0
                                                          ? 'text-status-critical'
                                                          : ''
                                                }
                                            >
                                                {item.change_percentage > 0
                                                    ? '+'
                                                    : ''}
                                                {item.change_percentage}%
                                            </span>
                                        </TableCell>
                                        <TableCell className="max-w-xs truncate text-sm text-muted-foreground">
                                            {item.justification ?? '-'}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                className={getStatusColor(
                                                    item.status,
                                                )}
                                            >
                                                {item.status}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {item.approver?.name ?? '-'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!review.items.length && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No employees in this review.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
