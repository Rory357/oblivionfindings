/**
 * Shared pieces of the Site reports (Sites › Reports): the one scope every
 * report reads from the address (period, date range, region — so a filter
 * chosen on one report carries to the next), the header filter row, links
 * that keep that scope, the on-time trend chart, the Site menu (open the
 * Site's report, or its profile) and a 25-row pager.
 *
 * Every report answers three questions: are our sites safe, are checks
 * done on time, where is maintenance slipping. A rate with nothing due is
 * "Nothing due", never 0% (DESIGN.md "Missing is not zero").
 */
import { HeaderDateRange } from '@/components/control-room/reports/report-parts';
import {
    CounterPill,
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import { PageHeaderFilterSelect } from '@/components/page';
import { Surface, type SgAction } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateOnly } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    BarChart3,
    CalendarRange,
    ClipboardCheck,
    FileBarChart,
    MapPin,
} from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

export type ReportFilters = {
    period: string;
    from: string;
    to: string;
    region: string | null;
    today: string;
    unit: 'week' | 'month';
};

export type ReportScope = {
    filters: ReportFilters;
    periods: { value: string; label: string }[];
    regions: string[];
    can: { export: boolean };
};

export type PackKey = 'houses' | 'facilities' | 'head-office';

export type SiteRef = {
    id: number;
    name: string;
    type: string;
    pack: PackKey;
};

export type SiteRow = SiteRef & {
    type_label: string;
    region: string | null;
};

/** The numbers every report works from (SiteReportService::emptyMetrics). */
export type ReportMetrics = {
    hazards_open: number;
    hazards_serious: number;
    actions_overdue: number;
    hazards_raised: number;
    hazards_closed: number;
    avg_days_to_close: number | null;
    checklists_due: number;
    checklists_on_time: number;
    checklists_late: number;
    checklists_missed: number;
    checklist_items_failed: number;
    inspections_due: number;
    inspections_on_time: number;
    inspections_late: number;
    inspections_missed: number;
    inspections_failed: number;
    inspections_overdue_now: number;
    checks_due: number;
    checks_on_time: number;
    checks_late: number;
    checks_missed: number;
    checks_on_time_rate: number | null;
    checklists_on_time_rate: number | null;
    inspections_on_time_rate: number | null;
    assets: number;
    assets_out_of_service: number;
    maintenance_overdue_now: number;
    maintenance_due: number;
    maintenance_on_time: number;
    maintenance_late: number;
    maintenance_missed: number;
    maintenance_on_time_rate: number | null;
    maintenance_done: number;
    maintenance_spend: number;
};

/** The per-Site subset the hub and packs receive. */
export type SiteRowMetrics = Pick<
    ReportMetrics,
    | 'hazards_open'
    | 'hazards_serious'
    | 'actions_overdue'
    | 'hazards_raised'
    | 'checks_due'
    | 'checks_on_time'
    | 'checks_missed'
    | 'checks_on_time_rate'
    | 'inspections_overdue_now'
    | 'maintenance_overdue_now'
    | 'maintenance_due'
    | 'maintenance_on_time_rate'
    | 'assets'
    | 'assets_out_of_service'
>;

export type TrendBucket = {
    key: string;
    start: string;
    end: string;
    checks_due: number;
    checks_on_time: number;
    checks_on_time_rate: number | null;
    checklists_due: number;
    checklists_on_time: number;
    checklists_on_time_rate: number | null;
    inspections_due: number;
    inspections_on_time: number;
    inspections_on_time_rate: number | null;
    hazards_raised: number;
    maintenance_due: number;
    maintenance_on_time: number;
    maintenance_on_time_rate: number | null;
    maintenance_done: number;
    failures?: number;
};

/* ------------------------------------------------------------------ */
/*  Scope in the address                                               */
/* ------------------------------------------------------------------ */

export const DEFAULT_PERIOD = '6m';
export const REPORTS_BASE = '/sites/reports';

export const PACK_PATH: Record<PackKey, string> = {
    houses: `${REPORTS_BASE}/houses`,
    facilities: `${REPORTS_BASE}/facilities`,
    'head-office': `${REPORTS_BASE}/head-office`,
};

/** The query parameters that carry a report's scope (defaults left out). */
export function scopeParams(
    filters: Pick<ReportFilters, 'period' | 'from' | 'to' | 'region'>,
    extra: Record<string, string | number | null | undefined> = {},
): Record<string, string> {
    const params: Record<string, string> = {};
    if (filters.period && filters.period !== DEFAULT_PERIOD) {
        params.period = filters.period;
    }
    if (filters.period === 'custom') {
        params.date_from = filters.from;
        params.date_to = filters.to;
    }
    if (filters.region) params.region = filters.region;
    for (const [key, value] of Object.entries(extra)) {
        if (value !== null && value !== undefined && value !== '') {
            params[key] = String(value);
        }
    }
    return params;
}

/** A report link that keeps the current period and region. */
export function reportHref(
    path: string,
    filters: ReportFilters,
    extra: Record<string, string | number | null | undefined> = {},
): string {
    const query = new URLSearchParams(scopeParams(filters, extra)).toString();
    return query ? `${path}?${query}` : path;
}

/** One Site's report, in the same period (a single Site has no region). */
export function siteReportHref(siteId: number, filters: ReportFilters): string {
    return reportHref(`${REPORTS_BASE}/site/${siteId}`, {
        ...filters,
        region: null,
    });
}

/** "Last 6 months" or "3 May 2026 – 2 Nov 2026". */
export function periodName(scope: ReportScope): string {
    const { filters, periods } = scope;
    if (filters.period === 'custom') {
        return `${formatDateOnly(filters.from)} – ${formatDateOnly(filters.to)}`;
    }
    return (
        periods.find((p) => p.value === filters.period)?.label ??
        'Last 6 months'
    );
}

/** "Last 6 months · Auckland" — the scope in words for a subline. */
export function scopeWords(scope: ReportScope): string {
    return [periodName(scope), scope.filters.region ?? 'all regions'].join(
        ' · ',
    );
}

/**
 * The header filter row every report shares: period (with From / To day
 * pickers for a custom range) and region. Changing either reloads the
 * current report with the new scope; `extra` keeps the report's own
 * parameters (a severity view, a site) across the reload.
 */
export function ReportFilterRow({
    scope,
    path,
    extra = {},
    showRegion = true,
}: {
    scope: ReportScope;
    path: string;
    extra?: Record<string, string | number | null | undefined>;
    showRegion?: boolean;
}) {
    const { filters, periods, regions } = scope;
    const visit = (next: Partial<ReportFilters>) =>
        router.get(path, scopeParams({ ...filters, ...next }, extra), {
            preserveState: true,
            preserveScroll: true,
        });

    return (
        <>
            <PageHeaderFilterSelect
                icon={CalendarRange}
                label="Last 6 months"
                allValue={DEFAULT_PERIOD}
                value={filters.period}
                options={periods}
                onChange={(period) => visit({ period })}
            />
            {filters.period === 'custom' ? (
                <HeaderDateRange
                    idPrefix="site-report-range"
                    from={filters.from}
                    to={filters.to}
                    onChange={(from, to) =>
                        visit({ period: 'custom', from, to })
                    }
                />
            ) : null}
            {showRegion && regions.length > 0 ? (
                <PageHeaderFilterSelect
                    icon={MapPin}
                    label="All regions"
                    value={filters.region ?? 'all'}
                    options={[
                        { value: 'all', label: 'All regions' },
                        ...regions.map((r) => ({ value: r, label: r })),
                    ]}
                    onChange={(region) =>
                        visit({ region: region === 'all' ? null : region })
                    }
                />
            ) : null}
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Words and numbers                                                  */
/* ------------------------------------------------------------------ */

export function plural(count: number, one: string, many = `${one}s`): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** "86%" — or null when nothing was due (the caller says "Nothing due"). */
export function formatRate(rate: number | null | undefined): string | null {
    if (rate === null || rate === undefined || !Number.isFinite(rate))
        return null;
    return `${Math.round(rate)}%`;
}

/** Tone for an on-time rate: 90%+ is on track, under 75% needs action. */
export function rateTone(
    rate: number | null | undefined,
): 'success' | 'warning' | 'critical' | 'neutral' {
    if (rate === null || rate === undefined) return 'neutral';
    if (rate >= 90) return 'success';
    if (rate >= 75) return 'warning';
    return 'critical';
}

/** "$84.60" — NZ dollars, never "NZD" on screen. */
export function formatMoney(amount: number | null | undefined): string {
    return Number(amount ?? 0).toLocaleString('en-NZ', {
        style: 'currency',
        currency: 'NZD',
        currencyDisplay: 'narrowSymbol',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/** A stored key in sentence case: "fire_safety" → "Fire safety". */
export function sentenceLabel(key: string | null | undefined): string {
    if (!key) return '—';
    const words = key.replace(/[_-]+/g, ' ').trim().toLowerCase();
    return words.charAt(0).toUpperCase() + words.slice(1);
}

export const SEVERITY_LABEL: Record<string, string> = {
    critical: 'Critical',
    high: 'High',
    medium: 'Medium',
    low: 'Low',
};

export function severityVariant(
    severity: string | null | undefined,
): 'critical' | 'warning' | 'info' | 'neutral' {
    if (severity === 'critical' || severity === 'high') return 'critical';
    if (severity === 'medium') return 'warning';
    if (severity === 'low') return 'info';
    return 'neutral';
}

export const ASSET_STATUS_LABEL: Record<string, string> = {
    active: 'In use',
    out_of_service: 'Out of service',
    maintenance: 'In maintenance',
    in_repair: 'In repair',
    repair: 'In repair',
    inactive: 'Not in use',
    retired: 'Retired',
    disposed: 'Disposed of',
    unknown: 'Not recorded',
};

export function assetStatusLabel(status: string | null | undefined): string {
    return ASSET_STATUS_LABEL[status ?? 'unknown'] ?? sentenceLabel(status);
}

/** Axis label for a trend bucket: "May 2026" by month, "4 May" by week. */
export function bucketLabel(bucket: TrendBucket, unit: 'week' | 'month') {
    if (unit === 'month') {
        return formatDateOnly(`${bucket.key}-01`, bucket.key).replace(
            /^\d+ /,
            '',
        );
    }
    return formatDateOnly(bucket.key, bucket.key).replace(/ \d{4}$/, '');
}

/** "12 Oct 2026" with a "9 days late" pill once it has passed. */
export function DueCell({
    date,
    daysOverdue,
    none,
}: {
    date: string | null;
    daysOverdue: number;
    none: string;
}) {
    if (!date) return <span className="text-muted-foreground">{none}</span>;
    return (
        <span className="flex min-w-0 items-center gap-2">
            <span className="truncate">{formatDateOnly(date)}</span>
            {daysOverdue > 0 ? (
                <CounterPill tone="critical">
                    {plural(daysOverdue, 'day')} late
                </CounterPill>
            ) : null}
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Blocks                                                             */
/* ------------------------------------------------------------------ */

/** Scroll a report block into view and focus it (meter blocks use this). */
export function showBlock(id: string) {
    if (typeof document === 'undefined') return;
    const el = document.getElementById(id);
    if (!el) return;
    const reduce =
        typeof window !== 'undefined' &&
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    el.scrollIntoView?.({
        behavior: reduce ? 'auto' : 'smooth',
        block: 'start',
    });
    el.focus({ preventScroll: true });
}

/**
 * One report block: the kit's plain surface (no coloured edge) with a
 * padded body and an anchor a header meter can jump to.
 */
export function ReportBlock({
    id,
    title,
    sub,
    action,
    flush = false,
    className,
    children,
}: {
    id?: string;
    title: string;
    sub?: ReactNode;
    action?: SgAction | null;
    /** Tables run edge to edge; charts and text take the padding. */
    flush?: boolean;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            id={id}
            tabIndex={id ? -1 : undefined}
            className={cn(
                'min-w-0 scroll-mt-5 rounded-[14px] outline-none focus-visible:ring-2 focus-visible:ring-ring',
                className,
            )}
        >
            <Surface title={title} sub={sub} action={action}>
                <div className={flush ? 'pt-3' : 'px-[18px] pt-3 pb-4'}>
                    {children}
                </div>
            </Surface>
        </div>
    );
}

const TICK = { fill: 'var(--muted-foreground)', fontSize: 12 };

const TOOLTIP_STYLE = {
    contentStyle: {
        background: 'var(--popover)',
        border: '1px solid var(--border)',
        borderRadius: 'var(--radius-md)',
        color: 'var(--popover-foreground)',
        fontSize: 12,
    },
    itemStyle: { color: 'var(--popover-foreground)' },
    labelStyle: { color: 'var(--popover-foreground)', fontWeight: 600 },
};

export type RateRow = {
    key: string;
    label: string;
    /** On-time share, or null when nothing was due in that bucket. */
    rate: number | null;
    done: number;
    due: number;
};

/** Turn trend buckets into rate rows for one measure. */
export function rateRows(
    trend: TrendBucket[],
    unit: 'week' | 'month',
    measure: 'checks' | 'checklists' | 'inspections' | 'maintenance',
): RateRow[] {
    const pick = (bucket: TrendBucket): [number | null, number, number] => {
        switch (measure) {
            case 'checklists':
                return [
                    bucket.checklists_on_time_rate,
                    bucket.checklists_on_time,
                    bucket.checklists_due,
                ];
            case 'inspections':
                return [
                    bucket.inspections_on_time_rate,
                    bucket.inspections_on_time,
                    bucket.inspections_due,
                ];
            case 'maintenance':
                return [
                    bucket.maintenance_on_time_rate,
                    bucket.maintenance_on_time,
                    bucket.maintenance_due,
                ];
            default:
                return [
                    bucket.checks_on_time_rate,
                    bucket.checks_on_time,
                    bucket.checks_due,
                ];
        }
    };
    return trend.map((bucket) => {
        const [rate, done, due] = pick(bucket);
        return {
            key: bucket.key,
            label: bucketLabel(bucket, unit),
            rate: rate ?? null,
            done: Number(done) || 0,
            due: Number(due) || 0,
        };
    });
}

/**
 * "Done on time" by month (or week) — one line on chart-1, 0–100%. A
 * bucket with nothing due leaves a gap rather than a fake 0%. The exact
 * values sit under "View chart data".
 */
export function OnTimeTrendChart({
    rows,
    noun = 'checks',
    emptyTitle,
    emptyDescription,
}: {
    rows: RateRow[];
    /** What was due: "checks", "inspections", "maintenance jobs". */
    noun?: string;
    emptyTitle: string;
    emptyDescription?: string;
}) {
    const measured = rows.filter((row) => row.rate !== null);
    if (measured.length === 0) {
        return (
            <EmptyState
                variant="compact"
                icon={ClipboardCheck}
                title={emptyTitle}
                description={emptyDescription}
            />
        );
    }
    const latest = measured[measured.length - 1];
    const lowest = measured.reduce((low, row) =>
        (row.rate ?? 0) < (low.rate ?? 0) ? row : low,
    );
    const summary = `${sentenceLabel(noun)} done on time: ${formatRate(latest.rate)} in ${latest.label}, the lowest ${formatRate(lowest.rate)} in ${lowest.label}. Exact values are under View chart data.`;
    const data = rows.map((row) => ({ ...row, value: row.rate }));

    return (
        <div className="grid gap-3">
            <p className="text-subtle">
                {formatRate(latest.rate)} done on time in {latest.label}
                {measured.length > 1 && lowest.key !== latest.key
                    ? ` · lowest ${formatRate(lowest.rate)} in ${lowest.label}`
                    : ''}
            </p>
            <div className="h-56 w-full" role="img" aria-label={summary}>
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart
                        data={data}
                        margin={{ top: 8, right: 12, left: 0, bottom: 0 }}
                    >
                        <CartesianGrid
                            vertical={false}
                            stroke="var(--border)"
                        />
                        <XAxis
                            dataKey="label"
                            tick={TICK}
                            tickLine={false}
                            axisLine={false}
                            interval="preserveStartEnd"
                            minTickGap={16}
                        />
                        <YAxis
                            domain={[0, 100]}
                            ticks={[0, 25, 50, 75, 100]}
                            tickFormatter={(value: number) => `${value}%`}
                            tick={TICK}
                            tickLine={false}
                            axisLine={false}
                            width={40}
                        />
                        <Tooltip
                            {...TOOLTIP_STYLE}
                            cursor={{
                                stroke: 'var(--muted-foreground)',
                                strokeWidth: 1,
                            }}
                            formatter={(_value, _name, item) => {
                                const row = item?.payload as RateRow;
                                return [
                                    row.rate === null
                                        ? 'Nothing due'
                                        : `${formatRate(row.rate)} (${row.done} of ${row.due})`,
                                    'On time',
                                ];
                            }}
                        />
                        <Line
                            type="linear"
                            dataKey="value"
                            name="On time"
                            stroke="var(--chart-1)"
                            strokeWidth={2}
                            strokeLinejoin="round"
                            strokeLinecap="round"
                            connectNulls={false}
                            dot={{
                                r: 4,
                                strokeWidth: 2,
                                stroke: 'var(--card)',
                                fill: 'var(--chart-1)',
                            }}
                            activeDot={{
                                r: 5,
                                strokeWidth: 2,
                                stroke: 'var(--card)',
                            }}
                            isAnimationActive={false}
                        />
                    </LineChart>
                </ResponsiveContainer>
            </div>
            <details className="text-sm">
                <summary className="cursor-pointer font-medium text-muted-foreground hover:text-foreground">
                    View chart data
                </summary>
                <div className="scrollbar-pretty mt-2 max-h-72 overflow-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Period</TableHead>
                                <TableHead className="text-right">
                                    Due
                                </TableHead>
                                <TableHead className="text-right">
                                    On time
                                </TableHead>
                                <TableHead className="text-right">
                                    Share
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <TableRow key={row.key}>
                                    <TableHead
                                        scope="row"
                                        className="font-normal"
                                    >
                                        {row.label}
                                    </TableHead>
                                    <TableCell className="text-right tabular-nums">
                                        {row.due}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.done}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatRate(row.rate) ?? 'Nothing due'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </details>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Site menu — a Site in a report opens its report                    */
/* ------------------------------------------------------------------ */

/**
 * The one menu for a Site row or card (kebab and right-click): its report
 * first (what a row click does), its profile second.
 */
export function siteMenu(site: SiteRef, filters: ReportFilters): MenuItem[] {
    return [
        {
            label: 'Open site report',
            icon: FileBarChart,
            onClick: () => router.visit(siteReportHref(site.id, filters)),
        },
        {
            label: 'Open site profile',
            icon: BarChart3,
            onClick: () => router.visit(`/sites/${site.id}`),
        },
    ];
}

/** Right-click support for Site rows/cards: returns the handler and the menu to render. */
export function useSiteContextMenu<T extends SiteRef>(filters: ReportFilters) {
    const context = useEntityContextMenu<T>();
    const menu = context.ctx ? (
        <EntityContextMenu
            {...context.ctx}
            onClose={context.close}
            title={context.ctx.record.name}
            items={siteMenu(context.ctx.record, filters)}
        />
    ) : null;
    return { open: context.open, menu } as const;
}

/* ------------------------------------------------------------------ */
/*  Pager — the pager takes over after 25 rows                         */
/* ------------------------------------------------------------------ */

export const PAGE_SIZE = 25;

export function usePaged<T>(rows: T[], resetKey: unknown = null) {
    const [state, setState] = useState({ page: 1, key: resetKey });
    // A new filter or search starts again on page 1.
    const page = state.key === resetKey ? state.page : 1;
    const last = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
    const current = Math.min(page, last);
    const slice = useMemo(
        () => rows.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE),
        [rows, current],
    );
    return {
        rows: slice,
        page: current,
        last,
        total: rows.length,
        setPage: (next: number) => setState({ page: next, key: resetKey }),
    };
}

export function Pager({
    page,
    last,
    total,
    setPage,
}: {
    page: number;
    last: number;
    total: number;
    setPage: (page: number) => void;
}) {
    if (last <= 1) return null;
    const first = (page - 1) * PAGE_SIZE + 1;
    const end = Math.min(page * PAGE_SIZE, total);
    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-caption">
                {first}–{end} of {total}
            </p>
            <div className="flex items-center gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    disabled={page === 1}
                    onClick={() => setPage(page - 1)}
                >
                    Previous
                </Button>
                <span className="text-caption">
                    Page {page} of {last}
                </span>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={page === last}
                    onClick={() => setPage(page + 1)}
                >
                    Next
                </Button>
            </div>
        </div>
    );
}
