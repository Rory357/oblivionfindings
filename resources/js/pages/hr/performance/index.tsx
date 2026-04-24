import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Head, Link, router } from '@inertiajs/react';
import {
    ClipboardList, AlertTriangle, Calendar, Plus, Search, ArrowRight, FileText,
    Target, Users, TrendingUp, TrendingDown, MessageSquare, ShieldAlert,
    Star, BarChart3,
} from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { useState } from 'react';
import {
    AreaChart, Area, BarChart, Bar, PieChart, Pie, Cell,
    XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
} from 'recharts';
import { HorizontalBarChart, ProgressRing } from '@/components/fleet-charts';

type BreadcrumbItem = { title: string; href: string };

type SupervisionNote = {
    id: number;
    staff_user: { id: number; name: string };
    supervisor: { id: number; name: string };
    date: string;
    summary: string;
    status?: string;
};

type UpcomingReview = {
    id: number;
    staff_user: { id: number; name: string };
    reviewer: { id: number; name: string };
    scheduled_at: string;
    status: string;
};

type RecentNote = {
    id: number;
    staff_user: { id: number; name: string };
    supervisor: { id: number; name: string };
    date: string;
    summary: string;
};

type Props = {
    supervisionNotes: {
        data: SupervisionNote[];
        links: any[];
    };
    upcomingReviews: UpcomingReview[];
    recentNotes: RecentNote[];
    staff: Array<{ id: number; name: string }>;
    oneToOneSla: {
        due_soon_count: number;
        overdue_count: number;
        due_rows: Array<{
            id: number;
            employee_name: string;
            supervisor_name: string;
            next_session_date: string | null;
            is_overdue: boolean;
        }>;
    };
    competencyGaps: Array<{
        id: number;
        title: string;
        employee_name: string;
        competency_area: string | null;
        current_level: number | null;
        target_level: number | null;
        gap: number;
        status: string;
        due_date: string | null;
    }>;
    engagementActionPlanSla: {
        open_total: number;
        overdue: number;
        due_next_7_days: number;
    };
    reviewCompletionTrend: Array<{ month: string; completed: number; total: number }>;
    notesPerMonth: Array<{ month: string; count: number }>;
    ratingDistribution: Array<{ rating: number; count: number }>;
    pipSummary: { active: number; completed: number; cancelled: number; total: number };
    feedbackSummary: { pending: number; completed: number; overdue: number };
    previousMonthNoteCount: number;
    filters: {
        q: string | null;
        staff_id: string | number | null;
    };
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Performance & Supervision', href: '/hr/performance' },
];

const RATING_COLORS = ['#ef4444', '#f59e0b', '#eab308', '#3b82f6', '#10b981'];
const PIP_COLORS = { active: '#ef4444', completed: '#10b981', cancelled: '#94a3b8' };

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
};

const getStatusColor = (status: string) => {
    switch (status) {
        case 'completed': return 'bg-green-100 text-green-800 border-green-200';
        case 'scheduled': return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'overdue': return 'bg-red-100 text-red-800 border-red-200';
        case 'in_progress': return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        default: return 'bg-muted text-foreground border-border';
    }
};

export default function PerformanceIndex({
    supervisionNotes,
    upcomingReviews,
    recentNotes,
    staff,
    oneToOneSla,
    competencyGaps,
    engagementActionPlanSla,
    reviewCompletionTrend,
    notesPerMonth,
    ratingDistribution,
    pipSummary,
    feedbackSummary,
    previousMonthNoteCount,
    filters,
    can,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/performance', { ...filters, ...next }, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });
    };

    const now = new Date();
    const thisMonth = now.getMonth();
    const thisYear = now.getFullYear();
    const notesThisMonth = recentNotes.filter((n) => {
        const d = new Date(n.date);
        return d.getMonth() === thisMonth && d.getFullYear() === thisYear;
    }).length;

    const overdueReviews = upcomingReviews.filter((r) => {
        return r.status === 'overdue' || (r.scheduled_at && new Date(r.scheduled_at) < now && r.status !== 'completed');
    }).length;

    const notesTrend = previousMonthNoteCount > 0
        ? Math.round(((notesThisMonth - previousMonthNoteCount) / previousMonthNoteCount) * 100)
        : null;

    // Data checks for conditional chart rendering
    const hasReviewTrend = reviewCompletionTrend.some((d) => d.total > 0);
    const hasRatings = ratingDistribution.some((d) => d.count > 0);
    const hasNotesTrend = notesPerMonth.some((d) => d.count > 0);
    const hasCompetencyGaps = competencyGaps.length > 0;
    const hasPips = pipSummary.total > 0;
    const feedbackTotal = feedbackSummary.pending + feedbackSummary.completed;
    const hasFeedback = feedbackTotal > 0;
    const hasAnyCharts = hasReviewTrend || hasRatings || hasNotesTrend || hasCompetencyGaps || hasPips || hasFeedback;

    const pipChartData = [
        { name: 'Active', value: pipSummary.active, color: PIP_COLORS.active },
        { name: 'Completed', value: pipSummary.completed, color: PIP_COLORS.completed },
        { name: 'Cancelled', value: pipSummary.cancelled, color: PIP_COLORS.cancelled },
    ].filter((d) => d.value > 0);

    const feedbackCompletionPct = feedbackTotal > 0 ? Math.round((feedbackSummary.completed / feedbackTotal) * 100) : 0;

    const competencyChartItems = competencyGaps.slice(0, 8).map((gap) => ({
        label: `${gap.employee_name}: ${gap.competency_area ?? 'General'}`,
        value: gap.gap,
        color: gap.gap >= 3 ? '#ef4444' : gap.gap >= 2 ? '#f59e0b' : '#3b82f6',
    }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Performance & Supervision" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-lg font-semibold">Performance & Supervision</h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            Supervision notes, performance reviews, and staff development
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <div className="w-40">
                            <Select
                                value={filters.staff_id ? String(filters.staff_id) : '__all__'}
                                onValueChange={(value) => onFilter({ staff_id: value === '__all__' ? null : value })}
                            >
                                <SelectTrigger className="h-8 text-xs">
                                    <SelectValue placeholder="All staff" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__all__">All staff</SelectItem>
                                    {staff.map((row) => (
                                        <SelectItem key={row.id} value={String(row.id)}>{row.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        {can.manage && (
                            <>
                                <Link href="/hr/performance/supervision/create">
                                    <Button size="sm" disabled={processing}>
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Add Note
                                    </Button>
                                </Link>
                                <Link href="/hr/performance/reviews/create">
                                    <Button size="sm" variant="outline" disabled={processing}>
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New Review
                                    </Button>
                                </Link>
                            </>
                        )}
                    </div>
                </div>

                {/* KPI Cards */}
                <div className="grid gap-3 grid-cols-2 lg:grid-cols-4">
                    <Card className="border-l-4 border-l-blue-500 bg-blue-50/40">
                        <CardContent className="p-4">
                            <div className="flex items-center justify-between">
                                <p className="text-xs font-medium text-blue-700">Notes This Month</p>
                                <div className="rounded-full bg-blue-100 p-1.5">
                                    <ClipboardList className="h-4 w-4 text-blue-600" />
                                </div>
                            </div>
                            <div className="mt-1.5 flex items-baseline gap-2">
                                <span className="text-2xl font-bold text-blue-900">{notesThisMonth}</span>
                                {notesTrend !== null && notesTrend !== 0 && (
                                    <span className={`flex items-center text-xs ${notesTrend > 0 ? 'text-green-600' : 'text-red-500'}`}>
                                        {notesTrend > 0 ? <TrendingUp className="mr-0.5 h-3 w-3" /> : <TrendingDown className="mr-0.5 h-3 w-3" />}
                                        {Math.abs(notesTrend)}%
                                    </span>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                    <Link href="/hr/performance/reviews">
                        <Card className={`h-full border-l-4 cursor-pointer transition-colors hover:opacity-80 ${overdueReviews > 0 ? 'border-l-red-500 bg-red-50/50' : 'border-l-emerald-500 bg-emerald-50/40'}`}>
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className={`text-xs font-medium ${overdueReviews > 0 ? 'text-red-700' : 'text-emerald-700'}`}>Overdue Reviews</p>
                                    <div className={`rounded-full p-1.5 ${overdueReviews > 0 ? 'bg-red-100' : 'bg-emerald-100'}`}>
                                        <AlertTriangle className={`h-4 w-4 ${overdueReviews > 0 ? 'text-red-600' : 'text-emerald-600'}`} />
                                    </div>
                                </div>
                                <span className={`mt-1.5 block text-2xl font-bold ${overdueReviews > 0 ? 'text-red-700' : 'text-emerald-900'}`}>{overdueReviews}</span>
                            </CardContent>
                        </Card>
                    </Link>
                    <Card className={`border-l-4 ${oneToOneSla.overdue_count > 0 ? 'border-l-amber-500 bg-amber-50/50' : 'border-l-purple-500 bg-primary/10/40'}`}>
                        <CardContent className="p-4">
                            <div className="flex items-center justify-between">
                                <p className={`text-xs font-medium ${oneToOneSla.overdue_count > 0 ? 'text-amber-700' : 'text-primary'}`}>1:1 Overdue</p>
                                <div className={`rounded-full p-1.5 ${oneToOneSla.overdue_count > 0 ? 'bg-amber-100' : 'bg-primary/10'}`}>
                                    <Users className={`h-4 w-4 ${oneToOneSla.overdue_count > 0 ? 'text-amber-600' : 'text-primary'}`} />
                                </div>
                            </div>
                            <span className={`mt-1.5 block text-2xl font-bold ${oneToOneSla.overdue_count > 0 ? 'text-amber-800' : 'text-primary'}`}>{oneToOneSla.overdue_count}</span>
                            <p className={`mt-0.5 text-xs ${oneToOneSla.overdue_count > 0 ? 'text-amber-600' : 'text-primary'}`}>{oneToOneSla.due_soon_count} due in 7 days</p>
                        </CardContent>
                    </Card>
                    <Card className={`border-l-4 ${engagementActionPlanSla.overdue > 0 ? 'border-l-orange-500 bg-orange-50/50' : 'border-l-cyan-500 bg-cyan-50/40'}`}>
                        <CardContent className="p-4">
                            <div className="flex items-center justify-between">
                                <p className={`text-xs font-medium ${engagementActionPlanSla.overdue > 0 ? 'text-orange-700' : 'text-cyan-700'}`}>Open Action Plans</p>
                                <div className={`rounded-full p-1.5 ${engagementActionPlanSla.overdue > 0 ? 'bg-orange-100' : 'bg-cyan-100'}`}>
                                    <Target className={`h-4 w-4 ${engagementActionPlanSla.overdue > 0 ? 'text-orange-600' : 'text-cyan-600'}`} />
                                </div>
                            </div>
                            <span className={`mt-1.5 block text-2xl font-bold ${engagementActionPlanSla.overdue > 0 ? 'text-orange-800' : 'text-cyan-900'}`}>{engagementActionPlanSla.open_total}</span>
                            {engagementActionPlanSla.overdue > 0 && (
                                <p className="mt-0.5 text-xs text-orange-600">{engagementActionPlanSla.overdue} overdue</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Quick-nav links */}
                <div className="flex flex-wrap gap-2">
                    <Link href="/hr/performance/reviews">
                        <Button size="sm" variant="outline" disabled={processing}>
                            <ClipboardList className="mr-1.5 h-4 w-4" /> Reviews
                        </Button>
                    </Link>
                    <Link href="/hr/performance/competencies">
                        <Button size="sm" variant="outline" disabled={processing}>
                            <Target className="mr-1.5 h-4 w-4" /> Competencies
                        </Button>
                    </Link>
                    <Link href="/hr/performance/pips">
                        <Button size="sm" variant="outline" disabled={processing}>
                            <ShieldAlert className="mr-1.5 h-4 w-4" /> PIPs {pipSummary.active > 0 && <Badge className="ml-1.5 bg-red-100 text-red-700 border-red-200 text-[10px] px-1.5">{pipSummary.active}</Badge>}
                        </Button>
                    </Link>
                    <Link href="/hr/feedback">
                        <Button size="sm" variant="outline" disabled={processing}>
                            <MessageSquare className="mr-1.5 h-4 w-4" /> Feedback {feedbackSummary.pending > 0 && <Badge className="ml-1.5 bg-amber-100 text-amber-700 border-amber-200 text-[10px] px-1.5">{feedbackSummary.pending}</Badge>}
                        </Button>
                    </Link>
                </div>

                {/* Charts - only rendered when data exists */}
                {hasAnyCharts && (
                    <div className="grid gap-6 lg:grid-cols-2">
                        {/* Review Completion Trend */}
                        {hasReviewTrend && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                        <BarChart3 className="h-4 w-4 text-blue-500" />
                                        Review Completion Trend
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <ResponsiveContainer width="100%" height={220}>
                                        <AreaChart data={reviewCompletionTrend} margin={{ top: 5, right: 10, bottom: 0, left: -20 }}>
                                            <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                            <XAxis dataKey="month" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                                            <YAxis tick={{ fontSize: 11 }} axisLine={false} tickLine={false} allowDecimals={false} />
                                            <Tooltip />
                                            <Area type="monotone" dataKey="total" stroke="#3b82f6" fill="#3b82f6" fillOpacity={0.08} name="Total" />
                                            <Area type="monotone" dataKey="completed" stroke="#10b981" fill="#10b981" fillOpacity={0.2} name="Completed" />
                                            <Legend />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </CardContent>
                            </Card>
                        )}

                        {/* Rating Distribution */}
                        {hasRatings && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                        <Star className="h-4 w-4 text-amber-500" />
                                        Rating Distribution
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <ResponsiveContainer width="100%" height={220}>
                                        <BarChart data={ratingDistribution} margin={{ top: 5, right: 10, bottom: 0, left: -20 }}>
                                            <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                            <XAxis dataKey="rating" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                                            <YAxis tick={{ fontSize: 11 }} axisLine={false} tickLine={false} allowDecimals={false} />
                                            <Tooltip formatter={(value: any) => [value, 'Reviews']} />
                                            <Bar dataKey="count" radius={[4, 4, 0, 0]} maxBarSize={48} name="Reviews">
                                                {ratingDistribution.map((_, idx) => (
                                                    <Cell key={idx} fill={RATING_COLORS[idx % RATING_COLORS.length]} />
                                                ))}
                                            </Bar>
                                        </BarChart>
                                    </ResponsiveContainer>
                                </CardContent>
                            </Card>
                        )}

                        {/* Notes Frequency */}
                        {hasNotesTrend && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                        <FileText className="h-4 w-4 text-blue-500" />
                                        Supervision Notes Frequency
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <ResponsiveContainer width="100%" height={200}>
                                        <BarChart data={notesPerMonth} margin={{ top: 5, right: 10, bottom: 0, left: -20 }}>
                                            <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                            <XAxis dataKey="month" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                                            <YAxis tick={{ fontSize: 11 }} axisLine={false} tickLine={false} allowDecimals={false} />
                                            <Tooltip formatter={(value: any) => [value, 'Notes']} />
                                            <Bar dataKey="count" fill="#3b82f6" radius={[4, 4, 0, 0]} maxBarSize={40} name="Notes" />
                                        </BarChart>
                                    </ResponsiveContainer>
                                </CardContent>
                            </Card>
                        )}

                        {/* Competency Gaps */}
                        {hasCompetencyGaps && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                            <Target className="h-4 w-4 text-amber-500" />
                                            Competency Gaps
                                        </CardTitle>
                                        <Link href="/hr/development/goals">
                                            <Button variant="ghost" size="sm" className="text-muted-foreground hover:text-foreground">
                                                View All <ArrowRight className="ml-1 h-3 w-3" />
                                            </Button>
                                        </Link>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <HorizontalBarChart items={competencyChartItems} />
                                </CardContent>
                            </Card>
                        )}

                        {/* PIP Status */}
                        {hasPips && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                            <ShieldAlert className="h-4 w-4 text-red-500" />
                                            PIP Status
                                        </CardTitle>
                                        <Link href="/hr/performance/pips">
                                            <Button variant="ghost" size="sm" className="text-muted-foreground hover:text-foreground">
                                                View All <ArrowRight className="ml-1 h-3 w-3" />
                                            </Button>
                                        </Link>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center gap-6">
                                        <div style={{ width: 160, height: 160 }}>
                                        <ResponsiveContainer width="100%" height="100%">
                                            <PieChart>
                                                <Pie data={pipChartData} dataKey="value" nameKey="name" cx="50%" cy="50%" outerRadius={70} innerRadius={42} paddingAngle={2}>
                                                    {pipChartData.map((entry, idx) => (
                                                        <Cell key={idx} fill={entry.color} />
                                                    ))}
                                                </Pie>
                                                <Tooltip />
                                            </PieChart>
                                        </ResponsiveContainer>
                                    </div>
                                        <div className="space-y-2 text-sm">
                                            {pipChartData.map((d) => (
                                                <div key={d.name} className="flex items-center gap-2">
                                                    <div className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: d.color }} />
                                                    <span className="text-muted-foreground">{d.name}: <span className="font-medium text-foreground">{d.value}</span></span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Feedback Summary */}
                        {hasFeedback && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                            <MessageSquare className="h-4 w-4 text-primary" />
                                            360 Feedback
                                        </CardTitle>
                                        <Link href="/hr/feedback">
                                            <Button variant="ghost" size="sm" className="text-muted-foreground hover:text-foreground">
                                                View All <ArrowRight className="ml-1 h-3 w-3" />
                                            </Button>
                                        </Link>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center gap-6">
                                        <ProgressRing value={feedbackCompletionPct} size={120} color="#8b5cf6" label="Completed" />
                                        <div className="space-y-2 text-sm">
                                            <div className="flex items-center justify-between gap-4">
                                                <span className="text-muted-foreground">Pending</span>
                                                <span className="font-medium">{feedbackSummary.pending}</span>
                                            </div>
                                            <div className="flex items-center justify-between gap-4">
                                                <span className="text-muted-foreground">Completed</span>
                                                <span className="font-medium text-green-600">{feedbackSummary.completed}</span>
                                            </div>
                                            {feedbackSummary.overdue > 0 && (
                                                <div className="flex items-center justify-between gap-4">
                                                    <span className="text-muted-foreground">Overdue</span>
                                                    <span className="font-medium text-red-600">{feedbackSummary.overdue}</span>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                )}

                {/* Upcoming Reviews + 1:1 Follow-up side by side */}
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                    <Calendar className="h-4 w-4 text-blue-500" />
                                    Upcoming Reviews
                                </CardTitle>
                                <Link href="/hr/performance/reviews">
                                    <Button variant="ghost" size="sm" className="text-muted-foreground hover:text-foreground">
                                        View All <ArrowRight className="ml-1 h-3 w-3" />
                                    </Button>
                                </Link>
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            {upcomingReviews.length > 0 ? (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Staff</TableHead>
                                            <TableHead>Scheduled</TableHead>
                                            <TableHead>Status</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {upcomingReviews.slice(0, 5).map((review) => (
                                            <TableRow key={review.id}>
                                                <TableCell className="font-medium">{review.staff_user.name}</TableCell>
                                                <TableCell className="text-sm text-muted-foreground">{formatDate(review.scheduled_at)}</TableCell>
                                                <TableCell>
                                                    <Badge className={getStatusColor(review.status)}>
                                                        {review.status.replace(/_/g, ' ')}
                                                    </Badge>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            ) : (
                                <div className="flex flex-col items-center justify-center py-8 text-center">
                                    <Calendar className="mb-2 h-8 w-8 text-slate-300" />
                                    <p className="text-sm text-muted-foreground">No upcoming reviews</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                    <Users className="h-4 w-4 text-blue-500" />
                                    1:1 Session Follow-up
                                </CardTitle>
                                {can.manage && (
                                    <Link href="/hr/performance/supervision/create">
                                        <Button variant="ghost" size="sm" className="text-muted-foreground hover:text-foreground">
                                            Schedule <ArrowRight className="ml-1 h-3 w-3" />
                                        </Button>
                                    </Link>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {oneToOneSla.due_rows.length > 0 ? (
                                oneToOneSla.due_rows.slice(0, 5).map((row) => (
                                    <div key={row.id} className="flex items-center justify-between rounded-md border p-2.5">
                                        <div>
                                            <p className="text-sm font-medium">{row.employee_name}</p>
                                            <p className="text-xs text-muted-foreground">{row.supervisor_name}</p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-xs text-muted-foreground">{formatDate(row.next_session_date)}</p>
                                            <Badge className={`text-[10px] ${row.is_overdue ? 'bg-red-100 text-red-800 border-red-200' : 'bg-blue-100 text-blue-800 border-blue-200'}`}>
                                                {row.is_overdue ? 'overdue' : 'scheduled'}
                                            </Badge>
                                        </div>
                                    </div>
                                ))
                            ) : (
                                <div className="flex flex-col items-center justify-center py-8 text-center">
                                    <Users className="mb-2 h-8 w-8 text-slate-300" />
                                    <p className="text-sm text-muted-foreground">No upcoming 1:1 sessions</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Supervision Notes */}
                <Card>
                    <CardHeader className="pb-2">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-sm font-medium">Supervision Notes</CardTitle>
                            <div className="w-56">
                                <div className="relative">
                                    <Search className="absolute left-2.5 top-2 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        placeholder="Search notes..."
                                        value={filters.q || ''}
                                        onChange={(e) => onFilter({ q: e.target.value })}
                                        className="h-8 pl-9 text-xs"
                                        disabled={processing}
                                    />
                                </div>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        {supervisionNotes.data.length > 0 ? (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Staff Member</TableHead>
                                        <TableHead>Supervisor</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Summary</TableHead>
                                        <TableHead className="w-16"></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {supervisionNotes.data.map((note) => (
                                        <TableRow key={note.id}>
                                            <TableCell className="font-medium">{note.staff_user.name}</TableCell>
                                            <TableCell>{note.supervisor.name}</TableCell>
                                            <TableCell>{formatDate(note.date)}</TableCell>
                                            <TableCell className="max-w-xs truncate text-sm text-muted-foreground">{note.summary}</TableCell>
                                            <TableCell>
                                                <Link href={`/hr/performance/supervision/${note.id}`}>
                                                    <Button variant="ghost" size="sm" disabled={processing}>View</Button>
                                                </Link>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-8 text-center">
                                <FileText className="mb-2 h-8 w-8 text-slate-300" />
                                <p className="text-sm text-muted-foreground">
                                    {filters.q ? 'No notes match your search.' : 'No supervision notes yet.'}
                                </p>
                                {can.manage && !filters.q && (
                                    <Link href="/hr/performance/supervision/create" className="mt-2">
                                        <Button variant="outline" size="sm" disabled={processing}>
                                            <Plus className="mr-1.5 h-4 w-4" /> Add Note
                                        </Button>
                                    </Link>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {supervisionNotes?.links?.length ? (
                    <LaravelPagination links={supervisionNotes.links} />
                ) : null}
            </div>
        </AppLayout>
    );
}
