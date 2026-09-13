import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ClipboardCheck, Eye, Plus, Wallet } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

const nzd = new Intl.NumberFormat('en-NZ', {
    style: 'currency',
    currency: 'NZD',
});

type ClientFund = {
    id: number;
    name: string;
    fund_type: string;
    balance: number;
    available_balance: number;
    reconciliation_status: 'clear' | 'review' | 'mismatch' | string;
    low_balance_threshold: number | null;
    transaction_count: number;
    client: { id: number; first_name: string; last_name: string } | null;
};

type Props = {
    funds: {
        data: ClientFund[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters?: {
        q?: string | null;
        fund_type?: string | null;
        low_balance?: boolean | string | null;
        review?: boolean | string | null;
    };
    stats?: {
        total: number;
        total_balance: number;
        total_available: number;
        clients: number;
        low_balance_alerts: number;
        review_required: number;
    };
};

type ViewKey = 'all' | 'low_balance' | 'review';

const VIEW_PARAMS: Record<ViewKey, Record<string, string>> = {
    all: {},
    low_balance: { low_balance: '1' },
    review: { review: '1' },
};

const FUND_TYPES: Record<string, string> = {
    trust: 'Trust',
    petty_cash: 'Petty cash',
    personal: 'Personal',
    activity: 'Activity',
};

export default function ClientFundsIndex({
    funds = { data: [], links: [], current_page: 1, last_page: 1, total: 0 },
    filters = {},
    stats,
}: Props) {
    const { labels, auth } = usePage().props as any;
    const canManage = Boolean(auth?.can?.client_funds?.manage);
    const clientSingular: string = labels?.['client.singular'] ?? 'Client';
    const clientPlural: string = labels?.['client.plural'] ?? 'Clients';

    const s = stats ?? {
        total: 0,
        total_balance: 0,
        total_available: 0,
        clients: 0,
        low_balance_alerts: 0,
        review_required: 0,
    };

    const view: ViewKey = filters.low_balance
        ? 'low_balance'
        : filters.review
          ? 'review'
          : 'all';

    const [q, setQ] = useState(filters.q ?? '');

    const applyFilters = useCallback(
        (overrides: { view?: ViewKey; q?: string; fund_type?: string }) => {
            const nextView = overrides.view ?? view;
            const nextQ = overrides.q ?? filters.q ?? '';
            const nextType = overrides.fund_type ?? filters.fund_type ?? 'all';
            const params: Record<string, string> = {
                ...VIEW_PARAMS[nextView],
            };
            if (nextQ.trim() !== '') params.q = nextQ.trim();
            if (nextType !== 'all') params.fund_type = nextType;
            router.get('/operations/client-funds', params, {
                preserveState: true,
                replace: true,
            });
        },
        [view, filters.q, filters.fund_type],
    );

    useEffect(() => {
        if ((filters.q ?? '') === q.trim()) return;
        const t = setTimeout(() => applyFilters({ q }), 400);
        return () => clearTimeout(t);
    }, [q, filters.q, applyFilters]);

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        { key: 'all', label: 'All funds', icon: Wallet, count: s.total },
        {
            key: 'low_balance',
            label: 'Low balance',
            icon: AlertTriangle,
            count: s.low_balance_alerts,
            alert: true,
        },
        {
            key: 'review',
            label: 'Needs review',
            icon: ClipboardCheck,
            count: s.review_required,
            alert: true,
        },
    ];

    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ?? 'All funds';

    const hasNarrowing =
        (filters.q ?? '') !== '' || (filters.fund_type ?? 'all') !== 'all';

    const typeOptions = [
        { value: 'all', label: 'All types' },
        ...Object.entries(FUND_TYPES).map(([value, label]) => ({
            value,
            label,
        })),
    ];

    const titleChip =
        s.review_required > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {s.review_required} to review
            </PageHeaderStatusChip>
        ) : s.low_balance_alerts > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {s.low_balance_alerts} low balance
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">
                Reconciled
            </PageHeaderStatusChip>
        );

    const header = (
        <PageHeader
            icon={Wallet}
            title={`${clientSingular} funds`}
            titleChip={titleChip}
            subline={`Trust, petty cash and personal funds · ${s.total} ${
                s.total === 1 ? 'fund' : 'funds'
            } · ${nzd.format(s.total_balance)} held`}
            actions={
                <>
                    <PageHeaderSearch
                        value={q}
                        onChange={setQ}
                        placeholder={`Search funds and ${clientPlural.toLowerCase()}…`}
                    />
                    {canManage ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() =>
                                router.visit('/operations/client-funds/create')
                            }
                        >
                            New fund
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total funds"
                        ariaLabel="View all funds"
                        onClick={() => applyFilters({ view: 'all' })}
                    >
                        <PageHeaderMeterBig>{s.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            for {s.clients}{' '}
                            {s.clients === 1
                                ? clientSingular.toLowerCase()
                                : clientPlural.toLowerCase()}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Total balance"
                        ariaLabel="View all fund balances"
                        onClick={() => applyFilters({ view: 'all' })}
                    >
                        <PageHeaderMeterBig>
                            {nzd.format(s.total_balance)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {nzd.format(s.total_available)} available
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {s.total > 0 ? (
                        <PageHeaderMeterBlock
                            label="Reconciled"
                            ariaLabel="View funds needing reconciliation review"
                            onClick={() => applyFilters({ view: 'review' })}
                        >
                            <PageHeaderMeterDonut
                                percent={
                                    ((s.total - s.review_required) / s.total) *
                                    100
                                }
                                caption={
                                    <>
                                        {s.review_required} need
                                        {s.review_required === 1 ? 's' : ''}
                                        <br />
                                        review
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Low balance"
                        tone={s.low_balance_alerts > 0 ? 'warning' : 'success'}
                        ariaLabel="View funds with low balance"
                        onClick={() => applyFilters({ view: 'low_balance' })}
                    >
                        <PageHeaderMeterBig>
                            {s.low_balance_alerts}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            at or below their threshold
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    icon={Wallet}
                    label="All types"
                    value={filters.fund_type ?? 'all'}
                    options={typeOptions}
                    onChange={(v) => applyFilters({ fund_type: v })}
                />
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={(key) => applyFilters({ view: key })}
                    ariaLabel="Fund views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title: `${clientSingular} funds`,
                    href: '/operations/client-funds',
                },
            ]}
        >
            <Head title={`${clientSingular} funds`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${funds.data.length} of ${funds.total} ${
                            funds.total === 1 ? 'fund' : 'funds'
                        } shown`}
                    />

                    <div className="space-y-2">
                        {funds.data.length === 0 ? (
                            <EmptyState
                                icon={Wallet}
                                title={`No ${clientSingular.toLowerCase()} funds found`}
                                description={
                                    hasNarrowing || view !== 'all'
                                        ? 'Try a different view or clear your filters.'
                                        : `Create your first ${clientSingular.toLowerCase()} fund to get started.`
                                }
                                action={
                                    canManage &&
                                    !hasNarrowing &&
                                    view === 'all' ? (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.visit(
                                                    '/operations/client-funds/create',
                                                )
                                            }
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                            New fund
                                        </Button>
                                    ) : undefined
                                }
                            />
                        ) : (
                            funds.data.map((fund) => {
                                const isLow =
                                    fund.low_balance_threshold !== null &&
                                    fund.balance <= fund.low_balance_threshold;
                                return (
                                    <Card
                                        key={fund.id}
                                        className="transition-all hover:border-border hover:shadow-sm"
                                    >
                                        <CardContent className="flex items-center gap-4 p-4">
                                            <div
                                                className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${
                                                    isLow
                                                        ? 'bg-status-warning-bg text-status-warning'
                                                        : 'bg-primary/10 text-primary'
                                                }`}
                                            >
                                                {isLow ? (
                                                    <AlertTriangle className="h-5 w-5" />
                                                ) : (
                                                    <Wallet className="h-5 w-5" />
                                                )}
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Link
                                                        href={`/operations/client-funds/${fund.id}`}
                                                        className="text-sm font-semibold hover:underline"
                                                    >
                                                        {fund.name}
                                                    </Link>
                                                    <Badge
                                                        variant="outline"
                                                        className="h-4 px-1.5 text-[9px]"
                                                    >
                                                        {FUND_TYPES[
                                                            fund.fund_type
                                                        ] ?? fund.fund_type}
                                                    </Badge>
                                                    {isLow && (
                                                        <StatusBadge variant="warning">
                                                            Low balance
                                                        </StatusBadge>
                                                    )}
                                                    {fund.reconciliation_status ===
                                                        'review' && (
                                                        <StatusBadge variant="warning">
                                                            Review
                                                        </StatusBadge>
                                                    )}
                                                    {fund.reconciliation_status ===
                                                        'mismatch' && (
                                                        <StatusBadge variant="critical">
                                                            Mismatch
                                                        </StatusBadge>
                                                    )}
                                                </div>
                                                <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                                    {fund.client && (
                                                        <span>
                                                            {
                                                                fund.client
                                                                    .first_name
                                                            }{' '}
                                                            {
                                                                fund.client
                                                                    .last_name
                                                            }
                                                        </span>
                                                    )}
                                                    <span
                                                        className={`font-semibold tabular-nums ${
                                                            isLow
                                                                ? 'text-status-critical'
                                                                : 'text-status-success'
                                                        }`}
                                                    >
                                                        {nzd.format(
                                                            fund.balance,
                                                        )}
                                                    </span>
                                                    <span>
                                                        {fund.transaction_count}{' '}
                                                        transactions
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="flex shrink-0 gap-1">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-7 w-7 p-0"
                                                >
                                                    <Link
                                                        href={`/operations/client-funds/${fund.id}`}
                                                        aria-label={`View ${fund.name}`}
                                                    >
                                                        <Eye className="h-3.5 w-3.5" />
                                                    </Link>
                                                </Button>
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })
                        )}
                    </div>

                    {(funds.last_page ?? 1) > 1 && (
                        <div className="flex items-center justify-center gap-1">
                            {(funds.links ?? []).map((link, i) => (
                                <Button
                                    key={i}
                                    size="sm"
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    className="h-7 min-w-[28px] px-2 text-xs"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
