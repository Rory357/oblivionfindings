/* Health & Safety dashboard tabs (WS3): the role-lens banner, the tab-items builder, and the
 * Leading / Lagging / Compliance tab panels. Each panel opens with the prototype's KPI/status
 * cards, bound to the WS1 leading_lagging payload. WS4 adds worklists + WS5 adds charts below
 * these; WS8 adds the governance export strip to Compliance. Tokens only; plain string URLs. */
import type { RosterTabItem } from '@/components/rostering';
import { Card, CardContent } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    ClipboardCheck,
    FileText,
    Flame,
    FlaskConical,
    HeartPulse,
    LayoutGrid,
    Search,
    ShieldAlert,
    ShieldCheck,
    Target,
    TrendingDown,
    TrendingUp,
    type LucideIcon,
} from 'lucide-react';

import {
    participationAccessForViewer,
    participationSourceMatches,
    participationValue,
    participationViewer,
    type ParticipationCoverage,
} from '../participation-source-state';
import type { HeroFilters, HeroLeadingLagging } from './command-centre-hero';

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
    href?: string;
    label: string;
    value: string;
    caption: string;
    accent: Accent;
}) {
    const content = (
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
    );
    return href ? (
        <Link
            href={href}
            className="frontline-tap frontline-focus group focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            {content}
        </Link>
    ) : (
        <div>{content}</div>
    );
}

type StatusTone = 'neutral' | 'warning';

const STATUS_CHIP: Record<StatusTone, string> = {
    neutral: 'bg-muted text-muted-foreground',
    warning: 'bg-status-warning-bg text-status-warning',
};

const STATUS_TOP: Record<StatusTone, string> = {
    neutral: 'border-t-border',
    warning: 'border-t-status-warning',
};

function StatusCard({
    icon: Icon,
    title,
    status,
    sub,
    tone,
    href,
    linkLabel,
}: {
    icon: LucideIcon;
    title: string;
    status: string;
    sub: string;
    tone: StatusTone;
    href?: string;
    linkLabel?: string;
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
                <StatusBadge variant={tone}>{status}</StatusBadge>
                <div className="text-caption text-muted-foreground">{sub}</div>
                {href && (
                    <Link
                        href={href}
                        className="frontline-tap frontline-focus inline-flex items-center rounded-md text-sm text-primary focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        {linkLabel}
                    </Link>
                )}
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
    filters,
}: {
    data: HeroLeadingLagging['leading'];
    workerParticipation: ParticipationCoverage;
    siteCount: number;
    filters?: HeroFilters;
}) {
    const participationAudience = participationViewer(
        usePage<SharedData>().props.auth,
    );
    const sourceAccess = participationAccessForViewer(
        workerParticipation.source_access,
        workerParticipation.viewer_id,
        participationAudience,
    );
    const participationPct = participationValue(
        workerParticipation.pct,
        workerParticipation.source_access,
        workerParticipation.viewer_id,
        participationAudience,
    );
    const participationCommittees = participationValue(
        workerParticipation.committees,
        workerParticipation.source_access,
        workerParticipation.viewer_id,
        participationAudience,
    );
    const hr = usePage<SharedData>().props.auth.can?.hr as
        | { compliance?: { view?: boolean } }
        | undefined;
    const nearMisses = new URLSearchParams({ kind: 'near_misses', tab: 'all' });
    const actions = new URLSearchParams({ tab: 'all' });
    const representatives = new URLSearchParams({ tab: 'representatives' });
    if (filters) {
        actions.set('from', filters.from);
        actions.set('to', filters.to);
        if (filters.site !== null) {
            for (const query of [nearMisses, actions, representatives])
                query.set('site_id', String(filters.site));
        }
    }
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
                href={`/incidents?${nearMisses}`}
                label="Near-miss : recordable injury"
                value={fmt(data.near_miss_ratio, '×')}
                caption={
                    data.near_miss_ratio === null
                        ? 'Not confirmed'
                        : 'readable reports · annual rate window'
                }
                accent="neutral"
            />
            <KpiCard
                href={`/health-safety/corrective-actions?${actions}`}
                label="Actions closed on time"
                value={fmt(data.actions_on_time_pct, '%')}
                caption={
                    data.actions_on_time_pct === null
                        ? 'Not confirmed'
                        : 'readable actions due in this period · target ≥ 90%'
                }
                accent={
                    data.actions_on_time_pct === null
                        ? 'neutral'
                        : data.actions_on_time_pct >= 90
                          ? 'success'
                          : 'warning'
                }
            />
            <KpiCard
                href={hr?.compliance?.view ? '/hr/compliance' : undefined}
                label="H&S training"
                value={fmt(data.training_pct, '%')}
                caption={
                    data.training_pct === null
                        ? 'Not confirmed'
                        : `linked training requirements · ${siteCount} site${siteCount === 1 ? '' : 's'}`
                }
                accent={
                    data.training_pct === null
                        ? 'neutral'
                        : data.training_pct >= 90
                          ? 'success'
                          : 'warning'
                }
            />
            <KpiCard
                href={
                    workerParticipation.source_access === 'readable' &&
                    participationSourceMatches(
                        workerParticipation.viewer_id,
                        participationAudience,
                    )
                        ? `/health-safety/worker-participation?${representatives}`
                        : undefined
                }
                label="Sites with recorded representatives"
                value={fmt(participationPct, '%')}
                caption={
                    sourceAccess === 'restricted'
                        ? 'Access restricted'
                        : participationPct === null
                          ? 'Not confirmed'
                          : participationCommittees === null
                            ? 'current and former records · committee count not confirmed'
                            : `current and former records · ${participationCommittees} committee${participationCommittees === 1 ? '' : 's'}`
                }
                accent="neutral"
            />
        </div>
    );
}

export function LaggingPanel({
    data,
    filters,
}: {
    data: HeroLeadingLagging['lagging'];
    filters?: HeroFilters;
}) {
    const incidents = new URLSearchParams({ kind: 'incidents', tab: 'all' });
    const injuries = new URLSearchParams();
    if (filters) {
        incidents.set('from', filters.from);
        incidents.set('to', filters.to);
        if (filters.site !== null) {
            incidents.set('site_id', String(filters.site));
            injuries.set('site_id', String(filters.site));
        }
    }
    // Rates use their own annual basis; the LTI interval uses all recorded history.
    // The injury register has no supported LTI-only filter, so preserve Site only.
    const injuryHref =
        '/health-safety/injuries' + (injuries.size ? `?${injuries}` : '');
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
                href={`/incidents?${incidents}`}
                label="Incidents"
                value={fmt(data.incidents)}
                caption={
                    filters
                        ? 'readable records · this period'
                        : 'readable records'
                }
                accent={data.incidents > 0 ? 'warning' : 'neutral'}
            />
            <KpiCard
                href={injuryHref}
                label="LTIFR"
                value={fmt(data.ltifr)}
                caption={
                    data.ltifr === null
                        ? 'Not confirmed'
                        : 'lost-time / million hrs'
                }
                accent={
                    data.ltifr !== null && data.ltifr > 0
                        ? 'warning'
                        : 'neutral'
                }
            />
            <KpiCard
                href={injuryHref}
                label="TRIFR"
                value={fmt(data.trifr)}
                caption={
                    data.trifr === null
                        ? 'Not confirmed'
                        : 'total recordable / million hrs'
                }
                accent="neutral"
            />
            <KpiCard
                href={injuryHref}
                label="Days since LTI"
                value={fmt(data.days_since_lti)}
                caption={
                    data.days_since_lti === null
                        ? 'Not confirmed'
                        : 'last readable lost-time injury'
                }
                accent="neutral"
            />
        </div>
    );
}

export function CompliancePanel({
    expiring,
    worksafePending,
    site,
}: {
    expiring: Array<{ type: string }>;
    worksafePending: number | null;
    site?: number | null;
}) {
    const events = new URLSearchParams({ tab: 'worksafe_pending' });
    if (site !== undefined && site !== null)
        events.set('site_id', String(site));
    const siteSuffix =
        site !== undefined && site !== null ? `?site_id=${site}` : '';
    const sdsExpiring = expiring.filter((e) => e.type === 'sds').length;
    const drillsDue = expiring.filter((e) => e.type === 'drill').length;

    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            <StatusCard
                icon={ShieldAlert}
                title="WorkSafe notifiable"
                status={
                    worksafePending === null
                        ? 'Not confirmed'
                        : `${worksafePending} readable records awaiting notification`
                }
                sub="Recorded notification work; this count does not confirm regulatory compliance."
                tone={
                    worksafePending !== null && worksafePending > 0
                        ? 'warning'
                        : 'neutral'
                }
                href={`/health-safety/events?${events}`}
                linkLabel="Open event register"
            />
            <StatusCard
                icon={ShieldCheck}
                title="Ngā Paerewa"
                status="Not confirmed"
                sub="Certification evidence is not included in this overview."
                tone="neutral"
            />
            <StatusCard
                icon={FlaskConical}
                title="Hazardous substances"
                status={
                    sdsExpiring > 0
                        ? `${sdsExpiring} SDS reviews in preview`
                        : 'No SDS reviews in preview'
                }
                sub="The preview is not a complete register or a compliance assessment."
                tone="neutral"
                href={`/health-safety/substances${siteSuffix}`}
                linkLabel="Open chemical register"
            />
            <StatusCard
                icon={Flame}
                title="Fire safety"
                status={
                    drillsDue > 0
                        ? `${drillsDue} drill${drillsDue === 1 ? '' : 's'} in preview`
                        : 'No drills in preview'
                }
                sub="Open the drill register for the current schedule and outcomes."
                tone="neutral"
                href={`/health-safety/drills${siteSuffix}`}
                linkLabel="Open drill register"
            />
            <StatusCard
                icon={HeartPulse}
                title="First-aid cover"
                status="Not confirmed"
                sub="Shift coverage and current first-aider certification are not included in this overview."
                tone="neutral"
                href={`/health-safety/first-aid${siteSuffix}`}
                linkLabel="Open First Aid register"
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
