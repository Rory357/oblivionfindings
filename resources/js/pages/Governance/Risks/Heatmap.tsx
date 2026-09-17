import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
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
import {
    riskImpactLabel,
    riskLevelLabel,
    riskLikelihoodLabel,
} from '@/lib/governance-labels';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Grid3x3 } from 'lucide-react';

import {
    RISK_BANDS,
    RiskScoreExplainer,
    RiskViewToggle,
    riskBand,
    type RiskBand,
} from './_shared';

interface HeatmapCell {
    score: number;
    count: number;
    likelihood: number;
    impact: number;
}

interface TrendPoint {
    month: string;
    new_risks: number;
}

type Basis = 'before' | 'after';

interface Props extends PageProps {
    heatmap: HeatmapCell[][];
    bands: Record<Basis, Record<RiskBand, number>>;
    trend: TrendPoint[];
    categories?: Array<{ value: string; label: string }>;
    filters?: { category?: string; include_closed?: string; score?: Basis };
}

const BASIS_LABEL: Record<Basis, string> = {
    before: 'before controls',
    after: 'after controls',
};

/**
 * Cell look per band — a word in every cell so colour is never the only
 * signal, and High gets its own dashed border so it reads apart from Medium.
 * Safety colours never retint with branding.
 */
function cellTone(band: RiskBand): string {
    switch (band) {
        case 'critical':
            return 'border-2 border-status-critical bg-status-critical-bg text-status-critical';
        case 'high':
            return 'border-2 border-dashed border-status-warning bg-status-warning-bg text-status-warning';
        case 'medium':
            return 'border border-status-warning/40 bg-status-warning-bg/50 text-status-warning';
        default:
            return 'border border-status-success/40 bg-status-success-bg text-status-success';
    }
}

function monthLabel(month: string): string {
    const [year, m] = month.split('-').map(Number);
    if (!year || !m) return month;
    return new Date(Date.UTC(year, m - 1, 15)).toLocaleDateString('en-NZ', {
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

export default function RiskHeatmap({
    heatmap,
    bands,
    trend,
    categories = [],
    filters = {},
}: Props) {
    const basis: Basis = filters.score === 'after' ? 'after' : 'before';
    const includeClosed = filters.include_closed === '1';
    const cells = heatmap.flat();
    const total = cells.reduce((sum, c) => sum + c.count, 0);
    const counts = bands[basis];
    const recentTrend = trend.slice(-6);
    const trendMax = Math.max(1, ...recentTrend.map((p) => p.new_risks));
    const categoryLabel = categories.find(
        (c) => c.value === filters.category,
    )?.label;

    // Links into the register keep the same scope as these counts.
    const registerHref = (params: Record<string, string | number | undefined>) => {
        const query = new URLSearchParams();
        if (filters.category) query.set('category', filters.category);
        if (includeClosed) query.set('status', 'all');
        Object.entries(params).forEach(([key, value]) => {
            if (value !== undefined) query.set(key, String(value));
        });
        const qs = query.toString();
        return `/governance/risks${qs ? `?${qs}` : ''}`;
    };

    const go = (patch: { category?: string; include_closed?: string; score?: Basis }) => {
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
                total === 0 ? (
                    <PageHeaderStatusChip variant="neutral">
                        No risks to show
                    </PageHeaderStatusChip>
                ) : counts.critical > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {counts.critical} critical {BASIS_LABEL[basis]}
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        None critical {BASIS_LABEL[basis]}
                    </PageHeaderStatusChip>
                )
            }
            subline={`Where risks sit by likelihood and impact · ${categoryLabel ?? 'All kinds of risk'} · ${includeClosed ? 'including closed risks' : 'open and accepted risks'}`}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="On the heatmap"
                        ariaLabel="View these risks on the register"
                        href={registerHref({})}
                    >
                        <PageHeaderMeterBig>{total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {includeClosed ? 'including closed' : 'open and accepted'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {RISK_BANDS.map((band) => (
                        <PageHeaderMeterBlock
                            key={band.key}
                            label={`${riskLevelLabel(band.key)} (${BASIS_LABEL[basis]})`}
                            ariaLabel={`View ${riskLevelLabel(band.key).toLowerCase()} risks ${BASIS_LABEL[basis]} on the register`}
                            href={registerHref({ severity: band.key, score: basis })}
                            tone={
                                counts[band.key] > 0
                                    ? band.key === 'critical'
                                        ? 'critical'
                                        : band.key === 'low'
                                          ? 'success'
                                          : 'warning'
                                    : 'brand'
                            }
                        >
                            <PageHeaderMeterBig>{counts[band.key]}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                score {band.range}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))}
                </>
            }
            filters={
                <>
                    <RiskViewToggle value="heatmap" />
                    <PageHeaderFilterSelect
                        label="All kinds of risk"
                        value={filters.category ?? 'all'}
                        options={[
                            { value: 'all', label: 'All kinds of risk' },
                            ...categories,
                        ]}
                        onChange={(v) =>
                            go({ category: v === 'all' ? undefined : v })
                        }
                    />
                    <PageHeaderViewToggle<Basis>
                        ariaLabel="Which score the levels use"
                        value={basis}
                        onChange={(value) => go({ score: value })}
                        options={[
                            { value: 'before', label: 'Before controls' },
                            { value: 'after', label: 'After controls' },
                        ]}
                    />
                    <PageHeaderFilterCheck
                        label="Include closed"
                        checked={includeClosed}
                        onChange={(checked) =>
                            go({ include_closed: checked ? '1' : undefined })
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
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <CardTitle>
                                        Risks before controls (likelihood × impact)
                                    </CardTitle>
                                    <CardDescription>
                                        Each square counts the risks assessed at
                                        that likelihood and impact. Controls lower
                                        the score, not the likelihood or impact, so
                                        this grid always shows risk before
                                        controls. Select a square to see its risks.
                                    </CardDescription>
                                </div>
                                <RiskScoreExplainer />
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="scrollbar-pretty overflow-x-auto">
                                <div
                                    role="table"
                                    aria-label="Risks before controls by likelihood and impact"
                                    className="grid min-w-[600px] gap-1.5"
                                    style={{
                                        gridTemplateColumns:
                                            '120px repeat(5, minmax(0, 1fr))',
                                    }}
                                >
                                    <div role="row" className="contents">
                                        <span
                                            role="columnheader"
                                            className="text-caption self-end pb-1 text-right"
                                        >
                                            Likelihood ↓ · Impact →
                                        </span>
                                        {[1, 2, 3, 4, 5].map((impact) => (
                                            <span
                                                key={impact}
                                                role="columnheader"
                                                className="text-caption pb-1 text-center"
                                            >
                                                {impact} · {riskImpactLabel(String(impact))}
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
                                                {row[0]?.likelihood} ·{' '}
                                                {riskLikelihoodLabel(String(row[0]?.likelihood))}
                                            </span>
                                            {row.map((cell) => {
                                                const band = riskBand(cell.score);
                                                const place = `${riskLikelihoodLabel(String(cell.likelihood))} × ${riskImpactLabel(String(cell.impact))}`;
                                                const label = `${cell.count} ${cell.count === 1 ? 'risk' : 'risks'}: ${place}, score ${cell.score} (${riskLevelLabel(band)})`;
                                                const body = (
                                                    <>
                                                        <span className="text-base leading-none font-bold">
                                                            {cell.count}
                                                        </span>
                                                        <span className="mt-1 text-xs leading-none font-medium">
                                                            {riskLevelLabel(band)} · {cell.score}
                                                        </span>
                                                    </>
                                                );
                                                const classes = cn(
                                                    'flex h-16 flex-col items-center justify-center rounded-md tabular-nums',
                                                    cellTone(band),
                                                );
                                                return cell.count > 0 ? (
                                                    <Link
                                                        key={`${cell.likelihood}-${cell.impact}`}
                                                        role="cell"
                                                        href={registerHref({
                                                            likelihood: cell.likelihood,
                                                            impact: cell.impact,
                                                        })}
                                                        aria-label={label}
                                                        className={cn(
                                                            classes,
                                                            'outline-none transition-transform hover:-translate-y-0.5 focus-visible:ring-2 focus-visible:ring-ring',
                                                        )}
                                                    >
                                                        {body}
                                                    </Link>
                                                ) : (
                                                    <span
                                                        key={`${cell.likelihood}-${cell.impact}`}
                                                        role="cell"
                                                        aria-label={`No risks: ${place}, score ${cell.score} (${riskLevelLabel(band)})`}
                                                        className={cn(classes, 'opacity-50')}
                                                    >
                                                        {body}
                                                    </span>
                                                );
                                            })}
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div className="mt-5 flex flex-wrap items-center justify-center gap-4">
                                {RISK_BANDS.map((band) => (
                                    <span
                                        key={band.key}
                                        className="flex items-center gap-2"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'h-4 w-4 rounded-[4px]',
                                                cellTone(band.key),
                                            )}
                                        />
                                        <span className="text-subtle">
                                            {riskLevelLabel(band.key)} ({band.range})
                                        </span>
                                    </span>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex flex-col gap-5">
                        <Card>
                            <CardHeader>
                                <CardTitle>Risk levels {BASIS_LABEL[basis]}</CardTitle>
                                <CardDescription>
                                    Use the switch in the header to compare before
                                    and after controls
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3">
                                {RISK_BANDS.map((band) => (
                                    <Link
                                        key={band.key}
                                        href={registerHref({ severity: band.key, score: basis })}
                                        className="flex items-center justify-between rounded-md px-1 py-0.5 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        <span className="text-sm">
                                            {riskLevelLabel(band.key)}{' '}
                                            <span className="text-caption">{band.range}</span>
                                        </span>
                                        <StatusBadge
                                            variant={
                                                band.key === 'critical'
                                                    ? 'critical'
                                                    : band.key === 'low'
                                                      ? 'success'
                                                      : 'warning'
                                            }
                                        >
                                            {counts[band.key]}
                                        </StatusBadge>
                                    </Link>
                                ))}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>New risks added</CardTitle>
                                <CardDescription>
                                    Each month, for the last 6 months
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
                                    <ul className="flex flex-col gap-2">
                                        {recentTrend.map((point) => (
                                            <li
                                                key={point.month}
                                                className="flex items-center gap-2"
                                            >
                                                <span className="text-caption w-20">
                                                    {monthLabel(point.month)}
                                                </span>
                                                <span
                                                    aria-hidden="true"
                                                    className="h-3 flex-1 overflow-hidden rounded-full bg-muted"
                                                >
                                                    <span
                                                        className="block h-full rounded-full bg-primary"
                                                        style={{
                                                            width: `${(point.new_risks / trendMax) * 100}%`,
                                                        }}
                                                    />
                                                </span>
                                                <span className="w-6 text-right text-xs font-semibold tabular-nums">
                                                    {point.new_risks}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
