/* eslint-disable no-restricted-syntax -- the on-dark hero action buttons are
 * custom hero-footer affordances (not shadcn Buttons), copied from the hs-hero-kit
 * segmented-pill idiom; every colour is a semantic token. */
import {
    ShiftContextMenu,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    ChartCard,
    TOKEN,
    severityFill,
} from '@/pages/health-safety/analytics-charts';
import {
    HeroCluster,
    HeroClusterTile,
    HeroComplianceBadges,
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    HeroSummaryMetric,
    HeroSummaryStrip,
    ngaPaerewaBadge,
    useNzsAssurance,
    type HeroComplianceBadge,
    type Tone,
} from '@/pages/health-safety/components/hs-hero-kit';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    BadgeCheck,
    Bell,
    BellRing,
    CalendarClock,
    CalendarDays,
    CheckCircle2,
    ClipboardCheck,
    Download,
    ExternalLink,
    FileUp,
    HeartPulse,
    ListChecks,
    MoreHorizontal,
    ShieldCheck,
    ShieldPlus,
    Siren,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { toast } from 'sonner';
import { CompleteObligationDialog } from './wizards/complete-obligation-dialog';
import { LogNotifiableDialog } from './wizards/log-notifiable-dialog';
import { LogObligationDialog } from './wizards/log-obligation-dialog';
import { RecordEvidenceDialog } from './wizards/record-evidence-dialog';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type Kpi = {
    key: string;
    label: string;
    value: number;
    caption: string;
    href: string | null;
    tone: StatusVariant;
    spark: number[];
};

type ObligationRow = {
    id: number;
    type: 'obligation';
    title: string;
    framework: string;
    reference: string | null;
    priority: string;
    due_date: string | null;
    days: number | null;
    owner: string | null;
    status: string;
    evidence_provided: boolean;
    href: string;
};

type ReviewRow = {
    id: number;
    type: 'review';
    title: string;
    framework: string;
    reference: string | null;
    priority: string;
    due_date: string | null;
    days: number | null;
    owner: string | null;
    status: string;
    evidence_provided: boolean;
    client_id: number;
    href: string;
};

type DueRow = ObligationRow | ReviewRow;

type Alert = {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
    source: string;
    triggered_at: string | null;
};

type Props = {
    period: string;
    kpis: Kpi[];
    whatsDue: { obligations: ObligationRow[]; reviews: ReviewRow[] };
    controlRoom: {
        open: number;
        critical: number;
        escalated: number;
        recentAlerts: Alert[];
        alertTrend: Array<{ date: string; total: number }>;
    };
    charts: {
        incidentBySeverity: Array<{ severity: string; total: number }>;
        marTrend: Array<{
            date: string;
            given: number;
            missed: number;
            refused: number;
            withheld: number;
        }>;
        cdTrend: Array<{ date: string; total: number }>;
    };
    can: {
        manage: boolean;
        triage: boolean;
        viewControlRoom: boolean;
        viewAudit: boolean;
        viewReports: boolean;
    };
    frameworks: Array<{ value: string; label: string }>;
    owners: Array<{ id: number; name: string }>;
    obligations: Array<{
        id: number;
        title: string;
        framework: string;
        due_date?: string | null;
    }>;
    relatedIncidents: Array<{ id: number; label: string }>;
};

type RowAction =
    | { sep: true }
    | {
          sep?: false;
          icon: LucideIcon;
          label: string;
          sub?: string;
          tone?: 'primary' | 'critical';
          onClick: () => void;
      };

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const PERIODS = [
    { key: '14d', label: '14 days' },
    { key: '30d', label: '30 days' },
    { key: '90d', label: '90 days' },
];

const SPARK_STROKE: Record<StatusVariant, string> = {
    success: TOKEN.success,
    warning: TOKEN.warning,
    critical: TOKEN.critical,
    info: TOKEN.info,
    neutral: TOKEN.primary,
};

function obligationStatusVariant(status: string): StatusVariant {
    switch (status) {
        case 'overdue':
            return 'critical';
        case 'due_soon':
            return 'warning';
        case 'complete':
            return 'success';
        case 'exempt':
            return 'neutral';
        default:
            return 'info';
    }
}

function dueLabel(row: DueRow): string {
    if (row.days == null) return '—';
    if (row.days < 0) return `${Math.abs(row.days)}d overdue`;
    if (row.days === 0) return 'Due today';
    return `Due in ${row.days}d`;
}

function dueTextClass(row: DueRow): string {
    if (row.status === 'overdue' || (row.days != null && row.days < 0))
        return 'text-status-critical';
    if (row.status === 'due_soon' || (row.days != null && row.days <= 7))
        return 'text-status-warning';
    return 'text-muted-foreground';
}

function relativeTime(iso: string | null): string {
    if (!iso) return '—';
    const diffMins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    const h = Math.floor(diffMins / 60);
    if (h < 24) return `${h}h ago`;
    return `${Math.floor(h / 24)}d ago`;
}

function alertSeverityVariant(severity: string): StatusVariant {
    switch (severity) {
        case 'critical':
            return 'critical';
        case 'high':
            return 'warning';
        case 'medium':
            return 'warning';
        case 'low':
            return 'info';
        default:
            return 'neutral';
    }
}

function toShiftItems(actions: RowAction[]): ShiftCtxItem[] {
    return actions.map((a) =>
        a.sep
            ? { sep: true }
            : {
                  icon: <a.icon className="h-4 w-4" />,
                  label: a.label,
                  sub: a.sub,
                  tone: a.tone,
                  onClick: a.onClick,
              },
    );
}

/* ------------------------------------------------------------------ */
/*  Small pieces                                                       */
/* ------------------------------------------------------------------ */

function HeroAction({
    icon: Icon,
    label,
    onClick,
}: {
    icon: LucideIcon;
    label: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="inline-flex items-center gap-1.5 rounded-lg bg-primary-foreground/15 px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
        >
            <Icon className="h-3.5 w-3.5" />
            {label}
        </button>
    );
}

function Kebab({ header, actions }: { header: string; actions: RowAction[] }) {
    const real = actions.filter((a) => !a.sep);
    if (real.length === 0) return null;
    return (
        <div onClick={(e) => e.stopPropagation()}>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label={`${header} actions`}
                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                    >
                        <MoreHorizontal className="h-[18px] w-[18px]" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    <DropdownMenuLabel className="truncate">
                        {header}
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    {actions.map((a, i) =>
                        a.sep ? (
                            <DropdownMenuSeparator key={`s${i}`} />
                        ) : (
                            <DropdownMenuItem
                                key={i}
                                onClick={a.onClick}
                                className={cn(
                                    a.tone === 'critical' &&
                                        'text-status-critical focus:text-status-critical',
                                )}
                            >
                                <a.icon className="h-4 w-4" />
                                {a.label}
                            </DropdownMenuItem>
                        ),
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

/** Tiny token-coloured sparkline (no axes). */
function Spark({ data, tone }: { data: number[]; tone: StatusVariant }) {
    if (!data || data.length === 0 || data.every((v) => v === 0)) {
        return <div className="h-9" />;
    }
    const points = data.map((v, i) => ({ i, v }));
    const stroke = SPARK_STROKE[tone];
    const gradId = `spark-${tone}`;
    return (
        <div className="h-9">
            <ResponsiveContainer width="100%" height="100%">
                <AreaChart
                    data={points}
                    margin={{ top: 2, right: 0, left: 0, bottom: 0 }}
                >
                    <defs>
                        <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
                            <stop
                                offset="0%"
                                stopColor={stroke}
                                stopOpacity={0.3}
                            />
                            <stop
                                offset="100%"
                                stopColor={stroke}
                                stopOpacity={0.02}
                            />
                        </linearGradient>
                    </defs>
                    <Area
                        type="monotone"
                        dataKey="v"
                        stroke={stroke}
                        strokeWidth={1.75}
                        fill={`url(#${gradId})`}
                        isAnimationActive={false}
                        dot={false}
                    />
                </AreaChart>
            </ResponsiveContainer>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

type WizardKind = 'obligation' | 'evidence' | 'complete' | 'notifiable' | null;

export default function ComplianceIndex({
    period,
    kpis,
    whatsDue,
    controlRoom,
    charts,
    can,
    frameworks,
    owners,
    obligations,
    relatedIncidents,
}: Props) {
    const assurance = useNzsAssurance();
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [wizard, setWizard] = useState<WizardKind>(null);
    const [prefillObligation, setPrefillObligation] = useState<number | null>(
        null,
    );
    const [dueFilter, setDueFilter] = useState<'all' | 'obligation' | 'review'>(
        'all',
    );

    const openWizard = (
        kind: Exclude<WizardKind, null>,
        obligationId: number | null = null,
    ) => {
        setPrefillObligation(obligationId);
        setWizard(kind);
    };
    const closeWizard = () => {
        setWizard(null);
        setPrefillObligation(null);
    };

    const openCtx = (
        e: React.MouseEvent,
        tag: string,
        meta: string,
        actions: RowAction[],
    ) => {
        e.preventDefault();
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag,
            meta,
            items: toShiftItems(actions),
        });
    };

    const setPeriod = (key: string) =>
        router.get(
            '/compliance',
            { period: key },
            { preserveScroll: true, preserveState: false, replace: true },
        );

    // ── derived ──
    const dueRows = useMemo<DueRow[]>(() => {
        const rows: DueRow[] = [...whatsDue.obligations, ...whatsDue.reviews];
        rows.sort((a, b) =>
            (a.due_date ?? '9999').localeCompare(b.due_date ?? '9999'),
        );
        return dueFilter === 'all'
            ? rows
            : rows.filter((r) => r.type === dueFilter);
    }, [whatsDue, dueFilter]);

    const obligationsDue = whatsDue.obligations.length;
    const reviewsOverdue = whatsDue.reviews.filter(
        (r) => r.status === 'overdue',
    ).length;
    const overdueObligations =
        kpis.find((k) => k.key === 'obligations')?.value ?? 0;
    const breakGlass = kpis.find((k) => k.key === 'break_glass')?.value ?? 0;

    const heroBadges: HeroComplianceBadge[] = [
        {
            icon: obligationsDue > 0 ? CalendarClock : CheckCircle2,
            tone:
                overdueObligations > 0
                    ? 'critical'
                    : obligationsDue > 0
                      ? 'warning'
                      : 'success',
            label:
                overdueObligations > 0
                    ? `Obligations · ${overdueObligations} overdue`
                    : `Obligations · ${obligationsDue} due`,
        },
        {
            icon: reviewsOverdue > 0 ? AlertTriangle : ClipboardCheck,
            tone: reviewsOverdue > 0 ? 'warning' : 'success',
            label:
                reviewsOverdue > 0
                    ? `Care-plan reviews · ${reviewsOverdue} overdue`
                    : 'Care-plan reviews · On track',
        },
        ngaPaerewaBadge(assurance.certification_status),
        {
            icon: breakGlass > 0 ? BellRing : CheckCircle2,
            tone: breakGlass > 0 ? 'warning' : 'success',
            label:
                breakGlass > 0
                    ? `Break-glass · ${breakGlass} this period`
                    : 'Break-glass · None',
        },
    ];

    const exceptionTiles = kpis.slice(0, 4);

    // ── control-room convenience triage (reuses the canonical endpoints) ──
    const triage = (alert: Alert, action: 'acknowledge' | 'resolve') => {
        router.post(
            `/control-room/alerts/${alert.id}/${action}`,
            { _modal: true },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    toast.success(
                        action === 'acknowledge'
                            ? 'Alert acknowledged'
                            : 'Alert resolved',
                    );
                    router.reload({ only: ['controlRoom'] });
                },
                onError: () => toast.error('Could not update the alert'),
            },
        );
    };

    const dueRowActions = (row: DueRow): RowAction[] => {
        if (row.type === 'review') {
            return [
                {
                    icon: ExternalLink,
                    label: 'Open client',
                    onClick: () => router.visit(row.href),
                },
                {
                    icon: CalendarDays,
                    label: 'Open calendar',
                    onClick: () =>
                        router.visit('/governance/compliance/calendar'),
                },
            ];
        }
        const actions: RowAction[] = [
            {
                icon: ShieldCheck,
                label: 'Open in register',
                onClick: () => router.visit(row.href),
            },
        ];
        if (can.manage) {
            actions.unshift(
                {
                    icon: BadgeCheck,
                    label: 'Complete obligation',
                    tone: 'primary',
                    onClick: () => openWizard('complete', row.id),
                },
                {
                    icon: FileUp,
                    label: 'Record evidence',
                    onClick: () => openWizard('evidence', row.id),
                },
            );
        }
        actions.push({
            icon: CalendarDays,
            label: 'Open calendar',
            onClick: () => router.visit('/governance/compliance/calendar'),
        });
        return actions;
    };

    const alertActions = (alert: Alert): RowAction[] => {
        const actions: RowAction[] = [
            {
                icon: ExternalLink,
                label: 'Open alert',
                onClick: () => router.visit(`/control-room/alerts/${alert.id}`),
            },
            {
                icon: Bell,
                label: 'Open Control Room',
                onClick: () => router.visit('/control-room'),
            },
        ];
        if (can.triage) {
            actions.unshift(
                {
                    icon: CheckCircle2,
                    label: 'Acknowledge',
                    tone: 'primary',
                    onClick: () => triage(alert, 'acknowledge'),
                },
                {
                    icon: BadgeCheck,
                    label: 'Resolve',
                    onClick: () => triage(alert, 'resolve'),
                },
            );
        }
        return actions;
    };

    const kpiActions = (k: Kpi): RowAction[] => {
        const actions: RowAction[] = [];
        if (k.href) {
            actions.push({
                icon: ExternalLink,
                label: 'Open filtered list',
                onClick: () => router.visit(k.href!),
            });
        }
        if (can.viewReports) {
            actions.push({
                icon: Download,
                label: 'Open Reports',
                onClick: () => router.visit('/reports'),
            });
        }

        return actions;
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Compliance', href: '/compliance' }]}>
            <Head title="Compliance" />

            <div className="flex flex-col gap-6 p-6">
                {/* ── Hero ── */}
                <HeroShell
                    footer={
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <HeroSegmented
                                label="Period"
                                ariaLabel="Reporting period"
                                variant="pill"
                                value={period}
                                onChange={setPeriod}
                                items={PERIODS}
                            />
                            <div className="flex flex-wrap items-center gap-2">
                                {can.manage ? (
                                    <>
                                        <HeroAction
                                            icon={ShieldPlus}
                                            label="Log obligation"
                                            onClick={() =>
                                                openWizard('obligation')
                                            }
                                        />
                                        <HeroAction
                                            icon={FileUp}
                                            label="Record evidence"
                                            onClick={() =>
                                                openWizard('evidence')
                                            }
                                        />
                                        <HeroAction
                                            icon={Siren}
                                            label="Log notifiable"
                                            onClick={() =>
                                                openWizard('notifiable')
                                            }
                                        />
                                    </>
                                ) : null}
                                {can.viewReports ? (
                                    <HeroAction
                                        icon={Download}
                                        label="Export"
                                        onClick={() => router.visit('/reports')}
                                    />
                                ) : null}
                            </div>
                        </div>
                    }
                >
                    <div className="flex items-start gap-4">
                        <HeroMedallion icon={ShieldCheck} />
                        <div className="min-w-0 flex-1">
                            <HeroStatusPill>
                                Compliance command centre · synced just now
                            </HeroStatusPill>
                            <h1 className="mt-2 text-2xl font-bold tracking-tight md:text-[28px]">
                                Compliance
                            </h1>
                            <p className="mt-1 max-w-2xl text-sm text-primary-foreground/80">
                                Application-wide obligations plus operational
                                assurance across your accessible Sites — Ngā
                                Paerewa, HSWA 2015 and the Privacy Act 2020.
                            </p>
                            <HeroComplianceBadges items={heroBadges} />
                        </div>
                    </div>

                    <div className="grid gap-3 lg:grid-cols-2">
                        <HeroCluster title="Exceptions · live" icon={Activity}>
                            {exceptionTiles.map((k) => (
                                <HeroClusterTile
                                    key={k.key}
                                    href={k.href ?? undefined}
                                    label={k.label}
                                    value={String(k.value)}
                                    caption={k.caption}
                                    tone={
                                        (k.tone === 'info'
                                            ? 'neutral'
                                            : k.tone) as Tone
                                    }
                                />
                            ))}
                        </HeroCluster>
                        <HeroCluster
                            title="Assurance · what's due"
                            icon={ListChecks}
                        >
                            <HeroClusterTile
                                label="Obligations due"
                                value={String(obligationsDue)}
                                caption="Next 30 days"
                                tone={
                                    overdueObligations > 0
                                        ? 'critical'
                                        : obligationsDue > 0
                                          ? 'warning'
                                          : 'success'
                                }
                            />
                            <HeroClusterTile
                                label="Overdue"
                                value={String(overdueObligations)}
                                caption="Past due date"
                                tone={
                                    overdueObligations > 0
                                        ? 'critical'
                                        : 'success'
                                }
                                href="/governance/compliance?status=overdue"
                            />
                            <HeroClusterTile
                                label="Reviews due"
                                value={String(whatsDue.reviews.length)}
                                caption="Care & support plans"
                                tone={
                                    reviewsOverdue > 0 ? 'warning' : 'success'
                                }
                            />
                            <HeroClusterTile
                                label="Alerts open"
                                value={String(controlRoom.open)}
                                caption="Control Room"
                                tone={
                                    controlRoom.critical > 0
                                        ? 'critical'
                                        : controlRoom.open > 0
                                          ? 'warning'
                                          : 'success'
                                }
                                href={
                                    can.viewControlRoom
                                        ? '/control-room'
                                        : undefined
                                }
                            />
                        </HeroCluster>
                    </div>

                    <HeroSummaryStrip label="At a glance">
                        <HeroSummaryMetric
                            tone={
                                overdueObligations > 0 ? 'critical' : 'success'
                            }
                        >
                            {overdueObligations} overdue obligations
                        </HeroSummaryMetric>
                        <HeroSummaryMetric
                            tone={obligationsDue > 0 ? 'warning' : 'success'}
                        >
                            {obligationsDue} due in 30 days
                        </HeroSummaryMetric>
                        <HeroSummaryMetric
                            tone={
                                controlRoom.critical > 0
                                    ? 'critical'
                                    : 'neutral'
                            }
                        >
                            {controlRoom.critical} critical alerts
                        </HeroSummaryMetric>
                    </HeroSummaryStrip>
                </HeroShell>

                {/* ── Exception KPIs ── */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {kpis.map((k) => (
                        <Card
                            key={k.key}
                            onClick={
                                k.href ? () => router.visit(k.href!) : undefined
                            }
                            onContextMenu={
                                kpiActions(k).length > 0
                                    ? (e) =>
                                          openCtx(
                                              e,
                                              'KPI',
                                              k.label,
                                              kpiActions(k),
                                          )
                                    : undefined
                            }
                            className={cn(
                                'transition-shadow',
                                k.href && 'cursor-pointer hover:shadow-md',
                            )}
                        >
                            <CardContent className="p-4">
                                <div className="flex items-start justify-between gap-1">
                                    <span className="text-xs font-medium text-muted-foreground">
                                        {k.label}
                                    </span>
                                    <Kebab
                                        header={k.label}
                                        actions={kpiActions(k)}
                                    />
                                </div>
                                <div className="mt-1 text-2xl font-bold tabular-nums">
                                    {k.value}
                                </div>
                                <Spark data={k.spark} tone={k.tone} />
                                <div className="mt-1 flex items-center justify-between gap-2">
                                    <span className="truncate text-[11px] text-muted-foreground">
                                        {k.caption}
                                    </span>
                                    <StatusBadge variant={k.tone} size="sm">
                                        {k.tone === 'success'
                                            ? 'Clear'
                                            : k.tone === 'critical'
                                              ? 'Action'
                                              : k.tone === 'warning'
                                                ? 'Watch'
                                                : 'Info'}
                                    </StatusBadge>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* ── What's due / assurance rail ── */}
                <Card>
                    <CardContent className="p-0">
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
                            <div className="flex items-center gap-2">
                                <ListChecks className="h-5 w-5 text-primary" />
                                <h2 className="text-base font-semibold">
                                    What's due
                                </h2>
                                <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground tabular-nums">
                                    {whatsDue.obligations.length +
                                        whatsDue.reviews.length}
                                </span>
                            </div>
                            <div className="flex items-center gap-1 rounded-lg bg-muted p-1">
                                {(['all', 'obligation', 'review'] as const).map(
                                    (f) => (
                                        <button
                                            key={f}
                                            type="button"
                                            onClick={() => setDueFilter(f)}
                                            aria-pressed={dueFilter === f}
                                            className={cn(
                                                'rounded-md px-2.5 py-1 text-xs font-semibold capitalize transition-colors',
                                                dueFilter === f
                                                    ? 'bg-card text-foreground shadow-sm'
                                                    : 'text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {f === 'all'
                                                ? 'All'
                                                : f === 'obligation'
                                                  ? 'Obligations'
                                                  : 'Reviews'}
                                        </button>
                                    ),
                                )}
                            </div>
                        </div>

                        {dueRows.length === 0 ? (
                            <EmptyState
                                variant="compact"
                                icon={CheckCircle2}
                                title="Nothing due"
                                description="No obligations or care-plan reviews are due in the next 30 days."
                                className="m-4"
                            />
                        ) : (
                            <ul className="divide-y divide-border">
                                {dueRows.map((row) => {
                                    const Icon =
                                        row.type === 'review'
                                            ? HeartPulse
                                            : ShieldCheck;
                                    const actions = dueRowActions(row);
                                    const meta = `${row.framework}${row.reference ? ` · ${row.reference}` : ''}`;
                                    return (
                                        <li
                                            key={`${row.type}-${row.id}`}
                                            onClick={() =>
                                                router.visit(row.href)
                                            }
                                            onContextMenu={(e) =>
                                                openCtx(
                                                    e,
                                                    row.type === 'review'
                                                        ? 'Review'
                                                        : 'Obligation',
                                                    row.title,
                                                    actions,
                                                )
                                            }
                                            className="flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/50"
                                        >
                                            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                                <Icon className="h-4 w-4" />
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-sm font-semibold">
                                                    {row.title}
                                                </div>
                                                <div className="truncate text-xs text-muted-foreground">
                                                    {meta}
                                                </div>
                                            </div>
                                            <span
                                                className={cn(
                                                    'hidden shrink-0 text-xs font-semibold sm:block',
                                                    dueTextClass(row),
                                                )}
                                            >
                                                {dueLabel(row)}
                                            </span>
                                            <StatusBadge
                                                variant={obligationStatusVariant(
                                                    row.status,
                                                )}
                                                size="sm"
                                            >
                                                {row.status === 'overdue'
                                                    ? 'Overdue'
                                                    : row.status === 'due_soon'
                                                      ? 'Due soon'
                                                      : row.status ===
                                                          'complete'
                                                        ? 'Complete'
                                                        : row.status ===
                                                            'not_due'
                                                          ? 'On track'
                                                          : row.status.replace(
                                                                /_/g,
                                                                ' ',
                                                            )}
                                            </StatusBadge>
                                            <Kebab
                                                header={row.title}
                                                actions={actions}
                                            />
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                {/* ── Control Room ── */}
                <Card>
                    <CardContent className="p-4">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <div className="flex items-center gap-2">
                                <Bell className="h-5 w-5 text-status-critical" />
                                <h2 className="text-base font-semibold">
                                    Control Room alerts
                                </h2>
                                <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                    owned by Control Room · convenience triage
                                    only
                                </span>
                            </div>
                            {can.viewControlRoom ? (
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.visit('/control-room')
                                    }
                                    className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                                >
                                    View all{' '}
                                    <ExternalLink className="h-3.5 w-3.5" />
                                </button>
                            ) : null}
                        </div>

                        <div className="mb-4 grid grid-cols-3 gap-3">
                            <div className="rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-center">
                                <div className="text-2xl font-bold text-status-critical tabular-nums">
                                    {controlRoom.open}
                                </div>
                                <div className="text-[11px] font-medium text-muted-foreground">
                                    Open
                                </div>
                            </div>
                            <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-center">
                                <div className="text-2xl font-bold text-status-warning tabular-nums">
                                    {controlRoom.critical}
                                </div>
                                <div className="text-[11px] font-medium text-muted-foreground">
                                    Critical
                                </div>
                            </div>
                            <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-center">
                                <div className="text-2xl font-bold text-status-warning tabular-nums">
                                    {controlRoom.escalated}
                                </div>
                                <div className="text-[11px] font-medium text-muted-foreground">
                                    Escalated
                                </div>
                            </div>
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <div>
                                <h3 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    Recent alerts
                                </h3>
                                {!can.viewControlRoom ? (
                                    <EmptyState
                                        variant="compact"
                                        icon={ShieldCheck}
                                        title="Alert details restricted"
                                        description="Summary counts remain available here. Open-alert details require Control Room access."
                                    />
                                ) : controlRoom.recentAlerts.length === 0 ? (
                                    <EmptyState
                                        variant="compact"
                                        icon={CheckCircle2}
                                        title="No open alerts"
                                        description="Control Room is all clear."
                                    />
                                ) : (
                                    <ul className="space-y-1.5">
                                        {controlRoom.recentAlerts.map(
                                            (alert) => {
                                                const actions =
                                                    alertActions(alert);
                                                return (
                                                    <li
                                                        key={alert.id}
                                                        onClick={() =>
                                                            router.visit(
                                                                `/control-room/alerts/${alert.id}`,
                                                            )
                                                        }
                                                        onContextMenu={(e) =>
                                                            openCtx(
                                                                e,
                                                                'Alert',
                                                                alert.alert_type,
                                                                actions,
                                                            )
                                                        }
                                                        className="flex cursor-pointer items-center gap-2 rounded-lg border border-border p-2 transition-colors hover:bg-muted/50"
                                                    >
                                                        <div className="min-w-0 flex-1">
                                                            <div className="truncate text-sm font-medium">
                                                                {
                                                                    alert.alert_type
                                                                }
                                                            </div>
                                                            <div className="truncate text-[11px] text-muted-foreground">
                                                                {alert.source} ·{' '}
                                                                {relativeTime(
                                                                    alert.triggered_at,
                                                                )}
                                                            </div>
                                                        </div>
                                                        <StatusBadge
                                                            variant={alertSeverityVariant(
                                                                alert.severity,
                                                            )}
                                                            size="sm"
                                                        >
                                                            {alert.severity}
                                                        </StatusBadge>
                                                        <Kebab
                                                            header={
                                                                alert.alert_type
                                                            }
                                                            actions={actions}
                                                        />
                                                    </li>
                                                );
                                            },
                                        )}
                                    </ul>
                                )}
                            </div>
                            <div>
                                <h3 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    Alert trend · 14 days
                                </h3>
                                <div className="h-[150px]">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <AreaChart
                                            data={controlRoom.alertTrend}
                                            margin={{
                                                top: 6,
                                                right: 6,
                                                left: -18,
                                                bottom: 0,
                                            }}
                                        >
                                            <defs>
                                                <linearGradient
                                                    id="cr-trend"
                                                    x1="0"
                                                    y1="0"
                                                    x2="0"
                                                    y2="1"
                                                >
                                                    <stop
                                                        offset="0%"
                                                        stopColor={
                                                            TOKEN.critical
                                                        }
                                                        stopOpacity={0.28}
                                                    />
                                                    <stop
                                                        offset="100%"
                                                        stopColor={
                                                            TOKEN.critical
                                                        }
                                                        stopOpacity={0.02}
                                                    />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                stroke={TOKEN.grid}
                                                vertical={false}
                                            />
                                            <XAxis dataKey="date" hide />
                                            <YAxis
                                                allowDecimals={false}
                                                width={28}
                                                tick={{
                                                    fontSize: 11,
                                                    fill: TOKEN.axis,
                                                }}
                                                axisLine={false}
                                                tickLine={false}
                                            />
                                            <Tooltip />
                                            <Area
                                                type="monotone"
                                                dataKey="total"
                                                stroke={TOKEN.critical}
                                                strokeWidth={2}
                                                fill="url(#cr-trend)"
                                                isAnimationActive={false}
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* ── Trends ── */}
                <div className="grid gap-3 lg:grid-cols-3">
                    <ChartCard
                        title="Incidents by severity"
                        subtitle={`Last ${period}`}
                        aria="Incidents by severity"
                        className="lg:col-span-1"
                    >
                        <div className="h-[240px]">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart
                                    data={charts.incidentBySeverity}
                                    margin={{
                                        top: 8,
                                        right: 8,
                                        left: -18,
                                        bottom: 0,
                                    }}
                                >
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        stroke={TOKEN.grid}
                                        vertical={false}
                                    />
                                    <XAxis
                                        dataKey="severity"
                                        tick={{
                                            fontSize: 11,
                                            fill: TOKEN.axis,
                                        }}
                                        axisLine={false}
                                        tickLine={false}
                                    />
                                    <YAxis
                                        allowDecimals={false}
                                        width={28}
                                        tick={{
                                            fontSize: 11,
                                            fill: TOKEN.axis,
                                        }}
                                        axisLine={false}
                                        tickLine={false}
                                    />
                                    <Tooltip
                                        cursor={{
                                            fill: 'var(--accent)',
                                            fillOpacity: 0.5,
                                        }}
                                    />
                                    <Bar
                                        dataKey="total"
                                        name="Incidents"
                                        radius={[4, 4, 0, 0]}
                                        maxBarSize={48}
                                        isAnimationActive={false}
                                    >
                                        {charts.incidentBySeverity.map((d) => (
                                            <Cell
                                                key={d.severity}
                                                fill={severityFill(d.severity)}
                                            />
                                        ))}
                                    </Bar>
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </ChartCard>

                    <ChartCard
                        title="MAR outcomes"
                        subtitle="Last 14 days"
                        aria="Medication administration outcomes trend"
                        className="lg:col-span-2"
                    >
                        <div className="h-[240px]">
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart
                                    data={charts.marTrend}
                                    margin={{
                                        top: 8,
                                        right: 8,
                                        left: -18,
                                        bottom: 0,
                                    }}
                                >
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        stroke={TOKEN.grid}
                                        vertical={false}
                                    />
                                    <XAxis dataKey="date" hide />
                                    <YAxis
                                        allowDecimals={false}
                                        width={28}
                                        tick={{
                                            fontSize: 11,
                                            fill: TOKEN.axis,
                                        }}
                                        axisLine={false}
                                        tickLine={false}
                                    />
                                    <Tooltip />
                                    <Line
                                        type="monotone"
                                        dataKey="given"
                                        name="Given"
                                        stroke={TOKEN.success}
                                        dot={false}
                                        strokeWidth={2}
                                        isAnimationActive={false}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="missed"
                                        name="Missed"
                                        stroke={TOKEN.critical}
                                        dot={false}
                                        strokeWidth={2}
                                        isAnimationActive={false}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="refused"
                                        name="Refused"
                                        stroke={TOKEN.warning}
                                        dot={false}
                                        strokeWidth={2}
                                        isAnimationActive={false}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="withheld"
                                        name="Withheld"
                                        stroke={TOKEN.info}
                                        dot={false}
                                        strokeWidth={2}
                                        isAnimationActive={false}
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    </ChartCard>
                </div>

                <ChartCard
                    title="Controlled-drug discrepancies"
                    subtitle={`Last ${period}`}
                    aria="Controlled drug discrepancies trend"
                >
                    <div className="h-[220px]">
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart
                                data={charts.cdTrend}
                                margin={{
                                    top: 8,
                                    right: 8,
                                    left: -18,
                                    bottom: 0,
                                }}
                            >
                                <defs>
                                    <linearGradient
                                        id="cd-trend"
                                        x1="0"
                                        y1="0"
                                        x2="0"
                                        y2="1"
                                    >
                                        <stop
                                            offset="0%"
                                            stopColor={TOKEN.critical}
                                            stopOpacity={0.24}
                                        />
                                        <stop
                                            offset="100%"
                                            stopColor={TOKEN.critical}
                                            stopOpacity={0.02}
                                        />
                                    </linearGradient>
                                </defs>
                                <CartesianGrid
                                    strokeDasharray="3 3"
                                    stroke={TOKEN.grid}
                                    vertical={false}
                                />
                                <XAxis dataKey="date" hide />
                                <YAxis
                                    allowDecimals={false}
                                    width={28}
                                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                                    axisLine={false}
                                    tickLine={false}
                                />
                                <Tooltip />
                                <Area
                                    type="monotone"
                                    dataKey="total"
                                    name="Discrepancies"
                                    stroke={TOKEN.critical}
                                    strokeWidth={2}
                                    fill="url(#cd-trend)"
                                    isAnimationActive={false}
                                />
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                </ChartCard>
            </div>

            {/* ── Context menu ── */}
            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}

            {/* ── Wizards ── */}
            {can.manage ? (
                <>
                    <LogObligationDialog
                        open={wizard === 'obligation'}
                        onClose={closeWizard}
                        frameworks={frameworks}
                        owners={owners}
                    />
                    <RecordEvidenceDialog
                        open={wizard === 'evidence'}
                        onClose={closeWizard}
                        obligations={obligations}
                        initialObligationId={prefillObligation}
                    />
                    <CompleteObligationDialog
                        open={wizard === 'complete'}
                        onClose={closeWizard}
                        obligations={obligations}
                        initialObligationId={prefillObligation}
                        onRecordEvidence={(id) => openWizard('evidence', id)}
                    />
                    <LogNotifiableDialog
                        open={wizard === 'notifiable'}
                        onClose={closeWizard}
                        relatedIncidents={relatedIncidents}
                    />
                </>
            ) : null}
        </AppLayout>
    );
}
