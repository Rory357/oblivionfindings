import { PerformanceTabs } from '@/components/hr';
import { FeedbackHero } from '@/components/hr/feedback-hero';
import {
    ManageTemplatesDialog,
    RequestFeedbackWizard,
    reviewTypeLabel,
    type FeedbackWizardData,
} from '@/components/hr/feedback-wizards';
import { PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BellRing,
    Eye,
    FileText,
    MessageSquare,
    MoreHorizontal,
    Plus,
    XCircle,
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
    /** Request-wizard data — null for users without hr.performance.manage. */
    wizard?: FeedbackWizardData | null;
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
        dot: 'bg-muted-foreground',
        label: 'Cancelled',
    },
};

const reviewTypeConfig: Record<string, string> = {
    peer: 'bg-status-info-bg text-status-info',
    manager: 'bg-primary/10 text-primary',
    direct_report: 'bg-status-success-bg text-status-success',
    self: 'bg-status-warning-bg text-status-warning',
};

function formatDate(value?: string | null): string {
    if (!value) {
        return '—';
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

function isOverdue(request: FeedbackRequest): boolean {
    if (request.status !== 'pending' || !request.due_date) return false;
    const due = new Date(`${request.due_date}T23:59:59`);
    return !Number.isNaN(due.getTime()) && due.getTime() < Date.now();
}

type LifecycleAction = 'decline' | 'cancel';

const LIFECYCLE_COPY: Record<
    LifecycleAction,
    { title: string; blurb: string; cta: string }
> = {
    decline: {
        title: 'Decline this request?',
        blurb: 'The reviewer will no longer be asked for feedback. The request is marked as declined.',
        cta: 'Decline request',
    },
    cancel: {
        title: 'Cancel this request?',
        blurb: 'The request is withdrawn and marked as cancelled. No feedback will be collected.',
        cta: 'Cancel request',
    },
};

export default function FeedbackIndex({
    requests,
    pendingCount,
    stats,
    can,
    wizard,
}: Props) {
    const page = usePage();
    const authProps = page.props as {
        auth?: {
            user?: { id?: number };
            can?: { hr?: { performance?: { manage?: boolean } } };
        };
    };
    const authUserId = authProps.auth?.user?.id ?? null;
    const canManage =
        authProps.auth?.can?.hr?.performance?.manage ?? can.manage;

    const [statusFilter, setStatusFilter] = useState<string | null>(null);
    const [showRequestWizard, setShowRequestWizard] = useState(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).has('new'),
    );
    const [initialSubjectId] = useState<string | null>(() =>
        typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search).get('employee')
            : null,
    );
    const [showTemplates, setShowTemplates] = useState(false);
    const [confirmAction, setConfirmAction] = useState<{
        request: FeedbackRequest;
        action: LifecycleAction;
    } | null>(null);
    const [actionBusy, setActionBusy] = useState(false);
    const [remindedIds, setRemindedIds] = useState<number[]>([]);

    const allData = requests.data;
    const totalCount = stats?.total ?? allData.length;
    const pendingTotal =
        stats?.pending ??
        pendingCount ??
        allData.filter((request) => request.status === 'pending').length;
    const completedCount =
        stats?.completed ??
        allData.filter((request) => request.status === 'completed').length;
    const overdueCount =
        stats?.overdue ??
        allData.filter((request) => isOverdue(request)).length;

    const filtered =
        statusFilter === 'overdue'
            ? allData.filter((request) => isOverdue(request))
            : statusFilter
              ? allData.filter((request) => request.status === statusFilter)
              : allData;

    const remind = (request: FeedbackRequest) => {
        router.post(
            `/hr/feedback/${request.id}/remind`,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    setRemindedIds((ids) =>
                        ids.includes(request.id) ? ids : [...ids, request.id],
                    ),
            },
        );
    };

    const runLifecycleAction = () => {
        if (!confirmAction) return;
        setActionBusy(true);
        router.post(
            `/hr/feedback/${confirmAction.request.id}/${confirmAction.action}`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setConfirmAction(null),
                onFinish: () => setActionBusy(false),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="360 Feedback" />

            <PageLayout
                hero={
                    <FeedbackHero
                        stats={{
                            total: totalCount,
                            pending: pendingTotal,
                            completed: completedCount,
                            overdue: overdueCount,
                        }}
                        actions={
                            canManage ? (
                                <>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setShowTemplates(true)}
                                    >
                                        <FileText className="h-4 w-4" />
                                        Templates
                                    </Button>
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            setShowRequestWizard(true)
                                        }
                                    >
                                        <Plus className="h-4 w-4" />
                                        Request feedback
                                    </Button>
                                </>
                            ) : undefined
                        }
                    />
                }
            >
                <PerformanceTabs active="feedback" />

                <div className="flex flex-wrap items-center gap-2">
                    {[
                        { key: null, label: 'All', count: totalCount },
                        {
                            key: 'pending',
                            label: 'Pending',
                            count: pendingTotal,
                        },
                        {
                            key: 'overdue',
                            label: 'Overdue',
                            count: overdueCount,
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
                            {canManage && !statusFilter && (
                                <Button
                                    className="mt-4 gap-1.5"
                                    size="sm"
                                    onClick={() => setShowRequestWizard(true)}
                                >
                                    <Plus className="h-3.5 w-3.5" />
                                    Request feedback
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
                            const typeColor =
                                reviewTypeConfig[request.review_type] ??
                                'bg-muted text-muted-foreground';
                            const overdue = isOverdue(request);
                            const isReviewer =
                                request.reviewer?.id === authUserId;

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
                                                            className={`border-0 text-[9px] ${typeColor}`}
                                                        >
                                                            {reviewTypeLabel(
                                                                request.review_type,
                                                            )}
                                                        </Badge>
                                                        {overdue && (
                                                            <Badge className="border-0 bg-status-critical-bg text-[9px] text-status-critical">
                                                                Overdue
                                                            </Badge>
                                                        )}
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

                                                <div className="flex items-center gap-1.5">
                                                    {request.status ===
                                                        'pending' &&
                                                        isReviewer && (
                                                            <Button
                                                                size="sm"
                                                                className="gap-1 text-xs"
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

                                                    {canManage &&
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

                                                    {canManage &&
                                                        request.status ===
                                                            'pending' && (
                                                            <>
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className="gap-1 text-xs"
                                                                    disabled={remindedIds.includes(
                                                                        request.id,
                                                                    )}
                                                                    onClick={() =>
                                                                        remind(
                                                                            request,
                                                                        )
                                                                    }
                                                                >
                                                                    <BellRing className="h-3 w-3" />
                                                                    {remindedIds.includes(
                                                                        request.id,
                                                                    )
                                                                        ? 'Reminded'
                                                                        : 'Remind'}
                                                                </Button>
                                                                <DropdownMenu>
                                                                    <DropdownMenuTrigger
                                                                        asChild
                                                                    >
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="icon"
                                                                            className="h-8 w-8"
                                                                            aria-label="More actions"
                                                                        >
                                                                            <MoreHorizontal className="h-4 w-4" />
                                                                        </Button>
                                                                    </DropdownMenuTrigger>
                                                                    <DropdownMenuContent align="end">
                                                                        <DropdownMenuItem
                                                                            onClick={() =>
                                                                                setConfirmAction(
                                                                                    {
                                                                                        request,
                                                                                        action: 'decline',
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            <XCircle className="h-3.5 w-3.5" />
                                                                            Decline
                                                                            request
                                                                        </DropdownMenuItem>
                                                                        <DropdownMenuItem
                                                                            className="text-status-critical focus:text-status-critical"
                                                                            onClick={() =>
                                                                                setConfirmAction(
                                                                                    {
                                                                                        request,
                                                                                        action: 'cancel',
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            <XCircle className="h-3.5 w-3.5" />
                                                                            Cancel
                                                                            request
                                                                        </DropdownMenuItem>
                                                                    </DropdownMenuContent>
                                                                </DropdownMenu>
                                                            </>
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

            {canManage && wizard && showRequestWizard ? (
                <RequestFeedbackWizard
                    data={wizard}
                    initialSubjectId={initialSubjectId}
                    onClose={() => setShowRequestWizard(false)}
                />
            ) : null}

            {canManage && wizard && showTemplates ? (
                <ManageTemplatesDialog
                    templates={wizard.templates}
                    onClose={() => setShowTemplates(false)}
                />
            ) : null}

            <Dialog
                open={!!confirmAction}
                onOpenChange={(open) => !open && setConfirmAction(null)}
            >
                <DialogContent className="sm:max-w-md">
                    {confirmAction ? (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    {LIFECYCLE_COPY[confirmAction.action].title}
                                </DialogTitle>
                                <DialogDescription>
                                    Feedback on{' '}
                                    <strong>
                                        {confirmAction.request.subject?.name ??
                                            'Unknown'}
                                    </strong>{' '}
                                    from{' '}
                                    <strong>
                                        {confirmAction.request.reviewer?.name ??
                                            'Unknown'}
                                    </strong>
                                    .{' '}
                                    {LIFECYCLE_COPY[confirmAction.action].blurb}
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    onClick={() => setConfirmAction(null)}
                                >
                                    Keep request
                                </Button>
                                <Button
                                    variant="destructive"
                                    disabled={actionBusy}
                                    onClick={runLifecycleAction}
                                >
                                    {actionBusy
                                        ? 'Working…'
                                        : LIFECYCLE_COPY[confirmAction.action]
                                              .cta}
                                </Button>
                            </DialogFooter>
                        </>
                    ) : null}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
