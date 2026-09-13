import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    DatabaseZap,
    HeartPulse,
    Minus,
    TrendingDown,
    TrendingUp,
    X,
} from 'lucide-react';
import { useState } from 'react';

import {
    ClinicalViewToggle,
    formatPeriod,
    INDICATOR_STATUS_LABELS,
    INDICATOR_STATUS_VARIANTS,
    targetLabel,
    type IndicatorStatus,
} from './_shared';

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
    status: IndicatorStatus;
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

const STATUS_FILTERS = [
    { value: 'all', label: 'All statuses' },
    { value: 'critical', label: 'Critical' },
    { value: 'warning', label: 'Warning' },
    { value: 'normal', label: 'On target' },
    { value: 'no_data', label: 'No data' },
];

export default function ClinicalDashboard({
    indicators,
    latestSnapshot,
    sourceHint,
}: Props) {
    const [category, setCategory] = useState('all');
    const [status, setStatus] = useState('all');

    const latestValue = (indicatorId: number): SnapshotValue | null =>
        latestSnapshot?.indicator_values.find(
            (value) => value.indicator_id === indicatorId,
        ) ?? null;

    const statusOf = (indicator: Indicator): IndicatorStatus | 'no_data' =>
        latestValue(indicator.id)?.status ?? 'no_data';

    const counts = indicators.reduce<Record<string, number>>(
        (acc, indicator) => {
            const key = statusOf(indicator);
            acc[key] = (acc[key] ?? 0) + 1;
            return acc;
        },
        {},
    );
    const withData = indicators.length - (counts.no_data ?? 0);
    const onTargetPct =
        withData > 0 ? ((counts.normal ?? 0) / withData) * 100 : 0;
    const automated = indicators.filter((i) => i.is_automated).length;

    const categories = Array.from(
        new Map(indicators.map((i) => [i.category, i.category_label])),
    ).map(([value, label]) => ({ value, label }));

    const visible = indicators.filter(
        (indicator) =>
            (category === 'all' || indicator.category === category) &&
            (status === 'all' || statusOf(indicator) === status),
    );
    const grouped = visible.reduce<Record<string, Indicator[]>>(
        (carry, indicator) => {
            carry[indicator.category_label] =
                carry[indicator.category_label] ?? [];
            carry[indicator.category_label].push(indicator);
            return carry;
        },
        {},
    );
    const hasFilters = category !== 'all' || status !== 'all';
    const period = formatPeriod(
        latestSnapshot?.period_start ?? null,
        latestSnapshot?.period_end ?? null,
    );

    const header = (
        <PageHeader
            icon={HeartPulse}
            title="Clinical governance"
            titleChip={
                (counts.critical ?? 0) > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {counts.critical} critical
                    </PageHeaderStatusChip>
                ) : (counts.warning ?? 0) > 0 ? (
                    <PageHeaderStatusChip variant="warning">
                        {counts.warning} warning
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        On target
                    </PageHeaderStatusChip>
                )
            }
            subline={`Automated clinical indicator snapshot for board oversight · ${period}`}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Indicators"
                        ariaLabel="View all indicators"
                        onClick={() => {
                            setCategory('all');
                            setStatus('all');
                        }}
                    >
                        <PageHeaderMeterBig>{indicators.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {indicators.filter((i) => i.is_active).length}{' '}
                            active · {categories.length} categories
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Critical"
                        ariaLabel="View critical indicators"
                        onClick={() => setStatus('critical')}
                        tone={(counts.critical ?? 0) > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>{counts.critical ?? 0}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            beyond critical threshold
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Warning"
                        ariaLabel="View warning indicators"
                        onClick={() => setStatus('warning')}
                        tone={(counts.warning ?? 0) > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{counts.warning ?? 0}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            beyond warning threshold
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="On target"
                        value={`${counts.normal ?? 0}/${withData}`}
                        ariaLabel="View indicators on target"
                        onClick={() => setStatus('normal')}
                        tone="success"
                    >
                        <PageHeaderMeterDonut
                            percent={onTargetPct}
                            caption={`${counts.normal ?? 0} of ${withData} with data`}
                        />
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Automated"
                        ariaLabel="View indicators without data"
                        onClick={() => setStatus('no_data')}
                    >
                        <PageHeaderMeterBig>{automated}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {counts.no_data ?? 0} without data this period
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <ClinicalViewToggle value="dashboard" />
                    <PageHeaderFilterSelect
                        label="Category"
                        value={category}
                        options={[
                            { value: 'all', label: 'All categories' },
                            ...categories,
                        ]}
                        onChange={setCategory}
                    />
                    <PageHeaderFilterSelect
                        label="Status"
                        value={status}
                        options={STATUS_FILTERS}
                        onChange={setStatus}
                    />
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Clinical governance', href: '/governance/clinical' },
            ]}
        >
            <Head title="Clinical governance" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <Card>
                        <CardContent className="flex flex-col gap-2 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-start gap-3">
                                <DatabaseZap className="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                                <div>
                                    <p className="text-sm font-medium">
                                        Automated source
                                    </p>
                                    <p className="text-subtle">{sourceHint}</p>
                                </div>
                            </div>
                            <StatusBadge variant="info" className="w-fit">
                                {period}
                            </StatusBadge>
                        </CardContent>
                    </Card>

                    {indicators.length === 0 ? (
                        <EmptyState
                            icon={HeartPulse}
                            title="No clinical governance indicators yet"
                            description="Automated indicators appear once clinical data sources are connected."
                        />
                    ) : visible.length === 0 ? (
                        <EmptyState
                            icon={HeartPulse}
                            title="No indicators match your filters"
                            description="Try clearing a filter."
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setCategory('all');
                                            setStatus('all');
                                        }}
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        Object.entries(grouped).map(
                            ([categoryLabel, categoryIndicators]) => (
                                <section
                                    key={categoryLabel}
                                    className="flex flex-col gap-3"
                                >
                                    <h2 className="text-section-title">
                                        {categoryLabel}
                                    </h2>
                                    <div className="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
                                        {categoryIndicators.map((indicator) => {
                                            const value = latestValue(
                                                indicator.id,
                                            );
                                            return (
                                                <Card key={indicator.id}>
                                                    <CardContent className="flex h-full flex-col gap-4 p-5">
                                                        <div className="flex items-start justify-between gap-3">
                                                            <div>
                                                                <p className="text-sm font-semibold text-foreground">
                                                                    {
                                                                        indicator.name
                                                                    }
                                                                </p>
                                                                <p className="text-caption mt-1">
                                                                    {indicator.definition ??
                                                                        indicator.data_source ??
                                                                        'Automated indicator'}
                                                                </p>
                                                            </div>
                                                            {value ? (
                                                                <StatusBadge
                                                                    variant={
                                                                        INDICATOR_STATUS_VARIANTS[
                                                                            value
                                                                                .status
                                                                        ]
                                                                    }
                                                                >
                                                                    {
                                                                        INDICATOR_STATUS_LABELS[
                                                                            value
                                                                                .status
                                                                        ]
                                                                    }
                                                                </StatusBadge>
                                                            ) : (
                                                                <StatusBadge variant="neutral">
                                                                    No data
                                                                </StatusBadge>
                                                            )}
                                                        </div>

                                                        <div className="flex items-end justify-between gap-3">
                                                            <div className="flex items-baseline gap-2">
                                                                <span className="text-page-title tabular-nums">
                                                                    {value
                                                                        ? value.value
                                                                        : '—'}
                                                                </span>
                                                                {indicator.unit ? (
                                                                    <span className="text-caption tracking-wide uppercase">
                                                                        {
                                                                            indicator.unit
                                                                        }
                                                                    </span>
                                                                ) : null}
                                                            </div>
                                                            <span className="text-muted-foreground">
                                                                {value?.trend ===
                                                                'up' ? (
                                                                    <TrendingUp
                                                                        className="h-4 w-4"
                                                                        aria-label="Trending up"
                                                                    />
                                                                ) : value?.trend ===
                                                                  'down' ? (
                                                                    <TrendingDown
                                                                        className="h-4 w-4"
                                                                        aria-label="Trending down"
                                                                    />
                                                                ) : (
                                                                    <Minus
                                                                        className="h-4 w-4"
                                                                        aria-label="Stable"
                                                                    />
                                                                )}
                                                            </span>
                                                        </div>

                                                        <div className="text-caption flex items-center justify-between gap-3">
                                                            <span>
                                                                Target{' '}
                                                                {targetLabel(
                                                                    indicator.target_direction,
                                                                    indicator.target_value,
                                                                )}
                                                            </span>
                                                            <span className="capitalize">
                                                                {
                                                                    indicator.reporting_frequency
                                                                }
                                                            </span>
                                                        </div>

                                                        {value?.source_href &&
                                                        value.source_label ? (
                                                            <Button
                                                                asChild
                                                                variant="ghost"
                                                                size="sm"
                                                                className="mt-auto h-8 w-fit px-0 text-primary"
                                                            >
                                                                <Link
                                                                    href={
                                                                        value.source_href
                                                                    }
                                                                >
                                                                    {
                                                                        value.source_label
                                                                    }
                                                                    <ArrowUpRight className="ml-1 h-3.5 w-3.5" />
                                                                </Link>
                                                            </Button>
                                                        ) : null}
                                                    </CardContent>
                                                </Card>
                                            );
                                        })}
                                    </div>
                                </section>
                            ),
                        )
                    )}

                    {latestSnapshot?.narrative ? (
                        <Card>
                            <CardContent className="flex flex-col gap-2 p-5">
                                <h2 className="text-sm font-semibold text-foreground">
                                    Narrative
                                </h2>
                                <p className="text-subtle leading-6">
                                    {latestSnapshot.narrative}
                                </p>
                            </CardContent>
                        </Card>
                    ) : null}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
