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
import { Star, Search, Plus, ShieldCheck, ClipboardList, CheckCircle, AlertTriangle, FileEdit } from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    BarChart, Bar, PieChart, Pie, Cell,
    XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
} from 'recharts';

type BreadcrumbItem = { title: string; href: string };

type PerformanceReview = {
    id: number;
    staff_user?: { id: number; name: string };
    employee?: { id: number; name: string };
    reviewer: { id: number; name: string };
    review_period_start: string;
    review_period_end: string;
    status: string;
    overall_rating: number | null;
    scheduled_at: string | null;
};

type ProbationReview = {
    id: number;
    employee: { id: number; name: string } | null;
    reviewer: { id: number; name: string } | null;
    review_number: number;
    review_date: string;
    status: string;
    recommendation: string | null;
};

type Props = {
    reviews: {
        data: PerformanceReview[];
        links: any[];
    };
    probationReviews?: ProbationReview[];
    stats?: { total: number; completed: number; overdue: number; draft: number };
    ratingDistribution?: Array<{ rating: number; count: number }>;
    statusDistribution?: Array<{ status: string; count: number }>;
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

const RATING_COLORS = ['#ef4444', '#f59e0b', '#eab308', '#3b82f6', '#10b981'];
const STATUS_COLORS: Record<string, string> = {
    completed: '#10b981', signed_off: '#059669', in_progress: '#f59e0b',
    draft: '#94a3b8', scheduled: '#3b82f6', cancelled: '#d1d5db',
};

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-NZ', {
        day: '2-digit', month: 'short', year: 'numeric',
    });
};

const getStatusColor = (status: string) => {
    switch (status) {
        case 'completed': return 'bg-green-100 text-green-800 border-green-200';
        case 'scheduled': return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'in_progress': return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'overdue': return 'bg-red-100 text-red-800 border-red-200';
        case 'draft': return 'bg-slate-100 text-slate-800 border-slate-200';
        case 'cancelled': return 'bg-slate-100 text-slate-500 border-slate-200';
        default: return 'bg-slate-100 text-slate-800 border-slate-200';
    }
};

const renderRating = (rating: number | null) => {
    if (rating === null || rating === undefined) {
        return <span className="text-slate-400">Not rated</span>;
    }
    const stars: ReactElement[] = [];
    for (let i = 1; i <= 5; i++) {
        stars.push(
            <Star key={i} className={`h-4 w-4 ${i <= rating ? 'fill-amber-400 text-amber-400' : 'text-slate-200'}`} />
        );
    }
    return <div className="flex items-center gap-0.5">{stars}</div>;
};

const getRecommendationColor = (rec: string | null) => {
    switch (rec) {
        case 'pass': return 'bg-green-100 text-green-800 border-green-200';
        case 'extend': return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'fail': return 'bg-red-100 text-red-800 border-red-200';
        default: return 'bg-slate-100 text-slate-800 border-slate-200';
    }
};

const statuses = ['draft', 'scheduled', 'in_progress', 'completed', 'overdue', 'cancelled'];

export default function PerformanceReviews({
    reviews, probationReviews = [], stats, ratingDistribution = [], statusDistribution = [], filters, can,
}: Props) {
    const NONE = '__none__';

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/performance/reviews', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    const hasRatings = ratingDistribution.some((d) => d.count > 0);
    const hasStatuses = statusDistribution.length > 0;
    const statusChartData = statusDistribution.map((d) => ({
        ...d,
        color: STATUS_COLORS[d.status] ?? '#94a3b8',
        label: d.status.replace(/_/g, ' '),
    })).filter((d) => d.count > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Performance Reviews" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Performance Reviews</h1>
                        <p className="mt-0.5 text-sm text-slate-500">Track and manage staff performance reviews</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/hr/performance">
                            <Button size="sm" variant="outline">Dashboard</Button>
                        </Link>
                        {can.manage && (
                            <Link href="/hr/performance/reviews/create">
                                <Button size="sm"><Plus className="mr-1.5 h-4 w-4" /> New Review</Button>
                            </Link>
                        )}
                    </div>
                </div>

                {/* KPI Cards */}
                {stats && (
                    <div className="grid gap-3 grid-cols-2 lg:grid-cols-4">
                        <Card className="border-l-4 border-l-blue-500 bg-blue-50/40">
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-medium text-blue-700">Total Reviews</p>
                                    <div className="rounded-full bg-blue-100 p-1.5"><ClipboardList className="h-4 w-4 text-blue-600" /></div>
                                </div>
                                <span className="mt-1.5 block text-2xl font-bold text-blue-900">{stats.total}</span>
                            </CardContent>
                        </Card>
                        <Card className="border-l-4 border-l-emerald-500 bg-emerald-50/40">
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-medium text-emerald-700">Completed</p>
                                    <div className="rounded-full bg-emerald-100 p-1.5"><CheckCircle className="h-4 w-4 text-emerald-600" /></div>
                                </div>
                                <span className="mt-1.5 block text-2xl font-bold text-emerald-900">{stats.completed}</span>
                                {stats.total > 0 && <p className="mt-0.5 text-xs text-emerald-600">{Math.round((stats.completed / stats.total) * 100)}% completion rate</p>}
                            </CardContent>
                        </Card>
                        <Card className={`border-l-4 ${stats.overdue > 0 ? 'border-l-red-500 bg-red-50/50' : 'border-l-amber-500 bg-amber-50/40'}`}>
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className={`text-xs font-medium ${stats.overdue > 0 ? 'text-red-700' : 'text-amber-700'}`}>Overdue</p>
                                    <div className={`rounded-full p-1.5 ${stats.overdue > 0 ? 'bg-red-100' : 'bg-amber-100'}`}>
                                        <AlertTriangle className={`h-4 w-4 ${stats.overdue > 0 ? 'text-red-600' : 'text-amber-600'}`} />
                                    </div>
                                </div>
                                <span className={`mt-1.5 block text-2xl font-bold ${stats.overdue > 0 ? 'text-red-700' : 'text-amber-900'}`}>{stats.overdue}</span>
                            </CardContent>
                        </Card>
                        <Card className="border-l-4 border-l-slate-400 bg-slate-50/40">
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-medium text-slate-600">Drafts</p>
                                    <div className="rounded-full bg-slate-100 p-1.5"><FileEdit className="h-4 w-4 text-slate-500" /></div>
                                </div>
                                <span className="mt-1.5 block text-2xl font-bold text-slate-800">{stats.draft}</span>
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
                                        <Star className="h-4 w-4 text-amber-500" /> Rating Distribution
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <ResponsiveContainer width="100%" height={200}>
                                        <BarChart data={ratingDistribution} margin={{ top: 5, right: 10, bottom: 0, left: -20 }}>
                                            <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                            <XAxis dataKey="rating" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                                            <YAxis tick={{ fontSize: 11 }} axisLine={false} tickLine={false} allowDecimals={false} />
                                            <Tooltip formatter={(value: any) => [value, 'Reviews']} />
                                            <Bar dataKey="count" radius={[4, 4, 0, 0]} maxBarSize={48}>
                                                {ratingDistribution.map((_, idx) => (
                                                    <Cell key={idx} fill={RATING_COLORS[idx % RATING_COLORS.length]} />
                                                ))}
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
                                        <ClipboardList className="h-4 w-4 text-blue-500" /> Status Breakdown
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center gap-6">
                                        <div style={{ width: 160, height: 160 }}>
                                            <ResponsiveContainer width="100%" height="100%">
                                                <PieChart>
                                                    <Pie data={statusChartData} dataKey="count" nameKey="label" cx="50%" cy="50%" outerRadius={70} innerRadius={42} paddingAngle={2}>
                                                        {statusChartData.map((entry, idx) => (
                                                            <Cell key={idx} fill={entry.color} />
                                                        ))}
                                                    </Pie>
                                                    <Tooltip />
                                                </PieChart>
                                            </ResponsiveContainer>
                                        </div>
                                        <div className="space-y-1.5 text-sm">
                                            {statusChartData.map((d) => (
                                                <div key={d.status} className="flex items-center gap-2">
                                                    <div className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: d.color }} />
                                                    <span className="text-slate-600 capitalize">{d.label}: <span className="font-medium text-foreground">{d.count}</span></span>
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
                        <CardTitle className="text-sm font-medium">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div className="sm:col-span-2">
                            <Label className="text-xs text-slate-500">Search</Label>
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
                                <Input placeholder="Search by staff or reviewer name..." value={filters.q || ''} onChange={(e) => onFilter({ q: e.target.value })} className="pl-9" />
                            </div>
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Status</Label>
                            <Select value={filters.status ?? NONE} onValueChange={(v) => onFilter({ status: v === NONE ? null : v })}>
                                <SelectTrigger><SelectValue placeholder="All statuses" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All Statuses</SelectItem>
                                    {statuses.map((s) => (
                                        <SelectItem key={s} value={s} className="capitalize">{s.replace(/_/g, ' ')}</SelectItem>
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
                                        <TableCell className="font-medium">{(review.staff_user ?? review.employee)?.name ?? 'Unknown'}</TableCell>
                                        <TableCell>{review.reviewer?.name ?? 'Unknown'}</TableCell>
                                        <TableCell className="text-sm text-slate-600">
                                            {formatDate(review.review_period_start)} - {formatDate(review.review_period_end)}
                                        </TableCell>
                                        <TableCell>{formatDate(review.scheduled_at)}</TableCell>
                                        <TableCell>
                                            <Badge className={getStatusColor(review.status)}>{review.status.replace(/_/g, ' ')}</Badge>
                                        </TableCell>
                                        <TableCell>{renderRating(review.overall_rating)}</TableCell>
                                        <TableCell>
                                            <Link href={`/hr/performance/reviews/${review.id}`} className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted">View</Link>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!reviews.data.length && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-8 text-center text-sm text-slate-500">No performance reviews found.</TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {reviews?.links?.length ? <LaravelPagination links={reviews.links} /> : null}

                {probationReviews.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <ShieldCheck className="h-4 w-4 text-blue-500" /> Probation Reviews
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Staff Member</TableHead>
                                        <TableHead>Reviewer</TableHead>
                                        <TableHead>Review #</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Recommendation</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {probationReviews.map((pr) => (
                                        <TableRow key={pr.id}>
                                            <TableCell className="font-medium">{pr.employee?.name ?? 'Unknown'}</TableCell>
                                            <TableCell>{pr.reviewer?.name ?? 'Unknown'}</TableCell>
                                            <TableCell>#{pr.review_number}</TableCell>
                                            <TableCell>{formatDate(pr.review_date)}</TableCell>
                                            <TableCell><Badge className={getStatusColor(pr.status)}>{pr.status.replace(/_/g, ' ')}</Badge></TableCell>
                                            <TableCell>
                                                {pr.recommendation ? (
                                                    <Badge className={getRecommendationColor(pr.recommendation)}>{pr.recommendation}</Badge>
                                                ) : (
                                                    <span className="text-slate-400">Pending</span>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
