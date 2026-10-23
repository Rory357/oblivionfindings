import { PageHeaderRail, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Card as GuardrailCard } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    IncidentTrendCard,
    LaggingCharts,
    LeadingCharts,
    type OpenHazardRow,
    SiteSafetyComparisonCard,
} from './components/charts';
import {
    CommandCentreAttention,
    CommandCentreHero,
    type HeroFilters,
    type HeroLeadingLagging,
} from './components/command-centre-hero';
import { searchDashboardWorklists } from './components/dashboard-search';
import {
    buildHsTabItems,
    CompliancePanel,
    GovernanceExports,
    LaggingPanel,
    LeadingPanel,
} from './components/dashboard-tabs';
import { HsFormWizard } from './components/form-wizard';
import { ReportIncidentDialog } from './components/report-incident-dialog';
import { ReportLauncher } from './components/report-launcher';
import { WIZARD_CONFIGS } from './components/wizard-configs';
import { HsWorklists, type WorklistsPayload } from './components/worklists';
import type { ParticipationCoverage } from './participation-source-state';

type Props = {
    source_scope?: 'readable_subset';
    source_completeness?: 'unknown';
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
    frequency_operands: { near_misses: number; recordable: number | null };
    hazard_burndown: Array<{ week: string; open: number }>;
    incidents_by_category: Array<{ label: string; count: number }>;
    site_league: Array<{
        id: number;
        name: string;
        incidents: number;
        hazards: number | null;
    }>;
    open_hazards_list: OpenHazardRow[];
    worker_participation: ParticipationCoverage;
    procedures: {
        approved: number;
        review_due: number;
        coverage_gap_categories: number;
    };
    first_aid?: {
        treatments: number | null;
        ambulance: number | null;
        hospital: number | null;
    };
    restraints?: RestraintDashboardData;
    worklists: WorklistsPayload;
};

type RestraintDashboardData = {
    summary: {
        events_in_period: number | null;
        out_of_plan: number | null;
        with_injury: number | null;
        critical: number | null;
        unreviewed: number | null;
        active_plans: number | null;
        plans_review_due: number | null;
        clients_no_active_bsp: number | null;
        source_access?: 'readable' | 'restricted' | 'unavailable';
        scope?: 'current_readable_sources';
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
    }> | null;
};

const EMPTY_RESTRAINTS: RestraintDashboardData = {
    summary: {
        events_in_period: null,
        out_of_plan: null,
        with_injury: null,
        critical: null,
        unreviewed: null,
        active_plans: null,
        plans_review_due: null,
        clients_no_active_bsp: null,
        source_access: 'unavailable',
    },
    unreviewed: null,
};

export default function HealthSafetyDashboard({
    source_completeness,
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
    worker_participation = {
        pct: null,
        committees: null,
        source_access: 'unavailable',
    },
    procedures = { approved: 0, review_due: 0, coverage_gap_categories: 0 },
    first_aid = { treatments: null, ambulance: null, hospital: null },
    restraints = EMPTY_RESTRAINTS,
    worklists,
}: Props) {
    const [tab, setTab] = useState<string>('overview');
    const [launcherOpen, setLauncherOpen] = useState(false);
    const [activeWizard, setActiveWizard] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const visibleWorklists = searchDashboardWorklists(worklists, search);
    const searchTerms = search
        .trim()
        .toLocaleLowerCase('en-NZ')
        .split(/\s+/)
        .filter(Boolean);
    const visibleSites = site_league.filter((site) =>
        searchTerms.every((term) =>
            site.name.toLocaleLowerCase('en-NZ').includes(term),
        ),
    );
    const tabItems = buildHsTabItems(
        worklists.open_investigations.length,
        worklists.expiring.length,
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Dashboard', href: '/health-safety/dashboard' },
            ]}
        >
            <Head title="Health & Safety Dashboard" />

            <PageLayout
                hero={
                    <CommandCentreHero
                        sourceCompleteness={source_completeness}
                        leadingLagging={leading_lagging}
                        filters={filters}
                        sites={sites}
                        expiring={worklists.expiring}
                        ppeState={worklists.ppe_expiring_state}
                        worksafePending={
                            backbone?.events.worksafe_pending ?? null
                        }
                        activeAlerts={kpis.active_alerts ?? null}
                        openSafeguarding={kpis.open_safeguarding ?? null}
                        fleetUnresolved={kpis.fleet_unresolved ?? null}
                        fleetIncidents30d={kpis.fleet_incidents_30d ?? null}
                        procedures={procedures}
                        onReport={() => setLauncherOpen(true)}
                        orgName={org_name}
                        search={{ value: search, onChange: setSearch }}
                        rail={
                            <PageHeaderRail
                                items={tabItems.map((item) => ({
                                    key: item.id,
                                    label: item.label,
                                    icon: item.icon,
                                    count:
                                        typeof item.badge === 'number'
                                            ? item.badge
                                            : undefined,
                                }))}
                                value={tab}
                                onSelect={setTab}
                                ariaLabel="Health & Safety views"
                            />
                        }
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    {filters.lens === 'frontline' && (
                        <p className="text-subtle">
                            <Link
                                href="/my-day"
                                className="frontline-hit frontline-focus inline-flex rounded-md text-primary underline"
                            >
                                Open My Day
                            </Link>{' '}
                            for your shift, medicines and reporting tasks.
                        </p>
                    )}
                    <CommandCentreAttention
                        expiring={worklists.expiring}
                        ppeState={worklists.ppe_expiring_state}
                        worksafePending={
                            backbone?.events.worksafe_pending ?? null
                        }
                        activeAlerts={kpis.active_alerts ?? null}
                    />
                    {search.trim() && (
                        <p className="text-subtle" role="status">
                            Search matches the items loaded on this overview.
                            Counts and charts retain the selected dates and
                            site. Open a register to search all accessible
                            records.
                        </p>
                    )}

                    {tab === 'overview' && (
                        <div className="flex flex-col gap-4">
                            {/* First attention queue — direct H&S governance acceptance. */}
                            <HsWorklists
                                worklists={visibleWorklists}
                                show={['acceptance']}
                            />
                            {/* Next row — overdue corrective actions (1.5fr) + site safety league (1fr) */}
                            <div className="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
                                <HsWorklists
                                    worklists={visibleWorklists}
                                    show={['corrective_actions']}
                                />
                                <SiteSafetyComparisonCard data={visibleSites} />
                            </div>
                            {/* Row 2 — WorkSafe-notifiable events + expiring soon (1fr 1fr) */}
                            <HsWorklists
                                worklists={visibleWorklists}
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
                                filters={filters}
                            />
                            <LeadingCharts
                                ratio={leading_lagging.leading.near_miss_ratio}
                                operands={frequency_operands}
                                burndown={hazard_burndown}
                                drillPct={kpis.drill_compliance_pct ?? null}
                                trainingPct={
                                    leading_lagging.leading.training_pct
                                }
                                openHazards={open_hazards_list}
                            />
                        </div>
                    )}

                    {tab === 'lagging' && (
                        <div className="flex flex-col gap-4">
                            <LaggingPanel
                                data={leading_lagging.lagging}
                                filters={filters}
                            />
                            <LaggingCharts
                                bars={incident_trends}
                                frequency={frequency_trends}
                                severity={backbone?.events?.events_by_severity}
                                category={incidents_by_category}
                            />
                            <RestraintStrip data={restraints} />
                            <HsWorklists
                                worklists={visibleWorklists}
                                show={['investigations']}
                            />
                        </div>
                    )}

                    {tab === 'compliance' && (
                        <div className="flex flex-col gap-4">
                            <CompliancePanel
                                site={filters.site}
                                expiring={worklists.expiring}
                                worksafePending={
                                    backbone?.events.worksafe_pending ?? null
                                }
                            />
                            <HsWorklists
                                worklists={visibleWorklists}
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
                                router.visit(
                                    '/health-safety/first-aid/report?return_to=%2Fhealth-safety',
                                );
                                return;
                            }
                            if (key === 'substance') {
                                router.visit(
                                    '/health-safety/substances/create',
                                );
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
            </PageLayout>
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
    data: {
        treatments: number | null;
        ambulance: number | null;
        hospital: number | null;
    };
}) {
    const cards: {
        label: string;
        value: number | null;
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
        value: number | null,
    ) => {
        const colour =
            value === null || value <= 0
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
                        {c.value ?? '—'}
                    </span>
                    <span className="mt-1 text-[11px] text-muted-foreground">
                        {c.value === null
                            ? 'Not confirmed'
                            : 'last 30 days · readable records'}
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
        value: number | null;
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
        value: number | null,
    ) => {
        const colour =
            value === null || value <= 0
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
                            {t.value ?? '—'}
                        </span>
                        <span className="mt-0.5 text-[10px] text-muted-foreground">
                            {t.value === null ? 'Not confirmed' : t.caption}
                        </span>
                    </Link>
                ))}
            </div>

            <p className="text-subtle mt-3 text-muted-foreground">
                {s.source_access === 'restricted'
                    ? 'Access to restraint measures is restricted.'
                    : 'Measures cover readable records; the full picture may be incomplete.'}
            </p>
            {data.unreviewed !== null && data.unreviewed.length > 0 ? (
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
                                        {e.client ??
                                            'Client details unavailable'}
                                    </span>
                                    {e.restraint_type ? (
                                        <span className="hidden truncate text-xs text-muted-foreground sm:inline">
                                            {e.restraint_type}
                                        </span>
                                    ) : null}
                                    {!e.within_support_plan ? (
                                        <StatusBadge
                                            variant="critical"
                                            size="sm"
                                        >
                                            Out of plan
                                        </StatusBadge>
                                    ) : null}
                                    {e.injury_occurred ? (
                                        <StatusBadge
                                            variant="critical"
                                            size="sm"
                                        >
                                            Injury
                                        </StatusBadge>
                                    ) : null}
                                    {e.severity ? (
                                        <StatusBadge
                                            variant={sevTone(e.severity)}
                                            status={e.severity}
                                            size="sm"
                                        />
                                    ) : null}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : (
                <p className="mt-4 rounded-lg border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                    {data.unreviewed === null
                        ? 'The review queue is not available.'
                        : 'No matching readable restraint events awaiting review.'}
                </p>
            )}
        </GuardrailCard>
    );
}
