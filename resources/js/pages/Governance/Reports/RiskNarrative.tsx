import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowUpRight, ShieldAlert } from 'lucide-react';

import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { refSuffix } from '@/lib/governance-labels';
import { PageProps } from '@/types';

import {
    controlText,
    riskBandLabel,
    riskLevelVariant,
    riskStatusChip,
    strategyLabel,
} from '../Risks/_shared';
import { GeneratedAt } from './_shared';

interface Risk {
    id: number;
    reference: string | null;
    title: string;
    category: string;
    category_label: string;
    description: string | null;
    inherent_score: number;
    residual_score: number;
    appetite_threshold: number;
    control_effectiveness: string | null;
    within_appetite: boolean;
    status: string;
    owner: string | null;
    mitigation_strategy: string | null;
    treatments_count: number;
    open_treatments: number;
    overdue_treatments: number;
    next_review: string | null;
}

interface Props extends PageProps {
    risks: Risk[];
    summary: {
        critical: number;
        high: number;
        above_limit: number;
        current: number;
    };
    generatedAt: string;
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-caption">{label}</dt>
            <dd className="mt-0.5 text-sm text-foreground">{children}</dd>
        </div>
    );
}

export default function RiskNarrative({ auth, risks, summary, generatedAt }: Props) {
    const titleChip =
        summary.above_limit > 0 ? (
            <PageHeaderStatusChip variant="critical">
                {summary.above_limit} above the board&apos;s limit
            </PageHeaderStatusChip>
        ) : summary.current === 0 ? (
            <PageHeaderStatusChip variant="neutral">No open risks</PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">
                None above the board&apos;s limit
            </PageHeaderStatusChip>
        );

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Risk register', href: '/governance/risks' },
                { title: 'Top risks report', href: '/governance/reports/risk-narrative' },
            ]}
        >
            <Head title="Top risks report" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={AlertTriangle}
                        title="Top risks report"
                        titleChip={titleChip}
                        subline="The 10 highest risks after controls"
                        backHref="/governance/risks"
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Critical"
                                    href="/governance/risks?severity=critical"
                                    tone={summary.critical > 0 ? 'critical' : 'brand'}
                                    ariaLabel="Open critical risks"
                                >
                                    <PageHeaderMeterBig>{summary.critical}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>score 20 to 25 after controls</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="High"
                                    href="/governance/risks?severity=high"
                                    tone={summary.high > 0 ? 'warning' : 'brand'}
                                    ariaLabel="Open high risks"
                                >
                                    <PageHeaderMeterBig>{summary.high}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>score 15 to 19 after controls</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Above the board's limit"
                                    href="/governance/risks?above_appetite=1"
                                    tone={summary.above_limit > 0 ? 'critical' : 'brand'}
                                    ariaLabel="Open risks above the board's limit"
                                >
                                    <PageHeaderMeterBig>{summary.above_limit}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>not accepted by the board</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="On the register"
                                    href="/governance/risks"
                                    ariaLabel="Open the risk register"
                                >
                                    <PageHeaderMeterBig>{summary.current}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>open or accepted by the board</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                    />
                }
            >
                {risks.length === 0 ? (
                    <EmptyState
                        icon={ShieldAlert}
                        title="No open risks"
                        description="Risks appear here once they are added to the register."
                    />
                ) : (
                    risks.map((risk, index) => {
                        const status = riskStatusChip(risk);
                        return (
                            <Card key={risk.id}>
                                <CardHeader>
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div className="flex flex-col gap-1">
                                            <CardTitle>
                                                <Link
                                                    href={`/governance/risks/${risk.id}`}
                                                    className="underline-offset-2 hover:underline"
                                                >
                                                    {index + 1}. {risk.title}
                                                </Link>
                                            </CardTitle>
                                            <CardDescription>
                                                {[risk.category_label, refSuffix(risk.reference)]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </CardDescription>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <StatusBadge variant={riskLevelVariant(risk.residual_score)}>
                                                {risk.residual_score} · {riskBandLabel(risk.residual_score)}
                                            </StatusBadge>
                                            <StatusBadge variant={status.variant}>{status.label}</StatusBadge>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-4">
                                    {risk.description ? (
                                        <p className="text-subtle whitespace-pre-wrap">
                                            {risk.description}
                                        </p>
                                    ) : null}
                                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                        <Fact label="Risk before controls">
                                            {risk.inherent_score} · {riskBandLabel(risk.inherent_score)}
                                        </Fact>
                                        <Fact label="Risk after controls">
                                            {risk.residual_score} · {riskBandLabel(risk.residual_score)}
                                        </Fact>
                                        <Fact label={`The board's limit for ${risk.category_label.toLowerCase()} risks`}>
                                            {risk.appetite_threshold}
                                        </Fact>
                                        <Fact label="How well controls work">
                                            {controlText(risk.control_effectiveness)}
                                        </Fact>
                                        <Fact label="Response">
                                            {strategyLabel(risk.mitigation_strategy)}
                                        </Fact>
                                        <Fact label="Owner">{risk.owner ?? 'No owner named'}</Fact>
                                        <Fact label="Actions">
                                            {risk.treatments_count === 0
                                                ? 'None yet'
                                                : `${risk.open_treatments} of ${risk.treatments_count} still open${
                                                      risk.overdue_treatments > 0
                                                          ? ` · ${risk.overdue_treatments} overdue`
                                                          : ''
                                                  }`}
                                        </Fact>
                                        <Fact label="Next review">
                                            {risk.next_review ? formatDateOnly(risk.next_review) : 'Not set'}
                                        </Fact>
                                    </dl>
                                    <Link
                                        href={`/governance/risks/${risk.id}`}
                                        className="inline-flex w-fit items-center gap-1 text-sm font-medium text-primary underline-offset-2 hover:underline"
                                    >
                                        Open this risk
                                        <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
                                    </Link>
                                </CardContent>
                            </Card>
                        );
                    })
                )}
                <GeneratedAt at={generatedAt} />
            </PageLayout>
        </AppLayout>
    );
}
