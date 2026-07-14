import { TabStrip } from '@/components/rostering';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { SubstanceWizardDialog } from '@/components/health-safety/substance-wizard-dialog';
import { Card as GuardrailCard } from '@/components/ui/card';
import {
    IncidentTrendCard,
    LaggingCharts,
    LeadingCharts,
    type OpenHazardRow,
    SiteLeagueCard,
} from './components/charts';
import {
    CommandCentreHero,
    type HeroFilters,
    type HeroLeadingLagging,
} from './components/command-centre-hero';
import {
    buildHsTabItems,
    CompliancePanel,
    GovernanceExports,
    LaggingPanel,
    LeadingPanel,
    RoleLensBanner,
} from './components/dashboard-tabs';
import { HsFormWizard } from './components/form-wizard';
import { ReportIncidentDialog } from './components/report-incident-dialog';
import { ReportLauncher } from './components/report-launcher';
import { WIZARD_CONFIGS } from './components/wizard-configs';
import { HsWorklists, type WorklistsPayload } from './components/worklists';

type Props = {
    kpis: Record<string, number | null>;
    incident_trends: Array<{
        month: string;
        count: number;
        types: Record<string, number>;
    }>;
    backbone?: {
        events: {
            open_events: number;
            open_events_high_critical: number;
            events_period: number;
            worksafe_notifiable_open: number;
            worksafe_pending: number;
            events_by_severity: Record<string, number>;
        };
        investigations: {
            active_investigations: number;
            overdue_investigations: number;
            awaiting_review: number;
        };
        corrective_actions: {
            open_actions: number;
            overdue_actions: number;
            awaiting_verification: number;
        };
        risk_assessments: {
            active_assessments: number;
            high_extreme_active: number;
            due_for_review: number;
        };
        training: {
            total_requirements: number;
            blocking_requirements: number;
            staff_non_compliant: number;
        };
    };
    filters: HeroFilters;
    lens: string;
    sites: Array<{ id: number; name: string }>;
    org_name: string | null;
    clients: Array<{
        id: number;
        first_name: string;
        last_name: string;
        site_id?: number | null;
    }>;
    staff: Array<{ id: number; name: string }>;
    leading_lagging: HeroLeadingLagging;
    frequency_trends: Array<{
        month: string;
        ltifr: number | null;
        trifr: number | null;
    }>;
    frequency_operands: { near_misses: number; recordable: number };
    hazard_burndown: Array<{ week: string; open: number }>;
    incidents_by_category: Array<{ label: string; count: number }>;
    site_league: Array<{
        id: number;
        name: string;
        incidents: number;
        hazards: number;
    }>;
    open_hazards_list: OpenHazardRow[];
    worker_participation: { pct: number | null; committees: number };
    procedures: {
        approved: number;
        review_due: number;
        coverage_gap_categories: number;
    };
    first_aid?: { treatments: number; ambulance: number; hospital: number };
    restraints?: RestraintDashboardData;
    worklists: WorklistsPayload;
};

type RestraintDashboardData = {
    summary: {
        events_in_period: number;
        out_of_plan: number;
        with_injury: number;
        critical: number;
        unreviewed: number;
        active_plans: number;
        plans_review_due: number;
        clients_no_active_bsp: number;
    };
    unreviewed: Array<{
        id: number;
        reference: string;
        client: string | null;
        restraint_type: string | null;
        severity: string | null;
        within_support_plan: boolean;
        injury_occurred: boolean;
        started_at: string | null;
    }>;
};

const EMPTY_RESTRAINTS: RestraintDashboardData = {
    summary: {
        events_in_period: 0,
        out_of_plan: 0,
        with_injury: 0,
        critical: 0,
        unreviewed: 0,
        active_plans: 0,
        plans_review_due: 0,
        clients_no_active_bsp: 0,
    },
    unreviewed: [],
};

export default function HealthSafetyDashboard({
    kpis,
    incident_trends,
    backbone,
    filters,
    sites,
    org_name,
    clients,
    staff,
    leading_lagging,
    frequency_trends,
    frequency_operands,
    hazard_burndown,
    incidents_by_category,
    site_league,
    open_hazards_list = [],
    worker_participation = { pct: null, committees: 0 },
    procedures = { approved: 0, review_due: 0, coverage_gap_categories: 0 },
    first_aid = { treatments: 0, ambulance: 0, hospital: 0 },
    restraints = EMPTY_RESTRAINTS,
    worklists,
}: Props) {
    const [tab, setTab] = useState<string>('overview');
    const [launcherOpen, setLauncherOpen] = useState(false);
    const [activeWizard, setActiveWizard] = useState<string | null>(null);
    const tabItems = buildHsTabItems(
        worklists.open_investigations.length,
        worklists.expiring.length,
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Dashboard', href: '/health-safety/dashboard' },
            ]}
        >
            <Head title="Health & Safety Dashboard" />

            <div className="flex flex-col gap-6 p-6">
                <CommandCentreHero
                    leadingLagging={leading_lagging}
                    filters={filters}
                    sites={sites}
                    expiring={worklists.expiring}
                    worksafePending={backbone?.events.worksafe_pending ?? 0}
                    activeAlerts={kpis.active_alerts ?? 0}
                    openSafeguarding={kpis.open_safeguarding ?? 0}
                    fleetUnresolved={kpis.fleet_unresolved ?? 0}
                    fleetIncidents30d={kpis.fleet_incidents_30d ?? 0}
                    procedures={procedures}
                    onReport={() => setLauncherOpen(true)}
                    orgName={org_name}
                />

                <TabStrip
                    value={tab}
                    onChange={setTab}
                    items={tabItems}
                    ariaLabel="Health & Safety views"
                />
                <RoleLensBanner lens={filters.lens} />

                {tab === 'overview' && (
                    <div className="flex flex-col gap-4">
                        {/* Row 1 — overdue corrective actions (1.5fr) + site safety league (1fr) */}
                        <div className="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
                            <HsWorklists
                                worklists={worklists}
                                show={['corrective_actions']}
                            />
                            <SiteLeagueCard data={site_league} />
                        </div>
                        {/* Row 2 — WorkSafe-notifiable events + expiring soon (1fr 1fr) */}
                        <HsWorklists
                            worklists={worklists}
                            show={['notifiable', 'expiring']}
                        />
                        {/* Row 3 — full-width incident & near-miss trend */}
                        <IncidentTrendCard
                            bars={incident_trends}
                            frequency={frequency_trends}
                            variant="full"
                        />
                    </div>
                )}

                {tab === 'leading' && (
                    <div className="flex flex-col gap-4">
                        <FirstAidStrip data={first_aid} />
                        <LeadingPanel
                            data={leading_lagging.leading}
                            workerParticipation={worker_participation}
                            siteCount={sites.length}
                        />
                        <LeadingCharts
                            ratio={leading_lagging.leading.near_miss_ratio}
                            operands={frequency_operands}
                            burndown={hazard_burndown}
                            drillPct={kpis.drill_compliance_pct ?? 0}
                            trainingPct={
                                leading_lagging.leading.training_pct ?? 0
                            }
                            openHazards={open_hazards_list}
                        />
                    </div>
                )}

                {tab === 'lagging' && (
                    <div className="flex flex-col gap-4">
                        <LaggingPanel data={leading_lagging.lagging} />
                        <LaggingCharts
                            bars={incident_trends}
                            frequency={frequency_trends}
                            severity={backbone?.events?.events_by_severity}
                            category={incidents_by_category}
                        />
                        <RestraintStrip data={restraints} />
                        <HsWorklists
                            worklists={worklists}
                            show={['investigations']}
                        />
                    </div>
                )}

                {tab === 'compliance' && (
                    <div className="flex flex-col gap-4">
                        <CompliancePanel
                            expiring={worklists.expiring}
                            worksafePending={
                                backbone?.events.worksafe_pending ?? 0
                            }
                        />
                        <HsWorklists
                            worklists={worklists}
                            show={['expiring']}
                        />
                        <GovernanceExports />
                    </div>
                )}

                <ReportLauncher
                    open={launcherOpen}
                    onClose={() => setLauncherOpen(false)}
                    onWorkflow={(key) => {
                        setLauncherOpen(false);
                        // First aid has its own bespoke add-client-style wizard on the register
                        // page (the single record-first-aid experience) — open it there with all
                        // its props rather than the generic config-driven HsFormWizard.
                        if (key === 'first_aid') {
                            router.visit('/health-safety/first-aid?report=1');
                            return;
                        }
                        setActiveWizard(key);
                    }}
                />
                {activeWizard === 'incident' ? (
                    <ReportIncidentDialog
                        open
                        onClose={() => setActiveWizard(null)}
                        clients={clients}
                        sites={sites}
                    />
                ) : null}
                {/* The Chemical register's add-substance wizard is the single source — the
                    launcher tile mounts the same modal as the register's "Add substance". */}
                {activeWizard === 'substance' ? (
                    <SubstanceWizardDialog
                        open
                        onClose={() => setActiveWizard(null)}
                        onOpenSubstance={(id, opts) => {
                            setActiveWizard(null);
                            router.visit(
                                `/health-safety/substances?substance=${id}${opts?.action ? `&action=${opts.action}` : ''}`,
                            );
                        }}
                    />
                ) : null}
                {activeWizard &&
                activeWizard !== 'substance' &&
                WIZARD_CONFIGS[activeWizard] ? (
                    <HsFormWizard
                        key={activeWizard}
                        config={WIZARD_CONFIGS[activeWizard]}
                        refData={{
                            sites,
                            clients: clients.map((client) => ({
                                id: client.id,
                                name: `${client.first_name} ${client.last_name}`.trim(),
                            })),
                            staff,
                        }}
                        open
                        onClose={() => setActiveWizard(null)}
                    />
                ) : null}
            </div>
        </AppLayout>
    );
}

/**
 * First-aid activity strip (Leading tab) — three deep-link stat cards into the First Aid
 * Register. A leading care-activity signal: first-aid-only treatment is NOT recordable and
 * is deliberately excluded from TRIFR, so it lives here rather than among the lagging rates.
 */
function FirstAidStrip({
    data,
}: {
    data: { treatments: number; ambulance: number; hospital: number };
}) {
    const cards: {
        label: string;
        value: number;
        href: string;
        tone: 'neutral' | 'warning' | 'critical';
    }[] = [
        {
            label: 'First-aid treatments',
            value: data.treatments,
            href: '/health-safety/first-aid',
            tone: 'neutral',
        },
        {
            label: 'Ambulance called',
            value: data.ambulance,
            href: '/health-safety/first-aid?tab=ambulance',
            tone: 'warning',
        },
        {
            label: 'Hospital referrals',
            value: data.hospital,
            href: '/health-safety/first-aid?treatment_outcome=sent_to_hospital',
            tone: 'critical',
        },
    ];

    const valueClass = (
        tone: 'neutral' | 'warning' | 'critical',
        value: number,
    ) => {
        const colour =
            value <= 0
                ? 'text-foreground'
                : tone === 'critical'
                  ? 'text-status-critical'
                  : tone === 'warning'
                    ? 'text-status-warning'
                    : 'text-foreground';
        return `mt-1 text-3xl font-bold tabular-nums ${colour}`;
    };

    return (
        <div className="grid gap-3 sm:grid-cols-3">
            {cards.map((c) => (
                <Link
                    key={c.label}
                    href={c.href}
                    className="group flex flex-col rounded-xl border border-border bg-card p-4 shadow-sm transition-colors hover:border-primary/40 hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                >
                    <span className="text-xs font-medium text-muted-foreground">
                        {c.label}
                    </span>
                    <span className={valueClass(c.tone, c.value)}>
                        {c.value}
                    </span>
                    <span className="mt-1 text-[11px] text-muted-foreground">
                        last 30 days
                    </span>
                </Link>
            ))}
        </div>
    );
}

/**
 * Restraint & behaviour-support governance strip (Lagging tab) — least-restrictive
 * practice (Ngā Paerewa NZS 8134:2021). Stat tiles deep-link into the restraints
 * register's matching tab; the "needs review" list opens each event in its review
 * queue. Kept self-contained here so the restraints module surfaces on the command
 * centre without churning the shared dashboard-tab components.
 */
function RestraintStrip({ data }: { data: RestraintDashboardData }) {
    const s = data.summary;
    const tiles: {
        label: string;
        value: number;
        caption: string;
        href: string;
        tone: 'neutral' | 'warning' | 'critical';
    }[] = [
        {
            label: 'Restraint events',
            value: s.events_in_period,
            caption: 'this period',
            href: '/health-safety/restraints?lens=events&tab=30d',
            tone: 'neutral',
        },
        {
            label: 'Out of plan',
            value: s.out_of_plan,
            caption: 'deviations',
            href: '/health-safety/restraints?lens=events&tab=out_of_plan',
            tone: 'critical',
        },
        {
            label: 'With injury',
            value: s.with_injury,
            caption: 'harm occurred',
            href: '/health-safety/restraints?lens=events&tab=injury',
            tone: 'critical',
        },
        {
            label: 'Unreviewed',
            value: s.unreviewed,
            caption: 'need review',
            href: '/health-safety/restraints?lens=events&tab=unreviewed',
            tone: 'warning',
        },
        {
            label: 'Active BSPs',
            value: s.active_plans,
            caption: 'in place',
            href: '/health-safety/restraints?lens=plans&tab=active',
            tone: 'neutral',
        },
        {
            label: 'Plans review due',
            value: s.plans_review_due,
            caption: 'within 30 days',
            href: '/health-safety/restraints?lens=plans&tab=review_due',
            tone: 'warning',
        },
    ];

    const valueClass = (
        tone: 'neutral' | 'warning' | 'critical',
        value: number,
    ) => {
        const colour =
            value <= 0
                ? 'text-foreground'
                : tone === 'critical'
                  ? 'text-status-critical'
                  : tone === 'warning'
                    ? 'text-status-warning'
                    : 'text-foreground';
        return `mt-1 text-2xl font-bold tabular-nums ${colour}`;
    };

    const sevTone = (sev: string | null): 'neutral' | 'warning' | 'critical' =>
        sev === 'critical' || sev === 'high'
            ? 'critical'
            : sev === 'medium'
              ? 'warning'
              : 'neutral';
    const sevClass = (sev: string | null) => {
        const t = sevTone(sev);
        return t === 'critical'
            ? 'bg-status-critical-bg text-status-critical'
            : t === 'warning'
              ? 'bg-status-warning-bg text-status-warning'
              : 'bg-muted text-muted-foreground';
    };

    return (
        <GuardrailCard
            unstyled
            className="rounded-xl border border-border bg-card p-4 shadow-sm"
        >
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h3 className="text-sm font-semibold text-foreground">
                        Restraint &amp; behaviour support
                    </h3>
                    <p className="text-[11px] text-muted-foreground">
                        Least-restrictive practice — Ngā Paerewa NZS 8134:2021
                    </p>
                </div>
                <Link
                    href="/health-safety/restraints"
                    className="shrink-0 text-xs font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                >
                    Open register →
                </Link>
            </div>

            <div className="mt-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                {tiles.map((t) => (
                    <Link
                        key={t.label}
                        href={t.href}
                        className="group flex flex-col rounded-lg border border-border bg-background p-3 transition-colors hover:border-primary/40 hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                    >
                        <span className="text-[11px] font-medium text-muted-foreground">
                            {t.label}
                        </span>
                        <span className={valueClass(t.tone, t.value)}>
                            {t.value}
                        </span>
                        <span className="mt-0.5 text-[10px] text-muted-foreground">
                            {t.caption}
                        </span>
                    </Link>
                ))}
            </div>

            {data.unreviewed.length > 0 ? (
                <div className="mt-4">
                    <div className="mb-2 flex items-center justify-between">
                        <span className="text-xs font-medium text-muted-foreground">
                            Needs review
                        </span>
                        <Link
                            href="/health-safety/restraints?lens=events&tab=unreviewed"
                            className="text-[11px] font-medium text-primary hover:underline"
                        >
                            View all
                        </Link>
                    </div>
                    <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                        {data.unreviewed.map((e) => (
                            <li key={e.id}>
                                <Link
                                    href={`/health-safety/restraints?lens=events&tab=unreviewed&event=${e.id}`}
                                    className="flex items-center gap-3 px-3 py-2 text-sm transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none focus-visible:ring-inset"
                                >
                                    <span className="font-mono text-[11px] text-muted-foreground">
                                        {e.reference}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate font-medium text-foreground">
                                        {e.client ?? 'Unknown client'}
                                    </span>
                                    {e.restraint_type ? (
                                        <span className="hidden truncate text-xs text-muted-foreground sm:inline">
                                            {e.restraint_type}
                                        </span>
                                    ) : null}
                                    {!e.within_support_plan ? (
                                        <span className="rounded-full bg-status-critical-bg px-2 py-0.5 text-[10px] font-medium text-status-critical">
                                            Out of plan
                                        </span>
                                    ) : null}
                                    {e.injury_occurred ? (
                                        <span className="rounded-full bg-status-critical-bg px-2 py-0.5 text-[10px] font-medium text-status-critical">
                                            Injury
                                        </span>
                                    ) : null}
                                    {e.severity ? (
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[10px] font-medium capitalize ${sevClass(e.severity)}`}
                                        >
                                            {e.severity}
                                        </span>
                                    ) : null}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : (
                <p className="mt-4 rounded-lg border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                    No restraint events awaiting review.
                </p>
            )}
        </GuardrailCard>
    );
}
