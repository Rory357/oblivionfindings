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
import { ArrowLeft, Receipt } from 'lucide-react';
import { FormEvent } from 'react';

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
    receipt_path: string | null;
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
}

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

const typeConfig: Record<string, { label: string; className: string }> = {
    top_up: { label: 'Top Up', className: 'bg-green-100 text-green-800' },
    expense: { label: 'Expense', className: 'bg-red-100 text-red-800' },
    adjustment: { label: 'Adjustment', className: 'bg-blue-100 text-blue-800' },
};

export default function PettyCashShow({ summary, expenseAccounts }: Props) {
    const { fund, transactions } = summary;

    const { data, setData, post, processing, errors, reset } = useForm({
        transaction_date: new Date().toISOString().split('T')[0],
        type: 'expense',
        amount: '',
        description: '',
        account_id: '',
        receipt_path: '',
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        post(route('finance.petty-cash.transaction', fund.id), {
            onSuccess: () => reset(),
        });
    };

    return (
        <AppLayout>
            <Head title={`Petty Cash - ${fund.name}`} />

            <div className="space-y-6">
                <div className="flex items-center gap-4">
                    <Button asChild variant="ghost" size="sm">
                        <Link href={route('finance.petty-cash.index')}>
                            <ArrowLeft className="mr-1 h-4 w-4" />
                            Back
                        </Link>
                    </Button>
                    <h1 className="text-2xl font-bold">{fund.name}</h1>
                    {fund.is_active ? (
                        <Badge variant="outline" className="border-green-300 text-green-600">
                            Active
                        </Badge>
                    ) : (
                        <Badge variant="secondary">Inactive</Badge>
                    )}
                </div>

                {/* Fund Details */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Float Amount</p>
                            <p className="text-xl font-bold">{formatCurrency(fund.float_amount)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Current Balance</p>
                            <p className="text-xl font-bold">{formatCurrency(fund.current_balance)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Variance</p>
                            <p
                                className={`text-xl font-bold ${fund.variance < 0 ? 'text-red-600' : fund.variance > 0 ? 'text-green-600' : ''}`}
                            >
                                {formatCurrency(fund.variance)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Custodian</p>
                            <p className="text-xl font-bold">{fund.custodian_name ?? '-'}</p>
                            {fund.gl_account_name && (
                                <p className="mt-1 text-xs text-muted-foreground">GL: {fund.gl_account_name}</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Add Transaction Form */}
                <Card>
                    <CardHeader>
                        <CardTitle>Record Transaction</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="flex flex-wrap items-end gap-4">
                            <div className="w-36">
                                <Label htmlFor="transaction_date">Date</Label>
                                <Input
                                    id="transaction_date"
                                    type="date"
                                    value={data.transaction_date}
                                    onChange={(e) => setData('transaction_date', e.target.value)}
                                />
                            </div>

                            <div className="w-36">
                                <Label htmlFor="type">Type</Label>
                                <Select value={data.type} onValueChange={(val) => setData('type', val)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="expense">Expense</SelectItem>
                                        <SelectItem value="top_up">Top Up</SelectItem>
                                        <SelectItem value="adjustment">Adjustment</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="w-28">
                                <Label htmlFor="amount">Amount</Label>
                                <Input
                                    id="amount"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    value={data.amount}
                                    onChange={(e) => setData('amount', e.target.value)}
                                    placeholder="0.00"
                                />
                            </div>

                            <div className="flex-1 min-w-[200px]">
                                <Label htmlFor="description">Description</Label>
                                <Input
                                    id="description"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="What was this for?"
                                />
                            </div>

                            {data.type === 'expense' && (
                                <div className="w-56">
                                    <Label htmlFor="account_id">Expense Account</Label>
                                    <Select
                                        value={data.account_id}
                                        onValueChange={(val) => setData('account_id', val)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select account" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {expenseAccounts.map((account) => (
                                                <SelectItem key={account.id} value={String(account.id)}>
                                                    {account.code} - {account.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}

                            <Button type="submit" disabled={processing}>
                                {processing ? 'Saving...' : 'Add'}
                            </Button>
                        </form>
                        {(errors.amount || errors.transaction_date || (errors as Record<string, string>).transaction) && (
                            <p className="mt-2 text-sm text-red-600">
                                {errors.amount || errors.transaction_date || (errors as Record<string, string>).transaction}
                            </p>
                        )}
                    </CardContent>
                </Card>

                {/* Transactions Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Transactions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {transactions.length === 0 ? (
                            <p className="text-muted-foreground">No transactions recorded yet.</p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Description</TableHead>
                                        <TableHead>Account</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                        <TableHead className="text-right">Balance</TableHead>
                                        <TableHead>Receipt</TableHead>
                                        <TableHead>By</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {transactions.map((txn) => {
                                        const config = typeConfig[txn.type] ?? {
                                            label: txn.type,
                                            className: 'bg-gray-100 text-gray-800',
                                        };
                                        return (
                                            <TableRow key={txn.id}>
                                                <TableCell>{formatDate(txn.transaction_date)}</TableCell>
                                                <TableCell>
                                                    <Badge className={config.className} variant="outline">
                                                        {config.label}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="max-w-[200px] truncate">
                                                    {txn.description ?? '-'}
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {txn.account_name ?? '-'}
                                                </TableCell>
                                                <TableCell
                                                    className={`text-right font-medium ${txn.type === 'expense' ? 'text-red-600' : 'text-green-600'}`}
                                                >
                                                    {txn.type === 'expense' ? '-' : '+'}
                                                    {formatCurrency(txn.amount)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {txn.running_balance !== null
                                                        ? formatCurrency(txn.running_balance)
                                                        : '-'}
                                                </TableCell>
                                                <TableCell>
                                                    {txn.receipt_path ? (
                                                        <Receipt className="h-4 w-4 text-green-600" />
                                                    ) : (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-sm">{txn.created_by ?? '-'}</TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
