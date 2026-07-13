import { TriangleAlert } from 'lucide-react';

import { catColorVar } from '../category';
import { useChecklistConfig, type PaneCtx } from '../context';
import { Donut } from '../charts';
import { CategoryDot, StatusBadge } from '../primitives';
import type { HeroStats } from '../hero';
import { Card as GuardrailCard } from '@/components/ui/card';

function legend(color: string, label: string) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span className="h-2.5 w-2.5 rounded-sm" style={{ background: color }} />
            {label}
        </span>
    );
}

function miniStat(value: number, label: string, tone: string) {
    return (
        <div className="rounded-lg bg-muted/50 px-2 py-2">
            <div className="text-lg font-bold tabular-nums" style={{ color: catColorVar(tone) }}>
                {value}
            </div>
            <div className="text-[10px] text-muted-foreground">{label}</div>
        </div>
    );
}

export function ReportsPane({ ctx, stats }: { ctx: PaneCtx; stats: HeroStats }) {
    const { categoryMap } = useChecklistConfig();
    const r = ctx.reports;
    const maxDone = Math.max(...r.trend.map((t) => t.done), 1);
    const completedWk = r.trend[r.trend.length - 1]?.done ?? 0;
    const hazardsRaised = r.topFailures.reduce((s, f) => s + f.hazards, 0);
    const hasActivity =
        r.trend.some((t) => t.done > 0 || t.overdue > 0) ||
        r.topFailures.length > 0 ||
        stats.overdue > 0 ||
        stats.dueToday > 0;

    return (
        <div className="space-y-4">
            {!hasActivity ? (
                <div className="rounded-xl border border-dashed border-border bg-muted/30 px-5 py-4 text-sm text-muted-foreground">
                    No completed or overdue runs yet — these reports fill in as checklists are run. Until then the
                    figures below show the baseline (100% on-track, nothing outstanding).
                </div>
            ) : null}
            <div className="grid gap-4 lg:grid-cols-3">
                <GuardrailCard unstyled className="rounded-xl border border-border bg-card shadow-sm lg:col-span-2">
                    <div className="border-b border-border px-5 py-3.5">
                        <h3 className="text-base font-semibold">Completed vs overdue</h3>
                        <p className="text-sm text-muted-foreground">Checklist runs over the last 8 weeks</p>
                    </div>
                    <div className="p-5">
                        <div className="flex items-end gap-3">
                            {r.trend.map((t, i) => (
                                <div key={i} className="flex flex-1 flex-col items-center gap-1.5">
                                    <div className="flex w-full items-end justify-center gap-1.5" style={{ height: 160 }}>
                                        <div
                                            title={`${t.done} completed`}
                                            style={{
                                                width: 14,
                                                borderRadius: '3px 3px 0 0',
                                                background: 'var(--primary)',
                                                height: `${Math.round((t.done / maxDone) * 158)}px`,
                                            }}
                                        />
                                        <div
                                            title={`${t.overdue} overdue`}
                                            style={{
                                                width: 14,
                                                borderRadius: '3px 3px 0 0',
                                                background: 'var(--status-critical)',
                                                height: `${Math.round((t.overdue / maxDone) * 158)}px`,
                                            }}
                                        />
                                    </div>
                                    <div className="text-[10px] text-muted-foreground">{t.w}</div>
                                </div>
                            ))}
                        </div>
                        <div className="mt-3 flex items-center gap-4 text-xs text-muted-foreground">
                            {legend('var(--primary)', 'Completed')}
                            {legend('var(--status-critical)', 'Overdue')}
                        </div>
                    </div>
                </GuardrailCard>

                <GuardrailCard unstyled className="rounded-xl border border-border bg-card shadow-sm">
                    <div className="border-b border-border px-5 py-3.5">
                        <h3 className="text-base font-semibold">Network on-track</h3>
                        <p className="text-sm text-muted-foreground">Weighted across all categories</p>
                    </div>
                    <div className="flex flex-col items-center p-5">
                        <Donut value={stats.onTrack} size={150} label="on track" />
                        <div className="mt-4 grid w-full grid-cols-2 gap-2 text-center">
                            {miniStat(completedWk, 'Completed · wk', 'success')}
                            {miniStat(stats.overdue, 'Overdue', 'critical')}
                            {miniStat(stats.dueToday, 'Due today', 'warning')}
                            {miniStat(hazardsRaised, 'Failures → hazards', 'info')}
                        </div>
                    </div>
                </GuardrailCard>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <GuardrailCard unstyled className="rounded-xl border border-border bg-card shadow-sm">
                    <div className="border-b border-border px-5 py-3.5">
                        <h3 className="text-base font-semibold">On-track by category</h3>
                    </div>
                    <div className="space-y-3 p-5">
                        {r.complianceByCategory.map((c) => (
                            <div key={c.key}>
                                <div className="mb-1 flex items-center justify-between text-sm">
                                    <span className="flex items-center gap-2">
                                        <CategoryDot category={c.key} />
                                        {c.label}
                                    </span>
                                    <span className="font-semibold tabular-nums">{c.rate}%</span>
                                </div>
                                <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full"
                                        style={{ width: `${c.rate}%`, background: catColorVar(c.tone) }}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                </GuardrailCard>

                <GuardrailCard unstyled className="rounded-xl border border-border bg-card shadow-sm">
                    <div className="border-b border-border px-5 py-3.5">
                        <h3 className="text-base font-semibold">Top failing items</h3>
                        <p className="text-sm text-muted-foreground">Most-failed checks and hazards raised</p>
                    </div>
                    {r.topFailures.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                            No failed checks recorded yet.
                        </p>
                    ) : (
                        <div className="divide-y divide-border">
                            {r.topFailures.map((f, i) => (
                                <div key={i} className="flex items-center gap-3 px-5 py-2.5">
                                    <CategoryDot category={f.cat} size={9} />
                                    <span className="min-w-0 flex-1 truncate text-sm">{f.item}</span>
                                    <span className="rounded-md border border-border px-1.5 py-0.5 text-[11px] font-medium tabular-nums text-muted-foreground">
                                        {f.count}×
                                    </span>
                                    {f.hazards ? (
                                        <StatusBadge tone="critical" Icon={TriangleAlert}>
                                            {f.hazards}
                                        </StatusBadge>
                                    ) : (
                                        <StatusBadge tone="neutral">0</StatusBadge>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </GuardrailCard>
            </div>
        </div>
    );
}
