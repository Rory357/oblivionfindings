import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDelta,
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
import { formatDateOnly } from '@/lib/datetime';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { TrendingUp } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { RiskViewToggle, riskLevelVariant } from './_shared';

interface Snapshot {
    id: number;
    snapshot_date: string;
    summary: {
        critical: number;
        high: number;
        medium: number;
        low: number;
        above_appetite: number;
    };
    by_category: Record<string, { count: number; avg_score: number }>;
}

interface Props extends PageProps {
    snapshots: Snapshot[];
}

type SummaryKey = keyof Snapshot['summary'];

const RANGE_OPTIONS = [
    { value: '12', label: 'Last 12 snapshots' },
    { value: '6', label: 'Last 6 snapshots' },
    { value: '3', label: 'Last 3 snapshots' },
];

function snapshotDate(value: string): string {
    return formatDateOnly(value?.slice(0, 10));
}

function scrollTo(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

export default function RiskTrends({ snapshots }: Props) {
    const [range, setRange] = useState('12');
    const latest = snapshots[0] ?? null;
    const previous = snapshots[1] ?? null;
    const displaySnapshots = snapshots.slice(0, Number(range));

    const maxCritHigh = Math.max(
        ...displaySnapshots.map((s) => s.summary.critical + s.summary.high),
        1,
    );

    /** Latest value + movement since the previous snapshot. */
    const meter = (
        key: SummaryKey,
        label: string,
        caption: string,
        tone: 'critical' | 'warning' | 'success' | 'brand',
    ): ReactNode => {
        const value = latest?.summary[key] ?? 0;
        const change = previous ? value - previous.summary[key] : 0;
        return (
            <PageHeaderMeterBlock
                label={label}
                ariaLabel={`View ${label.toLowerCase()} history`}
                onClick={() => scrollTo('snapshot-timeline')}
                tone={value > 0 ? tone : 'brand'}
            >
                <PageHeaderMeterBig>{value}</PageHeaderMeterBig>
                {previous && change !== 0 ? (
                    <PageHeaderMeterDelta
                        trend={change > 0 ? 'up' : 'down'}
                        good={key === 'low' ? change > 0 : change < 0}
                    >
                        {Math.abs(change)} since last snapshot
                    </PageHeaderMeterDelta>
                ) : (
                    <PageHeaderMeterCaption>{caption}</PageHeaderMeterCaption>
                )}
            </PageHeaderMeterBlock>
        );
    };

    const header = (
        <PageHeader
            icon={TrendingUp}
            title="Risk trends"
            titleChip={
                latest ? (
                    <PageHeaderStatusChip variant="info">
                        Latest {snapshotDate(latest.snapshot_date)}
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="neutral">
                        No snapshots
                    </PageHeaderStatusChip>
                )
            }
            subline={`Risk profile across reporting snapshots · ${snapshots.length} recorded`}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Snapshots"
                        ariaLabel="View the snapshot timeline"
                        onClick={() => scrollTo('snapshot-timeline')}
                    >
                        <PageHeaderMeterBig>{snapshots.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {latest
                                ? `since ${snapshotDate(snapshots[snapshots.length - 1].snapshot_date)}`
                                : 'none recorded yet'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {meter('critical', 'Critical', 'latest snapshot', 'critical')}
                    {meter('high', 'High', 'latest snapshot', 'warning')}
                    {meter('medium', 'Medium', 'latest snapshot', 'warning')}
                    {meter('low', 'Low', 'latest snapshot', 'success')}
                    {meter(
                        'above_appetite',
                        'Above appetite',
                        'latest snapshot',
                        'critical',
                    )}
                </>
            }
            filters={
                <>
                    <RiskViewToggle value="trends" />
                    <PageHeaderFilterSelect
                        label="Range"
                        value={range}
                        allValue="12"
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
                { title: 'Risk register', href: '/governance/risks' },
                { title: 'Trends', href: '/governance/risks/trends' },
            ]}
        >
            <Head title="Risk trends" />

            <PageLayout hero={header}>
                {snapshots.length === 0 ? (
                    <EmptyState
                        icon={TrendingUp}
                        title="No risk snapshots recorded yet"
                        description="Snapshots of the active register are captured on the reporting schedule; trends appear once the first one is taken."
                    />
                ) : (
                    <div className="flex flex-col gap-5">
                        <Card>
                            <CardHeader>
                                <CardTitle>Critical + high risks</CardTitle>
                                <CardDescription>
                                    Count of critical and high risks per
                                    snapshot · {displaySnapshots.length} shown
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div
                                    className="flex h-40 items-end gap-2"
                                    role="img"
                                    aria-label="Critical and high risks per snapshot"
                                >
                                    {displaySnapshots
                                        .slice()
                                        .reverse()
                                        .map((snap) => {
                                            const total =
                                                snap.summary.critical +
                                                snap.summary.high;
                                            const pct =
                                                (total / maxCritHigh) * 100;
                                            return (
                                                <div
                                                    key={snap.id}
                                                    className="group relative flex h-full flex-1 items-end"
                                                    title={`${snapshotDate(snap.snapshot_date)}: ${snap.summary.critical} critical / ${snap.summary.high} high`}
                                                >
                                                    <div
                                                        className="w-full min-w-[12px] rounded-t bg-status-critical"
                                                        style={{
                                                            height: `${Math.max(pct, 4)}%`,
                                                        }}
                                                    />
                                                </div>
                                            );
                                        })}
                                </div>
                                <div className="text-caption mt-2 flex justify-between">
                                    <span>
                                        {snapshotDate(
                                            displaySnapshots[
                                                displaySnapshots.length - 1
                                            ].snapshot_date,
                                        )}
                                    </span>
                                    <span>
                                        {snapshotDate(
                                            displaySnapshots[0].snapshot_date,
                                        )}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>

                        {latest &&
                        Object.keys(latest.by_category ?? {}).length > 0 ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Risk by category</CardTitle>
                                    <CardDescription>
                                        Latest snapshot ·{' '}
                                        {snapshotDate(latest.snapshot_date)}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Category</TableHead>
                                                <TableHead className="text-center">
                                                    Count
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    Average score
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {Object.entries(
                                                latest.by_category,
                                            ).map(([category, data]) => (
                                                <TableRow key={category}>
                                                    <TableCell className="font-medium capitalize">
                                                        {category.replace(
                                                            /_/g,
                                                            ' ',
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-center tabular-nums">
                                                        {data.count}
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        <StatusBadge
                                                            variant={riskLevelVariant(
                                                                data.avg_score,
                                                            )}
                                                        >
                                                            {Number(
                                                                data.avg_score,
                                                            ).toFixed(1)}
                                                        </StatusBadge>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                            </Card>
                        ) : null}

                        <Card id="snapshot-timeline">
                            <CardHeader>
                                <CardTitle>Snapshot timeline</CardTitle>
                                <CardDescription>
                                    Last {displaySnapshots.length} snapshots
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2">
                                {displaySnapshots.map((snap) => (
                                    <div
                                        key={snap.id}
                                        className="flex flex-wrap items-center gap-4 rounded-lg border border-border p-3"
                                    >
                                        <span className="w-28 shrink-0 text-sm font-medium text-muted-foreground">
                                            {snapshotDate(snap.snapshot_date)}
                                        </span>
                                        <span className="flex flex-wrap gap-2">
                                            <StatusBadge variant="critical">
                                                {snap.summary.critical} critical
                                            </StatusBadge>
                                            <StatusBadge variant="warning">
                                                {snap.summary.high} high
                                            </StatusBadge>
                                            <StatusBadge variant="warning">
                                                {snap.summary.medium} medium
                                            </StatusBadge>
                                            <StatusBadge variant="success">
                                                {snap.summary.low} low
                                            </StatusBadge>
                                            {snap.summary.above_appetite > 0 ? (
                                                <StatusBadge variant="critical">
                                                    {
                                                        snap.summary
                                                            .above_appetite
                                                    }{' '}
                                                    above appetite
                                                </StatusBadge>
                                            ) : null}
                                        </span>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
