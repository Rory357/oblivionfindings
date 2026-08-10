import {
    DonorFundTransactionDialog,
    FinanceTabs,
    formatMoney,
    type DonorFundGlSummary,
    type DonorFundTxnAccount,
    type DonorFundTxnBankAccount,
} from '@/components/finance';
import { chartColor } from '@/components/finance/chart-palette';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { PageProps, type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import {
    ArrowDownCircle,
    ArrowLeftRight,
    ArrowUpCircle,
    Download,
    FileBarChart,
} from 'lucide-react';
import { FormEvent, useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

interface FundData {
    id: number;
    fund_code: string;
    fund_name: string;
    donor_name: string | null;
    donor_contact: string | null;
    fund_type: string;
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
    canManage: boolean;
}

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

const fundTypeLabels: Record<string, string> = {
    grant: 'Grant',
    donation: 'Donation',
    bequest: 'Bequest',
    trust: 'Trust',
    government: 'Government',
    sponsorship: 'Sponsorship',
};

const txnTypeConfig: Record<
    string,
    { label: string; className: string; isInflow: boolean }
> = {
    receipt: {
        label: 'Receipt',
        className: 'bg-status-success-bg text-status-success',
        isInflow: true,
    },
    expenditure: {
        label: 'Expenditure',
        className: 'bg-status-critical-bg text-status-critical',
        isInflow: false,
    },
    commitment: {
        label: 'Commitment',
        className: 'bg-status-warning-bg text-status-warning',
        isInflow: false,
    },
    release: {
        label: 'Release',
        className: 'bg-status-info-bg text-status-info',
        isInflow: true,
    },
    transfer: {
        label: 'Transfer',
        className: 'bg-primary/10 text-primary',
        isInflow: false,
    },
    adjustment: {
        label: 'Adjustment',
        className: 'bg-muted text-foreground',
        isInflow: false,
    },
};

const statusBadge: Record<string, { label: string; variant: StatusVariant }> = {
    active: { label: 'Active', variant: 'success' },
    fully_spent: { label: 'Fully Spent', variant: 'warning' },
    expired: { label: 'Expired', variant: 'critical' },
    returned: { label: 'Returned', variant: 'neutral' },
};

export default function DonorFundShow({
    fund,
    transactions,
    reports,
    expenseAccounts,
    bankAccounts,
    canManage = false,
}: Props) {
    const badge = statusBadge[fund.status] ?? statusBadge.active;
    const utilisation = fund.budget_amount
        ? Math.round((fund.total_spent / fund.budget_amount) * 100)
        : null;
    const [txnType, setTxnType] = useState<'receipt' | 'expenditure' | null>(
        null,
    );
    const [tab, setTab] = useState<'transactions' | 'reports'>('transactions');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Donor Funds', href: '/finance/donor-funds' },
        { title: fund.fund_name, href: `/finance/donor-funds/${fund.id}` },
    ];

    const chartData = [
        { name: 'Received', amount: fund.total_received },
        { name: 'Spent', amount: fund.total_spent },
        { name: 'Available', amount: fund.available_balance },
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${fund.fund_name}`} />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        variant="compact"
                        backHref="/finance/donor-funds"
                        title={fund.fund_name}
                        description={
                            <>
                                {fund.fund_code} -{' '}
                                {fundTypeLabels[fund.fund_type] ??
                                    fund.fund_type}
                                {fund.donor_name && ` from ${fund.donor_name}`}
                            </>
                        }
                        actions={
                            <>
                                <StatusBadge
                                    variant={badge.variant}
                                    label={badge.label}
                                />
                                {fund.is_restricted && (
                                    <Badge
                                        variant="outline"
                                        className="border-status-warning/30 text-status-warning"
                                    >
                                        Restricted
                                    </Badge>
                                )}
                            </>
                        }
                    />
                }
            >
                {/* Fund Balance Cards + Bar Chart */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:col-span-2 lg:grid-cols-5">
                        <Card>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">
                                    Total Received
                                </p>
                                <p className="text-xl font-bold">
                                    {formatMoney(fund.total_received)}
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">
                                    Total Spent
                                </p>
                                <p className="text-xl font-bold">
                                    {formatMoney(fund.total_spent)}
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">
                                    Committed
                                </p>
                                <p className="text-xl font-bold">
                                    {formatMoney(fund.total_committed)}
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">
                                    Available Balance
                                </p>
                                <p className="text-xl font-bold text-status-success">
                                    {formatMoney(fund.available_balance)}
                                </p>
                            </CardContent>
                        </Card>
                        {fund.budget_amount && (
                            <Card>
                                <CardContent className="p-4">
                                    <p className="text-sm text-muted-foreground">
                                        Budget Utilisation
                                    </p>
                                    <p
                                        className={`text-xl font-bold ${utilisation && utilisation > 90 ? 'text-destructive' : ''}`}
                                    >
                                        {utilisation}%
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        of {formatMoney(fund.budget_amount)}
                                    </p>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Fund Utilization Bar Chart */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Fund Utilisation
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ResponsiveContainer width="100%" height={180}>
                                <BarChart data={chartData}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis
                                        dataKey="name"
                                        tick={{ fontSize: 12 }}
                                    />
                                    <YAxis tick={{ fontSize: 12 }} />
                                    <Tooltip
                                        formatter={
                                            ((value: number) =>
                                                formatMoney(value)) as any
                                        }
                                    />
                                    <Bar dataKey="amount" radius={[4, 4, 0, 0]}>
                                        {chartData.map((_, index) => (
                                            <Cell
                                                key={`cell-${index}`}
                                                fill={chartColor(index)}
                                            />
                                        ))}
                                    </Bar>
                                </BarChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                </div>

                {/* Fund Info */}
                {(fund.restrictions ||
                    fund.reporting_requirements ||
                    fund.start_date ||
                    fund.end_date) && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Fund Information</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                                {fund.start_date && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Start Date
                                        </dt>
                                        <dd className="font-medium">
                                            {formatDate(fund.start_date)}
                                        </dd>
                                    </div>
                                )}
                                {fund.end_date && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            End Date
                                        </dt>
                                        <dd
                                            className={`font-medium ${new Date(fund.end_date) < new Date() ? 'text-destructive' : ''}`}
                                        >
                                            {formatDate(fund.end_date)}
                                        </dd>
                                    </div>
                                )}
                                {fund.gl_account_name && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            GL Account
                                        </dt>
                                        <dd className="font-medium">
                                            {fund.gl_account_name}
                                        </dd>
                                    </div>
                                )}
                                {fund.funding_stream_name && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Funding Stream
                                        </dt>
                                        <dd className="font-medium">
                                            {fund.funding_stream_name}
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
                                            Reporting Requirements
                                        </dt>
                                        <dd className="mt-1 whitespace-pre-wrap">
                                            {fund.reporting_requirements}
                                        </dd>
                                    </div>
                                )}
                                {fund.next_report_due && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Next Report Due
                                        </dt>
                                        <dd
                                            className={`font-medium ${new Date(fund.next_report_due) <= new Date() ? 'text-destructive' : ''}`}
                                        >
                                            {formatDate(fund.next_report_due)}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>
                )}

                {/* Actions Tabs */}
                <div className="space-y-4">
                    <FinanceTabs
                        value={tab}
                        onChange={(next) =>
                            setTab(next as 'transactions' | 'reports')
                        }
                        ariaLabel="Donor fund views"
                        items={[
                            {
                                id: 'transactions',
                                label: 'Transactions',
                                icon: ArrowLeftRight,
                                tone: 'primary',
                            },
                            {
                                id: 'reports',
                                label: 'Reports',
                                icon: FileBarChart,
                                tone: 'info',
                            },
                        ]}
                    />

                    {/* Transactions Tab */}
                    {tab === 'transactions' && (
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>Transactions</CardTitle>
                                {canManage && (
                                    <div className="flex gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setTxnType('receipt')
                                            }
                                        >
                                            <ArrowDownCircle className="mr-1.5 h-4 w-4 text-status-success" />
                                            Record Receipt
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setTxnType('expenditure')
                                            }
                                        >
                                            <ArrowUpCircle className="mr-1.5 h-4 w-4 text-status-critical" />
                                            Record Expenditure
                                        </Button>
                                    </div>
                                )}
                            </CardHeader>
                            <CardContent className="p-0">
                                {transactions.length === 0 ? (
                                    <p className="p-6 text-muted-foreground">
                                        No transactions recorded yet.
                                    </p>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Date</TableHead>
                                                <TableHead>Type</TableHead>
                                                <TableHead>
                                                    Description
                                                </TableHead>
                                                <TableHead>Reference</TableHead>
                                                <TableHead className="text-right">
                                                    Amount
                                                </TableHead>
                                                <TableHead>Journal</TableHead>
                                                <TableHead>By</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {transactions.map((txn) => {
                                                const typeConf = txnTypeConfig[
                                                    txn.type
                                                ] ?? {
                                                    label: txn.type,
                                                    className:
                                                        'bg-muted text-foreground',
                                                    isInflow: false,
                                                };
                                                return (
                                                    <TableRow key={txn.id}>
                                                        <TableCell>
                                                            {formatDate(
                                                                txn.transaction_date,
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge
                                                                className={
                                                                    typeConf.className
                                                                }
                                                                variant="outline"
                                                            >
                                                                {typeConf.label}
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell className="max-w-[250px] truncate">
                                                            {txn.description}
                                                        </TableCell>
                                                        <TableCell className="text-sm">
                                                            {txn.reference ??
                                                                '-'}
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            <span
                                                                className={`font-medium ${typeConf.isInflow ? 'text-status-success' : 'text-destructive'}`}
                                                            >
                                                                {typeConf.isInflow
                                                                    ? '+'
                                                                    : '-'}
                                                                {formatMoney(
                                                                    txn.amount,
                                                                )}
                                                            </span>
                                                        </TableCell>
                                                        <TableCell className="font-mono text-sm">
                                                            {txn.journal_number ??
                                                                '-'}
                                                        </TableCell>
                                                        <TableCell className="text-sm">
                                                            {txn.created_by ??
                                                                '-'}
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* Reports Tab */}
                    {tab === 'reports' && (
                        <div className="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Generate Report</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <form
                                        onSubmit={handleReport}
                                        className="flex flex-wrap items-end gap-4"
                                    >
                                        <div className="w-40">
                                            <Label htmlFor="report_from">
                                                Period From
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
                                        <div className="w-40">
                                            <Label htmlFor="report_to">
                                                Period To
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
                                                ? 'Generating...'
                                                : 'Generate Report'}
                                        </Button>
                                    </form>
                                </CardContent>
                            </Card>

                            {reports.length > 0 && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Generated Reports</CardTitle>
                                    </CardHeader>
                                    <CardContent className="p-0">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>
                                                        Report
                                                    </TableHead>
                                                    <TableHead>
                                                        Period
                                                    </TableHead>
                                                    <TableHead className="text-right">
                                                        Opening
                                                    </TableHead>
                                                    <TableHead className="text-right">
                                                        Receipts
                                                    </TableHead>
                                                    <TableHead className="text-right">
                                                        Expenditure
                                                    </TableHead>
                                                    <TableHead className="text-right">
                                                        Closing
                                                    </TableHead>
                                                    <TableHead>
                                                        Status
                                                    </TableHead>
                                                    <TableHead className="text-right">
                                                        PDF
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {reports.map((report) => (
                                                    <TableRow key={report.id}>
                                                        <TableCell className="font-medium">
                                                            {report.report_name}
                                                        </TableCell>
                                                        <TableCell className="text-sm">
                                                            {formatDate(
                                                                report.period_from,
                                                            )}{' '}
                                                            -{' '}
                                                            {formatDate(
                                                                report.period_to,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            {formatMoney(
                                                                report.opening_balance,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right text-status-success">
                                                            {formatMoney(
                                                                report.total_receipts,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right text-destructive">
                                                            {formatMoney(
                                                                report.total_expenditure,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right font-medium">
                                                            {formatMoney(
                                                                report.closing_balance,
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            <StatusBadge
                                                                status={
                                                                    report.status
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            {report.download_url ? (
                                                                <Button
                                                                    asChild
                                                                    variant="ghost"
                                                                    size="sm"
                                                                >
                                                                    <a
                                                                        href={
                                                                            report.download_url
                                                                        }
                                                                    >
                                                                        <Download className="mr-1 h-4 w-4" />
                                                                        PDF
                                                                    </a>
                                                                </Button>
                                                            ) : (
                                                                <span className="text-sm text-muted-foreground">
                                                                    -
                                                                </span>
                                                            )}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    )}
                </div>
            </PageLayout>

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
                    }}
                    expenseAccounts={expenseAccounts as DonorFundTxnAccount[]}
                    bankAccounts={bankAccounts as DonorFundTxnBankAccount[]}
                />
            )}
        </AppLayout>
    );
}
