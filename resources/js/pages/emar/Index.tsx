/* Merged eMAR home (route emar.index). Combines the modal-first Overview design
 * with the Dashboard clinical-watch / ops widgets. Replaces the old /emar
 * dashboard AND the retired /emar/daily page. Data comes from
 * MedicationOverviewService via EmarController::dashboard(). */
import { DonutChart, OPS_COLORS, OpsStatCard } from '@/components/ops-stat-card';
import { PageHero } from '@/components/page';
import type { PageHeroBadge } from '@/components/page/page-hero-badges';
import type { PageHeroMetaItem } from '@/components/page/page-hero-meta';
import type { PageHeroStat } from '@/components/page/page-hero-stats';
import { ClientAvatar } from '@/components/meds/board-bits';
import { EntityFilter } from '@/components/rostering/entity-filter';
import { TabStrip, type RosterTabItem } from '@/components/rostering/tab-strip';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router, usePage } from '@inertiajs/react';
import type { SharedData } from '@/types';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    Award,
    CalendarCheck,
    CalendarDays,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Clock,
    FileText,
    HeartPulse,
    LayoutGrid,
    Lock,
    MapPin,
    Package,
    Pill,
    Printer,
    RefreshCw,
    Search,
    Shield,
    Syringe,
    TrendingUp,
    Users,
    X,
    Zap,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import {
    addDays,
    DayPickerChip,
    parseYmd,
} from '@/pages/meds/today/components/day-picker-chip';
import { RecordDoseWizard } from '@/pages/meds/today/components/record-dose-wizard';
import type {
    ClientInfo,
    NotGivenReasonOption,
    ScheduleRow,
} from '@/pages/meds/today/types';
import { AddMedicationModal } from './components/add-medication-modal';
import { AuditLogModal } from './components/audit-log-modal';
import {
    CdRegisterModal,
    type MedicationOption,
    type WitnessOption,
} from './components/cd-register-modal';
import { GenerateRoundsModal } from './components/generate-rounds-modal';
import { MedicationReviewModal } from './components/medication-review-modal';
import { ReportErrorModal, type ClientOption } from './components/report-error-modal';
import { ReportsModal } from './components/reports-modal';
import { StockMovementModal } from './components/stock-movement-modal';

/* ── Types (mirror MedicationOverviewService::payload) ───────────────── */

type Severity = 'critical' | 'warning' | 'info';
type AcCategory = 'doses' | 'controlled' | 'clinical' | 'stock';

type ActionItem = {
    id: string;
    type: string;
    category: AcCategory;
    code: string;
    severity: Severity;
    client: string;
    client_id: number | null;
    title: string;
    status: string;
    summary: string;
    action: string;
    action_type: string;
    opened_at: string | null;
    record?: { row: ScheduleRow; client: ClientInfo };
};

type Stats = {
    adminRate: number;
    dueNow: number;
    overdue: number;
    missed: number;
    cdDue: number;
    controlledCount: number;
    activeDiscrepancies: number;
    reviewsDue: number;
    overdueReviews: number;
    competenciesExpiring: number;
    stockAlerts: number;
    lowStock: number;
    expiringStock: number;
    expiredStock: number;
    prnToday: number;
    activeClients: number;
    roundsToday: number;
    roundsCompleted: number;
    totalToday: number;
    givenToday: number;
    givenTrend: number[];
};

type ComplianceDay = { day: string; rate: number; given: number; total: number };
type OutcomeSegment = { key: string; label: string; count: number; tone: string };
type OutcomeBreakdown = {
    total: number;
    givenPct: number;
    segments: OutcomeSegment[];
};
type CodedReason = { code: string; label: string; count: number };
type ClientBoardItem = {
    id: number;
    name: string;
    site: string | null;
    meds: number;
    given: number;
    pending: number;
    missed: number;
    total: number;
    done: number;
    percent: number;
    status: 'attention' | 'complete' | 'in_progress';
};
type InrWatchItem = {
    id: number;
    client_id: number;
    client: string;
    value: number;
    target: string;
    tested_on: string | null;
    status: 'above' | 'below' | 'in_range' | 'no_target';
    status_label: string;
};
type SyringeDriverItem = {
    id: number;
    client_id: number;
    client: string;
    site: string | null;
    contents: string;
    commenced_at: string | null;
    next_check_due: string | null;
    overdue: boolean;
    status_label: string;
};
type ReviewDueItem = {
    id: number;
    client_id: number;
    client: string;
    cadence: string;
    scheduled_date: string | null;
    status: 'overdue' | 'today' | 'upcoming';
    status_label: string;
};
type MedicationErrors = {
    open: number;
    byType: { type: string; count: number }[];
    trend: { date: string; count: number }[];
};
type ActivityItem = {
    id: number;
    status: string;
    administered_at: string | null;
    client: { first_name: string; last_name: string } | null;
    medication: { name: string } | null;
    administered_by: { name: string } | null;
};

type Props = {
    date: string;
    isToday: boolean;
    dateTitle: string;
    nowLabel: string;
    stats: Stats;
    complianceTrend: ComplianceDay[];
    outcomeBreakdown: OutcomeBreakdown;
    codedNotGivenReasons: CodedReason[];
    actionCentre: ActionItem[];
    clientBoard: ClientBoardItem[];
    inrWatch: InrWatchItem[];
    syringeDrivers: SyringeDriverItem[];
    reviewsDue: ReviewDueItem[];
    medicationErrors: MedicationErrors;
    recentActivity: ActivityItem[];
    clientOptions: ClientOption[];
    medicationOptions: MedicationOption[];
    witnesses: WitnessOption[];
    notGivenReasons: NotGivenReasonOption[];
    signedAs: { name: string; role_label: string | null };
    canManageSettings?: boolean;
};

/* ── Token-mapped style helpers ──────────────────────────────────────── */

const SEVERITY_ACCENT: Record<Severity, string> = {
    critical: 'border-l-status-critical',
    warning: 'border-l-status-warning',
    info: 'border-l-status-info',
};

const SEVERITY_CHIP: Record<Severity, string> = {
    critical: 'bg-status-critical-bg text-status-critical',
    warning: 'bg-status-warning-bg text-status-warning',
    info: 'bg-status-info-bg text-status-info',
};

const SEVERITY_PILL: Record<Severity, string> = {
    critical: 'border-status-critical/30 bg-status-critical-bg text-status-critical',
    warning: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    info: 'border-status-info/30 bg-status-info-bg text-status-info',
};

const SEVERITY_BTN: Record<Severity, string> = {
    critical: 'bg-status-critical text-white hover:bg-status-critical/90',
    warning: 'bg-status-warning text-white hover:bg-status-warning/90',
    info: 'bg-primary text-primary-foreground hover:bg-primary/90',
};

const OUTCOME_COLOR: Record<string, string> = {
    success: OPS_COLORS.success,
    muted: OPS_COLORS.muted,
    warning: OPS_COLORS.warning,
    critical: OPS_COLORS.danger,
    slate: OPS_COLORS.neutral,
};

function localYmdToday(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/* Deep-page destinations for Action-centre row actions. The deep /emar/* pages
 * are intentionally kept; these accelerators take the clinician straight to the
 * right surface (modal wiring is layered in progressively). */
function actionHref(it: ActionItem): string {
    switch (it.action_type) {
        case 'record':
            return `/emar/mar${it.client_id ? `?client_id=${it.client_id}` : ''}`;
        case 'resolve':
        case 'cd_balance':
            return '/emar/controlled';
        case 'review':
            return it.client_id ? `/emar/clients/${it.client_id}/inr` : '/emar/reviews';
        case 'complete_review':
            return '/emar/reviews';
        case 'stock':
            return '/emar/stock';
        case 'error':
            return '/emar/errors';
        default:
            return '/emar/mar';
    }
}

const HERO_TABS: { id: string; label: string; href: string | null }[] = [
    { id: 'overview', label: 'Overview', href: null },
    { id: 'mar', label: 'MAR charts', href: '/emar/mar' },
    { id: 'cd', label: 'CD register', href: '/emar/controlled' },
    { id: 'reviews', label: 'Reviews', href: '/emar/reviews' },
    { id: 'stock', label: 'Stock', href: '/emar/stock' },
    { id: 'errors', label: 'Errors', href: '/emar/errors' },
];

export default function EmarHome(props: Props) {
    const {
        date,
        isToday,
        dateTitle,
        nowLabel,
        stats,
        complianceTrend,
        outcomeBreakdown,
        codedNotGivenReasons,
        actionCentre,
        clientBoard,
        inrWatch,
        syringeDrivers,
        reviewsDue,
        medicationErrors,
        recentActivity,
        clientOptions,
        medicationOptions,
        witnesses,
        notGivenReasons,
        signedAs,
        canManageSettings,
    } = props;

    const page = usePage<SharedData>();
    const firstName = (page.props.auth?.user?.name ?? '').split(' ')[0] || 'there';
    const currentUserId = page.props.auth?.user?.id ?? 0;

    const [acFilter, setAcFilter] = useState<'all' | AcCategory>('all');
    const [search, setSearch] = useState('');
    const [siteFilter, setSiteFilter] = useState<number | null>(null);
    const [dismissed, setDismissed] = useState<Set<string>>(new Set());
    const [modal, setModal] = useState<
        | null
        | 'generate-rounds'
        | 'report-error'
        | 'add-medication'
        | 'medication-review'
        | 'cd-register'
        | 'stock-movement'
        | 'reports'
        | 'audit-log'
    >(null);
    const [modalClientId, setModalClientId] = useState<number | null>(null);
    const [recordWizard, setRecordWizard] = useState<{
        row: ScheduleRow;
        client: ClientInfo;
    } | null>(null);

    const openModal = (key: typeof modal, clientId: number | null = null) => {
        setModalClientId(clientId);
        setModal(key);
    };

    // Witnesses for the reused RecordDoseWizard, excluding the signer.
    const recordWitnesses = witnesses.filter((w) => w.id !== currentUserId);

    const goDate = (ymd: string) =>
        router.get(
            '/emar',
            ymd === localYmdToday() ? {} : { date: ymd },
            { preserveScroll: true, preserveState: true },
        );
    const stepLabel = (ymd: string) =>
        parseYmd(ymd).toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric' });

    /* Site options derived from the client board (board carries site names). */
    const siteNames = useMemo(
        () => [...new Set(clientBoard.map((c) => c.site).filter(Boolean))] as string[],
        [clientBoard],
    );
    const siteOptions = siteNames.map((name, i) => ({ id: i, name }));

    const q = search.trim().toLowerCase();
    const inrOutOfRange = actionCentre.filter((i) => i.type === 'inr').length;

    /* Action-centre counts + filtered list. */
    const acCounts = useMemo(() => {
        const live = actionCentre.filter((i) => !dismissed.has(i.id));
        return {
            all: live.length,
            doses: live.filter((i) => i.category === 'doses').length,
            controlled: live.filter((i) => i.category === 'controlled').length,
            clinical: live.filter((i) => i.category === 'clinical').length,
            stock: live.filter((i) => i.category === 'stock').length,
        };
    }, [actionCentre, dismissed]);

    const visibleActions = actionCentre
        .filter((i) => !dismissed.has(i.id))
        .filter((i) => acFilter === 'all' || i.category === acFilter)
        .filter(
            (i) =>
                !q ||
                i.title.toLowerCase().includes(q) ||
                i.client.toLowerCase().includes(q),
        );

    const filteredBoard = clientBoard
        .filter((c) => siteFilter === null || c.site === siteNames[siteFilter])
        .filter((c) => !q || c.name.toLowerCase().includes(q));

    /* ── Hero pieces ── */
    const heroMeta: PageHeroMetaItem[] = [
        { icon: Clock, label: 'Oversight shift · 07:00–15:00' },
        {
            icon: MapPin,
            label: `${siteNames.length || 1} site${siteNames.length === 1 ? '' : 's'} · ${stats.activeClients} clients`,
        },
        { icon: Shield, label: 'Medication lead · CD witness authorised' },
    ];

    const heroBadges: PageHeroBadge[] = [
        stats.overdue > 0 && {
            tone: 'critical' as const,
            icon: AlertTriangle,
            label: `${stats.overdue} dose${stats.overdue === 1 ? '' : 's'} overdue`,
        },
        stats.activeDiscrepancies > 0 && {
            tone: 'critical' as const,
            icon: Lock,
            label: `${stats.activeDiscrepancies} CD discrepancy — investigate`,
        },
        stats.overdueReviews > 0 && {
            tone: 'warning' as const,
            icon: Clock,
            label: `${stats.overdueReviews} review${stats.overdueReviews === 1 ? '' : 's'} overdue`,
        },
        inrOutOfRange > 0 && {
            tone: 'warning' as const,
            icon: HeartPulse,
            label: `${inrOutOfRange} INR out of range`,
        },
    ].filter(Boolean) as PageHeroBadge[];

    const heroStats: PageHeroStat[] = [
        { label: 'Admin rate', value: `${stats.adminRate}%` },
        { label: 'Due now', value: stats.dueNow, tone: stats.overdue > 0 ? 'critical' : undefined },
        { label: 'CD due', value: stats.cdDue },
        { label: 'Reviews', value: stats.reviewsDue },
    ];

    const heroFooter = (
        <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-between">
            <div className="flex flex-wrap items-center gap-1.5">
                {/* eslint-disable no-restricted-syntax -- segmented day-stepper on the dark hero (rostering idiom). */}
                <button
                    type="button"
                    className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                    onClick={() => goDate(addDays(date, -1))}
                >
                    <ChevronLeft className="h-3.5 w-3.5" />
                    {stepLabel(addDays(date, -1))}
                </button>
                <DayPickerChip date={date} isToday={isToday} onPick={goDate} />
                <button
                    type="button"
                    className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                    onClick={() => goDate(addDays(date, 1))}
                >
                    {stepLabel(addDays(date, 1))}
                    <ChevronRight className="h-3.5 w-3.5" />
                </button>
                {!isToday ? (
                    <button
                        type="button"
                        className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/35 bg-primary-foreground/20 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/30"
                        onClick={() => goDate(localYmdToday())}
                    >
                        Back to today
                    </button>
                ) : null}
                {/* eslint-enable no-restricted-syntax */}
            </div>
            <div className="flex flex-wrap items-center gap-2 md:ml-auto md:justify-end">
                <div className="relative w-full max-w-xs md:w-[260px]">
                    <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    {/* eslint-disable-next-line no-restricted-syntax -- white pill search on the dark hero per design handoff. */}
                    <input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search client, medication or NHI…"
                        aria-label="Search the medication picture"
                        className="h-8 w-full rounded-full border-0 bg-primary-foreground pr-3 pl-9 text-[13px] text-foreground shadow-sm outline-none placeholder:text-muted-foreground/80 focus:ring-2 focus:ring-primary-foreground/50"
                    />
                </div>
                <EntityFilter
                    label="Site"
                    allLabel="All sites"
                    items={siteOptions}
                    value={siteFilter}
                    onChange={setSiteFilter}
                    onDark
                />
            </div>
        </div>
    );

    const acTabs: RosterTabItem[] = [
        { id: 'all', label: 'All', icon: LayoutGrid, tone: 'primary', badge: acCounts.all },
        { id: 'doses', label: 'Doses', icon: Pill, tone: 'critical', badge: acCounts.doses },
        { id: 'controlled', label: 'Controlled', icon: Lock, tone: 'primary', badge: acCounts.controlled },
        { id: 'clinical', label: 'Clinical', icon: Activity, tone: 'warning', badge: acCounts.clinical },
        { id: 'stock', label: 'Stock', icon: Package, tone: 'warning', badge: acCounts.stock },
    ];

    const donutSegments = outcomeBreakdown.segments
        .filter((s) => s.count > 0)
        .map((s) => ({
            label: s.label,
            value: s.count,
            color: OUTCOME_COLOR[s.tone] ?? OPS_COLORS.muted,
        }));

    const maxReason = Math.max(1, ...codedNotGivenReasons.map((r) => r.count));
    const maxErrTrend = Math.max(1, ...medicationErrors.trend.map((t) => t.count));

    return (
        <AppLayout>
            <Head title="eMAR" />
            <div className="flex flex-col gap-4 p-6">
                {/* ── Hero ── */}
                <PageHero
                    category="ops"
                    icon={Pill}
                    title={
                        <span>
                            <span className="mb-2 flex items-center justify-center gap-2 text-[10.5px] font-semibold tracking-wider text-primary-foreground/80 uppercase md:justify-start">
                                {isToday ? (
                                    <span aria-hidden="true" className="relative inline-flex h-2 w-2">
                                        <span className="absolute inset-0 inline-flex h-full w-full animate-ping rounded-full bg-status-success/70" />
                                        <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success ring-2 ring-status-success/30" />
                                    </span>
                                ) : (
                                    <CalendarDays className="h-3 w-3" />
                                )}
                                {isToday
                                    ? `Live medication oversight · refreshed ${nowLabel}`
                                    : 'Medication oversight · day view'}
                            </span>
                            <span className="block">
                                <span className="font-normal text-primary-foreground/80">
                                    Kia ora {firstName}, the medication picture for{' '}
                                </span>
                                <span className="border-b-2 border-primary-foreground/40 pb-0.5">
                                    {dateTitle}
                                </span>
                            </span>
                        </span>
                    }
                    description={
                        <span>
                            {stats.totalToday} dose{stats.totalToday === 1 ? '' : 's'} scheduled across{' '}
                            {siteNames.length || 1} site{siteNames.length === 1 ? '' : 's'}. {stats.dueNow} due now
                            {stats.overdue > 0 ? ` (${stats.overdue} overdue)` : ''} and {stats.adminRate}% recorded
                            so far. {stats.activeDiscrepancies} controlled-drug discrepancy and {stats.overdueReviews}{' '}
                            review{stats.overdueReviews === 1 ? '' : 's'} need a clinician.
                        </span>
                    }
                    meta={heroMeta}
                    badges={heroBadges}
                    stats={heroStats}
                    actions={
                        <>
                            <Button
                                size="sm"
                                className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                                onClick={() => setModal('generate-rounds')}
                            >
                                <Clock className="h-4 w-4" />
                                Generate today&rsquo;s rounds
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                className="border-primary-foreground/30 bg-transparent text-primary-foreground hover:bg-primary-foreground/10"
                                onClick={() => setModal('reports')}
                            >
                                <Printer className="h-4 w-4" />
                                Export MAR &amp; CD register
                            </Button>
                        </>
                    }
                    footer={heroFooter}
                />

                {/* ── Hero tab strip ── */}
                <TabStrip
                    ariaLabel="eMAR views"
                    value="overview"
                    onChange={(id) => {
                        const t = HERO_TABS.find((x) => x.id === id);
                        if (t?.href) router.visit(t.href);
                    }}
                    items={HERO_TABS.map((t) => ({
                        id: t.id,
                        label: t.label,
                        icon:
                            t.id === 'overview'
                                ? LayoutGrid
                                : t.id === 'mar'
                                  ? Pill
                                  : t.id === 'cd'
                                    ? Lock
                                    : t.id === 'reviews'
                                      ? ClipboardCheck
                                      : t.id === 'stock'
                                        ? Package
                                        : AlertTriangle,
                        tone: 'primary',
                    }))}
                />

                {/* ── Critical ribbon ── */}
                {stats.overdue > 0 ? (
                    <div className="flex items-center gap-3 rounded-2xl border border-status-critical/30 bg-status-critical-bg p-3.5">
                        <AlertTriangle className="h-5 w-5 shrink-0 text-status-critical" />
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-bold text-status-critical">
                                {stats.overdue} dose{stats.overdue === 1 ? '' : 's'} past due
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Record now if given, or escalate. A missed dose must be recorded with a
                                reason — don&rsquo;t leave a hole in the MAR.
                            </p>
                        </div>
                        <Button asChild className="bg-status-critical text-white hover:bg-status-critical/90">
                            <Link href="/emar/mar">Open MAR</Link>
                        </Button>
                    </div>
                ) : null}

                {/* ── KPI strip ── */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <OpsStatCard label="Admin rate · target 95%" value={`${stats.adminRate}%`} icon={TrendingUp} color="emerald" subtitle="recorded today" trend={stats.givenTrend} />
                    <OpsStatCard label="Doses due now" value={stats.dueNow} icon={Clock} color="red" subtitle={`${stats.overdue} overdue`} />
                    <OpsStatCard label="Controlled drugs active" value={stats.controlledCount} icon={Lock} color="indigo" subtitle={`${stats.activeDiscrepancies} discrepancy open`} />
                    <OpsStatCard label="Chart reviews due" value={stats.reviewsDue} icon={ClipboardCheck} color="amber" subtitle={`${stats.overdueReviews} overdue`} />
                    <OpsStatCard label="Competencies expiring" value={stats.competenciesExpiring} icon={Award} color="indigo" subtitle="next 30 days" />
                    <OpsStatCard label="Stock alerts" value={stats.stockAlerts} icon={Package} color="amber" subtitle={`${stats.lowStock} low · ${stats.expiringStock} expiring`} />
                </div>

                {/* ── Main grid: Action centre + right rail ── */}
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
                    {/* LEFT — Action centre */}
                    <Card className="rounded-[18px]">
                        <CardHeader className="gap-3">
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex items-center gap-2.5">
                                    <span className="grid h-9 w-9 place-items-center rounded-lg bg-primary/10 text-primary">
                                        <Zap className="h-5 w-5" />
                                    </span>
                                    <div>
                                        <CardTitle className="text-base">Action centre</CardTitle>
                                        <p className="text-xs text-muted-foreground">
                                            Everything that needs a clinician right now, most urgent first.
                                        </p>
                                    </div>
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="h-7 gap-1.5 px-2.5 text-xs"
                                        onClick={() => setModal('report-error')}
                                    >
                                        <AlertTriangle className="h-3.5 w-3.5" />
                                        Report error
                                    </Button>
                                    <span className="rounded-full border border-status-critical/30 bg-status-critical-bg px-2.5 py-0.5 text-[11px] font-bold text-status-critical">
                                        {acCounts.all} open
                                    </span>
                                </div>
                            </div>
                            <TabStrip
                                ariaLabel="Action centre filter"
                                value={acFilter}
                                onChange={(id) => setAcFilter(id as 'all' | AcCategory)}
                                items={acTabs}
                            />
                        </CardHeader>
                        <CardContent className="flex flex-col gap-0">
                            {visibleActions.length === 0 ? (
                                <div className="flex flex-col items-center gap-2 py-12 text-center">
                                    <CheckCircle2 className="h-8 w-8 text-status-success" />
                                    <p className="text-sm font-medium">All clear in this view</p>
                                    <p className="text-xs text-muted-foreground">No outstanding actions for this filter.</p>
                                </div>
                            ) : (
                                visibleActions.map((it) => (
                                    <div
                                        key={it.id}
                                        className={cn(
                                            'flex items-center gap-3 border-b border-border/60 border-l-[3px] py-3 pl-3 last:border-b-0',
                                            SEVERITY_ACCENT[it.severity],
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'grid h-9 w-9 shrink-0 place-items-center rounded-md text-[10px] font-bold tracking-wide',
                                                SEVERITY_CHIP[it.severity],
                                            )}
                                        >
                                            {it.code}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="truncate text-[13.5px] font-bold">{it.title}</span>
                                                <span
                                                    className={cn(
                                                        'shrink-0 rounded-full border px-2 py-0.5 text-[10.5px] font-semibold',
                                                        SEVERITY_PILL[it.severity],
                                                    )}
                                                >
                                                    {it.status}
                                                </span>
                                            </div>
                                            <p className="truncate text-xs text-muted-foreground">{it.summary}</p>
                                        </div>
                                        {it.action_type === 'cd_balance' ? (
                                            <Button
                                                size="sm"
                                                className={cn('shrink-0', SEVERITY_BTN[it.severity])}
                                                onClick={() => openModal('cd-register', it.client_id)}
                                            >
                                                {it.action}
                                            </Button>
                                        ) : it.action_type === 'record' && it.record ? (
                                            <Button
                                                size="sm"
                                                className={cn('shrink-0', SEVERITY_BTN[it.severity])}
                                                onClick={() => setRecordWizard(it.record ?? null)}
                                            >
                                                {it.action}
                                            </Button>
                                        ) : (
                                            <Button asChild size="sm" className={cn('shrink-0', SEVERITY_BTN[it.severity])}>
                                                <Link href={actionHref(it)}>{it.action}</Link>
                                            </Button>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="h-8 w-8 shrink-0 text-muted-foreground"
                                            aria-label="Dismiss"
                                            onClick={() =>
                                                setDismissed((prev) => new Set(prev).add(it.id))
                                            }
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))
                            )}
                            {/* eslint-disable-next-line no-restricted-syntax -- inline text trigger; a shadcn Button would change the link styling. */}
                            <button
                                type="button"
                                onClick={() => setModal('audit-log')}
                                className="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"
                            >
                                <FileText className="h-3.5 w-3.5" />
                                View audit log &amp; resolved history
                            </button>
                        </CardContent>
                    </Card>

                    {/* RIGHT rail */}
                    <div className="flex flex-col gap-4">
                        {/* Administration compliance */}
                        <Card className="rounded-[18px]">
                            <CardHeader className="flex-row items-center justify-between pb-2">
                                <CardTitle className="text-sm">Administration compliance</CardTitle>
                                <span className="text-[11px] text-muted-foreground">7 days</span>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-end gap-2">
                                    <span className="text-3xl font-bold tracking-tight">{stats.adminRate}%</span>
                                    <span className="mb-1 text-xs font-semibold text-status-success">▲ on target</span>
                                </div>
                                <div className="mt-2 h-[140px]">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <AreaChart data={complianceTrend} margin={{ top: 6, right: 6, left: -22, bottom: 0 }}>
                                            <defs>
                                                <linearGradient id="gradRate" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="5%" stopColor={OPS_COLORS.primary} stopOpacity={0.3} />
                                                    <stop offset="95%" stopColor={OPS_COLORS.primary} stopOpacity={0} />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid strokeDasharray="3 3" className="stroke-muted/30" />
                                            <XAxis dataKey="day" tick={{ fontSize: 10 }} />
                                            <YAxis domain={[0, 100]} tick={{ fontSize: 10 }} />
                                            <Tooltip contentStyle={{ borderRadius: 8, fontSize: 12 }} />
                                            <ReferenceLine y={95} stroke={OPS_COLORS.success} strokeDasharray="4 4" />
                                            <Area type="monotone" dataKey="rate" stroke={OPS_COLORS.primary} fill="url(#gradRate)" strokeWidth={2} name="Admin rate" />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </div>
                                <p className="text-[11px] text-muted-foreground">95% target line</p>
                            </CardContent>
                        </Card>

                        {/* Today's med-pass outcomes */}
                        <Card className="rounded-[18px]">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">Today&rsquo;s med-pass outcomes</CardTitle>
                            </CardHeader>
                            <CardContent className="flex items-center gap-4">
                                <DonutChart
                                    segments={donutSegments.length ? donutSegments : [{ label: 'No doses', value: 1, color: OPS_COLORS.muted }]}
                                    size={130}
                                    strokeWidth={18}
                                    centerValue={`${outcomeBreakdown.givenPct}%`}
                                    centerLabel={`${outcomeBreakdown.total} doses`}
                                />
                                <ul className="flex-1 space-y-1.5">
                                    {outcomeBreakdown.segments.map((s) => (
                                        <li key={s.key} className="flex items-center gap-2 text-xs">
                                            <span
                                                className="h-2.5 w-2.5 shrink-0 rounded-full"
                                                style={{ backgroundColor: OUTCOME_COLOR[s.tone] ?? OPS_COLORS.muted }}
                                            />
                                            <span className="flex-1 text-muted-foreground">{s.label}</span>
                                            <span className="font-semibold tabular-nums">{s.count}</span>
                                        </li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>

                        {/* Reason not given */}
                        <Card className="rounded-[18px]">
                            <CardHeader className="flex-row items-center justify-between pb-2">
                                <CardTitle className="text-sm">Reason not given</CardTitle>
                                <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold text-primary uppercase">New</span>
                            </CardHeader>
                            <CardContent>
                                <p className="mb-2 text-[11px] text-muted-foreground">Standardised codes — last 7 days</p>
                                {codedNotGivenReasons.length === 0 ? (
                                    <p className="py-4 text-center text-xs text-muted-foreground">No coded omissions recorded.</p>
                                ) : (
                                    <ul className="space-y-2">
                                        {codedNotGivenReasons.map((r) => (
                                            <li key={r.code}>
                                                <div className="mb-0.5 flex items-center justify-between text-xs">
                                                    <span className="text-foreground">{r.label}</span>
                                                    <span className="font-semibold tabular-nums">{r.count}</span>
                                                </div>
                                                <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className="h-full rounded-full bg-status-warning"
                                                        style={{ width: `${(r.count / maxReason) * 100}%` }}
                                                    />
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {/* ── Client board ── */}
                <Card className="rounded-[18px]">
                    <CardHeader className="flex-row items-center justify-between pb-3">
                        <div className="flex items-center gap-2.5">
                            <span className="grid h-9 w-9 place-items-center rounded-lg bg-primary/10 text-primary">
                                <Users className="h-5 w-5" />
                            </span>
                            <div>
                                <CardTitle className="text-base">Client board — today</CardTitle>
                                <p className="text-xs text-muted-foreground">Med-pass progress per client.</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-3">
                            <Button size="sm" onClick={() => setModal('add-medication')}>
                                <Pill className="h-4 w-4" />
                                Add medication
                            </Button>
                            <Link href="/emar/mar" className="text-xs font-medium text-primary hover:underline">
                                All clients →
                            </Link>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {filteredBoard.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">No clients match this view.</p>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {filteredBoard.map((c) => (
                                    <Link
                                        key={c.id}
                                        href={`/emar/mar?client_id=${c.id}`}
                                        className={cn(
                                            'flex flex-col gap-2 rounded-xl border p-3 transition-colors hover:bg-muted/40',
                                            c.status === 'attention'
                                                ? 'border-status-critical/30 bg-status-critical-bg/40'
                                                : c.status === 'complete'
                                                  ? 'border-status-success/30 bg-status-success-bg/40'
                                                  : 'border-border',
                                        )}
                                    >
                                        <div className="flex items-center gap-2.5">
                                            <ClientAvatar name={c.name} clientId={c.id} />
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-semibold">{c.name}</p>
                                                <p className="truncate text-[11px] text-muted-foreground">
                                                    {c.site ?? 'No site'} · {c.meds} med{c.meds === 1 ? '' : 's'}
                                                </p>
                                            </div>
                                            <span
                                                className={cn(
                                                    'shrink-0 text-xs font-bold tabular-nums',
                                                    c.total > 0 && c.done === c.total ? 'text-status-success' : 'text-foreground',
                                                )}
                                            >
                                                {c.done}/{c.total}
                                            </span>
                                        </div>
                                        <div className="flex h-1.5 overflow-hidden rounded-full bg-muted">
                                            <div className="h-full bg-status-success" style={{ width: `${c.total ? (c.given / c.total) * 100 : 0}%` }} />
                                            <div className="h-full bg-status-critical" style={{ width: `${c.total ? (c.missed / c.total) * 100 : 0}%` }} />
                                        </div>
                                        <div className="flex flex-wrap gap-1.5 text-[10.5px]">
                                            <span className="rounded-full bg-status-success-bg px-1.5 py-0.5 font-semibold text-status-success">{c.given} given</span>
                                            {c.pending > 0 ? <span className="rounded-full bg-muted px-1.5 py-0.5 font-semibold text-muted-foreground">{c.pending} pending</span> : null}
                                            {c.missed > 0 ? <span className="rounded-full bg-status-critical-bg px-1.5 py-0.5 font-semibold text-status-critical">{c.missed} missed</span> : null}
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ── Clinical watch ── */}
                <div>
                    <h2 className="mb-2 text-sm font-bold tracking-tight">Clinical watch</h2>
                    <div className="grid gap-3.5 lg:grid-cols-3">
                        {/* INR */}
                        <Card className="rounded-[18px]">
                            <CardHeader className="flex-row items-center justify-between pb-2">
                                <div className="flex items-center gap-2">
                                    <span className="grid h-8 w-8 place-items-center rounded-lg bg-status-critical-bg text-status-critical">
                                        <HeartPulse className="h-4 w-4" />
                                    </span>
                                    <CardTitle className="text-sm">Warfarin / INR watch</CardTitle>
                                </div>
                                <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold text-primary uppercase">New</span>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {inrWatch.length === 0 ? (
                                    <p className="py-3 text-center text-xs text-muted-foreground">No INR records.</p>
                                ) : (
                                    inrWatch.map((r) => (
                                        <div key={r.id} className="flex items-center justify-between gap-2 border-b border-border/50 pb-2 last:border-0">
                                            <div className="min-w-0">
                                                <p className="truncate text-[13px] font-semibold">{r.client}</p>
                                                <p className="text-[11px] text-muted-foreground">Target {r.target} · tested {r.tested_on ?? '—'}</p>
                                            </div>
                                            <div className="shrink-0 text-right">
                                                <p className="text-lg font-bold tabular-nums">{r.value}</p>
                                                <p
                                                    className={cn(
                                                        'text-[10.5px] font-semibold',
                                                        r.status === 'above' ? 'text-status-critical' : r.status === 'below' ? 'text-status-warning' : 'text-status-success',
                                                    )}
                                                >
                                                    {r.status_label}
                                                </p>
                                            </div>
                                        </div>
                                    ))
                                )}
                            </CardContent>
                        </Card>

                        {/* Syringe drivers */}
                        <Card className="rounded-[18px]">
                            <CardHeader className="flex-row items-center gap-2 pb-2">
                                <span className="grid h-8 w-8 place-items-center rounded-lg bg-primary/10 text-primary">
                                    <Syringe className="h-4 w-4" />
                                </span>
                                <CardTitle className="text-sm">Syringe drivers</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {syringeDrivers.length === 0 ? (
                                    <p className="py-3 text-center text-xs text-muted-foreground">No running drivers.</p>
                                ) : (
                                    syringeDrivers.map((d) => (
                                        <div key={d.id} className={cn('rounded-lg border-l-[3px] bg-muted/30 py-2 pl-2.5', d.overdue ? 'border-l-status-critical' : 'border-l-status-success')}>
                                            <div className="flex items-center justify-between gap-2">
                                                <p className="truncate text-[13px] font-semibold">{d.client}</p>
                                                <span className={cn('shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold', d.overdue ? 'bg-status-critical-bg text-status-critical' : 'bg-status-success-bg text-status-success')}>
                                                    {d.status_label}
                                                </span>
                                            </div>
                                            <p className="truncate text-[11px] text-muted-foreground">{d.contents}</p>
                                            <p className="text-[11px] text-muted-foreground">{d.site ?? 'No site'} · started {d.commenced_at ?? '—'}</p>
                                        </div>
                                    ))
                                )}
                            </CardContent>
                        </Card>

                        {/* Reviews due */}
                        <Card className="rounded-[18px]">
                            <CardHeader className="flex-row items-center justify-between gap-2 pb-2">
                                <div className="flex items-center gap-2">
                                    <span className="grid h-8 w-8 place-items-center rounded-lg bg-status-warning-bg text-status-warning">
                                        <CalendarCheck className="h-4 w-4" />
                                    </span>
                                    <CardTitle className="text-sm">Chart reviews due</CardTitle>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-7 px-2.5 text-xs"
                                    onClick={() => setModal('medication-review')}
                                >
                                    Schedule
                                </Button>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {reviewsDue.length === 0 ? (
                                    <p className="py-3 text-center text-xs text-muted-foreground">No reviews scheduled.</p>
                                ) : (
                                    reviewsDue.map((r) => (
                                        <div key={r.id} className="flex items-center justify-between gap-2 border-b border-border/50 pb-2 last:border-0">
                                            <div className="min-w-0">
                                                <p className="truncate text-[13px] font-semibold">{r.client}</p>
                                                <p className="text-[11px] text-muted-foreground">{r.cadence} · due {r.scheduled_date ?? '—'}</p>
                                            </div>
                                            <span
                                                className={cn(
                                                    'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold',
                                                    r.status === 'overdue' ? 'bg-status-critical-bg text-status-critical' : r.status === 'today' ? 'bg-status-warning-bg text-status-warning' : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {r.status_label}
                                            </span>
                                        </div>
                                    ))
                                )}
                                <Link href="/emar/reviews" className="inline-flex items-center gap-1 pt-1 text-xs font-medium text-primary hover:underline">
                                    Open review schedule →
                                </Link>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {/* ── Ops row ── */}
                <div className="grid gap-3.5 lg:grid-cols-3">
                    {/* Stock & pharmacy */}
                    <Card className="rounded-[18px]">
                        <CardHeader className="flex-row items-center justify-between gap-2 pb-2">
                            <div className="flex items-center gap-2">
                                <span className="grid h-8 w-8 place-items-center rounded-lg bg-status-warning-bg text-status-warning">
                                    <Package className="h-4 w-4" />
                                </span>
                                <CardTitle className="text-sm">Stock &amp; pharmacy</CardTitle>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-7 px-2.5 text-xs"
                                onClick={() => openModal('stock-movement')}
                            >
                                Record stock
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-1.5 text-xs">
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Low stock</span>
                                <span className="font-semibold">{stats.lowStock}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Expiring soon</span>
                                <span className="font-semibold text-status-warning">{stats.expiringStock}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Expired</span>
                                <span className="font-semibold text-status-critical">{stats.expiredStock}</span>
                            </div>
                            <Link href="/emar/stock" className="inline-flex items-center gap-1 pt-1 text-xs font-medium text-primary hover:underline">
                                Manage stock &amp; reorders →
                            </Link>
                        </CardContent>
                    </Card>

                    {/* Medication errors */}
                    <Card className="rounded-[18px]">
                        <CardHeader className="flex-row items-center justify-between pb-2">
                            <div className="flex items-center gap-2">
                                <span className="grid h-8 w-8 place-items-center rounded-lg bg-status-critical-bg text-status-critical">
                                    <AlertTriangle className="h-4 w-4" />
                                </span>
                                <CardTitle className="text-sm">Medication errors</CardTitle>
                            </div>
                            <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold text-primary uppercase">New</span>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-end gap-2">
                                <span className="text-3xl font-bold tracking-tight text-status-critical">{medicationErrors.open}</span>
                                <span className="mb-1 text-[11px] text-muted-foreground">open</span>
                            </div>
                            <div className="mt-2 flex h-10 items-end gap-0.5">
                                {medicationErrors.trend.map((t, i) => (
                                    <div
                                        key={i}
                                        className={cn('flex-1 rounded-sm', t.count > 0 ? 'bg-status-warning' : 'bg-muted')}
                                        style={{ height: `${Math.max(8, (t.count / maxErrTrend) * 100)}%` }}
                                        title={`${t.date}: ${t.count}`}
                                    />
                                ))}
                            </div>
                            <p className="mt-1 text-[11px] text-muted-foreground">30-day trend</p>
                            <Link href="/emar/errors" className="inline-flex items-center gap-1 pt-1 text-xs font-medium text-primary hover:underline">
                                Error register →
                            </Link>
                        </CardContent>
                    </Card>

                    {/* Recent activity */}
                    <Card className="rounded-[18px]">
                        <CardHeader className="flex-row items-center gap-2 pb-2">
                            <span className="grid h-8 w-8 place-items-center rounded-lg bg-primary/10 text-primary">
                                <Activity className="h-4 w-4" />
                            </span>
                            <CardTitle className="text-sm">Recent activity</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {recentActivity.length === 0 ? (
                                <p className="py-3 text-center text-xs text-muted-foreground">No recent administrations.</p>
                            ) : (
                                recentActivity.slice(0, 6).map((a) => (
                                    <div key={a.id} className="flex items-center gap-2 text-xs">
                                        <span
                                            className={cn(
                                                'grid h-6 w-6 shrink-0 place-items-center rounded-full text-[10px] font-bold',
                                                a.status === 'given' ? 'bg-status-success-bg text-status-success' : a.status === 'refused' ? 'bg-status-warning-bg text-status-warning' : 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            {a.status === 'given' ? '✓' : a.status === 'refused' ? '✕' : '•'}
                                        </span>
                                        <span className="min-w-0 flex-1 truncate">
                                            {a.client ? `${a.client.first_name} ${a.client.last_name}` : 'Client'} · {a.medication?.name ?? 'Medication'}
                                        </span>
                                        <span className="shrink-0 text-[10.5px] text-muted-foreground">{a.administered_by?.name ?? ''}</span>
                                    </div>
                                ))
                            )}
                            <Link href="/emar/audit" className="inline-flex items-center gap-1 pt-1 text-xs font-medium text-primary hover:underline">
                                Full audit trail →
                            </Link>
                        </CardContent>
                    </Card>
                </div>

                {/* ── Quick access ── */}
                <Card className="rounded-[18px]">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm">Quick access</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
                            {[
                                { title: 'MAR charts', href: '/emar/mar', icon: Pill },
                                { title: 'CD register', href: '/emar/controlled', icon: Lock },
                                { title: 'Reviews', href: '/emar/reviews', icon: ClipboardCheck },
                                { title: 'Reports', href: '/emar/reports', icon: Printer },
                                { title: 'Handovers', href: '/emar/handovers', icon: FileText },
                                ...(canManageSettings
                                    ? [{ title: 'Admin rules', href: '/emar/settings', icon: Shield }]
                                    : []),
                            ].map((item) => {
                                const Icon = item.icon;
                                return (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        className="group flex items-center gap-3 rounded-lg border p-3 transition-all hover:bg-muted/50 hover:shadow-sm"
                                    >
                                        <span className="grid h-8 w-8 place-items-center rounded-lg bg-primary/10 text-primary">
                                            <Icon className="h-4 w-4" />
                                        </span>
                                        <span className="text-sm font-medium">{item.title}</span>
                                        <ArrowRight className="ml-auto h-4 w-4 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                                    </Link>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* ── Modals ── */}
            <GenerateRoundsModal
                open={modal === 'generate-rounds'}
                onClose={() => setModal(null)}
                defaultDate={date}
            />
            <ReportErrorModal
                open={modal === 'report-error'}
                onClose={() => setModal(null)}
                clients={clientOptions}
            />
            <AddMedicationModal
                open={modal === 'add-medication'}
                onClose={() => setModal(null)}
                clients={clientOptions}
            />
            <MedicationReviewModal
                open={modal === 'medication-review'}
                onClose={() => setModal(null)}
                clients={clientOptions}
            />
            <CdRegisterModal
                open={modal === 'cd-register'}
                onClose={() => setModal(null)}
                clients={clientOptions}
                medications={medicationOptions}
                witnesses={witnesses}
                currentUserId={currentUserId}
                initialClientId={modalClientId}
            />
            <StockMovementModal
                open={modal === 'stock-movement'}
                onClose={() => setModal(null)}
                clients={clientOptions}
                medications={medicationOptions}
                initialClientId={modalClientId}
            />
            <ReportsModal
                open={modal === 'reports'}
                onClose={() => setModal(null)}
                clients={clientOptions}
                defaultDate={date}
            />
            <AuditLogModal
                open={modal === 'audit-log'}
                onClose={() => setModal(null)}
                activity={recentActivity}
            />
            {recordWizard ? (
                <RecordDoseWizard
                    row={recordWizard.row}
                    client={recordWizard.client}
                    date={date}
                    witnesses={recordWitnesses}
                    notGivenReasons={notGivenReasons}
                    signedAs={signedAs}
                    onClose={() => setRecordWizard(null)}
                />
            ) : null}
        </AppLayout>
    );
}
