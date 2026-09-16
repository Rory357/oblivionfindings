import { FinanceSectionRail } from '@/components/finance';
import { chartColor } from '@/components/finance/chart-palette';
import { FinancePeriodFilter } from '@/components/finance/finance-period-filter';
import { formatMoney } from '@/components/finance/money';
import {
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
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ArrowUpRight, BarChart3, Building2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type Category = {
    key: string;
    label: string;
    amount: string;
    count: number;
};

type SiteRow = {
    site: {
        id: number;
        name: string;
        type: string;
        region?: string | null;
    };
    total_cost: string;
    budget: {
        planned: string;
        actual: string;
        variance: string;
        variance_pct: string;
        status: string;
    };
    top_category: Category | null;
    categories: Category[];
    trend: Array<{ month: string; amount: string }>;
    dashboard_url: string;
};

type Props = {
    filters: {
        from: string;
        to: string;
    };
    kpis: {
        total_cost: string;
        sites_over_budget: number;
        site_count: number;
        avg_cost_per_site: string;
        period: {
            from: string;
            to: string;
        };
        top_spenders: SiteRow[];
    };
    sites: SiteRow[];
    categoryKeys: Array<{ key: string; label: string }>;
};

type SortKey = 'cost' | 'variance' | 'name' | 'category';

const ALL = 'all';

const money = (value: string | number) => formatMoney(Number(value));
const pct = (value: string | number) => `${Number(value).toFixed(1)}%`;

const BUDGET_LABEL: Record<string, string> = {
    over_budget: 'Over budget',
    approaching: 'Approaching',
    under_budget: 'Under budget',
    on_track: 'On track',
};

const budgetVariant = (
    status: string,
): 'critical' | 'warning' | 'success' | 'neutral' => {
    switch (status) {
        case 'over_budget':
            return 'critical';
        case 'approaching':
            return 'warning';
        case 'under_budget':
            return 'success';
        default:
            return 'neutral';
    }
};

const budgetLabel = (status: string) =>
    BUDGET_LABEL[status] ??
    status.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

const SORT_OPTIONS: { value: SortKey; label: string }[] = [
    { value: 'cost', label: 'Highest cost' },
    { value: 'variance', label: 'Worst budget variance' },
    { value: 'name', label: 'Site name' },
    { value: 'category', label: 'Top category' },
];

const BUDGET_OPTIONS = [
    { value: ALL, label: 'All budgets' },
    { value: 'over_budget', label: 'Over budget' },
    { value: 'approaching', label: 'Approaching' },
    { value: 'under_budget', label: 'Under budget' },
    { value: 'on_track', label: 'On track' },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Overview', href: '/finance' },
    { title: 'By site', href: '/finance/sites' },
];

export default function SitesFinancialOverview({
    filters,
    kpis,
    sites,
    categoryKeys,
}: Props) {
    const [search, setSearch] = useState('');
    const [budget, setBudget] = useState<string>(ALL);
    const [sort, setSort] = useState<SortKey>('cost');

    const ctxMenu = useEntityContextMenu<SiteRow>();

    const visibleSites = useMemo(() => {
        const query = search.trim().toLowerCase();
        const rows = sites.filter((row) => {
            const matchesSearch =
                query === '' ||
                row.site.name.toLowerCase().includes(query) ||
                (row.site.region ?? '').toLowerCase().includes(query) ||
                (row.top_category?.label ?? '').toLowerCase().includes(query);
            const matchesBudget =
                budget === ALL || row.budget.status === budget;

            return matchesSearch && matchesBudget;
        });

        return [...rows].sort((a, b) => {
            if (sort === 'name') {
                return a.site.name.localeCompare(b.site.name);
            }
            if (sort === 'category') {
                return (a.top_category?.label ?? '').localeCompare(
                    b.top_category?.label ?? '',
                );
            }
            if (sort === 'variance') {
                return (
                    Number(b.budget.variance_pct) -
                    Number(a.budget.variance_pct)
                );
            }

            return Number(b.total_cost) - Number(a.total_cost);
        });
    }, [sites, search, budget, sort]);

    const chartData = useMemo(
        () =>
            visibleSites.map((row) => {
                const data: Record<string, string | number> = {
                    site: row.site.name,
                };

                row.categories.forEach((category) => {
                    data[category.key] = Number(category.amount);
                });

                return data;
            }),
        [visibleSites],
    );

    const topSpender = kpis.top_spenders[0] ?? null;

    const actionsFor = (row: SiteRow): MenuItem[] => [
        {
            label: 'Open site dashboard',
            icon: ArrowUpRight,
            onClick: () => router.visit(row.dashboard_url),
        },
    ];

    const columns: EntityTableColumn<SiteRow>[] = [
        {
            key: 'total_cost',
            label: 'Total cost',
            width: '150px',
            align: 'right',
            cell: (row) => (
                <span className="font-semibold tabular-nums">
                    {money(row.total_cost)}
                </span>
            ),
        },
        {
            key: 'budget',
            label: 'vs budget',
            width: '190px',
            align: 'right',
            cell: (row) => (
                <span className="flex items-center justify-end gap-2">
                    <span className="font-semibold tabular-nums">
                        {pct(row.budget.variance_pct)}
                    </span>
                    <EntityStatusChip
                        variant={budgetVariant(row.budget.status)}
                    >
                        {budgetLabel(row.budget.status)}
                    </EntityStatusChip>
                </span>
            ),
        },
        {
            key: 'top_category',
            label: 'Top category',
            width: '1.2fr',
            cell: (row) =>
                row.top_category ? (
                    <span className="min-w-0">
                        <span className="block truncate font-medium">
                            {row.top_category.label}
                        </span>
                        <span className="block text-[11.5px] text-muted-foreground tabular-nums">
                            {money(row.top_category.amount)}
                        </span>
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'trend',
            label: 'Trend',
            width: '140px',
            cell: (row) => (
                <Sparkline
                    values={row.trend.map((point) => Number(point.amount))}
                />
            ),
        },
    ];

    /* ---------------- Event Horizon header ---------------- */

    const header = (
        <PageHeader
            icon={Building2}
            title="By site"
            titleChip={
                <PageHeaderStatusChip
                    variant={kpis.sites_over_budget > 0 ? 'warning' : 'success'}
                >
                    {kpis.sites_over_budget} over budget
                </PageHeaderStatusChip>
            }
            subline={`Cost, budget variance and category mix · ${kpis.site_count} ${
                kpis.site_count === 1 ? 'site' : 'sites'
            } · ${categoryKeys.length} cost ${
                categoryKeys.length === 1 ? 'category' : 'categories'
            }`}
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search sites, regions, categories…"
                />
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total cost"
                        href="/finance/reports/profit-loss"
                        ariaLabel="View the profit and loss report"
                    >
                        <PageHeaderMeterBig>
                            {money(kpis.total_cost)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {kpis.site_count}{' '}
                            {kpis.site_count === 1 ? 'site' : 'sites'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Sites over budget"
                        tone={
                            kpis.sites_over_budget > 0 ? 'warning' : 'success'
                        }
                        ariaLabel="Show only sites that are over budget"
                        onClick={() => setBudget('over_budget')}
                    >
                        <PageHeaderMeterBig>
                            {kpis.sites_over_budget}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            past their category budget
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Avg cost per site"
                        ariaLabel="Show every site in the comparison"
                        onClick={() => setBudget(ALL)}
                    >
                        <PageHeaderMeterBig>
                            {money(kpis.avg_cost_per_site)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            for the selected period
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Sites"
                        href="/finance/executive-dashboard"
                        ariaLabel="View the executive financial dashboard"
                    >
                        <PageHeaderMeterBig>
                            {kpis.site_count}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            houses and facilities in scope
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    {topSpender ? (
                        <PageHeaderMeterBlock
                            label="Top spender"
                            href={topSpender.dashboard_url}
                            ariaLabel={`Open the financial dashboard for ${topSpender.site.name}`}
                        >
                            <PageHeaderMeterBig>
                                {money(topSpender.total_cost)}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {topSpender.site.name}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                </>
            }
            filters={
                <>
                    <FinancePeriodFilter
                        url="/finance/sites"
                        from={filters.from}
                        to={filters.to}
                        idPrefix="by-site-period"
                    />
                    <PageHeaderFilterSelect
                        label="All budgets"
                        value={budget}
                        allValue={ALL}
                        options={BUDGET_OPTIONS}
                        onChange={setBudget}
                    />
                    <PageHeaderFilterSelect
                        label="Sort"
                        value={sort}
                        allValue="cost"
                        options={SORT_OPTIONS}
                        onChange={(v) => setSort(v as SortKey)}
                    />
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Finance by site" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <Card>
                        <CardHeader>
                            <CardTitle>Cost by site and category</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {chartData.length > 0 && categoryKeys.length > 0 ? (
                                <ResponsiveContainer width="100%" height={360}>
                                    <BarChart
                                        data={chartData}
                                        margin={{
                                            top: 12,
                                            right: 16,
                                            left: 8,
                                            bottom: 56,
                                        }}
                                    >
                                        <CartesianGrid
                                            strokeDasharray="3 3"
                                            className="stroke-muted"
                                        />
                                        <XAxis
                                            dataKey="site"
                                            angle={-30}
                                            textAnchor="end"
                                            interval={0}
                                            height={72}
                                            className="text-xs"
                                        />
                                        <YAxis
                                            tickFormatter={(value) =>
                                                `$${Number(value / 1000).toFixed(0)}k`
                                            }
                                            className="text-xs"
                                        />
                                        <Tooltip
                                            formatter={(value) =>
                                                money(Number(value))
                                            }
                                        />
                                        <Legend />
                                        {categoryKeys.map((category, index) => (
                                            <Bar
                                                key={category.key}
                                                dataKey={category.key}
                                                name={category.label}
                                                stackId="cost"
                                                fill={chartColor(index)}
                                            />
                                        ))}
                                    </BarChart>
                                </ResponsiveContainer>
                            ) : (
                                <EmptyState
                                    icon={BarChart3}
                                    heading="No cost data for this period"
                                    description="Costs appear once journals post against site cost centres inside the selected date range."
                                />
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex flex-col gap-5">
                        <ListCaption
                            title="Site comparison"
                            caption={`${visibleSites.length} of ${sites.length} shown`}
                        />
                        {visibleSites.length === 0 ? (
                            <EmptyState
                                icon={Building2}
                                heading={
                                    sites.length === 0
                                        ? 'No sites found for this period'
                                        : 'No sites match your filters'
                                }
                                description={
                                    sites.length === 0
                                        ? 'Sites appear here once they are active and costs post against their cost centres.'
                                        : 'Try clearing the search or the budget filter.'
                                }
                            />
                        ) : (
                            <EntityTable
                                rows={visibleSites}
                                rowKey={(row) => row.site.id}
                                identityLabel="Site"
                                minWidth={980}
                                identity={(row) => ({
                                    icon: Building2,
                                    name: row.site.name,
                                    subline: row.site.region ?? row.site.type,
                                })}
                                hrefFor={(row) => row.dashboard_url}
                                columns={columns}
                                actionsFor={actionsFor}
                                onOpen={(row) =>
                                    router.visit(row.dashboard_url)
                                }
                                onRowContextMenu={(e, row) =>
                                    ctxMenu.open(e, row)
                                }
                            />
                        )}
                    </div>
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Building2}
                    title={ctxMenu.ctx.record.site.name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}
        </AppLayout>
    );
}

function Sparkline({ values }: { values: number[] }) {
    if (values.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    const max = Math.max(...values, 1);
    const points = values
        .map((value, index) => {
            const x =
                values.length === 1 ? 48 : (index / (values.length - 1)) * 96;
            const y = 32 - (value / max) * 28;

            return `${x},${y}`;
        })
        .join(' ');

    return (
        <svg
            viewBox="0 0 96 36"
            className="h-9 w-28"
            aria-label="Cost trend"
            role="img"
        >
            <polyline
                points={points}
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
                className="text-primary"
            />
        </svg>
    );
}
