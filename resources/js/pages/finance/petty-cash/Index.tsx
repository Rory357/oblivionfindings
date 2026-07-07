import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { BankingTabsFooter, PettyCashFundDialog, formatMoney, type UserOption } from '@/components/finance';
import type { AccountOption } from '@/components/finance';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Plus, Wallet, Coins, Download } from 'lucide-react';
import { useState } from 'react';

interface Fund {
    id: number;
    name: string;
    float_amount: number;
    current_balance: number;
    custodian_name: string | null;
    gl_account_name: string | null;
    is_active: boolean;
}

interface Props extends PageProps {
    funds: Fund[];
    canManage: boolean;
    accounts: AccountOption[];
    users: UserOption[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Petty Cash', href: '/finance/petty-cash' },
];

export default function PettyCashIndex({ funds, canManage = false, accounts = [], users = [] }: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const activeCount = funds.filter((f) => f.is_active).length;
    const totalFloat = funds.reduce((s, f) => s + f.float_amount, 0);
    const totalBalance = funds.reduce((s, f) => s + f.current_balance, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Petty Cash" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Coins}
                        title="Petty Cash Funds"
                        description="Manage petty cash floats and transactions"
                        stats={[
                            { label: 'Funds', value: funds.length },
                            { label: 'Active', value: activeCount },
                            { label: 'Total float', value: formatMoney(totalFloat) },
                            { label: 'Total balance', value: formatMoney(totalBalance) },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Button size="sm" variant="outline" asChild>
                                    <a href="/finance/petty-cash/export">
                                        <Download className="mr-1.5 h-4 w-4" />
                                        Export CSV
                                    </a>
                                </Button>
                                {canManage && (
                                    <Button size="sm" onClick={() => setCreateOpen(true)}>
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New Fund
                                    </Button>
                                )}
                            </div>
                        }
                        footer={<BankingTabsFooter active="petty-cash" />}
                    />
                }
            >
                {funds.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <Wallet className="mb-4 h-12 w-12 text-muted-foreground" />
                            <p className="text-lg font-medium text-muted-foreground">No petty cash funds yet.</p>
                            <p className="text-sm text-muted-foreground">Create your first fund to get started.</p>
                            {canManage && (
                                <Button size="sm" className="mt-4" onClick={() => setCreateOpen(true)}>
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New Fund
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {funds.map((fund) => {
                            const variance = fund.current_balance - fund.float_amount;
                            return (
                                <Link key={fund.id} href={`/finance/petty-cash/${fund.id}`}>
                                    <Card className="transition-shadow hover:shadow-md">
                                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                                            <CardTitle className="text-lg">{fund.name}</CardTitle>
                                            {fund.is_active ? (
                                                <Badge variant="outline" className="border-status-success/30 text-status-success">
                                                    Active
                                                </Badge>
                                            ) : (
                                                <Badge variant="secondary">Inactive</Badge>
                                            )}
                                        </CardHeader>
                                        <CardContent className="space-y-3">
                                            <div className="grid grid-cols-2 gap-2 text-sm">
                                                <div>
                                                    <p className="text-muted-foreground">Float</p>
                                                    <p className="font-semibold">
                                                        {formatMoney(fund.float_amount)}
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">Current Balance</p>
                                                    <p className="font-semibold">
                                                        {formatMoney(fund.current_balance)}
                                                    </p>
                                                </div>
                                            </div>
                                            {variance !== 0 && (
                                                <div className="text-sm">
                                                    <span className="text-muted-foreground">Variance: </span>
                                                    <span
                                                        className={
                                                            variance < 0 ? 'font-medium text-destructive' : 'text-status-success'
                                                        }
                                                    >
                                                        {formatMoney(variance)}
                                                    </span>
                                                </div>
                                            )}
                                            {fund.custodian_name && (
                                                <div className="text-sm">
                                                    <span className="text-muted-foreground">Custodian: </span>
                                                    <span>{fund.custodian_name}</span>
                                                </div>
                                            )}
                                            {fund.gl_account_name && (
                                                <div className="text-sm text-muted-foreground">
                                                    GL: {fund.gl_account_name}
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                </Link>
                            );
                        })}
                    </div>
                )}
            </PageLayout>

            {canManage && (
                <PettyCashFundDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    accounts={accounts}
                    users={users}
                />
            )}
        </AppLayout>
    );
}
