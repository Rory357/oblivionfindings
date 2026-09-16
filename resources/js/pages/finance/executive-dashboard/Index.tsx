import { FinanceSectionRail } from '@/components/finance';
import { FinancePeriodFilter } from '@/components/finance/finance-period-filter';
import { formatMoney } from '@/components/finance/money';
import {
    EmptyValue,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
    compactMenu,
    useEntityContextMenu,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDelta,
    PageHeaderMeterDonut,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    TrendingUp,
    Users,
    Wallet,
} from 'lucide-react';
import { useRef, useState } from 'react';

type Insight = {
    type: string;
    severity: string;
    message: string;
    data: Record<string, unknown>;
};
type SiteSummary = {
    site_id: number;
    site_name: string;
    total_cost: string;
    cost_per_resident: string;
    avg_residents: string;
    staffing: {
        wages: string;
        employer_oncost: string;
        total_staffing_cost: string;
        oncost_pct_of_wages: string;
    };
};

type Outlier = {
    client_id: number;
    client_name: string;
    total_cost: string;
    weekly_gap: string;
    is_underfunded: boolean;
};

type Props = {
    kpis: {
        period: { from: string; to: string };
        site_kpis: {
            avg_cost_per_resident: string;
            total_cost: string;
            cost_trend_pct: string;
            highest_cost_site: {
                site_name: string;
                cost_per_resident: string;
            } | null;
            underfunded_count?: number;
            sites_ranked: Array<{
                site_id: number;
                site_name: string;
                total_cost: string;
                cost_per_resident: string;
            }>;
        };
        client_kpis: {
            client_count: number;
            avg_client_cost: string;
            highest_cost_client: {
                client_name: string;
                total_cost: string;
            } | null;
            underfunded_count: number;
            top_outliers: Outlier[];
        };
        staffing_kpis: {
            total_wages: string;
            total_employer_oncost: string;
            total_staffing_cost: string;
            oncost_pct_of_wages: string;
            staffing_pct_of_total_cost: string;
        };
    };
    insights: Insight[];
    /** Org-wide count of sites carrying an over-budget insight (not page-local). */
    overBudgetSiteCount: number;
    siteSummaries: { sites: SiteSummary[] };
    filters: { from: string; to: string };
};

const $ = (v: string | number) => formatMoney(Number(v));
const pct = (v: string | number) => `${Number(v).toFixed(1)}%`;

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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Overview', href: '/finance' },
    { title: 'Executive', href: '/finance/executive-dashboard' },
];

export default function ExecutiveFinancialDashboard({
    kpis,
    insights,
    overBudgetSiteCount,
    siteSummaries,
    filters,
}: Props) {
    const { site_kpis, client_kpis, staffing_kpis } = kpis;
    const [search, setSearch] = useState('');
    const outliersRef = useRef<HTMLDivElement>(null);

    const insightCtx = useEntityContextMenu<Insight>();
    const siteCtx = useEntityContextMenu<SiteSummary>();
    const clientCtx = useEntityContextMenu<Outlier>();

    const query = search.trim().toLowerCase();
    const matches = (...parts: (string | null | undefined)[]) =>
        query === '' ||
        parts.some((p) => (p ?? '').toLowerCase().includes(query));

    const allSites = siteSummaries.sites.slice(0, 10);
    const allOutliers = client_kpis.top_outliers.slice(0, 10);
    const sites = allSites.filter((s) => matches(s.site_name));
    const outliers = allOutliers.filter((c) => matches(c.client_name));
    const shownInsights = insights.filter((i) =>
        matches(i.message, insightLabel(i.type)),
    );

    const costTrend = Number(site_kpis.cost_trend_pct);

    /* ---------------- Row actions ---------------- */

    const siteHref = (id: number) => `/finance/sites/${id}/financial-dashboard`;
    const clientHref = (id: number) => `/finance/clients/${id}/financials`;

    const insightActions = (insight: Insight): MenuItem[] =>
        compactMenu([
            typeof insight.data?.site_id === 'number'
                ? {
                      label: 'Open site dashboard',
                      icon: Building2,
                      onClick: () =>
                          router.visit(
                              siteHref(insight.data.site_id as number),
                          ),
                  }
                : null,
            typeof insight.data?.client_id === 'number'
                ? {
                      label: 'Open client financials',
                      icon: Users,
                      onClick: () =>
                          router.visit(
                              clientHref(insight.data.client_id as number),
                          ),
                  }
                : null,
        ]);

    const siteActions = (site: SiteSummary): MenuItem[] => [
        {
            label: 'Open site dashboard',
            icon: Building2,
            onClick: () => router.visit(siteHref(site.site_id)),
        },
    ];

    const clientActions = (client: Outlier): MenuItem[] => [
        {
            label: 'Open client financials',
            icon: Users,
            onClick: () => router.visit(clientHref(client.client_id)),
        },
    ];

    /* ---------------- Columns ---------------- */

    const insightColumns: EntityTableColumn<Insight>[] = [
        {
            key: 'severity',
            label: 'Severity',
            width: '140px',
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

    const siteColumns: EntityTableColumn<SiteSummary>[] = [
        {
            key: 'total',
            label: 'Total cost',
            width: '150px',
            align: 'right',
            cell: (s) => (
                <span className="font-semibold tabular-nums">
                    {$(s.total_cost)}
                </span>
            ),
        },
        {
            key: 'per_resident',
            label: 'Per resident',
            width: '150px',
            align: 'right',
            cell: (s) => (
                <span className="tabular-nums">{$(s.cost_per_resident)}</span>
            ),
        },
    ];

    const clientColumns: EntityTableColumn<Outlier>[] = [
        {
            key: 'funding',
            label: 'Funding',
            width: '150px',
            cell: (c) =>
                c.is_underfunded ? (
                    <EntityStatusChip variant="critical">
                        Underfunded
                    </EntityStatusChip>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'total',
            label: 'Total cost',
            width: '150px',
            align: 'right',
            cell: (c) => (
                <span className="font-semibold tabular-nums">
                    {$(c.total_cost)}
                </span>
            ),
        },
        {
            key: 'gap',
            label: 'Weekly gap',
            width: '140px',
            align: 'right',
            cell: (c) => (
                <span
                    className={
                        Number(c.weekly_gap) > 0
                            ? 'font-semibold text-status-critical tabular-nums'
                            : 'tabular-nums'
                    }
                >
                    {$(c.weekly_gap)}
                </span>
            ),
        },
    ];

    /* ---------------- Event Horizon header ---------------- */

    const header = (
        <PageHeader
            icon={TrendingUp}
            title="Executive financial dashboard"
            titleChip={
                <PageHeaderStatusChip
                    variant={
                        client_kpis.underfunded_count > 0
                            ? 'critical'
                            : 'success'
                    }
                >
                    {client_kpis.underfunded_count} underfunded
                </PageHeaderStatusChip>
            }
            subline={`Cost, funding and staffing risk · ${site_kpis.sites_ranked.length} ${
                site_kpis.sites_ranked.length === 1 ? 'site' : 'sites'
            } · ${client_kpis.client_count} ${
                client_kpis.client_count === 1 ? 'client' : 'clients'
            }`}
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search sites, clients, risks…"
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
                            {$(site_kpis.total_cost)}
                        </PageHeaderMeterBig>
                        {Number.isFinite(costTrend) ? (
                            <PageHeaderMeterDelta
                                trend={costTrend >= 0 ? 'up' : 'down'}
                                good={costTrend < 0}
                            >
                                {pct(Math.abs(costTrend))} vs previous period
                            </PageHeaderMeterDelta>
                        ) : (
                            <PageHeaderMeterCaption>
                                vs previous period
                            </PageHeaderMeterCaption>
                        )}
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Underfunded clients"
                        tone={
                            client_kpis.underfunded_count > 0
                                ? 'critical'
                                : 'success'
                        }
                        ariaLabel="Jump to the client cost outliers list"
                        onClick={() =>
                            outliersRef.current?.scrollIntoView({
                                block: 'start',
                            })
                        }
                    >
                        <PageHeaderMeterBig>
                            {client_kpis.underfunded_count}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            of {client_kpis.client_count}{' '}
                            {client_kpis.client_count === 1
                                ? 'client'
                                : 'clients'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Over-budget sites"
                        tone={overBudgetSiteCount > 0 ? 'warning' : 'success'}
                        href="/finance/sites"
                        ariaLabel="View cost and budget by site"
                    >
                        <PageHeaderMeterBig>
                            {overBudgetSiteCount}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            past their category budget
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Staffing cost"
                        value={$(staffing_kpis.total_staffing_cost)}
                        href="/finance/reports/profit-loss"
                        ariaLabel="View staffing cost in the profit and loss report"
                    >
                        <PageHeaderMeterDonut
                            percent={Number(
                                staffing_kpis.staffing_pct_of_total_cost,
                            )}
                            caption={
                                <>
                                    of total cost
                                    <br />
                                    oncosts{' '}
                                    {pct(staffing_kpis.oncost_pct_of_wages)} of
                                    wages
                                </>
                            }
                        />
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <FinancePeriodFilter
                    url="/finance/executive-dashboard"
                    from={filters.from}
                    to={filters.to}
                    idPrefix="executive-period"
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Executive financial dashboard" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {insights.length > 0 ? (
                        <div className="flex flex-col gap-5">
                            <ListCaption
                                title="Top risks and issues"
                                caption={`${shownInsights.length} of ${insights.length} shown`}
                            />
                            {shownInsights.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={AlertTriangle}
                                    heading="No risks match your search"
                                    description="Clear the search to see every open risk."
                                />
                            ) : (
                                <EntityTable
                                    rows={shownInsights}
                                    rowKey={(i) => `${i.type}-${i.message}`}
                                    identityLabel="Risk"
                                    identityWidth="3fr"
                                    minWidth={640}
                                    identity={(i) => ({
                                        icon: AlertTriangle,
                                        name: insightLabel(i.type),
                                        subline: i.message,
                                    })}
                                    columns={insightColumns}
                                    actionsFor={insightActions}
                                    onOpen={(i) => {
                                        if (typeof i.data?.site_id === 'number')
                                            router.visit(
                                                siteHref(
                                                    i.data.site_id as number,
                                                ),
                                            );
                                    }}
                                    onRowContextMenu={(e, i) => {
                                        if (insightActions(i).length > 0)
                                            insightCtx.open(e, i);
                                    }}
                                />
                            )}
                        </div>
                    ) : null}

                    <div className="grid grid-cols-1 gap-5 xl:grid-cols-2">
                        <div className="flex flex-col gap-5">
                            <ListCaption
                                title="Sites by cost"
                                caption={`${sites.length} of ${siteSummaries.sites.length} shown`}
                            />
                            {sites.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={Building2}
                                    heading={
                                        siteSummaries.sites.length === 0
                                            ? 'No site cost data'
                                            : 'No sites match your search'
                                    }
                                    description={
                                        siteSummaries.sites.length === 0
                                            ? 'Costs appear here once journals post against site cost centres for the period.'
                                            : 'Clear the search to see every site.'
                                    }
                                />
                            ) : (
                                <EntityTable
                                    rows={sites}
                                    rowKey={(s) => s.site_id}
                                    identityLabel="Site"
                                    minWidth={620}
                                    identity={(s) => ({
                                        icon: Building2,
                                        name: s.site_name,
                                        subline: `${s.avg_residents} avg residents`,
                                    })}
                                    hrefFor={(s) => siteHref(s.site_id)}
                                    columns={siteColumns}
                                    actionsFor={siteActions}
                                    onOpen={(s) =>
                                        router.visit(siteHref(s.site_id))
                                    }
                                    onRowContextMenu={(e, s) =>
                                        siteCtx.open(e, s)
                                    }
                                />
                            )}
                        </div>

                        <div
                            ref={outliersRef}
                            className="flex scroll-mt-5 flex-col gap-5"
                        >
                            <ListCaption
                                title="Client cost outliers"
                                caption={`${outliers.length} of ${client_kpis.top_outliers.length} shown`}
                            />
                            {outliers.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={Users}
                                    heading={
                                        client_kpis.top_outliers.length === 0
                                            ? 'No client cost data'
                                            : 'No clients match your search'
                                    }
                                    description={
                                        client_kpis.top_outliers.length === 0
                                            ? 'Client cost outliers appear once allocated costs post for the period.'
                                            : 'Clear the search to see every client.'
                                    }
                                />
                            ) : (
                                <EntityTable
                                    rows={outliers}
                                    rowKey={(c) => c.client_id}
                                    identityLabel="Client"
                                    minWidth={680}
                                    identity={(c) => ({
                                        icon: Wallet,
                                        name: c.client_name,
                                    })}
                                    hrefFor={(c) => clientHref(c.client_id)}
                                    columns={clientColumns}
                                    actionsFor={clientActions}
                                    onOpen={(c) =>
                                        router.visit(clientHref(c.client_id))
                                    }
                                    onRowContextMenu={(e, c) =>
                                        clientCtx.open(e, c)
                                    }
                                />
                            )}
                        </div>
                    </div>
                </div>
            </PageLayout>

            {insightCtx.ctx ? (
                <EntityContextMenu
                    x={insightCtx.ctx.x}
                    y={insightCtx.ctx.y}
                    icon={AlertTriangle}
                    title={insightLabel(insightCtx.ctx.record.type)}
                    items={insightActions(insightCtx.ctx.record)}
                    onClose={insightCtx.close}
                />
            ) : null}
            {siteCtx.ctx ? (
                <EntityContextMenu
                    x={siteCtx.ctx.x}
                    y={siteCtx.ctx.y}
                    icon={Building2}
                    title={siteCtx.ctx.record.site_name}
                    items={siteActions(siteCtx.ctx.record)}
                    onClose={siteCtx.close}
                />
            ) : null}
            {clientCtx.ctx ? (
                <EntityContextMenu
                    x={clientCtx.ctx.x}
                    y={clientCtx.ctx.y}
                    icon={Wallet}
                    title={clientCtx.ctx.record.client_name}
                    items={clientActions(clientCtx.ctx.record)}
                    onClose={clientCtx.close}
                />
            ) : null}
        </AppLayout>
    );
}
