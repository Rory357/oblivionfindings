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
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { DollarSign, Plus } from 'lucide-react';

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

const statuses = ['planning', 'in_progress', 'approved', 'applied'];

export default function CompensationReviews({ reviews, filters, can }: Props) {
    const NONE = '__none__';

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

            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={DollarSign}
                        title="Compensation Reviews"
                        description="Manage compensation review cycles and bulk salary adjustments."
                        stats={[
                            { label: 'Reviews', value: reviews.data.length },
                        ]}
                        actions={
                            <>
                                <Link href="/hr/compensation/bands">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                    >
                                        Salary Bands
                                    </Button>
                                </Link>
                                {can.manage && (
                                    <Link href="/hr/compensation/reviews/create">
                                        <Button size="sm">
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            New Review
                                        </Button>
                                    </Link>
                                )}
                            </>
                        }
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Status
                            </Label>
                            <Select
                                value={filters.status ?? NONE}
                                onValueChange={(v) =>
                                    onFilter({ status: v === NONE ? null : v })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        All Statuses
                                    </SelectItem>
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
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Title</TableHead>
                                    <TableHead>Cycle</TableHead>
                                    <TableHead>Effective Date</TableHead>
                                    <TableHead>Employees</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Created By</TableHead>
                                    <TableHead className="w-20"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {reviews.data.map((review) => (
                                    <TableRow key={review.id}>
                                        <TableCell className="font-medium">
                                            {review.title}
                                        </TableCell>
                                        <TableCell>
                                            {getCycleLabel(review.review_cycle)}
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(review.effective_date)}
                                        </TableCell>
                                        <TableCell>
                                            {review.items_count}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                className={getStatusColor(
                                                    review.status,
                                                )}
                                            >
                                                {review.status.replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {review.creator?.name ?? '-'}
                                        </TableCell>
                                        <TableCell>
                                            <Link
                                                href={`/hr/compensation/reviews/${review.id}`}
                                                className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                                            >
                                                View
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!reviews.data.length && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No compensation reviews found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {reviews?.links?.length ? (
                    <LaravelPagination links={reviews.links} />
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
