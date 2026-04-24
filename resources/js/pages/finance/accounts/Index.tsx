import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronRight, DollarSign, Plus } from 'lucide-react';
import { useState } from 'react';

type Account = {
    id: number;
    code: string;
    name: string;
    type: string;
    sub_type: string | null;
    is_system: boolean;
    is_active: boolean;
    gst_applicable: boolean;
    description: string | null;
    balance: number;
    children: Account[];
};

type AccountTree = {
    asset: Account[];
    liability: Account[];
    equity: Account[];
    revenue: Account[];
    expense: Account[];
};

type PageProps = {
    accountTree: AccountTree;
    accountTypes: { value: string; label: string }[];
};

const formatNZD = (amount: number) =>
    new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(amount);

const typeLabels: Record<string, string> = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    revenue: 'Revenue',
    expense: 'Expenses',
};

const typeColors: Record<string, string> = {
    asset: 'bg-status-info-bg text-status-info border-status-info/30',
    liability:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
    equity: 'bg-primary/10 text-primary border-primary/30',
    revenue:
        'bg-status-success-bg text-status-success border-status-success/30',
    expense:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
};

function AccountRow({
    account,
    depth = 0,
}: {
    account: Account;
    depth?: number;
}) {
    const [isOpen, setIsOpen] = useState(true);
    const hasChildren = account.children.length > 0;

    return (
        <div>
            <div
                className="group flex cursor-pointer items-center gap-2 rounded-md px-3 py-2 hover:bg-muted/50"
                style={{ paddingLeft: `${depth * 24 + 12}px` }}
                onClick={() => router.visit(`/finance/accounts/${account.id}`)}
            >
                {hasChildren ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={
                            isOpen ? 'Collapse account' : 'Expand account'
                        }
                        onClick={(e) => {
                            e.stopPropagation();
                            setIsOpen(!isOpen);
                        }}
                        className="h-6 w-6"
                    >
                        {isOpen ? (
                            <ChevronDown className="h-4 w-4 text-muted-foreground" />
                        ) : (
                            <ChevronRight className="h-4 w-4 text-muted-foreground" />
                        )}
                    </Button>
                ) : (
                    <span className="w-5" />
                )}

                <span className="w-20 shrink-0 font-mono text-sm text-muted-foreground">
                    {account.code}
                </span>
                <span className="flex-1 truncate text-sm">{account.name}</span>
                {account.is_system && (
                    <Badge variant="outline" className="text-xs">
                        System
                    </Badge>
                )}
                {!account.is_active && (
                    <Badge variant="secondary" className="text-xs">
                        Inactive
                    </Badge>
                )}
                <span className="w-32 text-right font-mono text-sm tabular-nums">
                    {formatNZD(account.balance)}
                </span>
            </div>
            {hasChildren && isOpen && (
                <div>
                    {account.children.map((child) => (
                        <AccountRow
                            key={child.id}
                            account={child}
                            depth={depth + 1}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

function AccountTypeSection({
    type,
    accounts,
}: {
    type: string;
    accounts: Account[];
}) {
    const [isOpen, setIsOpen] = useState(true);

    const totalBalance = accounts.reduce(function sumBalance(
        total: number,
        acc: Account,
    ): number {
        const childrenTotal = acc.children.reduce(sumBalance, 0);
        return total + acc.balance + childrenTotal;
    }, 0);

    return (
        <Collapsible open={isOpen} onOpenChange={setIsOpen}>
            <CollapsibleTrigger asChild>
                <div className="flex cursor-pointer items-center justify-between rounded-lg bg-muted/30 px-4 py-3 transition-colors hover:bg-muted/50">
                    <div className="flex items-center gap-3">
                        {isOpen ? (
                            <ChevronDown className="h-5 w-5 text-muted-foreground" />
                        ) : (
                            <ChevronRight className="h-5 w-5 text-muted-foreground" />
                        )}
                        <Badge variant="outline" className={typeColors[type]}>
                            {typeLabels[type]}
                        </Badge>
                        <span className="text-sm text-muted-foreground">
                            {accounts.length} account
                            {accounts.length !== 1 ? 's' : ''}
                        </span>
                    </div>
                    <span className="font-mono text-sm font-semibold tabular-nums">
                        {formatNZD(totalBalance)}
                    </span>
                </div>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div className="mt-1 ml-2">
                    {accounts.map((account) => (
                        <AccountRow key={account.id} account={account} />
                    ))}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

export default function AccountsIndex({
    accountTree,
    accountTypes,
}: PageProps) {
    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Chart of Accounts', href: '/finance/accounts' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Chart of Accounts" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Chart of Accounts
                        </h1>
                        <p className="text-muted-foreground">
                            Manage your organisation's account structure
                        </p>
                    </div>
                    <Link href={'/finance/accounts/create'}>
                        <Button>
                            <Plus className="mr-2 h-4 w-4" />
                            Add Account
                        </Button>
                    </Link>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <DollarSign className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>Account Tree</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {(
                            [
                                'asset',
                                'liability',
                                'equity',
                                'revenue',
                                'expense',
                            ] as const
                        ).map((type) => (
                            <AccountTypeSection
                                key={type}
                                type={type}
                                accounts={accountTree[type] || []}
                            />
                        ))}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
