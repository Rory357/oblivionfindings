import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import {
    BankAccountDialog,
    BankingTabsFooter,
    type AccountOption,
    type EditableBankAccount,
} from '@/components/finance';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Plus, Building2, AlertCircle, DollarSign, Landmark, Pencil, Star, Banknote } from 'lucide-react';
import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer } from 'recharts';
import { type BreadcrumbItem } from '@/types';
import { useMemo, useState } from 'react';

const CHART_COLORS = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#84cc16'];
const formatCurrency = (amount: number) => new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

interface BankAccount {
    id: number;
    name: string;
    bank_name: string;
    account_number: string | null;
    account_type: string;
    gl_account_id: number | null;
    current_balance: number;
    is_primary: boolean;
    is_active: boolean;
    gl_account: { id: number; code: string; name: string } | null;
    unreconciled_count: number;
}

interface Props {
    bankAccounts: BankAccount[];
    canManage: boolean;
    glAccounts: AccountOption[];
}

const accountTypeLabels: Record<string, string> = {
    cheque: 'Cheque',
    savings: 'Savings',
    term_deposit: 'Term Deposit',
    credit_card: 'Credit Card',
};

export default function BankAccountsIndex({ bankAccounts, canManage = false, glAccounts = [] }: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editAccount, setEditAccount] = useState<EditableBankAccount | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Bank Accounts', href: '/finance/bank-accounts' },
    ];

    const openEdit = (account: BankAccount) =>
        setEditAccount({
            id: account.id,
            name: account.name,
            bank_name: account.bank_name,
            account_number: account.account_number,
            account_type: account.account_type,
            gl_account_id: account.gl_account_id ?? account.gl_account?.id ?? null,
            is_primary: account.is_primary,
            is_active: account.is_active,
        });

    const totalCash = useMemo(() => bankAccounts.reduce((sum, a) => sum + a.current_balance, 0), [bankAccounts]);
    const primaryAccount = useMemo(() => bankAccounts.find((a) => a.is_primary), [bankAccounts]);
    const pieData = useMemo(
        () =>
            bankAccounts
                .filter((a) => a.current_balance > 0)
                .map((a) => ({ name: a.name, value: a.current_balance })),
        [bankAccounts],
    );

    const activeCount = useMemo(() => bankAccounts.filter((a) => a.is_active).length, [bankAccounts]);
    const unreconciledTotal = useMemo(
        () => bankAccounts.reduce((sum, a) => sum + a.unreconciled_count, 0),
        [bankAccounts],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank Accounts" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Banknote}
                        title="Bank Accounts"
                        description="Manage your organisation's bank accounts and balances"
                        stats={[
                            { label: 'Accounts', value: bankAccounts.length },
                            { label: 'Active', value: activeCount },
                            { label: 'Unreconciled', value: unreconciledTotal },
                        ]}
                        actions={
                            canManage && (
                                <Button size="sm" onClick={() => setCreateOpen(true)}>
                                    <Plus className="w-4 h-4 mr-1.5" />
                                    Add Bank Account
                                </Button>
                            )
                        }
                        footer={<BankingTabsFooter active="accounts" />}
                    />
                }
            >
                {bankAccounts.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12 text-center">
                            <Building2 className="h-12 w-12 text-muted-foreground/40 mb-4" />
                            <h3 className="text-lg font-medium text-foreground mb-1">No bank accounts</h3>
                            <p className="text-muted-foreground mb-4">
                                Get started by adding your first bank account.
                            </p>
                            {canManage && (
                                <Button onClick={() => setCreateOpen(true)}>
                                    <Plus className="w-4 h-4 mr-2" />
                                    Add Bank Account
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {/* KPI Cards */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-lg bg-status-info p-2">
                                            <DollarSign className="h-5 w-5 text-status-info" />
                                        </div>
                                        <div>
                                            <p className="text-sm text-muted-foreground">Total Cash</p>
                                            <p className={`text-2xl font-semibold font-mono tabular-nums ${totalCash >= 0 ? 'text-status-success' : 'text-status-critical'}`}>
                                                {formatCurrency(totalCash)}
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-lg bg-primary/10 p-2">
                                            <Landmark className="h-5 w-5 text-primary" />
                                        </div>
                                        <div>
                                            <p className="text-sm text-muted-foreground">Account Count</p>
                                            <p className="text-2xl font-semibold">
                                                {bankAccounts.length}
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-lg bg-status-warning p-2">
                                            <Star className="h-5 w-5 text-status-warning" />
                                        </div>
                                        <div>
                                            <p className="text-sm text-muted-foreground">Primary Account</p>
                                            <p className={`text-2xl font-semibold font-mono tabular-nums ${(primaryAccount?.current_balance ?? 0) >= 0 ? 'text-status-success' : 'text-status-critical'}`}>
                                                {primaryAccount ? formatCurrency(primaryAccount.current_balance) : 'N/A'}
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        {/* PieChart - Balance Distribution */}
                        {pieData.length > 0 && (
                            <Card className="mb-6">
                                <CardHeader>
                                    <CardTitle className="text-base">Balance Distribution</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="h-[280px]">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <PieChart>
                                                <Pie
                                                    data={pieData}
                                                    cx="50%"
                                                    cy="50%"
                                                    innerRadius={60}
                                                    outerRadius={100}
                                                    paddingAngle={2}
                                                    dataKey="value"
                                                    nameKey="name"
                                                    label={({ name, percent }) => `${name} (${((percent ?? 0) * 100).toFixed(0)}%)`}
                                                >
                                                    {pieData.map((_entry, index) => (
                                                        <Cell key={`cell-${index}`} fill={CHART_COLORS[index % CHART_COLORS.length]} />
                                                    ))}
                                                </Pie>
                                                <Tooltip formatter={(value?: number) => [formatCurrency(value ?? 0), 'Balance']} />
                                            </PieChart>
                                        </ResponsiveContainer>
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Account Cards */}
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            {bankAccounts.map((account) => (
                                <Card
                                    key={account.id}
                                    role="link"
                                    tabIndex={0}
                                    aria-label={`Open ${account.name}`}
                                    onClick={() => router.visit(`/finance/bank-accounts/${account.id}`)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') router.visit(`/finance/bank-accounts/${account.id}`);
                                    }}
                                    className="hover:shadow-md transition-shadow cursor-pointer h-full"
                                >
                                        <CardHeader className="pb-3">
                                            <div className="flex items-start justify-between">
                                                <div>
                                                    <CardTitle className="text-lg">{account.name}</CardTitle>
                                                    <p className="text-sm text-muted-foreground mt-1">{account.bank_name}</p>
                                                </div>
                                                <div className="flex items-center gap-1">
                                                    {account.is_primary && (
                                                        <Badge variant="default" className="bg-status-info-bg text-status-info border-status-info/30">Primary</Badge>
                                                    )}
                                                    {!account.is_active && (
                                                        <Badge variant="secondary">Inactive</Badge>
                                                    )}
                                                    {canManage && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            className="h-7 w-7 p-0"
                                                            aria-label={`Edit ${account.name}`}
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                openEdit(account);
                                                            }}
                                                        >
                                                            <Pencil className="h-3.5 w-3.5" />
                                                        </Button>
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
                                                    <span className={`text-lg font-semibold font-mono tabular-nums ${account.current_balance >= 0 ? 'text-status-success' : 'text-status-critical'}`}>
                                                        {formatCurrency(account.current_balance)}
                                                    </span>
                                                </div>

                                                {account.gl_account && (
                                                    <div className="flex justify-between items-center">
                                                        <span className="text-sm text-muted-foreground">GL Account</span>
                                                        <span className="text-sm font-mono">{account.gl_account.code}</span>
                                                    </div>
                                                )}

                                                {account.unreconciled_count > 0 && (
                                                    <div className="flex items-center gap-2 text-status-warning bg-status-warning rounded-md px-3 py-2">
                                                        <AlertCircle className="h-4 w-4 shrink-0" />
                                                        <span className="text-sm font-medium">
                                                            {account.unreconciled_count} unreconciled transaction{account.unreconciled_count !== 1 ? 's' : ''}
                                                        </span>
                                                    </div>
                                                )}
                                            </div>
                                        </CardContent>
                                </Card>
                            ))}
                        </div>
                    </>
                )}
            </PageLayout>

            {canManage && (
                <BankAccountDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    glAccounts={glAccounts}
                />
            )}

            {canManage && editAccount && (
                <BankAccountDialog
                    key={editAccount.id}
                    open
                    bankAccount={editAccount}
                    onClose={() => setEditAccount(null)}
                    glAccounts={glAccounts}
                />
            )}
        </AppLayout>
    );
}
