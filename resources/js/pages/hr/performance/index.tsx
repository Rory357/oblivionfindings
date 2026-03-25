import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Head, Link, router } from '@inertiajs/react';
import { ClipboardList, AlertTriangle, Calendar, Plus, Search } from 'lucide-react';

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
        case 'overdue':
            return 'bg-red-100 text-red-800 border-red-200';
        case 'in_progress':
            return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        default:
            return 'bg-slate-100 text-slate-800 border-slate-200';
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
    filters,
    can,
}: Props) {
    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/performance', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Performance & Supervision" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Performance & Supervision</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Supervision notes, performance reviews, and staff development
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/hr/performance/reviews">
                            <Button size="sm" variant="outline">
                                <ClipboardList className="mr-1.5 h-4 w-4" />
                                Reviews
                            </Button>
                        </Link>
                        {can.manage && (
                            <Link href="/hr/performance/supervision/create">
                                <Button size="sm">
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add Note
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-slate-500">Notes This Month</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-2">
                                <ClipboardList className="h-5 w-5 text-blue-500" />
                                <div className="text-2xl font-bold">{notesThisMonth}</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-slate-500">Overdue Reviews</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-2">
                                {overdueReviews > 0 && <AlertTriangle className="h-5 w-5 text-red-500" />}
                                <div className={`text-2xl font-bold ${overdueReviews > 0 ? 'text-red-600' : ''}`}>
                                    {overdueReviews}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-slate-500">1:1 Overdue</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{oneToOneSla.overdue_count}</div>
                            <p className="text-xs text-slate-500">{oneToOneSla.due_soon_count} due in next 7 days</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-slate-500">Open Action Plans</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{engagementActionPlanSla.open_total}</div>
                            <p className="text-xs text-slate-500">{engagementActionPlanSla.overdue} overdue</p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center gap-3">
                            <CardTitle className="text-base">Manager Filters</CardTitle>
                            <div className="w-64">
                                <Select
                                    value={filters.staff_id ? String(filters.staff_id) : '__all__'}
                                    onValueChange={(value) => onFilter({ staff_id: value === '__all__' ? null : value })}
                                >
                                    <SelectTrigger><SelectValue placeholder="All staff" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">All staff</SelectItem>
                                        {staff.map((row) => (
                                            <SelectItem key={row.id} value={String(row.id)}>{row.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardHeader>
                </Card>

                {upcomingReviews.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Calendar className="h-5 w-5 text-blue-500" />
                                Upcoming Reviews
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Staff Member</TableHead>
                                        <TableHead>Reviewer</TableHead>
                                        <TableHead>Scheduled</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {upcomingReviews.map((review) => (
                                        <TableRow key={review.id}>
                                            <TableCell className="font-medium">{review.staff_user.name}</TableCell>
                                            <TableCell>{review.reviewer.name}</TableCell>
                                            <TableCell>{formatDate(review.scheduled_at)}</TableCell>
                                            <TableCell>
                                                <Badge className={getStatusColor(review.status)}>
                                                    {review.status.replace(/_/g, ' ')}
                                                </Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Competency Gaps</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {competencyGaps.map((gap) => (
                                <div key={gap.id} className="rounded-md border p-3">
                                    <p className="font-medium">{gap.employee_name}</p>
                                    <p className="text-xs text-slate-500">{gap.title}</p>
                                    <p className="text-xs text-slate-500">
                                        {gap.competency_area ?? 'General'} - Level {gap.current_level ?? '-'} to {gap.target_level ?? '-'} (gap {gap.gap})
                                    </p>
                                </div>
                            ))}
                            {competencyGaps.length === 0 && (
                                <p className="text-sm text-slate-500">No active competency gaps for current filters.</p>
                            )}
                            <div>
                                <Link href="/hr/development/goals">
                                    <Button variant="outline" size="sm">Open Development Goals</Button>
                                </Link>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">1:1 Session Follow-up</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {oneToOneSla.due_rows.slice(0, 8).map((row) => (
                                <div key={row.id} className="flex items-center justify-between rounded-md border p-3">
                                    <div>
                                        <p className="font-medium">{row.employee_name}</p>
                                        <p className="text-xs text-slate-500">Supervisor: {row.supervisor_name}</p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-xs text-slate-500">{formatDate(row.next_session_date)}</p>
                                        <Badge className={row.is_overdue ? 'bg-red-100 text-red-800 border-red-200' : 'bg-blue-100 text-blue-800 border-blue-200'}>
                                            {row.is_overdue ? 'overdue' : 'scheduled'}
                                        </Badge>
                                    </div>
                                </div>
                            ))}
                            {oneToOneSla.due_rows.length === 0 && (
                                <p className="text-sm text-slate-500">No upcoming 1:1 sessions for current filters.</p>
                            )}
                            <div className="flex items-center gap-2">
                                <Link href="/hr/performance/supervision/create">
                                    <Button variant="outline" size="sm">Schedule 1:1</Button>
                                </Link>
                                <Link href="/hr/wellbeing">
                                    <Button variant="ghost" size="sm">Open Engagement Plans</Button>
                                </Link>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Supervision Notes</CardTitle>
                            <div className="w-64">
                                <div className="relative">
                                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
                                    <Input
                                        placeholder="Search notes..."
                                        value={filters.q || ''}
                                        onChange={(e) => onFilter({ q: e.target.value })}
                                        className="pl-9"
                                    />
                                </div>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Staff Member</TableHead>
                                    <TableHead>Supervisor</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Summary</TableHead>
                                    <TableHead className="w-20"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {supervisionNotes.data.map((note) => (
                                    <TableRow key={note.id}>
                                        <TableCell className="font-medium">{note.staff_user.name}</TableCell>
                                        <TableCell>{note.supervisor.name}</TableCell>
                                        <TableCell>{formatDate(note.date)}</TableCell>
                                        <TableCell className="max-w-xs truncate text-sm text-slate-600">
                                            {note.summary}
                                        </TableCell>
                                        <TableCell>
                                            <Link href={`/hr/performance/supervision/${note.id}`}>
                                                <Button variant="ghost" size="sm">
                                                    View
                                                </Button>
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!supervisionNotes.data.length && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="py-8 text-center text-sm text-slate-500">
                                            No supervision notes found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {supervisionNotes?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {supervisionNotes.links.map((l: any) => (
                            <button
                                key={l.label}
                                disabled={!l.url}
                                className={`rounded-md border px-3 py-2 text-xs ${l.active ? 'bg-muted' : 'hover:bg-muted'}`}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
