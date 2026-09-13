import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    BookOpen,
    CheckCircle2,
    ListChecks,
    Vote,
    type LucideIcon,
} from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly } from '@/lib/datetime';

/** One personal obligation from `GovernanceWorkQuery` (the My work feed). */
export interface MyWorkItem {
    id: string;
    kind: 'vote' | 'read' | 'act' | 'know' | string;
    source: { type: string; id: number; reference: string; href: string };
    title: string;
    reason: string;
    priority: string;
    status: string;
    due_date?: string | null;
    required_action?: {
        key: string;
        label: string;
        href: string;
        allowed: boolean;
        blocked_reason?: string | null;
    } | null;
}

export interface MyWorkTotals {
    all: number;
    vote: number;
    read: number;
    act: number;
    know: number;
    pending: number;
    overdue: number;
    blocked: number;
    completed: number;
}

/**
 * Home's preview of My work: the first items of `/governance/my-work` with
 * its FULL totals (DashboardController::myWork). `null` means the feed could
 * not be built.
 */
export interface MyWorkPreview {
    items: MyWorkItem[];
    totals: MyWorkTotals | null;
    pagination?: { total: number } | null;
    availability?: Record<string, string>;
    all_sources_succeeded?: boolean;
    href?: string;
}

interface MyNextActionsRailProps {
    myWork: MyWorkPreview | null | undefined;
}

const KIND_META: Record<string, { icon: LucideIcon; label: string }> = {
    vote: { icon: Vote, label: 'Vote' },
    read: { icon: BookOpen, label: 'Read' },
    act: { icon: ListChecks, label: 'Action' },
    know: { icon: Bell, label: 'Update' },
};

function statusBadge(status: string): { label: string; variant: StatusVariant } {
    switch (status) {
        case 'overdue':
            return { label: 'Overdue', variant: 'critical' };
        case 'due_soon':
            return { label: 'Due soon', variant: 'warning' };
        case 'blocked':
            return { label: 'Blocked', variant: 'critical' };
        default:
            return { label: 'Pending', variant: 'neutral' };
    }
}

/**
 * My work on Governance Home — the viewer's own actionable obligations
 * (votes, reading, assigned actions, updates), each with its due date, why it
 * matters and a direct action. The count is the same authorised total as
 * `/governance/my-work` — never the number of rows displayed — and a feed
 * that failed (or is partial) says so instead of "all caught up". Distinct
 * from the board-wide priorities panel.
 */
export function MyNextActionsRail({ myWork }: MyNextActionsRailProps) {
    const href = myWork?.href ?? '/governance/my-work';
    const totals = myWork?.totals ?? null;
    const pending = totals?.pending ?? 0;
    const items = myWork?.items ?? [];
    const partial = myWork != null && myWork.all_sources_succeeded === false;

    return (
        <Card data-dusk="cockpit-my-next-actions">
            <CardHeader className="pb-3">
                <div className="flex items-start justify-between gap-2">
                    <div className="flex flex-col gap-1">
                        <CardTitle className="text-section-title">
                            My work
                        </CardTitle>
                        <CardDescription>
                            {totals
                                ? `${pending} pending for you${totals.overdue > 0 ? ` · ${totals.overdue} overdue` : ''} — your votes, reading and follow-up.`
                                : 'Your votes, reading and follow-up.'}
                        </CardDescription>
                    </div>
                    {totals && pending > 0 ? (
                        <Link
                            href={href}
                            className="shrink-0 text-xs font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            View all {pending}
                        </Link>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                {partial ? (
                    <div
                        role="status"
                        className="flex items-start gap-2 rounded-lg border border-status-warning/30 bg-status-warning-bg px-3 py-2 text-xs text-status-warning"
                        data-dusk="my-work-partial"
                    >
                        <AlertTriangle
                            className="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            Some sources could not be reached, so this list may
                            be incomplete.
                        </span>
                    </div>
                ) : null}

                {myWork == null || totals == null ? (
                    <EmptyState
                        variant="compact"
                        icon={AlertTriangle}
                        title="My work could not be loaded"
                        description="Your personal obligations are unavailable right now. They have not been marked complete."
                        action={
                            <Button asChild size="sm" variant="outline">
                                <Link href={href}>Open My work</Link>
                            </Button>
                        }
                    />
                ) : items.length === 0 ? (
                    <EmptyState
                        variant="compact"
                        icon={CheckCircle2}
                        title={
                            partial
                                ? 'Nothing found in the sources that loaded'
                                : 'Nothing is waiting on you'
                        }
                        description={
                            totals.completed > 0
                                ? `You have ${totals.completed} completed ${totals.completed === 1 ? 'item' : 'items'} with receipts in My work.`
                                : 'No votes, reading or assigned actions are pending for you.'
                        }
                    />
                ) : (
                    <ul className="flex flex-col gap-2">
                        {items.map((item) => {
                            const meta = KIND_META[item.kind] ?? KIND_META.act;
                            const Icon = meta.icon;
                            const badge = statusBadge(item.status);
                            const action = item.required_action;
                            const actionHref = action?.href || item.source.href;
                            const allowed = action?.allowed !== false;

                            return (
                                <li
                                    key={item.id}
                                    className="flex items-start gap-3 rounded-lg border border-border bg-card p-3"
                                    data-dusk={`cockpit-my-action-${item.id}`}
                                >
                                    <div className="rounded-md bg-muted p-1.5 text-muted-foreground">
                                        <Icon
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <span className="text-caption font-medium uppercase">
                                                {meta.label}
                                            </span>
                                            <StatusBadge
                                                size="sm"
                                                variant={badge.variant}
                                            >
                                                {badge.label}
                                            </StatusBadge>
                                            <span className="text-caption">
                                                {item.due_date
                                                    ? `Due ${formatDateOnly(item.due_date)}`
                                                    : 'No due date'}
                                            </span>
                                        </div>
                                        <p className="text-sm leading-snug font-medium text-foreground">
                                            {item.title}
                                        </p>
                                        {item.reason ? (
                                            <p className="text-caption">
                                                {item.reason}
                                            </p>
                                        ) : null}
                                        {!allowed && action?.blocked_reason ? (
                                            <p className="text-xs text-status-critical">
                                                {action.blocked_reason}
                                            </p>
                                        ) : null}
                                    </div>
                                    {allowed && actionHref ? (
                                        <Button
                                            asChild
                                            size="sm"
                                            variant={
                                                item.status === 'overdue'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            className="shrink-0"
                                        >
                                            <Link href={actionHref}>
                                                {action?.label || 'Open'}
                                            </Link>
                                        </Button>
                                    ) : null}
                                </li>
                            );
                        })}
                    </ul>
                )}

                {totals && pending > items.length ? (
                    <Link
                        href={href}
                        className="self-center pt-1 text-xs font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        data-dusk="my-work-view-all"
                    >
                        Showing {items.length} of {pending} — view all {pending}{' '}
                        in My work
                    </Link>
                ) : null}
            </CardContent>
        </Card>
    );
}

export default MyNextActionsRail;
