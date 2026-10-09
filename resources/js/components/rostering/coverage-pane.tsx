import type { ReactNode } from 'react';

import { StatusBadge } from '@/components/ui/status-badge';
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
    sites: Array<{
        site_id: number;
        site_name: string;
        total_windows: number;
        under_covered_windows: number;
        exact_windows: number;
        overstaffed_windows: number;
        largest_missing_staff: number;
    }>;
    alerts?: CoverageAlertSummary[];
    actionEndSlot?: ReactNode;
};

export function CoveragePane({
    stats,
    sites,
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
                                Coverage windows needing review
                            </h3>
                            <div className="text-[11px] text-muted-foreground">
                                Returned alerts for the selected week. Site
                                totals below cover every assessed window.
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
            <section
                className="space-y-3"
                aria-label="Coverage summary by site"
            >
                <div>
                    <h3 className="text-section-title">
                        Configured coverage windows
                    </h3>
                    <p className="text-caption">
                        Totals use each site's configured days and exact times
                        for this week. Recorded roster supply is not
                        confirmation of attendance, skills or safe cover.
                    </p>
                </div>
                {sites.length === 0 ? (
                    <p className="rounded-xl border bg-card p-4 text-sm text-muted-foreground">
                        No site coverage assessment was returned for this view.
                        Review the House or site coverage requirements before
                        relying on an empty result.
                    </p>
                ) : (
                    <div className="grid gap-3 xl:grid-cols-2">
                        {sites.map((site) => (
                            <article
                                key={site.site_id}
                                className="rounded-xl border bg-card p-4"
                            >
                                <h4 className="text-sm font-semibold">
                                    {site.site_name}
                                </h4>
                                {site.total_windows === 0 ? (
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        No configured demand windows were
                                        assessed for this week. Staffing cover
                                        is not established.
                                    </p>
                                ) : (
                                    <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                        <div>
                                            <dt className="text-caption">
                                                Demand windows
                                            </dt>
                                            <dd className="font-semibold">
                                                {site.total_windows}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-caption">
                                                Under-covered
                                            </dt>
                                            <dd
                                                className={cn(
                                                    'font-semibold',
                                                    site.under_covered_windows >
                                                        0 &&
                                                        'text-status-critical',
                                                )}
                                            >
                                                {site.under_covered_windows}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-caption">
                                                Meets staff number
                                            </dt>
                                            <dd className="font-semibold">
                                                {site.exact_windows}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-caption">
                                                Overstaffed
                                            </dt>
                                            <dd className="font-semibold">
                                                {site.overstaffed_windows}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-caption">
                                                Largest staff shortfall
                                            </dt>
                                            <dd className="font-semibold">
                                                {site.largest_missing_staff}
                                            </dd>
                                        </div>
                                    </dl>
                                )}
                            </article>
                        ))}
                    </div>
                )}
            </section>
            {actionEndSlot}
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
                    <div className="text-sm font-semibold break-words">
                        {alert.site_name}
                    </div>
                    <div className="text-xs break-words text-muted-foreground">
                        {alert.rule_name}
                    </div>
                </div>
                <StatusBadge variant={tone === 'gap' ? 'critical' : 'warning'}>
                    {missing > 0 ? `${missing} short` : 'Review required'}
                </StatusBadge>
            </div>
            <div className="mt-2 flex flex-wrap items-center gap-1.5 text-[11px] text-muted-foreground">
                <span className="rounded bg-muted px-1.5 py-0.5">
                    {alert.window_label}
                </span>
                <span className="rounded bg-muted px-1.5 py-0.5 tabular-nums">
                    {alert.assigned_staff}/{alert.required_staff} in roster
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

export default CoveragePane;
