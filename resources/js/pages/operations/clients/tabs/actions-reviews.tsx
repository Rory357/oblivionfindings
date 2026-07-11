import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CalendarClock,
    CheckCircle2,
    FileWarning,
    ListChecks,
} from 'lucide-react';
import { useMemo } from 'react';

export type ClientActionReview = {
    type: string;
    severity: 'critical' | 'warning' | 'info' | string;
    due_at?: string | null;
    summary: string;
    deep_link: string;
    source_id?: number | null;
};

export type ClientActionsReviewsSummary = {
    open?: number;
    loaded?: number;
    has_more?: boolean;
    critical?: number;
    warning?: number;
};

type ActionsReviewsTabProps = {
    items: ClientActionReview[];
    summary: ClientActionsReviewsSummary;
    isLoading?: boolean;
};

const severityMeta = {
    critical: {
        label: 'Critical',
        className: 'bg-status-critical-bg text-status-critical',
        icon: AlertTriangle,
    },
    warning: {
        label: 'Review',
        className: 'bg-status-warning-bg text-status-warning',
        icon: FileWarning,
    },
    info: {
        label: 'Info',
        className: 'bg-status-info-bg text-status-info',
        icon: CalendarClock,
    },
};

const typeLabels: Record<string, string> = {
    overdue_follow_up: 'Overdue follow-up',
    open_follow_up: 'Open follow-up',
    flagged_note_review: 'Flagged note',
    document_expiring: 'Document review',
    risk_review_due: 'Risk review',
    care_plan_review_due: 'Care plan review',
    assessment_due: 'Assessment review',
    pending_consent_request: 'Consent request',
    pending_visit_request: 'Visit request',
};

const dueBuckets = [
    {
        key: 'overdue',
        label: 'Overdue',
        icon: AlertTriangle,
        className: 'bg-status-critical-bg text-status-critical',
    },
    {
        key: 'due_this_week',
        label: 'Due this week',
        icon: FileWarning,
        className: 'bg-status-warning-bg text-status-warning',
    },
    {
        key: 'upcoming',
        label: 'Upcoming',
        icon: CalendarClock,
        className: 'bg-status-info-bg text-status-info',
    },
    {
        key: 'flagged',
        label: 'Flagged',
        icon: AlertTriangle,
        className: 'bg-status-warning-bg text-status-warning',
    },
] as const;

type DueBucketKey = (typeof dueBuckets)[number]['key'];

function dateLabel(value?: string | null) {
    if (!value) return 'No due date';
    return new Intl.DateTimeFormat('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

function bucketForAction(item: ClientActionReview): DueBucketKey {
    if (item.type === 'flagged_note_review') return 'flagged';
    if (item.type.startsWith('overdue_')) return 'overdue';

    if (!item.due_at) return 'upcoming';

    const dueAt = new Date(item.due_at).getTime();
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const weekOut = new Date(today);
    weekOut.setDate(today.getDate() + 7);

    if (dueAt < today.getTime()) return 'overdue';
    if (dueAt <= weekOut.getTime()) return 'due_this_week';

    return 'upcoming';
}

export function ActionsReviewsTab({
    items,
    summary,
    isLoading = false,
}: ActionsReviewsTabProps) {
    const grouped = useMemo(() => {
        return items.reduce<Record<string, ClientActionReview[]>>(
            (groups, item) => {
                const key = bucketForAction(item);
                groups[key] = groups[key] ?? [];
                groups[key].push(item);
                return groups;
            },
            {},
        );
    }, [items]);

    if (isLoading) {
        return (
            <div className="space-y-6" aria-busy="true">
                <div className="grid gap-3 md:grid-cols-3">
                    {[0, 1, 2].map((item) => (
                        <div key={item} className="rounded-lg border p-4">
                            <Skeleton className="h-3 w-24" />
                            <Skeleton className="mt-3 h-8 w-12" />
                        </div>
                    ))}
                </div>
                <Skeleton className="h-72 rounded-lg" />
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="grid gap-3 md:grid-cols-3">
                <Metric
                    label={
                        summary.has_more ? 'Open actions shown' : 'Open actions'
                    }
                    value={
                        summary.has_more
                            ? `${summary.loaded ?? items.length}+`
                            : (summary.open ?? items.length)
                    }
                    icon={ListChecks}
                    tone="bg-primary/10 text-primary"
                />
                <Metric
                    label={summary.has_more ? 'Critical shown' : 'Critical'}
                    value={summary.critical ?? 0}
                    icon={AlertTriangle}
                    tone="bg-status-critical-bg text-status-critical"
                />
                <Metric
                    label={summary.has_more ? 'Review shown' : 'Review'}
                    value={summary.warning ?? 0}
                    icon={FileWarning}
                    tone="bg-status-warning-bg text-status-warning"
                />
            </div>

            {summary.has_more ? (
                <p className="rounded-md border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                    Additional open actions exist beyond this bounded profile
                    view. Open the owning profile tabs to review the full
                    queues.
                </p>
            ) : null}

            {items.length === 0 ? (
                <EmptyState
                    icon={CheckCircle2}
                    title="No open actions"
                    description="Review queues, follow-ups, expiring documents, risks, assessments, consents, and visit requests are clear."
                />
            ) : (
                <div className="space-y-4">
                    {dueBuckets.map((bucket) => {
                        const bucketItems = grouped[bucket.key] ?? [];
                        if (bucketItems.length === 0) return null;
                        const Icon = bucket.icon;

                        return (
                            <section
                                key={bucket.key}
                                className="rounded-lg border bg-card"
                            >
                                <div className="flex items-center gap-2 border-b px-4 py-3">
                                    <span
                                        className={cn(
                                            'rounded-lg p-1.5',
                                            bucket.className,
                                        )}
                                    >
                                        <Icon className="h-4 w-4" />
                                    </span>
                                    <h3 className="font-semibold">
                                        {bucket.label}
                                    </h3>
                                    <Badge variant="outline">
                                        {bucketItems.length}
                                    </Badge>
                                </div>
                                <div className="divide-y">
                                    {bucketItems.map((item, index) => {
                                        const severity =
                                            severityMeta[
                                                item.severity as keyof typeof severityMeta
                                            ] ?? severityMeta.info;

                                        return (
                                            <div
                                                key={`${item.type}-${item.source_id ?? index}`}
                                                className="flex flex-col gap-3 p-4 md:flex-row md:items-center md:justify-between"
                                            >
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <Badge
                                                            variant="secondary"
                                                            className="capitalize"
                                                        >
                                                            {typeLabels[
                                                                item.type
                                                            ] ??
                                                                item.type.replace(
                                                                    /_/g,
                                                                    ' ',
                                                                )}
                                                        </Badge>
                                                        <Badge
                                                            className={
                                                                severity.className
                                                            }
                                                        >
                                                            {severity.label}
                                                        </Badge>
                                                        <span className="text-xs text-muted-foreground">
                                                            Due{' '}
                                                            {dateLabel(
                                                                item.due_at,
                                                            )}
                                                        </span>
                                                    </div>
                                                    <p className="mt-2 text-sm font-medium">
                                                        {item.summary}
                                                    </p>
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                    className="min-h-10 shrink-0"
                                                >
                                                    <Link href={item.deep_link}>
                                                        Open
                                                        <ArrowRight className="ml-2 h-4 w-4" />
                                                    </Link>
                                                </Button>
                                            </div>
                                        );
                                    })}
                                </div>
                            </section>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function Metric({
    label,
    value,
    icon: Icon,
    tone,
}: {
    label: string;
    value: number | string;
    icon: typeof ListChecks;
    tone: string;
}) {
    return (
        <div className="rounded-lg border bg-card p-4">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="text-xs text-muted-foreground">{label}</p>
                    <p className="mt-1 text-2xl font-semibold">{value}</p>
                </div>
                <span className={cn('rounded-lg p-2', tone)}>
                    <Icon className="h-5 w-5" />
                </span>
            </div>
        </div>
    );
}
