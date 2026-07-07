import { LedgerTabsFooter, NewAccountDialog, formatMoney } from '@/components/finance';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { ChevronDown, ChevronRight, DollarSign, Download, Plus, Search, Wallet } from 'lucide-react';
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

type RefItem = { id: number; code: string; name: string; type?: string };

type PageProps = {
    accountTree: AccountTree;
    accountTypes: { value: string; label: string }[];
    canManage?: boolean;
    parentAccounts?: RefItem[];
    taxRates?: { id: number; name: string; code: string; rate: string }[];
    fundingStreams?: RefItem[];
};

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
                    {formatMoney(account.balance)}
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
                        {formatMoney(totalBalance)}
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

type ActiveFilter = 'all' | 'active' | 'inactive';

/**
 * Prune the account tree to rows matching the search text (code or name) and the
 * active filter, keeping any ancestor of a match so the hierarchy stays intact.
 * Runs client-side — the whole chart is already loaded, so a tree filter is the
 * right idiom (you never paginate a chart of accounts).
 */
function filterAccounts(nodes: Account[], q: string, active: ActiveFilter): Account[] {
    const needle = q.trim().toLowerCase();
    const matches = (a: Account) => {
        const activeOk =
            active === 'all' || (active === 'active' ? a.is_active : !a.is_active);
        const textOk =
            needle === '' ||
            a.code.toLowerCase().includes(needle) ||
            a.name.toLowerCase().includes(needle);
        return activeOk && textOk;
    };
    const walk = (list: Account[]): Account[] =>
        list.reduce<Account[]>((acc, node) => {
            const children = walk(node.children);
            if (matches(node) || children.length > 0) {
                acc.push({ ...node, children });
            }
            return acc;
        }, []);
    return walk(nodes);
}

export default function AccountsIndex({
    accountTree,
    accountTypes,
    canManage = false,
    parentAccounts = [],
    taxRates = [],
    fundingStreams = [],
}: PageProps) {
    const [createOpen, setCreateOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [activeFilter, setActiveFilter] = useState<ActiveFilter>('all');
    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Chart of Accounts', href: '/finance/accounts' },
    ];

    const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'] as const;

    const countTree = (nodes: Account[]): number =>
        nodes.reduce((t, n) => t + 1 + countTree(n.children), 0);
    const totalAccounts = TYPES.reduce(
        (sum, type) => sum + countTree(accountTree[type] || []),
        0,
    );

    const hasFilters = search.trim() !== '' || activeFilter !== 'all';
    const filteredTree = TYPES.reduce((acc, type) => {
        acc[type] = filterAccounts(accountTree[type] || [], search, activeFilter);
        return acc;
    }, {} as AccountTree);
    const visibleCount = TYPES.reduce(
        (sum, type) => sum + countTree(filteredTree[type]),
        0,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Chart of Accounts" />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        icon={Wallet}
                        title="Chart of Accounts"
                        description="Manage your organisation's account structure"
                        stats={[
                            { label: 'Total accounts', value: totalAccounts },
                            { label: 'Account types', value: accountTypes.length },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Button size="sm" variant="outline" asChild>
                                    <a href="/finance/accounts/export">
                                        <Download className="mr-1.5 h-4 w-4" />
                                        Export CSV
                                    </a>
                                </Button>
                                {canManage && (
                                    <Button size="sm" onClick={() => setCreateOpen(true)}>
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Add Account
                                    </Button>
                                )}
                            </div>
                        }
                        footer={<LedgerTabsFooter active="accounts" />}
                    />
                }
            >
                <Card>
                    <CardHeader className="gap-4">
                        <div className="flex items-center gap-2">
                            <DollarSign className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>Account Tree</CardTitle>
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto]">
                            <div className="relative">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search code or name..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="pl-9"
                                />
                            </div>
                            <Select
                                value={activeFilter}
                                onValueChange={(v) => setActiveFilter(v as ActiveFilter)}
                            >
                                <SelectTrigger className="sm:w-44">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All accounts</SelectItem>
                                    <SelectItem value="active">Active only</SelectItem>
                                    <SelectItem value="inactive">Inactive only</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {visibleCount === 0 ? (
                            <div className="py-12 text-center text-sm text-muted-foreground">
                                No accounts match your search.
                            </div>
                        ) : (
                            TYPES.filter(
                                (type) => !hasFilters || filteredTree[type].length > 0,
                            ).map((type) => (
                                <AccountTypeSection
                                    key={type}
                                    type={type}
                                    accounts={filteredTree[type]}
                                />
                            ))
                        )}
                    </CardContent>
                </Card>

                {canManage && (
                    <NewAccountDialog
                        open={createOpen}
                        onClose={() => setCreateOpen(false)}
                        parentAccounts={parentAccounts}
                        taxRates={taxRates}
                        fundingStreams={fundingStreams}
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
