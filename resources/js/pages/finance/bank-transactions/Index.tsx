import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import { PageHero, PageLayout } from '@/components/page';
import { BankingTabsFooter } from '@/components/finance';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ArrowDownToLine, ArrowUpFromLine, Banknote, Download, Plus } from 'lucide-react';
import { ChangeEvent, FormEvent, useMemo, useState } from 'react';

type BankAccount = {
    id: number;
    name: string;
};

type Transaction = {
    id: number;
    transaction_date: string | null;
    amount: number;
    description: string;
    reference: string | null;
    payee: string | null;
    source: string | null;
    status: string;
    is_from_feed: boolean;
    bank_account: BankAccount | null;
};

type PaginatedTransactions = {
    data: Transaction[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
};

type Filters = {
    bank_account_id: string;
    status: string;
    start_date: string;
    end_date: string;
};

type Props = {
    transactions: PaginatedTransactions;
    bankAccounts: BankAccount[];
    filters: Filters;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Bank Transactions', href: '/finance/bank-transactions' },
];

const ALL = '__ALL__';

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const formatDate = (value: string | null) =>
    value
        ? new Date(value).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' })
        : '-';

const statusStyles: Record<string, string> = {
    unreconciled: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    reconciled: 'bg-status-success-bg text-status-success border-status-success/30',
    matched: 'bg-status-info-bg text-status-info border-status-info/30',
};

export default function BankTransactionsIndex({ transactions, bankAccounts, filters }: Props) {
    const [showManualDialog, setShowManualDialog] = useState(false);
    const [showImportDialog, setShowImportDialog] = useState(false);

    const manualForm = useForm({
        bank_account_id: filters.bank_account_id || '',
        transaction_date: new Date().toISOString().split('T')[0],
        amount: '',
        description: '',
        reference: '',
        payee: '',
    });

    const importForm = useForm<{
        bank_account_id: string;
        file: File | null;
    }>({
        bank_account_id: filters.bank_account_id || '',
        file: null,
    });

    const summary = useMemo(() => {
        return transactions.data.reduce(
            (totals, transaction) => {
                totals.total += transaction.amount;
                totals.count += 1;
                if (transaction.status === 'unreconciled') {
                    totals.unreconciled += 1;
                }

                return totals;
            },
            { total: 0, count: 0, unreconciled: 0 },
        );
    }, [transactions.data]);

    const applyFilters = (next: Partial<Filters>) => {
        router.get(
            '/finance/bank-transactions',
            { ...filters, ...next, page: 1 },
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleManualSubmit = (event: FormEvent) => {
        event.preventDefault();
        manualForm.post('/finance/bank-transactions', {
            preserveScroll: true,
            onSuccess: () => {
                setShowManualDialog(false);
                manualForm.reset('amount', 'description', 'reference', 'payee');
            },
        });
    };

    const handleImportSubmit = (event: FormEvent) => {
        event.preventDefault();
        importForm.post('/finance/bank-transactions/import', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setShowImportDialog(false);
                importForm.reset('file');
            },
        });
    };

    const handleFileChange = (event: ChangeEvent<HTMLInputElement>) => {
        importForm.setData('file', event.target.files?.[0] ?? null);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank Transactions" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Banknote}
                        title="Bank Transactions"
                        description="Review imported activity, filter reconciliation queues, and record manual transactions."
                        stats={[
                            { label: 'On this page', value: summary.count },
                            { label: 'Unreconciled', value: summary.unreconciled },
                            { label: 'Accounts', value: bankAccounts.length },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Dialog open={showImportDialog} onOpenChange={setShowImportDialog}>
                                    <DialogTrigger asChild>
                                        <Button
                                            variant="outline"
                                            className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                        >
                                            <Download className="mr-2 h-4 w-4" />
                                            Import CSV
                                        </Button>
                                    </DialogTrigger>
                            <DialogContent className="sm:max-w-lg">
                                <form onSubmit={handleImportSubmit}>
                                    <DialogHeader>
                                        <DialogTitle>Import Bank Transactions</DialogTitle>
                                        <DialogDescription>
                                            Upload a CSV or TXT export from your bank to add transactions in bulk.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <div className="space-y-4 py-4">
                                        <div className="space-y-2">
                                            <Label>Bank Account</Label>
                                            <Select
                                                value={importForm.data.bank_account_id || ALL}
                                                onValueChange={(value) =>
                                                    importForm.setData('bank_account_id', value === ALL ? '' : value)
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select account" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={ALL}>Select account</SelectItem>
                                                    {bankAccounts.map((account) => (
                                                        <SelectItem key={account.id} value={String(account.id)}>
                                                            {account.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            {importForm.errors.bank_account_id && (
                                                <p className="text-sm text-destructive">{importForm.errors.bank_account_id}</p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="import-file">Transaction File</Label>
                                            <Input
                                                id="import-file"
                                                type="file"
                                                accept=".csv,.txt"
                                                onChange={handleFileChange}
                                            />
                                            {importForm.errors.file && (
                                                <p className="text-sm text-destructive">{importForm.errors.file}</p>
                                            )}
                                        </div>
                                    </div>
                                    <DialogFooter>
                                        <Button type="button" variant="outline" onClick={() => setShowImportDialog(false)}>
                                            Cancel
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={!importForm.data.bank_account_id || !importForm.data.file || importForm.processing}
                                        >
                                            {importForm.processing ? 'Importing...' : 'Import Transactions'}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>

                        <Dialog open={showManualDialog} onOpenChange={setShowManualDialog}>
                            <DialogTrigger asChild>
                                <Button>
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add Transaction
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="sm:max-w-2xl">
                                <form onSubmit={handleManualSubmit}>
                                    <DialogHeader>
                                        <DialogTitle>Add Bank Transaction</DialogTitle>
                                        <DialogDescription>
                                            Record a manual transaction when it did not arrive through a bank feed.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <div className="grid gap-4 py-4 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Bank Account</Label>
                                            <Select
                                                value={manualForm.data.bank_account_id || ALL}
                                                onValueChange={(value) =>
                                                    manualForm.setData('bank_account_id', value === ALL ? '' : value)
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select account" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={ALL}>Select account</SelectItem>
                                                    {bankAccounts.map((account) => (
                                                        <SelectItem key={account.id} value={String(account.id)}>
                                                            {account.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            {manualForm.errors.bank_account_id && (
                                                <p className="text-sm text-destructive">{manualForm.errors.bank_account_id}</p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="transaction_date">Date</Label>
                                            <Input
                                                id="transaction_date"
                                                type="date"
                                                value={manualForm.data.transaction_date}
                                                onChange={(event) => manualForm.setData('transaction_date', event.target.value)}
                                            />
                                            {manualForm.errors.transaction_date && (
                                                <p className="text-sm text-destructive">{manualForm.errors.transaction_date}</p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="amount">Amount</Label>
                                            <Input
                                                id="amount"
                                                type="number"
                                                step="0.01"
                                                value={manualForm.data.amount}
                                                onChange={(event) => manualForm.setData('amount', event.target.value)}
                                                placeholder="0.00"
                                            />
                                            {manualForm.errors.amount && (
                                                <p className="text-sm text-destructive">{manualForm.errors.amount}</p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="payee">Payee</Label>
                                            <Input
                                                id="payee"
                                                value={manualForm.data.payee}
                                                onChange={(event) => manualForm.setData('payee', event.target.value)}
                                                placeholder="Optional payee"
                                            />
                                        </div>

                                        <div className="space-y-2 sm:col-span-2">
                                            <Label htmlFor="description">Description</Label>
                                            <Input
                                                id="description"
                                                value={manualForm.data.description}
                                                onChange={(event) => manualForm.setData('description', event.target.value)}
                                                placeholder="Describe the transaction"
                                            />
                                            {manualForm.errors.description && (
                                                <p className="text-sm text-destructive">{manualForm.errors.description}</p>
                                            )}
                                        </div>

                                        <div className="space-y-2 sm:col-span-2">
                                            <Label htmlFor="reference">Reference</Label>
                                            <Input
                                                id="reference"
                                                value={manualForm.data.reference}
                                                onChange={(event) => manualForm.setData('reference', event.target.value)}
                                                placeholder="Optional statement or transfer reference"
                                            />
                                        </div>
                                    </div>
                                    <DialogFooter>
                                        <Button type="button" variant="outline" onClick={() => setShowManualDialog(false)}>
                                            Cancel
                                        </Button>
                                        <Button type="submit" disabled={manualForm.processing}>
                                            {manualForm.processing ? 'Saving...' : 'Save Transaction'}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                            </div>
                        }
                        footer={<BankingTabsFooter active="transactions" />}
                    />
                }
            >
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">Transactions on this page</p>
                            <p className="text-2xl font-semibold">{summary.count}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">Unreconciled</p>
                            <p className="text-2xl font-semibold">{summary.unreconciled}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">Total value on this page</p>
                            <p className="text-2xl font-semibold font-mono tabular-nums">{formatCurrency(summary.total)}</p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Filters</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 md:grid-cols-4">
                            <div className="space-y-2">
                                <Label>Bank Account</Label>
                                <Select
                                    value={filters.bank_account_id || ALL}
                                    onValueChange={(value) =>
                                        applyFilters({ bank_account_id: value === ALL ? '' : value })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All accounts" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>All accounts</SelectItem>
                                        {bankAccounts.map((account) => (
                                            <SelectItem key={account.id} value={String(account.id)}>
                                                {account.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-2">
                                <Label>Status</Label>
                                <Select
                                    value={filters.status || ALL}
                                    onValueChange={(value) => applyFilters({ status: value === ALL ? '' : value })}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>All statuses</SelectItem>
                                        <SelectItem value="unreconciled">Unreconciled</SelectItem>
                                        <SelectItem value="reconciled">Reconciled</SelectItem>
                                        <SelectItem value="matched">Matched</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="start_date">From</Label>
                                <Input
                                    id="start_date"
                                    type="date"
                                    value={filters.start_date}
                                    onChange={(event) => applyFilters({ start_date: event.target.value })}
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="end_date">To</Label>
                                <Input
                                    id="end_date"
                                    type="date"
                                    value={filters.end_date}
                                    onChange={(event) => applyFilters({ end_date: event.target.value })}
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Transactions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {transactions.data.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                No bank transactions matched the current filters.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Date</TableHead>
                                            <TableHead>Bank Account</TableHead>
                                            <TableHead>Description</TableHead>
                                            <TableHead>Reference</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="text-right">Amount</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {transactions.data.map((transaction) => {
                                            const isIncoming = transaction.amount >= 0;
                                            const statusClass = statusStyles[transaction.status] ?? 'bg-muted text-muted-foreground border-border';

                                            return (
                                                <TableRow key={transaction.id}>
                                                    <TableCell>{formatDate(transaction.transaction_date)}</TableCell>
                                                    <TableCell>{transaction.bank_account?.name ?? '-'}</TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-col">
                                                            <span className="font-medium">{transaction.description}</span>
                                                            <span className="text-xs text-muted-foreground">
                                                                {transaction.payee || (transaction.is_from_feed ? 'Imported from bank feed' : 'Manual transaction')}
                                                            </span>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {transaction.reference || transaction.source || '-'}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant="outline" className={statusClass}>
                                                            {transaction.status.replace('_', ' ')}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <div className={`inline-flex items-center gap-2 font-mono tabular-nums ${isIncoming ? 'text-status-success' : 'text-status-critical'}`}>
                                                            {isIncoming ? (
                                                                <ArrowDownToLine className="h-4 w-4" />
                                                            ) : (
                                                                <ArrowUpFromLine className="h-4 w-4" />
                                                            )}
                                                            {formatCurrency(transaction.amount)}
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        )}

                        {transactions.last_page > 1 && (
                            <div className="mt-4 flex items-center justify-between">
                                <p className="text-sm text-muted-foreground">
                                    Showing {(transactions.current_page - 1) * transactions.per_page + 1} to{' '}
                                    {Math.min(transactions.current_page * transactions.per_page, transactions.total)} of{' '}
                                    {transactions.total} transactions
                                </p>
                                <div className="flex gap-1">
                                    {transactions.links.map((link, index) => (
                                        <Button
                                            key={index}
                                            variant={link.active ? 'default' : 'outline'}
                                            size="sm"
                                            disabled={!link.url}
                                            onClick={() => link.url && router.visit(link.url)}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
