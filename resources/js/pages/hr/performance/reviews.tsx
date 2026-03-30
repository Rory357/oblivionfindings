import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Head, Link, router } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { Star, Search, Plus } from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

type BreadcrumbItem = { title: string; href: string };

type PerformanceReview = {
    id: number;
    staff_user: { id: number; name: string };
    reviewer: { id: number; name: string };
    review_period_start: string;
    review_period_end: string;
    status: string;
    overall_rating: number | null;
    scheduled_at: string | null;
};

type Props = {
    reviews: {
        data: PerformanceReview[];
        links: any[];
    };
    filters: {
        status: string | null;
        q: string | null;
    };
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Performance & Supervision', href: '/hr/performance' },
    { title: 'Reviews', href: '/hr/performance/reviews' },
];

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
};

const getStatusColor = (status: string) => {
    switch (status) {
        case 'completed':
            return 'bg-green-100 text-green-800 border-green-200';
        case 'scheduled':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'in_progress':
            return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'overdue':
            return 'bg-red-100 text-red-800 border-red-200';
        case 'draft':
            return 'bg-slate-100 text-slate-800 border-slate-200';
        case 'cancelled':
            return 'bg-slate-100 text-slate-500 border-slate-200';
        default:
            return 'bg-slate-100 text-slate-800 border-slate-200';
    }
};

const renderRating = (rating: number | null) => {
    if (rating === null || rating === undefined) {
        return <span className="text-slate-400">Not rated</span>;
    }
    const stars: ReactElement[] = [];
    for (let i = 1; i <= 5; i++) {
        stars.push(
            <Star
                key={i}
                className={`h-4 w-4 ${i <= rating ? 'fill-amber-400 text-amber-400' : 'text-slate-200'}`}
            />
        );
    }
    return <div className="flex items-center gap-0.5">{stars}</div>;
};

const statuses = ['draft', 'scheduled', 'in_progress', 'completed', 'overdue', 'cancelled'];

export default function PerformanceReviews({ reviews, filters, can }: Props) {
    const NONE = '__none__';

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/performance/reviews', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Performance Reviews" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Performance Reviews</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Track and manage staff performance reviews
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/hr/performance">
                            <Button size="sm" variant="outline">Dashboard</Button>
                        </Link>
                        {can.manage && (
                            <Link href="/hr/performance/reviews/create">
                                <Button size="sm">
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New Review
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div className="sm:col-span-2">
                            <Label className="text-xs text-slate-500">Search</Label>
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
                                <Input
                                    placeholder="Search by staff or reviewer name..."
                                    value={filters.q || ''}
                                    onChange={(e) => onFilter({ q: e.target.value })}
                                    className="pl-9"
                                />
                            </div>
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">Status</Label>
                            <Select
                                value={filters.status ?? NONE}
                                onValueChange={(v) => onFilter({ status: v === NONE ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="All statuses" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All Statuses</SelectItem>
                                    {statuses.map((s) => (
                                        <SelectItem key={s} value={s} className="capitalize">
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
                                    <TableHead>Staff Member</TableHead>
                                    <TableHead>Reviewer</TableHead>
                                    <TableHead>Review Period</TableHead>
                                    <TableHead>Scheduled</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Rating</TableHead>
                                    <TableHead className="w-20"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {reviews.data.map((review) => (
                                    <TableRow key={review.id}>
                                        <TableCell className="font-medium">{review.staff_user.name}</TableCell>
                                        <TableCell>{review.reviewer.name}</TableCell>
                                        <TableCell className="text-sm text-slate-600">
                                            {formatDate(review.review_period_start)} - {formatDate(review.review_period_end)}
                                        </TableCell>
                                        <TableCell>{formatDate(review.scheduled_at)}</TableCell>
                                        <TableCell>
                                            <Badge className={getStatusColor(review.status)}>
                                                {review.status.replace(/_/g, ' ')}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{renderRating(review.overall_rating)}</TableCell>
                                        <TableCell>
                                            <Link
                                                href={`/hr/performance/reviews/${review.id}`}
                                                className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                                            >
                                                View
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!reviews.data.length && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-8 text-center text-sm text-slate-500">
                                            No performance reviews found.
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
            </div>
        </AppLayout>
    );
}
