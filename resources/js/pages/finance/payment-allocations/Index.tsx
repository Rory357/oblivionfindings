import { FinanceSectionRail, formatMoney } from '@/components/finance';
import {
    EntityStatusChip,
    EntityTable,
    ListCaption,
    type EntityTableColumn,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { EmptyList } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ArrowLeftRight, Receipt } from 'lucide-react';

type Allocation = {
    id: number;
    type: string;
    payment_date: string;
    amount: number;
    allocatable_type: string;
    allocatable_id: number | null;
    notes: string | null;
    created_at: string;
    review_state: 'traceable' | 'review_required';
};

type Props = {
    allocations: {
        data: Allocation[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
        per_page: number;
    };
    filters: {
        type: string;
    };
    typeTotals: Record<
        'receivable' | 'payable',
        { count: number; total_amount: number }
    >;
    legacyReview: {
        state: 'clear' | 'review_required';
        count: number;
        total_amount: number;
        correction_policy: string;
    };
};

const ALL = '__all';

const TYPE_OPTIONS = [
    { value: ALL, label: 'Any type' },
    { value: 'receivable', label: 'Receipts (from clients)' },
    { value: 'payable', label: 'Payments (to vendors)' },
];

const TYPE_LABELS: Record<string, string> = {
    receivable: 'Receipt',
    payable: 'Payment',
};

/** `allocatable_type` arrives as a class basename — show the record it means. */
const TARGET_LABELS: Record<string, string> = {
    FinInvoice: 'Invoice',
    FinBill: 'Bill',
    FinCreditNote: 'Credit note',
    FinPaymentRun: 'Payment run',
    FinBankTransaction: 'Bank transaction',
};

const TARGET_HREFS: Record<string, string> = {
    FinInvoice: '/finance/invoices',
    FinBill: '/finance/bills',
    FinCreditNote: '/finance/credit-notes',
    FinPaymentRun: '/finance/payment-runs',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Receivables', href: '/finance/invoices' },
    { title: 'Allocations', href: '/finance/payment-allocations' },
];

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

export default function PaymentAllocationsIndex({
    allocations,
    filters,
    typeTotals,
    legacyReview,
}: Props) {
    const targetLabel = (allocation: Allocation) => {
        if (!allocation.allocatable_type) return 'Unlinked';
        const label =
            TARGET_LABELS[allocation.allocatable_type] ??
            allocation.allocatable_type;
        return allocation.allocatable_id
            ? `${label} #${allocation.allocatable_id}`
            : label;
    };

    const columns: EntityTableColumn<Allocation>[] = [
        {
            key: 'type',
            label: 'Type',
            width: '0.7fr',
            cell: (allocation) => (
                <EntityStatusChip
                    variant={
                        allocation.type === 'receivable' ? 'success' : 'info'
                    }
                >
                    {TYPE_LABELS[allocation.type] ?? allocation.type}
                </EntityStatusChip>
            ),
        },
        {
            key: 'target',
            label: 'Applied to',
            width: '1fr',
            cell: (allocation) => targetLabel(allocation),
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '0.8fr',
            align: 'right',
            cell: (allocation) => (
                <span className="font-medium tabular-nums">
                    {formatMoney(allocation.amount)}
                </span>
            ),
        },
        {
            key: 'review_state',
            label: 'Traceability',
            width: '0.9fr',
            cell: (allocation) => (
                <EntityStatusChip
                    variant={
                        allocation.review_state === 'traceable'
                            ? 'success'
                            : 'warning'
                    }
                >
                    {allocation.review_state === 'traceable'
                        ? 'Journal-backed'
                        : 'Needs review'}
                </EntityStatusChip>
            ),
        },
        {
            key: 'notes',
            label: 'Notes',
            width: '1.2fr',
            cell: (allocation) => (
                <span className="truncate text-muted-foreground">
                    {allocation.notes || '—'}
                </span>
            ),
        },
    ];

    const header = (
        <PageHeader
            variant="index"
            icon={ArrowLeftRight}
            title="Payment allocations"
            titleChip={
                legacyReview.count > 0 ? (
                    <PageHeaderStatusChip variant="warning">
                        {legacyReview.count} need review
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        All journal-backed
                    </PageHeaderStatusChip>
                )
            }
            subline={`How receipts and payments have been applied · ${allocations.total} allocations on record`}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Allocations"
                        href="/finance/payment-allocations"
                        ariaLabel="View every allocation"
                    >
                        <PageHeaderMeterBig>
                            {allocations.total}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Receipts and payments applied
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Receipts"
                        tone="success"
                        href="/finance/payment-allocations?type=receivable"
                        ariaLabel="View receipt allocations"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(typeTotals.receivable.total_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {typeTotals.receivable.count} applied to invoices
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Payments"
                        href="/finance/payment-allocations?type=payable"
                        ariaLabel="View payment allocations"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(typeTotals.payable.total_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {typeTotals.payable.count} applied to bills
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Needs review"
                        tone={legacyReview.count > 0 ? 'warning' : 'brand'}
                        href="/finance/receivables"
                        ariaLabel="View aged receivables"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(legacyReview.total_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {legacyReview.count} rows without a journal
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    label="Type"
                    value={filters.type || ALL}
                    allValue={ALL}
                    options={TYPE_OPTIONS}
                    onChange={(value) =>
                        router.get(
                            '/finance/payment-allocations',
                            value === ALL ? {} : { type: value },
                            { preserveState: true, preserveScroll: true },
                        )
                    }
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payment allocations" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Allocation history"
                        caption={`${allocations.data.length} of ${allocations.total} shown`}
                    />

                    {allocations.data.length === 0 ? (
                        <EmptyList
                            icon={ArrowLeftRight}
                            itemName="allocation"
                            title="No allocations yet"
                            description="Receipts and vendor payments appear here once they are applied to an invoice or bill."
                        />
                    ) : (
                        <>
                            <EntityTable
                                rows={allocations.data}
                                rowKey={(allocation) => allocation.id}
                                identityLabel="Allocation"
                                identity={(allocation) => ({
                                    icon: Receipt,
                                    name: formatDate(allocation.payment_date),
                                    subline: targetLabel(allocation),
                                })}
                                columns={columns}
                                actionsFor={() => []}
                                hrefFor={(allocation) =>
                                    allocation.allocatable_id &&
                                    TARGET_HREFS[allocation.allocatable_type]
                                        ? `${TARGET_HREFS[allocation.allocatable_type]}/${allocation.allocatable_id}`
                                        : '/finance/payment-allocations'
                                }
                                minWidth={1020}
                            />
                            <LaravelPagination
                                links={allocations.links}
                                lastPage={allocations.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
