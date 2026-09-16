import { FinanceSectionRail, formatMoney } from '@/components/finance';
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
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyList } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { PageProps, type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { CreditCard, Layers } from 'lucide-react';

import { eftposProviderLabel } from './_dialogs';

interface BatchData {
    id: number;
    batch_number: string;
    batch_date: string;
    settlement_date: string | null;
    terminal_name: string | null;
    terminal_id_code: string | null;
    provider: string | null;
    total_transactions: number;
    total_amount: number;
    total_refunds: number;
    net_amount: number;
    fees: number;
    settlement_amount: number;
    status: string;
    reconciled_at: string | null;
    reconciled_by_name: string | null;
    discrepancy_amount: number;
    discrepancy_notes: string | null;
    bank_transaction: {
        id: number;
        amount: number;
        transaction_date: string;
        description: string | null;
    } | null;
    created_by_name: string | null;
}

interface Transaction {
    id: number;
    transaction_reference: string;
    transaction_date: string;
    card_type: string;
    transaction_type: string;
    amount: number;
    fee_amount: number;
    auth_code: string | null;
    card_last_four: string | null;
    status: string;
}

interface Props extends PageProps {
    batch: BatchData;
    transactions: Transaction[];
}

const formatDate = (date: string | null) =>
    date
        ? new Date(date).toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : '—';

const formatDateTime = (date: string) =>
    new Date(date).toLocaleString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const CARD_TYPE_LABELS: Record<string, string> = {
    visa: 'Visa',
    mastercard: 'Mastercard',
    eftpos: 'EFTPOS',
    amex: 'Amex',
    other: 'Other',
};

/**
 * Transaction type is a domain label, not a lifecycle state, so it goes through
 * <StatusBadge> with an explicit variant instead of a local colour map.
 */
const TXN_TYPES: Record<
    string,
    { label: string; variant: 'success' | 'critical' | 'info' }
> = {
    purchase: { label: 'Purchase', variant: 'success' },
    refund: { label: 'Refund', variant: 'critical' },
    cash_out: { label: 'Cash out', variant: 'info' },
};

/** The header chip's tone — the table rows go through <StatusBadge status>. */
const BATCH_STATUS: Record<
    string,
    { label: string; variant: 'success' | 'warning' | 'critical' | 'info' }
> = {
    open: { label: 'Open', variant: 'info' },
    closed: { label: 'Closed', variant: 'warning' },
    reconciled: { label: 'Reconciled', variant: 'success' },
    discrepancy: { label: 'Discrepancy', variant: 'critical' },
};

export default function EftposBatchDetail({ batch, transactions }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Banking', href: '/finance/banking' },
        { title: 'EFTPOS', href: '/finance/eftpos/terminals' },
        { title: 'Batches', href: '/finance/eftpos/batches' },
        {
            title: `Batch ${batch.batch_number}`,
            href: `/finance/eftpos/batches/${batch.id}`,
        },
    ];

    // Batch transactions are an imported record — read-only by design.
    const actionsFor = (): MenuItem[] => compactMenu([]);

    const columns: EntityTableColumn<Transaction>[] = [
        {
            key: 'card',
            label: 'Card',
            width: '0.9fr',
            cell: (txn) => (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate">
                        {CARD_TYPE_LABELS[txn.card_type] ?? txn.card_type}
                    </span>
                    {txn.card_last_four ? (
                        <span className="truncate text-[11px] text-muted-foreground">
                            •••• {txn.card_last_four}
                        </span>
                    ) : null}
                </span>
            ),
        },
        {
            key: 'type',
            label: 'Type',
            width: '0.7fr',
            cell: (txn) => {
                const type = TXN_TYPES[txn.transaction_type];
                return type ? (
                    <StatusBadge variant={type.variant} label={type.label} />
                ) : (
                    <StatusBadge
                        variant="neutral"
                        label={txn.transaction_type}
                    />
                );
            },
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '0.8fr',
            align: 'right',
            cell: (txn) => (
                <span
                    className={`font-medium tabular-nums ${
                        txn.transaction_type === 'refund'
                            ? 'text-status-critical'
                            : ''
                    }`}
                >
                    {txn.transaction_type === 'refund' ? '−' : ''}
                    {formatMoney(txn.amount)}
                </span>
            ),
        },
        {
            key: 'fee',
            label: 'Fee',
            width: '0.6fr',
            align: 'right',
            cell: (txn) =>
                txn.fee_amount > 0 ? (
                    <span className="tabular-nums text-muted-foreground">
                        {formatMoney(txn.fee_amount)}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'auth',
            label: 'Auth code',
            width: '0.7fr',
            cell: (txn) =>
                txn.auth_code ?? (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.7fr',
            cell: (txn) => <StatusBadge status={txn.status} />,
        },
    ];

    const header = (
        <PageHeader
            variant="profile"
            icon={Layers}
            backHref="/finance/eftpos/batches"
            title={`Batch ${batch.batch_number}`}
            titleChip={
                <PageHeaderStatusChip
                    variant={BATCH_STATUS[batch.status]?.variant ?? 'neutral'}
                >
                    {BATCH_STATUS[batch.status]?.label ?? batch.status}
                </PageHeaderStatusChip>
            }
            subline={[
                batch.terminal_name ?? 'No terminal',
                eftposProviderLabel(batch.provider),
                `Batched ${formatDate(batch.batch_date)}`,
            ].join(' · ')}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Settlement"
                        tone="success"
                        href="/finance/eftpos/batches"
                        ariaLabel="View EFTPOS batches"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(batch.settlement_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {batch.settlement_date
                                ? `Settles ${formatDate(batch.settlement_date)}`
                                : 'Settlement date not set'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Net takings"
                        href="/finance/eftpos/batches"
                        ariaLabel="View EFTPOS batches"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(batch.net_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {formatMoney(batch.total_amount)} taken less{' '}
                            {formatMoney(batch.total_refunds)} refunded
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Fees"
                        href="/finance/eftpos/terminals"
                        ariaLabel="View terminals"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(batch.fees)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Deducted before settlement
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Transactions"
                        href={`/finance/eftpos/batches/${batch.id}`}
                        ariaLabel="View the transactions in this batch"
                    >
                        <PageHeaderMeterBig>
                            {batch.total_transactions}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Card transactions in the batch
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Discrepancy"
                        tone={
                            batch.discrepancy_amount !== 0
                                ? 'critical'
                                : 'brand'
                        }
                        href="/finance/bank-transactions?status=unreconciled"
                        ariaLabel="View unreconciled bank transactions"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(batch.discrepancy_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {batch.bank_transaction
                                ? `Matched to ${formatMoney(batch.bank_transaction.amount)} on ${formatDate(batch.bank_transaction.transaction_date)}`
                                : 'No bank transaction matched yet'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`EFTPOS batch ${batch.batch_number}`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title">
                                Batch details
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-2 gap-5 text-sm sm:grid-cols-3 lg:grid-cols-4">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Terminal
                                    </dt>
                                    <dd className="font-medium">
                                        {batch.terminal_name ?? '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Terminal ID
                                    </dt>
                                    <dd className="font-medium">
                                        {batch.terminal_id_code ?? '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Provider
                                    </dt>
                                    <dd className="font-medium">
                                        {eftposProviderLabel(batch.provider)}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Imported by
                                    </dt>
                                    <dd className="font-medium">
                                        {batch.created_by_name ?? '—'}
                                    </dd>
                                </div>
                                {batch.reconciled_at ? (
                                    <>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Reconciled
                                            </dt>
                                            <dd className="font-medium">
                                                {formatDateTime(
                                                    batch.reconciled_at,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Reconciled by
                                            </dt>
                                            <dd className="font-medium">
                                                {batch.reconciled_by_name ??
                                                    '—'}
                                            </dd>
                                        </div>
                                    </>
                                ) : null}
                                {batch.bank_transaction ? (
                                    <div className="col-span-2">
                                        <dt className="text-muted-foreground">
                                            Matched bank transaction
                                        </dt>
                                        <dd className="font-medium">
                                            {formatMoney(
                                                batch.bank_transaction.amount,
                                            )}{' '}
                                            on{' '}
                                            {formatDate(
                                                batch.bank_transaction
                                                    .transaction_date,
                                            )}
                                            {batch.bank_transaction
                                                .description
                                                ? ` · ${batch.bank_transaction.description}`
                                                : ''}
                                        </dd>
                                    </div>
                                ) : null}
                                {batch.discrepancy_notes ? (
                                    <div className="col-span-2">
                                        <dt className="text-muted-foreground">
                                            Discrepancy notes
                                        </dt>
                                        <dd className="font-medium">
                                            {batch.discrepancy_notes}
                                        </dd>
                                    </div>
                                ) : null}
                            </dl>
                        </CardContent>
                    </Card>

                    <ListCaption
                        title="Transactions"
                        caption={`${transactions.length} in this batch`}
                    />

                    {transactions.length === 0 ? (
                        <EmptyList
                            icon={CreditCard}
                            itemName="transaction"
                            title="No transactions in this batch"
                            description="The batch was imported without any card transactions."
                        />
                    ) : (
                        <EntityTable
                            rows={transactions}
                            rowKey={(txn) => txn.id}
                            identityLabel="Reference"
                            identity={(txn) => ({
                                icon: CreditCard,
                                name: txn.transaction_reference,
                                subline: formatDateTime(txn.transaction_date),
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            minWidth={1080}
                        />
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
