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
import { careQualityStatusLabel } from '@/lib/governance-labels';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    HeartPulse,
    Info,
    Minus,
    TrendingDown,
    TrendingUp,
    X,
} from 'lucide-react';
import { useState } from 'react';

import {
    ClinicalViewToggle,
    comparisonText,
    formatCount,
    indicatorChip,
    indicatorStatusKey,
    targetLabel,
    unitLabel,
    type Snapshot,
    type SnapshotValue,
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

type Props = {
    indicators: Indicator[];
    latestSnapshot: Snapshot | null;
    sourceHint: string;
    filters?: { status?: string };
};

const STATUS_FILTERS = [
    { value: 'all', label: 'All measures' },
    { value: 'critical', label: careQualityStatusLabel('critical') },
    { value: 'warning', label: careQualityStatusLabel('warning') },
    { value: 'normal', label: careQualityStatusLabel('normal') },
    { value: 'no_data', label: careQualityStatusLabel('no_data') },
];

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

export default function ClinicalDashboard({
    indicators,
    latestSnapshot,
    sourceHint,
    filters = {},
}: Props) {
    const [status, setStatus] = useState(filters.status ?? 'all');

    const latestValue = (indicatorId: number): SnapshotValue | null =>
        latestSnapshot?.indicator_values.find(
            (value) => value.indicator_id === indicatorId,
        ) ?? null;

    const statusOf = (indicator: Indicator) =>
        indicatorStatusKey(latestValue(indicator.id));

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
    const visible = indicators.filter(
        (indicator) => status === 'all' || statusOf(indicator) === status,
    );
    const period = latestSnapshot?.period_label ?? 'This month';
    const comparedWith = latestSnapshot?.compared_with_label ?? null;

    const titleChip =
        withData === 0 ? (
            <PageHeaderStatusChip variant="neutral">
                {careQualityStatusLabel('no_data')}
            </PageHeaderStatusChip>
        ) : (counts.critical ?? 0) > 0 ? (
            <PageHeaderStatusChip variant="critical">
                {plural(counts.critical ?? 0, 'needs action', 'need action')}
            </PageHeaderStatusChip>
        ) : (counts.warning ?? 0) > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {plural(
                    counts.warning ?? 0,
                    'needs watching',
                    'need watching',
                )}
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">
                {careQualityStatusLabel('normal')}
            </PageHeaderStatusChip>
        );

    const header = (
        <PageHeader
            icon={HeartPulse}
            title="Care quality"
            titleChip={titleChip}
            subline={`Medication errors, falls, skin injuries and infections · ${period}`}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Measures"
                        ariaLabel="Show all measures"
                        onClick={() => setStatus('all')}
                    >
                        <PageHeaderMeterBig>{indicators.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            counted automatically
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label={careQualityStatusLabel('critical')}
                        ariaLabel="Show measures that need action"
                        onClick={() => setStatus('critical')}
                        tone={(counts.critical ?? 0) > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>{counts.critical ?? 0}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            3 or more this month
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label={careQualityStatusLabel('warning')}
                        ariaLabel="Show measures that need watching"
                        onClick={() => setStatus('warning')}
                        tone={(counts.warning ?? 0) > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{counts.warning ?? 0}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            1 or 2 this month
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label={careQualityStatusLabel('normal')}
                        value={`${counts.normal ?? 0}/${withData}`}
                        ariaLabel="Show measures on target"
                        onClick={() => setStatus('normal')}
                        tone="success"
                    >
                        <PageHeaderMeterDonut
                            percent={onTargetPct}
                            caption={
                                withData === 0
                                    ? 'No data yet'
                                    : `${counts.normal ?? 0} of ${withData} with data`
                            }
                        />
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Month by month"
                        ariaLabel="Open month by month"
                        href="/governance/clinical/trends"
                    >
                        <PageHeaderMeterBig>
                            <span className="text-base">
                                {latestSnapshot?.short_label ?? 'This month'}
                            </span>
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {comparedWith
                                ? `compared with ${comparedWith}`
                                : 'compare past months'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <ClinicalViewToggle value="dashboard" />
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
                { title: 'Care quality', href: '/governance/clinical' },
            ]}
        >
            <Head title="Care quality" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {indicators.length === 0 ? (
                        <EmptyState
                            icon={HeartPulse}
                            title="No care quality measures yet"
                            description="Measures appear here once medication errors or care events are being recorded."
                        />
                    ) : visible.length === 0 ? (
                        <EmptyState
                            icon={HeartPulse}
                            title="No measures match this status"
                            description="Choose another status to see the other measures."
                            action={
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setStatus('all')}
                                >
                                    <X className="h-3.5 w-3.5" />
                                    Show all measures
                                </Button>
                            }
                        />
                    ) : (
                        <div className="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
                            {visible.map((indicator) => {
                                const value = latestValue(indicator.id);
                                const chip = indicatorChip(value);
                                const recorded = Boolean(value?.recorded);
                                const unit = unitLabel(indicator.unit);
                                const comparison =
                                    value && recorded
                                        ? comparisonText(value, comparedWith)
                                        : null;
                                return (
                                    <Card key={indicator.id}>
                                        <CardContent className="flex h-full flex-col gap-4 p-5">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <h2 className="text-sm font-semibold text-foreground">
                                                        {indicator.name}
                                                    </h2>
                                                    {indicator.definition ? (
                                                        <p className="text-caption mt-1">
                                                            {indicator.definition}
                                                        </p>
                                                    ) : null}
                                                </div>
                                                <StatusBadge variant={chip.variant}>
                                                    {chip.label}
                                                </StatusBadge>
                                            </div>

                                            <div className="flex flex-col gap-1">
                                                <div className="flex items-baseline gap-2">
                                                    <span className="text-page-title tabular-nums">
                                                        {value && recorded
                                                            ? formatCount(value.value)
                                                            : '—'}
                                                    </span>
                                                    {unit ? (
                                                        <span className="text-caption">
                                                            {unit}
                                                        </span>
                                                    ) : null}
                                                </div>
                                                {comparison && value ? (
                                                    <p className="text-caption flex items-center gap-1.5">
                                                        {value.trend === 'up' ? (
                                                            <TrendingUp
                                                                className="h-3.5 w-3.5"
                                                                aria-hidden="true"
                                                            />
                                                        ) : value.trend === 'down' ? (
                                                            <TrendingDown
                                                                className="h-3.5 w-3.5"
                                                                aria-hidden="true"
                                                            />
                                                        ) : (
                                                            <Minus
                                                                className="h-3.5 w-3.5"
                                                                aria-hidden="true"
                                                            />
                                                        )}
                                                        {comparison}
                                                    </p>
                                                ) : null}
                                            </div>

                                            <p className="text-caption">
                                                {targetLabel(
                                                    indicator.target_direction,
                                                    indicator.target_value,
                                                )}
                                            </p>

                                            {value?.source_href &&
                                            value.source_label ? (
                                                <Button
                                                    asChild
                                                    variant="ghost"
                                                    size="sm"
                                                    className="mt-auto h-8 w-fit px-0 text-primary"
                                                >
                                                    <Link href={value.source_href}>
                                                        {value.source_label}
                                                        <ArrowUpRight className="ml-1 h-3.5 w-3.5" />
                                                    </Link>
                                                </Button>
                                            ) : null}
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    )}

                    <Card>
                        <CardContent className="flex items-start gap-3 py-4">
                            <Info
                                className="mt-0.5 h-5 w-5 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <div className="flex flex-col gap-1">
                                <h2 className="text-sm font-semibold text-foreground">
                                    Where these numbers come from
                                </h2>
                                <p className="text-subtle">{sourceHint}</p>
                                <p className="text-subtle">
                                    Each number covers {period}.
                                    {comparedWith
                                        ? ` Up and down compare it with the same days last month (${comparedWith}).`
                                        : ''}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
