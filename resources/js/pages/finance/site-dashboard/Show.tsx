import { FinanceSectionRail } from '@/components/finance';
import { chartColor } from '@/components/finance/chart-palette';
import { FinancePeriodFilter } from '@/components/finance/finance-period-filter';
import { formatMoney } from '@/components/finance/money';
import {
    EntityStatusChip,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageLayout,
    StatTile,
} from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Building2,
    PieChart as PieChartIcon,
    Scale,
    TrendingUp,
    Users,
    Wallet,
} from 'lucide-react';
import { useRef } from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type Insight = {
    type: string;
    severity: string;
    message: string;
    data: Record<string, unknown>;
};
type VarianceLine = {
    category: string;
    label: string;
    planned: string;
    actual: string;
    variance: string;
    variance_pct: string;
    status: string;
};

type Props = {
    site: { id: number; name: string; type: string };
    dashboard: {
        hero_cards: {
            total_cost: string;
            cost_per_resident: string;
            avg_residents: string;
        };
        staffing: {
            wages: string;
            employer_oncost: string;
            total_staffing_cost: string;
            oncost_pct_of_wages: string;
            staffing_pct_of_total: string;
        };
        breakdown: {
            categories: Record<
                string,
                { amount: string; label: string; count: number }
            >;
            chart: Array<{ label: string; value: number; type: string }>;
        };
        trend: {
            monthly_cost: Record<string, string>;
            cost_per_resident: Record<
                string,
                {
                    total_cost: string;
                    avg_residents: string;
                    cost_per_resident: string;
                }
            >;
        };
    };
    variance: {
        lines: VarianceLine[];
        totals: {
            planned: string;
            actual: string;
            variance: string;
            variance_pct: string;
            status: string;
        };
    };
    insights: Insight[];
    filters: { from: string; to: string };
};

const $ = (v: string | number) => formatMoney(Number(v));
const pct = (v: string | number) => `${Number(v).toFixed(1)}%`;

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

const INSIGHT_LABEL: Record<string, string> = {
    cost_increase: 'Cost increase',
    underfunded_client: 'Underfunded client',
    utility_trend: 'Utility trend',
    over_budget: 'Over budget',
    approaching_budget: 'Approaching budget',
    forecast_overrun: 'Forecast overrun',
};

const insightLabel = (type: string) =>
    INSIGHT_LABEL[type] ??
    type.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

const severityVariant = (severity: string): 'critical' | 'warning' | 'info' =>
    severity === 'critical'
        ? 'critical'
        : severity === 'warning'
          ? 'warning'
          : 'info';

export default function SiteFinancialDashboard({
    site,
    dashboard,
    variance,
    insights,
    filters,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Overview', href: '/finance' },
        { title: 'By site', href: '/finance/sites' },
        { title: site.name },
    ];

    const staffingRef = useRef<HTMLDivElement>(null);
    const budgetRef = useRef<HTMLDivElement>(null);

    const trendData = Object.entries(dashboard.trend.monthly_cost).map(
        ([month, cost]) => ({
            month: month.slice(5), // 'MM' from 'YYYY-MM'
            cost: Number(cost),
        }),
    );

    const cprTrendData = Object.entries(dashboard.trend.cost_per_resident).map(
        ([month, d]) => ({
            month: month.slice(5),
            cpr: Number(d.cost_per_resident),
        }),
    );

    const planned = Number(variance.totals.planned);
    const actual = Number(variance.totals.actual);
    const budgetUsedPct = planned > 0 ? (actual / planned) * 100 : 0;
    const remaining = planned - actual;

    const varianceColumns: EntityTableColumn<VarianceLine>[] = [
        {
            key: 'planned',
            label: 'Planned',
            width: '130px',
            align: 'right',
            cell: (line) => (
                <span className="tabular-nums">{$(line.planned)}</span>
            ),
        },
        {
            key: 'actual',
            label: 'Actual',
            width: '130px',
            align: 'right',
            cell: (line) => (
                <span className="font-semibold tabular-nums">
                    {$(line.actual)}
                </span>
            ),
        },
        {
            key: 'variance',
            label: 'Variance',
            width: '150px',
            align: 'right',
            cell: (line) => (
                <span
                    className={
                        Number(line.variance) > 0
                            ? 'font-semibold text-status-critical tabular-nums'
                            : 'font-semibold text-status-success tabular-nums'
                    }
                >
                    {$(line.variance)} · {pct(line.variance_pct)}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '150px',
            align: 'right',
            cell: (line) => (
                <EntityStatusChip variant={budgetVariant(line.status)}>
                    {budgetLabel(line.status)}
                </EntityStatusChip>
            ),
        },
    ];

    const insightColumns: EntityTableColumn<Insight>[] = [
        {
            key: 'severity',
            label: 'Severity',
            width: '160px',
            align: 'right',
            cell: (i) => (
                <EntityStatusChip variant={severityVariant(i.severity)}>
                    {i.severity === 'critical'
                        ? 'Critical'
                        : i.severity === 'warning'
                          ? 'Warning'
                          : 'For information'}
                </EntityStatusChip>
            ),
        },
    ];

    /* ---------------- Event Horizon header ---------------- */

    const header = (
        <PageHeader
            variant="profile"
            backHref="/finance/sites"
            icon={Building2}
            title={site.name}
            titleChip={
                <PageHeaderStatusChip
                    variant={budgetVariant(variance.totals.status)}
                >
                    {budgetLabel(variance.totals.status)}
                </PageHeaderStatusChip>
            }
            subline={`Site financials · ${dashboard.hero_cards.avg_residents} avg residents · ${pct(
                dashboard.staffing.staffing_pct_of_total,
            )} staffing`}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total cost"
                        href="/finance/sites"
                        ariaLabel="Compare this site with every other site"
                    >
                        <PageHeaderMeterBig>
                            {$(dashboard.hero_cards.total_cost)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {pct(dashboard.staffing.staffing_pct_of_total)}{' '}
                            staffing
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Cost per resident"
                        href="/finance/executive-dashboard"
                        ariaLabel="View cost per resident across the organisation"
                    >
                        <PageHeaderMeterBig>
                            {$(dashboard.hero_cards.cost_per_resident)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {dashboard.hero_cards.avg_residents} avg residents
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Staffing cost"
                        ariaLabel="Jump to the staffing cost breakdown"
                        onClick={() =>
                            staffingRef.current?.scrollIntoView({
                                block: 'start',
                            })
                        }
                    >
                        <PageHeaderMeterBig>
                            {$(dashboard.staffing.total_staffing_cost)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            oncosts{' '}
                            {pct(dashboard.staffing.oncost_pct_of_wages)} of
                            wages
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Budget"
                        value={planned > 0 ? `of ${$(planned)}` : undefined}
                        tone={
                            variance.totals.status === 'over_budget'
                                ? 'critical'
                                : variance.totals.status === 'approaching'
                                  ? 'warning'
                                  : 'success'
                        }
                        ariaLabel="Jump to budget versus actual"
                        onClick={() =>
                            budgetRef.current?.scrollIntoView({
                                block: 'start',
                            })
                        }
                    >
                        <PageHeaderMeterBig>{$(actual)}</PageHeaderMeterBig>
                        <PageHeaderMeterBar percent={budgetUsedPct} />
                        <PageHeaderMeterCaption>
                            {planned <= 0
                                ? 'no budget set for this period'
                                : remaining >= 0
                                  ? `${$(remaining)} left of ${$(planned)}`
                                  : `${$(Math.abs(remaining))} over`}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <FinancePeriodFilter
                    url={`/finance/sites/${site.id}/financial-dashboard`}
                    from={filters.from}
                    to={filters.to}
                    idPrefix="site-period"
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${site.name} — finance`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {insights.length > 0 ? (
                        <div className="flex flex-col gap-5">
                            <ListCaption
                                title="Alerts and insights"
                                caption={`${insights.length} open`}
                            />
                            <EntityTable
                                rows={insights}
                                rowKey={(i) => `${i.type}-${i.message}`}
                                identityLabel="Alert"
                                identityWidth="3fr"
                                minWidth={640}
                                identity={(i) => ({
                                    icon: AlertTriangle,
                                    name: insightLabel(i.type),
                                    subline: i.message,
                                })}
                                columns={insightColumns}
                                actionsFor={() => []}
                            />
                        </div>
                    ) : null}

                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Cost breakdown</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {dashboard.breakdown.chart.length > 0 ? (
                                    <>
                                        <ResponsiveContainer
                                            width="100%"
                                            height={280}
                                        >
                                            <PieChart>
                                                <Pie
                                                    data={
                                                        dashboard.breakdown
                                                            .chart
                                                    }
                                                    cx="50%"
                                                    cy="50%"
                                                    innerRadius={60}
                                                    outerRadius={100}
                                                    dataKey="value"
                                                    nameKey="label"
                                                    paddingAngle={2}
                                                >
                                                    {dashboard.breakdown.chart.map(
                                                        (_, idx) => (
                                                            <Cell
                                                                key={idx}
                                                                fill={chartColor(
                                                                    idx,
                                                                )}
                                                            />
                                                        ),
                                                    )}
                                                </Pie>
                                                <Tooltip
                                                    formatter={(v?: number) =>
                                                        $(v ?? 0)
                                                    }
                                                />
                                            </PieChart>
                                        </ResponsiveContainer>
                                        <div className="mt-4 grid grid-cols-2 gap-2">
                                            {dashboard.breakdown.chart.map(
                                                (item, idx) => (
                                                    <div
                                                        key={item.type}
                                                        className="flex items-center gap-2 text-xs"
                                                    >
                                                        <span
                                                            aria-hidden="true"
                                                            className="h-2.5 w-2.5 shrink-0 rounded-full"
                                                            style={{
                                                                backgroundColor:
                                                                    chartColor(
                                                                        idx,
                                                                    ),
                                                            }}
                                                        />
                                                        <span className="truncate text-muted-foreground">
                                                            {item.label}
                                                        </span>
                                                        <span className="ml-auto font-medium tabular-nums">
                                                            {$(item.value)}
                                                        </span>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    </>
                                ) : (
                                    <EmptyState
                                        variant="compact"
                                        icon={PieChartIcon}
                                        heading="No cost data for this period"
                                        description="Costs appear once journals post against this site's cost centres."
                                    />
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Cost trend · 6 months</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {trendData.length > 1 ? (
                                    <ResponsiveContainer
                                        width="100%"
                                        height={280}
                                    >
                                        <AreaChart data={trendData}>
                                            <defs>
                                                <linearGradient
                                                    id="costGradient"
                                                    x1="0"
                                                    y1="0"
                                                    x2="0"
                                                    y2="1"
                                                >
                                                    <stop
                                                        offset="5%"
                                                        stopColor={chartColor(
                                                            0,
                                                        )}
                                                        stopOpacity={0.15}
                                                    />
                                                    <stop
                                                        offset="95%"
                                                        stopColor={chartColor(
                                                            0,
                                                        )}
                                                        stopOpacity={0}
                                                    />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                className="stroke-muted"
                                            />
                                            <XAxis
                                                dataKey="month"
                                                className="text-xs"
                                            />
                                            <YAxis
                                                tickFormatter={(v) =>
                                                    `$${(v / 1000).toFixed(0)}k`
                                                }
                                                className="text-xs"
                                            />
                                            <Tooltip
                                                formatter={(v?: number) =>
                                                    $(v ?? 0)
                                                }
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="cost"
                                                stroke={chartColor(0)}
                                                fill="url(#costGradient)"
                                                strokeWidth={2}
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                ) : (
                                    <EmptyState
                                        variant="compact"
                                        icon={TrendingUp}
                                        heading="Not enough data for a trend"
                                        description="A trend line needs at least two months of posted cost."
                                    />
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <div
                        ref={staffingRef}
                        className="flex scroll-mt-5 flex-col gap-5"
                    >
                        <ListCaption
                            title="Staffing costs"
                            caption={`${pct(dashboard.staffing.staffing_pct_of_total)} of total site cost`}
                        />
                        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            <StatTile
                                label="Wages"
                                value={$(dashboard.staffing.wages)}
                                icon={Wallet}
                                tone="primary"
                                staticValue
                            />
                            <StatTile
                                label="Employer oncosts"
                                value={$(dashboard.staffing.employer_oncost)}
                                icon={Scale}
                                tone="info"
                                subtitle={`${pct(dashboard.staffing.oncost_pct_of_wages)} of wages`}
                                staticValue
                            />
                            <StatTile
                                label="Total staffing"
                                value={$(
                                    dashboard.staffing.total_staffing_cost,
                                )}
                                icon={Users}
                                tone="info"
                                staticValue
                            />
                            <StatTile
                                label="Share of total cost"
                                value={pct(
                                    dashboard.staffing.staffing_pct_of_total,
                                )}
                                icon={BarChart3}
                                tone="neutral"
                                subtitle={`cost per resident ${$(dashboard.hero_cards.cost_per_resident)}`}
                                trend={cprTrendData.map((d) => d.cpr)}
                                staticValue
                            />
                        </div>
                    </div>

                    <div
                        ref={budgetRef}
                        className="flex scroll-mt-5 flex-col gap-5"
                    >
                        <ListCaption
                            title="Budget vs actual"
                            caption={`${variance.lines.length} ${
                                variance.lines.length === 1
                                    ? 'category'
                                    : 'categories'
                            }`}
                            right={
                                <span className="flex flex-wrap items-center gap-2 text-[12px] text-muted-foreground tabular-nums">
                                    <span>Planned {$(planned)}</span>
                                    <span>·</span>
                                    <span>Actual {$(actual)}</span>
                                    <span>·</span>
                                    <span>
                                        Variance {$(variance.totals.variance)} (
                                        {pct(variance.totals.variance_pct)})
                                    </span>
                                    <EntityStatusChip
                                        variant={budgetVariant(
                                            variance.totals.status,
                                        )}
                                    >
                                        {budgetLabel(variance.totals.status)}
                                    </EntityStatusChip>
                                </span>
                            }
                        />
                        {variance.lines.length === 0 ? (
                            <EmptyState
                                variant="compact"
                                icon={Scale}
                                heading="No budget set for this period"
                                description="Add planned amounts for this site's cost categories to compare them with actuals."
                            />
                        ) : (
                            <EntityTable
                                rows={variance.lines}
                                rowKey={(line) => line.category}
                                identityLabel="Category"
                                minWidth={900}
                                identity={(line) => ({
                                    icon: Scale,
                                    name: line.label,
                                })}
                                columns={varianceColumns}
                                actionsFor={() => []}
                            />
                        )}
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
