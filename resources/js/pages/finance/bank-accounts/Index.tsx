import {
    BankAccountDialog,
    BankingTabsFooter,
    formatMoney,
    useRowContextMenu,
    type AccountOption,
    type EditableBankAccount,
    type RowCtxItem,
} from '@/components/finance';
import { chartColor } from '@/components/finance/chart-palette';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyList } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertCircle,
    Banknote,
    Building2,
    DollarSign,
    Eye,
    Landmark,
    Pencil,
    Plus,
    Star,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';

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

export default function BankAccountsIndex({
    bankAccounts,
    canManage = false,
    glAccounts = [],
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editAccount, setEditAccount] = useState<EditableBankAccount | null>(
        null,
    );

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
            gl_account_id:
                account.gl_account_id ?? account.gl_account?.id ?? null,
            is_primary: account.is_primary,
            is_active: account.is_active,
        });

    // Right-click row menu — mirrors the card's existing inline actions (Open first).
    const rowMenu = useRowContextMenu();
    const rowMenuItems = (account: BankAccount): RowCtxItem[] => {
        const items: RowCtxItem[] = [
            {
                kind: 'item',
                label: 'Open',
                icon: Eye,
                onSelect: () =>
                    router.visit(`/finance/bank-accounts/${account.id}`),
            },
        ];
        if (canManage) {
            items.push({
                kind: 'item',
                label: 'Edit',
                icon: Pencil,
                onSelect: () => openEdit(account),
            });
        }
        return items;
    };

    const totalCash = useMemo(
        () => bankAccounts.reduce((sum, a) => sum + a.current_balance, 0),
        [bankAccounts],
    );
    const primaryAccount = useMemo(
        () => bankAccounts.find((a) => a.is_primary),
        [bankAccounts],
    );
    const pieData = useMemo(
        () =>
            bankAccounts
                .filter((a) => a.current_balance > 0)
                .map((a) => ({ name: a.name, value: a.current_balance })),
        [bankAccounts],
    );

    const activeCount = useMemo(
        () => bankAccounts.filter((a) => a.is_active).length,
        [bankAccounts],
    );
    const unreconciledTotal = useMemo(
        () => bankAccounts.reduce((sum, a) => sum + a.unreconciled_count, 0),
        [bankAccounts],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank Accounts" />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
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
                                <Button
                                    size="sm"
                                    onClick={() => setCreateOpen(true)}
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
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
                        <CardContent className="p-0">
                            <EmptyList
                                icon={Building2}
                                itemName="bank account"
                                title="No bank accounts yet"
                                description="Get started by adding your first bank account."
                                className="border-0"
                                action={
                                    canManage ? (
                                        <Button
                                            size="sm"
                                            onClick={() => setCreateOpen(true)}
                                        >
                                            Add bank account
                                        </Button>
                                    ) : undefined
                                }
                            />
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {/* KPI Cards */}
                        <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-lg bg-status-info p-2">
                                            <DollarSign className="h-5 w-5 text-status-info" />
                                        </div>
                                        <div>
                                            <p className="text-sm text-muted-foreground">
                                                Total Cash
                                            </p>
                                            <p
                                                className={`font-mono text-2xl font-semibold tabular-nums ${totalCash >= 0 ? 'text-status-success' : 'text-status-critical'}`}
                                            >
                                                {formatMoney(totalCash)}
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
                                            <p className="text-sm text-muted-foreground">
                                                Account Count
                                            </p>
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
                                            <p className="text-sm text-muted-foreground">
                                                Primary Account
                                            </p>
                                            <p
                                                className={`font-mono text-2xl font-semibold tabular-nums ${(primaryAccount?.current_balance ?? 0) >= 0 ? 'text-status-success' : 'text-status-critical'}`}
                                            >
                                                {primaryAccount
                                                    ? formatMoney(
                                                          primaryAccount.current_balance,
                                                      )
                                                    : 'N/A'}
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
                                    <CardTitle className="text-base">
                                        Balance Distribution
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="h-[280px]">
                                        <ResponsiveContainer
                                            width="100%"
                                            height="100%"
                                        >
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
                                                    label={({
                                                        name,
                                                        percent,
                                                    }) =>
                                                        `${name} (${((percent ?? 0) * 100).toFixed(0)}%)`
                                                    }
                                                >
                                                    {pieData.map(
                                                        (_entry, index) => (
                                                            <Cell
                                                                key={`cell-${index}`}
                                                                fill={chartColor(
                                                                    index,
                                                                )}
                                                            />
                                                        ),
                                                    )}
                                                </Pie>
                                                <Tooltip
                                                    formatter={(
                                                        value?: number,
                                                    ) => [
                                                        formatMoney(value ?? 0),
                                                        'Balance',
                                                    ]}
                                                />
                                            </PieChart>
                                        </ResponsiveContainer>
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Account Cards */}
                        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                            {bankAccounts.map((account) => (
                                <Card
                                    key={account.id}
                                    role="link"
                                    tabIndex={0}
                                    aria-label={`Open ${account.name}`}
                                    onClick={() =>
                                        router.visit(
                                            `/finance/bank-accounts/${account.id}`,
                                        )
                                    }
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter')
                                            router.visit(
                                                `/finance/bank-accounts/${account.id}`,
                                            );
                                    }}
                                    onContextMenu={rowMenu.open(
                                        rowMenuItems(account),
                                    )}
                                    className="h-full cursor-pointer transition-shadow hover:shadow-md"
                                >
                                    <CardHeader className="pb-3">
                                        <div className="flex items-start justify-between">
                                            <div>
                                                <CardTitle className="text-lg">
                                                    {account.name}
                                                </CardTitle>
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    {account.bank_name}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-1">
                                                {account.is_primary && (
                                                    <Badge
                                                        variant="default"
                                                        className="border-status-info/30 bg-status-info-bg text-status-info"
                                                    >
                                                        Primary
                                                    </Badge>
                                                )}
                                                {!account.is_active && (
                                                    <Badge variant="secondary">
                                                        Inactive
                                                    </Badge>
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
                                            <div className="flex items-center justify-between">
                                                <span className="text-sm text-muted-foreground">
                                                    Type
                                                </span>
                                                <Badge variant="outline">
                                                    {accountTypeLabels[
                                                        account.account_type
                                                    ] || account.account_type}
                                                </Badge>
                                            </div>

                                            <div className="flex items-center justify-between">
                                                <span className="text-sm text-muted-foreground">
                                                    Current Balance
                                                </span>
                                                <span
                                                    className={`font-mono text-lg font-semibold tabular-nums ${account.current_balance >= 0 ? 'text-status-success' : 'text-status-critical'}`}
                                                >
                                                    {formatMoney(
                                                        account.current_balance,
                                                    )}
                                                </span>
                                            </div>

                                            {account.gl_account && (
                                                <div className="flex items-center justify-between">
                                                    <span className="text-sm text-muted-foreground">
                                                        GL Account
                                                    </span>
                                                    <span className="font-mono text-sm">
                                                        {
                                                            account.gl_account
                                                                .code
                                                        }
                                                    </span>
                                                </div>
                                            )}

                                            {account.unreconciled_count > 0 && (
                                                <div className="flex items-center gap-2 rounded-md bg-status-warning px-3 py-2 text-status-warning">
                                                    <AlertCircle className="h-4 w-4 shrink-0" />
                                                    <span className="text-sm font-medium">
                                                        {
                                                            account.unreconciled_count
                                                        }{' '}
                                                        unreconciled transaction
                                                        {account.unreconciled_count !==
                                                        1
                                                            ? 's'
                                                            : ''}
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

            {rowMenu.element}

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
