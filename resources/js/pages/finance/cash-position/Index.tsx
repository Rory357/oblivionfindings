import { FinanceSectionRail } from '@/components/finance';
import { formatMoney } from '@/components/finance/money';
import {
    EmptyValue,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
    useEntityContextMenu,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { CalendarClock, Coins, Landmark, Wallet } from 'lucide-react';
import { useRef, useState } from 'react';

type BankAccount = {
    id: number;
    name: string;
    bank_name: string | null;
    account_type: string | null;
    current_balance: number;
    is_primary: boolean;
};

type PettyFund = { id: number; name: string; current_balance: number };

type Obligation = {
    id: string;
    source: string;
    title: string;
    start: string;
    status: string;
    amount: number | null;
    direction: 'inflow' | 'outflow' | null;
    ref: string | null;
    counterparty: string | null;
    link: string | null;
};

type Props = {
    accounts: BankAccount[];
    pettyCash: PettyFund[];
    totals: {
        bank: number;
        petty_cash: number;
        cash_on_hand: number;
        expected_in_30d: number;
        expected_out_30d: number;
        projected_30d: number;
    };
    obligations: Obligation[];
    /** Real row count behind the capped obligations list. */
    obligationTotal: number;
    horizon: number;
    horizonOptions: number[];
    asOf: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Overview', href: '/finance' },
    { title: 'Cash position', href: '/finance/cash-position' },
];

const dateLabel = (isoDate: string) =>
    new Date(isoDate).toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

export default function CashPosition({
    accounts,
    pettyCash,
    totals,
    obligations,
    obligationTotal,
    horizon = 30,
    horizonOptions = [7, 14, 30, 60, 90],
}: Props) {
    const [search, setSearch] = useState('');
    const obligationsRef = useRef<HTMLDivElement>(null);

    const accountCtx = useEntityContextMenu<BankAccount>();
    const fundCtx = useEntityContextMenu<PettyFund>();
    const obligationCtx = useEntityContextMenu<Obligation>();

    const query = search.trim().toLowerCase();
    const matches = (...parts: (string | null | undefined)[]) =>
        query === '' ||
        parts.some((p) => (p ?? '').toLowerCase().includes(query));

    const shownAccounts = accounts.filter((a) =>
        matches(a.name, a.bank_name, a.account_type),
    );
    const shownFunds = pettyCash.filter((f) => matches(f.name));
    const shownObligations = obligations.filter((o) =>
        matches(o.title, o.ref, o.counterparty, o.status),
    );

    const horizonLabel = `${horizon} days`;

    /* ---------------- Row actions ---------------- */

    const accountActions = (account: BankAccount): MenuItem[] => [
        {
            label: 'Open bank account',
            icon: Landmark,
            onClick: () => router.visit(`/finance/bank-accounts/${account.id}`),
        },
    ];
    const fundActions = (fund: PettyFund): MenuItem[] => [
        {
            label: 'Open petty-cash fund',
            icon: Coins,
            onClick: () => router.visit(`/finance/petty-cash/${fund.id}`),
        },
    ];
    const obligationActions = (item: Obligation): MenuItem[] =>
        item.link
            ? [
                  {
                      label: 'Open record',
                      icon: CalendarClock,
                      onClick: () => router.visit(item.link as string),
                  },
              ]
            : [];

    /* ---------------- Columns ---------------- */

    const accountColumns: EntityTableColumn<BankAccount>[] = [
        {
            key: 'type',
            label: 'Type',
            width: '150px',
            cell: (a) =>
                a.account_type ? (
                    <span className="text-muted-foreground">
                        {a.account_type}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'balance',
            label: 'Balance',
            width: '150px',
            align: 'right',
            cell: (a) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(a.current_balance)}
                </span>
            ),
        },
    ];

    const fundColumns: EntityTableColumn<PettyFund>[] = [
        {
            key: 'balance',
            label: 'Float balance',
            width: '160px',
            align: 'right',
            cell: (f) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(f.current_balance)}
                </span>
            ),
        },
    ];

    const obligationColumns: EntityTableColumn<Obligation>[] = [
        {
            key: 'due',
            label: 'Due',
            width: '140px',
            cell: (item) => (
                <span className="whitespace-nowrap text-muted-foreground">
                    {dateLabel(item.start)}
                </span>
            ),
        },
        {
            key: 'counterparty',
            label: 'Counterparty',
            width: '1.2fr',
            cell: (item) =>
                item.counterparty ? (
                    <span className="truncate">{item.counterparty}</span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'direction',
            label: 'Direction',
            width: '150px',
            cell: (item) =>
                item.direction ? (
                    <EntityStatusChip
                        variant={
                            item.direction === 'inflow' ? 'success' : 'warning'
                        }
                    >
                        {item.direction === 'inflow' ? 'Money in' : 'Money out'}
                    </EntityStatusChip>
                ) : (
                    <EntityStatusChip variant="neutral">
                        {item.status}
                    </EntityStatusChip>
                ),
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '140px',
            align: 'right',
            cell: (item) =>
                item.amount != null ? (
                    <span className="font-semibold tabular-nums">
                        {formatMoney(item.amount)}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
    ];

    /* ---------------- Event Horizon header ---------------- */

    const header = (
        <PageHeader
            icon={Wallet}
            title="Cash position"
            titleChip={
                <PageHeaderStatusChip
                    variant={
                        totals.projected_30d >= totals.cash_on_hand
                            ? 'success'
                            : 'warning'
                    }
                >
                    Next {horizonLabel}
                </PageHeaderStatusChip>
            }
            subline={`Live balances · ${accounts.length} bank ${
                accounts.length === 1 ? 'account' : 'accounts'
            } · ${pettyCash.length} petty-cash ${
                pettyCash.length === 1 ? 'fund' : 'funds'
            }`}
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search accounts, funds, obligations…"
                />
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Cash on hand"
                        href="/finance/bank-accounts"
                        ariaLabel="View bank accounts"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(totals.cash_on_hand)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {formatMoney(totals.bank)} bank ·{' '}
                            {formatMoney(totals.petty_cash)} petty cash
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Expected in"
                        tone="success"
                        href="/finance/invoices"
                        ariaLabel="View invoices due in"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(totals.expected_in_30d)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            over the next {horizonLabel}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Expected out"
                        tone="warning"
                        href="/finance/bills"
                        ariaLabel="View bills falling due"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(totals.expected_out_30d)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            over the next {horizonLabel}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Projected"
                        tone={
                            totals.projected_30d >= 0 ? 'success' : 'critical'
                        }
                        ariaLabel="Jump to the dated obligations behind this projection"
                        onClick={() =>
                            obligationsRef.current?.scrollIntoView({
                                block: 'start',
                            })
                        }
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(totals.projected_30d)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            cash on hand in {horizonLabel}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    label="Next 30 days"
                    value={String(horizon)}
                    allValue="30"
                    options={horizonOptions.map((days) => ({
                        value: String(days),
                        label: `Next ${days} days`,
                    }))}
                    onChange={(value) =>
                        router.get(
                            '/finance/cash-position',
                            { horizon: Number(value) },
                            { preserveScroll: true, preserveState: false },
                        )
                    }
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cash position" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                        <div className="flex flex-col gap-5">
                            <ListCaption
                                title="Bank accounts"
                                caption={`${shownAccounts.length} of ${accounts.length} shown · ${formatMoney(totals.bank)}`}
                            />
                            {shownAccounts.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={Landmark}
                                    heading={
                                        accounts.length === 0
                                            ? 'No active bank accounts'
                                            : 'No accounts match your search'
                                    }
                                    description={
                                        accounts.length === 0
                                            ? 'Add a bank account under Banking to see live balances here.'
                                            : 'Clear the search to see every account.'
                                    }
                                />
                            ) : (
                                <EntityTable
                                    rows={shownAccounts}
                                    rowKey={(a) => a.id}
                                    identityLabel="Account"
                                    minWidth={560}
                                    identity={(a) => ({
                                        icon: Landmark,
                                        name: a.name,
                                        subline: a.bank_name ?? '—',
                                        extra: a.is_primary ? (
                                            <EntityStatusChip variant="info">
                                                Primary
                                            </EntityStatusChip>
                                        ) : undefined,
                                    })}
                                    hrefFor={(a) =>
                                        `/finance/bank-accounts/${a.id}`
                                    }
                                    columns={accountColumns}
                                    actionsFor={accountActions}
                                    onOpen={(a) =>
                                        router.visit(
                                            `/finance/bank-accounts/${a.id}`,
                                        )
                                    }
                                    onRowContextMenu={(e, a) =>
                                        accountCtx.open(e, a)
                                    }
                                />
                            )}
                        </div>

                        <div className="flex flex-col gap-5">
                            <ListCaption
                                title="Petty cash"
                                caption={`${shownFunds.length} of ${pettyCash.length} shown · ${formatMoney(totals.petty_cash)}`}
                            />
                            {shownFunds.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={Coins}
                                    heading={
                                        pettyCash.length === 0
                                            ? 'No petty-cash funds'
                                            : 'No funds match your search'
                                    }
                                    description={
                                        pettyCash.length === 0
                                            ? 'Petty-cash floats appear here once a fund is created under Banking.'
                                            : 'Clear the search to see every fund.'
                                    }
                                />
                            ) : (
                                <EntityTable
                                    rows={shownFunds}
                                    rowKey={(f) => f.id}
                                    identityLabel="Fund"
                                    minWidth={480}
                                    identity={(f) => ({
                                        icon: Coins,
                                        name: f.name,
                                    })}
                                    hrefFor={(f) =>
                                        `/finance/petty-cash/${f.id}`
                                    }
                                    columns={fundColumns}
                                    actionsFor={fundActions}
                                    onOpen={(f) =>
                                        router.visit(
                                            `/finance/petty-cash/${f.id}`,
                                        )
                                    }
                                    onRowContextMenu={(e, f) =>
                                        fundCtx.open(e, f)
                                    }
                                />
                            )}
                        </div>
                    </div>

                    <div
                        ref={obligationsRef}
                        className="flex scroll-mt-5 flex-col gap-5"
                    >
                        <ListCaption
                            title={`Next ${horizonLabel} — dated obligations`}
                            caption={`${shownObligations.length} of ${obligationTotal} shown`}
                        />
                        {shownObligations.length === 0 ? (
                            <EmptyState
                                icon={CalendarClock}
                                heading={
                                    obligations.length === 0
                                        ? `Nothing due in the next ${horizonLabel}`
                                        : 'No obligations match your search'
                                }
                                description={
                                    obligations.length === 0
                                        ? 'Invoice, bill, payment-run, payroll and GST due dates will appear here.'
                                        : 'Clear the search to see every dated obligation.'
                                }
                            />
                        ) : (
                            <EntityTable
                                rows={shownObligations}
                                rowKey={(item) => item.id}
                                identityLabel="Obligation"
                                minWidth={980}
                                identity={(item) => ({
                                    icon: CalendarClock,
                                    name: item.title,
                                    subline: item.ref ?? undefined,
                                })}
                                columns={obligationColumns}
                                actionsFor={obligationActions}
                                onOpen={(item) => {
                                    if (item.link) router.visit(item.link);
                                }}
                                onRowContextMenu={(e, item) => {
                                    if (item.link) obligationCtx.open(e, item);
                                }}
                            />
                        )}
                    </div>
                </div>
            </PageLayout>

            {accountCtx.ctx ? (
                <EntityContextMenu
                    x={accountCtx.ctx.x}
                    y={accountCtx.ctx.y}
                    icon={Landmark}
                    title={accountCtx.ctx.record.name}
                    items={accountActions(accountCtx.ctx.record)}
                    onClose={accountCtx.close}
                />
            ) : null}
            {fundCtx.ctx ? (
                <EntityContextMenu
                    x={fundCtx.ctx.x}
                    y={fundCtx.ctx.y}
                    icon={Coins}
                    title={fundCtx.ctx.record.name}
                    items={fundActions(fundCtx.ctx.record)}
                    onClose={fundCtx.close}
                />
            ) : null}
            {obligationCtx.ctx ? (
                <EntityContextMenu
                    x={obligationCtx.ctx.x}
                    y={obligationCtx.ctx.y}
                    icon={CalendarClock}
                    title={obligationCtx.ctx.record.title}
                    items={obligationActions(obligationCtx.ctx.record)}
                    onClose={obligationCtx.close}
                />
            ) : null}
        </AppLayout>
    );
}
