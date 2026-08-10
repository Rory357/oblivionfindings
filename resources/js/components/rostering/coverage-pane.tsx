import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

import { MicroStats, type MicroStat } from './micro-stats';

export type CoverageCellState = 'ok' | 'partial' | 'gap';

export type CoverageCell = {
    state: CoverageCellState;
    label: string;
    sub: string;
};

export type CoverageRow = {
    site: string;
    windows: CoverageCell[];
};

export type CoverageAlertSummary = {
    site_name: string;
    rule_name: string;
    window_label: string;
    required_staff: number;
    assigned_staff: number;
    planned_staff?: number | null;
    missing_staff: number;
    coverage_state: CoverageCellState | string;
    gap_kind?: string | null;
};

export type CoveragePaneProps = {
    stats: MicroStat[];
    windowLabels: string[];
    rows: CoverageRow[];
    alerts?: CoverageAlertSummary[];
    actionEndSlot?: ReactNode;
};

const STATE_BG: Record<CoverageCellState, string> = {
    ok: 'bg-status-info-bg',
    partial: 'bg-status-warning-bg',
    gap: 'bg-status-critical-bg',
};

const PILL_STATE: Record<CoverageCellState, string> = {
    ok: 'bg-status-info text-white',
    partial: 'bg-status-warning text-white',
    gap: 'bg-status-critical text-white',
};

export function CoveragePane({
    stats,
    windowLabels,
    rows,
    alerts = [],
    actionEndSlot,
}: CoveragePaneProps) {
    const visibleAlerts = alerts.filter(
        (alert) => alert.missing_staff > 0 || alert.coverage_state !== 'ok',
    );

    return (
        <div className="space-y-4">
            <MicroStats stats={stats} />
            {visibleAlerts.length > 0 ? (
                <section className="rounded-[14px] border border-status-critical/30 bg-status-critical-bg/30 p-4">
                    <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h3 className="text-sm font-bold tracking-tight">
                                Coverage gaps this week
                            </h3>
                            <div className="text-[11px] text-muted-foreground">
                                All under-covered windows returned for this
                                roster
                            </div>
                        </div>
                        <span className="rounded-full bg-background/70 px-2 py-0.5 text-[11px] font-semibold text-muted-foreground tabular-nums">
                            {visibleAlerts.length}
                        </span>
                    </div>
                    <div className="grid gap-2 md:grid-cols-2">
                        {visibleAlerts.map((alert, index) => (
                            <CoverageAlertRow
                                key={`${alert.site_name}-${alert.rule_name}-${alert.window_label}-${index}`}
                                alert={alert}
                            />
                        ))}
                    </div>
                </section>
            ) : null}
            <div className="overflow-hidden rounded-[14px] border border-border bg-card">
                <div
                    className="grid border-b border-border bg-muted/50"
                    style={{
                        gridTemplateColumns: `200px repeat(${windowLabels.length}, minmax(0, 1fr))`,
                    }}
                >
                    <div className="px-3 py-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                        Site
                    </div>
                    {windowLabels.map((w) => (
                        <div
                            key={w}
                            className="px-3 py-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            {w}
                        </div>
                    ))}
                </div>
                {rows.length === 0 ? (
                    <div className="p-6 text-center text-sm text-muted-foreground">
                        No coverage windows configured for this week.
                    </div>
                ) : null}
                {rows.map((row, ri) => (
                    <div
                        key={ri}
                        className="grid border-t border-border"
                        style={{
                            gridTemplateColumns: `200px repeat(${windowLabels.length}, minmax(0, 1fr))`,
                        }}
                    >
                        <div className="border-r border-border bg-muted/30 px-3 py-3 text-sm font-medium">
                            {row.site}
                        </div>
                        {row.windows.map((cell, ci) => (
                            <div
                                key={ci}
                                className={cn(
                                    'border-r border-border p-3 last:border-r-0',
                                    STATE_BG[cell.state],
                                )}
                            >
                                <div
                                    className={cn(
                                        'inline-flex items-center justify-center rounded-md px-2 py-0.5 text-xs font-bold tabular-nums',
                                        PILL_STATE[cell.state],
                                    )}
                                >
                                    {cell.label}
                                </div>
                                <div className="mt-1 text-[11px] text-muted-foreground">
                                    {cell.sub}
                                </div>
                            </div>
                        ))}
                    </div>
                ))}
            </div>
            <div className="flex flex-wrap items-center gap-4 text-[11px] text-muted-foreground">
                <LegendDot color="var(--status-info)" label="Filled" />
                <LegendDot
                    color="var(--status-warning)"
                    label="Partial · attention needed"
                />
                <LegendDot
                    color="var(--status-critical)"
                    label="Gap · action required"
                />
                {actionEndSlot}
            </div>
        </div>
    );
}

function CoverageAlertRow({ alert }: { alert: CoverageAlertSummary }) {
    const missing = Math.max(0, alert.missing_staff);
    const tone: CoverageCellState = missing > 0 ? 'gap' : 'partial';

    return (
        <article
            className={cn(
                'rounded-md border bg-card p-3',
                tone === 'gap'
                    ? 'border-status-critical/30'
                    : 'border-status-warning/30',
            )}
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                    <div className="truncate text-sm font-semibold">
                        {alert.site_name}
                    </div>
                    <div className="truncate text-xs text-muted-foreground">
                        {alert.rule_name}
                    </div>
                </div>
                <span
                    className={cn(
                        'rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums',
                        tone === 'gap'
                            ? 'bg-status-critical text-white'
                            : 'bg-status-warning text-white',
                    )}
                >
                    {missing} short
                </span>
            </div>
            <div className="mt-2 flex flex-wrap items-center gap-1.5 text-[11px] text-muted-foreground">
                <span className="rounded bg-muted px-1.5 py-0.5">
                    {alert.window_label}
                </span>
                <span className="rounded bg-muted px-1.5 py-0.5 tabular-nums">
                    {alert.assigned_staff}/{alert.required_staff} assigned
                </span>
                {typeof alert.planned_staff === 'number' ? (
                    <span className="rounded bg-muted px-1.5 py-0.5 tabular-nums">
                        {alert.planned_staff} planned
                    </span>
                ) : null}
            </div>
        </article>
    );
}

function LegendDot({ color, label }: { color: string; label: string }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span
                className="inline-block h-2 w-2 rounded-full"
                style={{ background: color }}
                aria-hidden="true"
            />
            <span>{label}</span>
        </span>
    );
}

export default CoveragePane;
