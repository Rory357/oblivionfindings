/* Health & Safety dashboard tabs (WS3): the role-lens banner, the tab-items builder, and the
 * Leading / Lagging / Compliance tab panels. Each panel opens with the prototype's KPI/status
 * cards, bound to the WS1 leading_lagging payload. WS4 adds worklists + WS5 adds charts below
 * these; WS8 adds the governance export strip to Compliance. Tokens only; plain string URLs. */
import type { RosterTabItem } from '@/components/rostering';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    ClipboardCheck,
    FileText,
    Flame,
    FlaskConical,
    HeartPulse,
    LayoutGrid,
    type LucideIcon,
    Search,
    ShieldAlert,
    ShieldCheck,
    Target,
    TrendingDown,
    TrendingUp,
} from 'lucide-react';

import type { HeroLeadingLagging } from './command-centre-hero';

function fmt(value: number | null | undefined, suffix = ''): string {
    return value === null || value === undefined ? '—' : `${value}${suffix}`;
}

/* ------------------------------------------------------------------ */
/*  Tab items                                                          */
/* ------------------------------------------------------------------ */

export function buildHsTabItems(
    laggingCount: number,
    complianceCount: number,
): RosterTabItem[] {
    return [
        {
            id: 'overview',
            label: 'Overview',
            icon: LayoutGrid,
            tone: 'primary',
        },
        { id: 'leading', label: 'Leading', icon: TrendingUp, tone: 'success' },
        {
            id: 'lagging',
            label: 'Lagging',
            icon: TrendingDown,
            tone: 'critical',
            badge: laggingCount > 0 ? laggingCount : undefined,
        },
        {
            id: 'compliance',
            label: 'Compliance',
            icon: ShieldCheck,
            tone: 'primary',
            badge: complianceCount > 0 ? complianceCount : undefined,
        },
    ];
}

/* ------------------------------------------------------------------ */
/*  Role-lens banner (§2.2)                                            */
/* ------------------------------------------------------------------ */

const LENS_TEXT: Record<string, { title: string; body: string }> = {
    governance: {
        title: 'Governance lens',
        body: 'board-level posture prioritised: LTIFR / TRIFR trend, notifiable-event status, certification & compliance %. Operational worklists are de-emphasised.',
    },
    manager: {
        title: 'Manager lens',
        body: 'the incident & investigation pipeline and overdue corrective actions are surfaced first, then trends and registers.',
    },
    frontline: {
        title: 'Frontline lens',
        body: 'active hazard alerts, lone-worker check-ins and the quick Report launcher are prioritised for shift-level safety.',
    },
};

export function RoleLensBanner({ lens }: { lens: string }) {
    const t = LENS_TEXT[lens] ?? LENS_TEXT.manager;
    return (
        <div className="flex items-start gap-2 rounded-xl border border-dashed border-border bg-muted/40 px-3.5 py-2.5 text-xs text-muted-foreground">
            <Search className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span>
                <span className="font-semibold text-foreground">{t.title}</span>{' '}
                — {t.body}
            </span>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  KPI + status cards                                                 */
/* ------------------------------------------------------------------ */

type Accent = 'success' | 'warning' | 'critical' | 'neutral';

const BORDER_ACCENT: Record<Accent, string> = {
    success: 'border-l-status-success',
    warning: 'border-l-status-warning',
    critical: 'border-l-status-critical',
    neutral: 'border-l-border',
};

function KpiCard({
    href,
    label,
    value,
    caption,
    accent,
}: {
    href: string;
    label: string;
    value: string;
    caption: string;
    accent: Accent;
}) {
    return (
        <Link href={href} className="group">
            <Card
                className={cn(
                    'border-l-4 transition-all duration-150 group-hover:-translate-y-0.5 group-hover:shadow-md',
                    BORDER_ACCENT[accent],
                )}
            >
                <CardContent className="p-4">
                    <div className="text-xs font-medium text-muted-foreground">
                        {label}
                    </div>
                    <div className="mt-1 text-2xl font-bold text-foreground tabular-nums">
                        {value}
                    </div>
                    <div className="mt-0.5 text-[11px] text-muted-foreground">
                        {caption}
                    </div>
                </CardContent>
            </Card>
        </Link>
    );
}

type StatusTone = 'success' | 'warning';

const STATUS_CHIP: Record<StatusTone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
};

const STATUS_TOP: Record<StatusTone, string> = {
    success: 'border-t-status-success',
    warning: 'border-t-status-warning',
};

function StatusCard({
    icon: Icon,
    title,
    status,
    sub,
    tone,
}: {
    icon: LucideIcon;
    title: string;
    status: string;
    sub: string;
    tone: StatusTone;
}) {
    return (
        <Card className={cn('border-t-4', STATUS_TOP[tone])}>
            <CardContent className="flex flex-col gap-2 p-4">
                <div className="flex items-center gap-2">
                    <span
                        className={cn(
                            'flex h-8 w-8 items-center justify-center rounded-lg',
                            STATUS_CHIP[tone],
                        )}
                    >
                        <Icon className="h-4 w-4" />
                    </span>
                    <span className="text-sm font-semibold text-foreground">
                        {title}
                    </span>
                </div>
                <div className="text-sm font-medium text-foreground">
                    {status}
                </div>
                <div className="text-[11px] text-muted-foreground">{sub}</div>
            </CardContent>
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/*  Tab panels                                                         */
/* ------------------------------------------------------------------ */

export function LeadingPanel({
    data,
    workerParticipation,
    siteCount,
}: {
    data: HeroLeadingLagging['leading'];
    workerParticipation: { pct: number | null; committees: number };
    siteCount: number;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
                href="/incidents?type=near_miss"
                label="Near-miss : incident"
                value={fmt(data.near_miss_ratio, '×')}
                caption="target ≥ 3 — strong reporting culture"
                accent={
                    (data.near_miss_ratio ?? 0) >= 3 ? 'success' : 'warning'
                }
            />
            <KpiCard
                href="/health-safety/corrective-actions"
                label="Actions closed on time"
                value={fmt(data.actions_on_time_pct, '%')}
                caption="30-day · target ≥ 90%"
                accent={
                    (data.actions_on_time_pct ?? 0) >= 90
                        ? 'success'
                        : 'warning'
                }
            />
            <KpiCard
                href="/health-safety/worker-participation"
                label="Training & audit"
                value={fmt(data.training_pct, '%')}
                caption={`compliance across ${siteCount} site${siteCount === 1 ? '' : 's'}`}
                accent={(data.training_pct ?? 0) >= 90 ? 'success' : 'warning'}
            />
            <KpiCard
                href="/health-safety/worker-participation"
                label="Worker participation"
                value={fmt(workerParticipation.pct, '%')}
                caption={`HSR engagement · ${workerParticipation.committees} committee${workerParticipation.committees === 1 ? '' : 's'}`}
                accent={
                    (workerParticipation.pct ?? 0) >= 70 ? 'success' : 'warning'
                }
            />
        </div>
    );
}

export function LaggingPanel({
    data,
}: {
    data: HeroLeadingLagging['lagging'];
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
                href="/incidents"
                label="Incidents"
                value={fmt(data.incidents)}
                caption="this period"
                accent={data.incidents > 0 ? 'warning' : 'success'}
            />
            <KpiCard
                href="/health-safety/injuries"
                label="LTIFR"
                value={fmt(data.ltifr)}
                caption="lost-time / million hrs"
                accent="warning"
            />
            <KpiCard
                href="/health-safety/injuries"
                label="TRIFR"
                value={fmt(data.trifr)}
                caption="total recordable / million hrs"
                accent="neutral"
            />
            <KpiCard
                href="/health-safety/injuries"
                label="Days LTI-free"
                value={fmt(data.days_since_lti)}
                caption="since last lost-time injury"
                accent={
                    (data.days_since_lti ?? 0) >= 30 ? 'success' : 'warning'
                }
            />
        </div>
    );
}

export function CompliancePanel({
    expiring,
    worksafePending,
}: {
    expiring: Array<{ type: string }>;
    worksafePending: number;
}) {
    const sdsExpiring = expiring.filter((e) => e.type === 'sds').length;
    const drillsDue = expiring.filter((e) => e.type === 'drill').length;

    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            <StatusCard
                icon={worksafePending > 0 ? ShieldAlert : CheckCircle2}
                title="WorkSafe notifiable"
                status={`${worksafePending} awaiting notification`}
                sub="HSWA 2015 · records kept ≥ 5 years"
                tone={worksafePending > 0 ? 'warning' : 'success'}
            />
            <StatusCard
                icon={ShieldCheck}
                title="Ngā Paerewa"
                status="Certified"
                sub="NZS 8134:2021"
                tone="success"
            />
            <StatusCard
                icon={FlaskConical}
                title="Hazardous substances"
                status={
                    sdsExpiring > 0
                        ? `${sdsExpiring} SDS expiring`
                        : 'SDS current'
                }
                sub="Hazardous Substances Regs 2017"
                tone={sdsExpiring > 0 ? 'warning' : 'success'}
            />
            <StatusCard
                icon={Flame}
                title="Fire safety"
                status={
                    drillsDue > 0
                        ? `${drillsDue} drill${drillsDue === 1 ? '' : 's'} due`
                        : 'Drills current'
                }
                sub="Emergency evacuation drills"
                tone={drillsDue > 0 ? 'warning' : 'success'}
            />
            <StatusCard
                icon={HeartPulse}
                title="First-aid cover"
                status="Cover OK"
                sub="Certified first-aiders on every shift"
                tone="success"
            />
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Governance & board exports (WS8)                                   */
/* ------------------------------------------------------------------ */

const GOVERNANCE_REPORTS: Array<{
    label: string;
    desc: string;
    icon: LucideIcon;
    href: string;
}> = [
    {
        label: 'Board summary',
        desc: 'H&S posture for the board',
        icon: FileText,
        href: '/health-safety/reports/board-summary',
    },
    {
        label: 'WorkSafe register',
        desc: 'Notifiable-events register',
        icon: ShieldAlert,
        href: '/health-safety/reports/worksafe-register',
    },
    {
        label: 'Investigation outcomes',
        desc: 'Findings & lessons',
        icon: Search,
        href: '/health-safety/reports/investigation-outcomes',
    },
    {
        label: 'Corrective-action traceability',
        desc: 'Action → event trail',
        icon: ClipboardCheck,
        href: '/health-safety/reports/corrective-action-traceability',
    },
    {
        label: 'Risk-assessment register',
        desc: 'Active risk assessments',
        icon: Target,
        href: '/health-safety/reports/risk-assessment-register',
    },
];

export function GovernanceExports() {
    // Every export here links to a governance.view-gated report route; hide the
    // whole strip for register-only roles so they don't hit a 403 on click.
    const canViewBoardReports =
        usePage<SharedData>().props.auth.can?.governance?.view ?? false;
    if (!canViewBoardReports) {
        return null;
    }

    return (
        <Card>
            <CardContent className="p-4">
                <div className="mb-3">
                    <div className="text-base font-semibold text-foreground">
                        Governance &amp; board exports
                    </div>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        One-click reports for the board &amp; WorkSafe NZ
                    </p>
                </div>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {GOVERNANCE_REPORTS.map((r) => (
                        <Link
                            key={r.href}
                            href={r.href}
                            className="flex items-start gap-2.5 rounded-xl border border-border bg-card p-3 transition-all hover:border-primary/50 hover:bg-accent"
                        >
                            <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <r.icon className="h-4 w-4" />
                            </span>
                            <span className="min-w-0">
                                <span className="block text-[13px] font-semibold text-foreground">
                                    {r.label}
                                </span>
                                <span className="block text-[11px] text-muted-foreground">
                                    {r.desc}
                                </span>
                            </span>
                        </Link>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}
