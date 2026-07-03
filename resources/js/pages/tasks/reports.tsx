/* Task Reports — read-only analytics over the company-wide /tasks queue:
 * per-module open vs overdue, aging buckets, severity mix and rough 30-day
 * throughput. Pure CSS width-bars (no chart library) following the H&S
 * analytics ChartCard idiom: every visual carries an aria-label and a
 * visually-hidden data table so meaning is never colour-only. Semantic
 * tokens only. */
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/health-safety/components/hs-hero-kit';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, BarChart3 } from 'lucide-react';
import type { ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Types — mirror AllTasksController::reports()                       */
/* ------------------------------------------------------------------ */

type TaskSeverity = 'critical' | 'high' | 'medium' | 'low' | 'info';

interface ModuleRow {
    key: string;
    label: string;
    open: number;
    overdue: number;
    /** Aging of OPEN items from createdAt: 0–7d / 8–30d / 31d+. */
    aging: { fresh: number; aging: number; stale: number };
}

interface Props {
    totals: { open: number; overdue: number; done: number };
    modules: ModuleRow[];
    severity: Record<TaskSeverity, number>;
    closure: { opened30: number; closed30: number };
    sources: Array<{ key: string; label: string }>;
    generatedAt: string;
}

/* ------------------------------------------------------------------ */
/*  Tokens & helpers                                                   */
/* ------------------------------------------------------------------ */

/** Severity → chart token, matching the H&S analytics severityFill scale. */
const SEVERITY_COLOR: Record<TaskSeverity, string> = {
    critical: 'var(--status-critical)',
    high: 'var(--chart-3)',
    medium: 'var(--status-warning)',
    low: 'var(--status-info)',
    info: 'var(--chart-5)',
};

const SEVERITY_ORDER: TaskSeverity[] = ['critical', 'high', 'medium', 'low', 'info'];

const AGING_SEGMENTS = [
    { key: 'fresh' as const, label: '0–7 days', color: 'var(--status-success)' },
    { key: 'aging' as const, label: '8–30 days', color: 'var(--status-warning)' },
    { key: 'stale' as const, label: '31+ days', color: 'var(--status-critical)' },
];

function pct(part: number, whole: number): number {
    return whole > 0 ? (part / whole) * 100 : 0;
}

/* ------------------------------------------------------------------ */
/*  Shared chrome — mirrors the H&S analytics ChartCard idiom          */
/*  (title + subtitle header, aria-labelled figure, sr-only table).    */
/* ------------------------------------------------------------------ */

type SrTable = { caption: string; columns: string[]; rows: (string | number)[][] };

function ReportCard({
    title,
    subtitle,
    aria,
    children,
    table,
    className,
}: {
    title: ReactNode;
    subtitle?: ReactNode;
    aria: string;
    children: ReactNode;
    table?: SrTable;
    className?: string;
}) {
    return (
        <Card className={cn('rounded-xl shadow-sm', className)}>
            <CardHeader className="pb-1">
                <CardTitle className="text-sm font-bold tracking-tight">{title}</CardTitle>
                {subtitle ? <p className="mt-0.5 text-xs text-muted-foreground">{subtitle}</p> : null}
            </CardHeader>
            <CardContent className="pt-2">
                <figure className="m-0" role="group" aria-label={aria}>
                    {children}
                    {table ? (
                        <table className="sr-only">
                            <caption>{table.caption}</caption>
                            <thead>
                                <tr>
                                    {table.columns.map((c) => (
                                        <th key={c}>{c}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {table.rows.map((r, i) => (
                                    <tr key={i}>
                                        {r.map((c, j) => (
                                            <td key={j}>{c}</td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : null}
                </figure>
            </CardContent>
        </Card>
    );
}

function LegendDot({ color, label }: { color: string; label: string }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
            <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: color }} />
            {label}
        </span>
    );
}

function EmptyBars({ label }: { label: string }) {
    return (
        <div className="flex h-[120px] flex-col items-center justify-center rounded-lg border border-dashed border-border bg-muted/40 px-4 text-center">
            <p className="text-xs text-muted-foreground">{label}</p>
        </div>
    );
}

/** One summary tile (closure / totals row). */
function StatTile({
    label,
    value,
    caption,
    tone = 'neutral',
}: {
    label: string;
    value: number;
    caption: string;
    tone?: 'neutral' | 'success' | 'warning' | 'critical';
}) {
    const toneClass = {
        neutral: 'text-foreground',
        success: 'text-status-success',
        warning: 'text-status-warning',
        critical: 'text-status-critical',
    }[tone];
    return (
        <Card className="rounded-xl shadow-sm">
            <CardContent className="p-4">
                <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">{label}</p>
                <p className={cn('mt-1 text-2xl font-bold tabular-nums', toneClass)}>{value}</p>
                <p className="mt-0.5 text-xs text-muted-foreground">{caption}</p>
            </CardContent>
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function TaskReports({ totals, modules, severity, closure }: Props) {
    const openModules = modules.filter((m) => m.open > 0);
    const maxOpen = Math.max(1, ...openModules.map((m) => m.open));
    const severityTotal = SEVERITY_ORDER.reduce((s, k) => s + (severity[k] ?? 0), 0);
    const maxSeverity = Math.max(1, ...SEVERITY_ORDER.map((k) => severity[k] ?? 0));
    const net = closure.opened30 - closure.closed30;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'All Tasks', href: '/tasks' },
                { title: 'Reports', href: '/tasks/reports' },
            ]}
        >
            <Head title="Task Reports" />

            <div className="flex flex-col gap-4 p-6">
                {/* ── Hero (compact) ── */}
                <HeroShell>
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div className="flex items-start gap-4">
                            <HeroMedallion icon={BarChart3} />
                            <div className="min-w-0 flex-1">
                                <HeroStatusPill>Reports · computed live from the queue</HeroStatusPill>
                                <h1 className="mt-2 text-2xl font-bold tracking-tight md:text-[28px]">Task Reports</h1>
                                <p className="mt-1 max-w-2xl text-sm text-primary-foreground/80">
                                    How the company-wide work queue is trending — open and overdue load per
                                    module, how long items have been sitting, severity mix and rough 30-day
                                    throughput. Same permission scoping as the queue itself.
                                </p>
                            </div>
                        </div>
                        {/* On-dark hero affordance — mirrors the queue's Export CSV chrome. */}
                        <Link
                            href="/tasks"
                            className="inline-flex h-9 shrink-0 items-center gap-2 self-start rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-3 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Back to queue
                        </Link>
                    </div>
                </HeroShell>

                {/* ── Closure / totals summary tiles ── */}
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <StatTile label="Open now" value={totals.open} caption="across every module you can see" />
                    <StatTile
                        label="Overdue"
                        value={totals.overdue}
                        caption="open items past their due date"
                        tone={totals.overdue > 0 ? 'critical' : 'success'}
                    />
                    <StatTile
                        label="Opened · last 30 days"
                        value={closure.opened30}
                        caption={net > 0 ? `inflow outpacing closures by ${net}` : 'inflow in the last month'}
                        tone={net > 0 ? 'warning' : 'neutral'}
                    />
                    <StatTile
                        label="Closed · last 30 days"
                        value={closure.closed30}
                        caption="rough throughput — modules only surface recently-completed items"
                        tone="success"
                    />
                </div>

                {/* ── Charts grid ── */}
                <div className="grid gap-4 xl:grid-cols-2">
                    {/* Per-module open vs overdue */}
                    <ReportCard
                        title="Open load by module"
                        subtitle="Open items per module — the overdue share reads critical."
                        aria="Bar chart of open and overdue items per module"
                        className="xl:col-span-2"
                        table={{
                            caption: 'Open and overdue items per module',
                            columns: ['Module', 'Open', 'Overdue'],
                            rows: openModules.map((m) => [m.label, m.open, m.overdue]),
                        }}
                    >
                        {openModules.length === 0 ? (
                            <EmptyBars label="No open items — the queue is clear." />
                        ) : (
                            <div className="space-y-2" aria-hidden="true">
                                {openModules.map((m) => (
                                    <div key={m.key} className="flex items-center gap-3">
                                        <span className="w-44 shrink-0 truncate text-xs font-medium text-foreground">{m.label}</span>
                                        <div className="h-4 flex-1 overflow-hidden rounded-md bg-muted">
                                            <div
                                                className="flex h-full overflow-hidden rounded-md"
                                                style={{ width: `${pct(m.open, maxOpen)}%` }}
                                            >
                                                <div
                                                    className="h-full"
                                                    style={{
                                                        width: `${pct(m.overdue, m.open)}%`,
                                                        backgroundColor: 'var(--status-critical)',
                                                    }}
                                                />
                                                <div className="h-full flex-1" style={{ backgroundColor: 'var(--primary)' }} />
                                            </div>
                                        </div>
                                        <span className="w-24 shrink-0 text-right text-xs tabular-nums text-muted-foreground">
                                            <span className="font-semibold text-foreground">{m.open}</span>
                                            {m.overdue > 0 ? (
                                                <span className="font-semibold text-status-critical"> · {m.overdue} late</span>
                                            ) : null}
                                        </span>
                                    </div>
                                ))}
                                <div className="flex flex-wrap gap-3 border-t border-border pt-2">
                                    <LegendDot color="var(--status-critical)" label="Overdue" />
                                    <LegendDot color="var(--primary)" label="On track" />
                                </div>
                            </div>
                        )}
                    </ReportCard>

                    {/* Aging stacked bars per module */}
                    <ReportCard
                        title="Aging of open items"
                        subtitle="How long open items have been sitting, by module (from creation date)."
                        aria="Stacked bar chart of open item age buckets per module"
                        table={{
                            caption: 'Open items per module by age bucket',
                            columns: ['Module', '0–7 days', '8–30 days', '31+ days'],
                            rows: openModules.map((m) => [m.label, m.aging.fresh, m.aging.aging, m.aging.stale]),
                        }}
                    >
                        {openModules.length === 0 ? (
                            <EmptyBars label="No open items to age." />
                        ) : (
                            <div className="space-y-2" aria-hidden="true">
                                {openModules.map((m) => {
                                    const counted = m.aging.fresh + m.aging.aging + m.aging.stale;
                                    return (
                                        <div key={m.key} className="flex items-center gap-3">
                                            <span className="w-36 shrink-0 truncate text-xs font-medium text-foreground">{m.label}</span>
                                            <div className="h-4 flex-1 overflow-hidden rounded-md bg-muted">
                                                {counted > 0 ? (
                                                    <div
                                                        className="flex h-full overflow-hidden rounded-md"
                                                        style={{ width: `${pct(counted, maxOpen)}%` }}
                                                    >
                                                        {AGING_SEGMENTS.map((seg) =>
                                                            m.aging[seg.key] > 0 ? (
                                                                <div
                                                                    key={seg.key}
                                                                    className="h-full"
                                                                    style={{
                                                                        width: `${pct(m.aging[seg.key], counted)}%`,
                                                                        backgroundColor: seg.color,
                                                                    }}
                                                                />
                                                            ) : null,
                                                        )}
                                                    </div>
                                                ) : null}
                                            </div>
                                            <span className="w-12 shrink-0 text-right text-xs font-semibold tabular-nums text-foreground">
                                                {counted}
                                            </span>
                                        </div>
                                    );
                                })}
                                <div className="flex flex-wrap gap-3 border-t border-border pt-2">
                                    {AGING_SEGMENTS.map((seg) => (
                                        <LegendDot key={seg.key} color={seg.color} label={seg.label} />
                                    ))}
                                </div>
                            </div>
                        )}
                    </ReportCard>

                    {/* Severity mix */}
                    <ReportCard
                        title="Severity mix"
                        subtitle="Open items by severity across every module."
                        aria="Bar chart of open items by severity"
                        table={{
                            caption: 'Open items by severity',
                            columns: ['Severity', 'Count', 'Share'],
                            rows: SEVERITY_ORDER.map((k) => [
                                k,
                                severity[k] ?? 0,
                                `${Math.round(pct(severity[k] ?? 0, severityTotal))}%`,
                            ]),
                        }}
                    >
                        {severityTotal === 0 ? (
                            <EmptyBars label="No open items to break down." />
                        ) : (
                            <div className="space-y-2" aria-hidden="true">
                                {SEVERITY_ORDER.map((k) => {
                                    const count = severity[k] ?? 0;
                                    return (
                                        <div key={k} className="flex items-center gap-3">
                                            <span className="w-20 shrink-0 truncate text-xs font-medium capitalize text-foreground">{k}</span>
                                            <div className="h-4 flex-1 overflow-hidden rounded-md bg-muted">
                                                <div
                                                    className="h-full rounded-md"
                                                    style={{
                                                        width: `${pct(count, maxSeverity)}%`,
                                                        backgroundColor: SEVERITY_COLOR[k],
                                                    }}
                                                />
                                            </div>
                                            <span className="w-20 shrink-0 text-right text-xs tabular-nums text-muted-foreground">
                                                <span className="font-semibold text-foreground">{count}</span>
                                                {' · '}
                                                {Math.round(pct(count, severityTotal))}%
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </ReportCard>
                </div>

                {/* ── Per-module table ── */}
                <Card className="rounded-xl shadow-sm">
                    <CardHeader className="pb-1">
                        <CardTitle className="text-sm font-bold tracking-tight">Module breakdown</CardTitle>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            Open and overdue counts per module, with age buckets measured from each item's
                            creation date. Items without a recorded creation date are excluded from the age
                            buckets.
                        </p>
                    </CardHeader>
                    <CardContent className="p-0 pt-2">
                        <div className="overflow-x-auto">
                            <table className="w-full text-[13px]">
                                <thead>
                                    <tr className="border-b border-border bg-muted text-left text-muted-foreground">
                                        <th className="px-4 py-2.5 font-semibold">Module</th>
                                        <th className="px-3 py-2.5 text-right font-semibold">Open</th>
                                        <th className="px-3 py-2.5 text-right font-semibold">Overdue</th>
                                        <th className="px-3 py-2.5 text-right font-semibold">0–7d</th>
                                        <th className="px-3 py-2.5 text-right font-semibold">8–30d</th>
                                        <th className="px-3 py-2.5 text-right font-semibold">31d+</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {modules.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="px-4 py-6 text-center text-muted-foreground">
                                                No task data across your modules yet.
                                            </td>
                                        </tr>
                                    ) : (
                                        modules.map((m) => (
                                            <tr key={m.key} className="border-b border-border last:border-0">
                                                <td className="px-4 py-2.5 font-medium whitespace-nowrap">{m.label}</td>
                                                <td className="px-3 py-2.5 text-right font-semibold tabular-nums">{m.open}</td>
                                                <td
                                                    className={cn(
                                                        'px-3 py-2.5 text-right tabular-nums',
                                                        m.overdue > 0 ? 'font-semibold text-status-critical' : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {m.overdue}
                                                </td>
                                                <td className="px-3 py-2.5 text-right tabular-nums text-muted-foreground">{m.aging.fresh}</td>
                                                <td className="px-3 py-2.5 text-right tabular-nums text-muted-foreground">{m.aging.aging}</td>
                                                <td
                                                    className={cn(
                                                        'px-3 py-2.5 text-right tabular-nums',
                                                        m.aging.stale > 0 ? 'font-semibold text-status-warning' : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {m.aging.stale}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <p className="text-xs text-muted-foreground">
                    Throughput caveat: both 30-day figures count items by the date they were <em>created</em>{' '}
                    (the queue doesn't track close dates), and modules only surface recently-completed
                    items, so "closed" understates true closures — read these as rough throughput, not an
                    exact ledger.
                </p>
            </div>
        </AppLayout>
    );
}
