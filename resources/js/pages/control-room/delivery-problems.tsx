import {
    BROADCAST_CHANNEL_LABELS,
    broadcastReasonWords,
    channelList,
} from '@/components/control-room/broadcast-wizard';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import { longDuration, useNow } from '@/components/control-room/live-response/response-clock';
import {
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page';
import { PopupCallout } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    CalendarRange,
    CircleAlert,
    Copy,
    MailCheck,
    RefreshCw,
    Send,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';

type Outcome = 'failed' | 'unconfirmed';

interface Problem {
    id: number;
    broadcast_group_id: string;
    message: string;
    channel: string;
    outcome: Outcome;
    reason: string | null;
    detail: string | null;
    sent_at: string | null;
    failed_at: string | null;
    person: { id: number; name: string; site: string | null } | null;
    /** The same person's other channels for this broadcast that were delivered. */
    reached_by: string[];
    /** When they marked the in-app copy of this broadcast read. */
    read_at: string | null;
}

interface PaginatedProblems {
    data: Problem[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from?: number | null;
    to?: number | null;
}

interface Filters {
    channel: string | null;
    outcome: Outcome | null;
    period: string;
}

interface Props {
    problems: PaginatedProblems;
    stats: {
        failed: number;
        unconfirmed: number;
        people: number;
        oldest: {
            broadcast_group_id: string;
            message: string;
            sent_at: string | null;
        } | null;
    };
    filters: Filters;
    thresholdMinutes: number;
    can: { viewStaff: boolean };
}

const BASE = '/control-room/delivery-problems';
const DEFAULT_PERIOD = '7d';

const PERIODS = [
    { value: '24h', label: 'Last 24 hours', words: 'last 24 hours' },
    { value: '7d', label: 'Last 7 days', words: 'last 7 days' },
    { value: '30d', label: 'Last 30 days', words: 'last 30 days' },
    { value: '90d', label: 'Last 90 days', words: 'last 90 days' },
];

const OUTCOME: Record<Outcome, { label: string; tone: 'critical' | 'warning' }> = {
    failed: { label: 'Not delivered', tone: 'critical' },
    unconfirmed: { label: 'Not confirmed', tone: 'warning' },
};

function broadcastHref(groupId: string) {
    return `/control-room/broadcast/${groupId}?tab=recipients`;
}

function firstLine(message: string) {
    return message.split('\n')[0].trim() || message.trim();
}

function ago(at: string | null, now: number): string | null {
    if (!at) return null;
    const ms = now - new Date(at).getTime();
    if (!Number.isFinite(ms)) return null;
    return ms < 60_000 ? 'just now' : `${longDuration(ms)} ago`;
}

/** What to do about one send, in plain words — only links to pages that exist. */
function nextStep(
    problem: Problem,
    now: number,
): { text: string; done?: boolean; staffLink?: boolean } {
    if (problem.read_at) {
        return {
            text: `They read it in the app ${ago(problem.read_at, now)}. Nothing more needed.`,
            done: true,
        };
    }
    if (problem.reached_by.length > 0) {
        return {
            text: `They got it by ${channelList(problem.reached_by)}. Check they've seen it.`,
        };
    }
    if (problem.outcome === 'unconfirmed') {
        return {
            text: 'Check delivery on the broadcast before sending it again.',
        };
    }
    switch (problem.reason) {
        case 'no_phone':
            return {
                text: 'Add a mobile number to their record. Reach them another way for now.',
                staffLink: true,
            };
        case 'no_email':
            return {
                text: 'Add an email address to their record. Reach them another way for now.',
                staffLink: true,
            };
        case 'no_device':
            return {
                text: 'They need to turn on notifications on their phone. Reach them another way.',
            };
        case 'sms_not_set_up':
        case 'push_not_set_up':
            return { text: "This channel isn't set up yet. Reach them another way." };
        default:
            return { text: 'Reach them another way.' };
    }
}

/**
 * Communications › Delivery problems — every broadcast send that wasn't
 * delivered, or still has no result a while after it was sent, newest first.
 * Each row says who, which message, what happened and what to do next. There
 * is no resend here: broadcasts have no safe resend, so a new message is a new
 * broadcast.
 */
export default function DeliveryProblems({
    problems,
    stats,
    filters,
    thresholdMinutes,
    can,
}: Props) {
    const now = useNow(60_000);
    const [checking, setChecking] = useState(false);

    const visit = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        const params: Record<string, string> = {};
        if (merged.channel) params.channel = merged.channel;
        if (merged.outcome) params.outcome = merged.outcome;
        if (merged.period && merged.period !== DEFAULT_PERIOD) params.period = merged.period;
        router.get(BASE, params, { preserveState: true, preserveScroll: true });
    };

    const checkAgain = () => {
        setChecking(true);
        router.reload({ onFinish: () => setChecking(false) });
    };

    const periodWords =
        PERIODS.find((p) => p.value === filters.period)?.words ?? 'last 7 days';
    const filtered =
        filters.channel !== null ||
        filters.outcome !== null ||
        filters.period !== DEFAULT_PERIOD;
    const oldestAge = stats.oldest ? ago(stats.oldest.sent_at, now) : null;

    const first =
        problems.from ??
        (problems.data.length ? (problems.current_page - 1) * problems.per_page + 1 : 0);
    const last = problems.to ?? first + problems.data.length - 1;

    return (
        <ControlRoomPage
            headTitle="Delivery problems"
            header={{
                subline: `Broadcast sends that weren't delivered, or had no result ${thresholdMinutes} minutes after sending · newest first`,
                actions: (
                    <PageHeaderGlassButton
                        icon={RefreshCw}
                        onClick={checkAgain}
                        disabled={checking}
                    >
                        {checking ? 'Checking…' : 'Check again'}
                    </PageHeaderGlassButton>
                ),
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Not delivered"
                            tone={stats.failed > 0 ? 'critical' : 'brand'}
                            onClick={() => visit({ outcome: 'failed' })}
                            pressed={filters.outcome === 'failed'}
                            ariaLabel="Show sends that weren't delivered"
                        >
                            <PageHeaderMeterBig>{stats.failed}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                the channel couldn&apos;t deliver
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Not confirmed"
                            tone={stats.unconfirmed > 0 ? 'warning' : 'brand'}
                            onClick={() => visit({ outcome: 'unconfirmed' })}
                            pressed={filters.outcome === 'unconfirmed'}
                            ariaLabel="Show sends with no result yet"
                        >
                            <PageHeaderMeterBig>{stats.unconfirmed}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                no result after {thresholdMinutes} min
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="People affected"
                            onClick={() => visit({ outcome: null })}
                            pressed={filters.outcome === null}
                            ariaLabel="Show every delivery problem"
                        >
                            <PageHeaderMeterBig>{stats.people}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                may not have the message
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        {stats.oldest && oldestAge ? (
                            <PageHeaderMeterBlock
                                label="Oldest"
                                href={broadcastHref(stats.oldest.broadcast_group_id)}
                                ariaLabel="Open the broadcast with the oldest problem"
                            >
                                <PageHeaderMeterBig>{oldestAge}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {firstLine(stats.oldest.message)}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        ) : null}
                    </>
                ),
                filters: (
                    <>
                        <PageHeaderFilterSelect
                            icon={Send}
                            label="All channels"
                            value={filters.channel ?? 'all'}
                            options={[
                                { value: 'all', label: 'All channels' },
                                ...Object.entries(BROADCAST_CHANNEL_LABELS).map(
                                    ([value, label]) => ({ value, label }),
                                ),
                            ]}
                            onChange={(value) =>
                                visit({ channel: value === 'all' ? null : value })
                            }
                        />
                        <PageHeaderFilterSelect
                            icon={CircleAlert}
                            label="All problems"
                            value={filters.outcome ?? 'all'}
                            options={[
                                { value: 'all', label: 'All problems' },
                                { value: 'failed', label: OUTCOME.failed.label },
                                { value: 'unconfirmed', label: OUTCOME.unconfirmed.label },
                            ]}
                            onChange={(value) =>
                                visit({
                                    outcome: value === 'all' ? null : (value as Outcome),
                                })
                            }
                        />
                        <PageHeaderFilterSelect
                            icon={CalendarRange}
                            label="Last 7 days"
                            value={filters.period}
                            allValue={DEFAULT_PERIOD}
                            options={PERIODS.map(({ value, label }) => ({ value, label }))}
                            onChange={(value) => visit({ period: value })}
                        />
                    </>
                ),
            }}
        >
            {problems.total > 0 ? (
                <PopupCallout tone="warning">
                    Check a broadcast before sending it again. A send that isn&apos;t
                    confirmed may still arrive, and a new broadcast goes to its whole
                    audience again.
                </PopupCallout>
            ) : null}

            <Card className="min-w-0 gap-0 overflow-hidden py-0">
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                    <div className="min-w-0">
                        <h2 className="text-section-title">Delivery needing attention</h2>
                        <p className="text-subtle mt-0.5">
                            Not confirmed means no result {thresholdMinutes} minutes after
                            sending — delivery normally takes seconds.
                        </p>
                    </div>
                    {problems.total > 0 ? (
                        <StatusBadge variant="neutral" className="tabular-nums">
                            Showing {first}–{last} of {problems.total}
                        </StatusBadge>
                    ) : null}
                </div>

                {problems.data.length === 0 ? (
                    <div className="px-5 py-10">
                        {filtered ? (
                            <EmptyState
                                icon={MailCheck}
                                title="Nothing matches these filters"
                                description={`No delivery problems for these filters in the ${periodWords}.`}
                                action={
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            visit({
                                                channel: null,
                                                outcome: null,
                                                period: DEFAULT_PERIOD,
                                            })
                                        }
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={MailCheck}
                                title="No delivery problems"
                                description={`Nothing failed in the ${periodWords}, and every send older than ${thresholdMinutes} minutes has a result.`}
                            />
                        )}
                    </div>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5">Person</TableHead>
                                <TableHead>Message</TableHead>
                                <TableHead>Channel</TableHead>
                                <TableHead>What happened</TableHead>
                                <TableHead>When</TableHead>
                                <TableHead>Next step</TableHead>
                                <TableHead className="w-12 pr-5">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {problems.data.map((problem) => (
                                <ProblemRow
                                    key={problem.id}
                                    problem={problem}
                                    now={now}
                                    canViewStaff={can.viewStaff}
                                />
                            ))}
                        </TableBody>
                    </Table>
                )}

                {problems.last_page > 1 ? (
                    <div className="border-t border-border px-5 py-3">
                        <LaravelPagination
                            links={problems.links}
                            lastPage={problems.last_page}
                            preserveScroll
                        />
                    </div>
                ) : null}
            </Card>
        </ControlRoomPage>
    );
}

function ProblemRow({
    problem,
    now,
    canViewStaff,
}: {
    problem: Problem;
    now: number;
    canViewStaff: boolean;
}) {
    const href = broadcastHref(problem.broadcast_group_id);
    const outcome = OUTCOME[problem.outcome];
    const reason =
        problem.outcome === 'failed'
            ? broadcastReasonWords(problem.reason, problem.detail)
            : null;
    const step = nextStep(problem, now);
    const staffHref =
        canViewStaff && problem.person ? `/staff/${problem.person.id}` : null;
    const name = problem.person?.name ?? 'Unknown person';

    const items: ControlRoomRowAction[] = [
        {
            key: 'open',
            label: 'Open broadcast',
            icon: ArrowUpRight,
            onSelect: () => router.visit(href),
        },
        ...(staffHref
            ? [
                  {
                      key: 'staff',
                      label: 'Open staff record',
                      icon: UserRound,
                      onSelect: () => router.visit(staffHref),
                  },
              ]
            : []),
        {
            key: 'copy',
            label: 'Copy message',
            icon: Copy,
            onSelect: () => void navigator.clipboard?.writeText(problem.message),
        },
    ];

    return (
        <ControlRoomRowActions label={`Actions for ${name}`} items={items}>
            {({ rowProps, overflowButton }) => (
                <TableRow
                    {...rowProps}
                    className="cursor-pointer outline-none focus-visible:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                    onClick={(event) => {
                        // Links and the kebab handle their own clicks.
                        if ((event.target as HTMLElement).closest('a,button')) return;
                        router.visit(href);
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && event.target === event.currentTarget) {
                            router.visit(href);
                        }
                    }}
                >
                    <TableCell className="pl-5 whitespace-normal">
                        <span className="block font-semibold text-foreground">{name}</span>
                        {problem.person?.site ? (
                            <span className="text-caption block">{problem.person.site}</span>
                        ) : null}
                    </TableCell>
                    <TableCell className="max-w-[22rem] min-w-[12rem] whitespace-normal">
                        <Link
                            href={href}
                            title={problem.message}
                            className="line-clamp-2 text-foreground underline-offset-2 hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {firstLine(problem.message)}
                        </Link>
                    </TableCell>
                    <TableCell>
                        {BROADCAST_CHANNEL_LABELS[problem.channel] ?? problem.channel}
                    </TableCell>
                    <TableCell className="whitespace-normal">
                        <span className="grid gap-0.5">
                            <StatusBadge
                                variant={outcome.tone}
                                size="sm"
                                className="justify-self-start"
                            >
                                {outcome.label}
                            </StatusBadge>
                            {reason ? <span className="text-caption">{reason}</span> : null}
                        </span>
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                        {problem.sent_at ? (
                            <>
                                <span className="block">sent {ago(problem.sent_at, now)}</span>
                                <span className="text-caption block tabular-nums">
                                    {formatDateTime(problem.sent_at)}
                                </span>
                            </>
                        ) : null}
                    </TableCell>
                    <TableCell className="max-w-[20rem] min-w-[14rem] whitespace-normal">
                        <span
                            className={
                                step.done ? 'block text-status-success' : 'block text-foreground'
                            }
                        >
                            {step.text}
                        </span>
                        {step.staffLink && staffHref ? (
                            <Link
                                href={staffHref}
                                className="text-caption font-medium text-primary hover:underline"
                            >
                                Open staff record
                            </Link>
                        ) : null}
                    </TableCell>
                    <TableCell className="pr-5 text-right">{overflowButton}</TableCell>
                </TableRow>
            )}
        </ControlRoomRowActions>
    );
}
