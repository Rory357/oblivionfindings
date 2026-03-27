import { Head, useForm, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ArrowLeft, TrendingUp, TrendingDown } from 'lucide-react';
import { FormEvent } from 'react';

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
}

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

const fundTypeLabels: Record<string, string> = {
    grant: 'Grant',
    donation: 'Donation',
    bequest: 'Bequest',
    trust: 'Trust',
    government: 'Government',
    sponsorship: 'Sponsorship',
};

const txnTypeConfig: Record<string, { label: string; className: string; isInflow: boolean }> = {
    receipt: { label: 'Receipt', className: 'bg-green-100 text-green-800', isInflow: true },
    expenditure: { label: 'Expenditure', className: 'bg-red-100 text-red-800', isInflow: false },
    commitment: { label: 'Commitment', className: 'bg-amber-100 text-amber-800', isInflow: false },
    release: { label: 'Release', className: 'bg-blue-100 text-blue-800', isInflow: true },
    transfer: { label: 'Transfer', className: 'bg-purple-100 text-purple-800', isInflow: false },
    adjustment: { label: 'Adjustment', className: 'bg-gray-100 text-gray-800', isInflow: false },
};

const statusConfig: Record<string, { label: string; className: string }> = {
    active: { label: 'Active', className: 'border-green-300 text-green-600' },
    fully_spent: { label: 'Fully Spent', className: 'border-amber-300 text-amber-600' },
    expired: { label: 'Expired', className: 'border-red-300 text-red-600' },
    returned: { label: 'Returned', className: 'border-gray-300 text-gray-600' },
};

export default function DonorFundShow({ fund, transactions, reports, expenseAccounts, bankAccounts }: Props) {
    const config = statusConfig[fund.status] ?? statusConfig.active;
    const utilisation = fund.budget_amount ? Math.round((fund.total_spent / fund.budget_amount) * 100) : null;

    const receiptForm = useForm({
        transaction_date: new Date().toISOString().split('T')[0],
        description: '',
        amount: '',
        reference: '',
        bank_account_id: '',
    });

    const expenditureForm = useForm({
        transaction_date: new Date().toISOString().split('T')[0],
        description: '',
        amount: '',
        reference: '',
        expense_account_id: '',
    });

    const reportForm = useForm({
        period_from: '',
        period_to: '',
    });

    const handleReceipt = (e: FormEvent) => {
        e.preventDefault();
        receiptForm.post(`/finance/donor-funds/${fund.id}/receipt`, {
            onSuccess: () => receiptForm.reset(),
        });
    };

    const handleExpenditure = (e: FormEvent) => {
        e.preventDefault();
        expenditureForm.post(`/finance/donor-funds/${fund.id}/expenditure`, {
            onSuccess: () => expenditureForm.reset(),
        });
    };

    const handleReport = (e: FormEvent) => {
        e.preventDefault();
        reportForm.post(`/finance/donor-funds/${fund.id}/report`, {
            onSuccess: () => reportForm.reset(),
        });
    };

    return (
        <AppLayout>
            <Head title={`${fund.fund_name}`} />

            <div className="space-y-6">
                <div className="flex items-center gap-4">
                    <Button asChild variant="ghost" size="sm">
                        <Link href="/finance/donor-funds">
                            <ArrowLeft className="mr-1 h-4 w-4" />
                            Back
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-2xl font-bold">{fund.fund_name}</h1>
                        <p className="text-sm text-muted-foreground">
                            {fund.fund_code} - {fundTypeLabels[fund.fund_type] ?? fund.fund_type}
                            {fund.donor_name && ` from ${fund.donor_name}`}
                        </p>
                    </div>
                    <Badge variant="outline" className={config.className}>
                        {config.label}
                    </Badge>
                    {fund.is_restricted && (
                        <Badge variant="outline" className="border-amber-300 text-amber-600">
                            Restricted
                        </Badge>
                    )}
                </div>

                {/* Fund Balance Cards */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Total Received</p>
                            <p className="text-xl font-bold">{formatCurrency(fund.total_received)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Total Spent</p>
                            <p className="text-xl font-bold">{formatCurrency(fund.total_spent)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Committed</p>
                            <p className="text-xl font-bold">{formatCurrency(fund.total_committed)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Available Balance</p>
                            <p className="text-xl font-bold text-green-600">{formatCurrency(fund.available_balance)}</p>
                        </CardContent>
                    </Card>
                    {fund.budget_amount && (
                        <Card>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">Budget Utilisation</p>
                                <p className={`text-xl font-bold ${utilisation && utilisation > 90 ? 'text-red-600' : ''}`}>
                                    {utilisation}%
                                </p>
                                <p className="text-xs text-muted-foreground">of {formatCurrency(fund.budget_amount)}</p>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Fund Info */}
                {(fund.restrictions || fund.reporting_requirements || fund.start_date || fund.end_date) && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Fund Information</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                                {fund.start_date && (
                                    <div>
                                        <dt className="text-muted-foreground">Start Date</dt>
                                        <dd className="font-medium">{formatDate(fund.start_date)}</dd>
                                    </div>
                                )}
                                {fund.end_date && (
                                    <div>
                                        <dt className="text-muted-foreground">End Date</dt>
                                        <dd className={`font-medium ${new Date(fund.end_date) < new Date() ? 'text-red-600' : ''}`}>
                                            {formatDate(fund.end_date)}
                                        </dd>
                                    </div>
                                )}
                                {fund.gl_account_name && (
                                    <div>
                                        <dt className="text-muted-foreground">GL Account</dt>
                                        <dd className="font-medium">{fund.gl_account_name}</dd>
                                    </div>
                                )}
                                {fund.funding_stream_name && (
                                    <div>
                                        <dt className="text-muted-foreground">Funding Stream</dt>
                                        <dd className="font-medium">{fund.funding_stream_name}</dd>
                                    </div>
                                )}
                                {fund.restrictions && (
                                    <div className="sm:col-span-2">
                                        <dt className="text-muted-foreground">Restrictions</dt>
                                        <dd className="mt-1 whitespace-pre-wrap">{fund.restrictions}</dd>
                                    </div>
                                )}
                                {fund.reporting_requirements && (
                                    <div className="sm:col-span-2">
                                        <dt className="text-muted-foreground">Reporting Requirements</dt>
                                        <dd className="mt-1 whitespace-pre-wrap">{fund.reporting_requirements}</dd>
                                    </div>
                                )}
                                {fund.next_report_due && (
                                    <div>
                                        <dt className="text-muted-foreground">Next Report Due</dt>
                                        <dd className={`font-medium ${new Date(fund.next_report_due) <= new Date() ? 'text-red-600' : ''}`}>
                                            {formatDate(fund.next_report_due)}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>
                )}

                {/* Actions Tabs */}
                <Tabs defaultValue="transactions">
                    <TabsList>
                        <TabsTrigger value="transactions">Transactions</TabsTrigger>
                        <TabsTrigger value="receipt">Record Receipt</TabsTrigger>
                        <TabsTrigger value="expenditure">Record Expenditure</TabsTrigger>
                        <TabsTrigger value="reports">Reports</TabsTrigger>
                    </TabsList>

                    {/* Transactions Tab */}
                    <TabsContent value="transactions">
                        <Card>
                            <CardHeader>
                                <CardTitle>Transactions</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                {transactions.length === 0 ? (
                                    <p className="p-6 text-muted-foreground">No transactions recorded yet.</p>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Date</TableHead>
                                                <TableHead>Type</TableHead>
                                                <TableHead>Description</TableHead>
                                                <TableHead>Reference</TableHead>
                                                <TableHead className="text-right">Amount</TableHead>
                                                <TableHead>Journal</TableHead>
                                                <TableHead>By</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {transactions.map((txn) => {
                                                const typeConf = txnTypeConfig[txn.type] ?? {
                                                    label: txn.type,
                                                    className: 'bg-gray-100 text-gray-800',
                                                    isInflow: false,
                                                };
                                                return (
                                                    <TableRow key={txn.id}>
                                                        <TableCell>{formatDate(txn.transaction_date)}</TableCell>
                                                        <TableCell>
                                                            <Badge className={typeConf.className} variant="outline">
                                                                {typeConf.label}
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell className="max-w-[250px] truncate">{txn.description}</TableCell>
                                                        <TableCell className="text-sm">{txn.reference ?? '-'}</TableCell>
                                                        <TableCell className="text-right">
                                                            <span className={`font-medium ${typeConf.isInflow ? 'text-green-600' : 'text-red-600'}`}>
                                                                {typeConf.isInflow ? '+' : '-'}
                                                                {formatCurrency(txn.amount)}
                                                            </span>
                                                        </TableCell>
                                                        <TableCell className="font-mono text-sm">{txn.journal_number ?? '-'}</TableCell>
                                                        <TableCell className="text-sm">{txn.created_by ?? '-'}</TableCell>
                                                    </TableRow>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Receipt Tab */}
                    <TabsContent value="receipt">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <TrendingUp className="h-5 w-5 text-green-600" />
                                    Record Receipt
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={handleReceipt} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="receipt_date">Date</Label>
                                        <Input
                                            id="receipt_date"
                                            type="date"
                                            value={receiptForm.data.transaction_date}
                                            onChange={(e) => receiptForm.setData('transaction_date', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="receipt_amount">Amount (NZD)</Label>
                                        <Input
                                            id="receipt_amount"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            value={receiptForm.data.amount}
                                            onChange={(e) => receiptForm.setData('amount', e.target.value)}
                                            placeholder="0.00"
                                        />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <Label htmlFor="receipt_description">Description</Label>
                                        <Input
                                            id="receipt_description"
                                            value={receiptForm.data.description}
                                            onChange={(e) => receiptForm.setData('description', e.target.value)}
                                            placeholder="e.g. Q1 2026 grant instalment"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="receipt_reference">Reference</Label>
                                        <Input
                                            id="receipt_reference"
                                            value={receiptForm.data.reference}
                                            onChange={(e) => receiptForm.setData('reference', e.target.value)}
                                            placeholder="Optional reference"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="receipt_bank">Bank Account</Label>
                                        <Select
                                            value={receiptForm.data.bank_account_id}
                                            onValueChange={(val) => receiptForm.setData('bank_account_id', val)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select bank account" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {bankAccounts.map((acc) => (
                                                    <SelectItem key={acc.id} value={String(acc.id)}>
                                                        {acc.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="sm:col-span-2">
                                        <Button type="submit" disabled={receiptForm.processing}>
                                            {receiptForm.processing ? 'Recording...' : 'Record Receipt'}
                                        </Button>
                                        {(receiptForm.errors as Record<string, string>).receipt && (
                                            <p className="mt-2 text-sm text-red-600">{(receiptForm.errors as Record<string, string>).receipt}</p>
                                        )}
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Expenditure Tab */}
                    <TabsContent value="expenditure">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <TrendingDown className="h-5 w-5 text-red-600" />
                                    Record Expenditure
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={handleExpenditure} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="exp_date">Date</Label>
                                        <Input
                                            id="exp_date"
                                            type="date"
                                            value={expenditureForm.data.transaction_date}
                                            onChange={(e) => expenditureForm.setData('transaction_date', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="exp_amount">Amount (NZD)</Label>
                                        <Input
                                            id="exp_amount"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            value={expenditureForm.data.amount}
                                            onChange={(e) => expenditureForm.setData('amount', e.target.value)}
                                            placeholder="0.00"
                                        />
                                        {fund.is_restricted && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Available: {formatCurrency(fund.available_balance)}
                                            </p>
                                        )}
                                    </div>
                                    <div className="sm:col-span-2">
                                        <Label htmlFor="exp_description">Description</Label>
                                        <Input
                                            id="exp_description"
                                            value={expenditureForm.data.description}
                                            onChange={(e) => expenditureForm.setData('description', e.target.value)}
                                            placeholder="e.g. Staff training programme"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="exp_reference">Reference</Label>
                                        <Input
                                            id="exp_reference"
                                            value={expenditureForm.data.reference}
                                            onChange={(e) => expenditureForm.setData('reference', e.target.value)}
                                            placeholder="Optional reference"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="exp_account">Expense Account</Label>
                                        <Select
                                            value={expenditureForm.data.expense_account_id}
                                            onValueChange={(val) => expenditureForm.setData('expense_account_id', val)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select expense account" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {expenseAccounts.map((acc) => (
                                                    <SelectItem key={acc.id} value={String(acc.id)}>
                                                        {acc.code} - {acc.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="sm:col-span-2">
                                        <Button type="submit" disabled={expenditureForm.processing}>
                                            {expenditureForm.processing ? 'Recording...' : 'Record Expenditure'}
                                        </Button>
                                        {(expenditureForm.errors as Record<string, string>).expenditure && (
                                            <p className="mt-2 text-sm text-red-600">
                                                {(expenditureForm.errors as Record<string, string>).expenditure}
                                            </p>
                                        )}
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Reports Tab */}
                    <TabsContent value="reports">
                        <div className="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Generate Report</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <form onSubmit={handleReport} className="flex flex-wrap items-end gap-4">
                                        <div className="w-40">
                                            <Label htmlFor="report_from">Period From</Label>
                                            <Input
                                                id="report_from"
                                                type="date"
                                                value={reportForm.data.period_from}
                                                onChange={(e) => reportForm.setData('period_from', e.target.value)}
                                            />
                                        </div>
                                        <div className="w-40">
                                            <Label htmlFor="report_to">Period To</Label>
                                            <Input
                                                id="report_to"
                                                type="date"
                                                value={reportForm.data.period_to}
                                                onChange={(e) => reportForm.setData('period_to', e.target.value)}
                                            />
                                        </div>
                                        <Button type="submit" disabled={reportForm.processing}>
                                            {reportForm.processing ? 'Generating...' : 'Generate Report'}
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
                                                    <TableHead>Report</TableHead>
                                                    <TableHead>Period</TableHead>
                                                    <TableHead className="text-right">Opening</TableHead>
                                                    <TableHead className="text-right">Receipts</TableHead>
                                                    <TableHead className="text-right">Expenditure</TableHead>
                                                    <TableHead className="text-right">Closing</TableHead>
                                                    <TableHead>Status</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {reports.map((report) => (
                                                    <TableRow key={report.id}>
                                                        <TableCell className="font-medium">{report.report_name}</TableCell>
                                                        <TableCell className="text-sm">
                                                            {formatDate(report.period_from)} - {formatDate(report.period_to)}
                                                        </TableCell>
                                                        <TableCell className="text-right">{formatCurrency(report.opening_balance)}</TableCell>
                                                        <TableCell className="text-right text-green-600">
                                                            {formatCurrency(report.total_receipts)}
                                                        </TableCell>
                                                        <TableCell className="text-right text-red-600">
                                                            {formatCurrency(report.total_expenditure)}
                                                        </TableCell>
                                                        <TableCell className="text-right font-medium">
                                                            {formatCurrency(report.closing_balance)}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge variant="outline">{report.status}</Badge>
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
