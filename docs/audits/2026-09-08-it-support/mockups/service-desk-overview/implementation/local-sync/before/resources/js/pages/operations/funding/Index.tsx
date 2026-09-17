import {
    DonutChart,
    OPS_COLORS,
    OpsStatCard,
} from '@/components/ops-stat-card';
import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Banknote,
    DollarSign,
    FileText,
    TrendingUp,
} from 'lucide-react';

type Props = {
    stats: {
        total_budget: number;
        total_used: number;
        total_remaining: number;
        utilisation_percent: number;
        active_agreements: number;
        pending_claims: number;
        expiring_soon: number;
    };
    claims_by_status: Record<string, number>;
    top_agreements: Array<{
        id: number;
        title: string;
        client_name: string;
        total_budget: number;
        budget_used: number;
        utilisation_percent: number;
    }>;
};

function formatCurrency(n: number): string {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(n);
}

const CLAIM_COLORS: Record<string, string> = {
    draft: OPS_COLORS.muted,
    submitted: OPS_COLORS.warning,
    approved: OPS_COLORS.primary,
    paid: OPS_COLORS.success,
    rejected: OPS_COLORS.danger,
};

export default function FundingIndex({
    stats = {} as any,
    claims_by_status = {} as any,
    top_agreements = [],
}: Props) {
    const s = stats ?? {
        total_budget: 0,
        total_used: 0,
        total_remaining: 0,
        utilisation_percent: 0,
        active_agreements: 0,
        pending_claims: 0,
        expiring_soon: 0,
    };

    return (
        <AppLayout>
            <Head title="Funding" />
            <PageHero
                icon={Banknote}
                title="Funding Dashboard"
                description="Track budgets, utilisation, and claims across all service agreements."
                stats={[
                    {
                        label: 'Total budget',
                        value: formatCurrency(s.total_budget),
                    },
                    { label: 'Utilised', value: `${s.utilisation_percent}%` },
                    {
                        label: 'Remaining',
                        value: formatCurrency(s.total_remaining),
                    },
                    { label: 'Pending claims', value: s.pending_claims },
                ]}
            />
            <PageShell>
                {/* KPIs */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <OpsStatCard
                        label="Total Budget"
                        value={formatCurrency(s.total_budget)}
                        icon={DollarSign}
                        color="indigo"
                        subtitle={`${s.active_agreements} agreements`}
                    />
                    <OpsStatCard
                        label="Utilised"
                        value={formatCurrency(s.total_used)}
                        icon={TrendingUp}
                        color="blue"
                        subtitle={`${s.utilisation_percent}% used`}
                    />
                    <OpsStatCard
                        label="Remaining"
                        value={formatCurrency(s.total_remaining)}
                        icon={DollarSign}
                        color="emerald"
                        subtitle="Available budget"
                    />
                    <OpsStatCard
                        label="Pending Claims"
                        value={s.pending_claims}
                        icon={FileText}
                        color={s.pending_claims > 0 ? 'amber' : 'slate'}
                        href="/operations/funding/claims"
                    />
                </div>

                {/* Charts */}
                <div className="mt-6 grid gap-4 md:grid-cols-2">
                    {/* Budget Utilisation Gauge */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Budget Utilisation
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-col items-center gap-4">
                                <DonutChart
                                    segments={[
                                        {
                                            label: 'Used',
                                            value: s.total_used,
                                            color: OPS_COLORS.primary,
                                        },
                                        {
                                            label: 'Remaining',
                                            value: s.total_remaining,
                                            color: '#e2e8f0',
                                        },
                                    ]}
                                    centerValue={`${s.utilisation_percent}%`}
                                    centerLabel="Used"
                                    size={140}
                                    strokeWidth={18}
                                />
                                <div className="flex gap-6 text-xs text-muted-foreground">
                                    <span className="flex items-center gap-1.5">
                                        <div
                                            className="h-2.5 w-2.5 rounded-full"
                                            style={{
                                                backgroundColor:
                                                    OPS_COLORS.primary,
                                            }}
                                        />
                                        Used: {formatCurrency(s.total_used)}
                                    </span>
                                    <span className="flex items-center gap-1.5">
                                        <div className="h-2.5 w-2.5 rounded-full bg-muted" />
                                        Remaining:{' '}
                                        {formatCurrency(s.total_remaining)}
                                    </span>
                                </div>
                                {s.expiring_soon > 0 && (
                                    <div className="flex items-center gap-1.5 rounded-md bg-status-warning-bg px-3 py-1.5 text-xs text-status-warning dark:bg-status-warning-bg dark:text-status-warning">
                                        <AlertTriangle className="h-3.5 w-3.5" />
                                        {s.expiring_soon} agreement
                                        {s.expiring_soon !== 1 ? 's' : ''}{' '}
                                        expiring within 30 days
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Claims by Status */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">
                                Claims by Status
                            </CardTitle>
                            <Button
                                asChild
                                variant="ghost"
                                size="sm"
                                className="h-7 text-xs"
                            >
                                <Link href="/operations/funding/claims">
                                    View all{' '}
                                    <ArrowRight className="ml-1 h-3 w-3" />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center justify-center gap-6">
                                <DonutChart
                                    segments={Object.entries(
                                        claims_by_status ?? {},
                                    ).map(([status, count]) => ({
                                        label: status,
                                        value: count,
                                        color:
                                            CLAIM_COLORS[status] ??
                                            OPS_COLORS.muted,
                                    }))}
                                    centerValue={Object.values(
                                        claims_by_status ?? {},
                                    ).reduce((a, b) => a + b, 0)}
                                    centerLabel="Claims"
                                    size={130}
                                    strokeWidth={16}
                                />
                                <div className="space-y-1.5">
                                    {Object.entries(claims_by_status ?? {}).map(
                                        ([status, count]) => (
                                            <div
                                                key={status}
                                                className="flex items-center gap-2"
                                            >
                                                <div
                                                    className="h-2.5 w-2.5 rounded-full"
                                                    style={{
                                                        backgroundColor:
                                                            CLAIM_COLORS[
                                                                status
                                                            ] ??
                                                            OPS_COLORS.muted,
                                                    }}
                                                />
                                                <span className="text-xs text-muted-foreground capitalize">
                                                    {status}
                                                </span>
                                                <span className="ml-auto text-xs font-medium tabular-nums">
                                                    {count}
                                                </span>
                                            </div>
                                        ),
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Top agreements by utilisation */}
                {(top_agreements ?? []).length > 0 && (
                    <Card className="mt-6">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Top Agreements by Utilisation
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                {(top_agreements ?? []).map((ag) => (
                                    <div
                                        key={ag.id}
                                        className="flex items-center gap-3"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={`/operations/service-agreements/${ag.id}`}
                                                    className="truncate text-xs font-medium hover:underline"
                                                >
                                                    {ag.title}
                                                </Link>
                                                <span className="text-[10px] text-muted-foreground">
                                                    {ag.client_name}
                                                </span>
                                            </div>
                                            <div className="mt-1 flex items-center gap-2">
                                                <div className="h-1.5 flex-1 rounded-full bg-muted">
                                                    <div
                                                        className="h-1.5 rounded-full transition-all"
                                                        style={{
                                                            width: `${Math.min(100, ag.utilisation_percent)}%`,
                                                            backgroundColor:
                                                                ag.utilisation_percent >
                                                                90
                                                                    ? OPS_COLORS.danger
                                                                    : ag.utilisation_percent >
                                                                        70
                                                                      ? OPS_COLORS.warning
                                                                      : OPS_COLORS.primary,
                                                        }}
                                                    />
                                                </div>
                                                <span className="text-[10px] text-muted-foreground tabular-nums">
                                                    {ag.utilisation_percent}%
                                                </span>
                                            </div>
                                        </div>
                                        <span className="shrink-0 text-xs font-medium tabular-nums">
                                            {formatCurrency(ag.budget_used)} /{' '}
                                            {formatCurrency(ag.total_budget)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
