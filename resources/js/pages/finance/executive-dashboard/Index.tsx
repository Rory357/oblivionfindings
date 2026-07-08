import { formatMoney } from '@/components/finance/money';
import { OverviewTabsFooter } from '@/components/finance/overview-hub';
import { PageHero, PageLayout } from '@/components/page';
import { FleetStatCard } from '@/components/fleet-stat-card';
import { Badge } from '@/components/ui/badge';
import { StatusBadge } from '@/components/ui/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Building2,
    DollarSign,
    TrendingDown,
    Users,
} from 'lucide-react';

type Insight = { type: string; severity: string; message: string; data: Record<string, any> };
type SiteSummary = {
    site_id: number;
    site_name: string;
    total_cost: string;
    cost_per_resident: string;
    avg_residents: string;
    staffing: { wages: string; employer_oncost: string; total_staffing_cost: string; oncost_pct_of_wages: string };
};

type Props = {
    kpis: {
        period: { from: string; to: string };
        site_kpis: {
            avg_cost_per_resident: string;
            total_cost: string;
            cost_trend_pct: string;
            highest_cost_site: { site_name: string; cost_per_resident: string } | null;
            underfunded_count?: number;
            sites_ranked: Array<{ site_id: number; site_name: string; total_cost: string; cost_per_resident: string }>;
        };
        client_kpis: {
            client_count: number;
            avg_client_cost: string;
            highest_cost_client: { client_name: string; total_cost: string } | null;
            underfunded_count: number;
            top_outliers: Array<{ client_id: number; client_name: string; total_cost: string; weekly_gap: string; is_underfunded: boolean }>;
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
    siteSummaries: { sites: SiteSummary[] };
    filters: { from: string; to: string };
};

const $ = (v: string | number) => formatMoney(Number(v));
const pct = (v: string | number) => `${Number(v).toFixed(1)}%`;

const severityColor: Record<string, string> = {
    critical: 'bg-status-critical-bg border-status-critical/30 text-status-critical dark:bg-status-critical-bg dark:border-status-critical/30 dark:text-status-critical',
    warning: 'bg-status-warning-bg border-status-warning/30 text-status-warning dark:bg-status-warning-bg dark:border-status-warning/30 dark:text-status-warning',
    info: 'bg-status-info-bg border-status-info/30 text-status-info dark:bg-status-info-bg dark:border-status-info/30 dark:text-status-info',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Executive' },
];

export default function ExecutiveFinancialDashboard({ kpis, insights, siteSummaries, filters }: Props) {
    const { site_kpis, client_kpis, staffing_kpis } = kpis;
    const overBudgetCount = insights.filter(i => i.type === 'over_budget').length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Executive Financial Dashboard" />

            <PageLayout
                width="wide"
                hero={
                    <PageHero
                        category="finance"
                        title="Executive Financial Dashboard"
                        description={`Organisation-wide cost, funding and staffing risk across ${site_kpis.sites_ranked.length} ${site_kpis.sites_ranked.length === 1 ? 'site' : 'sites'} and ${client_kpis.client_count} ${client_kpis.client_count === 1 ? 'client' : 'clients'}.`}
                        icon={<BarChart3 className="h-7 w-7 text-white" />}
                        stats={[
                            { label: 'Total cost', value: $(site_kpis.total_cost) },
                            { label: 'Underfunded clients', value: client_kpis.underfunded_count, tone: client_kpis.underfunded_count > 0 ? 'critical' : undefined },
                            { label: 'Over-budget sites', value: overBudgetCount, tone: overBudgetCount > 0 ? 'warning' : undefined },
                            { label: 'Staffing cost', value: $(staffing_kpis.total_staffing_cost) },
                        ]}
                        footer={<OverviewTabsFooter active="executive" />}
                    />
                }
            >
                {/* Cost-trend context under the hero stats */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <FleetStatCard
                        label="Cost trend"
                        value={pct(site_kpis.cost_trend_pct)}
                        icon={DollarSign}
                        color="purple"
                        subtitle="vs previous period"
                    />
                    <FleetStatCard
                        label="Staffing share"
                        value={pct(staffing_kpis.staffing_pct_of_total_cost)}
                        icon={TrendingDown}
                        color="cyan"
                        subtitle={`oncosts ${pct(staffing_kpis.oncost_pct_of_wages)} of wages`}
                    />
                </div>

                {/* Insights */}
                {insights.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider">Top Risks & Issues</h2>
                        <div className="space-y-2">
                            {insights.map((insight, i) => (
                                <div key={i} className={`flex items-start gap-3 rounded-lg border p-3 ${severityColor[insight.severity] || severityColor.info}`}>
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                    <span className="text-sm">{insight.message}</span>
                                    <StatusBadge
                                        size="sm"
                                        variant={insight.severity === 'critical' ? 'critical' : insight.severity === 'warning' ? 'warning' : 'info'}
                                        label={insight.severity}
                                        className="ml-auto shrink-0"
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Sites + Clients Side by Side */}
                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Highest Cost Sites */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Sites by Cost</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {siteSummaries.sites.length > 0 ? (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Site</TableHead>
                                            <TableHead className="text-right">Total</TableHead>
                                            <TableHead className="text-right">Per Resident</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {siteSummaries.sites.slice(0, 10).map((site) => (
                                            <TableRow key={site.site_id}>
                                                <TableCell>
                                                    <Link href={`/finance/sites/${site.site_id}/financial-dashboard`} className="font-medium text-primary hover:underline">
                                                        {site.site_name}
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">{$(site.total_cost)}</TableCell>
                                                <TableCell className="text-right tabular-nums">{$(site.cost_per_resident)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            ) : (
                                <EmptyState
                                    variant="compact"
                                    icon={Building2}
                                    heading="No site cost data"
                                    description="Costs appear here once journals post against site cost centres for the period."
                                />
                            )}
                        </CardContent>
                    </Card>

                    {/* Highest Cost / Underfunded Clients */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Client Cost Outliers</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {client_kpis.top_outliers.length > 0 ? (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Client</TableHead>
                                            <TableHead className="text-right">Total Cost</TableHead>
                                            <TableHead className="text-right">Weekly Gap</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {client_kpis.top_outliers.slice(0, 10).map((c) => (
                                            <TableRow key={c.client_id}>
                                                <TableCell>
                                                    <Link href={`/finance/clients/${c.client_id}/financials`} className="font-medium text-primary hover:underline">
                                                        {c.client_name}
                                                    </Link>
                                                    {c.is_underfunded && (
                                                        <Badge variant="destructive" className="ml-2 text-[10px]">Underfunded</Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">{$(c.total_cost)}</TableCell>
                                                <TableCell className={`text-right tabular-nums ${Number(c.weekly_gap) > 0 ? 'text-status-critical' : ''}`}>
                                                    {$(c.weekly_gap)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            ) : (
                                <EmptyState
                                    variant="compact"
                                    icon={Users}
                                    heading="No client cost data"
                                    description="Client cost outliers appear once allocated costs post for the period."
                                />
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
