import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { type BreadcrumbItem } from '@/types';
import {
    BarChart3,
    CheckCircle2,
    Clock,
    Eye,
    MessageSquare,
    Plus,
    Send,
} from 'lucide-react';
import { useState } from 'react';

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

const statusConfig: Record<string, { bg: string; text: string; dot: string; label: string }> = {
    pending: { bg: 'bg-amber-50', text: 'text-amber-700', dot: 'bg-amber-400', label: 'Pending' },
    completed: { bg: 'bg-emerald-50', text: 'text-emerald-700', dot: 'bg-emerald-400', label: 'Completed' },
    declined: { bg: 'bg-red-50', text: 'text-red-700', dot: 'bg-red-400', label: 'Declined' },
    expired: { bg: 'bg-slate-50', text: 'text-slate-600', dot: 'bg-slate-400', label: 'Expired' },
};

const reviewTypeConfig: Record<string, { label: string; color: string }> = {
    peer: { label: 'Peer', color: 'bg-blue-100 text-blue-700' },
    manager: { label: 'Manager', color: 'bg-violet-100 text-violet-700' },
    direct_report: { label: 'Direct Report', color: 'bg-emerald-100 text-emerald-700' },
    self: { label: 'Self', color: 'bg-amber-100 text-amber-700' },
};

function formatDate(value?: string | null): string {
    if (!value) {
        return '\u2014';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });
}

function getInitials(name: string) {
    return name
        .split(' ')
        .map((part) => part[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

const AVATAR_COLORS = [
    'bg-blue-500',
    'bg-violet-500',
    'bg-emerald-500',
    'bg-amber-500',
    'bg-pink-500',
    'bg-cyan-500',
    'bg-rose-500',
    'bg-indigo-500',
];

function avatarColor(id: number) {
    return AVATAR_COLORS[id % AVATAR_COLORS.length];
}

export default function FeedbackIndex({ requests, pendingCount, stats, can }: Props) {
    const [statusFilter, setStatusFilter] = useState<string | null>(null);

    const allData = requests.data;
    const totalCount = stats?.total ?? allData.length;
    const pendingTotal = stats?.pending ?? pendingCount ?? allData.filter((request) => request.status === 'pending').length;
    const completedCount = stats?.completed ?? allData.filter((request) => request.status === 'completed').length;
    const responseRate = totalCount > 0 ? Math.round((completedCount / totalCount) * 100) : 0;

    const filtered = statusFilter
        ? allData.filter((request) => request.status === statusFilter)
        : allData;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="360 Feedback" />

            <div className="space-y-6 p-4 lg:p-6">
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-600 via-violet-600 to-purple-700 p-6 text-white shadow-lg">
                    <div className="absolute -right-10 -top-10 h-40 w-40 rounded-full bg-white/5" />
                    <div className="absolute -bottom-8 right-20 h-24 w-24 rounded-full bg-white/5" />
                    <div className="absolute left-1/3 -top-4 h-28 w-28 rounded-full bg-white/5" />

                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-bold">360-Degree Feedback</h1>
                            <p className="mt-1 text-white/70">Manage and respond to feedback requests across your team</p>
                        </div>

                        <div className="flex items-center gap-3">
                            <div className="flex items-center gap-6">
                                {pendingTotal > 0 && (
                                    <>
                                        <div className="text-center">
                                            <div className="text-3xl font-bold">{pendingTotal}</div>
                                            <div className="text-[10px] uppercase tracking-wider text-white/60">Pending</div>
                                        </div>
                                        <div className="h-10 w-px bg-white/20" />
                                    </>
                                )}

                                <div className="text-center">
                                    <div className="text-3xl font-bold">{responseRate}%</div>
                                    <div className="text-[10px] uppercase tracking-wider text-white/60">Response Rate</div>
                                </div>
                            </div>

                            {can.manage && (
                                <Button
                                    size="sm"
                                    className="ml-4 gap-1.5 bg-white text-violet-700 shadow-md hover:bg-white/90"
                                    asChild
                                >
                                    <Link href="/hr/feedback/request">
                                        <Plus className="h-4 w-4" />
                                        Request Feedback
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {[
                        {
                            label: 'Total Requests',
                            value: totalCount,
                            icon: Send,
                            gradient: 'from-violet-500/10 to-purple-500/5',
                            iconBg: 'bg-violet-100',
                            iconColor: 'text-violet-600',
                            hover: 'hover:border-violet-300',
                        },
                        {
                            label: 'Pending',
                            value: pendingTotal,
                            icon: Clock,
                            gradient: 'from-amber-500/10 to-yellow-500/5',
                            iconBg: 'bg-amber-100',
                            iconColor: 'text-amber-600',
                            hover: 'hover:border-amber-300',
                        },
                        {
                            label: 'Completed',
                            value: completedCount,
                            icon: CheckCircle2,
                            gradient: 'from-emerald-500/10 to-green-500/5',
                            iconBg: 'bg-emerald-100',
                            iconColor: 'text-emerald-600',
                            hover: 'hover:border-emerald-300',
                        },
                        {
                            label: 'Response Rate',
                            value: `${responseRate}%`,
                            icon: BarChart3,
                            gradient: 'from-blue-500/10 to-indigo-500/5',
                            iconBg: 'bg-blue-100',
                            iconColor: 'text-blue-600',
                            hover: 'hover:border-blue-300',
                        },
                    ].map((kpi) => {
                        const Icon = kpi.icon;

                        return (
                            <Card
                                key={kpi.label}
                                className={`group overflow-hidden bg-gradient-to-br ${kpi.gradient} transition-all ${kpi.hover} hover:shadow-md`}
                            >
                                <CardContent className="pt-5">
                                    <div className="flex items-start justify-between">
                                        <div>
                                            <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                                                {kpi.label}
                                            </p>
                                            <p className="mt-1 text-3xl font-bold tracking-tight">{kpi.value}</p>
                                        </div>

                                        <div
                                            className={`flex h-10 w-10 items-center justify-center rounded-xl ${kpi.iconBg} transition-transform group-hover:scale-110`}
                                        >
                                            <Icon className={`h-5 w-5 ${kpi.iconColor}`} />
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                <div className="flex items-center gap-2">
                    {[
                        { key: null, label: 'All', count: totalCount },
                        { key: 'pending', label: 'Pending', count: pendingTotal },
                        { key: 'completed', label: 'Completed', count: completedCount },
                    ].map((tab) => (
                        <button
                            key={tab.label}
                            onClick={() => setStatusFilter(tab.key)}
                            className={`flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors ${
                                statusFilter === tab.key
                                    ? 'bg-primary text-white shadow-sm'
                                    : 'bg-muted text-muted-foreground hover:bg-muted/80'
                            }`}
                        >
                            {tab.label}
                            <Badge
                                variant="secondary"
                                className={`text-[9px] ${statusFilter === tab.key ? 'bg-white/20 text-white' : ''}`}
                            >
                                {tab.count}
                            </Badge>
                        </button>
                    ))}
                </div>

                {filtered.length === 0 ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-violet-50">
                                <MessageSquare className="h-8 w-8 text-violet-400" />
                            </div>
                            <p className="font-medium">No Feedback Requests</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {statusFilter ? `No ${statusFilter} feedback requests.` : 'Start by requesting feedback for a team member.'}
                            </p>
                            {can.manage && !statusFilter && (
                                <Button className="mt-4 gap-1.5 bg-violet-600 hover:bg-violet-700" size="sm" asChild>
                                    <Link href="/hr/feedback/request">
                                        <Plus className="h-3.5 w-3.5" />
                                        Request Feedback
                                    </Link>
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-3">
                        {filtered.map((request) => {
                            const status = statusConfig[request.status] || statusConfig.pending;
                            const reviewType = reviewTypeConfig[request.review_type] || {
                                label: request.review_type,
                                color: 'bg-slate-100 text-slate-600',
                            };

                            return (
                                <Card key={request.id} className="group overflow-hidden transition-all hover:border-violet-200 hover:shadow-md">
                                    <CardContent className="p-4">
                                        <div className="flex items-center justify-between gap-4">
                                            <div className="min-w-0 flex items-center gap-3">
                                                <div
                                                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white ${avatarColor(request.subject?.id ?? 0)}`}
                                                >
                                                    {getInitials(request.subject?.name ?? '?')}
                                                </div>

                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-semibold">
                                                        Feedback for {request.subject?.name ?? 'Unknown'}
                                                    </p>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                        <Badge className={`border-0 text-[9px] ${status.bg} ${status.text}`}>
                                                            <span className={`mr-1 inline-block h-1.5 w-1.5 rounded-full ${status.dot}`} />
                                                            {status.label}
                                                        </Badge>
                                                        <Badge className={`border-0 text-[9px] ${reviewType.color}`}>
                                                            {reviewType.label}
                                                        </Badge>
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-4">
                                                <div className="hidden text-right text-[11px] text-muted-foreground sm:block">
                                                    <p>
                                                        Reviewer:{' '}
                                                        <span className="font-medium text-foreground">
                                                            {request.reviewer?.name ?? 'Unknown'}
                                                        </span>
                                                    </p>
                                                    <p>
                                                        {request.status === 'completed'
                                                            ? `Completed ${formatDate(request.completed_at)}`
                                                            : request.due_date
                                                                ? `Due ${formatDate(request.due_date)}`
                                                                : `Created ${formatDate(request.created_at)}`}
                                                    </p>
                                                </div>

                                                <div className="flex gap-1.5">
                                                    {request.status === 'pending' && (
                                                        <Button size="sm" className="gap-1 bg-violet-600 text-xs hover:bg-violet-700" asChild>
                                                            <Link href={`/hr/feedback/${request.id}/respond`}>
                                                                <MessageSquare className="h-3 w-3" />
                                                                Respond
                                                            </Link>
                                                        </Button>
                                                    )}

                                                    {can.manage && request.status === 'completed' && (
                                                        <Button variant="outline" size="sm" className="gap-1 text-xs" asChild>
                                                            <Link href={`/hr/feedback/summary/${request.subject?.id}`}>
                                                                <Eye className="h-3 w-3" />
                                                                Summary
                                                            </Link>
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}

                {requests.links?.length > 3 && <LaravelPagination links={requests.links} />}
            </div>
        </AppLayout>
    );
}
