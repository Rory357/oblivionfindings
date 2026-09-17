import { ConfirmDialog, FinanceSectionRail, formatMoney } from '@/components/finance';
import {
    EmptyValue,
    EntityContextMenu,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
    useEntityContextMenu,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDelta,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ArrowLeftRight, Globe, Plus } from 'lucide-react';
import { useState } from 'react';

type Revaluation = {
    id: number;
    revaluation_date: string;
    total_gain_loss: string;
    status: string;
    journal_number: string | null;
    notes: string | null;
    created_by_name: string | null;
    created_at: string;
};

type PaginatedData = {
    data: Revaluation[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

/** Register-wide totals — never the current page's slice. */
type Summary = {
    total: number;
    posted: number;
    draft: number;
    net_gain_loss: number;
    posted_gain_loss: number;
};

type PageProps = {
    revaluations: PaginatedData;
    summary: Summary;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'General ledger', href: '/finance/ledger' },
    { title: 'FX revaluations', href: '/finance/fx-revaluations' },
];

const STATUS_OPTIONS = [
    { value: 'all', label: 'All statuses' },
    { value: 'draft', label: 'Draft' },
    { value: 'posted', label: 'Posted' },
];

const revalDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

/** Losses read as "(1,234.00)" — the accounting convention. */
const signedMoney = (value: number) =>
    value < 0
        ? `(${formatMoney(Math.abs(value))})`
        : formatMoney(Math.abs(value));

export default function FxRevaluationsIndex({
    revaluations,
    summary,
}: PageProps) {
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [postTarget, setPostTarget] = useState<Revaluation | null>(null);
    const [posting, setPosting] = useState(false);

    const ctx = useEntityContextMenu<Revaluation>();

    const confirmPost = () => {
        if (!postTarget) return;
        router.post(
            `/finance/fx-revaluations/${postTarget.id}/post`,
            {},
            {
                onStart: () => setPosting(true),
                onFinish: () => setPosting(false),
                onSuccess: () => setPostTarget(null),
            },
        );
    };

    const query = search.trim().toLowerCase();
    const shown = revaluations.data.filter((reval) => {
        const matchesText =
            query === '' ||
            (reval.journal_number ?? '').toLowerCase().includes(query) ||
            (reval.notes ?? '').toLowerCase().includes(query) ||
            (reval.created_by_name ?? '').toLowerCase().includes(query);
        const matchesStatus =
            statusFilter === 'all' || reval.status === statusFilter;
        return matchesText && matchesStatus;
    });

    const actionsFor = (reval: Revaluation): MenuItem[] => {
        const items: MenuItem[] = [];
        if (reval.status === 'draft') {
            items.push({
                label: 'Post to the general ledger',
                icon: ArrowLeftRight,
                onClick: () => setPostTarget(reval),
            });
        }
        return items;
    };

    const columns: EntityTableColumn<Revaluation>[] = [
        {
            key: 'gain_loss',
            label: 'Gain / loss',
            width: '160px',
            align: 'right',
            cell: (reval) => {
                const value = Number(reval.total_gain_loss);
                return (
                    <span
                        className={
                            value > 0
                                ? 'font-semibold text-status-success tabular-nums'
                                : value < 0
                                  ? 'font-semibold text-status-critical tabular-nums'
                                  : 'font-semibold tabular-nums'
                        }
                    >
                        {signedMoney(value)}
                    </span>
                );
            },
        },
        {
            key: 'status',
            label: 'Status',
            width: '130px',
            cell: (reval) => <StatusBadge status={reval.status} />,
        },
        {
            key: 'journal',
            label: 'Journal',
            width: '150px',
            cell: (reval) =>
                reval.journal_number ? (
                    <span className="truncate">{reval.journal_number}</span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'created_by',
            label: 'Created by',
            width: '180px',
            cell: (reval) =>
                reval.created_by_name ? (
                    <span className="truncate">{reval.created_by_name}</span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'notes',
            label: 'Notes',
            width: '1.2fr',
            cell: (reval) =>
                reval.notes ? (
                    <span className="truncate text-muted-foreground">
                        {reval.notes}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
    ];

    const netGain = summary.net_gain_loss >= 0;

    const header = (
        <PageHeader
            icon={Globe}
            title="FX revaluations"
            titleChip={
                <PageHeaderStatusChip
                    variant={summary.draft > 0 ? 'warning' : 'success'}
                >
                    {summary.draft} draft{summary.draft === 1 ? '' : 's'}
                </PageHeaderStatusChip>
            }
            subline={`General ledger · ${summary.total} revaluations · unrealised foreign-exchange gain and loss`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search journal, notes or author…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() =>
                            router.visit('/finance/fx-revaluations/create')
                        }
                    >
                        New revaluation
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Revaluations"
                        href="/finance/fx-revaluations"
                        ariaLabel="View every revaluation"
                    >
                        <PageHeaderMeterBig>
                            {summary.total}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            calculated to date
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Posted"
                        tone="success"
                        ariaLabel="Show only posted revaluations"
                        onClick={() => setStatusFilter('posted')}
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
                        label="Awaiting posting"
                        tone={summary.draft > 0 ? 'warning' : 'brand'}
                        ariaLabel="Show only draft revaluations"
                        onClick={() => setStatusFilter('draft')}
                    >
                        <PageHeaderMeterBig>
                            {summary.draft}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            drafts not yet posted to the GL
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Net gain / loss"
                        tone={netGain ? 'success' : 'critical'}
                        href="/finance/journals"
                        ariaLabel="View the journals behind the revaluation gain and loss"
                    >
                        <PageHeaderMeterBig>
                            {signedMoney(summary.net_gain_loss)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterDelta
                            trend={netGain ? 'up' : 'down'}
                            good={netGain}
                        >
                            {signedMoney(summary.posted_gain_loss)} posted
                        </PageHeaderMeterDelta>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    label="Status"
                    value={statusFilter}
                    allValue="all"
                    options={STATUS_OPTIONS}
                    onChange={setStatusFilter}
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="FX revaluations" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Revaluation history"
                        caption={`${shown.length} of ${revaluations.total} shown`}
                    />

                    {shown.length === 0 ? (
                        <EmptyState
                            icon={ArrowLeftRight}
                            heading={
                                revaluations.data.length === 0
                                    ? 'No FX revaluations yet'
                                    : 'No revaluations match your search'
                            }
                            description={
                                revaluations.data.length === 0
                                    ? 'Create your first revaluation to calculate the unrealised gain or loss on open foreign-currency items.'
                                    : 'Clear the search or the status filter to see every revaluation.'
                            }
                            action={
                                revaluations.data.length === 0 ? (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.visit(
                                                '/finance/fx-revaluations/create',
                                            )
                                        }
                                    >
                                        New revaluation
                                    </Button>
                                ) : (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearch('');
                                            setStatusFilter('all');
                                        }}
                                    >
                                        Clear filters
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <>
                            <EntityTable
                                rows={shown}
                                rowKey={(reval) => reval.id}
                                identityLabel="Revaluation"
                                minWidth={1080}
                                identity={(reval) => ({
                                    icon: Globe,
                                    name: revalDate(reval.revaluation_date),
                                    subline: reval.journal_number ?? undefined,
                                })}
                                columns={columns}
                                actionsFor={actionsFor}
                                onRowContextMenu={(e, reval) =>
                                    ctx.open(e, reval)
                                }
                            />
                            <LaravelPagination
                                links={revaluations.links}
                                lastPage={revaluations.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={Globe}
                    title={revalDate(ctx.ctx.record.revaluation_date)}
                    items={actionsFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            ) : null}

            <ConfirmDialog
                variant="default"
                open={!!postTarget}
                onClose={() => setPostTarget(null)}
                title="Post revaluation to the general ledger?"
                description={
                    <>
                        This posts a journal to the ledger for the FX
                        revaluation dated{' '}
                        <span className="font-medium text-foreground">
                            {postTarget
                                ? revalDate(postTarget.revaluation_date)
                                : ''}
                        </span>{' '}
                        recognising{' '}
                        <span className="font-medium text-foreground">
                            {postTarget
                                ? signedMoney(Number(postTarget.total_gain_loss))
                                : ''}
                        </span>{' '}
                        of unrealised gain or loss. Once posted it can&rsquo;t
                        be undone.
                    </>
                }
                confirmText="Post to the general ledger"
                processing={posting}
                onConfirm={confirmPost}
            />
        </AppLayout>
    );
}
