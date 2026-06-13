import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    BarChart3,
    CheckCircle2,
    Clock,
    Eye,
    MessageCircle,
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
    stats?: {
        total: number;
        pending: number;
        completed: number;
        overdue: number;
    };
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Performance', href: '/hr/performance' },
    { title: '360 Feedback', href: '/hr/feedback' },
];

const statusConfig: Record<
    string,
    { bg: string; text: string; dot: string; label: string }
> = {
    pending: {
        bg: 'bg-status-warning-bg',
        text: 'text-status-warning',
        dot: 'bg-status-warning',
        label: 'Pending',
    },
    completed: {
        bg: 'bg-status-success-bg',
        text: 'text-status-success',
        dot: 'bg-status-success',
        label: 'Completed',
    },
    declined: {
        bg: 'bg-status-critical-bg',
        text: 'text-status-critical',
        dot: 'bg-status-critical',
        label: 'Declined',
    },
    expired: {
        bg: 'bg-muted',
        text: 'text-muted-foreground',
        dot: 'bg-muted',
        label: 'Expired',
    },
};

const reviewTypeConfig: Record<string, { label: string; color: string }> = {
    peer: { label: 'Peer', color: 'bg-status-info-bg text-status-info' },
    manager: { label: 'Manager', color: 'bg-primary/10 text-primary' },
    direct_report: {
        label: 'Direct Report',
        color: 'bg-status-success-bg text-status-success',
    },
    self: { label: 'Self', color: 'bg-status-warning-bg text-status-warning' },
};

function formatDate(value?: string | null): string {
    if (!value) {
        return '\u2014';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
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
    'bg-status-info',
    'bg-primary',
    'bg-status-success',
    'bg-status-warning',
    'bg-status-critical',
    'bg-status-info',
    'bg-status-critical',
    'bg-primary',
];

function avatarColor(id: number) {
    return AVATAR_COLORS[id % AVATAR_COLORS.length];
}

export default function FeedbackIndex({
    requests,
    pendingCount,
    stats,
    can,
}: Props) {
    const [statusFilter, setStatusFilter] = useState<string | null>(null);

    const allData = requests.data;
    const totalCount = stats?.total ?? allData.length;
    const pendingTotal =
        stats?.pending ??
        pendingCount ??
        allData.filter((request) => request.status === 'pending').length;
    const completedCount =
        stats?.completed ??
        allData.filter((request) => request.status === 'completed').length;
    const responseRate =
        totalCount > 0 ? Math.round((completedCount / totalCount) * 100) : 0;

    const filtered = statusFilter
        ? allData.filter((request) => request.status === statusFilter)
        : allData;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="360 Feedback" />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={MessageCircle}
                        title="360-Degree Feedback"
                        description="Manage and respond to feedback requests across your team."
                        stats={[
                            { label: 'Pending', value: pendingTotal },
                            { label: 'Response rate', value: `${responseRate}%` },
                            { label: 'Total', value: totalCount },
                            { label: 'Completed', value: completedCount },
                        ]}
                        actions={
                            can.manage ? (
                                <Button size="sm" asChild>
                                    <Link href="/hr/feedback/request">
                                        <Plus className="h-4 w-4" />
                                        Request Feedback
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                }
            >
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {[
                        {
                            label: 'Total Requests',
                            value: totalCount,
                            icon: Send,
                            gradient: 'from-primary/10 to-primary/5',
                            iconBg: 'bg-primary/10',
                            iconColor: 'text-primary',
                            hover: 'hover:border-primary',
                        },
                        {
                            label: 'Pending',
                            value: pendingTotal,
                            icon: Clock,
                            gradient:
                                'from-status-warning/10 to-status-warning/5',
                            iconBg: 'bg-status-warning-bg',
                            iconColor: 'text-status-warning',
                            hover: 'hover:border-status-warning/30',
                        },
                        {
                            label: 'Completed',
                            value: completedCount,
                            icon: CheckCircle2,
                            gradient:
                                'from-status-success/10 to-status-success/5',
                            iconBg: 'bg-status-success-bg',
                            iconColor: 'text-status-success',
                            hover: 'hover:border-status-success/30',
                        },
                        {
                            label: 'Response Rate',
                            value: `${responseRate}%`,
                            icon: BarChart3,
                            gradient: 'from-status-info/10 to-primary/5',
                            iconBg: 'bg-status-info-bg',
                            iconColor: 'text-status-info',
                            hover: 'hover:border-status-info/30',
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
                                            <p className="text-[11px] font-medium tracking-wider text-muted-foreground uppercase">
                                                {kpi.label}
                                            </p>
                                            <p className="mt-1 text-3xl font-bold tracking-tight">
                                                {kpi.value}
                                            </p>
                                        </div>

                                        <div
                                            className={`flex h-10 w-10 items-center justify-center rounded-xl ${kpi.iconBg} transition-transform group-hover:scale-110`}
                                        >
                                            <Icon
                                                className={`h-5 w-5 ${kpi.iconColor}`}
                                            />
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
                        {
                            key: 'pending',
                            label: 'Pending',
                            count: pendingTotal,
                        },
                        {
                            key: 'completed',
                            label: 'Completed',
                            count: completedCount,
                        },
                    ].map((tab) => (
                        <Button
                            type="button"
                            key={tab.label}
                            size="sm"
                            variant={
                                statusFilter === tab.key
                                    ? 'default'
                                    : 'secondary'
                            }
                            onClick={() => setStatusFilter(tab.key)}
                            className={`gap-1.5 text-xs ${statusFilter === tab.key ? 'text-primary-foreground' : 'text-muted-foreground'}`}
                        >
                            {tab.label}
                            <Badge
                                variant="secondary"
                                className={`text-[9px] ${statusFilter === tab.key ? 'bg-primary-foreground/20 text-primary-foreground' : ''}`}
                            >
                                {tab.count}
                            </Badge>
                        </Button>
                    ))}
                </div>

                {filtered.length === 0 ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10">
                                <MessageSquare className="h-8 w-8 text-primary" />
                            </div>
                            <p className="font-medium">No Feedback Requests</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {statusFilter
                                    ? `No ${statusFilter} feedback requests.`
                                    : 'Start by requesting feedback for a team member.'}
                            </p>
                            {can.manage && !statusFilter && (
                                <Button
                                    className="mt-4 gap-1.5 bg-primary hover:bg-primary"
                                    size="sm"
                                    asChild
                                >
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
                            const status =
                                statusConfig[request.status] ||
                                statusConfig.pending;
                            const reviewType = reviewTypeConfig[
                                request.review_type
                            ] || {
                                label: request.review_type,
                                color: 'bg-muted text-muted-foreground',
                            };

                            return (
                                <Card
                                    key={request.id}
                                    className="group overflow-hidden transition-all hover:border-primary hover:shadow-md"
                                >
                                    <CardContent className="p-4">
                                        <div className="flex items-center justify-between gap-4">
                                            <div className="flex min-w-0 items-center gap-3">
                                                <div
                                                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-bold text-primary-foreground ${avatarColor(request.subject?.id ?? 0)}`}
                                                >
                                                    {getInitials(
                                                        request.subject?.name ??
                                                            '?',
                                                    )}
                                                </div>

                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-semibold">
                                                        Feedback for{' '}
                                                        {request.subject
                                                            ?.name ?? 'Unknown'}
                                                    </p>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                        <Badge
                                                            className={`border-0 text-[9px] ${status.bg} ${status.text}`}
                                                        >
                                                            <span
                                                                className={`mr-1 inline-block h-1.5 w-1.5 rounded-full ${status.dot}`}
                                                            />
                                                            {status.label}
                                                        </Badge>
                                                        <Badge
                                                            className={`border-0 text-[9px] ${reviewType.color}`}
                                                        >
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
                                                            {request.reviewer
                                                                ?.name ??
                                                                'Unknown'}
                                                        </span>
                                                    </p>
                                                    <p>
                                                        {request.status ===
                                                        'completed'
                                                            ? `Completed ${formatDate(request.completed_at)}`
                                                            : request.due_date
                                                              ? `Due ${formatDate(request.due_date)}`
                                                              : `Created ${formatDate(request.created_at)}`}
                                                    </p>
                                                </div>

                                                <div className="flex gap-1.5">
                                                    {request.status ===
                                                        'pending' && (
                                                        <Button
                                                            size="sm"
                                                            className="gap-1 bg-primary text-xs hover:bg-primary"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/hr/feedback/${request.id}/respond`}
                                                            >
                                                                <MessageSquare className="h-3 w-3" />
                                                                Respond
                                                            </Link>
                                                        </Button>
                                                    )}

                                                    {can.manage &&
                                                        request.status ===
                                                            'completed' && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                className="gap-1 text-xs"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={`/hr/feedback/summary/${request.subject?.id}`}
                                                                >
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

                {requests.links?.length > 3 && (
                    <LaravelPagination links={requests.links} />
                )}
            </PageLayout>
        </AppLayout>
    );
}
