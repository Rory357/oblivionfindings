import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import { Plus, MessageSquare, Eye, Clock, CheckCircle, AlertTriangle, Inbox } from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { ProgressRing } from '@/components/fleet-charts';

type User = { id: number; name: string };

type FeedbackRequest = {
    id: number;
    subject: User | null;
    requester: User | null;
    reviewer: User | null;
    review_type: string;
    status: string;
    due_date: string | null;
    completed_at: string | null;
    created_at: string;
};

type Props = {
    requests: {
        data: FeedbackRequest[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    pendingCount: number;
    stats?: { total: number; pending: number; completed: number; overdue: number };
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Performance', href: '/hr/performance' },
    { title: '360 Feedback', href: '/hr/feedback' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    pending: { className: 'border-amber-500/30 text-amber-700 bg-amber-50', label: 'Pending' },
    completed: { className: 'border-emerald-500/30 text-emerald-700 bg-emerald-50', label: 'Completed' },
    declined: { className: 'border-red-500/30 text-red-700 bg-red-50', label: 'Declined' },
    expired: { className: 'border-slate-500/30 text-slate-600 bg-slate-50', label: 'Expired' },
};

const reviewTypeLabels: Record<string, string> = {
    peer: 'Peer Review',
    manager: 'Manager Review',
    direct_report: 'Direct Report',
    self: 'Self Assessment',
};

export default function FeedbackIndex({ requests, pendingCount, stats, can }: Props) {
    const completionPct = stats && stats.total > 0 ? Math.round((stats.completed / stats.total) * 100) : 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="360 Feedback" />
            <div className="space-y-6">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">360-Degree Feedback</h1>
                        <p className="mt-0.5 text-sm text-slate-500">Manage and respond to feedback requests</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link href="/hr/performance">
                            <Button size="sm" variant="outline">Dashboard</Button>
                        </Link>
                        {can.manage && (
                            <Button asChild size="sm">
                                <Link href="/hr/feedback/request"><Plus className="mr-1.5 h-4 w-4" /> Request Feedback</Link>
                            </Button>
                        )}
                    </div>
                </div>

                {/* KPI Cards + Progress Ring */}
                {stats && (
                    <div className="grid gap-4 lg:grid-cols-5">
                        <Card className="border-l-4 border-l-blue-500 bg-blue-50/40">
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-medium text-blue-700">Total Requests</p>
                                    <div className="rounded-full bg-blue-100 p-1.5"><Inbox className="h-4 w-4 text-blue-600" /></div>
                                </div>
                                <span className="mt-1.5 block text-2xl font-bold text-blue-900">{stats.total}</span>
                            </CardContent>
                        </Card>
                        <Card className={`border-l-4 ${stats.pending > 0 ? 'border-l-amber-500 bg-amber-50/40' : 'border-l-slate-300 bg-slate-50/40'}`}>
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className={`text-xs font-medium ${stats.pending > 0 ? 'text-amber-700' : 'text-slate-600'}`}>Pending</p>
                                    <div className={`rounded-full p-1.5 ${stats.pending > 0 ? 'bg-amber-100' : 'bg-slate-100'}`}>
                                        <MessageSquare className={`h-4 w-4 ${stats.pending > 0 ? 'text-amber-600' : 'text-slate-500'}`} />
                                    </div>
                                </div>
                                <span className={`mt-1.5 block text-2xl font-bold ${stats.pending > 0 ? 'text-amber-800' : 'text-slate-800'}`}>{stats.pending}</span>
                            </CardContent>
                        </Card>
                        <Card className="border-l-4 border-l-emerald-500 bg-emerald-50/40">
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-medium text-emerald-700">Completed</p>
                                    <div className="rounded-full bg-emerald-100 p-1.5"><CheckCircle className="h-4 w-4 text-emerald-600" /></div>
                                </div>
                                <span className="mt-1.5 block text-2xl font-bold text-emerald-900">{stats.completed}</span>
                            </CardContent>
                        </Card>
                        <Card className={`border-l-4 ${stats.overdue > 0 ? 'border-l-red-500 bg-red-50/50' : 'border-l-slate-300 bg-slate-50/40'}`}>
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className={`text-xs font-medium ${stats.overdue > 0 ? 'text-red-700' : 'text-slate-600'}`}>Overdue</p>
                                    <div className={`rounded-full p-1.5 ${stats.overdue > 0 ? 'bg-red-100' : 'bg-slate-100'}`}>
                                        <AlertTriangle className={`h-4 w-4 ${stats.overdue > 0 ? 'text-red-600' : 'text-slate-500'}`} />
                                    </div>
                                </div>
                                <span className={`mt-1.5 block text-2xl font-bold ${stats.overdue > 0 ? 'text-red-700' : 'text-slate-800'}`}>{stats.overdue}</span>
                            </CardContent>
                        </Card>
                        {stats.total > 0 && (
                            <Card>
                                <CardContent className="flex items-center justify-center p-4">
                                    <ProgressRing value={completionPct} size={100} color="#8b5cf6" label="Completion" />
                                </CardContent>
                            </Card>
                        )}
                    </div>
                )}

                {/* Requests List */}
                <div className="grid gap-3">
                    {requests.data.map((req) => {
                        const config = statusConfig[req.status] || statusConfig.pending;
                        return (
                            <Card key={req.id}>
                                <CardHeader className="pb-2">
                                    <div className="flex items-start justify-between">
                                        <div>
                                            <CardTitle className="text-sm font-medium">
                                                Feedback for {req.subject?.name ?? 'Unknown'}
                                            </CardTitle>
                                            <div className="mt-1 flex items-center gap-2">
                                                <Badge variant="outline" className={config.className}>{config.label}</Badge>
                                                <Badge variant="secondary">{reviewTypeLabels[req.review_type] || req.review_type}</Badge>
                                            </div>
                                        </div>
                                        <div className="flex gap-2">
                                            {req.status === 'pending' && (
                                                <Button variant="default" size="sm" asChild>
                                                    <Link href={`/hr/feedback/${req.id}/respond`}>
                                                        <MessageSquare className="mr-1.5 h-3.5 w-3.5" /> Respond
                                                    </Link>
                                                </Button>
                                            )}
                                            {can.manage && req.status === 'completed' && (
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={`/hr/feedback/summary/${req.subject?.id}`}>
                                                        <Eye className="mr-1.5 h-3.5 w-3.5" /> Summary
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="pt-0">
                                    <div className="flex gap-6 text-xs text-muted-foreground">
                                        <span>Reviewer: {req.reviewer?.name ?? 'Unknown'}</span>
                                        <span>Requested by: {req.requester?.name ?? 'Unknown'}</span>
                                        {req.due_date && (
                                            <span className="flex items-center gap-1">
                                                <Clock className="h-3 w-3" /> Due: {req.due_date}
                                            </span>
                                        )}
                                        {req.completed_at && <span>Completed: {req.completed_at}</span>}
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                    {requests.data.length === 0 && (
                        <Card>
                            <CardContent className="py-12 text-center text-muted-foreground">
                                No feedback requests found.
                            </CardContent>
                        </Card>
                    )}
                </div>

                {requests.links?.length > 3 && <LaravelPagination links={requests.links} />}
            </div>
        </AppLayout>
    );
}
