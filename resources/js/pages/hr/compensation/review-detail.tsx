import {
    ReviewBuilderDialog,
    type ReviewBuilderBand,
} from '@/components/hr/review-builder-dialog';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { CheckCircle2, ClipboardCheck, Play, Sparkles } from 'lucide-react';
import { useState } from 'react';

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
    /** Optional active salary bands for per-line in/under/over placement in the
     *  builder. Omitted gracefully when the controller doesn't pass it. */
    bands?: ReviewBuilderBand[];
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
    bands,
}: Props) {
    const isNew = !review;
    const [builderOpen, setBuilderOpen] = useState(false);

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

    const approveReview = () => {
        if (
            review &&
            confirm(
                'Approve this compensation review? This marks all pending line-items approved and unlocks applying it.',
            )
        ) {
            router.post(`/hr/compensation/reviews/${review.id}/approve`);
        }
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

    // Create mode — a guided 3-step builder wizard replaces the long inline form.
    if (isNew) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="New Compensation Review" />
                <PageLayout
                    hero={
                        <PageHero
                            category="hr"
                            variant="compact"
                            backHref="/hr/compensation/reviews"
                            title="New Compensation Review"
                            description="Define a new review cycle and add employees for adjustments."
                            actions={
                                <Button onClick={() => setBuilderOpen(true)}>
                                    <ClipboardCheck className="mr-1.5 h-4 w-4" />
                                    Build review
                                </Button>
                            }
                        />
                    }
                >
                    <Card>
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <span className="relative grid h-16 w-16 place-items-center rounded-2xl bg-primary/10 text-primary">
                                <ClipboardCheck className="h-8 w-8" />
                                <Sparkles className="absolute -top-1.5 -right-1.5 h-5 w-5 text-primary" />
                            </span>
                            <div className="max-w-md space-y-1">
                                <CardTitle className="text-lg">
                                    Build a pay review in three steps
                                </CardTitle>
                                <p className="text-sm text-muted-foreground">
                                    Set the cycle and identity, add employees
                                    with their proposed adjustments, then review
                                    the budget-vs-committed tally before you
                                    create it.
                                </p>
                            </div>
                            <Button
                                size="lg"
                                onClick={() => setBuilderOpen(true)}
                            >
                                <ClipboardCheck className="mr-1.5 h-4 w-4" />
                                Start the builder
                            </Button>
                        </CardContent>
                    </Card>

                    <ReviewBuilderDialog
                        open={builderOpen}
                        onClose={() => setBuilderOpen(false)}
                        employees={employees}
                        reviewCycles={reviewCycles}
                        bands={bands}
                    />
                </PageLayout>
            </AppLayout>
        );
    }

    // View/detail mode
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={review.title} />

            <PageLayout
                hero={
                    <PageHero
                        category="hr"
                        variant="compact"
                        backHref="/hr/compensation/reviews"
                        title={review.title}
                        description={`${getCycleLabel(review.review_cycle)} · Effective ${formatDate(review.effective_date)}`}
                        actions={
                            <div className="flex items-center gap-2">
                                <Badge
                                    className={getStatusColor(review.status)}
                                >
                                    {review.status.replace(/_/g, ' ')}
                                </Badge>
                                {can.manage &&
                                    (review.status === 'planning' ||
                                        review.status === 'in_progress') && (
                                        <Button
                                            size="sm"
                                            onClick={approveReview}
                                        >
                                            <CheckCircle2 className="mr-1 h-4 w-4" />
                                            Approve Review
                                        </Button>
                                    )}
                                {can.manage && review.status === 'approved' && (
                                    <Button size="sm" onClick={applyReview}>
                                        <Play className="mr-1 h-4 w-4" />
                                        Apply Review
                                    </Button>
                                )}
                            </div>
                        }
                    />
                }
            >
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
            </PageLayout>
        </AppLayout>
    );
}
