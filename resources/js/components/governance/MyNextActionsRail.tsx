import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    CalendarDays,
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
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly } from '@/lib/datetime';
import { refSuffix } from '@/lib/governance-labels';

import {
    isDueStateStatus,
    unavailableWorkMessage,
    workKindLabel,
    workStatusChip,
} from './governance-work';

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
    due_at?: string | null;
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
    /** Meetings coming up — for your information, never counted as work to do. */
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
    /** Upcoming meetings — shown by the Next meeting card, not listed here. */
    coming_up?: MyWorkItem[];
    totals: MyWorkTotals | null;
    pagination?: { total: number } | null;
    availability?: Record<string, string>;
    all_sources_succeeded?: boolean;
    href?: string;
}

interface MyNextActionsRailProps {
    myWork: MyWorkPreview | null | undefined;
}

const KIND_ICON: Record<string, LucideIcon> = {
    vote: Vote,
    read: BookOpen,
    act: ListChecks,
    know: CalendarDays,
};

/**
 * My work on Governance Home — the viewer's own votes, reading and actions,
 * each with its due date, why it matters and one direct action. The "See
 * all" count is the same authorised total as `/governance/my-work`, never
 * the number of rows displayed, and a feed that failed (or is partial) says
 * so instead of "nothing to do". Upcoming meetings are left to the Next
 * meeting card.
 */
export function MyNextActionsRail({ myWork }: MyNextActionsRailProps) {
    const href = myWork?.href ?? '/governance/my-work';
    const totals = myWork?.totals ?? null;
    const pending = totals?.pending ?? 0;
    const items = myWork?.items ?? [];
    const missingMessage = myWork ? unavailableWorkMessage(myWork.availability) : null;
    const partial =
        myWork != null &&
        (myWork.all_sources_succeeded === false || missingMessage !== null);

    return (
        <Card data-dusk="cockpit-my-next-actions">
            <CardHeader className="pb-3">
                <CardTitle className="text-section-title">My work</CardTitle>
                <CardDescription>
                    Your votes, reading and actions — most urgent first.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                {partial && totals != null ? (
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
                            {missingMessage ??
                                "Some of your work couldn't be loaded, so this list may be missing items. Try again in a few minutes."}
                        </span>
                    </div>
                ) : null}

                {myWork == null || totals == null ? (
                    <EmptyState
                        variant="compact"
                        icon={AlertTriangle}
                        title="My work couldn't be loaded"
                        description="Your work isn't showing right now. Nothing has been marked as done — try again in a few minutes."
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
                                ? 'Nothing to show from what loaded'
                                : 'Nothing to do right now'
                        }
                        description={
                            partial
                                ? 'There may still be things for you to do once everything loads.'
                                : 'No votes, reading or actions are waiting for you.'
                        }
                    />
                ) : (
                    <ul className="flex flex-col gap-2">
                        {items.map((item) => {
                            const Icon = KIND_ICON[item.kind] ?? ListChecks;
                            const chip = workStatusChip(item.status);
                            const action = item.required_action;
                            const actionHref = action?.href || item.source.href;
                            const allowed = action?.allowed !== false;
                            const reference = refSuffix(item.source.reference);

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
                                        <div className="flex flex-wrap items-center gap-1.5 text-caption">
                                            <span className="font-medium text-foreground">
                                                {workKindLabel(item.kind)}
                                            </span>
                                            <span aria-hidden="true">·</span>
                                            <span>
                                                {item.due_date
                                                    ? `Due ${formatDateOnly(item.due_date)}`
                                                    : 'No due date'}
                                            </span>
                                            {isDueStateStatus(item.status) ? (
                                                <StatusBadge
                                                    size="sm"
                                                    variant={chip.variant}
                                                >
                                                    {chip.label}
                                                </StatusBadge>
                                            ) : null}
                                        </div>
                                        <p className="text-sm leading-snug font-medium text-foreground">
                                            {item.title}
                                            {reference ? (
                                                <span className="ml-1.5 text-caption font-normal">
                                                    {reference}
                                                </span>
                                            ) : null}
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
                        See all {pending} in My work
                    </Link>
                ) : null}
            </CardContent>
        </Card>
    );
}

export default MyNextActionsRail;
