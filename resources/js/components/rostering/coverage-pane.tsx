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

export type CoveragePaneProps = {
    stats: MicroStat[];
    windowLabels: string[];
    rows: CoverageRow[];
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
    actionEndSlot,
}: CoveragePaneProps) {
    return (
        <div className="space-y-4">
            <MicroStats stats={stats} />
            <div className="overflow-hidden rounded-[14px] border border-border bg-card">
                <div
                    className="grid border-b border-border bg-muted/50"
                    style={{
                        gridTemplateColumns: `200px repeat(${windowLabels.length}, minmax(0, 1fr))`,
                    }}
                >
                    <div className="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Site
                    </div>
                    {windowLabels.map((w) => (
                        <div
                            key={w}
                            className="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground"
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
