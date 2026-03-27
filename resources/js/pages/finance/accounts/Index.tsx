import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ChevronRight, ChevronDown, Plus, DollarSign } from 'lucide-react';
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
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const typeLabels: Record<string, string> = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    revenue: 'Revenue',
    expense: 'Expenses',
};

const typeColors: Record<string, string> = {
    asset: 'bg-blue-500/10 text-blue-600 border-blue-500/30',
    liability: 'bg-red-500/10 text-red-600 border-red-500/30',
    equity: 'bg-purple-500/10 text-purple-600 border-purple-500/30',
    revenue: 'bg-green-500/10 text-green-600 border-green-500/30',
    expense: 'bg-amber-500/10 text-amber-600 border-amber-500/30',
};

function AccountRow({ account, depth = 0 }: { account: Account; depth?: number }) {
    const [isOpen, setIsOpen] = useState(true);
    const hasChildren = account.children.length > 0;

    return (
        <div>
            <div
                className="flex items-center gap-2 py-2 px-3 hover:bg-muted/50 rounded-md cursor-pointer group"
                style={{ paddingLeft: `${depth * 24 + 12}px` }}
                onClick={() => router.visit(`/finance/accounts/${account.id}`)}
            >
                {hasChildren ? (
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            setIsOpen(!isOpen);
                        }}
                        className="p-0.5 hover:bg-muted rounded"
                    >
                        {isOpen ? (
                            <ChevronDown className="h-4 w-4 text-muted-foreground" />
                        ) : (
                            <ChevronRight className="h-4 w-4 text-muted-foreground" />
                        )}
                    </button>
                ) : (
                    <span className="w-5" />
                )}

                <span className="text-sm font-mono text-muted-foreground w-20 shrink-0">
                    {account.code}
                </span>
                <span className="text-sm flex-1 truncate">
                    {account.name}
                </span>
                {account.is_system && (
                    <Badge variant="outline" className="text-xs">System</Badge>
                )}
                {!account.is_active && (
                    <Badge variant="secondary" className="text-xs">Inactive</Badge>
                )}
                <span className="text-sm font-mono w-32 text-right tabular-nums">
                    {formatNZD(account.balance)}
                </span>
            </div>
            {hasChildren && isOpen && (
                <div>
                    {account.children.map((child) => (
                        <AccountRow key={child.id} account={child} depth={depth + 1} />
                    ))}
                </div>
            )}
        </div>
    );
}

function AccountTypeSection({ type, accounts }: { type: string; accounts: Account[] }) {
    const [isOpen, setIsOpen] = useState(true);

    const totalBalance = accounts.reduce(function sumBalance(total: number, acc: Account): number {
        const childrenTotal = acc.children.reduce(sumBalance, 0);
        return total + acc.balance + childrenTotal;
    }, 0);

    return (
        <Collapsible open={isOpen} onOpenChange={setIsOpen}>
            <CollapsibleTrigger asChild>
                <div className="flex items-center justify-between py-3 px-4 bg-muted/30 rounded-lg cursor-pointer hover:bg-muted/50 transition-colors">
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
                            {accounts.length} account{accounts.length !== 1 ? 's' : ''}
                        </span>
                    </div>
                    <span className="text-sm font-semibold font-mono tabular-nums">
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

export default function AccountsIndex({ accountTree, accountTypes }: PageProps) {
    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Chart of Accounts', href: '/finance/accounts' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Chart of Accounts" />

            <div className="mx-auto max-w-6xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Chart of Accounts</h1>
                        <p className="text-muted-foreground">Manage your organisation's account structure</p>
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
                        {(['asset', 'liability', 'equity', 'revenue', 'expense'] as const).map((type) => (
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
