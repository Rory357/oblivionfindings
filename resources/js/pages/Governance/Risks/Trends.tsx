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
import { formatDateLong, formatDateOnly } from '@/lib/datetime';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { TrendingUp } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    LabelList,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

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
    by_category: Array<{
        category: string;
        label: string;
        count: number;
        avg_score: number;
    }>;
}

interface Props extends PageProps {
    snapshots: Snapshot[];
    nextSnapshotOn: string;
}

type SummaryKey = keyof Snapshot['summary'];

const RANGE_OPTIONS = [
    { value: '12', label: 'Last 12 months' },
    { value: '6', label: 'Last 6 months' },
    { value: '3', label: 'Last 3 months' },
];

/** Semantic tokens only (DESIGN.md) — safety colours never retint. */
const TOKEN = {
    critical: 'var(--status-critical)',
    high: 'var(--status-warning)',
    grid: 'var(--border)',
    axis: 'var(--muted-foreground)',
    label: 'var(--foreground)',
    halo: 'var(--card)',
};

function monthOf(date: string): string {
    const [year, month] = date.slice(0, 10).split('-').map(Number);
    if (!year || !month) return date;
    return new Date(Date.UTC(year, month - 1, 15)).toLocaleDateString('en-NZ', {
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

function scrollTo(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function ChartTooltip({ active, payload, label }: any) {
    if (!active || !payload?.length) return null;
    return (
        // eslint-disable-next-line no-restricted-syntax -- chart tooltip popover, not a content card
        <div className="rounded-lg border border-border bg-card px-3 py-2 text-xs shadow-lg">
            <p className="mb-1 font-semibold text-foreground">{label}</p>
            {/* eslint-disable-next-line @typescript-eslint/no-explicit-any */}
            {payload.map((p: any) => (
                <p key={p.name} className="flex items-center gap-1.5 text-muted-foreground">
                    <span
                        className="inline-block h-2 w-2 rounded-full"
                        style={{ backgroundColor: p.fill }}
                    />
                    {p.name}
                    <span className="ml-auto pl-3 font-semibold text-foreground tabular-nums">
                        {p.value}
                    </span>
                </p>
            ))}
        </div>
    );
}

export default function RiskTrends({ snapshots, nextSnapshotOn }: Props) {
    const [range, setRange] = useState('12');
    const latest = snapshots[0] ?? null;
    const previous = snapshots[1] ?? null;
    const displaySnapshots = snapshots.slice(0, Number(range));
    const chartData = displaySnapshots
        .slice()
        .reverse()
        .map((snap) => ({
            month: monthOf(snap.snapshot_date),
            critical: snap.summary.critical,
            high: snap.summary.high,
        }));

    /** Latest value + movement since the previous month's record. */
    const meter = (
        key: SummaryKey,
        label: string,
        href: string,
        tone: 'critical' | 'warning' | 'success' | 'brand',
    ): ReactNode => {
        if (!latest) return null;
        const value = latest.summary[key] ?? 0;
        const change = previous ? value - (previous.summary[key] ?? 0) : 0;
        return (
            <PageHeaderMeterBlock
                label={label}
                ariaLabel={`View ${label.toLowerCase()} risks on the register now`}
                href={href}
                tone={value > 0 ? tone : 'brand'}
            >
                <PageHeaderMeterBig>{value}</PageHeaderMeterBig>
                {previous && change !== 0 ? (
                    <PageHeaderMeterDelta
                        trend={change > 0 ? 'up' : 'down'}
                        good={key === 'low' ? change > 0 : change < 0}
                    >
                        {Math.abs(change)} since {monthOf(previous.snapshot_date)}
                    </PageHeaderMeterDelta>
                ) : (
                    <PageHeaderMeterCaption>
                        on {formatDateOnly(latest.snapshot_date)}
                    </PageHeaderMeterCaption>
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
                        Last recorded {formatDateOnly(latest.snapshot_date)}
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="neutral">
                        No monthly records yet
                    </PageHeaderStatusChip>
                )
            }
            subline={`How the register changes month by month · recorded on the 1st of each month · ${snapshots.length} ${snapshots.length === 1 ? 'record' : 'records'}`}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Monthly records"
                        ariaLabel="View the month-by-month table"
                        onClick={() => scrollTo('risk-trend-table')}
                    >
                        <PageHeaderMeterBig>{snapshots.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            next on {formatDateOnly(nextSnapshotOn)}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {meter('critical', 'Critical', '/governance/risks?severity=critical', 'critical')}
                    {meter('high', 'High', '/governance/risks?severity=high', 'warning')}
                    {meter('medium', 'Medium', '/governance/risks?severity=medium', 'warning')}
                    {meter('low', 'Low', '/governance/risks?severity=low', 'success')}
                    {meter(
                        'above_appetite',
                        "Above the board's limit",
                        '/governance/risks?above_appetite=1',
                        'critical',
                    )}
                </>
            }
            filters={
                <>
                    <RiskViewToggle value="trends" />
                    <PageHeaderFilterSelect
                        label="Last 12 months"
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
                        title="No monthly records yet"
                        description={`Monthly risk records start on ${formatDateLong(nextSnapshotOn)}. From then, this page shows how the number of critical and high risks changes each month.`}
                    />
                ) : (
                    <div className="flex flex-col gap-5">
                        <Card>
                            <CardHeader>
                                <CardTitle>Critical and high risks each month</CardTitle>
                                <CardDescription>
                                    Risks on the register at each monthly record,
                                    by their score after controls ·{' '}
                                    {displaySnapshots.length} shown
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <figure
                                    className="m-0"
                                    role="group"
                                    aria-label="Critical and high risks each month"
                                >
                                    <ResponsiveContainer width="100%" height={240}>
                                        <BarChart
                                            data={chartData}
                                            margin={{ top: 8, right: 12, left: -14, bottom: 0 }}
                                        >
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                stroke={TOKEN.grid}
                                                vertical={false}
                                            />
                                            <XAxis
                                                dataKey="month"
                                                tick={{ fontSize: 11, fill: TOKEN.axis }}
                                                axisLine={false}
                                                tickLine={false}
                                            />
                                            <YAxis
                                                allowDecimals={false}
                                                tick={{ fontSize: 11, fill: TOKEN.axis }}
                                                axisLine={false}
                                                tickLine={false}
                                                width={30}
                                            />
                                            <Tooltip
                                                content={<ChartTooltip />}
                                                cursor={{ fill: 'var(--accent)', fillOpacity: 0.5 }}
                                            />
                                            <Legend wrapperStyle={{ fontSize: 11, paddingTop: 4 }} />
                                            <Bar
                                                dataKey="critical"
                                                name="Critical (20–25)"
                                                stackId="risks"
                                                fill={TOKEN.critical}
                                                maxBarSize={36}
                                                isAnimationActive={false}
                                            >
                                                <LabelList
                                                    dataKey="critical"
                                                    position="center"
                                                    formatter={(value: unknown) =>
                                                        Number(value) > 0 ? String(value) : ''
                                                    }
                                                    style={{
                                                        fontSize: 11,
                                                        fontWeight: 700,
                                                        fill: TOKEN.label,
                                                        stroke: TOKEN.halo,
                                                        strokeWidth: 3,
                                                        paintOrder: 'stroke',
                                                    }}
                                                />
                                            </Bar>
                                            <Bar
                                                dataKey="high"
                                                name="High (15–19)"
                                                stackId="risks"
                                                fill={TOKEN.high}
                                                radius={[3, 3, 0, 0]}
                                                maxBarSize={36}
                                                isAnimationActive={false}
                                            >
                                                <LabelList
                                                    dataKey="high"
                                                    position="center"
                                                    formatter={(value: unknown) =>
                                                        Number(value) > 0 ? String(value) : ''
                                                    }
                                                    style={{
                                                        fontSize: 11,
                                                        fontWeight: 700,
                                                        fill: TOKEN.label,
                                                        stroke: TOKEN.halo,
                                                        strokeWidth: 3,
                                                        paintOrder: 'stroke',
                                                    }}
                                                />
                                            </Bar>
                                        </BarChart>
                                    </ResponsiveContainer>
                                </figure>
                            </CardContent>
                        </Card>

                        <Card id="risk-trend-table" className="scroll-mt-5">
                            <CardHeader>
                                <CardTitle>Month by month</CardTitle>
                                <CardDescription>
                                    Risks on the register at each record, by score
                                    after controls
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Recorded</TableHead>
                                            <TableHead className="text-center">Critical</TableHead>
                                            <TableHead className="text-center">High</TableHead>
                                            <TableHead className="text-center">Medium</TableHead>
                                            <TableHead className="text-center">Low</TableHead>
                                            <TableHead className="text-center">
                                                Above the board&apos;s limit
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {displaySnapshots.map((snap) => (
                                            <TableRow key={snap.id}>
                                                <TableCell className="font-medium">
                                                    {formatDateLong(snap.snapshot_date)}
                                                </TableCell>
                                                <TableCell className="text-center tabular-nums">
                                                    {snap.summary.critical}
                                                </TableCell>
                                                <TableCell className="text-center tabular-nums">
                                                    {snap.summary.high}
                                                </TableCell>
                                                <TableCell className="text-center tabular-nums">
                                                    {snap.summary.medium}
                                                </TableCell>
                                                <TableCell className="text-center tabular-nums">
                                                    {snap.summary.low}
                                                </TableCell>
                                                <TableCell className="text-center tabular-nums">
                                                    {snap.summary.above_appetite}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>

                        {latest && latest.by_category.length > 0 ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>By kind of risk</CardTitle>
                                    <CardDescription>
                                        Recorded {formatDateLong(latest.snapshot_date)}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Kind of risk</TableHead>
                                                <TableHead className="text-center">
                                                    Risks
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    Average risk after controls
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {latest.by_category.map((row) => (
                                                <TableRow key={row.category}>
                                                    <TableCell className="font-medium">
                                                        {row.label}
                                                    </TableCell>
                                                    <TableCell className="text-center tabular-nums">
                                                        {row.count}
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        <StatusBadge
                                                            variant={riskLevelVariant(
                                                                row.avg_score,
                                                            )}
                                                        >
                                                            {Number(row.avg_score).toFixed(1)}
                                                        </StatusBadge>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
