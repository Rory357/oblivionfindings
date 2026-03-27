import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Plus, Building2, AlertCircle } from 'lucide-react';

interface BankAccount {
    id: number;
    name: string;
    bank_name: string;
    account_type: string;
    current_balance: number;
    is_primary: boolean;
    is_active: boolean;
    gl_account: { id: number; code: string; name: string } | null;
    unreconciled_count: number;
}

interface Props {
    bankAccounts: BankAccount[];
}

const formatNZD = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const accountTypeLabels: Record<string, string> = {
    cheque: 'Cheque',
    savings: 'Savings',
    term_deposit: 'Term Deposit',
    credit_card: 'Credit Card',
};

export default function BankAccountsIndex({ bankAccounts }: Props) {
    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Bank Accounts', href: '/finance/bank-accounts' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank Accounts" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">Bank Accounts</h1>
                        <p className="text-gray-500 mt-1">
                            Manage your organisation's bank accounts and balances
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={'/finance/bank-accounts/create'}>
                            <Plus className="w-4 h-4 mr-2" />
                            Add Bank Account
                        </Link>
                    </Button>
                </div>

                {bankAccounts.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12 text-center">
                            <Building2 className="h-12 w-12 text-gray-300 mb-4" />
                            <h3 className="text-lg font-medium text-gray-900 mb-1">No bank accounts</h3>
                            <p className="text-gray-500 mb-4">
                                Get started by adding your first bank account.
                            </p>
                            <Button asChild>
                                <Link href={'/finance/bank-accounts/create'}>
                                    <Plus className="w-4 h-4 mr-2" />
                                    Add Bank Account
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        {bankAccounts.map((account) => (
                            <Link
                                key={account.id}
                                href={`/finance/bank-accounts/${account.id}`}
                                className="block"
                            >
                                <Card className="hover:shadow-md transition-shadow cursor-pointer h-full">
                                    <CardHeader className="pb-3">
                                        <div className="flex items-start justify-between">
                                            <div>
                                                <CardTitle className="text-lg">{account.name}</CardTitle>
                                                <p className="text-sm text-muted-foreground mt-1">{account.bank_name}</p>
                                            </div>
                                            <div className="flex gap-1">
                                                {account.is_primary && (
                                                    <Badge variant="default" className="bg-blue-100 text-blue-800">Primary</Badge>
                                                )}
                                                {!account.is_active && (
                                                    <Badge variant="secondary">Inactive</Badge>
                                                )}
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-3">
                                            <div className="flex justify-between items-center">
                                                <span className="text-sm text-muted-foreground">Type</span>
                                                <Badge variant="outline">
                                                    {accountTypeLabels[account.account_type] || account.account_type}
                                                </Badge>
                                            </div>

                                            <div className="flex justify-between items-center">
                                                <span className="text-sm text-muted-foreground">Current Balance</span>
                                                <span className={`text-lg font-semibold font-mono tabular-nums ${account.current_balance >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                                                    {formatNZD(account.current_balance)}
                                                </span>
                                            </div>

                                            {account.gl_account && (
                                                <div className="flex justify-between items-center">
                                                    <span className="text-sm text-muted-foreground">GL Account</span>
                                                    <span className="text-sm font-mono">{account.gl_account.code}</span>
                                                </div>
                                            )}

                                            {account.unreconciled_count > 0 && (
                                                <div className="flex items-center gap-2 text-amber-600 bg-amber-50 rounded-md px-3 py-2">
                                                    <AlertCircle className="h-4 w-4 shrink-0" />
                                                    <span className="text-sm font-medium">
                                                        {account.unreconciled_count} unreconciled transaction{account.unreconciled_count !== 1 ? 's' : ''}
                                                    </span>
                                                </div>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
