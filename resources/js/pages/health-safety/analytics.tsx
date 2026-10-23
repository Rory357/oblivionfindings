/**
 * Health & Safety Analytics — trend / root-cause / governance explorer.
 *
 * Read-only analyse/explore/export surface (no create wizards — those live on
 * the dashboard). Shares the dashboard's hero/tab/filter idiom. NZ-only:
 * LTIFR / TRIFR, WorkSafe notifiable events, Nga Paerewa NZS 8134:2021, ACC.
 */
import { PageLayout } from '@/components/page';
import type {
    RosterTabItem,
    ShiftCtxItem,
    ShiftCtxState,
} from '@/components/rostering';
import { ShiftContextMenu } from '@/components/rostering';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { WizardStep } from '@/components/wizard/shell';
import { WizardShell } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    BarChart3,
    Building2,
    Calendar,
    ClipboardList,
    Download,
    ExternalLink,
    Eye,
    FileText,
    LayoutDashboard,
    Shield,
    TrendingUp,
    User,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import {
    BreakdownRows,
    CaClosureChart,
    ChartCard,
    FocusDonut,
    HazardBurndownChart,
    HorizontalBars,
    LtifrTrifrChart,
    RootCausePareto,
    SingleAreaChart,
    TOKEN,
    WorkerParticipationChart,
    WorksafeNotifiableChart,
    riskFill,
    severityFill,
    type BreakdownItem,
    type DonutDatum,
} from './analytics-charts';
import type {
    AnalyticsProps,
    DrillTarget,
    ScorecardItem,
    SiteRow,
} from './analytics-types';
import {
    AnalyticsHeader,
    AnalyticsPeriodSummary,
} from './components/analytics-header';
import { RoleLensBanner } from './components/dashboard-tabs';
import { FirstAidBreakdowns } from './components/first-aid-breakdowns';
import {
    participationAccessForViewer,
    participationSourceMatches,
    participationValue,
    participationViewer,
    workerParticipationTable,
} from './participation-source-state';

const PALETTE = [
    TOKEN.c1,
    TOKEN.c2,
    TOKEN.c3,
    TOKEN.c4,
    TOKEN.c5,
    'var(--status-info)',
];

const TABS: RosterTabItem[] = [
    {
        id: 'overview',
        label: 'Overview',
        icon: LayoutDashboard,
        tone: 'primary',
    },
    { id: 'trends', label: 'Trends', icon: TrendingUp, tone: 'info' },
    { id: 'breakdowns', label: 'Breakdowns', icon: BarChart3, tone: 'violet' },
    { id: 'sites', label: 'Sites', icon: Building2, tone: 'success' },
    { id: 'governance', label: 'Governance', icon: Shield, tone: 'warning' },
];

const REGISTER: Record<string, string> = {
    incidents: '/health-safety/events',
    injuries: '/health-safety/injuries',
    hazards: '/compliance/hazards',
    sites: '/health-safety',
    root_cause: '/health-safety/events',
    first_aid: '/health-safety/first-aid',
};

// Governance report set — same list as the dashboard's export popover; analytics opens each
// dated report in a new tab (export-led), the dashboard navigates (report-led).
const GOV_REPORTS: { name: string; label: string }[] = [
    { name: 'board-summary', label: 'Board summary' },
    { name: 'worksafe-register', label: 'WorkSafe register' },
    { name: 'investigation-outcomes', label: 'Investigation outcomes' },
    {
        name: 'corrective-action-traceability',
        label: 'Corrective-action traceability',
    },
    { name: 'risk-assessment-register', label: 'Risk-assessment register' },
];

function fmt(v: number | null | undefined, suffix = ''): string {
    return v === null || v === undefined ? '—' : `${v}${suffix}`;
}

function toneFromDir(dir: string): 'success' | 'critical' | 'neutral' {
    return dir === 'improving'
        ? 'success'
        : dir === 'watch'
          ? 'critical'
          : 'neutral';
}

function deltaArrow(dir: string): string {
    return dir === 'improving' ? '▲' : dir === 'watch' ? '▼' : '—';
}

// ── small UI helpers ────────────────────────────────────────────────────

export function ScorecardCell({ item }: { item: ScorecardItem }) {
    const viewer = participationViewer(usePage<SharedData>().props.auth);
    const participation =
        item.key === 'worker_engagement' || item.key === 'worker_consultation';
    const sourceAccess = participationAccessForViewer(
        item.source_access,
        item.viewer_id,
        viewer,
    );
    const value = participation
        ? participationValue(
              item.value,
              item.source_access,
              item.viewer_id,
              viewer,
          )
        : item.value;
    const delta = participation
        ? participationValue(
              item.delta,
              item.source_access,
              item.viewer_id,
              viewer,
          )
        : item.delta;
    const tone =
        item.dir === 'improving'
            ? 'text-status-success'
            : item.dir === 'watch'
              ? 'text-status-critical'
              : 'text-muted-foreground';
    return (
        // eslint-disable-next-line no-restricted-syntax -- compact scorecard metric cell, not a content card
        <div className="rounded-lg border border-border bg-card p-2.5">
            <div className="truncate text-[11px] text-muted-foreground">
                {item.label}
            </div>
            <div className="mt-0.5 text-lg font-bold text-foreground tabular-nums">
                {fmt(value)}
                <span className="text-xs font-semibold text-muted-foreground">
                    {value !== null ? item.suffix : ''}
                </span>
            </div>
            {delta !== null ? (
                <div
                    className={cn(
                        'mt-0.5 flex items-center gap-1 text-[11px] font-medium',
                        tone,
                    )}
                >
                    <span aria-hidden>{deltaArrow(item.dir)}</span>
                    <span>
                        {delta > 0 ? '+' : ''}
                        {delta} vs last mo
                    </span>
                </div>
            ) : (
                <div className="mt-0.5 text-[11px] text-muted-foreground">
                    {participation &&
                    (item.source_access !== 'readable' ||
                        !participationSourceMatches(item.viewer_id, viewer))
                        ? sourceAccess === 'restricted'
                            ? 'Access restricted'
                            : 'Not confirmed'
                        : 'no prior data'}
                </div>
            )}
        </div>
    );
}

function Scorecard({
    leading,
    lagging,
}: {
    leading: ScorecardItem[];
    lagging: ScorecardItem[];
}) {
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <div className="rounded-xl border border-status-success/30 bg-status-success-bg/40 p-4">
                <div className="mb-2 flex items-center gap-2">
                    <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-status-success-bg text-status-success">
                        <TrendingUp className="h-4 w-4" />
                    </span>
                    <div>
                        <h3 className="text-sm font-bold text-foreground">
                            Leading indicators
                        </h3>
                        <p className="text-[11px] text-muted-foreground">
                            Recorded prevention and participation measures
                        </p>
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {leading.map((it) => (
                        <ScorecardCell key={it.key} item={it} />
                    ))}
                </div>
            </div>
            <div className="rounded-xl border border-status-warning/30 bg-status-warning-bg/40 p-4">
                <div className="mb-2 flex items-center gap-2">
                    <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning">
                        <AlertTriangle className="h-4 w-4" />
                    </span>
                    <div>
                        <h3 className="text-sm font-bold text-foreground">
                            Lagging outcomes
                        </h3>
                        <p className="text-[11px] text-muted-foreground">
                            Reactive — harm already occurred; lower is better
                        </p>
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {lagging.map((it) => (
                        <ScorecardCell key={it.key} item={it} />
                    ))}
                </div>
            </div>
        </div>
    );
}

// ── Site league + heatmap ───────────────────────────────────────────────

type SortKey = keyof Pick<
    SiteRow,
    | 'name'
    | 'total_incidents'
    | 'open_hazards'
    | 'lost_time_days'
    | 'ltifr'
    | 'trifr'
    | 'compliance_score'
    | 'drill_status'
>;

const LEAGUE_COLS: [SortKey, string, boolean][] = [
    ['name', 'Site', false],
    ['total_incidents', 'Incidents', true],
    ['open_hazards', 'Open hazards', true],
    ['lost_time_days', 'Lost-time days', true],
    ['ltifr', 'LTIFR', true],
    ['trifr', 'TRIFR', true],
    ['compliance_score', 'Compliance %', true],
    ['drill_status', 'Drill', false],
];

function drillStatusBadge(status: string): string {
    if (status === 'compliant')
        return 'bg-status-success-bg text-status-success border-status-success/30';
    if (status === 'due_soon')
        return 'bg-status-warning-bg text-status-warning border-status-warning/30';
    return 'bg-status-critical-bg text-status-critical border-status-critical/30';
}

function SiteLeague({
    sites,
    onRow,
    onRowCtx,
}: {
    sites: SiteRow[];
    onRow: (s: SiteRow) => void;
    onRowCtx: (e: React.MouseEvent, s: SiteRow) => void;
}) {
    const [sortKey, setSortKey] = useState<SortKey>('total_incidents');
    const [asc, setAsc] = useState(false);

    const sorted = useMemo(() => {
        const rows = [...sites];
        rows.sort((a, b) => {
            const av = a[sortKey];
            const bv = b[sortKey];
            if (av === null && bv === null) return 0;
            if (av === null) return 1;
            if (bv === null) return -1;
            if (typeof av === 'number' && typeof bv === 'number')
                return asc ? av - bv : bv - av;
            return asc
                ? String(av).localeCompare(String(bv))
                : String(bv).localeCompare(String(av));
        });
        return rows;
    }, [sites, sortKey, asc]);

    const toggle = (k: SortKey) => {
        if (k === sortKey) setAsc((v) => !v);
        else {
            setSortKey(k);
            setAsc(false);
        }
    };

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-border text-left text-xs text-muted-foreground">
                        {LEAGUE_COLS.map(([key, label, sortable]) => (
                            <th key={key} className="pb-2 font-medium">
                                {sortable ? (
                                    // eslint-disable-next-line no-restricted-syntax -- sortable column header trigger, not a standard Button
                                    <button
                                        type="button"
                                        onClick={() => toggle(key)}
                                        className="inline-flex items-center gap-1 hover:text-foreground"
                                        aria-label={`Sort by ${label}`}
                                    >
                                        {label}
                                        <span
                                            aria-hidden
                                            className="text-[10px]"
                                        >
                                            {sortKey === key
                                                ? asc
                                                    ? '▲'
                                                    : '▼'
                                                : '↕'}
                                        </span>
                                    </button>
                                ) : (
                                    label
                                )}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {sorted.map((s) => (
                        <tr
                            key={s.id}
                            tabIndex={0}
                            role="button"
                            onClick={() => onRow(s)}
                            onContextMenu={(e) => onRowCtx(e, s)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    e.preventDefault();
                                    onRow(s);
                                }
                            }}
                            className="border-line cursor-pointer border-b outline-none last:border-0 hover:bg-accent focus-visible:bg-accent"
                        >
                            <td className="py-2 font-medium text-foreground">
                                {s.name}
                            </td>
                            <td className="py-2 tabular-nums">
                                {s.total_incidents}
                            </td>
                            <td className="py-2 tabular-nums">
                                {s.open_hazards}
                            </td>
                            <td className="py-2 tabular-nums">
                                {s.lost_time_days ?? 'Not confirmed'}
                            </td>
                            <td className="py-2 tabular-nums">
                                {fmt(s.ltifr)}
                            </td>
                            <td className="py-2 tabular-nums">
                                {fmt(s.trifr)}
                            </td>
                            <td className="py-2">
                                <span
                                    className={cn(
                                        'font-semibold tabular-nums',
                                        s.compliance_score === null
                                            ? 'text-muted-foreground'
                                            : s.compliance_score >= 90
                                              ? 'text-status-success'
                                              : s.compliance_score >= 70
                                                ? 'text-status-warning'
                                                : 'text-status-critical',
                                    )}
                                >
                                    {s.compliance_score === null
                                        ? 'Not confirmed'
                                        : `${s.compliance_score}%`}
                                </span>
                            </td>
                            <td className="py-2">
                                <Badge
                                    className={cn(
                                        'border',
                                        drillStatusBadge(s.drill_status),
                                    )}
                                >
                                    {s.drill_status === 'due_soon'
                                        ? 'Due soon'
                                        : s.drill_status
                                              .charAt(0)
                                              .toUpperCase() +
                                          s.drill_status.slice(1)}
                                </Badge>
                            </td>
                        </tr>
                    ))}
                    {!sorted.length ? (
                        <tr>
                            <td
                                colSpan={LEAGUE_COLS.length}
                                className="py-6 text-center text-muted-foreground"
                            >
                                No site data available.
                            </td>
                        </tr>
                    ) : null}
                </tbody>
            </table>
        </div>
    );
}

const HEAT_COLS: [keyof SiteRow, string, boolean][] = [
    ['total_incidents', 'Incidents', false],
    ['open_hazards', 'Open hazards', false],
    ['lost_time_days', 'Lost-time', false],
    ['ltifr', 'LTIFR', false],
    ['trifr', 'TRIFR', false],
    ['compliance_score', 'Compliance', true],
];

function Heatmap({
    sites,
    onCell,
}: {
    sites: SiteRow[];
    onCell: (s: SiteRow) => void;
}) {
    const maxes = useMemo(() => {
        const m: Record<string, number> = {};
        HEAT_COLS.forEach(([key]) => {
            m[key as string] = Math.max(
                1,
                ...sites.map((s) => Number(s[key] ?? 0)),
            );
        });
        return m;
    }, [sites]);

    return (
        <div className="overflow-x-auto">
            <table className="w-full border-separate border-spacing-1 text-xs">
                <thead>
                    <tr className="text-left text-muted-foreground">
                        <th className="px-2 py-1 font-medium">Site</th>
                        {HEAT_COLS.map(([key, label]) => (
                            <th
                                key={key as string}
                                className="px-2 py-1 text-center font-medium"
                            >
                                {label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {sites.map((s) => (
                        <tr key={s.id}>
                            <th
                                scope="row"
                                className="px-2 py-1 text-left font-medium whitespace-nowrap text-foreground"
                            >
                                {s.name}
                            </th>
                            {HEAT_COLS.map(([key, , invert]) => {
                                const unknown = s[key] === null;
                                const raw = Number(s[key] ?? 0);
                                const norm = raw / maxes[key as string];
                                // darker = higher burden; compliance inverts (low compliance = worse).
                                // Bake intensity into the bg COLOUR (not button opacity, which would
                                // fade the value text below WCAG AA); cap the mix so dark text stays ≥4.5:1.
                                const burden = Math.min(
                                    1,
                                    Math.max(0, invert ? 1 - raw / 100 : norm),
                                );
                                const mixPct = unknown
                                    ? 0
                                    : Math.round(8 + 44 * burden);
                                return (
                                    <td key={key as string} className="p-0">
                                        {/* eslint-disable-next-line no-restricted-syntax -- heatmap intensity cell, custom shaded surface */}
                                        <button
                                            type="button"
                                            onClick={() => onCell(s)}
                                            title={`${s.name} · ${String(key).replace(/_/g, ' ')}: ${unknown ? 'Not confirmed' : fmt(s[key] as number)}`}
                                            className="flex h-9 w-full min-w-[68px] items-center justify-center rounded-md font-semibold text-foreground tabular-nums transition-transform hover:scale-[1.04] focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                                            style={{
                                                backgroundColor: `color-mix(in oklab, var(--status-critical) ${mixPct}%, var(--card))`,
                                            }}
                                        >
                                            {unknown
                                                ? '—'
                                                : key === 'compliance_score'
                                                  ? `${raw}%`
                                                  : fmt(s[key] as number)}
                                        </button>
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
            <p className="mt-2 text-[11px] text-muted-foreground">
                — = not confirmed. Darker = higher recorded burden (compliance
                inverted). Click a cell to drill into the site.
            </p>
        </div>
    );
}

// ── Governance pack card ────────────────────────────────────────────────

function GovPackCard({
    icon: Icon,
    title,
    desc,
    onOpen,
    actionLabel = 'Open',
}: {
    icon: typeof FileText;
    title: string;
    desc: string;
    onOpen: () => void;
    actionLabel?: string;
}) {
    return (
        <Card className="flex flex-col rounded-xl shadow-sm">
            <CardContent className="flex flex-1 flex-col gap-2 p-4">
                <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-accent text-primary">
                    <Icon className="h-4 w-4" />
                </span>
                <div className="flex-1">
                    <h3 className="text-sm font-bold text-foreground">
                        {title}
                    </h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {desc}
                    </p>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    className="mt-1 w-full justify-center gap-1.5"
                    onClick={onOpen}
                >
                    {actionLabel === 'Open' ? (
                        <ExternalLink className="h-3.5 w-3.5" />
                    ) : (
                        <Download className="h-3.5 w-3.5" />
                    )}
                    {actionLabel}
                </Button>
            </CardContent>
        </Card>
    );
}

// ── Drill-in detail modal (Add-Client WizardShell chrome) ───────────────

const DRILL_FACETS: WizardStep[] = [
    {
        key: 'selection',
        label: 'Selection',
        blurb: 'What you drilled into',
        icon: Eye,
    },
    { key: 'range', label: 'Range', blurb: 'Reporting window', icon: Calendar },
    { key: 'site', label: 'Site', blurb: 'Scope', icon: Building2 },
    {
        key: 'records',
        label: 'Records',
        blurb: 'Underlying register',
        icon: FileText,
    },
    {
        key: 'notifiable',
        label: 'Notifiable',
        blurb: 'WorkSafe status',
        icon: Shield,
    },
];

type RecordsResponse = {
    name: string;
    headers: string[];
    rows: (string | number)[][];
    total: number;
};

function DrillModal({
    target,
    query,
    onClose,
    onExport,
}: {
    target: DrillTarget;
    query: string;
    onClose: () => void;
    onExport: () => void;
}) {
    const [data, setData] = useState<RecordsResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [section, setSection] = useState(3);

    useEffect(() => {
        let active = true;
        setLoading(true);
        fetch(`/health-safety/analytics/records?${query}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r)))
            .then((d: RecordsResponse) => {
                if (active) {
                    setData(d);
                    setLoading(false);
                }
            })
            .catch(() => {
                if (active) setLoading(false);
            });
        return () => {
            active = false;
        };
    }, [query]);

    const register = target.register ?? REGISTER[target.view];

    return (
        <WizardShell
            open
            onClose={onClose}
            title={`Detail — ${target.label}`}
            description="Read-only drill-in listing the underlying register records. Navigational & export only."
            railIcon={Eye}
            railTitle={target.label}
            railSub="Navigational & export only"
            steps={DRILL_FACETS}
            stepIndex={section}
            onStepClick={setSection}
            pct={null}
            footerStart={
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                <>
                    <Button
                        variant="outline"
                        onClick={onExport}
                        className="gap-1.5"
                    >
                        <Download className="h-4 w-4" /> Export CSV
                    </Button>
                    <Button
                        onClick={() => router.visit(register)}
                        className="gap-1.5"
                    >
                        <ArrowRight className="h-4 w-4" /> Open register
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="flex flex-wrap gap-2 text-xs">
                    <span className="rounded-md border border-border bg-muted px-2 py-1">
                        <span className="text-muted-foreground">
                            Selection:
                        </span>{' '}
                        <span className="font-medium text-foreground capitalize">
                            {target.label}
                        </span>
                    </span>
                    {data ? (
                        <span className="rounded-md border border-border bg-muted px-2 py-1">
                            <span className="text-muted-foreground">
                                Records:
                            </span>{' '}
                            <span className="font-medium text-foreground">
                                {data.total}
                            </span>
                        </span>
                    ) : null}
                </div>

                {loading ? (
                    <div className="flex h-40 items-center justify-center text-sm text-muted-foreground">
                        Loading records…
                    </div>
                ) : !data || !data.rows.length ? (
                    <div className="flex h-40 items-center justify-center rounded-lg border border-dashed border-border text-sm text-muted-foreground">
                        No records for this selection.
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-border">
                        <table className="w-full text-xs">
                            <thead className="bg-muted">
                                <tr className="text-left text-muted-foreground">
                                    {data.headers.map((h) => (
                                        <th
                                            key={h}
                                            className="px-3 py-2 font-medium whitespace-nowrap"
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {data.rows.map((row, i) => (
                                    <tr
                                        key={i}
                                        className="border-line border-t"
                                    >
                                        {row.map((cell, j) => (
                                            <td
                                                key={j}
                                                className="px-3 py-1.5 whitespace-nowrap text-foreground tabular-nums"
                                            >
                                                {cell}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {data.total > data.rows.length ? (
                            <p className="px-3 py-2 text-[11px] text-muted-foreground">
                                Showing first {data.rows.length} of {data.total}
                                . Export for the full set.
                            </p>
                        ) : null}
                    </div>
                )}
            </div>
        </WizardShell>
    );
}

// ── Page ────────────────────────────────────────────────────────────────

export default function HealthSafetyAnalytics(props: AnalyticsProps) {
    const {
        trends,
        scorecard,
        period_summary,
        worksafe_notifiable,
        sds_expiring,
        hours_meta,
        filters,
        sites,
        incident_data,
        severity_data,
        root_cause_data,
        injury_data,
        hazard_data,
        site_comparison,
        restraint_analytics,
    } = props;

    // Board-report routes are gated on governance.view; hide the launcher + the
    // Governance-tab report packs for register-only roles to avoid a hard 403.
    const canViewBoardReports =
        usePage<SharedData>().props.auth.can?.governance?.view ?? false;

    const [tab, setTab] = useState('overview');
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [drill, setDrill] = useState<DrillTarget | null>(null);

    const params = useMemo(
        () => ({
            period: filters.period,
            from: filters.from,
            to: filters.to,
            site_id: filters.site_id,
            lens: filters.lens,
        }),
        [filters],
    );

    const reload = (next: Record<string, string | number | null>) => {
        router.get(
            '/health-safety/analytics',
            { ...params, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const setPeriod = (p: string) =>
        p === 'custom'
            ? reload({ period: 'custom' })
            : reload({ period: p, from: null, to: null });

    const queryFor = (
        view: string,
        extra: Record<string, string | number> = {},
        base = 'export',
    ) => {
        const p = new URLSearchParams();
        p.set('view', view);
        p.set('period', filters.period);
        p.set('lens', filters.lens);
        if (filters.from) p.set('from', filters.from);
        if (filters.to) p.set('to', filters.to);
        if (filters.site_id) p.set('site_id', String(filters.site_id));
        Object.entries(extra).forEach(([k, v]) => p.set(k, String(v)));
        return base === 'export'
            ? `/health-safety/analytics/export?${p.toString()}`
            : p.toString();
    };

    const reportUrl = (name: string) => {
        const p = new URLSearchParams();
        if (filters.from) p.set('from', filters.from);
        if (filters.to) p.set('to', filters.to);
        if (filters.site_id !== null) p.set('site_id', String(filters.site_id));
        return `/health-safety/reports/${name}?${p.toString()}`;
    };

    const openDrill = (target: DrillTarget) => setDrill(target);

    const openCtx = (
        e: React.MouseEvent,
        target: DrillTarget,
        tag: string,
        tone: 'success' | 'warning' | 'critical' | 'info' | 'neutral',
    ) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [
            {
                icon: <Eye className="h-3.5 w-3.5" />,
                label: 'View detail',
                sub: 'Read-only drill-in',
                tone: 'primary',
                onClick: () => openDrill(target),
            },
        ];
        if (target.clientHref)
            items.push({
                icon: <User className="h-3.5 w-3.5" />,
                label: 'View client',
                onClick: () => router.visit(target.clientHref!),
            });
        if (target.staffHref)
            items.push({
                icon: <Users className="h-3.5 w-3.5" />,
                label: 'View staff',
                onClick: () => router.visit(target.staffHref!),
            });
        items.push({ sep: true });
        items.push({
            icon: <ClipboardList className="h-3.5 w-3.5" />,
            label: 'Open corrective action',
            onClick: () => router.visit('/health-safety/corrective-actions'),
        });
        items.push({
            icon: <Download className="h-3.5 w-3.5" />,
            label: 'Export CSV',
            onClick: () =>
                (window.location.href = queryFor(
                    target.view,
                    target.filters ?? {},
                )),
        });
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag,
            tagBg: `var(--status-${tone}-bg)`,
            tagColor: `var(--status-${tone})`,
            meta: target.label,
            items,
        });
    };

    // ── derived chart data ──
    const severitySegments: DonutDatum[] = severity_data.map((d) => ({
        key: d.severity,
        label: d.severity.replace(/_/g, ' '),
        value: d.count,
        color: severityFill(d.severity),
    }));
    const riskSegments: DonutDatum[] = hazard_data.map((d) => ({
        key: d.risk_rating,
        label: d.risk_rating,
        value: d.count,
        color: riskFill(d.risk_rating),
    }));
    const incidentTypeItems: BreakdownItem[] = incident_data.map((d, i) => ({
        key: d.type,
        label: d.type.replace(/_/g, ' '),
        value: d.count,
        color: PALETTE[i % PALETTE.length],
    }));
    const injuryTypeItems: BreakdownItem[] = injury_data.by_type.map(
        (d, i) => ({
            key: d.type,
            label: d.type.replace(/_/g, ' '),
            value: d.count,
            color: PALETTE[i % PALETTE.length],
        }),
    );
    const bodyPartItems: BreakdownItem[] = injury_data.by_body_part.map(
        (d, i) => ({
            key: d.body_part,
            label: d.body_part.replace(/_/g, ' '),
            value: d.count,
            color: PALETTE[i % PALETTE.length],
        }),
    );
    // ── drill builders ──
    const incidentTypeTarget = (it: BreakdownItem): DrillTarget => ({
        view: 'incidents',
        label: `Incidents · ${it.label}`,
        filters: { type: it.key },
        register: REGISTER.incidents,
    });
    const severityTarget = (d: DonutDatum): DrillTarget => ({
        view: 'incidents',
        label: `Incidents · ${d.label} severity`,
        filters: { severity: d.key },
        register: REGISTER.incidents,
    });
    const causeTarget = (cause: string): DrillTarget => ({
        view: 'incidents',
        label: `Root cause · ${cause}`,
        filters: { cause },
        register: REGISTER.incidents,
    });
    const injuryTypeTarget = (it: BreakdownItem): DrillTarget => ({
        view: 'injuries',
        label: `Injuries · ${it.label}`,
        filters: { type: it.key },
        register: REGISTER.injuries,
    });
    const bodyPartTarget = (it: BreakdownItem): DrillTarget => ({
        view: 'injuries',
        label: `Injuries · ${it.label}`,
        filters: { body_part: it.key },
        register: REGISTER.injuries,
    });
    const riskTarget = (d: DonutDatum): DrillTarget => ({
        view: 'hazards',
        label: `Hazards · ${d.label} risk`,
        filters: { risk: d.key },
        register: REGISTER.hazards,
    });
    const siteTarget = (s: SiteRow): DrillTarget => ({
        view: 'incidents',
        label: `Site · ${s.name}`,
        filters: { site_id: s.id },
        register: REGISTER.incidents,
    });
    // First-aid breakdowns are a leading care-activity signal (excluded from TRIFR). The drill
    // records endpoint has no first-aid view, so row/segment clicks navigate straight to the
    // First Aid register rather than opening the recordable-injury drill modal.
    const goFirstAid = () => router.visit(REGISTER.first_aid);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Analytics', href: '/health-safety/analytics' },
            ]}
        >
            <Head title="H&S Analytics" />

            <PageLayout
                hero={
                    <AnalyticsHeader
                        filters={filters}
                        sites={sites}
                        scorecard={scorecard}
                        period_summary={period_summary}
                        worksafe_notifiable={worksafe_notifiable}
                        tab={tab}
                        tabs={TABS}
                        onTabChange={setTab}
                        onPeriod={setPeriod}
                        onReload={reload}
                        exportHref={queryFor(
                            tab === 'sites' ? 'sites' : 'incidents',
                        )}
                        canViewBoardReports={canViewBoardReports}
                        reports={GOV_REPORTS.map((report) => ({
                            label: report.label,
                            href: reportUrl(report.name),
                        }))}
                    />
                }
            >
                <div className="flex min-w-0 flex-col gap-6">
                    <AnalyticsPeriodSummary
                        summary={period_summary}
                        sdsExpiring={sds_expiring}
                        sourceCompleteness={props.source_completeness}
                    />

                    <RoleLensBanner lens={filters.lens} />

                    {tab === 'overview' ? (
                        <OverviewTab
                            lens={filters.lens}
                            scorecard={scorecard}
                            trends={trends}
                            hoursMeta={hours_meta}
                            severitySegments={severitySegments}
                            incidentTypeItems={incidentTypeItems}
                            onDrill={openDrill}
                            onCtx={openCtx}
                            severityTarget={severityTarget}
                            incidentTypeTarget={incidentTypeTarget}
                        />
                    ) : null}

                    {tab === 'trends' ? <TrendsTab trends={trends} /> : null}

                    {tab === 'breakdowns' ? (
                        <div className="grid gap-4">
                            <BreakdownsTab
                                sourceNotices={[
                                    ...(injury_data.access === 'restricted'
                                        ? [
                                              'Access to injury breakdowns is restricted.',
                                          ]
                                        : injury_data.access ===
                                            'repair_required'
                                          ? [
                                                'Recorded injury facts need source review.',
                                            ]
                                          : []),
                                ]}
                                incidentTypeItems={incidentTypeItems}
                                severitySegments={severitySegments}
                                rootCause={root_cause_data}
                                injuryTypeItems={injuryTypeItems}
                                bodyPartItems={bodyPartItems}
                                riskSegments={riskSegments}
                                firstAidData={props.first_aid_data}
                                onDrill={openDrill}
                                onCtx={openCtx}
                                onFirstAid={goFirstAid}
                                incidentTypeTarget={incidentTypeTarget}
                                severityTarget={severityTarget}
                                causeTarget={causeTarget}
                                injuryTypeTarget={injuryTypeTarget}
                                bodyPartTarget={bodyPartTarget}
                                riskTarget={riskTarget}
                            />
                            <RestraintBreakdowns data={restraint_analytics} />
                        </div>
                    ) : null}

                    {tab === 'sites' ? (
                        <div className="grid gap-4">
                            <Card className="rounded-xl shadow-sm">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-bold">
                                        Site comparison league
                                    </CardTitle>
                                    <p className="text-xs text-muted-foreground">
                                        Click a column to sort · click or
                                        right-click a row to drill in.
                                        Compliance score reflects NZ obligations
                                        (incidents, open hazards,
                                        emergency-drill cadence).
                                    </p>
                                </CardHeader>
                                <CardContent>
                                    <SiteLeague
                                        sites={site_comparison}
                                        onRow={(s) => openDrill(siteTarget(s))}
                                        onRowCtx={(e, s) =>
                                            openCtx(
                                                e,
                                                siteTarget(s),
                                                s.compliance_score === null
                                                    ? 'Not confirmed'
                                                    : `${s.compliance_score}%`,
                                                s.compliance_score === null
                                                    ? 'neutral'
                                                    : s.compliance_score >= 90
                                                      ? 'success'
                                                      : s.compliance_score >= 70
                                                        ? 'warning'
                                                        : 'critical',
                                            )
                                        }
                                    />
                                </CardContent>
                            </Card>
                            <Card className="rounded-xl shadow-sm">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-bold">
                                        Hotspot heatmap
                                    </CardTitle>
                                    <p className="text-xs text-muted-foreground">
                                        Sites × burden metrics,
                                        intensity-shaded.
                                    </p>
                                </CardHeader>
                                <CardContent>
                                    <Heatmap
                                        sites={site_comparison}
                                        onCell={(s) => openDrill(siteTarget(s))}
                                    />
                                </CardContent>
                            </Card>
                        </div>
                    ) : null}

                    {tab === 'governance' ? (
                        <GovernanceTab
                            scorecard={scorecard}
                            trends={trends}
                            reportUrl={reportUrl}
                            exportUrl={() =>
                                (window.location.href = queryFor('incidents'))
                            }
                            canViewReports={canViewBoardReports}
                        />
                    ) : null}

                    {/* hours-worked honesty footnote — frequency rates need a meaningful exposure basis */}
                    {hours_meta.total_hours < 1000 ? (
                        <p className="text-[11px] text-muted-foreground">
                            LTIFR / TRIFR need ≥1,000 worked hours in the
                            trailing-12-month basis to report a meaningful rate
                            (standard frequency-rate convention); they show “—”
                            until then.
                        </p>
                    ) : null}

                    {/* org + NZ framework footer */}
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-border pt-3 text-[11px] text-muted-foreground">
                        <span>WorkSafe NZ · HSWA 2015</span>
                        <span aria-hidden>·</span>
                        <span>Ngā Paerewa NZS 8134:2021</span>
                        <span aria-hidden>·</span>
                        <span>Hazardous Substances Regulations 2017</span>
                        <span aria-hidden>·</span>
                        <span>ACC</span>
                        <span aria-hidden>·</span>
                        <span>
                            {Math.round(
                                hours_meta.total_hours,
                            ).toLocaleString()}{' '}
                            hours worked (12-month basis)
                        </span>
                    </div>
                </div>
            </PageLayout>

            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}
            {drill ? (
                <DrillModal
                    target={drill}
                    query={queryFor(drill.view, drill.filters ?? {}, 'records')}
                    onClose={() => setDrill(null)}
                    onExport={() =>
                        (window.location.href = queryFor(
                            drill.view,
                            drill.filters ?? {},
                        ))
                    }
                />
            ) : null}
        </AppLayout>
    );
}

// ── sub-components ──────────────────────────────────────────────────────

type DrillBuilders = {
    onDrill: (t: DrillTarget) => void;
    onCtx: (
        e: React.MouseEvent,
        t: DrillTarget,
        tag: string,
        tone: 'success' | 'warning' | 'critical' | 'info',
    ) => void;
};

function OverviewTab({
    lens,
    scorecard,
    trends,
    severitySegments,
    incidentTypeItems,
    onDrill,
    onCtx,
    severityTarget,
    incidentTypeTarget,
}: DrillBuilders & {
    lens: string;
    scorecard: AnalyticsProps['scorecard'];
    trends: AnalyticsProps['trends'];
    hoursMeta: AnalyticsProps['hours_meta'];
    severitySegments: DonutDatum[];
    incidentTypeItems: BreakdownItem[];
    severityTarget: (d: DonutDatum) => DrillTarget;
    incidentTypeTarget: (i: BreakdownItem) => DrillTarget;
}) {
    const scorecardBlock = (
        <Scorecard leading={scorecard.leading} lagging={scorecard.lagging} />
    );
    const headlineTrends = (
        <div className="grid gap-4 lg:grid-cols-2">
            <ChartCard
                title="LTIFR & TRIFR"
                subtitle="Lost-time & total recordable injury frequency (per 1M hrs)"
                aria="LTIFR and TRIFR trend over time"
                table={{
                    caption: 'LTIFR and TRIFR by month',
                    columns: ['Month', 'LTIFR', 'TRIFR'],
                    rows: trends.map((t) => [
                        t.label,
                        t.ltifr ?? '—',
                        t.trifr ?? '—',
                    ]),
                }}
            >
                <LtifrTrifrChart trends={trends} />
            </ChartCard>
            <ChartCard
                title="Near-miss : incident ratio"
                subtitle="Higher is healthier — a strong reporting culture trends up"
                aria="Near-miss to incident ratio over time"
                table={{
                    caption: 'Near-miss ratio by month',
                    columns: ['Month', 'Ratio'],
                    rows: trends.map((t) => [t.label, t.near_miss_ratio]),
                }}
            >
                <SingleAreaChart
                    trends={trends}
                    dataKey="near_miss_ratio"
                    name="Ratio"
                    color={TOKEN.success}
                />
            </ChartCard>
        </div>
    );
    const threeUp = (
        <div className="grid gap-4 lg:grid-cols-3">
            <ChartCard
                title="Incident severity"
                subtitle="Distribution this period"
                aria="Incident severity distribution"
                table={{
                    caption: 'Incidents by severity',
                    columns: ['Severity', 'Count'],
                    rows: severitySegments.map((s) => [s.label, s.value]),
                }}
            >
                <FocusDonut
                    segments={severitySegments}
                    onSegment={(d) => onDrill(severityTarget(d))}
                    onSegmentCtx={(e, d) =>
                        onCtx(e, severityTarget(d), String(d.value), 'info')
                    }
                />
            </ChartCard>
            <ChartCard
                title="Incidents by type"
                subtitle="Click a bar or row to drill in"
                aria="Incidents by type"
                table={{
                    caption: 'Incidents by type',
                    columns: ['Type', 'Count'],
                    rows: incidentTypeItems.map((s) => [s.label, s.value]),
                }}
            >
                <HorizontalBars data={incidentTypeItems} />
                <BreakdownRows
                    items={incidentTypeItems}
                    onItem={(d) => onDrill(incidentTypeTarget(d))}
                    onItemCtx={(e, d) =>
                        onCtx(e, incidentTypeTarget(d), String(d.value), 'info')
                    }
                />
            </ChartCard>
            <ChartCard
                title="Hazard burn-down"
                subtitle="Opened vs closed + running open"
                aria="Hazard burn-down over time"
                table={{
                    caption: 'Hazards opened, closed and running open by month',
                    columns: ['Month', 'Opened', 'Closed', 'Open'],
                    rows: trends.map((t) => [
                        t.label,
                        t.hazards_opened,
                        t.hazards_closed,
                        t.hazards_open,
                    ]),
                }}
            >
                <HazardBurndownChart trends={trends} />
            </ChartCard>
        </div>
    );

    // role re-weights ordering: governance leads with the board scorecard;
    // frontline leads with the live breakdowns; manager leads with trends.
    return (
        <div className="grid gap-4">
            {lens === 'frontline' ? (
                <>
                    {threeUp}
                    {headlineTrends}
                    {scorecardBlock}
                </>
            ) : lens === 'governance' ? (
                <>
                    {scorecardBlock}
                    {headlineTrends}
                    {threeUp}
                </>
            ) : (
                <>
                    {scorecardBlock}
                    {headlineTrends}
                    {threeUp}
                </>
            )}
        </div>
    );
}

function TrendsTab({ trends }: { trends: AnalyticsProps['trends'] }) {
    const participationAudience = participationViewer(
        usePage<SharedData>().props.auth,
    );
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <ChartCard
                title="LTIFR & TRIFR"
                subtitle="Per 1,000,000 hours worked (NZ/AU convention)"
                aria="LTIFR and TRIFR trend"
                table={{
                    caption: 'LTIFR/TRIFR by month',
                    columns: ['Month', 'LTIFR', 'TRIFR'],
                    rows: trends.map((t) => [
                        t.label,
                        t.ltifr ?? '—',
                        t.trifr ?? '—',
                    ]),
                }}
            >
                <LtifrTrifrChart trends={trends} />
            </ChartCard>
            <ChartCard
                title="Near-miss : incident ratio"
                subtitle="Leading reporting-culture signal"
                aria="Near-miss ratio trend"
                table={{
                    caption: 'Near-miss ratio by month',
                    columns: ['Month', 'Ratio'],
                    rows: trends.map((t) => [t.label, t.near_miss_ratio]),
                }}
            >
                <SingleAreaChart
                    trends={trends}
                    dataKey="near_miss_ratio"
                    name="Ratio"
                    color={TOKEN.success}
                />
            </ChartCard>
            <ChartCard
                title="Incidents / 30 days"
                subtitle="Lagging volume"
                aria="Incidents per period"
                table={{
                    caption: 'Incidents by month',
                    columns: ['Month', 'Incidents'],
                    rows: trends.map((t) => [t.label, t.incidents]),
                }}
            >
                <SingleAreaChart
                    trends={trends}
                    dataKey="incidents"
                    name="Incidents"
                    color={TOKEN.warning}
                />
            </ChartCard>
            <ChartCard
                title="Hazard burn-down"
                subtitle="Opened (bars) vs closed (bars) + running open (line)"
                aria="Hazard burn-down"
                table={{
                    caption: 'Hazards by month',
                    columns: ['Month', 'Opened', 'Closed', 'Open'],
                    rows: trends.map((t) => [
                        t.label,
                        t.hazards_opened,
                        t.hazards_closed,
                        t.hazards_open,
                    ]),
                }}
            >
                <HazardBurndownChart trends={trends} />
            </ChartCard>
            <ChartCard
                title="Corrective-action closure"
                subtitle="Avg days to close + % closed on time"
                aria="Corrective-action closure trend"
                table={{
                    caption: 'Corrective-action closure by month',
                    columns: ['Month', 'Avg days', '% on time'],
                    rows: trends.map((t) => [
                        t.label,
                        t.ca_avg_days ?? '—',
                        t.ca_pct_on_time ?? '—',
                    ]),
                }}
            >
                <CaClosureChart trends={trends} />
            </ChartCard>
            <ChartCard
                title="Training & audit compliance"
                subtitle="% mandatory training valid"
                aria="Training and audit compliance trend"
                table={{
                    caption: 'Compliance by month',
                    columns: ['Month', 'Compliance %'],
                    rows: trends.map((t) => [t.label, t.compliance_pct ?? '—']),
                }}
            >
                <SingleAreaChart
                    trends={trends}
                    dataKey="compliance_pct"
                    name="Compliance %"
                    color={TOKEN.c2}
                    domain={[
                        (min: number) =>
                            Math.min(60, Math.floor((min || 60) / 10) * 10),
                        100,
                    ]}
                />
            </ChartCard>
            <ChartCard
                title="WorkSafe notifiable events"
                subtitle="Notified vs awaiting notification (HSWA s.56)"
                aria="WorkSafe notifiable events over time"
                table={{
                    caption: 'WorkSafe notifiable by month',
                    columns: ['Month', 'Notified', 'Awaiting'],
                    rows: trends.map((t) => [
                        t.label,
                        t.worksafe_notified,
                        t.worksafe_awaiting,
                    ]),
                }}
            >
                <WorksafeNotifiableChart trends={trends} />
            </ChartCard>
            <ChartCard
                title="Worker participation"
                subtitle="HSR/committee engagement + consultation completion"
                aria="Worker participation trend"
                table={{
                    caption: 'Worker participation by month',
                    columns: [
                        'Month',
                        'Meetings completed %',
                        'Consultation %',
                    ],
                    rows: workerParticipationTable(
                        trends,
                        participationAudience,
                    ),
                }}
            >
                <WorkerParticipationChart trends={trends} />
            </ChartCard>
        </div>
    );
}

function BreakdownsTab({
    sourceNotices,
    incidentTypeItems,
    severitySegments,
    rootCause,
    injuryTypeItems,
    bodyPartItems,
    riskSegments,
    firstAidData,
    onDrill,
    onCtx,
    onFirstAid,
    incidentTypeTarget,
    severityTarget,
    causeTarget,
    injuryTypeTarget,
    bodyPartTarget,
    riskTarget,
}: DrillBuilders & {
    sourceNotices: string[];
    incidentTypeItems: BreakdownItem[];
    severitySegments: DonutDatum[];
    rootCause: AnalyticsProps['root_cause_data'];
    injuryTypeItems: BreakdownItem[];
    bodyPartItems: BreakdownItem[];
    riskSegments: DonutDatum[];
    firstAidData: AnalyticsProps['first_aid_data'];
    onFirstAid: () => void;
    incidentTypeTarget: (i: BreakdownItem) => DrillTarget;
    severityTarget: (d: DonutDatum) => DrillTarget;
    causeTarget: (cause: string) => DrillTarget;
    injuryTypeTarget: (i: BreakdownItem) => DrillTarget;
    bodyPartTarget: (i: BreakdownItem) => DrillTarget;
    riskTarget: (d: DonutDatum) => DrillTarget;
}) {
    return (
        <div className="grid gap-4">
            {sourceNotices.length > 0 && (
                <p role="status" className="text-subtle text-muted-foreground">
                    {sourceNotices.join(' ')}
                </p>
            )}
            <ChartCard
                title="Root-cause Pareto"
                subtitle="The vital few behind ~80% of events — the key HSWA investigation output"
                aria="Root cause Pareto chart with cumulative percentage"
                table={{
                    caption: 'Root cause Pareto',
                    columns: ['Cause', 'Count', '%', 'Cumulative %'],
                    rows: rootCause.map((r) => [
                        r.cause,
                        r.count,
                        `${r.pct}%`,
                        `${r.cumulative_pct}%`,
                    ]),
                }}
            >
                <RootCausePareto
                    data={rootCause}
                    onBar={(r) => onDrill(causeTarget(r.cause))}
                    onBarCtx={(e, r) =>
                        onCtx(
                            e,
                            causeTarget(r.cause),
                            String(r.count),
                            'critical',
                        )
                    }
                />
                <BreakdownRows
                    items={rootCause.map((r, i) => ({
                        key: r.cause,
                        label: r.cause,
                        value: r.count,
                        color:
                            i === 0
                                ? TOKEN.critical
                                : i <= 2
                                  ? TOKEN.warning
                                  : TOKEN.primary,
                    }))}
                    onItem={(d) => onDrill(causeTarget(d.key))}
                    onItemCtx={(e, d) =>
                        onCtx(
                            e,
                            causeTarget(d.key),
                            String(d.value),
                            'critical',
                        )
                    }
                />
            </ChartCard>

            <div className="grid gap-4 lg:grid-cols-2">
                <ChartCard
                    title="Incidents by type"
                    subtitle="Click to drill into the register"
                    aria="Incidents by type"
                    table={{
                        caption: 'Incidents by type',
                        columns: ['Type', 'Count'],
                        rows: incidentTypeItems.map((s) => [s.label, s.value]),
                    }}
                >
                    <HorizontalBars data={incidentTypeItems} />
                    <BreakdownRows
                        items={incidentTypeItems}
                        onItem={(d) => onDrill(incidentTypeTarget(d))}
                        onItemCtx={(e, d) =>
                            onCtx(
                                e,
                                incidentTypeTarget(d),
                                String(d.value),
                                'info',
                            )
                        }
                    />
                </ChartCard>
                <ChartCard
                    title="Incident severity"
                    subtitle="Hover to focus · click to drill"
                    aria="Incident severity distribution"
                    table={{
                        caption: 'Incidents by severity',
                        columns: ['Severity', 'Count'],
                        rows: severitySegments.map((s) => [s.label, s.value]),
                    }}
                >
                    <FocusDonut
                        segments={severitySegments}
                        onSegment={(d) => onDrill(severityTarget(d))}
                        onSegmentCtx={(e, d) =>
                            onCtx(e, severityTarget(d), String(d.value), 'info')
                        }
                    />
                </ChartCard>
                <ChartCard
                    title="Injuries by type"
                    subtitle="ACC-reportable workplace injuries"
                    aria="Injuries by type"
                    table={{
                        caption: 'Injuries by type',
                        columns: ['Type', 'Count'],
                        rows: injuryTypeItems.map((s) => [s.label, s.value]),
                    }}
                >
                    <HorizontalBars data={injuryTypeItems} />
                    <BreakdownRows
                        items={injuryTypeItems}
                        onItem={(d) => onDrill(injuryTypeTarget(d))}
                        onItemCtx={(e, d) =>
                            onCtx(
                                e,
                                injuryTypeTarget(d),
                                String(d.value),
                                'warning',
                            )
                        }
                    />
                </ChartCard>
                <ChartCard
                    title="Injury by body part"
                    subtitle="Where harm concentrates"
                    aria="Injury by body part"
                    table={{
                        caption: 'Injuries by body part',
                        columns: ['Body part', 'Count'],
                        rows: bodyPartItems.map((s) => [s.label, s.value]),
                    }}
                >
                    <HorizontalBars data={bodyPartItems} />
                    <BreakdownRows
                        items={bodyPartItems}
                        onItem={(d) => onDrill(bodyPartTarget(d))}
                        onItemCtx={(e, d) =>
                            onCtx(
                                e,
                                bodyPartTarget(d),
                                String(d.value),
                                'warning',
                            )
                        }
                    />
                </ChartCard>
                <FirstAidBreakdowns data={firstAidData} onOpen={onFirstAid} />
            </div>

            <ChartCard
                title="Open hazards by risk rating"
                subtitle="Hover to focus · click to drill"
                aria="Open hazards by risk rating"
                className="lg:max-w-xl"
                table={{
                    caption: 'Hazards by risk rating',
                    columns: ['Risk', 'Count'],
                    rows: riskSegments.map((s) => [s.label, s.value]),
                }}
            >
                <FocusDonut
                    segments={riskSegments}
                    onSegment={(d) => onDrill(riskTarget(d))}
                    onSegmentCtx={(e, d) =>
                        onCtx(e, riskTarget(d), String(d.value), 'critical')
                    }
                />
            </ChartCard>
        </div>
    );
}

/**
 * Restraint & behaviour-support breakdowns (Ngā Paerewa NZS 8134:2021 least-restrictive
 * practice). Additive analytics section — restraint events by type/severity and BSPs by
 * status, with a governance summary strip. Rows deep-link into the restraints register.
 */
function RestraintBreakdowns({
    data,
}: {
    data: AnalyticsProps['restraint_analytics'];
}) {
    if (!data) return null;
    const s = data.summary;
    const toItems = (
        rows: { label: string; count: number }[] | null,
        colour: (label: string, i: number) => string,
    ): BreakdownItem[] =>
        (rows ?? []).map((r, i) => ({
            key: r.label || 'unspecified',
            label: titleCaseLabel(r.label),
            value: r.count,
            color: colour(r.label, i),
        }));

    const typeItems = toItems(
        data.breakdowns.by_type,
        (_l, i) =>
            [TOKEN.primary, TOKEN.c2, TOKEN.c3, TOKEN.c4, TOKEN.c5][i % 5],
    );
    const severityItems = toItems(data.breakdowns.by_severity, (l) =>
        severityFill(l),
    );
    const planStatusItems = toItems(data.breakdowns.by_plan_status, (l) =>
        planStatusFill(l),
    );

    const stats: {
        label: string;
        value: number | null;
        tone: 'neutral' | 'warning' | 'critical';
        href: string;
    }[] = [
        {
            label: 'Events (period)',
            value: s.events_in_period,
            tone: 'neutral',
            href: '/health-safety/restraints?lens=events&tab=30d',
        },
        {
            label: 'Out of plan',
            value: s.out_of_plan,
            tone: 'critical',
            href: '/health-safety/restraints?lens=events&tab=out_of_plan',
        },
        {
            label: 'With injury',
            value: s.with_injury,
            tone: 'critical',
            href: '/health-safety/restraints?lens=events&tab=injury',
        },
        {
            label: 'Unreviewed',
            value: s.unreviewed,
            tone: 'warning',
            href: '/health-safety/restraints?lens=events&tab=unreviewed',
        },
        {
            label: 'Active BSPs',
            value: s.active_plans,
            tone: 'neutral',
            href: '/health-safety/restraints?lens=plans&tab=active',
        },
        {
            label: 'Plans review due',
            value: s.plans_review_due,
            tone: 'warning',
            href: '/health-safety/restraints?lens=plans&tab=review_due',
        },
    ];
    const statValueClass = (
        tone: 'neutral' | 'warning' | 'critical',
        value: number | null,
    ) =>
        `mt-0.5 text-xl font-bold tabular-nums ${value === null || value <= 0 ? 'text-foreground' : tone === 'critical' ? 'text-status-critical' : tone === 'warning' ? 'text-status-warning' : 'text-foreground'}`;

    return (
        <div className="grid gap-4">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h3 className="text-sm font-bold text-foreground">
                        Restraint &amp; behaviour support
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        Least-restrictive practice — Ngā Paerewa NZS 8134:2021.
                        Click a tile to open the register.
                    </p>
                </div>
                <Link
                    href="/health-safety/restraints"
                    className="shrink-0 text-xs font-medium text-primary hover:underline"
                >
                    Open register →
                </Link>
            </div>

            <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                {stats.map((st) => (
                    <Link
                        key={st.label}
                        href={st.href}
                        className="flex flex-col rounded-lg border border-border bg-card p-3 transition-colors hover:border-primary/40 hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                    >
                        <span className="text-[11px] font-medium text-muted-foreground">
                            {st.label}
                        </span>
                        <span className={statValueClass(st.tone, st.value)}>
                            {st.value ?? 'Not confirmed'}
                        </span>
                    </Link>
                ))}
            </div>

            <p className="text-subtle text-muted-foreground">
                {s.source_access === 'restricted'
                    ? 'Access to restraint measures is restricted.'
                    : 'Measures cover readable records; the full picture may be incomplete.'}
            </p>
            {data.breakdowns.by_type !== null &&
            data.breakdowns.by_severity !== null &&
            data.breakdowns.by_plan_status !== null ? (
                <div className="grid gap-4 lg:grid-cols-3">
                    <ChartCard
                        title="Restraints by type"
                        subtitle="Restrictive practice used"
                        aria="Restraint events by type"
                        table={{
                            caption: 'Restraint events by type',
                            columns: ['Type', 'Count'],
                            rows: typeItems.map((s) => [s.label, s.value]),
                        }}
                    >
                        {typeItems.length ? (
                            <HorizontalBars data={typeItems} />
                        ) : (
                            <EmptyBreakdown />
                        )}
                    </ChartCard>
                    <ChartCard
                        title="Restraints by severity"
                        subtitle="Distribution this period"
                        aria="Restraint events by severity"
                        table={{
                            caption: 'Restraint events by severity',
                            columns: ['Severity', 'Count'],
                            rows: severityItems.map((s) => [s.label, s.value]),
                        }}
                    >
                        {severityItems.length ? (
                            <HorizontalBars data={severityItems} />
                        ) : (
                            <EmptyBreakdown />
                        )}
                    </ChartCard>
                    <ChartCard
                        title="Behaviour support plans"
                        subtitle="By lifecycle status"
                        aria="Behaviour support plans by status"
                        table={{
                            caption: 'Behaviour support plans by status',
                            columns: ['Status', 'Count'],
                            rows: planStatusItems.map((s) => [
                                s.label,
                                s.value,
                            ]),
                        }}
                    >
                        {planStatusItems.length ? (
                            <HorizontalBars data={planStatusItems} />
                        ) : (
                            <EmptyBreakdown />
                        )}
                    </ChartCard>
                </div>
            ) : (
                <p className="text-subtle text-muted-foreground">
                    Restraint breakdowns are unavailable.
                </p>
            )}
        </div>
    );
}

function EmptyBreakdown() {
    return (
        <p className="flex h-[120px] items-center justify-center text-xs text-muted-foreground">
            No data for this period.
        </p>
    );
}

function titleCaseLabel(s: string): string {
    if (!s) return 'Unspecified';
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function planStatusFill(status: string): string {
    switch (status) {
        case 'active':
            return TOKEN.success;
        case 'under_review':
            return TOKEN.warning;
        case 'draft':
            return TOKEN.info;
        case 'archived':
            return TOKEN.c5;
        default:
            return TOKEN.primary;
    }
}

function GovernanceTab({
    scorecard,
    trends,
    reportUrl,
    exportUrl,
    canViewReports,
}: {
    scorecard: AnalyticsProps['scorecard'];
    trends: AnalyticsProps['trends'];
    reportUrl: (n: string) => string;
    exportUrl: () => void;
    canViewReports: boolean;
}) {
    const participationAudience = participationViewer(
        usePage<SharedData>().props.auth,
    );
    return (
        <div className="grid gap-4">
            <Scorecard
                leading={scorecard.leading}
                lagging={scorecard.lagging}
            />

            <div className="grid gap-4 lg:grid-cols-2">
                <ChartCard
                    title="Worker participation & engagement"
                    subtitle="HSR/committee engagement + consultation completion (HSWA worker-engagement duty)"
                    aria="Worker participation trend"
                    table={{
                        caption: 'Worker participation by month',
                        columns: [
                            'Month',
                            'Meetings completed %',
                            'Consultation %',
                        ],
                        rows: workerParticipationTable(
                            trends,
                            participationAudience,
                        ),
                    }}
                >
                    <WorkerParticipationChart trends={trends} />
                </ChartCard>
                <ChartCard
                    title="Competency & training completion"
                    subtitle="Mandatory training valid over time"
                    aria="Training compliance trend"
                    table={{
                        caption: 'Training compliance by month',
                        columns: ['Month', 'Compliance %'],
                        rows: trends.map((t) => [
                            t.label,
                            t.compliance_pct ?? '—',
                        ]),
                    }}
                >
                    <SingleAreaChart
                        trends={trends}
                        dataKey="compliance_pct"
                        name="Compliance %"
                        color={TOKEN.c2}
                        domain={[
                            (min: number) =>
                                Math.min(60, Math.floor((min || 60) / 10) * 10),
                            100,
                        ]}
                    />
                </ChartCard>
            </div>

            <div>
                <h3 className="mb-2 text-sm font-bold text-foreground">
                    Governance packs
                </h3>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {/* Report packs open governance.view-gated routes; hide them for
                        register-only roles. The CSV export below is hazards.view-gated,
                        so it stays available. */}
                    {canViewReports ? (
                        <>
                            <GovPackCard
                                icon={FileText}
                                title="Board safety summary"
                                desc="Leading-vs-lagging assurance for the board pack."
                                onOpen={() =>
                                    window.open(
                                        reportUrl('board-summary'),
                                        '_blank',
                                    )
                                }
                            />
                            <GovPackCard
                                icon={Shield}
                                title="WorkSafe register analytics"
                                desc="Notifiable events register (HSWA s.56)."
                                onOpen={() =>
                                    window.open(
                                        reportUrl('worksafe-register'),
                                        '_blank',
                                    )
                                }
                            />
                            <GovPackCard
                                icon={Activity}
                                title="Investigation outcomes"
                                desc="Investigation status and outcomes summary."
                                onOpen={() =>
                                    window.open(
                                        reportUrl('investigation-outcomes'),
                                        '_blank',
                                    )
                                }
                            />
                            <GovPackCard
                                icon={ClipboardList}
                                title="Corrective-action traceability"
                                desc="Action close-out evidence trail."
                                onOpen={() =>
                                    window.open(
                                        reportUrl(
                                            'corrective-action-traceability',
                                        ),
                                        '_blank',
                                    )
                                }
                            />
                            <GovPackCard
                                icon={AlertTriangle}
                                title="Risk-assessment register"
                                desc="Current hazard/risk assessment register."
                                onOpen={() =>
                                    window.open(
                                        reportUrl('risk-assessment-register'),
                                        '_blank',
                                    )
                                }
                            />
                        </>
                    ) : null}
                    <GovPackCard
                        icon={Download}
                        title="Export current view"
                        desc="CSV of the active analytics view."
                        actionLabel="Download"
                        onOpen={exportUrl}
                    />
                </div>
            </div>
        </div>
    );
}
