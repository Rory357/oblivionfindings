import { TabStrip } from '@/components/rostering';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { IncidentTrendCard, LaggingCharts, LeadingCharts, type OpenHazardRow, SiteLeagueCard } from './components/charts';
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
import { SubstanceWizardDialog } from '@/components/health-safety/substance-wizard-dialog';
import { ReportLauncher } from './components/report-launcher';
import { WIZARD_CONFIGS } from './components/wizard-configs';
import { HsWorklists, type WorklistsPayload } from './components/worklists';

type Props = {
    kpis: Record<string, number>;
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
    clients: Array<{ id: number; name: string }>;
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
    site_league: Array<{ id: number; name: string; incidents: number; hazards: number }>;
    open_hazards_list: OpenHazardRow[];
    worker_participation: { pct: number | null; committees: number };
    procedures: { approved: number; review_due: number; coverage_gap_categories: number };
    first_aid?: { treatments: number; ambulance: number; hospital: number };
    worklists: WorklistsPayload;
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
                    notifiableEvents={worklists.notifiable_events}
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
                            <HsWorklists worklists={worklists} show={['corrective_actions']} />
                            <SiteLeagueCard data={site_league} />
                        </div>
                        {/* Row 2 — WorkSafe-notifiable events + expiring soon (1fr 1fr) */}
                        <HsWorklists worklists={worklists} show={['notifiable', 'expiring']} />
                        {/* Row 3 — full-width incident & near-miss trend */}
                        <IncidentTrendCard bars={incident_trends} frequency={frequency_trends} variant="full" />
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
                            trainingPct={leading_lagging.leading.training_pct ?? 0}
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
                        <HsWorklists worklists={worklists} show={['investigations']} />
                    </div>
                )}

                {tab === 'compliance' && (
                    <div className="flex flex-col gap-4">
                        <CompliancePanel
                            expiring={worklists.expiring}
                            notifiableEvents={worklists.notifiable_events}
                        />
                        <HsWorklists worklists={worklists} show={['expiring']} />
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
                    <ReportIncidentDialog open onClose={() => setActiveWizard(null)} clients={clients} sites={sites} />
                ) : null}
                {/* The Chemical register's add-substance wizard is the single source — the
                    launcher tile mounts the same modal as the register's "Add substance". */}
                {activeWizard === 'substance' ? (
                    <SubstanceWizardDialog
                        open
                        onClose={() => setActiveWizard(null)}
                        onOpenSubstance={(id, opts) => {
                            setActiveWizard(null);
                            router.visit(`/health-safety/substances?substance=${id}${opts?.action ? `&action=${opts.action}` : ''}`);
                        }}
                    />
                ) : null}
                {activeWizard && activeWizard !== 'substance' && WIZARD_CONFIGS[activeWizard] ? (
                    <HsFormWizard
                        key={activeWizard}
                        config={WIZARD_CONFIGS[activeWizard]}
                        refData={{ sites, clients, staff }}
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
function FirstAidStrip({ data }: { data: { treatments: number; ambulance: number; hospital: number } }) {
    const cards: { label: string; value: number; href: string; tone: 'neutral' | 'warning' | 'critical' }[] = [
        { label: 'First-aid treatments', value: data.treatments, href: '/health-safety/first-aid', tone: 'neutral' },
        { label: 'Ambulance called', value: data.ambulance, href: '/health-safety/first-aid?tab=ambulance', tone: 'warning' },
        { label: 'Hospital referrals', value: data.hospital, href: '/health-safety/first-aid?treatment_outcome=sent_to_hospital', tone: 'critical' },
    ];

    const valueClass = (tone: 'neutral' | 'warning' | 'critical', value: number) => {
        const colour = value <= 0 ? 'text-foreground' : tone === 'critical' ? 'text-status-critical' : tone === 'warning' ? 'text-status-warning' : 'text-foreground';
        return `mt-1 text-3xl font-bold tabular-nums ${colour}`;
    };

    return (
        <div className="grid gap-3 sm:grid-cols-3">
            {cards.map((c) => (
                <Link
                    key={c.label}
                    href={c.href}
                    className="group flex flex-col rounded-xl border border-border bg-card p-4 shadow-sm transition-colors hover:border-primary/40 hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                >
                    <span className="text-xs font-medium text-muted-foreground">{c.label}</span>
                    <span className={valueClass(c.tone, c.value)}>{c.value}</span>
                    <span className="mt-1 text-[11px] text-muted-foreground">last 30 days</span>
                </Link>
            ))}
        </div>
    );
}
