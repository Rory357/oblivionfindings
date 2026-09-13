import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    BookOpen,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Copy,
    ExternalLink,
    ListChecks,
    Search,
    Vote as VoteIcon,
} from 'lucide-react';
import { useCallback, useState } from 'react';

import { GovernanceHomeRail } from '@/components/governance/GovernanceHomeRail';
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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import {
    formatDateOnly,
    formatDateTimeLong,
    toDateInput,
} from '@/lib/datetime';
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
    receipt?: {
        receipt_id: string;
        completed_at?: string | null;
        vote?: string;
        revision_number?: number;
        version?: string | number;
        completion_notes?: string;
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
        case 'act':
            return ListChecks;
        case 'know':
            return Bell;
        default:
            return ListChecks;
    }
}

const KIND_BADGE: Record<string, { label: string; variant: StatusVariant }> = {
    vote: { label: 'Vote', variant: 'warning' },
    read: { label: 'Read', variant: 'info' },
    act: { label: 'Act', variant: 'critical' },
    know: { label: 'Know', variant: 'neutral' },
};

const WORK_STATUS_BADGE: Record<string, { label: string; variant: StatusVariant }> = {
    pending: { label: 'Pending', variant: 'neutral' },
    due_soon: { label: 'Due soon', variant: 'warning' },
    overdue: { label: 'Overdue', variant: 'critical' },
    blocked: { label: 'Blocked', variant: 'critical' },
    completed: { label: 'Completed', variant: 'success' },
};

/** Whole days between two YYYY-MM-DD calendar dates (no timezone drift). */
function calendarDayDiff(from: string, to: string): number {
    const toUtc = (value: string) => {
        const [y, m, d] = value.split('-').map(Number);
        return Date.UTC(y, (m ?? 1) - 1, d ?? 1);
    };
    return Math.round((toUtc(to) - toUtc(from)) / 86_400_000);
}

function formatDueDate(dueDateString: string | null | undefined): {
    text: string;
    tone: 'critical' | 'warning' | 'neutral';
} {
    if (!dueDateString) {
        return { text: 'No deadline', tone: 'neutral' };
    }

    // Compare Auckland calendar dates — parsing YYYY-MM-DD with `new Date()`
    // shifts the day in non-NZ browser timezones.
    const dueDay = dueDateString.substring(0, 10);
    const diffDays = calendarDayDiff(toDateInput(new Date()), dueDay);

    if (diffDays < 0) {
        const overdueDays = Math.abs(diffDays);
        return {
            text: `Overdue (${overdueDays}d)`,
            tone: 'critical',
        };
    }
    if (diffDays === 0) {
        return { text: 'Due today', tone: 'warning' };
    }
    if (diffDays === 1) {
        return { text: 'Due tomorrow', tone: 'warning' };
    }
    if (diffDays <= 7) {
        return { text: `Due in ${diffDays}d`, tone: 'warning' };
    }
    return {
        text: formatDateOnly(dueDay, dueDay),
        tone: 'neutral',
    };
}

const KIND_OPTIONS = (totals: GovernanceWorkFeed['totals']) => [
    { value: 'all', label: `All work (${totals.all})` },
    { value: 'vote', label: `Vote (${totals.vote})` },
    { value: 'read', label: `Read (${totals.read})` },
    { value: 'act', label: `Act (${totals.act})` },
    { value: 'know', label: `Know (${totals.know})` },
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

            // Remove empty/default parameters to keep URL clean
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

    const unavailableSources = Object.entries(feed.availability ?? {})
        .filter(([_, status]) => status === 'unavailable')
        .map(([src]) => src.replace(/_/g, ' '));

    const isFiltered =
        currentKind !== 'all' ||
        currentStatus !== 'pending' ||
        currentDue !== 'all' ||
        Boolean(searchQuery);

    const columns: EntityTableColumn<GovernanceWorkItemData>[] = [
        {
            key: 'kind',
            label: 'Kind',
            width: '95px',
            cell: (row) => {
                const meta = KIND_BADGE[row.kind] ?? {
                    label: row.kind,
                    variant: 'neutral' as const,
                };
                return (
                    <StatusBadge size="sm" variant={meta.variant}>
                        {meta.label}
                    </StatusBadge>
                );
            },
        },
        {
            key: 'due',
            label: 'Due',
            width: '130px',
            cell: (row) => {
                if (row.status === 'completed') {
                    return (
                        <span className="text-xs text-muted-foreground">
                            Completed
                        </span>
                    );
                }
                const { text, tone } = formatDueDate(row.due_date);
                return (
                    <span
                        className={cn(
                            'text-xs font-medium',
                            tone === 'critical' &&
                                'font-semibold text-status-critical',
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
            label: 'Status / Blocker',
            width: '160px',
            cell: (row) => {
                const isBlocked = row.status === 'blocked';
                const meta = WORK_STATUS_BADGE[row.status] ?? {
                    label: row.status.replace(/_/g, ' '),
                    variant: 'neutral' as const,
                };
                const blockerReason =
                    row.required_action?.blocked_reason ||
                    (isBlocked ? 'Awaiting prerequisites' : null);

                return (
                    <div className="flex flex-col gap-0.5">
                        <StatusBadge
                            size="sm"
                            variant={meta.variant}
                            className="w-fit"
                        >
                            {meta.label}
                        </StatusBadge>
                        {isBlocked && blockerReason && (
                            <span
                                className="max-w-[150px] truncate text-[10.5px] text-status-critical"
                                title={blockerReason}
                            >
                                {blockerReason}
                            </span>
                        )}
                    </div>
                );
            },
        },
        {
            key: 'owner',
            label: 'Owner',
            width: '75px',
            cell: () => (
                <span className="text-xs font-medium text-foreground">You</span>
            ),
        },
        {
            key: 'action',
            label: 'Action',
            width: '135px',
            align: 'right',
            cell: (row) => {
                if (row.status === 'completed') {
                    return (
                        <Button
                            size="sm"
                            variant="outline"
                            className="h-7 text-xs"
                            onClick={(e) => {
                                e.stopPropagation();
                                setSelectedReceiptItem(row);
                            }}
                        >
                            View receipt
                        </Button>
                    );
                }

                const action = row.required_action;
                const isAllowed = action?.allowed !== false;
                const buttonLabel = action?.label || 'View';

                return (
                    <Button
                        size="sm"
                        variant={
                            row.priority === 'critical' ||
                            row.status === 'overdue'
                                ? 'destructive'
                                : 'default'
                        }
                        disabled={!isAllowed}
                        className="h-7 text-xs"
                        onClick={(e) => {
                            e.stopPropagation();
                            if (action?.href) {
                                router.visit(action.href);
                            }
                        }}
                    >
                        {buttonLabel}
                    </Button>
                );
            },
        },
    ];

    const getActionsFor = (row: GovernanceWorkItemData): MenuItem[] => {
        const menuItems: MenuItem[] = [
            {
                label: 'Open record',
                onClick: () => {
                    if (row.required_action?.href) {
                        router.visit(row.required_action.href);
                    }
                },
            },
            {
                label: 'Copy reference',
                onClick: () => {
                    if (row.source?.reference) {
                        void navigator.clipboard.writeText(row.source.reference);
                    }
                },
            },
        ];

        if (row.receipt) {
            menuItems.push({
                label: 'View receipt',
                onClick: () => setSelectedReceiptItem(row),
            });
        }

        return menuItems;
    };

    const handleRowOpen = (row: GovernanceWorkItemData) => {
        if (row.status === 'completed' && row.receipt) {
            setSelectedReceiptItem(row);
        } else if (row.required_action?.href && row.required_action.allowed) {
            router.visit(row.required_action.href);
        }
    };

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
                            ) : feed.totals.pending > 0 ? (
                                <PageHeaderStatusChip variant="warning">
                                    {feed.totals.pending} pending
                                </PageHeaderStatusChip>
                            ) : (
                                <PageHeaderStatusChip variant="success">
                                    Up to date
                                </PageHeaderStatusChip>
                            )
                        }
                        subline={[
                            'Your board decisions, reading and follow-up',
                            `${feed.totals.pending} pending`,
                            `${feed.totals.completed} completed with receipts`,
                            unavailableSources.length > 0
                                ? 'Some sources unavailable'
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        actions={
                            <form
                                onSubmit={handleSearchSubmit}
                                className="flex items-center"
                            >
                                <PageHeaderSearch
                                    value={searchQuery}
                                    onChange={setSearchQuery}
                                    placeholder="Search personal work..."
                                />
                            </form>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Decisions to vote"
                                    href="/governance/my-work?kind=vote"
                                    tone={
                                        feed.totals.vote > 0
                                            ? 'warning'
                                            : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {feed.totals.vote}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {feed.totals.vote === 1
                                            ? '1 vote waiting'
                                            : `${feed.totals.vote} votes waiting`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Reading & packs"
                                    href="/governance/my-work?kind=read"
                                    tone="brand"
                                >
                                    <PageHeaderMeterBig>
                                        {feed.totals.read}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {feed.totals.read === 1
                                            ? '1 pack / policy'
                                            : `${feed.totals.read} packs / policies`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Assigned actions"
                                    href="/governance/my-work?kind=act"
                                    tone={
                                        feed.totals.act > 0
                                            ? feed.totals.overdue > 0
                                                ? 'critical'
                                                : 'warning'
                                            : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {feed.totals.act}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {feed.totals.act === 1
                                            ? '1 action pending'
                                            : `${feed.totals.act} actions pending`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Awareness & updates"
                                    href="/governance/my-work?kind=know"
                                    tone="brand"
                                >
                                    <PageHeaderMeterBig>
                                        {feed.totals.know}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {feed.totals.know === 1
                                            ? '1 update'
                                            : `${feed.totals.know} updates`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Completed"
                                    href="/governance/my-work?status=completed"
                                    tone={
                                        feed.totals.completed > 0
                                            ? 'success'
                                            : 'brand'
                                    }
                                    ariaLabel="View completed work and receipts"
                                >
                                    <PageHeaderMeterBig>
                                        {feed.totals.completed}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {feed.totals.completed === 1
                                            ? '1 receipt'
                                            : `${feed.totals.completed} receipts`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <div className="flex flex-wrap items-center gap-2">
                                <PageHeaderFilterSelect
                                    icon={ListChecks}
                                    label="Kind"
                                    value={currentKind}
                                    options={KIND_OPTIONS(feed.totals)}
                                    onChange={(val) =>
                                        applyFilters({ kind: val, page: 1 })
                                    }
                                />
                                <PageHeaderFilterSelect
                                    label="Status"
                                    value={currentStatus}
                                    options={[
                                        {
                                            value: 'pending',
                                            label: 'Pending',
                                        },
                                        {
                                            value: 'completed',
                                            label: 'Completed',
                                        },
                                        { value: 'all', label: 'All status' },
                                    ]}
                                    onChange={(val) =>
                                        applyFilters({ status: val, page: 1 })
                                    }
                                />
                                <PageHeaderFilterSelect
                                    label="Due"
                                    value={currentDue}
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All deadlines',
                                        },
                                        {
                                            value: 'overdue',
                                            label: 'Overdue only',
                                        },
                                        {
                                            value: 'next7',
                                            label: 'Next 7 days',
                                        },
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
                    {/* Source availability banner if any source failed */}
                    {unavailableSources.length > 0 && (
                        <div
                            role="alert"
                            data-dusk="source-unavailable-banner"
                            className="flex items-center gap-3 rounded-lg border border-status-warning/30 bg-status-warning-bg p-4 text-sm text-status-warning"
                        >
                            <AlertTriangle
                                className="size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <div>
                                <span className="font-semibold">
                                    Some source systems could not be reached (
                                    {unavailableSources.join(', ')}).
                                </span>{' '}
                                Your work list may be incomplete. Missing
                                records from unavailable sources are not marked
                                as completed.
                            </div>
                        </div>
                    )}

                    {/* Work feed table or empty states */}
                    {feed.items.length === 0 ? (
                        isFiltered ? (
                            <EmptyState
                                icon={Search}
                                title="No results for these filters"
                                description="No personal obligations match the selected kind, status, due date or search."
                                action={
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearchQuery('');
                                            router.get('/governance/my-work');
                                        }}
                                    >
                                        Reset filters
                                    </Button>
                                }
                            />
                        ) : unavailableSources.length > 0 ? (
                            <EmptyState
                                icon={AlertTriangle}
                                title="Nothing found in the sources that loaded"
                                description="Some sources could not be reached, so obligations from them are not shown. Try again shortly."
                            />
                        ) : (
                            <EmptyState
                                icon={CheckCircle2}
                                title="Nothing pending for you"
                                description={
                                    feed.totals.completed > 0
                                        ? `No votes, reading or assigned actions are waiting on you. ${feed.totals.completed} completed ${feed.totals.completed === 1 ? 'item has a receipt' : 'items have receipts'} under Completed.`
                                        : 'No votes, reading or assigned actions are waiting on you.'
                                }
                            />
                        )
                    ) : (
                        <>
                            <EntityTable<GovernanceWorkItemData>
                                rows={feed.items}
                                rowKey={(row) => row.id}
                                identityLabel="Obligation"
                                identityWidth="2.3fr"
                                identity={(row) => ({
                                    icon: getKindIcon(row.kind),
                                    name: row.title,
                                    subline: `${row.source.reference}${row.reason ? ` · ${row.reason}` : ''}`,
                                    linkLabel: row.title,
                                })}
                                hrefFor={(row) =>
                                    row.status !== 'completed' &&
                                    row.required_action?.href &&
                                    row.required_action.allowed
                                        ? row.required_action.href
                                        : '#'
                                }
                                onOpen={handleRowOpen}
                                columns={columns}
                                actionsFor={getActionsFor}
                            />

                            {/* Pagination controls */}
                            {feed.pagination.last_page > 1 && (
                                <div className="flex flex-wrap items-center justify-between gap-3 px-2 py-2">
                                    <span className="text-xs text-muted-foreground">
                                        Showing{' '}
                                        {(feed.pagination.current_page - 1) *
                                            feed.pagination.per_page +
                                            1}{' '}
                                        to{' '}
                                        {Math.min(
                                            feed.pagination.current_page *
                                                feed.pagination.per_page,
                                            feed.pagination.total,
                                        )}{' '}
                                        of {feed.pagination.total} items
                                    </span>
                                    <div className="flex items-center gap-1">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                feed.pagination.current_page <=
                                                1
                                            }
                                            onClick={() =>
                                                applyFilters({
                                                    page:
                                                        feed.pagination
                                                            .current_page - 1,
                                                })
                                            }
                                            aria-label="Previous page"
                                        >
                                            <ChevronLeft className="h-4 w-4" />
                                        </Button>
                                        <span className="px-3 text-xs font-medium text-foreground">
                                            Page {feed.pagination.current_page}{' '}
                                            of {feed.pagination.last_page}
                                        </span>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                feed.pagination.current_page >=
                                                feed.pagination.last_page
                                            }
                                            onClick={() =>
                                                applyFilters({
                                                    page:
                                                        feed.pagination
                                                            .current_page + 1,
                                                })
                                            }
                                            aria-label="Next page"
                                        >
                                            <ChevronRight className="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </PageLayout>

            {/* Durable Receipt Dialog */}
            <Dialog
                open={selectedReceiptItem !== null}
                onOpenChange={(open) => {
                    if (!open) setSelectedReceiptItem(null);
                }}
            >
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <CheckCircle2 className="h-5 w-5 text-status-success" />
                            Completion receipt
                        </DialogTitle>
                        <DialogDescription>
                            Durable cryptographic or audit receipt proving
                            completion of this governance obligation.
                        </DialogDescription>
                    </DialogHeader>

                    {selectedReceiptItem && (
                        <div className="space-y-4 py-2">
                            <div className="rounded-lg border bg-muted/40 p-4 space-y-2.5">
                                <div className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Obligation
                                </div>
                                <div className="text-sm font-semibold text-foreground">
                                    {selectedReceiptItem.title}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Reference:{' '}
                                    <span className="font-mono text-foreground">
                                        {selectedReceiptItem.source.reference}
                                    </span>
                                </div>
                            </div>

                            <div className="rounded-lg border p-4 space-y-3">
                                <div className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Receipt Details
                                </div>
                                <div className="grid grid-cols-2 gap-3 text-xs">
                                    <div>
                                        <span className="text-muted-foreground">
                                            Receipt ID:
                                        </span>
                                        <div className="mt-0.5 font-mono text-[11px] font-semibold text-foreground break-all">
                                            {selectedReceiptItem.receipt
                                                ?.receipt_id || 'N/A'}
                                        </div>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">
                                            Completed at:
                                        </span>
                                        <div className="mt-0.5 text-foreground font-medium">
                                            {selectedReceiptItem.receipt
                                                ?.completed_at ? (
                                                <time
                                                    dateTime={
                                                        selectedReceiptItem
                                                            .receipt
                                                            .completed_at
                                                    }
                                                >
                                                    {formatDateTimeLong(
                                                        selectedReceiptItem
                                                            .receipt
                                                            .completed_at,
                                                    )}
                                                </time>
                                            ) : (
                                                'Confirmed'
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {selectedReceiptItem.receipt?.vote && (
                                    <div className="pt-2 border-t text-xs">
                                        <span className="text-muted-foreground">
                                            Vote cast:
                                        </span>
                                        <span className="ml-2 font-semibold uppercase text-foreground">
                                            {selectedReceiptItem.receipt.vote}
                                        </span>
                                    </div>
                                )}

                                {selectedReceiptItem.receipt
                                    ?.revision_number !== undefined && (
                                    <div className="pt-2 border-t text-xs">
                                        <span className="text-muted-foreground">
                                            Pack revision confirmed:
                                        </span>
                                        <span className="ml-2 font-mono font-semibold text-foreground">
                                            Rev{' '}
                                            {
                                                selectedReceiptItem.receipt
                                                    .revision_number
                                            }
                                        </span>
                                    </div>
                                )}

                                {selectedReceiptItem.receipt?.version !==
                                    undefined && (
                                    <div className="pt-2 border-t text-xs">
                                        <span className="text-muted-foreground">
                                            Policy version attested:
                                        </span>
                                        <span className="ml-2 font-mono font-semibold text-foreground">
                                            v{selectedReceiptItem.receipt.version}
                                        </span>
                                    </div>
                                )}

                                {selectedReceiptItem.receipt
                                    ?.completion_notes && (
                                    <div className="pt-2 border-t text-xs">
                                        <span className="text-muted-foreground">
                                            Completion notes:
                                        </span>
                                        <p className="mt-1 italic text-foreground">
                                            {
                                                selectedReceiptItem.receipt
                                                    .completion_notes
                                            }
                                        </p>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    <DialogFooter className="flex flex-col sm:flex-row items-center justify-between gap-2">
                        {selectedReceiptItem?.receipt?.receipt_id && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-xs"
                                onClick={() =>
                                    handleCopyReceipt(
                                        selectedReceiptItem.receipt
                                            ?.receipt_id ?? '',
                                    )
                                }
                            >
                                <Copy className="mr-1.5 h-3.5 w-3.5" />
                                {copiedReceipt ? 'Copied!' : 'Copy receipt ID'}
                            </Button>
                        )}
                        <div className="flex items-center gap-2 ml-auto">
                            {selectedReceiptItem?.source?.href && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    asChild
                                >
                                    <Link
                                        href={selectedReceiptItem.source.href}
                                    >
                                        <ExternalLink className="mr-1.5 h-3.5 w-3.5" />
                                        Open source record
                                    </Link>
                                </Button>
                            )}
                            <Button
                                size="sm"
                                onClick={() => setSelectedReceiptItem(null)}
                            >
                                Return to My work
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
