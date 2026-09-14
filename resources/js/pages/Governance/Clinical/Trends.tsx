import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import {
    Activity,
    DatabaseZap,
    Minus,
    TrendingDown,
    TrendingUp,
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
    unit: string | null;
    target_value: number | null;
    target_direction: 'above' | 'below' | 'equal';
    is_active: boolean;
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
    snapshots: Snapshot[];
    indicators: Indicator[];
    sourceHint: string;
};

const RANGE_OPTIONS = [
    { value: '6', label: 'Last 6 snapshots' },
    { value: '3', label: 'Last 3 snapshots' },
];

function columnDate(date: string | null): string {
    if (!date) return 'Current';
    const [year, month] = date.slice(0, 10).split('-').map(Number);
    if (!year || !month) return date;
    return new Date(Date.UTC(year, month - 1, 15)).toLocaleDateString(
        'en-NZ',
        { month: 'short', year: '2-digit', timeZone: 'UTC' },
    );
}

function scrollTo(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

export default function ClinicalTrends({
    snapshots,
    indicators,
    sourceHint,
}: Props) {
    const [category, setCategory] = useState('all');
    const [range, setRange] = useState('6');

    const latestSnapshot = snapshots[0] ?? null;
    const activeIndicators = indicators.filter(
        (indicator) =>
            indicator.is_active &&
            (category === 'all' || indicator.category === category),
    );
    const historicalSnapshots = snapshots.slice(0, Number(range)).reverse();
    const categories = Array.from(
        new Map(indicators.map((i) => [i.category, i.category_label])),
    ).map(([value, label]) => ({ value, label }));

    const valueFor = (
        snapshot: Snapshot | null,
        indicatorId: number,
    ): SnapshotValue | null =>
        snapshot?.indicator_values.find(
            (value) => value.indicator_id === indicatorId,
        ) ?? null;

    const latestStatuses = activeIndicators.map(
        (indicator) => valueFor(latestSnapshot, indicator.id)?.status,
    );
    const critical = latestStatuses.filter((s) => s === 'critical').length;
    const warning = latestStatuses.filter((s) => s === 'warning').length;
    const period = formatPeriod(
        latestSnapshot?.period_start ?? null,
        latestSnapshot?.period_end ?? null,
    );

    const header = (
        <PageHeader
            icon={Activity}
            title="Clinical governance trends"
            titleChip={
                critical > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {critical} critical now
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="info">
                        {snapshots.length}{' '}
                        {snapshots.length === 1 ? 'snapshot' : 'snapshots'}
                    </PageHeaderStatusChip>
                )
            }
            subline={`Automated snapshot history for the clinical indicators · latest ${period}`}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Snapshots"
                        ariaLabel="View historical performance"
                        onClick={() => scrollTo('clinical-history')}
                    >
                        <PageHeaderMeterBig>{snapshots.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            recorded periods
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Indicators"
                        ariaLabel="View the current snapshot"
                        onClick={() => scrollTo('clinical-current')}
                    >
                        <PageHeaderMeterBig>
                            {activeIndicators.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            active{category === 'all' ? '' : ' in category'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Critical now"
                        ariaLabel="View the current snapshot"
                        onClick={() => scrollTo('clinical-current')}
                        tone={critical > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>{critical}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            latest snapshot
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Warning now"
                        ariaLabel="View the current snapshot"
                        onClick={() => scrollTo('clinical-current')}
                        tone={warning > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{warning}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            latest snapshot
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Latest period"
                        ariaLabel="View the current snapshot"
                        onClick={() => scrollTo('clinical-current')}
                    >
                        <PageHeaderMeterBig>
                            <span className="text-base">
                                {columnDate(latestSnapshot?.period_end ?? null)}
                            </span>
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{period}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <ClinicalViewToggle value="trends" />
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
                        label="Range"
                        value={range}
                        allValue="6"
                        options={RANGE_OPTIONS}
                        onChange={setRange}
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
                { title: 'Trends', href: '/governance/clinical/trends' },
            ]}
        >
            <Head title="Clinical governance trends" />

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

                    <Card id="clinical-current">
                        <CardHeader>
                            <CardTitle>Current snapshot</CardTitle>
                            <CardDescription>
                                {latestSnapshot
                                    ? period
                                    : 'No snapshot recorded yet.'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {!latestSnapshot || activeIndicators.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={Activity}
                                    title={
                                        latestSnapshot
                                            ? 'No active indicators in this category'
                                            : 'No clinical governance snapshots yet'
                                    }
                                />
                            ) : (
                                <div className="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
                                    {activeIndicators.map((indicator) => {
                                        const value = valueFor(
                                            latestSnapshot,
                                            indicator.id,
                                        );
                                        return (
                                            <div
                                                key={indicator.id}
                                                className="rounded-xl border border-border p-4"
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div>
                                                        <p className="text-sm font-semibold text-foreground">
                                                            {indicator.name}
                                                        </p>
                                                        <p className="text-caption">
                                                            {
                                                                indicator.category_label
                                                            }
                                                        </p>
                                                    </div>
                                                    {value ? (
                                                        <StatusBadge
                                                            variant={
                                                                INDICATOR_STATUS_VARIANTS[
                                                                    value.status
                                                                ]
                                                            }
                                                        >
                                                            {
                                                                INDICATOR_STATUS_LABELS[
                                                                    value.status
                                                                ]
                                                            }
                                                        </StatusBadge>
                                                    ) : (
                                                        <StatusBadge variant="neutral">
                                                            No data
                                                        </StatusBadge>
                                                    )}
                                                </div>
                                                <div className="mt-3 flex items-end justify-between gap-3">
                                                    <div className="flex items-baseline gap-2">
                                                        <span className="text-page-title tabular-nums">
                                                            {value
                                                                ? value.value
                                                                : '—'}
                                                        </span>
                                                        {indicator.unit ? (
                                                            <span className="text-caption tracking-wide uppercase">
                                                                {indicator.unit}
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
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card id="clinical-history">
                        <CardHeader>
                            <CardTitle>Historical performance</CardTitle>
                            <CardDescription>
                                Last {historicalSnapshots.length} recorded
                                snapshots
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="overflow-x-auto">
                            {historicalSnapshots.length === 0 ||
                            activeIndicators.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={Activity}
                                    title="No history to compare yet"
                                />
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Indicator</TableHead>
                                            {historicalSnapshots.map(
                                                (snapshot) => (
                                                    <TableHead
                                                        key={snapshot.id}
                                                        className="text-center whitespace-nowrap"
                                                    >
                                                        {columnDate(
                                                            snapshot.period_end,
                                                        )}
                                                    </TableHead>
                                                ),
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {activeIndicators.map((indicator) => (
                                            <TableRow key={indicator.id}>
                                                <TableCell>
                                                    <div className="font-medium text-foreground">
                                                        {indicator.name}
                                                    </div>
                                                    <div className="text-caption">
                                                        Target{' '}
                                                        {targetLabel(
                                                            indicator.target_direction,
                                                            indicator.target_value,
                                                        )}
                                                    </div>
                                                </TableCell>
                                                {historicalSnapshots.map(
                                                    (snapshot) => {
                                                        const entry = valueFor(
                                                            snapshot,
                                                            indicator.id,
                                                        );
                                                        return (
                                                            <TableCell
                                                                key={snapshot.id}
                                                                className="text-center"
                                                            >
                                                                {entry ? (
                                                                    <StatusBadge
                                                                        variant={
                                                                            INDICATOR_STATUS_VARIANTS[
                                                                                entry
                                                                                    .status
                                                                            ]
                                                                        }
                                                                    >
                                                                        {
                                                                            entry.value
                                                                        }
                                                                    </StatusBadge>
                                                                ) : (
                                                                    <span className="text-muted-foreground">
                                                                        —
                                                                    </span>
                                                                )}
                                                            </TableCell>
                                                        );
                                                    },
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>

                    {latestSnapshot?.narrative ? (
                        <Card>
                            <CardHeader>
                                <CardTitle>Narrative</CardTitle>
                                <CardDescription>{period}</CardDescription>
                            </CardHeader>
                            <CardContent>
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
