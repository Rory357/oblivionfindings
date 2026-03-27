import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Upload, Plus, ArrowRight, FileText, CheckCircle, Clock, AlertCircle } from 'lucide-react';
import { useState } from 'react';

interface BankAccount {
    id: number;
    name: string;
    bank_name: string;
    account_type: string;
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

interface Props {
    bankAccount: BankAccount;
    transactions: Transaction[];
    reconciliations: Reconciliation[];
}

const formatNZD = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const statusBadge = (status: string) => {
    switch (status) {
        case 'reconciled':
            return <Badge className="bg-green-100 text-green-800"><CheckCircle className="h-3 w-3 mr-1" />Reconciled</Badge>;
        case 'matched':
            return <Badge className="bg-blue-100 text-blue-800"><Clock className="h-3 w-3 mr-1" />Matched</Badge>;
        default:
            return <Badge className="bg-amber-100 text-amber-800"><AlertCircle className="h-3 w-3 mr-1" />Unreconciled</Badge>;
    }
};

const reconStatusBadge = (status: string) => {
    switch (status) {
        case 'completed':
            return <Badge className="bg-green-100 text-green-800">Completed</Badge>;
        default:
            return <Badge className="bg-blue-100 text-blue-800">In Progress</Badge>;
    }
};

export default function BankAccountShow({ bankAccount, transactions, reconciliations }: Props) {
    const [importOpen, setImportOpen] = useState(false);

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

    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Bank Accounts', href: '/finance/bank-accounts' },
        { title: bankAccount.name, href: `/finance/bank-accounts/${bankAccount.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={bankAccount.name} />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">{bankAccount.name}</h1>
                        <p className="text-gray-500 mt-1">{bankAccount.bank_name}</p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={`/finance/bank-accounts/${bankAccount.id}/edit`}>
                                Edit
                            </Link>
                        </Button>
                    </div>
                </div>

                {/* Balance Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">Opening Balance</p>
                            <p className="text-2xl font-semibold font-mono tabular-nums mt-1">
                                {formatNZD(bankAccount.opening_balance)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">Current Balance</p>
                            <p className={`text-2xl font-semibold font-mono tabular-nums mt-1 ${bankAccount.current_balance >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                                {formatNZD(bankAccount.current_balance)}
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
                                <FileText className="h-12 w-12 text-gray-300 mb-4" />
                                <h3 className="text-lg font-medium text-gray-900 mb-1">No transactions</h3>
                                <p className="text-gray-500">Import a CSV file or add transactions manually.</p>
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
                                            <TableCell className={`text-right font-mono tabular-nums ${txn.amount >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                                                {formatNZD(txn.amount)}
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
                                <p className="text-gray-500">No reconciliations yet.</p>
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
                                                {formatNZD(recon.statement_balance)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums">
                                                {recon.calculated_balance !== null ? formatNZD(recon.calculated_balance) : '-'}
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
            </div>
        </AppLayout>
    );
}
