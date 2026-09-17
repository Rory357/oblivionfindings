import { FinanceSectionRail } from '@/components/finance';
import {
    EntityTable,
    ListCaption,
    compactMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { EmptyList } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';

import { providerLabel } from './_dialogs';

interface LogEntry {
    id: number;
    synced_at: string;
    status: 'success' | 'failed' | 'partial';
    transactions_fetched: number;
    transactions_imported: number;
    transactions_skipped: number;
    error_message: string | null;
    duration_ms: number | null;
}

interface PaginatedLogs {
    data: LogEntry[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Feed {
    id: number;
    provider: string;
    bank_account_id: number;
    bank_account_name: string;
    bank_name: string;
}

interface Summary {
    total: number;
    successful: number;
    failed: number;
    imported: number;
    last_synced_at: string | null;
}

interface Props {
    feed: Feed;
    logs: PaginatedLogs;
    summary: Summary;
}

const formatDuration = (ms: number | null): string => {
    if (ms === null) return '—';
    if (ms < 1000) return `${ms} ms`;
    return `${(ms / 1000).toFixed(1)} s`;
};

export default function BankFeedLogs({ feed, logs, summary }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Banking', href: '/finance/banking' },
        { title: 'Bank feeds', href: '/finance/bank-feeds' },
        {
            title: `${feed.bank_account_name} sync logs`,
            href: `/finance/bank-feeds/${feed.id}/logs`,
        },
    ];

    // Sync logs are an append-only audit trail — nothing to open or act on.
    const actionsFor = (): MenuItem[] => compactMenu([]);

    const columns: EntityTableColumn<LogEntry>[] = [
        {
            key: 'status',
            label: 'Outcome',
            width: '0.9fr',
            cell: (log) => <StatusBadge status={log.status} />,
        },
        {
            key: 'fetched',
            label: 'Fetched',
            width: '0.6fr',
            align: 'right',
            cell: (log) => (
                <span className="tabular-nums">{log.transactions_fetched}</span>
            ),
        },
        {
            key: 'imported',
            label: 'Imported',
            width: '0.6fr',
            align: 'right',
            cell: (log) => (
                <span className="tabular-nums">
                    {log.transactions_imported}
                </span>
            ),
        },
        {
            key: 'skipped',
            label: 'Skipped',
            width: '0.6fr',
            align: 'right',
            cell: (log) => (
                <span className="tabular-nums">{log.transactions_skipped}</span>
            ),
        },
        {
            key: 'duration',
            label: 'Duration',
            width: '0.6fr',
            align: 'right',
            cell: (log) => (
                <span className="tabular-nums">
                    {formatDuration(log.duration_ms)}
                </span>
            ),
        },
        {
            key: 'error',
            label: 'Error',
            width: '1.4fr',
            cell: (log) =>
                log.error_message ? (
                    <span className="truncate text-status-critical">
                        {log.error_message}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
    ];

    const header = (
        <PageHeader
            variant="profile"
            icon={RefreshCw}
            backHref="/finance/bank-feeds"
            title="Sync logs"
            titleChip={
                <PageHeaderStatusChip
                    variant={summary.failed > 0 ? 'critical' : 'success'}
                >
                    {summary.failed > 0
                        ? `${summary.failed} failed`
                        : 'No failures'}
                </PageHeaderStatusChip>
            }
            subline={`${feed.bank_account_name} · ${providerLabel(feed.provider)} · ${feed.bank_name}`}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Syncs"
                        href={`/finance/bank-feeds/${feed.id}/logs`}
                        ariaLabel="View every sync log"
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.last_synced_at
                                ? `Last ran ${summary.last_synced_at}`
                                : 'Never run'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Successful"
                        tone="success"
                        href={`/finance/bank-feeds/${feed.id}/logs`}
                        ariaLabel="View successful syncs"
                    >
                        <PageHeaderMeterBig>
                            {summary.successful}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Runs that completed
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Failed"
                        tone={summary.failed > 0 ? 'critical' : 'brand'}
                        href="/finance/bank-feeds"
                        ariaLabel="View bank feeds"
                    >
                        <PageHeaderMeterBig>
                            {summary.failed}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Runs that ended in an error
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Transactions imported"
                        href={`/finance/bank-transactions?bank_account_id=${feed.bank_account_id}`}
                        ariaLabel="View the transactions this feed imported"
                    >
                        <PageHeaderMeterBig>
                            {summary.imported}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Across every sync
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Sync logs — ${feed.bank_account_name}`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {logs.data.length === 0 ? (
                        <EmptyList
                            icon={RefreshCw}
                            itemName="sync log"
                            title="No sync logs yet"
                            description="This bank feed has not run a sync."
                        />
                    ) : (
                        <>
                            <ListCaption
                                title="Sync history"
                                caption={`${logs.data.length} of ${logs.total} shown`}
                            />
                            <EntityTable
                                rows={logs.data}
                                rowKey={(log) => log.id}
                                identityLabel="Synced at"
                                identityWidth="1.3fr"
                                identity={(log) => ({
                                    icon: RefreshCw,
                                    name: log.synced_at,
                                    subline:
                                        log.status === 'failed'
                                            ? 'Sync failed'
                                            : `${log.transactions_imported} imported`,
                                })}
                                columns={columns}
                                actionsFor={actionsFor}
                                minWidth={1040}
                            />
                            <LaravelPagination
                                links={logs.links}
                                lastPage={logs.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
