import { ConfirmDialog, FinanceSectionRail } from '@/components/finance';
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
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Coins, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { CurrencyDialog, type Currency } from './_dialogs';

type PageProps = {
    currencies: Currency[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'General ledger', href: '/finance/ledger' },
    { title: 'Currencies', href: '/finance/currencies' },
];

const ACTIVE_OPTIONS = [
    { value: 'all', label: 'All currencies' },
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
];

export default function CurrenciesIndex({ currencies }: PageProps) {
    const [search, setSearch] = useState('');
    const [activeFilter, setActiveFilter] = useState('all');
    const [createOpen, setCreateOpen] = useState(false);
    const [editTarget, setEditTarget] = useState<Currency | null>(null);
    const [deleteTarget, setDeleteTarget] = useState<Currency | null>(null);
    const [deleting, setDeleting] = useState(false);

    const ctx = useEntityContextMenu<Currency>();

    const confirmDelete = () => {
        if (!deleteTarget) return;
        router.delete(`/finance/currencies/${deleteTarget.id}`, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
            onSuccess: () => setDeleteTarget(null),
        });
    };

    const activeCurrencies = currencies.filter((c) => c.is_active);
    const baseCurrency = currencies.find((c) => c.is_base);
    const staleRates = currencies.filter(
        (c) => !c.is_base && !c.rate_updated_at,
    ).length;

    const query = search.trim().toLowerCase();
    const shown = currencies.filter((currency) => {
        const matchesText =
            query === '' ||
            currency.code.toLowerCase().includes(query) ||
            currency.name.toLowerCase().includes(query);
        const matchesActive =
            activeFilter === 'all' ||
            (activeFilter === 'active'
                ? currency.is_active
                : !currency.is_active);
        return matchesText && matchesActive;
    });

    const actionsFor = (currency: Currency): MenuItem[] => {
        const items: MenuItem[] = [
            {
                label: 'Edit currency',
                icon: Pencil,
                onClick: () => setEditTarget(currency),
            },
        ];
        // The base currency anchors every rate — it can't be deleted.
        if (!currency.is_base) {
            items.push({
                label: 'Delete currency',
                icon: Trash2,
                danger: true,
                onClick: () => setDeleteTarget(currency),
            });
        }
        return items;
    };

    const columns: EntityTableColumn<Currency>[] = [
        {
            key: 'symbol',
            label: 'Symbol',
            width: '100px',
            cell: (currency) => (
                <span className="text-muted-foreground">
                    {currency.symbol}
                </span>
            ),
        },
        {
            key: 'rate',
            label: 'Rate to NZD',
            width: '160px',
            align: 'right',
            cell: (currency) => (
                <span className="font-semibold tabular-nums">
                    {Number(currency.exchange_rate).toFixed(6)}
                </span>
            ),
        },
        {
            key: 'rate_updated',
            label: 'Rate updated',
            width: '190px',
            cell: (currency) =>
                currency.rate_updated_at ? (
                    <span className="whitespace-nowrap text-muted-foreground">
                        {formatDateTime(currency.rate_updated_at)}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '130px',
            cell: (currency) => (
                <StatusBadge
                    variant={currency.is_active ? 'success' : 'neutral'}
                >
                    {currency.is_active ? 'Active' : 'Inactive'}
                </StatusBadge>
            ),
        },
    ];

    const header = (
        <PageHeader
            icon={Coins}
            title="Currencies"
            titleChip={
                <PageHeaderStatusChip variant="info">
                    Base {baseCurrency ? baseCurrency.code : 'not set'}
                </PageHeaderStatusChip>
            }
            subline={`General ledger · ${currencies.length} currencies · ${activeCurrencies.length} active for new transactions`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search code or name…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setCreateOpen(true)}
                    >
                        New currency
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Currencies"
                        href="/finance/currencies"
                        ariaLabel="View every currency"
                    >
                        <PageHeaderMeterBig>
                            {currencies.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            available to invoices, bills and bank accounts
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Active"
                        tone="success"
                        ariaLabel="Show only active currencies"
                        onClick={() => setActiveFilter('active')}
                    >
                        <PageHeaderMeterDonut
                            percent={
                                currencies.length === 0
                                    ? 0
                                    : (activeCurrencies.length /
                                          currencies.length) *
                                      100
                            }
                            caption={`${activeCurrencies.length} of ${currencies.length} in use`}
                        />
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Base currency"
                        ariaLabel="View the base currency"
                        href="/finance/currencies"
                    >
                        <PageHeaderMeterBig>
                            {baseCurrency ? baseCurrency.code : 'Not set'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {baseCurrency
                                ? `${baseCurrency.name} (${baseCurrency.symbol})`
                                : 'set a base currency to anchor every rate'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Rates never updated"
                        tone={staleRates > 0 ? 'warning' : 'brand'}
                        href="/finance/fx-revaluations"
                        ariaLabel="View FX revaluations"
                    >
                        <PageHeaderMeterBig>{staleRates}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            still on their entered rate
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
                    onChange={setActiveFilter}
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Currencies" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Currencies"
                        caption={`${shown.length} of ${currencies.length} shown`}
                    />

                    {shown.length === 0 ? (
                        <EmptyState
                            icon={Coins}
                            heading={
                                currencies.length === 0
                                    ? 'No currencies yet'
                                    : 'No currencies match your search'
                            }
                            description={
                                currencies.length === 0
                                    ? 'Add your first currency to enable multi-currency invoices, bills and bank accounts.'
                                    : 'Clear the search or the active-state filter to see every currency.'
                            }
                            action={
                                currencies.length === 0 ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        New currency
                                    </Button>
                                ) : (
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
                                )
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={shown}
                            rowKey={(currency) => currency.id}
                            identityLabel="Currency"
                            minWidth={900}
                            identity={(currency) => ({
                                icon: Coins,
                                name: currency.code,
                                subline: currency.name,
                                extra: currency.is_base ? (
                                    <EntityStatusChip variant="info">
                                        Base
                                    </EntityStatusChip>
                                ) : undefined,
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            mutedFor={(currency) => !currency.is_active}
                            onOpen={(currency) => setEditTarget(currency)}
                            onRowContextMenu={(e, currency) =>
                                ctx.open(e, currency)
                            }
                        />
                    )}
                </div>
            </PageLayout>

            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={Coins}
                    title={`${ctx.ctx.record.code} — ${ctx.ctx.record.name}`}
                    items={actionsFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            ) : null}

            <CurrencyDialog
                open={createOpen}
                onClose={() => setCreateOpen(false)}
            />

            {editTarget ? (
                <CurrencyDialog
                    key={editTarget.id}
                    open
                    currency={editTarget}
                    onClose={() => setEditTarget(null)}
                />
            ) : null}

            <ConfirmDialog
                open={!!deleteTarget}
                onClose={() => setDeleteTarget(null)}
                title="Delete currency?"
                description={
                    <>
                        This permanently deletes the currency{' '}
                        <span className="font-medium text-foreground">
                            {deleteTarget?.code} — {deleteTarget?.name}
                        </span>
                        . This can&rsquo;t be undone.
                    </>
                }
                confirmText="Delete currency"
                variant="destructive"
                processing={deleting}
                onConfirm={confirmDelete}
            />
        </AppLayout>
    );
}
