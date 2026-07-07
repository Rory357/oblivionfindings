import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { BankAccountDialog, formatMoney, type AccountOption } from '@/components/finance';
import { chartColor } from '@/components/finance/chart-palette';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Upload, ArrowRight, FileText, CheckCircle, Clock, AlertCircle } from 'lucide-react';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { PageHero, PageLayout } from '@/components/page';
import { type BreadcrumbItem } from '@/types';
import { useState } from 'react';

interface BankAccount {
    id: number;
    name: string;
    bank_name: string;
    account_number: string | null;
    account_type: string;
    gl_account_id: number | null;
    opening_balance: number;
    current_balance: number;
    is_primary: boolean;
    is_active: boolean;
    gl_account: { id: number; code: string; name: string } | null;
}

interface Transaction {
    id: number;
    transaction_date: string;
    amount: number;
    description: string;
    reference: string | null;
    source: string;
    status: string;
}

interface Reconciliation {
    id: number;
    statement_date: string;
    statement_balance: number;
    calculated_balance: number | null;
    status: string;
    completed_at: string | null;
}

interface BalanceHistoryEntry {
    date: string;
    amount: number;
}

interface Props {
    bankAccount: BankAccount;
    transactions: Transaction[];
    reconciliations: Reconciliation[];
    balanceHistory: BalanceHistoryEntry[];
    canManage: boolean;
    glAccounts: AccountOption[];
}

const statusBadge = (status: string) => {
    switch (status) {
        case 'reconciled':
            return <Badge className="bg-status-success-bg text-status-success border-status-success/30"><CheckCircle className="h-3 w-3 mr-1" />Reconciled</Badge>;
        case 'matched':
            return <Badge className="bg-status-info-bg text-status-info border-status-info/30"><Clock className="h-3 w-3 mr-1" />Matched</Badge>;
        default:
            return <Badge className="bg-status-warning-bg text-status-warning border-status-warning/30"><AlertCircle className="h-3 w-3 mr-1" />Unreconciled</Badge>;
    }
};

const reconStatusBadge = (status: string) => {
    switch (status) {
        case 'completed':
            return <Badge className="bg-status-success-bg text-status-success border-status-success/30">Completed</Badge>;
        default:
            return <Badge className="bg-status-info-bg text-status-info border-status-info/30">In Progress</Badge>;
    }
};

export default function BankAccountShow({ bankAccount, transactions, reconciliations, balanceHistory, canManage = false, glAccounts = [] }: Props) {
    const [importOpen, setImportOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);

    const importForm = useForm({
        bank_account_id: bankAccount.id,
        file: null as File | null,
    });

    const handleImport = (e: React.FormEvent) => {
        e.preventDefault();
        if (!importForm.data.file) return;

        const formData = new FormData();
        formData.append('bank_account_id', String(bankAccount.id));
        formData.append('file', importForm.data.file);

        router.post('/finance/bank-transactions/import', formData, {
            onSuccess: () => {
                setImportOpen(false);
                importForm.reset();
            },
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Bank Accounts', href: '/finance/bank-accounts' },
        { title: bankAccount.name, href: `/finance/bank-accounts/${bankAccount.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={bankAccount.name} />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        variant="compact"
                        backHref="/finance/bank-accounts"
                        title={bankAccount.name}
                        description={bankAccount.bank_name}
                        actions={
                            canManage && (
                                <Button variant="outline" onClick={() => setEditOpen(true)}>
                                    Edit
                                </Button>
                            )
                        }
                    />
                }
            >
                {/* Balance Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">Opening Balance</p>
                            <p className="text-2xl font-semibold font-mono tabular-nums mt-1">
                                {formatMoney(bankAccount.opening_balance)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">Current Balance</p>
                            <p className={`text-2xl font-semibold font-mono tabular-nums mt-1 ${bankAccount.current_balance >= 0 ? 'text-status-success' : 'text-status-critical'}`}>
                                {formatMoney(bankAccount.current_balance)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">GL Account</p>
                            <p className="text-lg font-medium mt-1">
                                {bankAccount.gl_account ? `${bankAccount.gl_account.code} - ${bankAccount.gl_account.name}` : 'Not linked'}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Balance History Chart */}
                {balanceHistory.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Transaction Amounts Over Time</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-[280px]">
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart data={balanceHistory}>
                                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                        <XAxis dataKey="date" tick={{ fontSize: 12 }} className="text-muted-foreground" />
                                        <YAxis tick={{ fontSize: 12 }} className="text-muted-foreground" tickFormatter={(v) => formatMoney(v)} />
                                        <Tooltip formatter={(value?: number) => [formatMoney(value ?? 0), 'Amount']} />
                                        <Line
                                            type="monotone"
                                            dataKey="amount"
                                            stroke={chartColor(0)}
                                            strokeWidth={2}
                                            dot={{ fill: chartColor(0), r: 3 }}
                                            activeDot={{ r: 5 }}
                                        />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Transactions */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle>Recent Transactions</CardTitle>
                            <div className="flex gap-2">
                                <Dialog open={importOpen} onOpenChange={setImportOpen}>
                                    <DialogTrigger asChild>
                                        <Button variant="outline" size="sm">
                                            <Upload className="h-4 w-4 mr-2" />
                                            Import Transactions
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>Import Bank Transactions</DialogTitle>
                                        </DialogHeader>
                                        <form onSubmit={handleImport} className="space-y-4">
                                            <div>
                                                <Label>CSV File</Label>
                                                <p className="text-sm text-muted-foreground mb-2">
                                                    Format: Date, Amount, Description, Reference (first row as headers)
                                                </p>
                                                <Input
                                                    type="file"
                                                    accept=".csv,.txt"
                                                    onChange={(e) => {
                                                        const file = e.target.files?.[0] ?? null;
                                                        importForm.setData('file', file);
                                                    }}
                                                />
                                            </div>
                                            <div className="flex justify-end gap-2">
                                                <Button type="button" variant="outline" onClick={() => setImportOpen(false)}>
                                                    Cancel
                                                </Button>
                                                <Button type="submit" disabled={!importForm.data.file || importForm.processing}>
                                                    {importForm.processing ? 'Importing...' : 'Import'}
                                                </Button>
                                            </div>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                                <Link href={`/finance/bank-reconciliation/create?bank_account_id=${bankAccount.id}`}>
                                    <Button size="sm">
                                        <ArrowRight className="h-4 w-4 mr-2" />
                                        Start Reconciliation
                                    </Button>
                                </Link>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        {transactions.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <FileText className="h-12 w-12 text-muted-foreground/40 mb-4" />
                                <h3 className="text-lg font-medium text-foreground mb-1">No transactions</h3>
                                <p className="text-muted-foreground">Import a CSV file or add transactions manually.</p>
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Description</TableHead>
                                        <TableHead>Reference</TableHead>
                                        <TableHead>Source</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {transactions.map((txn) => (
                                        <TableRow key={txn.id}>
                                            <TableCell className="whitespace-nowrap">{txn.transaction_date}</TableCell>
                                            <TableCell>{txn.description}</TableCell>
                                            <TableCell className="text-muted-foreground">{txn.reference || '-'}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline" className="capitalize">{txn.source}</Badge>
                                            </TableCell>
                                            <TableCell className={`text-right font-mono tabular-nums ${txn.amount >= 0 ? 'text-status-success' : 'text-status-critical'}`}>
                                                {formatMoney(txn.amount)}
                                            </TableCell>
                                            <TableCell>{statusBadge(txn.status)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {/* Reconciliations */}
                <Card>
                    <CardHeader>
                        <CardTitle>Past Reconciliations</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {reconciliations.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-8 text-center">
                                <p className="text-muted-foreground">No reconciliations yet.</p>
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Statement Date</TableHead>
                                        <TableHead className="text-right">Statement Balance</TableHead>
                                        <TableHead className="text-right">Calculated Balance</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Completed At</TableHead>
                                        <TableHead></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {reconciliations.map((recon) => (
                                        <TableRow key={recon.id}>
                                            <TableCell>{recon.statement_date}</TableCell>
                                            <TableCell className="text-right font-mono tabular-nums">
                                                {formatMoney(recon.statement_balance)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums">
                                                {recon.calculated_balance !== null ? formatMoney(recon.calculated_balance) : '-'}
                                            </TableCell>
                                            <TableCell>{reconStatusBadge(recon.status)}</TableCell>
                                            <TableCell className="text-muted-foreground">{recon.completed_at || '-'}</TableCell>
                                            <TableCell>
                                                <Link href={`/finance/bank-reconciliation/${recon.id}`}>
                                                    <Button variant="ghost" size="sm">View</Button>
                                                </Link>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>

            {/* Mounted only while open so each edit starts from fresh props. */}
            {canManage && editOpen && (
                <BankAccountDialog
                    open
                    onClose={() => setEditOpen(false)}
                    glAccounts={glAccounts}
                    bankAccount={{
                        id: bankAccount.id,
                        name: bankAccount.name,
                        bank_name: bankAccount.bank_name,
                        account_number: bankAccount.account_number,
                        account_type: bankAccount.account_type,
                        gl_account_id: bankAccount.gl_account_id ?? bankAccount.gl_account?.id ?? null,
                        is_primary: bankAccount.is_primary,
                        is_active: bankAccount.is_active,
                    }}
                />
            )}
        </AppLayout>
    );
}
