import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import {
    ProbationDialog,
    type ExistingProbationReview,
} from '@/components/hr/performance/probation-dialog';
import {
    ReviewWizardDialog,
    type ExistingReview,
    type ReviewStaff,
    type ReviewTypeOption,
} from '@/components/hr/performance/review-wizard-dialog';
import { PageHero, PageLayout } from '@/components/page';
import { PerformanceTabs } from '@/components/hr';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    AlertTriangle,
    CheckCircle,
    ClipboardList,
    FileEdit,
    Plus,
    Search,
    ShieldCheck,
    Star,
    TrendingUp,
} from 'lucide-react';
import type { ReactElement } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type BreadcrumbItem = { title: string; href: string };

type PerformanceReview = {
    id: number;
    staff_user?: { id: number; name: string };
    employee?: { id: number; name: string };
    reviewer: { id: number; name: string };
    review_type: string;
    review_period_start: string;
    review_period_end: string;
    next_review_date: string | null;
    status: string;
    overall_rating: number | null;
    scheduled_at: string | null;
    strengths: string | null;
    development_areas: string | null;
    goals: string[] | null;
    training_recommendations: string[] | null;
};

type ProbationReview = {
    id: number;
    employee: { id: number; name: string } | null;
    reviewer: { id: number; name: string } | null;
    review_number: number;
    review_date: string;
    status: string;
    recommendation: string | null;
    extension_weeks: number | null;
    concerns: string | null;
    areas_assessed: string[] | null;
    notes: string | null;
};

type Props = {
    reviews: {
        data: PerformanceReview[];
        links: any[];
    };
    probationReviews?: ProbationReview[];
    stats?: {
        total: number;
        completed: number;
        overdue: number;
        draft: number;
    };
    ratingDistribution?: Array<{ rating: number; count: number }>;
    statusDistribution?: Array<{ status: string; count: number }>;
    filters: {
        status: string | null;
        q: string | null;
    };
    staff: ReviewStaff[];
    reviewTypes: ReviewTypeOption[];
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Performance & Supervision', href: '/hr/performance' },
    { title: 'Reviews', href: '/hr/performance/reviews' },
];

const RATING_COLORS = ['#ef4444', '#f59e0b', '#eab308', '#3b82f6', '#10b981'];
const STATUS_COLORS: Record<string, string> = {
    completed: '#10b981',
    signed_off: '#059669',
    in_progress: '#f59e0b',
    draft: '#94a3b8',
    scheduled: '#3b82f6',
    cancelled: '#d1d5db',
};

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
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
        case 'completed':
            return 'bg-status-success-bg text-status-success border-status-success/30';
        case 'scheduled':
            return 'bg-status-info-bg text-status-info border-status-info/30';
        case 'in_progress':
            return 'bg-status-warning-bg text-status-warning border-status-warning/30';
        case 'overdue':
            return 'bg-status-critical-bg text-status-critical border-status-critical/30';
        case 'draft':
            return 'bg-muted text-foreground border-border';
        case 'cancelled':
            return 'bg-muted text-muted-foreground border-border';
        default:
            return 'bg-muted text-foreground border-border';
    }
};

const renderRating = (rating: number | null) => {
    if (rating === null || rating === undefined) {
        return <span className="text-muted-foreground">Not rated</span>;
    }
    const stars: ReactElement[] = [];
    for (let i = 1; i <= 5; i++) {
        stars.push(
            <Star
                key={i}
                className={`h-4 w-4 ${i <= rating ? 'fill-status-warning text-status-warning' : 'text-foreground'}`}
            />,
        );
    }
    return <div className="flex items-center gap-0.5">{stars}</div>;
};

const getRecommendationColor = (rec: string | null) => {
    switch (rec) {
        case 'pass':
            return 'bg-status-success-bg text-status-success border-status-success/30';
        case 'extend':
            return 'bg-status-warning-bg text-status-warning border-status-warning/30';
        case 'fail':
            return 'bg-status-critical-bg text-status-critical border-status-critical/30';
        default:
            return 'bg-muted text-foreground border-border';
    }
};

const statuses = [
    'draft',
    'scheduled',
    'in_progress',
    'completed',
    'overdue',
    'cancelled',
];

export default function PerformanceReviews({
    reviews,
    probationReviews = [],
    stats,
    ratingDistribution = [],
    statusDistribution = [],
    filters,
    staff,
    reviewTypes,
    can,
}: Props) {
    const NONE = '__none__';
    const [createOpen, setCreateOpen] = useState(false);
    const [editing, setEditing] = useState<ExistingReview | null>(null);
    const [probationOpen, setProbationOpen] = useState(false);
    const [editingProbation, setEditingProbation] =
        useState<ExistingProbationReview | null>(null);

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/performance/reviews',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    const hasRatings = ratingDistribution.some((d) => d.count > 0);
    const hasStatuses = statusDistribution.length > 0;
    const statusChartData = statusDistribution
        .map((d) => ({
            ...d,
            color: STATUS_COLORS[d.status] ?? '#94a3b8',
            label: d.status.replace(/_/g, ' '),
        }))
        .filter((d) => d.count > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Performance Reviews" />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={TrendingUp}
                        title="Performance Reviews"
                        description="Track and manage staff performance reviews."
                        stats={
                            stats
                                ? [
                                      { label: 'Total', value: stats.total },
                                      { label: 'Completed', value: stats.completed },
                                      { label: 'Overdue', value: stats.overdue },
                                      { label: 'Drafts', value: stats.draft },
                                  ]
                                : undefined
                        }
                        actions={
                            <>
                                <Link href="/hr/performance">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                    >
                                        Dashboard
                                    </Button>
                                </Link>
                                {can.manage && (
                                    <Button
                                        size="sm"
                                        onClick={() => {
                                            setEditing(null);
                                            setCreateOpen(true);
                                        }}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" /> New Review
                                    </Button>
                                )}
                            </>
                        }
                    />
                }
            >
                <PerformanceTabs active="reviews" />

                {/* KPI Cards */}
                {stats && (
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <Card className="border-l-4 border-l-blue-500 bg-status-info-bg">
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-medium text-status-info">
                                        Total Reviews
                                    </p>
                                    <div className="rounded-full bg-status-info-bg p-1.5">
                                        <ClipboardList className="h-4 w-4 text-status-info" />
                                    </div>
                                </div>
                                <span className="mt-1.5 block text-2xl font-bold text-status-info">
                                    {stats.total}
                                </span>
                            </CardContent>
                        </Card>
                        <Card className="border-l-4 border-l-emerald-500 bg-status-success-bg">
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-medium text-status-success">
                                        Completed
                                    </p>
                                    <div className="rounded-full bg-status-success-bg p-1.5">
                                        <CheckCircle className="h-4 w-4 text-status-success" />
                                    </div>
                                </div>
                                <span className="mt-1.5 block text-2xl font-bold text-status-success">
                                    {stats.completed}
                                </span>
                                {stats.total > 0 && (
                                    <p className="mt-0.5 text-xs text-status-success">
                                        {Math.round(
                                            (stats.completed / stats.total) *
                                                100,
                                        )}
                                        % completion rate
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                        <Card
                            className={`border-l-4 ${stats.overdue > 0 ? 'border-l-red-500 bg-status-critical-bg' : 'border-l-amber-500 bg-status-warning-bg'}`}
                        >
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p
                                        className={`text-xs font-medium ${stats.overdue > 0 ? 'text-status-critical' : 'text-status-warning'}`}
                                    >
                                        Overdue
                                    </p>
                                    <div
                                        className={`rounded-full p-1.5 ${stats.overdue > 0 ? 'bg-status-critical-bg' : 'bg-status-warning-bg'}`}
                                    >
                                        <AlertTriangle
                                            className={`h-4 w-4 ${stats.overdue > 0 ? 'text-status-critical' : 'text-status-warning'}`}
                                        />
                                    </div>
                                </div>
                                <span
                                    className={`mt-1.5 block text-2xl font-bold ${stats.overdue > 0 ? 'text-status-critical' : 'text-status-warning'}`}
                                >
                                    {stats.overdue}
                                </span>
                            </CardContent>
                        </Card>
                        <Card className="border-l-4 border-l-slate-400 bg-muted/40">
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Drafts
                                    </p>
                                    <div className="rounded-full bg-muted p-1.5">
                                        <FileEdit className="h-4 w-4 text-muted-foreground" />
                                    </div>
                                </div>
                                <span className="mt-1.5 block text-2xl font-bold text-foreground">
                                    {stats.draft}
                                </span>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* Charts */}
                {(hasRatings || hasStatuses) && (
                    <div className="grid gap-6 lg:grid-cols-2">
                        {hasRatings && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                        <Star className="h-4 w-4 text-status-warning" />{' '}
                                        Rating Distribution
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <ResponsiveContainer
                                        width="100%"
                                        height={200}
                                    >
                                        <BarChart
                                            data={ratingDistribution}
                                            margin={{
                                                top: 5,
                                                right: 10,
                                                bottom: 0,
                                                left: -20,
                                            }}
                                        >
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                className="stroke-muted"
                                            />
                                            <XAxis
                                                dataKey="rating"
                                                tick={{ fontSize: 11 }}
                                                axisLine={false}
                                                tickLine={false}
                                            />
                                            <YAxis
                                                tick={{ fontSize: 11 }}
                                                axisLine={false}
                                                tickLine={false}
                                                allowDecimals={false}
                                            />
                                            <Tooltip
                                                formatter={(value: any) => [
                                                    value,
                                                    'Reviews',
                                                ]}
                                            />
                                            <Bar
                                                dataKey="count"
                                                radius={[4, 4, 0, 0]}
                                                maxBarSize={48}
                                            >
                                                {ratingDistribution.map(
                                                    (_, idx) => (
                                                        <Cell
                                                            key={idx}
                                                            fill={
                                                                RATING_COLORS[
                                                                    idx %
                                                                        RATING_COLORS.length
                                                                ]
                                                            }
                                                        />
                                                    ),
                                                )}
                                            </Bar>
                                        </BarChart>
                                    </ResponsiveContainer>
                                </CardContent>
                            </Card>
                        )}
                        {hasStatuses && statusChartData.length > 0 && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                        <ClipboardList className="h-4 w-4 text-status-info" />{' '}
                                        Status Breakdown
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center gap-6">
                                        <div
                                            style={{ width: 160, height: 160 }}
                                        >
                                            <ResponsiveContainer
                                                width="100%"
                                                height="100%"
                                            >
                                                <PieChart>
                                                    <Pie
                                                        data={statusChartData}
                                                        dataKey="count"
                                                        nameKey="label"
                                                        cx="50%"
                                                        cy="50%"
                                                        outerRadius={70}
                                                        innerRadius={42}
                                                        paddingAngle={2}
                                                    >
                                                        {statusChartData.map(
                                                            (entry, idx) => (
                                                                <Cell
                                                                    key={idx}
                                                                    fill={
                                                                        entry.color
                                                                    }
                                                                />
                                                            ),
                                                        )}
                                                    </Pie>
                                                    <Tooltip />
                                                </PieChart>
                                            </ResponsiveContainer>
                                        </div>
                                        <div className="space-y-1.5 text-sm">
                                            {statusChartData.map((d) => (
                                                <div
                                                    key={d.status}
                                                    className="flex items-center gap-2"
                                                >
                                                    <div
                                                        className="h-2.5 w-2.5 rounded-full"
                                                        style={{
                                                            backgroundColor:
                                                                d.color,
                                                        }}
                                                    />
                                                    <span className="text-muted-foreground capitalize">
                                                        {d.label}:{' '}
                                                        <span className="font-medium text-foreground">
                                                            {d.count}
                                                        </span>
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                )}

                {/* Filters */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">
                            Filters
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div className="sm:col-span-2">
                            <Label className="text-xs text-muted-foreground">
                                Search
                            </Label>
                            <div className="relative">
                                <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search by staff or reviewer name..."
                                    value={filters.q || ''}
                                    onChange={(e) =>
                                        onFilter({ q: e.target.value })
                                    }
                                    className="pl-9"
                                />
                            </div>
                        </div>
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

                {/* Reviews Table */}
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
                                        <TableCell className="font-medium">
                                            {(
                                                review.staff_user ??
                                                review.employee
                                            )?.name ?? 'Unknown'}
                                        </TableCell>
                                        <TableCell>
                                            {review.reviewer?.name ?? 'Unknown'}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {formatDate(
                                                review.review_period_start,
                                            )}{' '}
                                            -{' '}
                                            {formatDate(
                                                review.review_period_end,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(review.scheduled_at)}
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
                                        <TableCell>
                                            {renderRating(
                                                review.overall_rating,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-1.5">
                                                <Link
                                                    href={`/hr/performance/reviews/${review.id}`}
                                                    className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                                                >
                                                    View
                                                </Link>
                                                {can.manage && (
                                                    // eslint-disable-next-line no-restricted-syntax -- chip-styled row action matching the adjacent View link
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setEditing({
                                                                id: review.id,
                                                                employee:
                                                                    review.employee ??
                                                                    review.staff_user ??
                                                                    null,
                                                                review_type:
                                                                    review.review_type,
                                                                review_period_start:
                                                                    review.review_period_start,
                                                                review_period_end:
                                                                    review.review_period_end,
                                                                next_review_date:
                                                                    review.next_review_date,
                                                                overall_rating:
                                                                    review.overall_rating,
                                                                strengths:
                                                                    review.strengths,
                                                                development_areas:
                                                                    review.development_areas,
                                                                goals: review.goals,
                                                                training_recommendations:
                                                                    review.training_recommendations,
                                                            });
                                                            setCreateOpen(true);
                                                        }}
                                                        className="inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                                                    >
                                                        <FileEdit className="h-3.5 w-3.5" />
                                                        Edit
                                                    </button>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!reviews.data.length && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="py-8 text-center text-sm text-muted-foreground"
                                        >
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

                {(probationReviews.length > 0 || can.manage) && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                    <ShieldCheck className="h-4 w-4 text-status-info" />{' '}
                                    Probation Reviews
                                </CardTitle>
                                {can.manage && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => {
                                            setEditingProbation(null);
                                            setProbationOpen(true);
                                        }}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Record probation review
                                    </Button>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            {probationReviews.length === 0 ? (
                                <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                                    No probation reviews recorded yet.
                                </p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Staff Member</TableHead>
                                            <TableHead>Reviewer</TableHead>
                                            <TableHead>Review #</TableHead>
                                            <TableHead>Date</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Recommendation</TableHead>
                                            <TableHead className="w-16"></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {probationReviews.map((pr) => (
                                            <TableRow key={pr.id}>
                                                <TableCell className="font-medium">
                                                    {pr.employee?.name ?? 'Unknown'}
                                                </TableCell>
                                                <TableCell>
                                                    {pr.reviewer?.name ?? 'Unknown'}
                                                </TableCell>
                                                <TableCell>
                                                    #{pr.review_number}
                                                </TableCell>
                                                <TableCell>
                                                    {formatDate(pr.review_date)}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        className={getStatusColor(
                                                            pr.status,
                                                        )}
                                                    >
                                                        {pr.status.replace(
                                                            /_/g,
                                                            ' ',
                                                        )}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {pr.recommendation ? (
                                                        <Badge
                                                            className={getRecommendationColor(
                                                                pr.recommendation,
                                                            )}
                                                        >
                                                            {pr.recommendation}
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            Pending
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {can.manage && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => {
                                                                setEditingProbation(
                                                                    {
                                                                        id: pr.id,
                                                                        employee:
                                                                            pr.employee,
                                                                        review_number:
                                                                            pr.review_number,
                                                                        review_date:
                                                                            pr.review_date,
                                                                        status: pr.status,
                                                                        recommendation:
                                                                            pr.recommendation,
                                                                        extension_weeks:
                                                                            pr.extension_weeks,
                                                                        concerns:
                                                                            pr.concerns,
                                                                        areas_assessed:
                                                                            pr.areas_assessed,
                                                                        notes: pr.notes,
                                                                    },
                                                                );
                                                                setProbationOpen(
                                                                    true,
                                                                );
                                                            }}
                                                        >
                                                            Edit
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                )}

                {can.manage && (
                    <ReviewWizardDialog
                        key={editing?.id ?? 'new'}
                        open={createOpen}
                        onClose={() => {
                            setCreateOpen(false);
                            setEditing(null);
                        }}
                        staff={staff}
                        reviewTypes={reviewTypes}
                        review={editing}
                    />
                )}

                {can.manage && (
                    <ProbationDialog
                        key={`prob-${editingProbation?.id ?? 'new'}`}
                        open={probationOpen}
                        onClose={() => {
                            setProbationOpen(false);
                            setEditingProbation(null);
                        }}
                        staff={staff}
                        review={editingProbation}
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
