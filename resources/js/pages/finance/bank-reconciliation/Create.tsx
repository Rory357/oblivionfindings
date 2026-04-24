import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface BankAccount {
    id: number;
    name: string;
    current_balance: string;
}

interface Props {
    bankAccounts: BankAccount[];
    preselectedBankAccountId: number | null;
}

const formatNZD = (amount: number | string) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

export default function ReconciliationCreate({ bankAccounts, preselectedBankAccountId }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        bank_account_id: preselectedBankAccountId ? String(preselectedBankAccountId) : '',
        statement_date: new Date().toISOString().split('T')[0],
        statement_balance: '',
    });

    const selectedAccount = bankAccounts.find((a) => a.id === Number(data.bank_account_id));

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/finance/bank-reconciliation');
    };

    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Bank Reconciliation', href: '/finance/bank-reconciliation' },
        { title: 'New Reconciliation', href: '/finance/bank-reconciliation/create' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Reconciliation" />

            <div className="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-3xl font-bold text-foreground">New Bank Reconciliation</h1>
                    <p className="text-muted-foreground mt-1">
                        Start reconciling a bank statement against your ledger
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Statement Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div className="space-y-2">
                                <Label htmlFor="bank_account_id">Bank Account</Label>
                                <Select
                                    value={data.bank_account_id}
                                    onValueChange={(value) => setData('bank_account_id', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a bank account" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {bankAccounts.map((account) => (
                                            <SelectItem key={account.id} value={String(account.id)}>
                                                {account.name} ({formatNZD(account.current_balance)})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.bank_account_id && (
                                    <p className="text-sm text-red-600">{errors.bank_account_id}</p>
                                )}
                                {selectedAccount && (
                                    <p className="text-sm text-muted-foreground">
                                        Current balance: {formatNZD(selectedAccount.current_balance)}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="statement_date">Statement Date</Label>
                                <Input
                                    id="statement_date"
                                    type="date"
                                    value={data.statement_date}
                                    onChange={(e) => setData('statement_date', e.target.value)}
                                />
                                {errors.statement_date && (
                                    <p className="text-sm text-red-600">{errors.statement_date}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="statement_balance">Statement Closing Balance (NZD)</Label>
                                <Input
                                    id="statement_balance"
                                    type="number"
                                    step="0.01"
                                    placeholder="0.00"
                                    value={data.statement_balance}
                                    onChange={(e) => setData('statement_balance', e.target.value)}
                                />
                                {errors.statement_balance && (
                                    <p className="text-sm text-red-600">{errors.statement_balance}</p>
                                )}
                            </div>

                            <div className="flex justify-end gap-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => window.history.back()}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Starting...' : 'Start Reconciliation'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
