import {
    AlertTriangle,
    ArrowDown,
    ArrowRight,
    ArrowUp,
    CalendarClock,
    Check,
    CheckCircle2,
    TrendingDown,
    TrendingUp,
} from 'lucide-react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import { Card as GuardrailCard } from '@/components/ui/card';
import { catColorVar, relDay } from '../category';
import { Donut, LegendDot, MiniRing, SegmentDonut, Sparkline } from '../charts';
import { useChecklistConfig, type GoTab, type PaneCtx } from '../context';
import type { HeroStats } from '../hero';
import { Empty, StatusBadge } from '../primitives';

function miniKpi(value: number, label: string, tone: string) {
    return (
        <div className="rounded-lg bg-muted/50 px-3 py-2 text-center">
            <div
                className="text-lg font-bold tabular-nums"
                style={{ color: catColorVar(tone) }}
            >
                {value}
            </div>
            <div className="text-[10px] leading-tight text-muted-foreground">
                {label}
            </div>
        </div>
    );
}

export function OverviewPane({
    ctx,
    stats,
    goTab,
}: {
    ctx: PaneCtx;
    stats: HeroStats;
    goTab: GoTab;
}) {
    const { categoryMap, scope } = useChecklistConfig();
    const today = ctx.today;
    const overdue = ctx.runs.filter(
        (r) => r.scheduled_date && r.scheduled_date < today,
    );
    const dueToday = ctx.runs.filter((r) => r.scheduled_date === today);
    const inProg = ctx.runs.filter((r) => r.status === 'in_progress');
    const scheduled = ctx.runs.filter(
        (r) =>
            r.status === 'scheduled' &&
            r.scheduled_date &&
            r.scheduled_date > today,
    );
    const onTrack = stats.onTrack;

    const workSegs = [
        {
            key: 'completed',
            label: 'Completed',
            value: ctx.history.length,
            color: 'var(--status-success)',
        },
        {
            key: 'scheduled',
            label: 'Scheduled',
            value: scheduled.length,
            color: 'var(--primary)',
        },
        {
            key: 'inprog',
            label: 'In progress',
            value: inProg.length,
            color: 'var(--status-warning)',
        },
        {
            key: 'overdue',
            label: 'Overdue',
            value: overdue.length,
            color: 'var(--status-critical)',
        },
    ];
    const workTotal = workSegs.reduce((s, x) => s + x.value, 0);

    const doneSeries = ctx.reports.trend.map((t) => t.done);
    const lastDone = doneSeries[doneSeries.length - 1] ?? 0;
    const delta = lastDone - (doneSeries[doneSeries.length - 2] ?? lastDone);

    const siteAttention = ctx.sites
        .map((s) => ({
            site: s,
            overdue: ctx.runs.filter(
                (r) =>
                    r.site?.id === s.id &&
                    r.scheduled_date &&
                    r.scheduled_date < today,
            ).length,
            due: ctx.runs.filter(
                (r) => r.site?.id === s.id && r.scheduled_date === today,
            ).length,
        }))
        .filter((x) => x.overdue > 0 || x.due > 0)
        .sort((a, b) => b.overdue - a.overdue);

    const categoriesHealthy = ctx.reports.complianceByCategory.filter(
        (c) => c.rate >= 95,
    ).length;

    return (
        <div className="space-y-4">
            {/* analytics strip */}
            <div className="grid gap-4 lg:grid-cols-3">
                <GuardrailCard
                    unstyled
                    className="flex flex-col items-center rounded-xl border border-border bg-card p-5 shadow-sm"
                >
                    <div className="mb-3 flex w-full items-center justify-between">
                        <h3 className="text-sm font-semibold">
                            Compliance health
                        </h3>
                        <StatusBadge
                            tone={onTrack >= 90 ? 'success' : 'warning'}
                            Icon={onTrack >= 90 ? TrendingUp : TrendingDown}
                        >
                            {onTrack >= 90 ? 'Healthy' : 'Watch'}
                        </StatusBadge>
                    </div>
                    <Donut
                        value={onTrack}
                        size={150}
                        color={
                            onTrack >= 90
                                ? 'var(--status-success)'
                                : 'var(--primary)'
                        }
                        label="on track"
                        sub={scope.mode === 'org' ? 'all sites' : 'this home'}
                    />
                    <div className="mt-4 grid w-full grid-cols-2 gap-2">
                        {miniKpi(
                            categoriesHealthy,
                            'categories ≥95%',
                            'success',
                        )}
                        {miniKpi(
                            overdue.length,
                            'overdue items',
                            overdue.length ? 'critical' : 'success',
                        )}
                    </div>
                </GuardrailCard>

                <GuardrailCard
                    unstyled
                    className="rounded-xl border border-border bg-card p-5 shadow-sm"
                >
                    <h3 className="mb-3 text-sm font-semibold">Run workload</h3>
                    <div className="flex items-center gap-5">
                        <SegmentDonut
                            segments={workSegs}
                            size={132}
                            stroke={15}
                            centerValue={workTotal}
                            centerLabel="runs"
                        />
                        <div className="flex-1 space-y-1.5">
                            {workSegs.map((s) => (
                                <Button
                                    unstyled
                                    key={s.key}
                                    type="button"
                                    onClick={() =>
                                        goTab(
                                            s.key === 'overdue' ||
                                                s.key === 'inprog'
                                                ? 'due'
                                                : 'runs',
                                        )
                                    }
                                    className="w-full"
                                >
                                    <LegendDot
                                        color={s.color}
                                        label={s.label}
                                        value={s.value}
                                    />
                                </Button>
                            ))}
                        </div>
                    </div>
                </GuardrailCard>

                <GuardrailCard
                    unstyled
                    className="flex flex-col rounded-xl border border-border bg-card p-5 shadow-sm"
                >
                    <div className="mb-1 flex items-start justify-between">
                        <div>
                            <h3 className="text-sm font-semibold">
                                Completion trend
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                Runs completed · last 8 weeks
                            </p>
                        </div>
                        <div className="text-right">
                            <div className="text-2xl leading-none font-bold tabular-nums">
                                {lastDone}
                            </div>
                            <div
                                className={cn(
                                    'mt-0.5 flex items-center justify-end gap-0.5 text-[11px] font-medium',
                                    delta >= 0
                                        ? 'text-status-success'
                                        : 'text-status-critical',
                                )}
                            >
                                {delta >= 0 ? (
                                    <ArrowUp className="h-3 w-3" />
                                ) : (
                                    <ArrowDown className="h-3 w-3" />
                                )}
                                {Math.abs(delta)} vs last wk
                            </div>
                        </div>
                    </div>
                    <div className="mt-2 flex-1">
                        <Sparkline
                            series={doneSeries.length ? doneSeries : [0]}
                            color="var(--primary)"
                        />
                    </div>
                    <div className="mt-3 flex items-center justify-between border-t border-border pt-3 text-xs">
                        <span className="flex items-center gap-1.5 text-muted-foreground">
                            <span className="h-2 w-2 rounded-full bg-status-critical" />
                            {overdue.length} overdue now
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7"
                            onClick={() => goTab('reports')}
                        >
                            Analytics
                            <ArrowRight className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </GuardrailCard>
            </div>

            {/* category rings + needs attention */}
            <div className="grid gap-4 lg:grid-cols-3">
                <GuardrailCard
                    unstyled
                    className="rounded-xl border border-border bg-card shadow-sm lg:col-span-2"
                >
                    <div className="flex items-center justify-between border-b border-border px-5 py-3.5">
                        <div>
                            <h3 className="text-base font-semibold">
                                On-track by category
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {ctx.reports.complianceByCategory.length}{' '}
                                categories across the library
                            </p>
                        </div>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => goTab('library')}
                        >
                            Library
                            <ArrowRight className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                    <div className="grid grid-cols-1 gap-x-4 gap-y-2 p-4 sm:grid-cols-2 xl:grid-cols-3">
                        {ctx.reports.complianceByCategory.map((c) => (
                            <Button
                                unstyled
                                key={c.key}
                                type="button"
                                onClick={() => {
                                    ctx.setCat(c.key);
                                    goTab('library');
                                }}
                                className="flex items-center gap-3 rounded-lg border border-border p-2.5 text-left transition hover:border-primary/40 hover:bg-accent/40"
                            >
                                <MiniRing
                                    value={c.rate}
                                    size={42}
                                    color={catColorVar(c.tone)}
                                />
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-sm font-medium">
                                        {c.label}
                                    </div>
                                    {c.overdue > 0 ? (
                                        <div className="text-[11px] font-medium text-status-critical">
                                            {c.overdue} overdue
                                        </div>
                                    ) : (
                                        <div className="text-[11px] text-status-success">
                                            on track
                                        </div>
                                    )}
                                </div>
                            </Button>
                        ))}
                    </div>
                </GuardrailCard>

                <GuardrailCard
                    unstyled
                    className="rounded-xl border border-border bg-card shadow-sm"
                >
                    <div className="flex items-center justify-between border-b border-border px-5 py-3.5">
                        <h3 className="text-base font-semibold">
                            Needs attention
                        </h3>
                        {siteAttention.length ? (
                            <StatusBadge tone="critical">
                                {siteAttention.length} sites
                            </StatusBadge>
                        ) : (
                            <StatusBadge tone="success" Icon={Check}>
                                Clear
                            </StatusBadge>
                        )}
                    </div>
                    <div className="p-2">
                        {siteAttention.length === 0 ? (
                            <Empty
                                Icon={CheckCircle2}
                                title="All caught up"
                                sub="Nothing overdue or due."
                            />
                        ) : (
                            <div className="space-y-1">
                                {siteAttention.slice(0, 6).map((x) => (
                                    <Button
                                        unstyled
                                        key={x.site.id}
                                        type="button"
                                        onClick={() => goTab('due')}
                                        className="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left transition hover:bg-accent/50"
                                    >
                                        <div className="flex min-w-0 items-center gap-2.5">
                                            <span
                                                className={cn(
                                                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                                                    x.overdue
                                                        ? 'bg-status-critical-bg text-status-critical'
                                                        : 'bg-status-warning-bg text-status-warning',
                                                )}
                                            >
                                                {x.overdue ? (
                                                    <AlertTriangle className="h-3.5 w-3.5" />
                                                ) : (
                                                    <CalendarClock className="h-3.5 w-3.5" />
                                                )}
                                            </span>
                                            <div className="min-w-0">
                                                <div className="truncate text-sm font-medium">
                                                    {x.site.name}
                                                </div>
                                                <div className="truncate text-[11px] text-muted-foreground">
                                                    {x.overdue + x.due} waiting
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-1">
                                            {x.overdue ? (
                                                <span className="rounded-md bg-status-critical-bg px-1.5 py-0.5 text-[11px] font-semibold text-status-critical tabular-nums">
                                                    {x.overdue}
                                                </span>
                                            ) : null}
                                            {x.due ? (
                                                <span className="rounded-md bg-status-warning-bg px-1.5 py-0.5 text-[11px] font-semibold text-status-warning tabular-nums">
                                                    {x.due}
                                                </span>
                                            ) : null}
                                        </div>
                                    </Button>
                                ))}
                            </div>
                        )}
                    </div>
                </GuardrailCard>
            </div>

            {/* site performance (org) + recent activity */}
            <div className="grid gap-4 lg:grid-cols-3">
                {scope.mode === 'org' ? (
                    <GuardrailCard
                        unstyled
                        className="rounded-xl border border-border bg-card shadow-sm lg:col-span-2"
                    >
                        <div className="border-b border-border px-5 py-3.5">
                            <h3 className="text-base font-semibold">
                                Site performance
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                On-track rate and live load per site
                            </p>
                        </div>
                        <div className="grid gap-2.5 p-4 sm:grid-cols-2 xl:grid-cols-3">
                            {ctx.sites.map((s) => (
                                <Button
                                    unstyled
                                    key={s.id}
                                    type="button"
                                    onClick={() => goTab('assignments')}
                                    className="group flex items-center gap-3 rounded-lg border border-border p-3 text-left transition hover:border-primary/40 hover:shadow-sm"
                                >
                                    <MiniRing
                                        value={s.on_track_rate}
                                        size={46}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm font-medium group-hover:text-primary">
                                            {s.name}
                                        </div>
                                        <div className="mt-0.5 flex items-center gap-2 text-[11px] text-muted-foreground">
                                            <span>
                                                {s.active_assignments} assigned
                                            </span>
                                            {s.overdue_runs ? (
                                                <span className="font-medium text-status-critical">
                                                    {s.overdue_runs} overdue
                                                </span>
                                            ) : (
                                                <span className="text-status-success">
                                                    on track
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </Button>
                            ))}
                        </div>
                    </GuardrailCard>
                ) : null}

                <div
                    className={cn(
                        'rounded-xl border border-border bg-card shadow-sm',
                        scope.mode === 'org' ? '' : 'lg:col-span-3',
                    )}
                >
                    <div className="border-b border-border px-5 py-3.5">
                        <h3 className="text-base font-semibold">
                            Recent activity
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            Latest completed runs
                        </p>
                    </div>
                    <div className="p-2">
                        {ctx.history.length === 0 ? (
                            <Empty
                                Icon={CheckCircle2}
                                title="No completed runs yet"
                            />
                        ) : (
                            <div className="space-y-0.5">
                                {ctx.history.slice(0, 6).map((r) => {
                                    const failed = (r.items_failed ?? 0) > 0;
                                    return (
                                        <div
                                            key={r.id}
                                            className="flex items-center gap-2.5 rounded-lg px-3 py-2"
                                        >
                                            <span
                                                className={cn(
                                                    'flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
                                                    failed
                                                        ? 'bg-status-warning-bg text-status-warning'
                                                        : 'bg-status-success-bg text-status-success',
                                                )}
                                            >
                                                {failed ? (
                                                    <AlertTriangle className="h-3.5 w-3.5" />
                                                ) : (
                                                    <Check className="h-3.5 w-3.5" />
                                                )}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-sm font-medium">
                                                    {r.template?.name}
                                                </div>
                                                <div className="truncate text-[11px] text-muted-foreground">
                                                    {r.site?.name} ·{' '}
                                                    {relDay(
                                                        r.scheduled_date,
                                                        today,
                                                    )}
                                                </div>
                                            </div>
                                            {failed ? (
                                                <span className="shrink-0 text-[11px] font-medium text-status-warning">
                                                    {r.items_failed} failed
                                                </span>
                                            ) : (
                                                <span className="shrink-0 text-[11px] font-medium text-status-success">
                                                    100%
                                                </span>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
