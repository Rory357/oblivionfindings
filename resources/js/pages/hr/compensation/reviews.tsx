import {
    CompensationHero,
    CompensationTabs,
    type CompensationHeroStats,
} from '@/components/hr';
import {
    ReviewBuilderDialog,
    type ReviewBuilderBand,
    type ReviewBuilderEmployee,
    type ReviewCycleOption,
} from '@/components/hr/review-builder-dialog';
import { StatusBadge } from '@/components/hr/status-badge';
import { PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
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
import { Head, Link, router } from '@inertiajs/react';
import { ClipboardCheck, Eye, Plus } from 'lucide-react';
import { useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

type CompensationReview = {
    id: number;
    title: string;
    review_cycle: string;
    effective_date: string;
    status: string;
    items_count: number;
    creator: { id: number; name: string } | null;
    created_at: string;
};

type Props = {
    reviews: { data: CompensationReview[]; links: any[] };
    filters: { status: string | null };
    stats: CompensationHeroStats;
    employees?: ReviewBuilderEmployee[];
    reviewCycles?: ReviewCycleOption[];
    bands?: ReviewBuilderBand[];
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Compensation', href: '/hr/compensation/bands' },
    { title: 'Reviews', href: '/hr/compensation/reviews' },
];

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

const statuses = ['planning', 'in_progress', 'approved', 'applied'];

export default function CompensationReviews({
    reviews,
    filters,
    stats,
    employees = [],
    reviewCycles = [],
    bands,
    can,
}: Props) {
    const NONE = '__none__';
    const [builderOpen, setBuilderOpen] = useState(false);

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/compensation/reviews',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Compensation Reviews" />

            <PageLayout hero={<CompensationHero stats={stats} />}>
                <CompensationTabs active="reviews" />

                {/* Inline toolbar — status filter + New review (bands idiom) */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <Select
                        value={filters.status ?? NONE}
                        onValueChange={(v) =>
                            onFilter({ status: v === NONE ? null : v })
                        }
                    >
                        <SelectTrigger
                            className="w-full sm:max-w-xs"
                            aria-label="Filter by status"
                        >
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>All statuses</SelectItem>
                            {statuses.map((s) => (
                                <SelectItem
                                    key={s}
                                    value={s}
                                    className="capitalize"
                                >
                                    {s.replace(/_/g, ' ')}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {can.manage ? (
                        <Button size="sm" onClick={() => setBuilderOpen(true)}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            New review
                        </Button>
                    ) : null}
                </div>

                {reviews.data.length > 0 ? (
                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Title</TableHead>
                                        <TableHead>Cycle</TableHead>
                                        <TableHead>Effective</TableHead>
                                        <TableHead>Employees</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Created by</TableHead>
                                        <TableHead className="w-16 text-right"></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {reviews.data.map((review) => (
                                        <TableRow key={review.id}>
                                            <TableCell className="font-medium">
                                                {review.title}
                                            </TableCell>
                                            <TableCell>
                                                {getCycleLabel(
                                                    review.review_cycle,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {formatDate(
                                                    review.effective_date,
                                                )}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {review.items_count}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={review.status}
                                                    tone={
                                                        review.status ===
                                                        'applied'
                                                            ? 'info'
                                                            : undefined
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {review.creator?.name ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-8 w-8"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/hr/compensation/reviews/${review.id}`}
                                                        aria-label={`View ${review.title}`}
                                                    >
                                                        <Eye className="h-4 w-4" />
                                                    </Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                ) : (
                    <EmptyState
                        icon={ClipboardCheck}
                        heading={
                            filters.status
                                ? 'No reviews match this status'
                                : 'No pay reviews yet'
                        }
                        description={
                            filters.status
                                ? 'Try clearing the status filter.'
                                : 'Start a pay review to plan and apply salary adjustments across your people.'
                        }
                        action={
                            can.manage && !filters.status ? (
                                <Button
                                    size="sm"
                                    onClick={() => setBuilderOpen(true)}
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New review
                                </Button>
                            ) : undefined
                        }
                    />
                )}

                {reviews?.links?.length ? (
                    <LaravelPagination links={reviews.links} />
                ) : null}
            </PageLayout>

            {can.manage ? (
                <ReviewBuilderDialog
                    open={builderOpen}
                    onClose={() => setBuilderOpen(false)}
                    employees={employees}
                    reviewCycles={reviewCycles}
                    bands={bands}
                />
            ) : null}
        </AppLayout>
    );
}
