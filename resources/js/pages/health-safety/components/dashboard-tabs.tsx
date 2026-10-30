/* Health & safety overview tabs, drawn as the approved H&S drawings (23 Oct 2026): the tab items, the role-lens
 * banner (analytics), and the Prevention / Harm & injuries / Duties panels on the signature blocks. Every number
 * still opens where it lives, and every "not confirmed" or "restricted" state stays distinct from a recorded zero.
 * Tokens only; plain string URLs. */
import type { RosterTabItem } from '@/components/rostering';
import { RingCard, RowList, SectionHead, Tally, type TallyItem } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { BOARD_REPORTS_LABEL, boardReportLinks, type BoardReportKey } from '@/lib/health-safety/board-reports';
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

/** The overview's four tabs in plain words (ids unchanged so links keep working). */
export function buildHsTabItems(
    laggingCount: number,
    complianceCount: number,
): RosterTabItem[] {
    return [
        { id: 'overview', label: 'Overview', icon: LayoutGrid, tone: 'primary' },
        { id: 'leading', label: 'Prevention', icon: TrendingUp, tone: 'success' },
        {
            id: 'lagging',
            label: 'Harm & injuries',
            icon: TrendingDown,
            tone: 'critical',
            badge: laggingCount > 0 ? laggingCount : undefined,
        },
        {
            id: 'compliance',
            label: 'Duties',
            icon: ShieldCheck,
            tone: 'primary',
            badge: complianceCount > 0 ? complianceCount : undefined,
        },
    ];
}

/* ------------------------------------------------------------------ */
/*  Role-lens banner (analytics)                                       */
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
/*  Prevention: worker participation as a ring                        */
/* ------------------------------------------------------------------ */

/**
 * Prevention tab: the share of Sites with a health and safety representative closes the ring. The other leading
 * measures (near misses per injury, actions on time, training) are the header's numbers and are not repeated
 * here (DESIGN.md "hero numbers repeated as body KPI cards").
 */
export function LeadingPanel({
    workerParticipation,
    filters,
}: {
    data?: HeroLeadingLagging['leading'];
    workerParticipation: ParticipationCoverage;
    siteCount?: number;
    filters?: HeroFilters;
}) {
    const audience = participationViewer(usePage<SharedData>().props.auth);
    const sourceAccess = participationAccessForViewer(
        workerParticipation.source_access,
        workerParticipation.viewer_id,
        audience,
    );
    const pct = participationValue(
        workerParticipation.pct,
        workerParticipation.source_access,
        workerParticipation.viewer_id,
        audience,
    );
    const committees = participationValue(
        workerParticipation.committees,
        workerParticipation.source_access,
        workerParticipation.viewer_id,
        audience,
    );
    const representatives = new URLSearchParams({ tab: 'representatives' });
    if (filters?.site != null) representatives.set('site_id', String(filters.site));
    const readable =
        workerParticipation.source_access === 'readable' &&
        participationSourceMatches(workerParticipation.viewer_id, audience);
    const sub =
        sourceAccess === 'restricted'
            ? 'Access restricted'
            : pct === null
              ? 'Not confirmed'
              : committees === null
                ? 'Current and past reps · committee count not confirmed'
                : `Current and past reps · ${committees} committee${committees === 1 ? '' : 's'} · Worker participation`;
    return (
        <RingCard
            value={pct ?? 0}
            mark={pct === null ? '—' : undefined}
            label={pct === null ? 'Sites with a health and safety rep' : `Sites with a health and safety rep — ${pct}%`}
            sub={sub}
            action={
                readable
                    ? { label: 'Open representatives', href: `/health-safety/worker-participation?${representatives}` }
                    : null
            }
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Harm & injuries: harm that has already happened                    */
/* ------------------------------------------------------------------ */

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
    const injuryHref = '/health-safety/injuries' + (injuries.size ? `?${injuries}` : '');
    const items: TallyItem[] = [
        {
            value: fmt(data.incidents),
            label: 'Incidents',
            sub: filters ? 'This period · records you can see' : 'Records you can see',
            tone: data.incidents > 0 ? 'warning' : undefined,
            href: `/incidents?${incidents}`,
        },
        {
            value: fmt(data.ltifr),
            label: 'Lost-time injury rate',
            sub: data.ltifr === null ? 'Not confirmed' : 'Per million hours worked',
            href: injuryHref,
        },
        {
            value: fmt(data.trifr),
            label: 'Recordable injury rate',
            sub: data.trifr === null ? 'Not confirmed' : 'Per million hours worked',
            href: injuryHref,
        },
        {
            value: fmt(data.days_since_lti),
            label: 'Days since a lost-time injury',
            sub: data.days_since_lti === null ? 'Not confirmed' : 'Since the last one you can see',
            href: injuryHref,
        },
    ];
    // A recorded zero is a fact here, not "nothing to report": keep every measure visible.
    return (
        <Tally
            title="Harm that has already happened"
            sub="Rates are per year · days since a lost-time injury counts from the last one on record"
            items={items}
            keepZeros
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Duties: where we stand                                             */
/* ------------------------------------------------------------------ */

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
    if (site !== undefined && site !== null) events.set('site_id', String(site));
    const siteSuffix = site !== undefined && site !== null ? `?site_id=${site}` : '';
    const sdsExpiring = expiring.filter((e) => e.type === 'sds').length;
    const drillsDue = expiring.filter((e) => e.type === 'drill').length;
    const icon = (Icon: LucideIcon) => <Icon aria-hidden="true" className="size-4" />;
    return (
        <RowList
            title="Where we stand"
            sub="This shows work on record. None of it proves we meet the regulations."
            items={[
                {
                    key: 'worksafe',
                    leading: icon(ShieldAlert),
                    title: 'WorkSafe notifiable',
                    sub: 'Notification work on record — it doesn’t prove compliance · Open safety reviews',
                    href: `/health-safety/events?${events}`,
                    badge: (
                        <StatusBadge variant={worksafePending === null ? 'neutral' : worksafePending > 0 ? 'warning' : 'neutral'} size="sm">
                            {worksafePending === null
                                ? 'Not confirmed'
                                : worksafePending > 0
                                  ? `${worksafePending} not notified yet`
                                  : 'None you can see'}
                        </StatusBadge>
                    ),
                },
                {
                    key: 'nga-paerewa',
                    leading: icon(ShieldCheck),
                    title: 'Ngā Paerewa',
                    sub: 'Certification evidence isn’t shown here',
                    badge: (
                        <StatusBadge variant="neutral" size="sm">
                            Not confirmed
                        </StatusBadge>
                    ),
                },
                {
                    key: 'substances',
                    leading: icon(FlaskConical),
                    title: 'Hazardous substances',
                    sub: 'Not the full register, and not a compliance check · Open chemical register',
                    href: `/health-safety/substances${siteSuffix}`,
                    badge: (
                        <StatusBadge variant="neutral" size="sm">
                            {sdsExpiring > 0 ? 'Safety data sheet review due' : 'No reviews in this preview'}
                        </StatusBadge>
                    ),
                },
                {
                    key: 'fire',
                    leading: icon(Flame),
                    title: 'Fire safety',
                    sub: 'See the drill register for dates and outcomes',
                    href: `/health-safety/drills${siteSuffix}`,
                    badge: (
                        <StatusBadge variant="neutral" size="sm">
                            {drillsDue > 0 ? (drillsDue === 1 ? 'Drill due' : `${drillsDue} drills due`) : 'No drills in this preview'}
                        </StatusBadge>
                    ),
                },
                {
                    key: 'first-aid',
                    leading: icon(HeartPulse),
                    title: 'First-aid cover',
                    sub: 'Doesn’t show who is on shift or whose first-aid certificate is current · Open first aid register',
                    href: `/health-safety/first-aid${siteSuffix}`,
                    badge: (
                        <StatusBadge variant="neutral" size="sm">
                            Not confirmed
                        </StatusBadge>
                    ),
                },
            ]}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Reports for the board and WorkSafe                                 */
/* ------------------------------------------------------------------ */

// Same list and names as every other board-report menu; each opens its readable report page.
const REPORT_ICONS: Record<BoardReportKey, LucideIcon> = {
    board: FileText,
    notifications: ShieldAlert,
    'investigation-outcomes': Search,
    'action-traceability': ClipboardCheck,
    'risk-register': Target,
};
const GOVERNANCE_REPORTS = boardReportLinks().map((report) => ({ ...report, desc: report.description, icon: REPORT_ICONS[report.key] }));

export function GovernanceExports() {
    // Every export here links to a governance.view-gated report route; hide the
    // whole section for register-only roles so they don't hit a 403 on click.
    const canViewBoardReports = usePage<SharedData>().props.auth.can?.governance?.view ?? false;
    if (!canViewBoardReports) return null;
    return (
        <section>
            <SectionHead title={BOARD_REPORTS_LABEL} sub="Reports for the board and for WorkSafe, ready to read or print" />
            <ul className="sg-cards" style={{ ['--cols' as string]: 3 }}>
                {GOVERNANCE_REPORTS.map((r) => (
                    <li key={r.href} className="sg-surface sg-p">
                        <Link href={r.href} className="sg-ph rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring">
                            <span aria-hidden="true" className="sg-av !size-9">
                                <r.icon className="size-4" />
                            </span>
                            <span className="min-w-0">
                                <b>{r.label}</b>
                                <small>{r.desc}</small>
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}
