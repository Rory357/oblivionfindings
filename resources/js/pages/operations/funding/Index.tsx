import { DonutChart, OPS_COLORS } from '@/components/ops-stat-card';
import {
    PageHeader,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Banknote } from 'lucide-react';
import { useMemo, useState } from 'react';

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

    const [search, setSearch] = useState('');

    const totalClaims = Object.values(claims_by_status ?? {}).reduce(
        (a, b) => a + b,
        0,
    );
    const paidClaims = (claims_by_status ?? {}).paid ?? 0;

    const shownAgreements = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return top_agreements ?? [];
        return (top_agreements ?? []).filter((ag) =>
            `${ag.title} ${ag.client_name}`.toLowerCase().includes(q),
        );
    }, [top_agreements, search]);

    const titleChip =
        s.expiring_soon > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {s.expiring_soon} expiring soon
            </PageHeaderStatusChip>
        ) : s.pending_claims > 0 ? (
            <PageHeaderStatusChip variant="info">
                {s.pending_claims} pending claims
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">
                On track
            </PageHeaderStatusChip>
        );

    const header = (
        <PageHeader
            icon={Banknote}
            title="Funding"
            titleChip={titleChip}
            subline={`Budgets, utilisation and claims · ${
                s.active_agreements
            } active ${s.active_agreements === 1 ? 'agreement' : 'agreements'}`}
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search top agreements…"
                />
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Budget utilised"
                        tone={s.total_remaining < 0 ? 'warning' : 'brand'}
                        ariaLabel="View service agreements and their budgets"
                        href="/operations/service-agreements"
                    >
                        <PageHeaderMeterBig>
                            {formatCurrency(s.total_used)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterBar percent={s.utilisation_percent} />
                        <PageHeaderMeterCaption>
                            {s.total_remaining >= 0
                                ? `${formatCurrency(s.total_remaining)} left of ${formatCurrency(s.total_budget)}`
                                : `${formatCurrency(-s.total_remaining)} over ${formatCurrency(s.total_budget)}`}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Agreements"
                        ariaLabel="View active service agreements"
                        href="/operations/service-agreements"
                    >
                        <PageHeaderMeterBig>
                            {s.active_agreements}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            active with budgets tracked
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Pending claims"
                        tone={s.pending_claims > 0 ? 'warning' : 'success'}
                        ariaLabel="View funding claims"
                        href="/operations/funding/claims"
                    >
                        <PageHeaderMeterBig>
                            {s.pending_claims}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            awaiting approval
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {totalClaims > 0 ? (
                        <PageHeaderMeterBlock
                            label="Claims paid"
                            ariaLabel="View funding claims by status"
                            href="/operations/funding/claims"
                        >
                            <PageHeaderMeterDonut
                                percent={(paidClaims / totalClaims) * 100}
                                caption={
                                    <>
                                        {paidClaims} of {totalClaims}
                                        <br />
                                        paid
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Expiring soon"
                        tone={s.expiring_soon > 0 ? 'warning' : 'success'}
                        ariaLabel="View service agreements expiring soon"
                        href="/operations/service-agreements"
                    >
                        <PageHeaderMeterBig>
                            {s.expiring_soon}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            within the next 30 days
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Funding', href: '/operations/funding' },
            ]}
        >
            <Head title="Funding" />

            <PageLayout hero={header}>
                <div className="grid gap-4 md:grid-cols-2">
                    {/* Claims by Status */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">
                                Claims by status
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
                                    centerValue={totalClaims}
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

                    {/* Top agreements by utilisation */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Top agreements by utilisation
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {shownAgreements.length === 0 ? (
                                <div className="rounded-md border border-dashed p-4 text-center text-xs text-muted-foreground">
                                    {search.trim() !== ''
                                        ? 'No agreements match your search.'
                                        : 'No agreements with budgets yet.'}
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {shownAgreements.map((ag) => (
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
                                                        {ag.utilisation_percent}
                                                        %
                                                    </span>
                                                </div>
                                            </div>
                                            <span className="shrink-0 text-xs font-medium tabular-nums">
                                                {formatCurrency(ag.budget_used)}{' '}
                                                /{' '}
                                                {formatCurrency(
                                                    ag.total_budget,
                                                )}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
