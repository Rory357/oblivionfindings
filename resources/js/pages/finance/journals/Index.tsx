import { FinanceSectionRail, NewJournalDialog, formatMoney } from '@/components/finance';
import { FinancePeriodFilter } from '@/components/finance/finance-period-filter';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
    useEntityContextMenu,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    BookOpen,
    Download,
    Eye,
    Plus,
    RefreshCw,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';

interface Journal {
    id: number;
    journal_number: string;
    journal_date: string;
    type: string;
    description: string | null;
    total_amount: string;
    status: string;
    lines_count: number;
}

interface PaginatedJournals {
    data: Journal[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Filters {
    status?: string;
    type?: string;
    date_from?: string;
    date_to?: string;
    search?: string;
}

/** Org-wide totals for the CURRENT filter — never the page in front of you. */
interface Summary {
    total: number;
    posted: number;
    draft: number;
    reversed: number;
}

interface RefItem {
    id: number;
    code: string;
    name: string;
}

interface RecurringOccurrenceAttempt {
    outcome: 'failed' | 'posted' | 'recovered';
    error_code: string | null;
    started_at: string | null;
    finished_at: string | null;
}

interface RecurringOccurrenceHistory {
    id: number;
    schedule_name: string;
    scheduled_for: string;
    status: 'failed' | 'posted' | 'processing';
    attempt_count: number;
    last_attempted_at: string | null;
    posted_at: string | null;
    failed_at: string | null;
    recovered_at: string | null;
    last_error_code: string | null;
    journal: { id: number; journal_number: string } | null;
    attempts: RecurringOccurrenceAttempt[];
}

interface Props {
    journals: PaginatedJournals;
    summary: Summary;
    filters: Filters;
    canManage?: boolean;
    accounts?: RefItem[];
    costCentres?: RefItem[];
    fundingStreams?: RefItem[];
    recurringOccurrenceHistory?: RecurringOccurrenceHistory[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'General ledger', href: '/finance/ledger' },
    { title: 'Journals', href: '/finance/journals' },
];

const STATUS_OPTIONS = [
    { value: 'all', label: 'All statuses' },
    { value: 'draft', label: 'Draft' },
    { value: 'posted', label: 'Posted' },
    { value: 'reversed', label: 'Reversed' },
];

const TYPE_OPTIONS = [
    { value: 'all', label: 'All types' },
    { value: 'standard', label: 'Standard' },
    { value: 'adjustment', label: 'Adjustment' },
    { value: 'opening', label: 'Opening' },
];

/** `journal_date` is a date cast — it arrives as an ISO instant, not YYYY-MM-DD. */
const journalDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

const typeLabel = (type: string) =>
    TYPE_OPTIONS.find((o) => o.value === type)?.label ??
    type.charAt(0).toUpperCase() + type.slice(1);

/** What a recurring run actually did, in one phrase plus its severity. */
const recurringOutcome = (occurrence: RecurringOccurrenceHistory) => {
    if (occurrence.status === 'failed') {
        return { variant: 'warning' as const, label: 'Retry pending' };
    }
    if (occurrence.status === 'processing') {
        return { variant: 'info' as const, label: 'Processing' };
    }
    if (occurrence.attempt_count > 1) {
        return { variant: 'success' as const, label: 'Recovered on retry' };
    }
    if (occurrence.recovered_at !== null) {
        return {
            variant: 'success' as const,
            label: 'Recovered from legacy run',
        };
    }
    return { variant: 'success' as const, label: 'Posted' };
};

export default function JournalsIndex({
    journals,
    summary,
    filters,
    canManage = false,
    accounts = [],
    costCentres = [],
    fundingStreams = [],
    recurringOccurrenceHistory = [],
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const recurringRef = useRef<HTMLDivElement>(null);

    const journalCtx = useEntityContextMenu<Journal>();
    const runCtx = useEntityContextMenu<RecurringOccurrenceHistory>();

    const status = filters.status ?? '';
    const type = filters.type ?? '';
    const dateFrom = filters.date_from ?? '';
    const dateTo = filters.date_to ?? '';

    const apply = (next: Partial<Filters>) => {
        const merged: Filters = {
            status,
            type,
            date_from: dateFrom,
            date_to: dateTo,
            search,
            ...next,
        };
        const params: Record<string, string> = {};
        Object.entries(merged).forEach(([key, value]) => {
            if (value) params[key] = value;
        });
        router.get('/finance/journals', params, { preserveState: true });
    };

    const clearFilters = () => {
        setSearch('');
        router.get('/finance/journals', {}, { preserveState: true });
    };

    const hasFilters = Boolean(
        filters.search || status || type || dateFrom || dateTo,
    );

    const exportUrl = `/finance/journals/export?${new URLSearchParams(
        Object.entries({
            status,
            type,
            date_from: dateFrom,
            date_to: dateTo,
            search: filters.search ?? '',
        }).filter(([, v]) => v) as [string, string][],
    ).toString()}`;

    const failedRuns = recurringOccurrenceHistory.filter(
        (o) => o.status === 'failed',
    ).length;

    /* ---------------- Row actions ---------------- */

    const journalActions = (journal: Journal): MenuItem[] => [
        {
            label: 'Open journal',
            icon: Eye,
            onClick: () => router.visit(`/finance/journals/${journal.id}`),
        },
    ];

    const runActions = (run: RecurringOccurrenceHistory): MenuItem[] =>
        run.journal
            ? [
                  {
                      label: `Open journal ${run.journal.journal_number}`,
                      icon: BookOpen,
                      onClick: () =>
                          router.visit(`/finance/journals/${run.journal?.id}`),
                  },
              ]
            : [];

    /* ---------------- Columns ---------------- */

    const journalColumns: EntityTableColumn<Journal>[] = [
        {
            key: 'date',
            label: 'Date',
            width: '140px',
            cell: (journal) => (
                <span className="whitespace-nowrap text-muted-foreground">
                    {journalDate(journal.journal_date)}
                </span>
            ),
        },
        {
            key: 'type',
            label: 'Type',
            width: '150px',
            cell: (journal) => <EntityChip>{typeLabel(journal.type)}</EntityChip>,
        },
        {
            key: 'lines',
            label: 'Lines',
            width: '90px',
            align: 'right',
            cell: (journal) => (
                <span className="tabular-nums">{journal.lines_count}</span>
            ),
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '160px',
            align: 'right',
            cell: (journal) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(journal.total_amount)}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '130px',
            cell: (journal) => <StatusBadge status={journal.status} />,
        },
    ];

    const runColumns: EntityTableColumn<RecurringOccurrenceHistory>[] = [
        {
            key: 'due',
            label: 'Due date',
            width: '140px',
            cell: (run) => (
                <span className="whitespace-nowrap text-muted-foreground">
                    {formatDateOnly(run.scheduled_for)}
                </span>
            ),
        },
        {
            key: 'attempts',
            label: 'Attempts',
            width: '110px',
            align: 'right',
            cell: (run) => (
                <span className="tabular-nums">{run.attempt_count}</span>
            ),
        },
        {
            key: 'last_attempt',
            label: 'Last attempt',
            width: '190px',
            cell: (run) =>
                run.last_attempted_at ? (
                    <span className="whitespace-nowrap text-muted-foreground">
                        {formatDateTime(run.last_attempted_at)}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'outcome',
            label: 'Outcome',
            width: '220px',
            cell: (run) => {
                const outcome = recurringOutcome(run);
                const error = run.attempts[0]?.error_code;
                return (
                    <span className="flex min-w-0 flex-col gap-0.5">
                        <EntityStatusChip variant={outcome.variant}>
                            {outcome.label}
                        </EntityStatusChip>
                        {error ? (
                            <span className="truncate text-[11px] text-muted-foreground">
                                {error.replace(/_/g, ' ')}
                            </span>
                        ) : null}
                    </span>
                );
            },
        },
        {
            key: 'journal',
            label: 'Journal',
            width: '150px',
            cell: (run) =>
                run.journal ? (
                    <span className="truncate">
                        {run.journal.journal_number}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
    ];

    /* ---------------- Event Horizon header ---------------- */

    const header = (
        <PageHeader
            icon={BookOpen}
            title="Journals"
            titleChip={
                <PageHeaderStatusChip
                    variant={summary.draft > 0 ? 'warning' : 'success'}
                >
                    {summary.draft} draft{summary.draft === 1 ? '' : 's'}
                </PageHeaderStatusChip>
            }
            subline={`General ledger · ${summary.total} journals in this view · ${summary.posted} posted`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') apply({ search });
                        }}
                        placeholder="Search number or description…"
                    />
                    <PageHeaderGlassButton
                        icon={Download}
                        onClick={() => {
                            window.location.href = exportUrl;
                        }}
                    >
                        Export CSV
                    </PageHeaderGlassButton>
                    {canManage ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New journal
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Journals"
                        href="/finance/journals"
                        ariaLabel="View every journal"
                    >
                        <PageHeaderMeterBig>
                            {summary.total}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {hasFilters ? 'matching this filter' : 'all time'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Posted"
                        tone="success"
                        href="/finance/journals?status=posted"
                        ariaLabel="View posted journals"
                    >
                        <PageHeaderMeterDonut
                            percent={
                                summary.total === 0
                                    ? 0
                                    : (summary.posted / summary.total) * 100
                            }
                            caption={`${summary.posted} of ${summary.total} on the ledger`}
                        />
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Drafts"
                        tone={summary.draft > 0 ? 'warning' : 'brand'}
                        href="/finance/journals?status=draft"
                        ariaLabel="View draft journals"
                    >
                        <PageHeaderMeterBig>
                            {summary.draft}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            waiting to be posted
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Reversed"
                        href="/finance/journals?status=reversed"
                        ariaLabel="View reversed journals"
                    >
                        <PageHeaderMeterBig>
                            {summary.reversed}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            corrected by a reversing entry
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    {recurringOccurrenceHistory.length > 0 ? (
                        <PageHeaderMeterBlock
                            label="Recurring runs"
                            tone={failedRuns > 0 ? 'warning' : 'brand'}
                            ariaLabel="Jump to the recurring journal run history"
                            onClick={() =>
                                recurringRef.current?.scrollIntoView({
                                    block: 'start',
                                })
                            }
                        >
                            <PageHeaderMeterBig>
                                {failedRuns}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                awaiting retry of{' '}
                                {recurringOccurrenceHistory.length} tracked runs
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={status || 'all'}
                        allValue="all"
                        options={STATUS_OPTIONS}
                        onChange={(value) =>
                            apply({ status: value === 'all' ? '' : value })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Type"
                        value={type || 'all'}
                        allValue="all"
                        options={TYPE_OPTIONS}
                        onChange={(value) =>
                            apply({ type: value === 'all' ? '' : value })
                        }
                    />
                    <FinancePeriodFilter
                        url="/finance/journals"
                        from={dateFrom}
                        to={dateTo}
                        idPrefix="journals-period"
                        onApply={(range) =>
                            apply({ date_from: range.from, date_to: range.to })
                        }
                    />
                    {hasFilters ? (
                        <PageHeaderFilterButton icon={X} onClick={clearFilters}>
                            Clear filters
                        </PageHeaderFilterButton>
                    ) : null}
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Journals" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {recurringOccurrenceHistory.length > 0 ? (
                        <div
                            ref={recurringRef}
                            className="flex scroll-mt-5 flex-col gap-5"
                        >
                            <ListCaption
                                title="Recurring journal run history"
                                caption={`${recurringOccurrenceHistory.length} tracked runs · failed runs stay queued for the scheduled retry`}
                            />
                            <EntityTable
                                rows={recurringOccurrenceHistory}
                                rowKey={(run) => run.id}
                                identityLabel="Schedule"
                                minWidth={1040}
                                identity={(run) => ({
                                    icon: RefreshCw,
                                    name: run.schedule_name,
                                    subline: run.journal
                                        ? run.journal.journal_number
                                        : undefined,
                                })}
                                columns={runColumns}
                                actionsFor={runActions}
                                onOpen={(run) => {
                                    if (run.journal) {
                                        router.visit(
                                            `/finance/journals/${run.journal.id}`,
                                        );
                                    }
                                }}
                                onRowContextMenu={(e, run) => runCtx.open(e, run)}
                            />
                        </div>
                    ) : null}

                    <ListCaption
                        title="Journals"
                        caption={`${journals.data.length} of ${journals.total} shown`}
                    />

                    {journals.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No journals match your search"
                            />
                        ) : (
                            <EmptyList
                                icon={BookOpen}
                                itemName="journal"
                                title="No journals yet"
                                description="Create your first general ledger journal entry to get started."
                                action={
                                    canManage ? (
                                        <Button
                                            size="sm"
                                            onClick={() => setCreateOpen(true)}
                                        >
                                            New journal
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )
                    ) : (
                        <>
                            <EntityTable
                                rows={journals.data}
                                rowKey={(journal) => journal.id}
                                identityLabel="Journal"
                                minWidth={1040}
                                identity={(journal) => ({
                                    icon: BookOpen,
                                    name: journal.journal_number,
                                    subline: journal.description ?? undefined,
                                })}
                                hrefFor={(journal) =>
                                    `/finance/journals/${journal.id}`
                                }
                                columns={journalColumns}
                                actionsFor={journalActions}
                                onOpen={(journal) =>
                                    router.visit(
                                        `/finance/journals/${journal.id}`,
                                    )
                                }
                                onRowContextMenu={(e, journal) =>
                                    journalCtx.open(e, journal)
                                }
                            />
                            <LaravelPagination
                                links={journals.links}
                                lastPage={journals.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {journalCtx.ctx ? (
                <EntityContextMenu
                    x={journalCtx.ctx.x}
                    y={journalCtx.ctx.y}
                    icon={BookOpen}
                    title={journalCtx.ctx.record.journal_number}
                    items={journalActions(journalCtx.ctx.record)}
                    onClose={journalCtx.close}
                />
            ) : null}

            {runCtx.ctx ? (
                <EntityContextMenu
                    x={runCtx.ctx.x}
                    y={runCtx.ctx.y}
                    icon={RefreshCw}
                    title={runCtx.ctx.record.schedule_name}
                    items={runActions(runCtx.ctx.record)}
                    onClose={runCtx.close}
                />
            ) : null}

            {canManage ? (
                <NewJournalDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    accounts={accounts}
                    costCentres={costCentres}
                    fundingStreams={fundingStreams}
                />
            ) : null}
        </AppLayout>
    );
}
