/**
 * Health & Safety Analytics — trend / root-cause / governance explorer.
 *
 * Read-only analyse/explore/export surface (no create wizards — those live on
 * the dashboard). Shares the dashboard's hero/tab/filter idiom. NZ-only:
 * LTIFR / TRIFR, WorkSafe notifiable events, Nga Paerewa NZS 8134:2021, ACC.
 */
import { EntityFilter, ShiftContextMenu, TabStrip } from '@/components/rostering';
import type { RosterTabItem, ShiftCtxItem, ShiftCtxState } from '@/components/rostering';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { WizardShell } from '@/components/wizard/shell';
import type { WizardStep } from '@/components/wizard/shell';
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
    ChevronDown,
    ClipboardList,
    Clock,
    Download,
    ExternalLink,
    Eye,
    FileText,
    LayoutDashboard,
    Shield,
    TrendingDown,
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
import type { AnalyticsProps, DrillTarget, ScorecardItem, SiteRow } from './analytics-types';
import { RoleLensBanner } from './components/dashboard-tabs';
import {
    HeroCluster,
    HeroClusterTile,
    HeroComplianceBadges,
    HeroMedallion,
    type HeroSegItem,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    HeroSummaryMetric,
    HeroSummaryStrip,
    type Tone,
} from './components/hs-hero-kit';

const PALETTE = [TOKEN.c1, TOKEN.c2, TOKEN.c3, TOKEN.c4, TOKEN.c5, 'var(--status-info)'];

// Lens segmented items (shared kit shape). Period presets are built inline in the
// component because the Custom item embeds a range-picker popover with live closures.
const LENS_SEG_ITEMS: HeroSegItem[] = [
    { key: 'governance', label: 'Governance' },
    { key: 'manager', label: 'Manager' },
    { key: 'frontline', label: 'Frontline' },
];

const TABS: RosterTabItem[] = [
    { id: 'overview', label: 'Overview', icon: LayoutDashboard, tone: 'primary' },
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
};

// Governance report set — same list as the dashboard's export popover; analytics opens each
// dated report in a new tab (export-led), the dashboard navigates (report-led).
const GOV_REPORTS: { name: string; label: string }[] = [
    { name: 'board-summary', label: 'Board summary' },
    { name: 'worksafe-register', label: 'WorkSafe register' },
    { name: 'investigation-outcomes', label: 'Investigation outcomes' },
    { name: 'corrective-action-traceability', label: 'Corrective-action traceability' },
    { name: 'risk-assessment-register', label: 'Risk-assessment register' },
];

function rangeLabel(period: string): string {
    return { '30d': 'Last 30 days', q: 'This quarter', '6m': 'Last 6 months', ytd: 'Year to date', custom: 'Custom range' }[period] ?? 'Year to date';
}

function fmt(v: number | null | undefined, suffix = ''): string {
    return v === null || v === undefined ? '—' : `${v}${suffix}`;
}

function toneFromDir(dir: string): Tone {
    return dir === 'improving' ? 'success' : dir === 'watch' ? 'critical' : 'neutral';
}

function deltaArrow(dir: string): string {
    return dir === 'improving' ? '▲' : dir === 'watch' ? '▼' : '—';
}

// ── small UI helpers ────────────────────────────────────────────────────

function ScorecardCell({ item }: { item: ScorecardItem }) {
    const tone = item.dir === 'improving' ? 'text-status-success' : item.dir === 'watch' ? 'text-status-critical' : 'text-muted-foreground';
    return (
        // eslint-disable-next-line no-restricted-syntax -- compact scorecard metric cell, not a content card
        <div className="rounded-lg border border-border bg-card p-2.5">
            <div className="truncate text-[11px] text-muted-foreground">{item.label}</div>
            <div className="mt-0.5 text-lg font-bold tabular-nums text-foreground">
                {fmt(item.value)}
                <span className="text-xs font-semibold text-muted-foreground">{item.value !== null ? item.suffix : ''}</span>
            </div>
            {item.delta !== null ? (
                <div className={cn('mt-0.5 flex items-center gap-1 text-[11px] font-medium', tone)}>
                    <span aria-hidden>{deltaArrow(item.dir)}</span>
                    <span>
                        {item.delta > 0 ? '+' : ''}
                        {item.delta} vs last mo
                    </span>
                </div>
            ) : (
                <div className="mt-0.5 text-[11px] text-muted-foreground">no prior data</div>
            )}
        </div>
    );
}

function Scorecard({ leading, lagging }: { leading: ScorecardItem[]; lagging: ScorecardItem[] }) {
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <div className="rounded-xl border border-status-success/30 bg-status-success-bg/40 p-4">
                <div className="mb-2 flex items-center gap-2">
                    <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-status-success-bg text-status-success">
                        <TrendingUp className="h-4 w-4" />
                    </span>
                    <div>
                        <h3 className="text-sm font-bold text-foreground">Leading indicators</h3>
                        <p className="text-[11px] text-muted-foreground">Proactive — a healthy safety culture trends up</p>
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
                        <h3 className="text-sm font-bold text-foreground">Lagging outcomes</h3>
                        <p className="text-[11px] text-muted-foreground">Reactive — harm already occurred; lower is better</p>
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

type SortKey = keyof Pick<SiteRow, 'name' | 'total_incidents' | 'open_hazards' | 'lost_time_days' | 'ltifr' | 'trifr' | 'compliance_score' | 'drill_status'>;

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
    if (status === 'compliant') return 'bg-status-success-bg text-status-success border-status-success/30';
    if (status === 'due_soon') return 'bg-status-warning-bg text-status-warning border-status-warning/30';
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
            if (av === null) return 1;
            if (bv === null) return -1;
            if (typeof av === 'number' && typeof bv === 'number') return asc ? av - bv : bv - av;
            return asc ? String(av).localeCompare(String(bv)) : String(bv).localeCompare(String(av));
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
                                    <button type="button" onClick={() => toggle(key)} className="inline-flex items-center gap-1 hover:text-foreground" aria-label={`Sort by ${label}`}>
                                        {label}
                                        <span aria-hidden className="text-[10px]">
                                            {sortKey === key ? (asc ? '▲' : '▼') : '↕'}
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
                            className="cursor-pointer border-b border-line outline-none last:border-0 hover:bg-accent focus-visible:bg-accent"
                        >
                            <td className="py-2 font-medium text-foreground">{s.name}</td>
                            <td className="py-2 tabular-nums">{s.total_incidents}</td>
                            <td className="py-2 tabular-nums">{s.open_hazards}</td>
                            <td className="py-2 tabular-nums">{s.lost_time_days}</td>
                            <td className="py-2 tabular-nums">{fmt(s.ltifr)}</td>
                            <td className="py-2 tabular-nums">{fmt(s.trifr)}</td>
                            <td className="py-2">
                                <span className={cn('font-semibold tabular-nums', s.compliance_score >= 90 ? 'text-status-success' : s.compliance_score >= 70 ? 'text-status-warning' : 'text-status-critical')}>
                                    {s.compliance_score}%
                                </span>
                            </td>
                            <td className="py-2">
                                <Badge className={cn('border', drillStatusBadge(s.drill_status))}>
                                    {s.drill_status === 'due_soon' ? 'Due soon' : s.drill_status.charAt(0).toUpperCase() + s.drill_status.slice(1)}
                                </Badge>
                            </td>
                        </tr>
                    ))}
                    {!sorted.length ? (
                        <tr>
                            <td colSpan={LEAGUE_COLS.length} className="py-6 text-center text-muted-foreground">
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

function Heatmap({ sites, onCell }: { sites: SiteRow[]; onCell: (s: SiteRow) => void }) {
    const maxes = useMemo(() => {
        const m: Record<string, number> = {};
        HEAT_COLS.forEach(([key]) => {
            m[key as string] = Math.max(1, ...sites.map((s) => Number(s[key] ?? 0)));
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
                            <th key={key as string} className="px-2 py-1 text-center font-medium">
                                {label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {sites.map((s) => (
                        <tr key={s.id}>
                            <th scope="row" className="whitespace-nowrap px-2 py-1 text-left font-medium text-foreground">
                                {s.name}
                            </th>
                            {HEAT_COLS.map(([key, , invert]) => {
                                const raw = Number(s[key] ?? 0);
                                const norm = raw / maxes[key as string];
                                // darker = higher burden; compliance inverts (low compliance = worse).
                                // Bake intensity into the bg COLOUR (not button opacity, which would
                                // fade the value text below WCAG AA); cap the mix so dark text stays ≥4.5:1.
                                const burden = Math.min(1, Math.max(0, invert ? 1 - raw / 100 : norm));
                                const mixPct = Math.round(8 + 44 * burden);
                                return (
                                    <td key={key as string} className="p-0">
                                        {/* eslint-disable-next-line no-restricted-syntax -- heatmap intensity cell, custom shaded surface */}
                                        <button
                                            type="button"
                                            onClick={() => onCell(s)}
                                            title={`${s.name} · ${String(key).replace(/_/g, ' ')}: ${fmt(s[key] as number)}`}
                                            className="flex h-9 w-full min-w-[68px] items-center justify-center rounded-md font-semibold tabular-nums text-foreground transition-transform hover:scale-[1.04] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                            style={{ backgroundColor: `color-mix(in oklab, var(--status-critical) ${mixPct}%, var(--card))` }}
                                        >
                                            {key === 'compliance_score' ? `${raw}%` : fmt(s[key] as number)}
                                        </button>
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
            <p className="mt-2 text-[11px] text-muted-foreground">Darker = higher burden (compliance inverted). Click a cell to drill into the site.</p>
        </div>
    );
}

// ── Governance pack card ────────────────────────────────────────────────

function GovPackCard({ icon: Icon, title, desc, onOpen, actionLabel = 'Open' }: { icon: typeof FileText; title: string; desc: string; onOpen: () => void; actionLabel?: string }) {
    return (
        <Card className="flex flex-col rounded-xl shadow-sm">
            <CardContent className="flex flex-1 flex-col gap-2 p-4">
                <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-accent text-primary">
                    <Icon className="h-4 w-4" />
                </span>
                <div className="flex-1">
                    <h3 className="text-sm font-bold text-foreground">{title}</h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">{desc}</p>
                </div>
                <Button variant="outline" size="sm" className="mt-1 w-full justify-center gap-1.5" onClick={onOpen}>
                    {actionLabel === 'Open' ? <ExternalLink className="h-3.5 w-3.5" /> : <Download className="h-3.5 w-3.5" />}
                    {actionLabel}
                </Button>
            </CardContent>
        </Card>
    );
}

// ── Drill-in detail modal (Add-Client WizardShell chrome) ───────────────

const DRILL_FACETS: WizardStep[] = [
    { key: 'selection', label: 'Selection', blurb: 'What you drilled into', icon: Eye },
    { key: 'range', label: 'Range', blurb: 'Reporting window', icon: Calendar },
    { key: 'site', label: 'Site', blurb: 'Scope', icon: Building2 },
    { key: 'records', label: 'Records', blurb: 'Underlying register', icon: FileText },
    { key: 'notifiable', label: 'Notifiable', blurb: 'WorkSafe status', icon: Shield },
];

type RecordsResponse = { name: string; headers: string[]; rows: (string | number)[][]; total: number };

function DrillModal({ target, query, onClose, onExport }: { target: DrillTarget; query: string; onClose: () => void; onExport: () => void }) {
    const [data, setData] = useState<RecordsResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [section, setSection] = useState(3);

    useEffect(() => {
        let active = true;
        setLoading(true);
        fetch(`/health-safety/analytics/records?${query}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
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
                    <Button variant="outline" onClick={onExport} className="gap-1.5">
                        <Download className="h-4 w-4" /> Export CSV
                    </Button>
                    <Button onClick={() => router.visit(register)} className="gap-1.5">
                        <ArrowRight className="h-4 w-4" /> Open register
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="flex flex-wrap gap-2 text-xs">
                    <span className="rounded-md border border-border bg-muted px-2 py-1">
                        <span className="text-muted-foreground">Selection:</span> <span className="font-medium capitalize text-foreground">{target.label}</span>
                    </span>
                    {data ? (
                        <span className="rounded-md border border-border bg-muted px-2 py-1">
                            <span className="text-muted-foreground">Records:</span> <span className="font-medium text-foreground">{data.total}</span>
                        </span>
                    ) : null}
                </div>

                {loading ? (
                    <div className="flex h-40 items-center justify-center text-sm text-muted-foreground">Loading records…</div>
                ) : !data || !data.rows.length ? (
                    <div className="flex h-40 items-center justify-center rounded-lg border border-dashed border-border text-sm text-muted-foreground">No records for this selection.</div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-border">
                        <table className="w-full text-xs">
                            <thead className="bg-muted">
                                <tr className="text-left text-muted-foreground">
                                    {data.headers.map((h) => (
                                        <th key={h} className="whitespace-nowrap px-3 py-2 font-medium">
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {data.rows.map((row, i) => (
                                    <tr key={i} className="border-t border-line">
                                        {row.map((cell, j) => (
                                            <td key={j} className="whitespace-nowrap px-3 py-1.5 tabular-nums text-foreground">
                                                {cell}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {data.total > data.rows.length ? <p className="px-3 py-2 text-[11px] text-muted-foreground">Showing first {data.rows.length} of {data.total}. Export for the full set.</p> : null}
                    </div>
                )}
            </div>
        </WizardShell>
    );
}

// ── Page ────────────────────────────────────────────────────────────────

export default function HealthSafetyAnalytics(props: AnalyticsProps) {
    const { trends, scorecard, period_summary, worksafe_notifiable, hours_meta, filters, sites, incident_data, severity_data, root_cause_data, injury_data, hazard_data, site_comparison } = props;

    // Board-report routes are gated on governance.view; hide the launcher + the
    // Governance-tab report packs for register-only roles to avoid a hard 403.
    const canViewBoardReports = usePage<SharedData>().props.auth.can?.governance?.view ?? false;

    const [tab, setTab] = useState('overview');
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [drill, setDrill] = useState<DrillTarget | null>(null);
    const [showSummary, setShowSummary] = useState(true);

    const params = useMemo(
        () => ({ period: filters.period, from: filters.from, to: filters.to, site_id: filters.site_id, lens: filters.lens }),
        [filters],
    );

    const reload = (next: Record<string, string | number | null>) => {
        router.get('/health-safety/analytics', { ...params, ...next }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const setPeriod = (p: string) => (p === 'custom' ? reload({ period: 'custom' }) : reload({ period: p, from: null, to: null }));

    const queryFor = (view: string, extra: Record<string, string | number> = {}, base = 'export') => {
        const p = new URLSearchParams();
        p.set('view', view);
        p.set('period', filters.period);
        p.set('lens', filters.lens);
        if (filters.from) p.set('from', filters.from);
        if (filters.to) p.set('to', filters.to);
        if (filters.site_id) p.set('site_id', String(filters.site_id));
        Object.entries(extra).forEach(([k, v]) => p.set(k, String(v)));
        return base === 'export' ? `/health-safety/analytics/export?${p.toString()}` : p.toString();
    };

    const reportUrl = (name: string) => {
        const p = new URLSearchParams();
        if (filters.from) p.set('from', filters.from);
        if (filters.to) p.set('to', filters.to);
        return `/health-safety/reports/${name}?${p.toString()}`;
    };

    const openDrill = (target: DrillTarget) => setDrill(target);

    const openCtx = (e: React.MouseEvent, target: DrillTarget, tag: string, tone: 'success' | 'warning' | 'critical' | 'info') => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View detail', sub: 'Read-only drill-in', tone: 'primary', onClick: () => openDrill(target) },
        ];
        if (target.clientHref) items.push({ icon: <User className="h-3.5 w-3.5" />, label: 'View client', onClick: () => router.visit(target.clientHref!) });
        if (target.staffHref) items.push({ icon: <Users className="h-3.5 w-3.5" />, label: 'View staff', onClick: () => router.visit(target.staffHref!) });
        items.push({ sep: true });
        items.push({ icon: <ClipboardList className="h-3.5 w-3.5" />, label: 'Open corrective action', onClick: () => router.visit('/health-safety/corrective-actions') });
        items.push({ icon: <Download className="h-3.5 w-3.5" />, label: 'Export CSV', onClick: () => (window.location.href = queryFor(target.view, target.filters ?? {})) });
        setCtx({ x: e.clientX, y: e.clientY, tag, tagBg: `var(--status-${tone}-bg)`, tagColor: `var(--status-${tone})`, meta: target.label, items });
    };

    // ── derived chart data ──
    const severitySegments: DonutDatum[] = severity_data.map((d) => ({ key: d.severity, label: d.severity.replace(/_/g, ' '), value: d.count, color: severityFill(d.severity) }));
    const riskSegments: DonutDatum[] = hazard_data.map((d) => ({ key: d.risk_rating, label: d.risk_rating, value: d.count, color: riskFill(d.risk_rating) }));
    const incidentTypeItems: BreakdownItem[] = incident_data.map((d, i) => ({ key: d.type, label: d.type.replace(/_/g, ' '), value: d.count, color: PALETTE[i % PALETTE.length] }));
    const injuryTypeItems: BreakdownItem[] = injury_data.by_type.map((d, i) => ({ key: d.type, label: d.type.replace(/_/g, ' '), value: d.count, color: PALETTE[i % PALETTE.length] }));
    const bodyPartItems: BreakdownItem[] = injury_data.by_body_part.map((d, i) => ({ key: d.body_part, label: d.body_part.replace(/_/g, ' '), value: d.count, color: PALETTE[i % PALETTE.length] }));

    // ── drill builders ──
    const incidentTypeTarget = (it: BreakdownItem): DrillTarget => ({ view: 'incidents', label: `Incidents · ${it.label}`, filters: { type: it.key }, register: REGISTER.incidents });
    const severityTarget = (d: DonutDatum): DrillTarget => ({ view: 'incidents', label: `Incidents · ${d.label} severity`, filters: { severity: d.key }, register: REGISTER.incidents });
    const causeTarget = (cause: string): DrillTarget => ({ view: 'incidents', label: `Root cause · ${cause}`, filters: { cause }, register: REGISTER.incidents });
    const injuryTypeTarget = (it: BreakdownItem): DrillTarget => ({ view: 'injuries', label: `Injuries · ${it.label}`, filters: { type: it.key }, register: REGISTER.injuries });
    const bodyPartTarget = (it: BreakdownItem): DrillTarget => ({ view: 'injuries', label: `Injuries · ${it.label}`, filters: { body_part: it.key }, register: REGISTER.injuries });
    const riskTarget = (d: DonutDatum): DrillTarget => ({ view: 'hazards', label: `Hazards · ${d.label} risk`, filters: { risk: d.key }, register: REGISTER.hazards });
    const siteTarget = (s: SiteRow): DrillTarget => ({ view: 'incidents', label: `Site · ${s.name}`, filters: { site_id: s.id }, register: REGISTER.incidents });

    // ── hero pieces ──
    // Cluster tiles read from the scorecard split (it carries the period-over-period
    // deltas); the dashboard's hero_stats overlap so we don't read hero_stats here.
    const scoreByKey = useMemo(() => {
        const m: Record<string, ScorecardItem> = {};
        for (const it of [...scorecard.leading, ...scorecard.lagging]) m[it.key] = it;
        return m;
    }, [scorecard]);
    const heroVal = (key: string): number | null => scoreByKey[key]?.value ?? null;
    const heroDelta = (key: string): { delta?: string; deltaTone?: Tone } => {
        const it = scoreByKey[key];
        if (!it || it.delta === null || it.delta === 0) return {};
        // ▲ = improving (good), ▼ = worsening — sentiment, not numeric sign (matches the body scorecard).
        return { delta: `${deltaArrow(it.dir)} ${Math.abs(it.delta)}`, deltaTone: toneFromDir(it.dir) };
    };

    const siteScope = filters.site_id ? sites.find((s) => s.id === filters.site_id)?.name ?? 'Selected site' : `${sites.length} supported-living sites`;
    // analytics tracks completed-vs-total drills (no due-soon granularity) → non-compliant = overdue.
    const drillsOverdue = Math.max(0, period_summary.drills_total - period_summary.drills_complete);

    // Period presets — analytics keeps its longer-horizon set; Custom embeds the range popover.
    const periodItems: HeroSegItem[] = [
        { key: '30d', label: 'Last 30d' },
        { key: 'q', label: 'Quarter' },
        { key: '6m', label: '6 months' },
        { key: 'ytd', label: 'YTD' },
        {
            key: 'custom',
            label: 'Custom',
            popover: <CustomRangeFields from={filters.from} to={filters.to} onApply={(f, t) => reload({ period: 'custom', from: f, to: t })} />,
        },
    ];

    const heroFooter = (
        <>
            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <HeroSegmented variant="pill" label="Period" ariaLabel="Date range" items={periodItems} value={filters.period} onChange={setPeriod} />
                <div className="flex flex-wrap items-center gap-2">
                    <EntityFilter onDark label="Site" allLabel="All sites" items={sites} value={filters.site_id} onChange={(id) => reload({ site_id: id })} />
                    <HeroSegmented variant="segmented" label="Lens" ariaLabel="Role lens" items={LENS_SEG_ITEMS} value={filters.lens} onChange={(l) => reload({ lens: l })} />
                </div>
            </div>
            <HeroSummaryStrip label={rangeLabel(filters.period)} collapsed={!showSummary} onToggle={() => setShowSummary((v) => !v)}>
                <HeroSummaryMetric tone={period_summary.incidents > 0 ? 'warning' : 'success'}>{period_summary.incidents} incidents</HeroSummaryMetric>
                <HeroSummaryMetric tone="neutral">{period_summary.near_misses} near misses</HeroSummaryMetric>
                <HeroSummaryMetric tone={worksafe_notifiable.awaiting > 0 ? 'critical' : 'success'}>{worksafe_notifiable.awaiting} WorkSafe-notifiable</HeroSummaryMetric>
                <HeroSummaryMetric tone={period_summary.open_hazards > 0 ? 'warning' : 'success'}>{period_summary.open_hazards} open hazards</HeroSummaryMetric>
                <HeroSummaryMetric tone={(period_summary.actions_on_time_pct ?? 0) >= 90 ? 'success' : 'warning'}>{fmt(period_summary.actions_on_time_pct, '%')} actions on time</HeroSummaryMetric>
                <HeroSummaryMetric tone={period_summary.drills_complete === period_summary.drills_total ? 'success' : 'warning'}>
                    {period_summary.drills_complete}/{period_summary.drills_total} drills complete
                </HeroSummaryMetric>
            </HeroSummaryStrip>
        </>
    );

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Analytics', href: '/health-safety/analytics' }]}>
            <Head title="H&S Analytics" />

            <div className="flex flex-col gap-6 p-6">
                <HeroShell footer={heroFooter}>
                    {/* Eyebrow + export actions */}
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <HeroStatusPill>Safety analytics · {rangeLabel(filters.period)}</HeroStatusPill>
                        <div className="flex flex-wrap items-center gap-2">
                            <Link
                                href="/health-safety"
                                className="inline-flex items-center gap-1.5 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                            >
                                <LayoutDashboard className="h-4 w-4" />
                                H&amp;S command centre
                            </Link>
                            <a href={queryFor(tab === 'sites' ? 'sites' : 'incidents')} className="inline-flex">
                                <Button className="gap-1.5 bg-primary-foreground text-primary shadow-sm hover:bg-primary-foreground/90">
                                    <Download className="h-4 w-4" /> Export
                                </Button>
                            </a>
                            {canViewBoardReports ? (
                                <Popover>
                                    <PopoverTrigger asChild>
                                        {/* eslint-disable-next-line no-restricted-syntax -- translucent action pill on the dark hero; not a shadcn Button. */}
                                        <button
                                            type="button"
                                            className="inline-flex items-center gap-1.5 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                                        >
                                            <FileText className="h-4 w-4" />
                                            Board reports
                                            <ChevronDown className="h-3.5 w-3.5" />
                                        </button>
                                    </PopoverTrigger>
                                    <PopoverContent align="end" className="w-60 p-1">
                                        {GOV_REPORTS.map((r) => (
                                            // eslint-disable-next-line no-restricted-syntax -- governance report row; opens the dated report in a new tab.
                                            <button
                                                key={r.name}
                                                type="button"
                                                onClick={() => window.open(reportUrl(r.name), '_blank')}
                                                className="block w-full rounded-md px-2.5 py-2 text-left text-[13px] text-foreground transition-colors hover:bg-muted"
                                            >
                                                {r.label}
                                            </button>
                                        ))}
                                    </PopoverContent>
                                </Popover>
                            ) : null}
                        </div>
                    </div>

                    {/* Title block */}
                    <div className="flex items-start gap-4 md:gap-5">
                        <HeroMedallion icon={BarChart3} />
                        <div className="min-w-0 flex-1">
                            <h1 className="text-2xl font-bold tracking-tight md:text-[28px]">Health &amp; Safety analytics</h1>
                            <p className="mt-1 text-sm text-primary-foreground/75">
                                <span className="underline decoration-primary-foreground/40 underline-offset-4">{siteScope}</span>
                                {' · Ngā Paerewa NZS 8134:2021 · HSWA 2015 · ACC'}
                            </p>
                            <HeroComplianceBadges worksafeAwaiting={worksafe_notifiable.awaiting} sdsExpiring={0} drillsOverdue={drillsOverdue} />
                        </div>
                    </div>

                    {/* Stat clusters — Leading / Lagging with period-over-period deltas */}
                    <div className="grid gap-3 lg:grid-cols-2">
                        <HeroCluster title="Lagging · outcomes" icon={TrendingDown}>
                            <HeroClusterTile href="/incidents" label="Incidents" value={fmt(heroVal('incidents'))} caption="this period" tone={(heroVal('incidents') ?? 0) > 0 ? 'warning' : 'success'} {...heroDelta('incidents')} />
                            <HeroClusterTile href="/health-safety/injuries" label="LTIFR" value={fmt(heroVal('ltifr'))} caption="per M hrs" tone="neutral" {...heroDelta('ltifr')} />
                            <HeroClusterTile href="/health-safety/injuries" label="TRIFR" value={fmt(heroVal('trifr'))} caption="per M hrs" tone="neutral" {...heroDelta('trifr')} />
                            <HeroClusterTile href="/health-safety/injuries" label="Days LTI-free" value={fmt(heroVal('days_since_lti'))} caption="since last LTI" tone={(heroVal('days_since_lti') ?? 0) >= 30 ? 'success' : 'warning'} {...heroDelta('days_since_lti')} />
                        </HeroCluster>

                        <HeroCluster title="Leading · proactive" icon={Clock}>
                            <HeroClusterTile href="/incidents?type=near_miss" label="Near-miss" value={fmt(heroVal('near_miss_ratio'), '×')} caption=": incident" tone={(heroVal('near_miss_ratio') ?? 0) >= 3 ? 'success' : 'warning'} {...heroDelta('near_miss_ratio')} />
                            <HeroClusterTile href="/health-safety/corrective-actions" label="Actions on time" value={fmt(heroVal('actions_on_time_pct'), '%')} caption="30-day" tone={(heroVal('actions_on_time_pct') ?? 0) >= 90 ? 'success' : 'warning'} {...heroDelta('actions_on_time_pct')} />
                            <HeroClusterTile href="/health-safety/worker-participation" label="Train / audit" value={fmt(heroVal('training_pct'), '%')} caption="compliance" tone={(heroVal('training_pct') ?? 0) >= 90 ? 'success' : 'warning'} {...heroDelta('training_pct')} />
                            <HeroClusterTile href="/compliance/hazards" label="Open hazards" value={fmt(heroVal('open_hazards'))} caption="open now" tone={(heroVal('open_hazards') ?? 0) > 0 ? 'warning' : 'success'} {...heroDelta('open_hazards')} />
                        </HeroCluster>
                    </div>
                </HeroShell>

                <TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Analytics views" />

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
                    <BreakdownsTab
                        incidentTypeItems={incidentTypeItems}
                        severitySegments={severitySegments}
                        rootCause={root_cause_data}
                        injuryTypeItems={injuryTypeItems}
                        bodyPartItems={bodyPartItems}
                        riskSegments={riskSegments}
                        onDrill={openDrill}
                        onCtx={openCtx}
                        incidentTypeTarget={incidentTypeTarget}
                        severityTarget={severityTarget}
                        causeTarget={causeTarget}
                        injuryTypeTarget={injuryTypeTarget}
                        bodyPartTarget={bodyPartTarget}
                        riskTarget={riskTarget}
                    />
                ) : null}

                {tab === 'sites' ? (
                    <div className="grid gap-4">
                        <Card className="rounded-xl shadow-sm">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-bold">Site comparison league</CardTitle>
                                <p className="text-xs text-muted-foreground">Click a column to sort · click or right-click a row to drill in. Compliance score reflects NZ obligations (incidents, open hazards, emergency-drill cadence).</p>
                            </CardHeader>
                            <CardContent>
                                <SiteLeague sites={site_comparison} onRow={(s) => openDrill(siteTarget(s))} onRowCtx={(e, s) => openCtx(e, siteTarget(s), `${s.compliance_score}%`, s.compliance_score >= 90 ? 'success' : s.compliance_score >= 70 ? 'warning' : 'critical')} />
                            </CardContent>
                        </Card>
                        <Card className="rounded-xl shadow-sm">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-bold">Hotspot heatmap</CardTitle>
                                <p className="text-xs text-muted-foreground">Sites × burden metrics, intensity-shaded.</p>
                            </CardHeader>
                            <CardContent>
                                <Heatmap sites={site_comparison} onCell={(s) => openDrill(siteTarget(s))} />
                            </CardContent>
                        </Card>
                    </div>
                ) : null}

                {tab === 'governance' ? (
                    <GovernanceTab scorecard={scorecard} trends={trends} reportUrl={reportUrl} exportUrl={() => (window.location.href = queryFor('incidents'))} canViewReports={canViewBoardReports} />
                ) : null}

                {/* hours-worked honesty footnote — frequency rates need a meaningful exposure basis */}
                {hours_meta.total_hours < 1000 ? (
                    <p className="text-[11px] text-muted-foreground">
                        LTIFR / TRIFR need ≥1,000 worked hours in the trailing-12-month basis to report a meaningful rate (standard frequency-rate convention); they show “—” until then.
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
                    <span>{Math.round(hours_meta.total_hours).toLocaleString()} hours worked (12-month basis)</span>
                </div>
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}
            {drill ? <DrillModal target={drill} query={queryFor(drill.view, drill.filters ?? {}, 'records')} onClose={() => setDrill(null)} onExport={() => (window.location.href = queryFor(drill.view, drill.filters ?? {}))} /> : null}
        </AppLayout>
    );
}

// ── sub-components ──────────────────────────────────────────────────────

/** Range-picker fields rendered inside the period segmented control's Custom popover. */
function CustomRangeFields({ from, to, onApply }: { from: string; to: string; onApply: (from: string, to: string) => void }) {
    const [f, setF] = useState(from);
    const [t, setT] = useState(to);
    return (
        <>
            <div className="flex items-end gap-2">
                <label className="flex flex-col gap-1 text-xs text-muted-foreground">
                    From
                    <Input type="date" value={f} max={t || undefined} onChange={(e) => setF(e.target.value)} className="mt-1" />
                </label>
                <label className="flex flex-col gap-1 text-xs text-muted-foreground">
                    To
                    <Input type="date" value={t} min={f || undefined} onChange={(e) => setT(e.target.value)} className="mt-1" />
                </label>
            </div>
            <Button size="sm" className="w-full" onClick={() => onApply(f, t)}>
                Apply range
            </Button>
        </>
    );
}

type DrillBuilders = {
    onDrill: (t: DrillTarget) => void;
    onCtx: (e: React.MouseEvent, t: DrillTarget, tag: string, tone: 'success' | 'warning' | 'critical' | 'info') => void;
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
    const scorecardBlock = <Scorecard leading={scorecard.leading} lagging={scorecard.lagging} />;
    const headlineTrends = (
        <div className="grid gap-4 lg:grid-cols-2">
            <ChartCard title="LTIFR & TRIFR" subtitle="Lost-time & total recordable injury frequency (per 1M hrs)" aria="LTIFR and TRIFR trend over time" table={{ caption: 'LTIFR and TRIFR by month', columns: ['Month', 'LTIFR', 'TRIFR'], rows: trends.map((t) => [t.label, t.ltifr ?? '—', t.trifr ?? '—']) }}>
                <LtifrTrifrChart trends={trends} />
            </ChartCard>
            <ChartCard title="Near-miss : incident ratio" subtitle="Higher is healthier — a strong reporting culture trends up" aria="Near-miss to incident ratio over time" table={{ caption: 'Near-miss ratio by month', columns: ['Month', 'Ratio'], rows: trends.map((t) => [t.label, t.near_miss_ratio]) }}>
                <SingleAreaChart trends={trends} dataKey="near_miss_ratio" name="Ratio" color={TOKEN.success} />
            </ChartCard>
        </div>
    );
    const threeUp = (
        <div className="grid gap-4 lg:grid-cols-3">
            <ChartCard title="Incident severity" subtitle="Distribution this period" aria="Incident severity distribution" table={{ caption: 'Incidents by severity', columns: ['Severity', 'Count'], rows: severitySegments.map((s) => [s.label, s.value]) }}>
                <FocusDonut segments={severitySegments} onSegment={(d) => onDrill(severityTarget(d))} onSegmentCtx={(e, d) => onCtx(e, severityTarget(d), String(d.value), 'info')} />
            </ChartCard>
            <ChartCard title="Incidents by type" subtitle="Click a bar or row to drill in" aria="Incidents by type" table={{ caption: 'Incidents by type', columns: ['Type', 'Count'], rows: incidentTypeItems.map((s) => [s.label, s.value]) }}>
                <HorizontalBars data={incidentTypeItems} />
                <BreakdownRows items={incidentTypeItems} onItem={(d) => onDrill(incidentTypeTarget(d))} onItemCtx={(e, d) => onCtx(e, incidentTypeTarget(d), String(d.value), 'info')} />
            </ChartCard>
            <ChartCard title="Hazard burn-down" subtitle="Opened vs closed + running open" aria="Hazard burn-down over time" table={{ caption: 'Hazards opened, closed and running open by month', columns: ['Month', 'Opened', 'Closed', 'Open'], rows: trends.map((t) => [t.label, t.hazards_opened, t.hazards_closed, t.hazards_open]) }}>
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
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <ChartCard title="LTIFR & TRIFR" subtitle="Per 1,000,000 hours worked (NZ/AU convention)" aria="LTIFR and TRIFR trend" table={{ caption: 'LTIFR/TRIFR by month', columns: ['Month', 'LTIFR', 'TRIFR'], rows: trends.map((t) => [t.label, t.ltifr ?? '—', t.trifr ?? '—']) }}>
                <LtifrTrifrChart trends={trends} />
            </ChartCard>
            <ChartCard title="Near-miss : incident ratio" subtitle="Leading reporting-culture signal" aria="Near-miss ratio trend" table={{ caption: 'Near-miss ratio by month', columns: ['Month', 'Ratio'], rows: trends.map((t) => [t.label, t.near_miss_ratio]) }}>
                <SingleAreaChart trends={trends} dataKey="near_miss_ratio" name="Ratio" color={TOKEN.success} />
            </ChartCard>
            <ChartCard title="Incidents / 30 days" subtitle="Lagging volume" aria="Incidents per period" table={{ caption: 'Incidents by month', columns: ['Month', 'Incidents'], rows: trends.map((t) => [t.label, t.incidents]) }}>
                <SingleAreaChart trends={trends} dataKey="incidents" name="Incidents" color={TOKEN.warning} />
            </ChartCard>
            <ChartCard title="Hazard burn-down" subtitle="Opened (bars) vs closed (bars) + running open (line)" aria="Hazard burn-down" table={{ caption: 'Hazards by month', columns: ['Month', 'Opened', 'Closed', 'Open'], rows: trends.map((t) => [t.label, t.hazards_opened, t.hazards_closed, t.hazards_open]) }}>
                <HazardBurndownChart trends={trends} />
            </ChartCard>
            <ChartCard title="Corrective-action closure" subtitle="Avg days to close + % closed on time" aria="Corrective-action closure trend" table={{ caption: 'Corrective-action closure by month', columns: ['Month', 'Avg days', '% on time'], rows: trends.map((t) => [t.label, t.ca_avg_days ?? '—', t.ca_pct_on_time ?? '—']) }}>
                <CaClosureChart trends={trends} />
            </ChartCard>
            <ChartCard title="Training & audit compliance" subtitle="% mandatory training valid" aria="Training and audit compliance trend" table={{ caption: 'Compliance by month', columns: ['Month', 'Compliance %'], rows: trends.map((t) => [t.label, t.compliance_pct ?? '—']) }}>
                <SingleAreaChart trends={trends} dataKey="compliance_pct" name="Compliance %" color={TOKEN.c2} domain={[(min: number) => Math.min(60, Math.floor((min || 60) / 10) * 10), 100]} />
            </ChartCard>
            <ChartCard title="WorkSafe notifiable events" subtitle="Notified vs awaiting notification (HSWA s.56)" aria="WorkSafe notifiable events over time" table={{ caption: 'WorkSafe notifiable by month', columns: ['Month', 'Notified', 'Awaiting'], rows: trends.map((t) => [t.label, t.worksafe_notified, t.worksafe_awaiting]) }}>
                <WorksafeNotifiableChart trends={trends} />
            </ChartCard>
            <ChartCard title="Worker participation" subtitle="HSR/committee engagement + consultation completion" aria="Worker participation trend" table={{ caption: 'Worker participation by month', columns: ['Month', 'Engagement %', 'Consultation %'], rows: trends.map((t) => [t.label, t.worker_engagement ?? '—', t.worker_consultation ?? '—']) }}>
                <WorkerParticipationChart trends={trends} />
            </ChartCard>
        </div>
    );
}

function BreakdownsTab({
    incidentTypeItems,
    severitySegments,
    rootCause,
    injuryTypeItems,
    bodyPartItems,
    riskSegments,
    onDrill,
    onCtx,
    incidentTypeTarget,
    severityTarget,
    causeTarget,
    injuryTypeTarget,
    bodyPartTarget,
    riskTarget,
}: DrillBuilders & {
    incidentTypeItems: BreakdownItem[];
    severitySegments: DonutDatum[];
    rootCause: AnalyticsProps['root_cause_data'];
    injuryTypeItems: BreakdownItem[];
    bodyPartItems: BreakdownItem[];
    riskSegments: DonutDatum[];
    incidentTypeTarget: (i: BreakdownItem) => DrillTarget;
    severityTarget: (d: DonutDatum) => DrillTarget;
    causeTarget: (cause: string) => DrillTarget;
    injuryTypeTarget: (i: BreakdownItem) => DrillTarget;
    bodyPartTarget: (i: BreakdownItem) => DrillTarget;
    riskTarget: (d: DonutDatum) => DrillTarget;
}) {
    return (
        <div className="grid gap-4">
            <ChartCard title="Root-cause Pareto" subtitle="The vital few behind ~80% of events — the key HSWA investigation output" aria="Root cause Pareto chart with cumulative percentage" table={{ caption: 'Root cause Pareto', columns: ['Cause', 'Count', '%', 'Cumulative %'], rows: rootCause.map((r) => [r.cause, r.count, `${r.pct}%`, `${r.cumulative_pct}%`]) }}>
                <RootCausePareto data={rootCause} onBar={(r) => onDrill(causeTarget(r.cause))} onBarCtx={(e, r) => onCtx(e, causeTarget(r.cause), String(r.count), 'critical')} />
                <BreakdownRows
                    items={rootCause.map((r, i) => ({ key: r.cause, label: r.cause, value: r.count, color: i === 0 ? TOKEN.critical : i <= 2 ? TOKEN.warning : TOKEN.primary }))}
                    onItem={(d) => onDrill(causeTarget(d.key))}
                    onItemCtx={(e, d) => onCtx(e, causeTarget(d.key), String(d.value), 'critical')}
                />
            </ChartCard>

            <div className="grid gap-4 lg:grid-cols-2">
                <ChartCard title="Incidents by type" subtitle="Click to drill into the register" aria="Incidents by type" table={{ caption: 'Incidents by type', columns: ['Type', 'Count'], rows: incidentTypeItems.map((s) => [s.label, s.value]) }}>
                    <HorizontalBars data={incidentTypeItems} />
                    <BreakdownRows items={incidentTypeItems} onItem={(d) => onDrill(incidentTypeTarget(d))} onItemCtx={(e, d) => onCtx(e, incidentTypeTarget(d), String(d.value), 'info')} />
                </ChartCard>
                <ChartCard title="Incident severity" subtitle="Hover to focus · click to drill" aria="Incident severity distribution" table={{ caption: 'Incidents by severity', columns: ['Severity', 'Count'], rows: severitySegments.map((s) => [s.label, s.value]) }}>
                    <FocusDonut segments={severitySegments} onSegment={(d) => onDrill(severityTarget(d))} onSegmentCtx={(e, d) => onCtx(e, severityTarget(d), String(d.value), 'info')} />
                </ChartCard>
                <ChartCard title="Injuries by type" subtitle="ACC-reportable workplace injuries" aria="Injuries by type" table={{ caption: 'Injuries by type', columns: ['Type', 'Count'], rows: injuryTypeItems.map((s) => [s.label, s.value]) }}>
                    <HorizontalBars data={injuryTypeItems} />
                    <BreakdownRows items={injuryTypeItems} onItem={(d) => onDrill(injuryTypeTarget(d))} onItemCtx={(e, d) => onCtx(e, injuryTypeTarget(d), String(d.value), 'warning')} />
                </ChartCard>
                <ChartCard title="Injury by body part" subtitle="Where harm concentrates" aria="Injury by body part" table={{ caption: 'Injuries by body part', columns: ['Body part', 'Count'], rows: bodyPartItems.map((s) => [s.label, s.value]) }}>
                    <HorizontalBars data={bodyPartItems} />
                    <BreakdownRows items={bodyPartItems} onItem={(d) => onDrill(bodyPartTarget(d))} onItemCtx={(e, d) => onCtx(e, bodyPartTarget(d), String(d.value), 'warning')} />
                </ChartCard>
            </div>

            <ChartCard title="Open hazards by risk rating" subtitle="Hover to focus · click to drill" aria="Open hazards by risk rating" className="lg:max-w-xl" table={{ caption: 'Hazards by risk rating', columns: ['Risk', 'Count'], rows: riskSegments.map((s) => [s.label, s.value]) }}>
                <FocusDonut segments={riskSegments} onSegment={(d) => onDrill(riskTarget(d))} onSegmentCtx={(e, d) => onCtx(e, riskTarget(d), String(d.value), 'critical')} />
            </ChartCard>
        </div>
    );
}

function GovernanceTab({ scorecard, trends, reportUrl, exportUrl, canViewReports }: { scorecard: AnalyticsProps['scorecard']; trends: AnalyticsProps['trends']; reportUrl: (n: string) => string; exportUrl: () => void; canViewReports: boolean }) {
    return (
        <div className="grid gap-4">
            <Scorecard leading={scorecard.leading} lagging={scorecard.lagging} />

            <div className="grid gap-4 lg:grid-cols-2">
                <ChartCard title="Worker participation & engagement" subtitle="HSR/committee engagement + consultation completion (HSWA worker-engagement duty)" aria="Worker participation trend" table={{ caption: 'Worker participation by month', columns: ['Month', 'Engagement %', 'Consultation %'], rows: trends.map((t) => [t.label, t.worker_engagement ?? '—', t.worker_consultation ?? '—']) }}>
                    <WorkerParticipationChart trends={trends} />
                </ChartCard>
                <ChartCard title="Competency & training completion" subtitle="Mandatory training valid over time" aria="Training compliance trend" table={{ caption: 'Training compliance by month', columns: ['Month', 'Compliance %'], rows: trends.map((t) => [t.label, t.compliance_pct ?? '—']) }}>
                    <SingleAreaChart trends={trends} dataKey="compliance_pct" name="Compliance %" color={TOKEN.c2} domain={[(min: number) => Math.min(60, Math.floor((min || 60) / 10) * 10), 100]} />
                </ChartCard>
            </div>

            <div>
                <h3 className="mb-2 text-sm font-bold text-foreground">Governance packs</h3>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {/* Report packs open governance.view-gated routes; hide them for
                        register-only roles. The CSV export below is hazards.view-gated,
                        so it stays available. */}
                    {canViewReports ? (
                        <>
                            <GovPackCard icon={FileText} title="Board safety summary" desc="Leading-vs-lagging assurance for the board pack." onOpen={() => window.open(reportUrl('board-summary'), '_blank')} />
                            <GovPackCard icon={Shield} title="WorkSafe register analytics" desc="Notifiable events register (HSWA s.56)." onOpen={() => window.open(reportUrl('worksafe-register'), '_blank')} />
                            <GovPackCard icon={Activity} title="Investigation outcomes" desc="Investigation status and outcomes summary." onOpen={() => window.open(reportUrl('investigation-outcomes'), '_blank')} />
                            <GovPackCard icon={ClipboardList} title="Corrective-action traceability" desc="Action close-out evidence trail." onOpen={() => window.open(reportUrl('corrective-action-traceability'), '_blank')} />
                            <GovPackCard icon={AlertTriangle} title="Risk-assessment register" desc="Current hazard/risk assessment register." onOpen={() => window.open(reportUrl('risk-assessment-register'), '_blank')} />
                        </>
                    ) : null}
                    <GovPackCard icon={Download} title="Export current view" desc="CSV of the active analytics view." actionLabel="Download" onOpen={exportUrl} />
                </div>
            </div>
        </div>
    );
}
