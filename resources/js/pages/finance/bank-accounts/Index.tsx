import {
    BankAccountDialog,
    FinanceSectionRail,
    formatMoney,
    type AccountOption,
    type EditableBankAccount,
} from '@/components/finance';
import { chartColor } from '@/components/finance/chart-palette';
import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertCircle,
    Banknote,
    Building2,
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

const ACCOUNT_TYPE_LABELS: Record<string, string> = {
    cheque: 'Cheque',
    savings: 'Savings',
    term_deposit: 'Term deposit',
    credit_card: 'Credit card',
};

const ALL = '__all';

const TYPE_OPTIONS = [
    { value: ALL, label: 'Any type' },
    ...Object.entries(ACCOUNT_TYPE_LABELS).map(([value, label]) => ({
        value,
        label,
    })),
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Banking', href: '/finance/banking' },
    { title: 'Bank accounts', href: '/finance/bank-accounts' },
];

export default function BankAccountsIndex({
    bankAccounts,
    canManage = false,
    glAccounts = [],
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editAccount, setEditAccount] = useState<EditableBankAccount | null>(
        null,
    );
    const [search, setSearch] = useState('');
    const [type, setType] = useState(ALL);
    const [includeInactive, setIncludeInactive] = useState(true);
    const ctxMenu = useEntityContextMenu<BankAccount>();

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

    const openAccount = (account: BankAccount) =>
        router.visit(`/finance/bank-accounts/${account.id}`);

    const actionsFor = (account: BankAccount): MenuItem[] =>
        compactMenu([
            {
                label: 'Open account',
                icon: Eye,
                onClick: () => openAccount(account),
            },
            canManage
                ? {
                      label: 'Edit account',
                      icon: Pencil,
                      onClick: () => openEdit(account),
                  }
                : null,
        ]);

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
    const positiveCash = useMemo(
        () =>
            bankAccounts.reduce(
                (sum, a) => sum + Math.max(0, a.current_balance),
                0,
            ),
        [bankAccounts],
    );

    const term = search.trim().toLowerCase();
    const visible = useMemo(
        () =>
            bankAccounts.filter((account) => {
                if (!includeInactive && !account.is_active) return false;
                if (type !== ALL && account.account_type !== type) return false;
                if (!term) return true;
                return [
                    account.name,
                    account.bank_name,
                    account.account_number ?? '',
                    account.gl_account?.code ?? '',
                ]
                    .join(' ')
                    .toLowerCase()
                    .includes(term);
            }),
        [bankAccounts, includeInactive, type, term],
    );

    const hasFilters = Boolean(term) || type !== ALL || !includeInactive;
    const clearFilters = () => {
        setSearch('');
        setType(ALL);
        setIncludeInactive(true);
    };

    const header = (
        <PageHeader
            variant="index"
            icon={Banknote}
            title="Bank accounts"
            titleChip={
                <PageHeaderStatusChip
                    variant={unreconciledTotal > 0 ? 'warning' : 'success'}
                >
                    {unreconciledTotal > 0
                        ? `${unreconciledTotal} unreconciled`
                        : 'All reconciled'}
                </PageHeaderStatusChip>
            }
            subline={`Banking · ${bankAccounts.length} accounts · ${activeCount} active`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search accounts, banks…"
                    />
                    {canManage && (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New bank account
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total cash"
                        tone={totalCash >= 0 ? 'success' : 'critical'}
                        href="/finance/cash-position"
                        ariaLabel="View the cash position"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(totalCash)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Across {bankAccounts.length} accounts
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Active accounts"
                        onClick={() => {
                            setIncludeInactive(false);
                            setType(ALL);
                            setSearch('');
                        }}
                        ariaLabel="Show only active accounts"
                    >
                        <PageHeaderMeterBig>{activeCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {bankAccounts.length - activeCount} inactive
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Unreconciled"
                        tone={unreconciledTotal > 0 ? 'warning' : 'brand'}
                        href="/finance/bank-transactions?status=unreconciled"
                        ariaLabel="View unreconciled bank transactions"
                    >
                        <PageHeaderMeterBig>
                            {unreconciledTotal}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Transactions still to match
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {primaryAccount ? (
                        <PageHeaderMeterBlock
                            label="Primary account"
                            href={`/finance/bank-accounts/${primaryAccount.id}`}
                            ariaLabel={`Open ${primaryAccount.name}`}
                        >
                            <PageHeaderMeterBig>
                                {formatMoney(primaryAccount.current_balance)}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {primaryAccount.name}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Type"
                        value={type}
                        allValue={ALL}
                        options={TYPE_OPTIONS}
                        onChange={setType}
                    />
                    <PageHeaderFilterCheck
                        label="Include inactive"
                        checked={includeInactive}
                        onChange={setIncludeInactive}
                    />
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank accounts" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {bankAccounts.length === 0 ? (
                        <EmptyList
                            icon={Building2}
                            itemName="bank account"
                            title="No bank accounts yet"
                            description="Add your first bank account to start tracking balances and reconciliations."
                            action={
                                canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        New bank account
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <>
                            {pieData.length > 0 && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-section-title">
                                            Balance distribution
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
                                                            formatMoney(
                                                                value ?? 0,
                                                            ),
                                                            'Balance',
                                                        ]}
                                                    />
                                                </PieChart>
                                            </ResponsiveContainer>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            <ListCaption
                                title="Bank accounts"
                                caption={`${visible.length} of ${bankAccounts.length} shown`}
                            />

                            {visible.length === 0 ? (
                                <EmptySearch
                                    onClear={clearFilters}
                                    title="No bank accounts match your filters"
                                />
                            ) : (
                                <EntityCardGrid>
                                    {visible.map((account) => (
                                        <EntityCard
                                            key={account.id}
                                            meridian={
                                                !account.is_active
                                                    ? 'warning'
                                                    : account.unreconciled_count >
                                                        0
                                                      ? 'warning'
                                                      : 'success'
                                            }
                                            icon={Landmark}
                                            name={account.name}
                                            subline={account.bank_name}
                                            sublineIcon={Building2}
                                            href={`/finance/bank-accounts/${account.id}`}
                                            onOpen={() => openAccount(account)}
                                            onContextMenu={(event) =>
                                                ctxMenu.open(event, account)
                                            }
                                            actions={actionsFor(account)}
                                            muted={!account.is_active}
                                            chips={
                                                <>
                                                    <EntityChip>
                                                        {ACCOUNT_TYPE_LABELS[
                                                            account.account_type
                                                        ] ??
                                                            account.account_type}
                                                    </EntityChip>
                                                    {account.is_primary ? (
                                                        <EntityStatusChip
                                                            variant="info"
                                                            icon={Star}
                                                        >
                                                            Primary
                                                        </EntityStatusChip>
                                                    ) : null}
                                                    {!account.is_active ? (
                                                        <EntityStatusChip variant="neutral">
                                                            Inactive
                                                        </EntityStatusChip>
                                                    ) : null}
                                                </>
                                            }
                                            metric={{
                                                label: 'Current balance',
                                                value: formatMoney(
                                                    account.current_balance,
                                                ),
                                                percent:
                                                    positiveCash > 0
                                                        ? Math.round(
                                                              (Math.max(
                                                                  0,
                                                                  account.current_balance,
                                                              ) /
                                                                  positiveCash) *
                                                                  100,
                                                          )
                                                        : null,
                                                tone:
                                                    account.current_balance >= 0
                                                        ? 'success'
                                                        : 'critical',
                                            }}
                                            alerts={
                                                account.unreconciled_count >
                                                0 ? (
                                                    <EntityStatusChip
                                                        variant="warning"
                                                        icon={AlertCircle}
                                                    >
                                                        {
                                                            account.unreconciled_count
                                                        }{' '}
                                                        unreconciled
                                                        {account.unreconciled_count ===
                                                        1
                                                            ? ' transaction'
                                                            : ' transactions'}
                                                    </EntityStatusChip>
                                                ) : undefined
                                            }
                                            footer={{
                                                personIcon: Landmark,
                                                primary:
                                                    account.account_number ??
                                                    'No account number',
                                                secondary: account.gl_account
                                                    ? `GL ${account.gl_account.code} · ${account.gl_account.name}`
                                                    : 'No GL account linked',
                                            }}
                                            openLabel="Open"
                                        />
                                    ))}
                                </EntityCardGrid>
                            )}
                        </>
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Landmark}
                    title={ctxMenu.ctx.record.name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

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
