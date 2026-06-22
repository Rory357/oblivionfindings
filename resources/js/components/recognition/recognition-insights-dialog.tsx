/* eslint-disable no-restricted-syntax -- Compact KPI tiles, value bars and
 * leaderboard rows are bespoke on-card layout surfaces (raw divs) styled with
 * semantic tokens; a generic shadcn <Card> would over-pad them. */
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { Sparkles, Trophy } from 'lucide-react';

export type InsightsMetrics = {
    kudos_this_month: number;
    participation: number;
    celebrations: number;
    posts_this_week: number;
};

export type InsightsValue = { key: string; label: string; count: number };
export type InsightsLeader = { user_id: number; user_name: string; kudos_count: number };
export type InsightsTrend = { label: string; count: number };

function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((p) => p[0]?.toUpperCase() ?? '')
            .join('') || '?'
    );
}

/**
 * Recognition insights — a lightweight read-only modal opened from the feed hero's
 * "View insights" action. Surfaces this month's recognition picture (the four
 * KPIs, the most-recognised values, and the leaderboard) to anyone who can view
 * the feed — no analytics permission required.
 */
export function RecognitionInsightsDialog({
    open,
    onClose,
    metrics,
    valueBreakdown,
    kudosTrend,
    leaderboard,
}: {
    open: boolean;
    onClose: () => void;
    metrics: InsightsMetrics;
    valueBreakdown: InsightsValue[];
    kudosTrend: InsightsTrend[];
    leaderboard: InsightsLeader[];
}) {
    const maxValue = Math.max(1, ...valueBreakdown.map((v) => v.count));
    const hasValues = valueBreakdown.some((v) => v.count > 0);
    const trendMax = Math.max(1, ...kudosTrend.map((w) => w.count));
    const hasTrend = kudosTrend.some((w) => w.count > 0);

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent style={{ maxWidth: 'min(94vw, 640px)' }}>
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Sparkles className="h-5 w-5 text-primary" />
                        Recognition insights
                    </DialogTitle>
                    <DialogDescription>This month at a glance.</DialogDescription>
                </DialogHeader>

                {/* KPI tiles */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Kpi label="Kudos" value={metrics.kudos_this_month} />
                    <Kpi label="Participation" value={`${metrics.participation}%`} />
                    <Kpi label="Celebrations" value={metrics.celebrations} />
                    <Kpi label="Posts / wk" value={metrics.posts_this_week} />
                </div>

                {/* Most recognised values */}
                <section className="mt-2">
                    <h3 className="mb-2 text-sm font-semibold">Most recognised values</h3>
                    {hasValues ? (
                        <ul className="space-y-2">
                            {valueBreakdown.map((v) => (
                                <li key={v.key} className="flex items-center gap-3">
                                    <span className="w-32 shrink-0 truncate text-[13px] text-muted-foreground">
                                        {v.label}
                                    </span>
                                    <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full bg-primary transition-[width] duration-500"
                                            style={{ width: `${(v.count / maxValue) * 100}%` }}
                                        />
                                    </div>
                                    <span className="w-6 shrink-0 text-right text-[13px] font-semibold tabular-nums">
                                        {v.count}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground">No kudos given yet this month.</p>
                    )}
                </section>

                {/* Kudos trend — last 8 weeks */}
                <section className="mt-2">
                    <h3 className="mb-2 text-sm font-semibold">Kudos over the last 8 weeks</h3>
                    {hasTrend ? (
                        <div className="flex h-20 items-end gap-1.5">
                            {kudosTrend.map((w, i) => (
                                <div
                                    key={i}
                                    className="flex flex-1 flex-col items-center gap-1"
                                    title={`${w.label}: ${w.count} kudos`}
                                >
                                    <div className="flex w-full flex-1 items-end">
                                        <div
                                            className="w-full rounded-t-sm bg-primary/80 transition-[height] duration-500"
                                            style={{ height: `${(w.count / trendMax) * 100}%` }}
                                        />
                                    </div>
                                    <span className="text-[9px] text-muted-foreground">{w.label}</span>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">No kudos in the last 8 weeks.</p>
                    )}
                </section>

                {/* Top recognised */}
                <section>
                    <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold">
                        <Trophy className="h-4 w-4 text-status-warning" /> Top recognised
                    </h3>
                    {leaderboard.length > 0 ? (
                        <ul className="space-y-2">
                            {leaderboard.slice(0, 5).map((entry, i) => (
                                <li key={entry.user_id} className="flex items-center gap-3">
                                    <span
                                        className={cn(
                                            'grid h-6 w-6 flex-none place-items-center rounded-full text-xs font-bold',
                                            i === 0
                                                ? 'bg-status-warning-bg text-status-warning'
                                                : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {i + 1}
                                    </span>
                                    <span className="grid h-7 w-7 flex-none place-items-center rounded-full bg-muted text-[11px] font-bold">
                                        {initials(entry.user_name)}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate text-sm font-medium">
                                        {entry.user_name}
                                    </span>
                                    <span className="text-sm font-semibold tabular-nums">{entry.kudos_count}</span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground">No kudos yet this month.</p>
                    )}
                </section>
            </DialogContent>
        </Dialog>
    );
}

function Kpi({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="rounded-xl border border-border bg-card/60 p-3">
            <div className="text-[10px] font-bold uppercase tracking-[0.08em] text-muted-foreground">
                {label}
            </div>
            <div className="mt-0.5 text-xl font-bold tabular-nums">{value}</div>
        </div>
    );
}

export default RecognitionInsightsDialog;
