import { FinanceSectionRail, formatMoney } from '@/components/finance';
import { StartReconciliationDialog } from '@/components/finance/start-reconciliation-dialog';
import {
    EntityContextMenu,
    EntityTable,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Eye, Landmark, Play, Plus, Scale } from 'lucide-react';
import { useCallback, useState } from 'react';

interface Reconciliation {
    id: number;
    statement_date: string;
    statement_balance: string;
    calculated_balance: string | null;
    status: string;
    completed_at: string | null;
    bank_account: { id: number; name: string };
    completed_by: { id: number; name: string } | null;
}

interface PaginatedReconciliations {
    data: Reconciliation[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface BankAccount {
    id: number;
    name: string;
    current_balance?: string | number | null;
}

interface Filters {
    bank_account_id: string;
    status: string;
}

interface Summary {
    total: number;
    completed: number;
    in_progress: number;
    bank_accounts: number;
}

interface Props {
    reconciliations: PaginatedReconciliations;
    bankAccounts: BankAccount[];
    summary: Summary;
    canManage: boolean;
    filters: Filters;
}

const ALL = '__all';

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'completed', label: 'Completed' },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Banking', href: '/finance/banking' },
    { title: 'Reconciliation', href: '/finance/bank-reconciliation' },
];

export default function ReconciliationIndex({
    reconciliations,
    bankAccounts,
    summary,
    canManage = false,
    filters,
}: Props) {
    const [startOpen, setStartOpen] = useState(false);
    const ctxMenu = useEntityContextMenu<Reconciliation>();

    const applyFilters = useCallback(
        (patch: Partial<Filters>) => {
            const next = { ...filters, ...patch };
            router.get(
                '/finance/bank-reconciliation',
                {
                    ...(next.bank_account_id
                        ? { bank_account_id: next.bank_account_id }
                        : {}),
                    ...(next.status ? { status: next.status } : {}),
                },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [filters],
    );

    const clearFilters = () =>
        router.get('/finance/bank-reconciliation', {}, { preserveState: true });

    const hasFilters = Boolean(filters.bank_account_id || filters.status);

    const openReconciliation = (recon: Reconciliation) =>
        router.visit(`/finance/bank-reconciliation/${recon.id}`);

    const actionsFor = (recon: Reconciliation): MenuItem[] =>
        compactMenu([
            {
                label:
                    recon.status === 'in_progress'
                        ? 'Continue reconciliation'
                        : 'Open reconciliation',
                icon: recon.status === 'in_progress' ? Play : Eye,
                onClick: () => openReconciliation(recon),
            },
            {
                label: 'Open bank account',
                icon: Landmark,
                onClick: () =>
                    router.visit(
                        `/finance/bank-accounts/${recon.bank_account.id}`,
                    ),
            },
        ]);

    const columns: EntityTableColumn<Reconciliation>[] = [
        {
            key: 'statement_balance',
            label: 'Statement balance',
            width: '1fr',
            align: 'right',
            cell: (recon) => (
                <span className="tabular-nums">
                    {formatMoney(recon.statement_balance)}
                </span>
            ),
        },
        {
            key: 'calculated_balance',
            label: 'Calculated balance',
            width: '1fr',
            align: 'right',
            cell: (recon) =>
                recon.calculated_balance ? (
                    <span className="tabular-nums">
                        {formatMoney(recon.calculated_balance)}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.8fr',
            cell: (recon) => <StatusBadge status={recon.status} />,
        },
        {
            key: 'completed_at',
            label: 'Completed',
            width: '1fr',
            cell: (recon) =>
                recon.completed_at ? (
                    <span>
                        {recon.completed_at}
                        {recon.completed_by ? (
                            <span className="block text-[11px] text-muted-foreground">
                                {recon.completed_by.name}
                            </span>
                        ) : null}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
    ];

    const header = (
        <PageHeader
            variant="index"
            icon={Scale}
            title="Bank reconciliation"
            titleChip={
                <PageHeaderStatusChip
                    variant={summary.in_progress > 0 ? 'warning' : 'success'}
                >
                    {summary.in_progress > 0
                        ? `${summary.in_progress} in progress`
                        : 'Nothing in progress'}
                </PageHeaderStatusChip>
            }
            subline={`Banking · statements matched against the ledger · ${summary.bank_accounts} bank accounts`}
            actions={
                canManage ? (
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setStartOpen(true)}
                    >
                        Start reconciliation
                    </PageHeaderPrimaryButton>
                ) : undefined
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Reconciliations"
                        href="/finance/bank-reconciliation"
                        ariaLabel="View all reconciliations"
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Across every bank account
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="In progress"
                        tone={summary.in_progress > 0 ? 'warning' : 'brand'}
                        href="/finance/bank-reconciliation?status=in_progress"
                        ariaLabel="View reconciliations in progress"
                    >
                        <PageHeaderMeterBig>
                            {summary.in_progress}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Statements still open
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Completed"
                        tone="success"
                        href="/finance/bank-reconciliation?status=completed"
                        ariaLabel="View completed reconciliations"
                    >
                        <PageHeaderMeterBig>
                            {summary.completed}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Signed off against the ledger
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Bank accounts"
                        href="/finance/bank-accounts"
                        ariaLabel="View bank accounts"
                    >
                        <PageHeaderMeterBig>
                            {summary.bank_accounts}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Active accounts to reconcile
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Bank account"
                        value={filters.bank_account_id || ALL}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any bank account' },
                            ...bankAccounts.map((account) => ({
                                value: String(account.id),
                                label: account.name,
                            })),
                        ]}
                        onChange={(value) =>
                            applyFilters({
                                bank_account_id: value === ALL ? '' : value,
                            })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status || ALL}
                        allValue={ALL}
                        options={STATUS_OPTIONS}
                        onChange={(value) =>
                            applyFilters({ status: value === ALL ? '' : value })
                        }
                    />
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank reconciliation" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Reconciliations"
                        caption={`${reconciliations.data.length} of ${reconciliations.total} shown`}
                    />

                    {reconciliations.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No reconciliations match your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={Scale}
                                itemName="reconciliation"
                                title="No reconciliations yet"
                                description="Start your first bank reconciliation to match a statement against the ledger."
                                action={
                                    canManage ? (
                                        <Button
                                            size="sm"
                                            onClick={() => setStartOpen(true)}
                                        >
                                            Start reconciliation
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )
                    ) : (
                        <>
                            <EntityTable
                                rows={reconciliations.data}
                                rowKey={(recon) => recon.id}
                                identityLabel="Statement"
                                identity={(recon) => ({
                                    icon: Scale,
                                    name: recon.bank_account?.name ?? 'Account',
                                    subline: `Statement ${recon.statement_date}`,
                                })}
                                columns={columns}
                                actionsFor={actionsFor}
                                hrefFor={(recon) =>
                                    `/finance/bank-reconciliation/${recon.id}`
                                }
                                onOpen={openReconciliation}
                                onRowContextMenu={ctxMenu.open}
                                minWidth={1000}
                            />
                            <LaravelPagination
                                links={reconciliations.links}
                                lastPage={reconciliations.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Scale}
                    title={`${ctxMenu.ctx.record.bank_account?.name ?? 'Account'} · ${ctxMenu.ctx.record.statement_date}`}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canManage && (
                <StartReconciliationDialog
                    open={startOpen}
                    onClose={() => setStartOpen(false)}
                    bankAccounts={bankAccounts}
                />
            )}
        </AppLayout>
    );
}
