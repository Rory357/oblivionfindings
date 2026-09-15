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
import { careQualityStatusLabel } from '@/lib/governance-labels';
import { Head } from '@inertiajs/react';
import { Activity, Info } from 'lucide-react';
import { useState } from 'react';

import {
    ClinicalViewToggle,
    formatCount,
    indicatorChip,
    indicatorStatusKey,
    targetLabel,
    type Snapshot,
    type SnapshotValue,
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

type Props = {
    snapshots: Snapshot[];
    indicators: Indicator[];
    sourceHint: string;
};

const RANGE_OPTIONS = [
    { value: '6', label: 'Last 6 months' },
    { value: '3', label: 'Last 3 months' },
    { value: '12', label: 'Last 12 months' },
];

function scrollTo(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

export default function ClinicalTrends({
    snapshots,
    indicators,
    sourceHint,
}: Props) {
    const [range, setRange] = useState('6');

    const latestSnapshot = snapshots[0] ?? null;
    const activeIndicators = indicators.filter((indicator) => indicator.is_active);
    const months = snapshots.slice(0, Number(range)).reverse();

    const valueFor = (
        snapshot: Snapshot | null,
        indicatorId: number,
    ): SnapshotValue | null =>
        snapshot?.indicator_values.find(
            (value) => value.indicator_id === indicatorId,
        ) ?? null;

    const latestStatuses = activeIndicators.map((indicator) =>
        indicatorStatusKey(valueFor(latestSnapshot, indicator.id)),
    );
    const critical = latestStatuses.filter((s) => s === 'critical').length;
    const warning = latestStatuses.filter((s) => s === 'warning').length;
    const period = latestSnapshot?.period_label ?? 'This month';

    const header = (
        <PageHeader
            icon={Activity}
            title="Care quality trends"
            titleChip={
                critical > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {plural(critical, 'needs action now', 'need action now')}
                    </PageHeaderStatusChip>
                ) : snapshots.length === 0 ? (
                    <PageHeaderStatusChip variant="neutral">
                        {careQualityStatusLabel('no_data')}
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="info">
                        {plural(snapshots.length, 'month recorded', 'months recorded')}
                    </PageHeaderStatusChip>
                )
            }
            subline="How medication errors, falls, skin injuries and infections change from month to month"
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Months recorded"
                        ariaLabel="Go to the month by month table"
                        onClick={() => scrollTo('care-quality-months')}
                    >
                        <PageHeaderMeterBig>{snapshots.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            including this month so far
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label={careQualityStatusLabel('critical')}
                        ariaLabel="Open measures that need action this month"
                        href="/governance/clinical?status=critical"
                        tone={critical > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>{critical}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>this month</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label={careQualityStatusLabel('warning')}
                        ariaLabel="Open measures that need watching this month"
                        href="/governance/clinical?status=warning"
                        tone={warning > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{warning}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>this month</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="This month"
                        ariaLabel="Open this month"
                        href="/governance/clinical"
                    >
                        <PageHeaderMeterBig>
                            <span className="text-base">
                                {latestSnapshot?.short_label ?? '—'}
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
                { title: 'Care quality', href: '/governance/clinical' },
                { title: 'Trends', href: '/governance/clinical/trends' },
            ]}
        >
            <Head title="Care quality trends" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <Card id="care-quality-months">
                        <CardHeader>
                            <CardTitle>
                                Month by month (current month so far)
                            </CardTitle>
                            <CardDescription>
                                {months.length > 0
                                    ? `The last ${plural(months.length, 'month', 'months')}. The newest month is counted up to today.`
                                    : 'Nothing has been counted yet.'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="overflow-x-auto">
                            {months.length === 0 || activeIndicators.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={Activity}
                                    title="No months to compare yet"
                                    description="Each month is added here once medication errors or care events are being recorded."
                                />
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Measure</TableHead>
                                            {months.map((snapshot) => (
                                                <TableHead
                                                    key={snapshot.id}
                                                    className="text-center whitespace-nowrap"
                                                >
                                                    {snapshot.short_label}
                                                </TableHead>
                                            ))}
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
                                                        {targetLabel(
                                                            indicator.target_direction,
                                                            indicator.target_value,
                                                        )}
                                                    </div>
                                                </TableCell>
                                                {months.map((snapshot) => {
                                                    const entry = valueFor(
                                                        snapshot,
                                                        indicator.id,
                                                    );
                                                    const chip = indicatorChip(entry);
                                                    return (
                                                        <TableCell
                                                            key={snapshot.id}
                                                            className="text-center"
                                                        >
                                                            {entry && entry.recorded ? (
                                                                <StatusBadge
                                                                    variant={chip.variant}
                                                                    aria-label={`${formatCount(entry.value)} in ${snapshot.period_label}: ${chip.label}`}
                                                                >
                                                                    {formatCount(entry.value)}
                                                                </StatusBadge>
                                                            ) : (
                                                                <span className="text-caption">
                                                                    {entry ? chip.label : '—'}
                                                                </span>
                                                            )}
                                                        </TableCell>
                                                    );
                                                })}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>

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
                                    A measure is on target at 0, needs watching at
                                    1 or 2 in a month and needs action at 3 or
                                    more.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
