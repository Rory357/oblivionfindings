import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    PageHeader,
    PageHeaderFilterCheck,
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
import AppLayout from '@/layouts/app-layout';
import { riskScoreLevel } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Grid3x3 } from 'lucide-react';

import { RiskViewToggle, riskLevelVariant } from './_shared';

interface HeatmapCell {
    score: number;
    count: number;
}

interface TrendPoint {
    month: string;
    new_risks: number;
}

interface Props extends PageProps {
    heatmap: HeatmapCell[][];
    trend: TrendPoint[];
    categories?: Array<{ value: string; label: string }>;
    filters?: { category?: string; active?: string };
}

const IMPACT_LABELS = [
    'Insignificant',
    'Minor',
    'Moderate',
    'Major',
    'Catastrophic',
];
const LIKELIHOOD_LABELS = [
    'Almost certain',
    'Likely',
    'Possible',
    'Unlikely',
    'Rare',
];

const LEVELS = ['Critical', 'High', 'Medium', 'Low'] as const;

const LEVEL_RANGE: Record<(typeof LEVELS)[number], string> = {
    Critical: '20–25',
    High: '15–19',
    Medium: '10–14',
    Low: '1–9',
};

/** Token pair per band — safety colours never retint with branding. */
function cellTone(score: number): string {
    const level = riskScoreLevel(score);
    if (level === 'Critical')
        return 'border-status-critical/40 bg-status-critical-bg text-status-critical';
    if (level === 'High')
        return 'border-status-warning/60 bg-status-warning-bg text-status-warning';
    if (level === 'Medium')
        return 'border-status-warning/25 bg-status-warning-bg text-status-warning';
    return 'border-status-success/30 bg-status-success-bg text-status-success';
}

function monthLabel(month: string): string {
    const [year, m] = month.split('-').map(Number);
    if (!year || !m) return month;
    return new Date(Date.UTC(year, m - 1, 15)).toLocaleDateString('en-NZ', {
        month: 'short',
        year: '2-digit',
        timeZone: 'UTC',
    });
}

export default function RiskHeatmap({
    heatmap,
    trend,
    categories = [],
    filters = {},
}: Props) {
    const cells = heatmap.flat();
    const total = cells.reduce((sum, c) => sum + c.count, 0);
    const byLevel = cells.reduce<Record<string, number>>((acc, cell) => {
        const level = riskScoreLevel(cell.score);
        acc[level] = (acc[level] ?? 0) + cell.count;
        return acc;
    }, {});
    const recentTrend = trend.slice(-6);
    const trendMax = Math.max(1, ...recentTrend.map((p) => p.new_risks));
    const categoryLabel = categories.find(
        (c) => c.value === filters.category,
    )?.label;

    const scrollToMatrix = () =>
        document
            .getElementById('risk-matrix')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

    const go = (patch: { category?: string; active?: string }) => {
        const next = { ...filters, ...patch };
        router.get(
            '/governance/risks/heatmap',
            Object.fromEntries(
                Object.entries(next).filter(([, v]) => v != null && v !== ''),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const header = (
        <PageHeader
            icon={Grid3x3}
            title="Risk heatmap"
            titleChip={
                (byLevel.Critical ?? 0) > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {byLevel.Critical} critical
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        No critical risks
                    </PageHeaderStatusChip>
                )
            }
            subline={`Likelihood × impact distribution · ${categoryLabel ?? 'All categories'} · ${filters.active ? 'active risks' : 'all statuses'}`}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="On the matrix"
                        ariaLabel="View the risk register"
                        href="/governance/risks"
                    >
                        <PageHeaderMeterBig>{total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            risks assessed
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Critical"
                        ariaLabel="View the critical band on the matrix"
                        onClick={scrollToMatrix}
                        tone={(byLevel.Critical ?? 0) > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {byLevel.Critical ?? 0}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Inherent 20–25
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="High"
                        ariaLabel="View the high band on the matrix"
                        onClick={scrollToMatrix}
                        tone={(byLevel.High ?? 0) > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{byLevel.High ?? 0}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Inherent 15–19
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Medium"
                        ariaLabel="View the medium band on the matrix"
                        onClick={scrollToMatrix}
                    >
                        <PageHeaderMeterBig>
                            {byLevel.Medium ?? 0}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Inherent 10–14
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Low"
                        ariaLabel="View the low band on the matrix"
                        onClick={scrollToMatrix}
                        tone="success"
                    >
                        <PageHeaderMeterBig>{byLevel.Low ?? 0}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>Inherent 1–9</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <RiskViewToggle value="heatmap" />
                    <PageHeaderFilterSelect
                        label="Category"
                        value={filters.category ?? 'all'}
                        options={[
                            { value: 'all', label: 'All categories' },
                            ...categories,
                        ]}
                        onChange={(v) =>
                            go({ category: v === 'all' ? undefined : v })
                        }
                    />
                    <PageHeaderFilterCheck
                        label="Active only"
                        checked={filters.active === '1'}
                        onChange={(checked) =>
                            go({ active: checked ? '1' : undefined })
                        }
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
                { title: 'Heatmap', href: '/governance/risks/heatmap' },
            ]}
        >
            <Head title="Risk heatmap" />

            <PageLayout hero={header}>
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <Card id="risk-matrix" className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Inherent risk matrix</CardTitle>
                            <CardDescription>
                                Each cell counts the risks assessed at that
                                likelihood and impact (score = likelihood ×
                                impact).
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="scrollbar-pretty overflow-x-auto">
                                <div
                                    role="table"
                                    aria-label="Risk matrix"
                                    className="grid min-w-[560px] gap-1.5"
                                    style={{
                                        gridTemplateColumns:
                                            '112px repeat(5, minmax(0, 1fr))',
                                    }}
                                >
                                    <div role="row" className="contents">
                                        <span
                                            role="columnheader"
                                            className="text-caption self-end pb-1 text-right uppercase"
                                        >
                                            Likelihood ↓ / Impact →
                                        </span>
                                        {IMPACT_LABELS.map((label) => (
                                            <span
                                                key={label}
                                                role="columnheader"
                                                className="text-caption pb-1 text-center"
                                            >
                                                {label}
                                            </span>
                                        ))}
                                    </div>
                                    {heatmap.map((row, rowIndex) => (
                                        <div
                                            key={rowIndex}
                                            role="row"
                                            className="contents"
                                        >
                                            <span
                                                role="rowheader"
                                                className="text-caption flex items-center justify-end pr-2 text-right"
                                            >
                                                {LIKELIHOOD_LABELS[rowIndex]}
                                            </span>
                                            {row.map((cell, colIndex) => (
                                                <span
                                                    key={colIndex}
                                                    role="cell"
                                                    title={`${LIKELIHOOD_LABELS[rowIndex]} × ${IMPACT_LABELS[colIndex]}: score ${cell.score}, ${cell.count} ${cell.count === 1 ? 'risk' : 'risks'}`}
                                                    className={cn(
                                                        'flex h-16 flex-col items-center justify-center rounded-md border tabular-nums',
                                                        cellTone(cell.score),
                                                        cell.count === 0 &&
                                                            'opacity-45',
                                                    )}
                                                >
                                                    <span className="text-base leading-none font-bold">
                                                        {cell.count}
                                                    </span>
                                                    <span className="mt-1 text-[10.5px] leading-none font-medium">
                                                        score {cell.score}
                                                    </span>
                                                </span>
                                            ))}
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div className="mt-5 flex flex-wrap items-center justify-center gap-3">
                                {LEVELS.map((level) => (
                                    <span
                                        key={level}
                                        className="flex items-center gap-2"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'h-3.5 w-3.5 rounded-[4px] border',
                                                cellTone(
                                                    level === 'Critical'
                                                        ? 20
                                                        : level === 'High'
                                                          ? 15
                                                          : level === 'Medium'
                                                            ? 10
                                                            : 1,
                                                ),
                                            )}
                                        />
                                        <span className="text-subtle">
                                            {level} ({LEVEL_RANGE[level]})
                                        </span>
                                    </span>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex flex-col gap-5">
                        <Card>
                            <CardHeader>
                                <CardTitle>Risk distribution</CardTitle>
                                <CardDescription>
                                    Risks per inherent band
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3">
                                {LEVELS.map((level) => (
                                    <div
                                        key={level}
                                        className="flex items-center justify-between"
                                    >
                                        <span className="text-sm">
                                            {level}{' '}
                                            <span className="text-caption">
                                                {LEVEL_RANGE[level]}
                                            </span>
                                        </span>
                                        <StatusBadge
                                            variant={riskLevelVariant(
                                                level === 'Critical'
                                                    ? 20
                                                    : level === 'Low'
                                                      ? 1
                                                      : 10,
                                            )}
                                        >
                                            {byLevel[level] ?? 0}
                                        </StatusBadge>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>New risks</CardTitle>
                                <CardDescription>
                                    Identified per month · last 6 months
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {recentTrend.every((p) => p.new_risks === 0) ? (
                                    <EmptyState
                                        variant="compact"
                                        icon={Grid3x3}
                                        title="No new risks in the last 6 months"
                                    />
                                ) : (
                                    <div className="flex flex-col gap-2">
                                        {recentTrend.map((point) => (
                                            <div
                                                key={point.month}
                                                className="flex items-center gap-2"
                                            >
                                                <span className="text-caption w-14">
                                                    {monthLabel(point.month)}
                                                </span>
                                                <div className="h-3 flex-1 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className="h-full rounded-full bg-primary"
                                                        style={{
                                                            width: `${(point.new_risks / trendMax) * 100}%`,
                                                        }}
                                                    />
                                                </div>
                                                <span className="w-6 text-right text-xs font-semibold tabular-nums">
                                                    {point.new_risks}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
