import {
    DonorFundDialog,
    DonorFundTransactionDialog,
    FinanceSectionRail,
    formatMoney,
    type DonorFundFundingStream,
    type DonorFundGlAccount,
    type DonorFundGlSummary,
    type DonorFundTxnAccount,
    type DonorFundTxnBankAccount,
    type DonorFundTxnBill,
    type EditableDonorFund,
} from '@/components/finance';
import {
    EmptyValue,
    EntityContextMenu,
    EntityTable,
    ListCaption,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyList } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { PageProps, type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import {
    ArrowDownCircle,
    ArrowLeftRight,
    ArrowUpCircle,
    Download,
    FileBarChart,
    HandHeart,
    Pencil,
    Undo2,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

import { ReverseTransactionDialog } from './_dialogs';

interface FundData {
    id: number;
    fund_code: string;
    fund_name: string;
    donor_name: string | null;
    donor_contact: string | null;
    fund_type: string;
    gl_account_id: number | null;
    funding_stream_id: number | null;
    total_received: number;
    total_spent: number;
    total_committed: number;
    available_balance: number;
    budget_amount: number | null;
    start_date: string | null;
    end_date: string | null;
    restrictions: string | null;
    reporting_requirements: string | null;
    next_report_due: string | null;
    status: string;
    is_restricted: boolean;
    gl_account_name: string | null;
    gl_account: DonorFundGlSummary;
    release_account: DonorFundGlSummary;
    funding_stream_name: string | null;
    created_by: string | null;
}

interface Transaction {
    id: number;
    transaction_date: string;
    type: string;
    description: string;
    amount: number;
    reference: string | null;
    journal_number: string | null;
    created_by: string | null;
    is_reversal: boolean;
    is_reversed: boolean;
    can_reverse: boolean;
}

interface Report {
    id: number;
    report_name: string;
    period_from: string;
    period_to: string;
    opening_balance: number;
    total_receipts: number;
    total_expenditure: number;
    closing_balance: number;
    status: string;
    download_url: string | null;
}

interface Account {
    id: number;
    code: string;
    name: string;
}

interface BankAccount {
    id: number;
    name: string;
}

interface Props extends PageProps {
    fund: FundData;
    transactions: Transaction[];
    reports: Report[];
    expenseAccounts: Account[];
    bankAccounts: BankAccount[];
    eligibleBills: DonorFundTxnBill[];
    glAccounts: DonorFundGlAccount[];
    fundingStreams: DonorFundFundingStream[];
    canManage: boolean;
}

const shortDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

const FUND_TYPE_LABELS: Record<string, string> = {
    grant: 'Grant',
    donation: 'Donation',
    bequest: 'Bequest',
    trust: 'Trust',
    government: 'Government',
    sponsorship: 'Sponsorship',
};

/** Transaction type → badge severity and cash direction. */
const TXN_TYPES: Record<
    string,
    { label: string; variant: StatusVariant; isInflow: boolean }
> = {
    receipt: { label: 'Receipt', variant: 'success', isInflow: true },
    expenditure: {
        label: 'Expenditure',
        variant: 'critical',
        isInflow: false,
    },
    commitment: { label: 'Commitment', variant: 'warning', isInflow: false },
    release: { label: 'Release', variant: 'info', isInflow: true },
    transfer: { label: 'Transfer', variant: 'info', isInflow: false },
    adjustment: { label: 'Adjustment', variant: 'neutral', isInflow: false },
};

const FUND_STATUS: Record<string, { label: string; variant: StatusVariant }> = {
    active: { label: 'Active', variant: 'success' },
    fully_spent: { label: 'Fully spent', variant: 'warning' },
    expired: { label: 'Expired', variant: 'critical' },
    returned: { label: 'Returned', variant: 'neutral' },
};

type FundTab = 'transactions' | 'reports';

const isFundTab = (value: string | null): value is FundTab =>
    value === 'transactions' || value === 'reports';

export default function DonorFundShow({
    fund,
    transactions,
    reports,
    expenseAccounts,
    bankAccounts,
    eligibleBills,
    glAccounts = [],
    fundingStreams = [],
    canManage = false,
}: Props) {
    const badge = FUND_STATUS[fund.status] ?? FUND_STATUS.active;
    const utilisation = fund.budget_amount
        ? Math.round((fund.total_spent / fund.budget_amount) * 100)
        : null;

    /** W13: the record tabs live in `?tab=` so a refresh or a shared link keeps them. */
    const [tab, setTab] = useState<FundTab>(() => {
        if (typeof window === 'undefined') return 'transactions';
        const requested = new URLSearchParams(window.location.search).get(
            'tab',
        );
        return isFundTab(requested) ? requested : 'transactions';
    });

    const selectTab = (key: string) => {
        if (!isFundTab(key)) return;
        setTab(key);
        if (typeof window !== 'undefined') {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', key);
            window.history.replaceState({}, '', url);
        }
    };

    // The index's "Record receipt/expenditure" row actions land here with
    // ?action=… so the right dialog opens without a second click.
    const [txnType, setTxnType] = useState<'receipt' | 'expenditure' | null>(
        () => {
            if (typeof window === 'undefined') return null;
            const action = new URLSearchParams(window.location.search).get(
                'action',
            );
            return action === 'receipt' || action === 'expenditure'
                ? action
                : null;
        },
    );
    const [editOpen, setEditOpen] = useState(false);
    const [reverseTarget, setReverseTarget] = useState<Transaction | null>(
        null,
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Tax & compliance', href: '/finance/tax' },
        { title: 'Donor funds', href: '/finance/donor-funds' },
        { title: fund.fund_name, href: `/finance/donor-funds/${fund.id}` },
    ];

    const reportForm = useForm({
        period_from: '',
        period_to: '',
    });

    const handleReport = (e: FormEvent) => {
        e.preventDefault();
        reportForm.post(`/finance/donor-funds/${fund.id}/report`, {
            onSuccess: () => reportForm.reset(),
        });
    };

    const editableFund: EditableDonorFund = {
        id: fund.id,
        fund_code: fund.fund_code,
        fund_name: fund.fund_name,
        donor_name: fund.donor_name,
        donor_contact: fund.donor_contact,
        fund_type: fund.fund_type,
        gl_account_id: fund.gl_account_id,
        funding_stream_id: fund.funding_stream_id,
        budget_amount: fund.budget_amount,
        start_date: fund.start_date,
        end_date: fund.end_date,
        restrictions: fund.restrictions,
        reporting_requirements: fund.reporting_requirements,
        next_report_due: fund.next_report_due,
        is_restricted: fund.is_restricted,
        status: fund.status,
    };

    /* ---------------- Transactions ---------------- */

    const txnCtx = useEntityContextMenu<Transaction>();

    const txnActions = (txn: Transaction): MenuItem[] =>
        txn.can_reverse
            ? [
                  {
                      label: 'Reverse transaction',
                      icon: Undo2,
                      danger: true,
                      onClick: () => setReverseTarget(txn),
                  },
              ]
            : [];

    const txnColumns: EntityTableColumn<Transaction>[] = [
        {
            key: 'date',
            label: 'Date',
            width: '140px',
            cell: (txn) => (
                <span className="whitespace-nowrap text-muted-foreground">
                    {shortDate(txn.transaction_date)}
                </span>
            ),
        },
        {
            key: 'type',
            label: 'Type',
            width: '160px',
            cell: (txn) => {
                const conf = TXN_TYPES[txn.type] ?? {
                    label: txn.type,
                    variant: 'neutral' as StatusVariant,
                    isInflow: false,
                };
                return (
                    <span className="flex flex-wrap items-center gap-1">
                        <StatusBadge
                            variant={conf.variant}
                            label={conf.label}
                        />
                        {txn.is_reversal ? (
                            <StatusBadge
                                variant="neutral"
                                size="sm"
                                label="Reversal"
                            />
                        ) : null}
                        {txn.is_reversed ? (
                            <StatusBadge
                                variant="warning"
                                size="sm"
                                label="Reversed"
                            />
                        ) : null}
                    </span>
                );
            },
        },
        {
            key: 'reference',
            label: 'Reference',
            width: '170px',
            cell: (txn) =>
                txn.reference ? (
                    <span className="truncate">{txn.reference}</span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '170px',
            align: 'right',
            cell: (txn) => {
                const conf = TXN_TYPES[txn.type];
                const inflow = conf?.isInflow ?? false;
                return (
                    <span className="font-semibold tabular-nums">
                        {inflow ? '+' : '−'}
                        {formatMoney(txn.amount)}
                    </span>
                );
            },
        },
        {
            key: 'journal',
            label: 'Journal',
            width: '160px',
            cell: (txn) =>
                txn.journal_number ? (
                    <span className="truncate">{txn.journal_number}</span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'created_by',
            label: 'Recorded by',
            width: '170px',
            cell: (txn) =>
                txn.created_by ? (
                    <span className="truncate">{txn.created_by}</span>
                ) : (
                    <EmptyValue />
                ),
        },
    ];

    /* ---------------- Reports ---------------- */

    const reportActions = (report: Report): MenuItem[] =>
        report.download_url
            ? [
                  {
                      label: 'Download PDF',
                      icon: Download,
                      onClick: () =>
                          window.location.assign(report.download_url as string),
                  },
              ]
            : [];

    const reportColumns: EntityTableColumn<Report>[] = [
        {
            key: 'period',
            label: 'Period',
            width: '220px',
            cell: (report) => (
                <span className="whitespace-nowrap text-muted-foreground">
                    {shortDate(report.period_from)} –{' '}
                    {shortDate(report.period_to)}
                </span>
            ),
        },
        {
            key: 'opening',
            label: 'Opening',
            width: '150px',
            align: 'right',
            cell: (report) => (
                <span className="tabular-nums">
                    {formatMoney(report.opening_balance)}
                </span>
            ),
        },
        {
            key: 'receipts',
            label: 'Receipts',
            width: '150px',
            align: 'right',
            cell: (report) => (
                <span className="tabular-nums">
                    {formatMoney(report.total_receipts)}
                </span>
            ),
        },
        {
            key: 'expenditure',
            label: 'Expenditure',
            width: '160px',
            align: 'right',
            cell: (report) => (
                <span className="tabular-nums">
                    {formatMoney(report.total_expenditure)}
                </span>
            ),
        },
        {
            key: 'closing',
            label: 'Closing',
            width: '150px',
            align: 'right',
            cell: (report) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(report.closing_balance)}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '130px',
            cell: (report) => <StatusBadge status={report.status} />,
        },
    ];

    /* ---------------- Event Horizon header ---------------- */

    const header = (
        <PageHeader
            variant="profile"
            backHref="/finance/donor-funds"
            icon={HandHeart}
            title={fund.fund_name}
            titleChip={
                <PageHeaderStatusChip variant={badge.variant}>
                    {badge.label}
                </PageHeaderStatusChip>
            }
            subline={[
                fund.fund_code,
                FUND_TYPE_LABELS[fund.fund_type] ?? fund.fund_type,
                fund.donor_name ? `from ${fund.donor_name}` : null,
                fund.is_restricted ? 'Restricted fund' : 'Unrestricted fund',
                fund.gl_account_name,
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                canManage ? (
                    <>
                        <PageHeaderGlassButton
                            icon={Pencil}
                            onClick={() => setEditOpen(true)}
                        >
                            Edit fund
                        </PageHeaderGlassButton>
                        <PageHeaderGlassButton
                            icon={ArrowUpCircle}
                            onClick={() => setTxnType('expenditure')}
                        >
                            Record expenditure
                        </PageHeaderGlassButton>
                        <PageHeaderPrimaryButton
                            icon={ArrowDownCircle}
                            onClick={() => setTxnType('receipt')}
                        >
                            Record receipt
                        </PageHeaderPrimaryButton>
                    </>
                ) : undefined
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Received"
                        tone="success"
                        onClick={() => selectTab('transactions')}
                        ariaLabel="View this fund's transactions"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(fund.total_received)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            donated into this fund
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Spent"
                        onClick={() => selectTab('transactions')}
                        ariaLabel="View this fund's transactions"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(fund.total_spent)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            applied to approved bills
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Committed"
                        tone={fund.total_committed > 0 ? 'warning' : 'brand'}
                        onClick={() => selectTab('transactions')}
                        ariaLabel="View this fund's transactions"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(fund.total_committed)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            promised, not yet spent
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Available"
                        tone="success"
                        onClick={() => selectTab('transactions')}
                        ariaLabel="View this fund's transactions"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(fund.available_balance)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            still to be spent
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    {fund.budget_amount ? (
                        <PageHeaderMeterBlock
                            label="Budget used"
                            tone={
                                utilisation !== null && utilisation > 90
                                    ? 'critical'
                                    : 'brand'
                            }
                            onClick={() => selectTab('reports')}
                            ariaLabel="View this fund's reports"
                        >
                            <PageHeaderMeterBar percent={utilisation ?? 0} />
                            <PageHeaderMeterCaption>
                                {utilisation}% of{' '}
                                {formatMoney(fund.budget_amount)}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={fund.fund_name} />

            <PageLayout
                hero={header}
                tabs={
                    <TierTwoTabs
                        tabs={[
                            {
                                key: 'transactions',
                                label: 'Transactions',
                                icon: ArrowLeftRight,
                                count: transactions.length,
                            },
                            {
                                key: 'reports',
                                label: 'Reports',
                                icon: FileBarChart,
                                count: reports.length,
                            },
                        ]}
                        activeTab={tab}
                        onTab={selectTab}
                        testIdPrefix="donor-fund"
                        ariaLabel="Donor fund sections"
                        renderLink={() => null}
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    {(fund.restrictions ||
                        fund.reporting_requirements ||
                        fund.start_date ||
                        fund.end_date ||
                        fund.funding_stream_name ||
                        fund.next_report_due) && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-section-title">
                                    Fund information
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                                    {fund.start_date && (
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Start date
                                            </dt>
                                            <dd className="font-medium">
                                                {shortDate(fund.start_date)}
                                            </dd>
                                        </div>
                                    )}
                                    {fund.end_date && (
                                        <div>
                                            <dt className="text-muted-foreground">
                                                End date
                                            </dt>
                                            <dd className="flex items-center gap-2 font-medium">
                                                {shortDate(fund.end_date)}
                                                {new Date(fund.end_date) <
                                                new Date() ? (
                                                    <StatusBadge
                                                        variant="critical"
                                                        size="sm"
                                                        label="Past"
                                                    />
                                                ) : null}
                                            </dd>
                                        </div>
                                    )}
                                    {fund.funding_stream_name && (
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Funding stream
                                            </dt>
                                            <dd className="font-medium">
                                                {fund.funding_stream_name}
                                            </dd>
                                        </div>
                                    )}
                                    {fund.next_report_due && (
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Next report due
                                            </dt>
                                            <dd className="flex items-center gap-2 font-medium">
                                                {shortDate(
                                                    fund.next_report_due,
                                                )}
                                                {new Date(
                                                    fund.next_report_due,
                                                ) <= new Date() ? (
                                                    <StatusBadge
                                                        variant="warning"
                                                        size="sm"
                                                        label="Due"
                                                    />
                                                ) : null}
                                            </dd>
                                        </div>
                                    )}
                                    {fund.restrictions && (
                                        <div className="sm:col-span-2">
                                            <dt className="text-muted-foreground">
                                                Restrictions
                                            </dt>
                                            <dd className="mt-1 whitespace-pre-wrap">
                                                {fund.restrictions}
                                            </dd>
                                        </div>
                                    )}
                                    {fund.reporting_requirements && (
                                        <div className="sm:col-span-2">
                                            <dt className="text-muted-foreground">
                                                Reporting requirements
                                            </dt>
                                            <dd className="mt-1 whitespace-pre-wrap">
                                                {fund.reporting_requirements}
                                            </dd>
                                        </div>
                                    )}
                                </dl>
                            </CardContent>
                        </Card>
                    )}

                    {tab === 'transactions' && (
                        <>
                            <ListCaption
                                title="Transactions"
                                caption={`${transactions.length} most recent · entries are immutable, correct one with a reversal`}
                            />
                            {transactions.length === 0 ? (
                                <EmptyList
                                    icon={ArrowLeftRight}
                                    itemName="transaction"
                                    title="No transactions yet"
                                    description="Record a receipt to bring money into this fund."
                                    action={
                                        canManage ? (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setTxnType('receipt')
                                                }
                                            >
                                                Record receipt
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            ) : (
                                <EntityTable
                                    rows={transactions}
                                    rowKey={(txn) => txn.id}
                                    identityLabel="Transaction"
                                    minWidth={1240}
                                    identity={(txn) => ({
                                        icon: ArrowLeftRight,
                                        name: txn.description,
                                        subline: txn.created_by ?? undefined,
                                    })}
                                    columns={txnColumns}
                                    actionsFor={txnActions}
                                    mutedFor={(txn) =>
                                        txn.is_reversed || txn.is_reversal
                                    }
                                    onRowContextMenu={(e, txn) =>
                                        txnCtx.open(e, txn)
                                    }
                                />
                            )}
                        </>
                    )}

                    {tab === 'reports' && (
                        <>
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-section-title">
                                        Generate a report
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <form
                                        onSubmit={handleReport}
                                        className="flex flex-wrap items-end gap-4"
                                    >
                                        <div className="w-40 space-y-2">
                                            <Label htmlFor="report_from">
                                                Period from
                                            </Label>
                                            <Input
                                                id="report_from"
                                                type="date"
                                                value={
                                                    reportForm.data.period_from
                                                }
                                                onChange={(e) =>
                                                    reportForm.setData(
                                                        'period_from',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="w-40 space-y-2">
                                            <Label htmlFor="report_to">
                                                Period to
                                            </Label>
                                            <Input
                                                id="report_to"
                                                type="date"
                                                value={
                                                    reportForm.data.period_to
                                                }
                                                onChange={(e) =>
                                                    reportForm.setData(
                                                        'period_to',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={reportForm.processing}
                                        >
                                            {reportForm.processing
                                                ? 'Generating…'
                                                : 'Generate report'}
                                        </Button>
                                    </form>
                                </CardContent>
                            </Card>

                            <ListCaption
                                title="Generated reports"
                                caption={`${reports.length} report${
                                    reports.length === 1 ? '' : 's'
                                } for this fund`}
                            />
                            {reports.length === 0 ? (
                                <EmptyList
                                    icon={FileBarChart}
                                    itemName="report"
                                    title="No reports yet"
                                    description="Generate a period report to send the donor a PDF statement."
                                />
                            ) : (
                                <EntityTable
                                    rows={reports}
                                    rowKey={(report) => report.id}
                                    identityLabel="Report"
                                    minWidth={1240}
                                    identity={(report) => ({
                                        icon: FileBarChart,
                                        name: report.report_name,
                                    })}
                                    columns={reportColumns}
                                    actionsFor={reportActions}
                                />
                            )}
                        </>
                    )}
                </div>
            </PageLayout>

            {txnCtx.ctx ? (
                <EntityContextMenu
                    x={txnCtx.ctx.x}
                    y={txnCtx.ctx.y}
                    icon={ArrowLeftRight}
                    title={txnCtx.ctx.record.description}
                    items={txnActions(txnCtx.ctx.record)}
                    onClose={txnCtx.close}
                />
            ) : null}

            {canManage && editOpen ? (
                <DonorFundDialog
                    open
                    onClose={() => setEditOpen(false)}
                    glAccounts={glAccounts}
                    fundingStreams={fundingStreams}
                    fund={editableFund}
                />
            ) : null}

            {canManage && txnType && (
                <DonorFundTransactionDialog
                    key={txnType}
                    open
                    initialType={txnType}
                    onClose={() => setTxnType(null)}
                    fund={{
                        id: fund.id,
                        fund_name: fund.fund_name,
                        fund_code: fund.fund_code,
                        is_restricted: fund.is_restricted,
                        available_balance: fund.available_balance,
                        gl_account: fund.gl_account,
                        release_account: fund.release_account,
                    }}
                    expenseAccounts={expenseAccounts as DonorFundTxnAccount[]}
                    bankAccounts={bankAccounts as DonorFundTxnBankAccount[]}
                    eligibleBills={eligibleBills}
                />
            )}

            {canManage && reverseTarget ? (
                <ReverseTransactionDialog
                    key={reverseTarget.id}
                    open
                    onClose={() => setReverseTarget(null)}
                    fundId={fund.id}
                    transaction={reverseTarget}
                />
            ) : null}
        </AppLayout>
    );
}
