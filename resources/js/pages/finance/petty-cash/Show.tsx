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
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyList } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { PageProps, type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Coins, Plus, Receipt, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';

import { PettyCashTransactionDialog, pettyCashType } from './_dialogs';

interface FundDetails {
    id: number;
    name: string;
    float_amount: number;
    current_balance: number;
    custodian_name: string | null;
    gl_account_name: string | null;
    is_active: boolean;
    variance: number;
}

interface Transaction {
    id: number;
    transaction_date: string;
    type: string;
    description: string | null;
    amount: number;
    account_name: string | null;
    has_receipt: boolean;
    receipt_url: string | null;
    created_by: string | null;
    running_balance: number | null;
}

interface Account {
    id: number;
    code: string;
    name: string;
}

interface Props extends PageProps {
    summary: {
        fund: FundDetails;
        transactions: Transaction[];
    };
    expenseAccounts: Account[];
    canManage: boolean;
}

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

export default function PettyCashShow({
    summary,
    expenseAccounts,
    canManage = false,
}: Props) {
    const { fund, transactions } = summary;
    const [recordOpen, setRecordOpen] = useState(false);

    // The fund cards link here with ?record=1 to open the dialog straight away.
    useEffect(() => {
        if (
            canManage &&
            new URLSearchParams(window.location.search).get('record')
        ) {
            setRecordOpen(true);
        }
    }, [canManage]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Banking', href: '/finance/banking' },
        { title: 'Petty cash', href: '/finance/petty-cash' },
        { title: fund.name, href: `/finance/petty-cash/${fund.id}` },
    ];

    const receiptCount = transactions.filter(
        (transaction) => transaction.has_receipt,
    ).length;

    const actionsFor = (transaction: Transaction): MenuItem[] =>
        compactMenu([
            transaction.receipt_url
                ? {
                      label: 'Open receipt',
                      icon: Receipt,
                      onClick: () =>
                          window.open(transaction.receipt_url!, '_blank'),
                  }
                : null,
        ]);

    const columns: EntityTableColumn<Transaction>[] = [
        {
            key: 'type',
            label: 'Type',
            width: '0.7fr',
            cell: (transaction) => {
                const type = pettyCashType(transaction.type);
                return (
                    <StatusBadge variant={type.variant} label={type.label} />
                );
            },
        },
        {
            key: 'account',
            label: 'Account',
            width: '1.2fr',
            cell: (transaction) =>
                transaction.account_name ?? (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '0.8fr',
            align: 'right',
            cell: (transaction) => (
                <span
                    className={`font-medium tabular-nums ${
                        transaction.type === 'expense'
                            ? 'text-status-critical'
                            : 'text-status-success'
                    }`}
                >
                    {transaction.type === 'expense' ? '−' : '+'}
                    {formatMoney(transaction.amount)}
                </span>
            ),
        },
        {
            key: 'balance',
            label: 'Balance',
            width: '0.8fr',
            align: 'right',
            cell: (transaction) =>
                transaction.running_balance !== null ? (
                    <span className="tabular-nums">
                        {formatMoney(transaction.running_balance)}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'receipt',
            label: 'Receipt',
            width: '0.7fr',
            cell: (transaction) =>
                transaction.receipt_url ? (
                    <a
                        href={transaction.receipt_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1 text-primary underline-offset-4 hover:underline"
                    >
                        <Receipt className="size-3.5" />
                        View
                    </a>
                ) : (
                    <span className="text-muted-foreground">None</span>
                ),
        },
        {
            key: 'by',
            label: 'Recorded by',
            width: '0.9fr',
            cell: (transaction) =>
                transaction.created_by ?? (
                    <span className="text-muted-foreground">—</span>
                ),
        },
    ];

    const header = (
        <PageHeader
            variant="profile"
            icon={Wallet}
            backHref="/finance/petty-cash"
            title={fund.name}
            titleChip={
                <PageHeaderStatusChip
                    variant={fund.is_active ? 'success' : 'neutral'}
                >
                    {fund.is_active ? 'Active' : 'Closed'}
                </PageHeaderStatusChip>
            }
            subline={[
                fund.custodian_name
                    ? `Custodian ${fund.custodian_name}`
                    : 'No custodian assigned',
                fund.gl_account_name ?? 'No GL account linked',
                `${transactions.length} recent transactions`,
            ].join(' · ')}
            actions={
                canManage ? (
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setRecordOpen(true)}
                    >
                        Record transaction
                    </PageHeaderPrimaryButton>
                ) : undefined
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Current balance"
                        tone={fund.current_balance >= 0 ? 'success' : 'critical'}
                        href="/finance/cash-position"
                        ariaLabel="View the cash position"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(fund.current_balance)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Cash that should be in the tin
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Float"
                        href="/finance/petty-cash"
                        ariaLabel="View every petty cash fund"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(fund.float_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Authorised float for this fund
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Variance"
                        tone={
                            fund.variance < 0
                                ? 'warning'
                                : fund.variance > 0
                                  ? 'brand'
                                  : 'success'
                        }
                        href={`/finance/petty-cash/${fund.id}`}
                        ariaLabel="View this fund's transactions"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(fund.variance)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Balance against the float
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Receipts attached"
                        tone={
                            receiptCount < transactions.length
                                ? 'warning'
                                : 'brand'
                        }
                        href={`/finance/petty-cash/${fund.id}`}
                        ariaLabel="View this fund's transactions"
                    >
                        <PageHeaderMeterBig>
                            {receiptCount}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            of {transactions.length} recent transactions
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Petty cash — ${fund.name}`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Transactions"
                        caption={`${transactions.length} most recent`}
                    />

                    {transactions.length === 0 ? (
                        <EmptyList
                            icon={Coins}
                            itemName="transaction"
                            title="No transactions yet"
                            description="Record the first expense, top-up or adjustment against this float."
                            action={
                                canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setRecordOpen(true)}
                                    >
                                        Record transaction
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={transactions}
                            rowKey={(transaction) => transaction.id}
                            identityLabel="Transaction"
                            identity={(transaction) => ({
                                icon: Coins,
                                name:
                                    transaction.description ||
                                    pettyCashType(transaction.type).label,
                                subline: formatDate(
                                    transaction.transaction_date,
                                ),
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            minWidth={1080}
                        />
                    )}
                </div>
            </PageLayout>

            {canManage && (
                <PettyCashTransactionDialog
                    open={recordOpen}
                    onClose={() => setRecordOpen(false)}
                    fundId={fund.id}
                    expenseAccounts={expenseAccounts}
                />
            )}
        </AppLayout>
    );
}
