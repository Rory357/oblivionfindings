import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    CalendarDays,
    CheckCircle2,
    Copy,
    ExternalLink,
    ListChecks,
    Search,
    Vote as VoteIcon,
} from 'lucide-react';
import { useCallback, useState } from 'react';

import { GovernanceHomeRail } from '@/components/governance/GovernanceHomeRail';
import {
    receiptTitle,
    unavailableWorkMessage,
    workKindLabel,
    workStatusChip,
} from '@/components/governance/governance-work';
import {
    EntityTable,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import {
    formatDateLong,
    formatDateOnly,
    formatDateTimeLong,
    toDateInput,
} from '@/lib/datetime';
import { refSuffix, voteLabel } from '@/lib/governance-labels';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';

export interface GovernanceWorkItemData {
    id: string;
    kind: 'vote' | 'read' | 'act' | 'know' | string;
    source: {
        type: string;
        id: number;
        reference: string;
        href: string;
    };
    title: string;
    reason: string;
    priority: string;
    status: string;
    due_at?: string | null;
    due_date?: string | null;
    assignee_user_id?: number | null;
    board_member_id?: number | null;
    required_action: {
        key: string;
        label: string;
        href: string;
        allowed: boolean;
        blocked_reason?: string | null;
    };
    source_version?: number | null;
    available_as_of?: string | null;
    /** Only what the server recorded when the work was finished. */
    receipt?: {
        receipt_id?: string | null;
        completed_at?: string | null;
        vote?: string;
        paper_version?: number;
        revision_number?: number;
        version?: string | number;
        completion_notes?: string | null;
        [key: string]: unknown;
    } | null;
    area?: string;
    area_key?: string;
    detail?: string;
    action_label?: string;
    action_url?: string;
    owner?: string | null;
}

export interface GovernanceWorkFeed {
    items: GovernanceWorkItemData[];
    /** Meetings coming up — for your information, never counted as work to do. */
    coming_up?: GovernanceWorkItemData[];
    totals: {
        all: number;
        vote: number;
        read: number;
        act: number;
        know: number;
        pending: number;
        overdue: number;
        blocked: number;
        completed: number;
    };
    pagination: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    scope: {
        viewer: {
            user_id: number;
            name: string;
            board_member_id?: number | null;
        };
        filters: Record<string, unknown>;
    };
    availability: Record<string, 'available' | 'unavailable'>;
    all_sources_succeeded: boolean;
    generated_at: string;
}

interface Props extends PageProps {
    feed: GovernanceWorkFeed;
    filters: {
        kind?: string;
        status?: string;
        due?: string;
        committee?: string;
        search?: string;
        page?: number | string;
    };
}

function getKindIcon(kind: string) {
    switch (kind) {
        case 'vote':
            return VoteIcon;
        case 'read':
            return BookOpen;
        case 'know':
            return CalendarDays;
        default:
            return ListChecks;
    }
}

const plural = (count: number, one: string, many: string) =>
    count === 1 ? one : many;

/** Whole days between two YYYY-MM-DD calendar dates (no timezone drift). */
function calendarDayDiff(from: string, to: string): number {
    const toUtc = (value: string) => {
        const [y, m, d] = value.split('-').map(Number);
        return Date.UTC(y, (m ?? 1) - 1, d ?? 1);
    };
    return Math.round((toUtc(to) - toUtc(from)) / 86_400_000);
}

function dueText(dueDateString: string | null | undefined): {
    text: string;
    tone: 'critical' | 'warning' | 'neutral';
} {
    if (!dueDateString) {
        return { text: 'No due date', tone: 'neutral' };
    }

    // Compare NZ calendar dates — parsing YYYY-MM-DD with `new Date()`
    // shifts the day in non-NZ browser timezones.
    const dueDay = dueDateString.substring(0, 10);
    const diffDays = calendarDayDiff(toDateInput(new Date()), dueDay);

    if (diffDays < 0) {
        const late = Math.abs(diffDays);
        return {
            text: `Overdue by ${late} ${plural(late, 'day', 'days')}`,
            tone: 'critical',
        };
    }
    if (diffDays === 0) return { text: 'Due today', tone: 'warning' };
    if (diffDays === 1) return { text: 'Due tomorrow', tone: 'warning' };
    if (diffDays <= 7) return { text: `Due in ${diffDays} days`, tone: 'warning' };
    return { text: `Due ${formatDateOnly(dueDay, dueDay)}`, tone: 'neutral' };
}

const KIND_OPTIONS = (totals: GovernanceWorkFeed['totals']) => [
    { value: 'all', label: `All kinds (${totals.all})` },
    { value: 'vote', label: `Vote (${totals.vote})` },
    { value: 'read', label: `Read (${totals.read})` },
    { value: 'act', label: `Do (${totals.act})` },
    { value: 'know', label: `For your information (${totals.know})` },
];

export default function MyWorkIndex({ auth, feed, filters }: Props) {
    const [searchQuery, setSearchQuery] = useState(filters.search ?? '');
    const [selectedReceiptItem, setSelectedReceiptItem] =
        useState<GovernanceWorkItemData | null>(null);
    const [copiedReceipt, setCopiedReceipt] = useState(false);

    const applyFilters = useCallback(
        (newFilters: Record<string, unknown>) => {
            const merged: Record<string, unknown> = {
                kind: filters.kind ?? 'all',
                status: filters.status ?? 'pending',
                due: filters.due ?? 'all',
                committee: filters.committee,
                search: searchQuery || undefined,
                ...newFilters,
            };

            // Leave defaults out so the URL stays clean.
            const cleanedParams: Record<string, string> = {};
            if (merged.kind && merged.kind !== 'all') {
                cleanedParams.kind = String(merged.kind);
            }
            if (merged.status && merged.status !== 'pending') {
                cleanedParams.status = String(merged.status);
            }
            if (merged.due && merged.due !== 'all') {
                cleanedParams.due = String(merged.due);
            }
            if (merged.committee) {
                cleanedParams.committee = String(merged.committee);
            }
            if (merged.search) {
                cleanedParams.search = String(merged.search);
            }
            if (merged.page && Number(merged.page) > 1) {
                cleanedParams.page = String(merged.page);
            }

            router.get('/governance/my-work', cleanedParams, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        },
        [filters, searchQuery],
    );

    const handleSearchSubmit = (e?: React.FormEvent) => {
        if (e) e.preventDefault();
        applyFilters({ search: searchQuery || undefined, page: 1 });
    };

    const handleCopyReceipt = (text: string) => {
        void navigator.clipboard.writeText(text);
        setCopiedReceipt(true);
        setTimeout(() => setCopiedReceipt(false), 2000);
    };

    const currentKind = filters.kind ?? 'all';
    const currentStatus = filters.status ?? 'pending';
    const currentDue = filters.due ?? 'all';

    const missingMessage = unavailableWorkMessage(feed.availability);
    const comingUp = feed.coming_up ?? [];
    const showComingUp =
        comingUp.length > 0 && currentKind !== 'know' && currentStatus !== 'completed';

    const isFiltered =
        currentKind !== 'all' ||
        currentStatus !== 'pending' ||
        currentDue !== 'all' ||
        Boolean(searchQuery);

    const openRow = (row: GovernanceWorkItemData) => {
        if (row.status === 'completed' && row.receipt) {
            setSelectedReceiptItem(row);
        } else if (row.required_action?.href && row.required_action.allowed !== false) {
            router.visit(row.required_action.href);
        }
    };

    const columns: EntityTableColumn<GovernanceWorkItemData>[] = [
        {
            key: 'kind',
            label: 'Kind',
            width: '150px',
            cell: (row) => (
                <StatusBadge size="sm" variant="neutral">
                    {workKindLabel(row.kind)}
                </StatusBadge>
            ),
        },
        {
            key: 'due',
            label: 'When',
            width: '150px',
            cell: (row) => {
                if (row.status === 'completed') {
                    return (
                        <span className="text-xs text-muted-foreground">
                            {row.receipt?.completed_at
                                ? `Done ${formatDateLong(row.receipt.completed_at)}`
                                : 'Done'}
                        </span>
                    );
                }
                if (row.status === 'upcoming') {
                    return (
                        <span className="text-xs text-muted-foreground">
                            {row.due_date ? formatDateOnly(row.due_date) : 'Date to be confirmed'}
                        </span>
                    );
                }
                const { text, tone } = dueText(row.due_date);
                return (
                    <span
                        className={cn(
                            'text-xs font-medium',
                            tone === 'critical' && 'text-status-critical',
                            tone === 'warning' && 'text-status-warning',
                            tone === 'neutral' && 'text-muted-foreground',
                        )}
                    >
                        {text}
                    </span>
                );
            },
        },
        {
            key: 'status',
            label: 'Status',
            width: '170px',
            cell: (row) => {
                const chip = workStatusChip(row.status);
                const blockerReason =
                    row.status === 'blocked'
                        ? row.required_action?.blocked_reason || 'Waiting on something else'
                        : null;

                return (
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <StatusBadge size="sm" variant={chip.variant} className="w-fit">
                            {chip.label}
                        </StatusBadge>
                        {blockerReason ? (
                            <span
                                className="truncate text-xs text-status-critical"
                                title={blockerReason}
                            >
                                {blockerReason}
                            </span>
                        ) : null}
                    </div>
                );
            },
        },
        {
            key: 'action',
            label: 'Next step',
            width: '170px',
            align: 'right',
            cell: (row) => {
                if (row.status === 'completed') {
                    return row.receipt ? (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={(e) => {
                                e.stopPropagation();
                                setSelectedReceiptItem(row);
                            }}
                        >
                            View record
                        </Button>
                    ) : null;
                }

                const action = row.required_action;
                if (action?.allowed === false) {
                    return (
                        <span className="text-xs text-muted-foreground">
                            {action.blocked_reason || "You can't open this"}
                        </span>
                    );
                }

                return (
                    <Button
                        size="sm"
                        variant={row.status === 'upcoming' ? 'outline' : 'default'}
                        onClick={(e) => {
                            e.stopPropagation();
                            if (action?.href) {
                                router.visit(action.href);
                            }
                        }}
                    >
                        {action?.label || 'Open'}
                    </Button>
                );
            },
        },
    ];

    const getActionsFor = (row: GovernanceWorkItemData): MenuItem[] => {
        const menuItems: MenuItem[] = [];

        if (row.required_action?.href && row.required_action.allowed !== false) {
            menuItems.push({
                label: row.required_action.label || 'Open',
                onClick: () => router.visit(row.required_action.href),
            });
        }

        if (row.source?.reference) {
            menuItems.push({
                label: 'Copy reference',
                onClick: () => {
                    void navigator.clipboard.writeText(row.source.reference);
                },
            });
        }

        if (row.receipt) {
            menuItems.push({
                label: 'View record of completion',
                onClick: () => setSelectedReceiptItem(row),
            });
        }

        return menuItems;
    };

    const receipt = selectedReceiptItem?.receipt ?? null;
    const firstShown =
        feed.pagination.total === 0
            ? 0
            : (feed.pagination.current_page - 1) * feed.pagination.per_page + 1;
    const lastShown = Math.min(
        feed.pagination.current_page * feed.pagination.per_page,
        feed.pagination.total,
    );

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'My work', href: '/governance/my-work' },
            ]}
        >
            <Head title="My work" />

            <PageLayout
                hero={
                    <PageHeader
                        variant="index"
                        icon={ListChecks}
                        title="My work"
                        titleDusk="governance-my-work-heading"
                        titleChip={
                            feed.totals.overdue > 0 ? (
                                <PageHeaderStatusChip variant="critical">
                                    {feed.totals.overdue} overdue
                                </PageHeaderStatusChip>
                            ) : missingMessage ? (
                                <PageHeaderStatusChip variant="warning">
                                    Some work not loaded
                                </PageHeaderStatusChip>
                            ) : feed.totals.pending === 0 ? (
                                <PageHeaderStatusChip variant="success">
                                    Up to date
                                </PageHeaderStatusChip>
                            ) : null
                        }
                        subline="Your votes, reading and actions for the board, most urgent first"
                        actions={
                            <form
                                onSubmit={handleSearchSubmit}
                                className="flex items-center"
                            >
                                <PageHeaderSearch
                                    value={searchQuery}
                                    onChange={setSearchQuery}
                                    placeholder="Search my work…"
                                />
                            </form>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="To vote"
                                    href="/governance/my-work?kind=vote"
                                    tone={feed.totals.vote > 0 ? 'warning' : 'brand'}
                                    ariaLabel="Show resolutions to vote on"
                                >
                                    <PageHeaderMeterBig>{feed.totals.vote}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {feed.totals.vote > 0
                                            ? `${plural(feed.totals.vote, 'resolution', 'resolutions')} waiting for your vote`
                                            : 'Nothing to vote on'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="To read"
                                    href="/governance/my-work?kind=read"
                                    ariaLabel="Show board packs and policies to read"
                                >
                                    <PageHeaderMeterBig>{feed.totals.read}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {feed.totals.read > 0
                                            ? 'board packs and policies'
                                            : 'Nothing to read'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="To do"
                                    href="/governance/my-work?kind=act"
                                    ariaLabel="Show actions assigned to you"
                                >
                                    <PageHeaderMeterBig>{feed.totals.act}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {feed.totals.act > 0
                                            ? `${plural(feed.totals.act, 'action', 'actions')} assigned to you`
                                            : 'No actions assigned'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Coming up"
                                    href="/governance/my-work?kind=know"
                                    ariaLabel="Show meetings coming up"
                                >
                                    <PageHeaderMeterBig>{feed.totals.know}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {feed.totals.know > 0
                                            ? `${plural(feed.totals.know, 'meeting', 'meetings')} — for your information`
                                            : 'No meetings coming up'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Done"
                                    href="/governance/my-work?status=completed"
                                    tone={feed.totals.completed > 0 ? 'success' : 'brand'}
                                    ariaLabel="Show work you've finished"
                                >
                                    <PageHeaderMeterBig>{feed.totals.completed}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {feed.totals.completed > 0
                                            ? 'with a record of completion'
                                            : 'Nothing finished yet'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <div className="flex flex-wrap items-center gap-2">
                                <PageHeaderFilterSelect
                                    icon={ListChecks}
                                    label="All kinds"
                                    value={currentKind}
                                    options={KIND_OPTIONS(feed.totals)}
                                    onChange={(val) =>
                                        applyFilters({ kind: val, page: 1 })
                                    }
                                />
                                <PageHeaderFilterSelect
                                    label="To do"
                                    value={currentStatus}
                                    allValue="pending"
                                    options={[
                                        { value: 'pending', label: 'To do' },
                                        { value: 'completed', label: 'Done' },
                                        { value: 'all', label: 'To do and done' },
                                    ]}
                                    onChange={(val) =>
                                        applyFilters({ status: val, page: 1 })
                                    }
                                />
                                <PageHeaderFilterSelect
                                    label="Any due date"
                                    value={currentDue}
                                    options={[
                                        { value: 'all', label: 'Any due date' },
                                        { value: 'overdue', label: 'Overdue' },
                                        { value: 'next7', label: 'Next 7 days' },
                                    ]}
                                    onChange={(val) =>
                                        applyFilters({ due: val, page: 1 })
                                    }
                                />
                            </div>
                        }
                        rail={
                            <GovernanceHomeRail
                                value="my-work"
                                myWorkCount={feed.totals.pending}
                            />
                        }
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    {missingMessage ? (
                        <div
                            role="alert"
                            data-dusk="source-unavailable-banner"
                            className="flex items-center gap-3 rounded-lg border border-status-warning/30 bg-status-warning-bg p-4 text-sm text-status-warning"
                        >
                            <AlertTriangle
                                className="size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <span>{missingMessage}</span>
                        </div>
                    ) : null}

                    {feed.items.length === 0 ? (
                        isFiltered ? (
                            <EmptyState
                                icon={Search}
                                title="Nothing matches these filters"
                                description="Try a different kind, status, due date or search."
                                action={
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearchQuery('');
                                            router.get('/governance/my-work');
                                        }}
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        ) : missingMessage ? (
                            <EmptyState
                                icon={AlertTriangle}
                                title="Nothing to show from what loaded"
                                description="There may still be things for you to do once everything loads."
                            />
                        ) : (
                            <EmptyState
                                icon={CheckCircle2}
                                title="Nothing to do right now"
                                description={
                                    feed.totals.completed > 0
                                        ? `No votes, reading or actions are waiting for you. You've finished ${feed.totals.completed} ${plural(feed.totals.completed, 'thing', 'things')} — see Done.`
                                        : 'No votes, reading or actions are waiting for you.'
                                }
                            />
                        )
                    ) : (
                        <div className="flex flex-col gap-3">
                            <EntityTable<GovernanceWorkItemData>
                                rows={feed.items}
                                rowKey={(row) => row.id}
                                identityLabel="What"
                                identityWidth="2.3fr"
                                identity={(row) => ({
                                    icon: getKindIcon(row.kind),
                                    name: row.title,
                                    subline: [row.reason, refSuffix(row.source.reference)]
                                        .filter(Boolean)
                                        .join(' · '),
                                    linkLabel: row.title,
                                })}
                                onOpen={openRow}
                                columns={columns}
                                actionsFor={getActionsFor}
                            />

                            {feed.pagination.last_page > 1 ? (
                                <div className="flex flex-col items-center gap-2">
                                    <span className="text-caption">
                                        Showing {firstShown}–{lastShown} of {feed.pagination.total}
                                    </span>
                                    <LaravelPagination
                                        links={feed.pagination.links ?? []}
                                        lastPage={feed.pagination.last_page}
                                        preserveScroll
                                    />
                                </div>
                            ) : null}
                        </div>
                    )}

                    {showComingUp ? (
                        <Card data-dusk="my-work-coming-up">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-section-title">
                                    Coming up
                                </CardTitle>
                                <CardDescription>
                                    For your information — meetings you can
                                    open. There's nothing to do for these yet.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <ul className="flex flex-col divide-y divide-border">
                                    {comingUp.map((item) => (
                                        <li
                                            key={item.id}
                                            className="flex flex-wrap items-center justify-between gap-3 py-2.5"
                                        >
                                            <span className="min-w-0">
                                                <span className="block text-sm font-medium text-foreground">
                                                    {item.title}
                                                </span>
                                                <span className="block text-caption">
                                                    {[item.reason, refSuffix(item.source.reference)]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </span>
                                            </span>
                                            <Button asChild size="sm" variant="outline">
                                                <Link href={item.required_action.href}>
                                                    {item.required_action.label || 'Open meeting'}
                                                </Link>
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    ) : null}
                </div>
            </PageLayout>

            <Dialog
                open={selectedReceiptItem !== null}
                onOpenChange={(open) => {
                    if (!open) setSelectedReceiptItem(null);
                }}
            >
                <DialogContent style={{ maxWidth: 'min(92vw, 520px)' }}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <CheckCircle2
                                className="size-5 text-status-success"
                                aria-hidden="true"
                            />
                            {receiptTitle(selectedReceiptItem?.kind)}
                        </DialogTitle>
                        <DialogDescription>
                            What was recorded when you finished this.
                        </DialogDescription>
                    </DialogHeader>

                    {selectedReceiptItem ? (
                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                            <dt className="text-muted-foreground">What</dt>
                            <dd className="font-medium text-foreground">
                                {selectedReceiptItem.title}
                                {selectedReceiptItem.source.reference ? (
                                    <span className="ml-1.5 text-caption font-normal">
                                        {refSuffix(selectedReceiptItem.source.reference)}
                                    </span>
                                ) : null}
                            </dd>

                            <dt className="text-muted-foreground">When</dt>
                            <dd className="text-foreground">
                                {receipt?.completed_at ? (
                                    <time dateTime={receipt.completed_at}>
                                        {formatDateTimeLong(receipt.completed_at)}
                                    </time>
                                ) : (
                                    'Time not recorded'
                                )}
                            </dd>

                            {receipt?.vote ? (
                                <>
                                    <dt className="text-muted-foreground">Your vote</dt>
                                    <dd className="text-foreground">
                                        {voteLabel(receipt.vote)}
                                    </dd>
                                </>
                            ) : null}

                            {receipt?.paper_version !== undefined ? (
                                <>
                                    <dt className="text-muted-foreground">Paper</dt>
                                    <dd className="text-foreground">
                                        Version {receipt.paper_version}
                                    </dd>
                                </>
                            ) : null}

                            {receipt?.revision_number !== undefined ? (
                                <>
                                    <dt className="text-muted-foreground">Board pack</dt>
                                    <dd className="text-foreground">
                                        Version {receipt.revision_number}
                                    </dd>
                                </>
                            ) : null}

                            {receipt?.version !== undefined ? (
                                <>
                                    <dt className="text-muted-foreground">Policy</dt>
                                    <dd className="text-foreground">
                                        Version {receipt.version}
                                    </dd>
                                </>
                            ) : null}

                            {receipt?.completion_notes ? (
                                <>
                                    <dt className="text-muted-foreground">Notes</dt>
                                    <dd className="text-foreground">
                                        {receipt.completion_notes}
                                    </dd>
                                </>
                            ) : null}

                            {receipt?.receipt_id ? (
                                <>
                                    <dt className="text-muted-foreground">Reference</dt>
                                    <dd className="font-mono text-xs break-all text-foreground">
                                        {receipt.receipt_id}
                                    </dd>
                                </>
                            ) : null}
                        </dl>
                    ) : null}

                    <DialogFooter className="flex flex-col items-center justify-between gap-2 sm:flex-row">
                        {receipt?.receipt_id ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => handleCopyReceipt(receipt.receipt_id ?? '')}
                            >
                                <Copy className="mr-1.5 size-4" aria-hidden="true" />
                                {copiedReceipt ? 'Copied' : 'Copy reference'}
                            </Button>
                        ) : (
                            <span />
                        )}
                        <div className="flex items-center gap-2">
                            {selectedReceiptItem?.source?.href ? (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={selectedReceiptItem.source.href}>
                                        <ExternalLink
                                            className="mr-1.5 size-4"
                                            aria-hidden="true"
                                        />
                                        Open record
                                    </Link>
                                </Button>
                            ) : null}
                            <Button
                                size="sm"
                                onClick={() => setSelectedReceiptItem(null)}
                            >
                                Close
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
