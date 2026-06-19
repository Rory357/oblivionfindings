import { TabStrip } from '@/components/rostering';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
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
                        setActiveWizard(key);
                    }}
                />
                {activeWizard === 'incident' ? (
                    <ReportIncidentDialog open onClose={() => setActiveWizard(null)} clients={clients} sites={sites} />
                ) : null}
                {activeWizard && WIZARD_CONFIGS[activeWizard] ? (
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
