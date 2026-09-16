import { FinanceSectionRail, formatMoney } from '@/components/finance';
import {
    PaymentRunDialog,
    type PaymentRunBankAccount,
    type PaymentRunBill,
} from '@/components/finance/payment-run-dialog';
import {
    CounterPill,
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
    PageHeaderGlassButton,
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
import { Banknote, Download, Eye, Landmark, Plus } from 'lucide-react';
import { useState } from 'react';

type PaymentRun = {
    id: number;
    run_number: string;
    payment_date: string;
    bank_account: { id: number; name: string; bank_name: string } | null;
    item_count: number;
    total_amount: number;
    status: string;
    processed_at: string | null;
};

type PaginatedData<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

type Summary = {
    total: number;
    draft: number;
    approved: number;
    awaiting_bank: number;
    awaiting_bank_value: number;
    settled: number;
    legacy_processing: number;
    legacy_completed: number;
};

type PageProps = {
    paymentRuns: PaginatedData<PaymentRun>;
    filters: { status: string };
    summary: Summary;
    canManage: boolean;
    bankAccounts: PaymentRunBankAccount[];
    payableBills: PaymentRunBill[];
};

const STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    approved: 'Approved',
    prepared: 'Prepared',
    exported: 'Exported',
    accepted: 'Bank accepted',
    settled: 'Settled',
    reconciled: 'Reconciled',
    rejected: 'Rejected',
    failed: 'Failed',
    processing: 'Processed (legacy)',
    completed: 'Completed (legacy)',
};

const formatDate = (date: string | null) =>
    date
        ? new Date(date).toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : '—';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Payables', href: '/finance/payables' },
    { title: 'Payment runs', href: '/finance/payment-runs' },
];

export default function PaymentRunsIndex({
    paymentRuns,
    filters,
    summary,
    canManage,
    bankAccounts,
    payableBills,
}: PageProps) {
    const [createOpen, setCreateOpen] = useState(false);

    const apply = (status: string) =>
        router.get(
            '/finance/payment-runs',
            { status: status === 'all' ? '' : status },
            { preserveState: true, replace: true },
        );

    const clearFilters = () =>
        router.get(
            '/finance/payment-runs',
            {},
            { preserveState: true, replace: true },
        );

    const hasFilters = Boolean(filters.status);

    // The two pre-settlement-workflow statuses only appear as filter options
    // when real rows still carry them, and then read as "(legacy)".
    const statusOptions = [
        { value: 'all', label: 'All statuses' },
        { value: 'draft', label: 'Draft' },
        { value: 'approved', label: 'Approved' },
        { value: 'prepared', label: 'Prepared' },
        { value: 'exported', label: 'Exported' },
        { value: 'accepted', label: 'Bank accepted' },
        { value: 'settled', label: 'Settled' },
        { value: 'reconciled', label: 'Reconciled' },
        { value: 'rejected', label: 'Rejected' },
        { value: 'failed', label: 'Failed' },
        ...(summary.legacy_processing > 0
            ? [{ value: 'processing', label: 'Processed (legacy)' }]
            : []),
        ...(summary.legacy_completed > 0
            ? [{ value: 'completed', label: 'Completed (legacy)' }]
            : []),
    ];

    const ctx = useEntityContextMenu<PaymentRun>();

    const menuFor = (run: PaymentRun): MenuItem[] =>
        compactMenu([
            {
                label: 'Open payment run',
                icon: Eye,
                onClick: () =>
                    router.visit(`/finance/payment-runs/${run.id}`),
            },
        ]);

    const columns: EntityTableColumn<PaymentRun>[] = [
        {
            key: 'payment_date',
            label: 'Payment date',
            width: '1.2fr',
            cell: (r) => (
                <span className="text-muted-foreground">
                    {formatDate(r.payment_date)}
                </span>
            ),
        },
        {
            key: 'bank_account',
            label: 'Bank account',
            width: '1.8fr',
            cell: (r) => (
                <span className="truncate">
                    {r.bank_account
                        ? `${r.bank_account.name} · ${r.bank_account.bank_name}`
                        : '—'}
                </span>
            ),
        },
        {
            key: 'items',
            label: 'Bills',
            width: '80px',
            align: 'center',
            cell: (r) => <CounterPill tone="neutral">{r.item_count}</CounterPill>,
        },
        {
            key: 'total',
            label: 'Total',
            width: '1.2fr',
            align: 'right',
            cell: (r) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(r.total_amount)}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '160px',
            cell: (r) => (
                <StatusBadge
                    status={r.status}
                    label={STATUS_LABELS[r.status]}
                    className="rounded-[8px] font-semibold"
                />
            ),
        },
        {
            key: 'processed_at',
            label: 'Processed',
            width: '1.2fr',
            cell: (r) => (
                <span className="text-muted-foreground">
                    {r.processed_at ? formatDate(r.processed_at) : '—'}
                </span>
            ),
        },
    ];

    const exportUrl = `/finance/payment-runs/export?${new URLSearchParams(
        Object.entries({ status: filters.status }).filter(([, v]) => v),
    ).toString()}`;

    const header = (
        <PageHeader
            variant="index"
            icon={Banknote}
            title="Payment runs"
            titleChip={
                <PageHeaderStatusChip
                    variant={summary.awaiting_bank > 0 ? 'info' : 'success'}
                >
                    {summary.awaiting_bank} awaiting bank
                </PageHeaderStatusChip>
            }
            subline={`Batch payments to vendors · ${summary.total} runs · ${summary.draft} draft`}
            actions={
                <>
                    <PageHeaderGlassButton
                        icon={Download}
                        onClick={() => {
                            window.location.href = exportUrl;
                        }}
                    >
                        Export CSV
                    </PageHeaderGlassButton>
                    {canManage && (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New payment run
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="All runs"
                        href="/finance/payment-runs"
                        ariaLabel="View all payment runs"
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Every batch payment recorded
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Awaiting bank"
                        tone="warning"
                        href="/finance/payment-runs?status=exported"
                        ariaLabel="View runs awaiting the bank"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.awaiting_bank_value)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.awaiting_bank} prepared, exported or
                            accepted
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Settled"
                        tone="success"
                        href="/finance/payment-runs?status=settled"
                        ariaLabel="View settled payment runs"
                    >
                        <PageHeaderMeterBig>
                            {summary.settled}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Confirmed and posted
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Draft"
                        href="/finance/payment-runs?status=draft"
                        ariaLabel="View draft payment runs"
                    >
                        <PageHeaderMeterBig>{summary.draft}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.approved} approved and ready to prepare
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    label="Status"
                    value={filters.status || 'all'}
                    options={statusOptions}
                    onChange={apply}
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payment runs" />

            <PageLayout hero={header}>
                <ListCaption
                    title="Payment runs"
                    caption={`${paymentRuns.data.length} of ${paymentRuns.total} shown`}
                />

                {paymentRuns.data.length === 0 ? (
                    hasFilters ? (
                        <EmptySearch
                            onClear={clearFilters}
                            title="No payment runs match your filters"
                        />
                    ) : (
                        <EmptyList
                            icon={Landmark}
                            itemName="payment run"
                            title="No payment runs yet"
                            description="A payment run batches approved bills into one bank payment. Create one to get started."
                            action={
                                canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        New payment run
                                    </Button>
                                ) : undefined
                            }
                        />
                    )
                ) : (
                    <>
                        <EntityTable
                            rows={paymentRuns.data}
                            rowKey={(r) => r.id}
                            identityLabel="Run"
                            identity={(r) => ({
                                icon: Banknote,
                                name: r.run_number,
                            })}
                            columns={columns}
                            actionsFor={menuFor}
                            hrefFor={(r) => `/finance/payment-runs/${r.id}`}
                            onOpen={(r) =>
                                router.visit(`/finance/payment-runs/${r.id}`)
                            }
                            onRowContextMenu={ctx.open}
                            minWidth={1140}
                        />
                        <LaravelPagination
                            links={paymentRuns.links}
                            lastPage={paymentRuns.last_page}
                        />
                    </>
                )}
            </PageLayout>

            {ctx.ctx && (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={Banknote}
                    title={ctx.ctx.record.run_number}
                    items={menuFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            )}

            {canManage && (
                <PaymentRunDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    bankAccounts={bankAccounts}
                    bills={payableBills}
                />
            )}
        </AppLayout>
    );
}
