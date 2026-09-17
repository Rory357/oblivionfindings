import {
    FinanceSectionRail,
    NewAccountDialog,
    formatMoney,
    type EditableAccount,
} from '@/components/finance';
import {
    EntityContextMenu,
    EntityKebab,
    EntityStatusChip,
    ListCaption,
    type MenuItem,
    useEntityContextMenu,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    Download,
    Eye,
    Pencil,
    Plus,
    Wallet,
} from 'lucide-react';
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
    parent_id: number | null;
    default_tax_rate_id: number | null;
    funding_stream_id: number | null;
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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'General ledger', href: '/finance/ledger' },
    { title: 'Chart of accounts', href: '/finance/accounts' },
];

const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'] as const;

const typeLabels: Record<string, string> = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    revenue: 'Revenue',
    expense: 'Expenses',
};

const ACTIVE_OPTIONS = [
    { value: 'all', label: 'All accounts' },
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
];

const editableFrom = (account: Account): EditableAccount => ({
    id: account.id,
    code: account.code,
    name: account.name,
    type: account.type,
    sub_type: account.sub_type,
    parent_id: account.parent_id,
    description: account.description,
    gst_applicable: account.gst_applicable,
    is_active: account.is_active,
    default_tax_rate_id: account.default_tax_rate_id,
    funding_stream_id: account.funding_stream_id,
});

function AccountRow({
    account,
    depth = 0,
    actionsFor,
    onContextMenu,
}: {
    account: Account;
    depth?: number;
    actionsFor: (account: Account) => MenuItem[];
    onContextMenu: (e: React.MouseEvent, account: Account) => void;
}) {
    const [isOpen, setIsOpen] = useState(true);
    const hasChildren = account.children.length > 0;

    return (
        <div>
            <div
                className="flex h-[46px] cursor-pointer items-center gap-2 border-b border-border px-3 transition-colors hover:bg-primary/5"
                style={{ paddingLeft: `${depth * 24 + 12}px` }}
                onClick={() => router.visit(`/finance/accounts/${account.id}`)}
                onContextMenu={(e) => onContextMenu(e, account)}
            >
                {hasChildren ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={
                            isOpen
                                ? `Collapse ${account.name}`
                                : `Expand ${account.name}`
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

                <span className="w-20 shrink-0 text-[12.5px] text-muted-foreground tabular-nums">
                    {account.code}
                </span>
                <span className="flex-1 truncate text-[13px] font-semibold text-foreground">
                    {account.name}
                </span>
                {account.is_system ? (
                    <EntityStatusChip variant="info">System</EntityStatusChip>
                ) : null}
                {!account.is_active ? (
                    <EntityStatusChip variant="neutral">
                        Inactive
                    </EntityStatusChip>
                ) : null}
                <span className="w-32 text-right text-[12.5px] font-semibold tabular-nums">
                    {formatMoney(account.balance)}
                </span>
                <span
                    onClick={(e) => e.stopPropagation()}
                    className="flex items-center justify-end"
                >
                    <EntityKebab
                        actions={actionsFor(account)}
                        label={`Actions for ${account.name}`}
                    />
                </span>
            </div>
            {hasChildren && isOpen ? (
                <div>
                    {account.children.map((child) => (
                        <AccountRow
                            key={child.id}
                            account={child}
                            depth={depth + 1}
                            actionsFor={actionsFor}
                            onContextMenu={onContextMenu}
                        />
                    ))}
                </div>
            ) : null}
        </div>
    );
}

function AccountTypeSection({
    type,
    accounts,
    actionsFor,
    onContextMenu,
}: {
    type: string;
    accounts: Account[];
    actionsFor: (account: Account) => MenuItem[];
    onContextMenu: (e: React.MouseEvent, account: Account) => void;
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
                <div className="flex h-8 cursor-pointer items-center justify-between border-b border-border bg-muted/60 px-3 transition-colors hover:bg-muted">
                    <div className="flex items-center gap-2">
                        {isOpen ? (
                            <ChevronDown className="h-4 w-4 text-muted-foreground" />
                        ) : (
                            <ChevronRight className="h-4 w-4 text-muted-foreground" />
                        )}
                        <span className="text-[10px] font-semibold tracking-[0.06em] text-muted-foreground uppercase">
                            {typeLabels[type]}
                        </span>
                        <span className="text-[11.5px] text-muted-foreground">
                            {accounts.length} account
                            {accounts.length !== 1 ? 's' : ''}
                        </span>
                    </div>
                    <span className="text-[12.5px] font-semibold tabular-nums">
                        {formatMoney(totalBalance)}
                    </span>
                </div>
            </CollapsibleTrigger>
            <CollapsibleContent>
                {accounts.map((account) => (
                    <AccountRow
                        key={account.id}
                        account={account}
                        actionsFor={actionsFor}
                        onContextMenu={onContextMenu}
                    />
                ))}
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
function filterAccounts(
    nodes: Account[],
    q: string,
    active: ActiveFilter,
): Account[] {
    const needle = q.trim().toLowerCase();
    const matches = (a: Account) => {
        const activeOk =
            active === 'all' ||
            (active === 'active' ? a.is_active : !a.is_active);
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

const countTree = (nodes: Account[]): number =>
    nodes.reduce((t, n) => t + 1 + countTree(n.children), 0);

const countTreeWhere = (
    nodes: Account[],
    predicate: (account: Account) => boolean,
): number =>
    nodes.reduce(
        (t, n) => t + (predicate(n) ? 1 : 0) + countTreeWhere(n.children, predicate),
        0,
    );

export default function AccountsIndex({
    accountTree,
    accountTypes,
    canManage = false,
    parentAccounts = [],
    taxRates = [],
    fundingStreams = [],
}: PageProps) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editAccount, setEditAccount] = useState<EditableAccount | null>(null);
    const [search, setSearch] = useState('');
    const [activeFilter, setActiveFilter] = useState<ActiveFilter>('all');

    const ctx = useEntityContextMenu<Account>();

    const totalAccounts = TYPES.reduce(
        (sum, type) => sum + countTree(accountTree[type] || []),
        0,
    );
    const activeAccounts = TYPES.reduce(
        (sum, type) =>
            sum + countTreeWhere(accountTree[type] || [], (a) => a.is_active),
        0,
    );
    const systemAccounts = TYPES.reduce(
        (sum, type) =>
            sum + countTreeWhere(accountTree[type] || [], (a) => a.is_system),
        0,
    );

    const hasFilters = search.trim() !== '' || activeFilter !== 'all';
    const filteredTree = TYPES.reduce((acc, type) => {
        acc[type] = filterAccounts(
            accountTree[type] || [],
            search,
            activeFilter,
        );
        return acc;
    }, {} as AccountTree);
    const visibleCount = TYPES.reduce(
        (sum, type) => sum + countTree(filteredTree[type]),
        0,
    );

    const actionsFor = (account: Account): MenuItem[] => {
        const items: MenuItem[] = [
            {
                label: 'Open account',
                icon: Eye,
                onClick: () => router.visit(`/finance/accounts/${account.id}`),
            },
        ];
        if (canManage) {
            items.push({
                label: 'Edit account',
                icon: Pencil,
                onClick: () => setEditAccount(editableFrom(account)),
            });
        }
        return items;
    };

    const header = (
        <PageHeader
            icon={Wallet}
            title="Chart of accounts"
            titleChip={
                <PageHeaderStatusChip variant="success">
                    {activeAccounts} active
                </PageHeaderStatusChip>
            }
            subline={`General ledger · ${totalAccounts} accounts · ${accountTypes.length} account types`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search code or name…"
                    />
                    <PageHeaderGlassButton
                        icon={Download}
                        onClick={() => {
                            window.location.href = '/finance/accounts/export';
                        }}
                    >
                        Export CSV
                    </PageHeaderGlassButton>
                    {canManage ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New account
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Accounts"
                        href="/finance/accounts"
                        ariaLabel="View the whole chart of accounts"
                    >
                        <PageHeaderMeterBig>{totalAccounts}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {accountTypes.length} account types
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Active"
                        tone="success"
                        ariaLabel="Show only active accounts"
                        onClick={() => setActiveFilter('active')}
                    >
                        <PageHeaderMeterDonut
                            percent={
                                totalAccounts === 0
                                    ? 0
                                    : (activeAccounts / totalAccounts) * 100
                            }
                            caption={`${activeAccounts} of ${totalAccounts} in use`}
                        />
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Inactive"
                        tone="warning"
                        ariaLabel="Show only inactive accounts"
                        onClick={() => setActiveFilter('inactive')}
                    >
                        <PageHeaderMeterBig>
                            {totalAccounts - activeAccounts}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            retired from posting
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="System accounts"
                        ariaLabel="View journals posted by the system accounts"
                        href="/finance/journals"
                    >
                        <PageHeaderMeterBig>
                            {systemAccounts}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            reserved by automatic postings
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    label="Active state"
                    value={activeFilter}
                    allValue="all"
                    options={ACTIVE_OPTIONS}
                    onChange={(value) =>
                        setActiveFilter(value as ActiveFilter)
                    }
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Chart of accounts" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Account tree"
                        caption={`${visibleCount} of ${totalAccounts} shown`}
                    />

                    {visibleCount === 0 ? (
                        <EmptyState
                            icon={Wallet}
                            heading={
                                hasFilters
                                    ? 'No accounts match your search'
                                    : 'No accounts yet'
                            }
                            description={
                                hasFilters
                                    ? 'Clear the search or the active-state filter to see the whole chart.'
                                    : 'Add your first account to start building the chart of accounts.'
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearch('');
                                            setActiveFilter('all');
                                        }}
                                    >
                                        Clear filters
                                    </Button>
                                ) : canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        New account
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <Card className="gap-0 overflow-hidden rounded-[14px] py-0">
                            {TYPES.filter(
                                (type) =>
                                    !hasFilters ||
                                    filteredTree[type].length > 0,
                            ).map((type) => (
                                <AccountTypeSection
                                    key={type}
                                    type={type}
                                    accounts={filteredTree[type]}
                                    actionsFor={actionsFor}
                                    onContextMenu={(e, account) =>
                                        ctx.open(e, account)
                                    }
                                />
                            ))}
                        </Card>
                    )}
                </div>
            </PageLayout>

            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={Wallet}
                    title={`${ctx.ctx.record.code} — ${ctx.ctx.record.name}`}
                    items={actionsFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            ) : null}

            {canManage ? (
                <NewAccountDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    parentAccounts={parentAccounts}
                    taxRates={taxRates}
                    fundingStreams={fundingStreams}
                />
            ) : null}

            {canManage && editAccount ? (
                <NewAccountDialog
                    key={editAccount.id}
                    open
                    account={editAccount}
                    onClose={() => setEditAccount(null)}
                    parentAccounts={parentAccounts}
                    taxRates={taxRates}
                    fundingStreams={fundingStreams}
                />
            ) : null}
        </AppLayout>
    );
}
