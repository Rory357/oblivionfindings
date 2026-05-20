import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, HeartPulse, Minus, TrendingDown, TrendingUp } from 'lucide-react';

type Indicator = {
    id: number;
    indicator_code: string;
    name: string;
    category: string;
    category_label: string;
    definition: string | null;
    data_source: string | null;
    unit: string | null;
    target_value: number | null;
    target_direction: 'above' | 'below' | 'equal';
    reporting_frequency: string;
    is_active: boolean;
    is_automated: boolean;
};

type SnapshotValue = {
    indicator_id: number;
    indicator_code: string;
    value: number;
    status: 'normal' | 'warning' | 'critical';
    trend: 'up' | 'down' | 'stable';
    source_href: string | null;
    source_label: string | null;
};

type Snapshot = {
    id: number;
    period_start: string | null;
    period_end: string | null;
    indicator_values: SnapshotValue[];
    narrative: string | null;
};

type Props = {
    indicators: Indicator[];
    latestSnapshot: Snapshot | null;
    sourceHint: string;
};

const statusStyles: Record<SnapshotValue['status'], string> = {
    normal: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
};

function formatPeriod(start: string | null, end: string | null): string {
    if (!start || !end) {
        return 'Current period';
    }

    return `${new Date(start).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    })} - ${new Date(end).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    })}`;
}

export default function ClinicalDashboard({ indicators, latestSnapshot, sourceHint }: Props) {
    const getLatestValue = (indicatorId: number): SnapshotValue | null =>
        latestSnapshot?.indicator_values.find((value) => value.indicator_id === indicatorId) ?? null;

    const grouped = indicators.reduce<Record<string, Indicator[]>>((carry, indicator) => {
        carry[indicator.category_label] = carry[indicator.category_label] ?? [];
        carry[indicator.category_label].push(indicator);

        return carry;
    }, {});

    return (
        <AppLayout>
            <Head title="Clinical Governance" />

            <PageLayout
                hero={
                    <PageHero
                        icon={HeartPulse}
                        title="Clinical Governance"
                        description="Automated clinical indicator snapshot for Governance oversight."
                        stats={[
                            { label: 'Indicators', value: indicators.length },
                            { label: 'Active', value: indicators.filter((i) => i.is_active).length },
                            { label: 'Automated', value: indicators.filter((i) => i.is_automated).length },
                        ]}
                        actions={
                            <Button
                                size="sm"
                                variant="outline"
                                asChild
                                className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            >
                                <Link href="/governance/clinical/trends">Trends</Link>
                            </Button>
                        }
                    />
                }
            >
                <Card className="border-status-info/30 bg-status-info-bg">
                    <CardContent className="flex flex-col gap-2 p-4 text-sm text-status-info sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="font-medium">Automated source</p>
                            <p className="text-status-info">{sourceHint}</p>
                        </div>
                        <Badge variant="secondary" className="w-fit bg-white text-status-info">
                            {formatPeriod(latestSnapshot?.period_start ?? null, latestSnapshot?.period_end ?? null)}
                        </Badge>
                    </CardContent>
                </Card>

                {Object.entries(grouped).map(([categoryLabel, categoryIndicators]) => (
                    <div key={categoryLabel} className="space-y-3">
                        <h2 className="text-lg font-semibold text-foreground">{categoryLabel}</h2>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                            {categoryIndicators.map((indicator) => {
                                const latestValue = getLatestValue(indicator.id);

                                return (
                                    <Card key={indicator.id}>
                                        <CardContent className="space-y-4 p-5">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-semibold text-foreground">
                                                        {indicator.name}
                                                    </p>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {indicator.definition ?? indicator.data_source ?? 'Automated indicator'}
                                                    </p>
                                                </div>
                                                <Badge
                                                    className={cn(
                                                        'capitalize',
                                                        latestValue ? statusStyles[latestValue.status] : 'bg-muted text-foreground',
                                                    )}
                                                >
                                                    {latestValue?.status ?? 'No data'}
                                                </Badge>
                                            </div>

                                            <div className="flex items-end justify-between gap-3">
                                                <div className="flex items-baseline gap-2">
                                                    <span className="text-3xl font-bold text-foreground">
                                                        {latestValue ? latestValue.value : '—'}
                                                    </span>
                                                    {indicator.unit && (
                                                        <span className="text-xs uppercase tracking-wide text-muted-foreground">
                                                            {indicator.unit}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {latestValue?.trend === 'up' ? (
                                                        <TrendingUp className="h-4 w-4" />
                                                    ) : latestValue?.trend === 'down' ? (
                                                        <TrendingDown className="h-4 w-4" />
                                                    ) : (
                                                        <Minus className="h-4 w-4" />
                                                    )}
                                                </div>
                                            </div>

                                            <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
                                                <span>
                                                    Target: {indicator.target_direction === 'below' ? '≤' : indicator.target_direction === 'above' ? '≥' : '='}{' '}
                                                    {indicator.target_value ?? '—'}
                                                </span>
                                                <span className="capitalize">{indicator.reporting_frequency}</span>
                                            </div>

                                            {latestValue?.source_href && latestValue.source_label && (
                                                <Link href={latestValue.source_href}>
                                                    <Button variant="ghost" size="sm" className="h-8 px-0 text-status-info">
                                                        {latestValue.source_label}
                                                        <ArrowUpRight className="ml-1 h-3.5 w-3.5" />
                                                    </Button>
                                                </Link>
                                            )}
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    </div>
                ))}

                {latestSnapshot?.narrative && (
                    <Card>
                        <CardContent className="space-y-2 p-5">
                            <p className="text-sm font-semibold text-foreground">Narrative</p>
                            <p className="text-sm leading-6 text-muted-foreground">{latestSnapshot.narrative}</p>
                        </CardContent>
                    </Card>
                )}

                {indicators.length === 0 && (
                    <Card>
                        <CardContent className="p-8 text-center text-sm text-muted-foreground">
                            No automated clinical governance indicators are available yet.
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
