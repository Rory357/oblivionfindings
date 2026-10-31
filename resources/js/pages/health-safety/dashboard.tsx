import { DeferredPanel } from '@/components/deferred-panel';
import { PageHeaderRail, PageLayout } from '@/components/page';
import {
    Flow,
    Log,
    PointBars,
    RowList,
    SideColumn,
    Tally,
    type LogItem,
    type TallyItem,
} from '@/components/signature/blocks';
import { SkeletonCard, SkeletonStats } from '@/components/ui/skeleton-card';
import { formatDateTimeLong } from '@/lib/datetime';
import { hsLabel, hsVariant } from '@/lib/health-safety/hs-labels';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

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
import type { HeaderBoxes } from './components/overview-header-boxes';
import { OverviewHeaderCharts } from './components/overview-header-charts';
import { OverviewQueue, ReviewStages, type OverviewQueueData } from './components/overview-queue';
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
import { reportPageFor } from './components/report-pages';
import { RoleStart } from './components/role-start';
import { WIZARD_CONFIGS } from './components/wizard-configs';
import { completedAgo, HsWorklists, type WorklistsPayload } from './components/worklists';
import type { ParticipationCoverage } from './participation-source-state';

type Backbone = {
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

type SafeguardingSource = { access: 'readable' | 'restricted'; href: string | null };
type Kpis = Record<string, number | null>;
type IncidentTrend = Array<{ month: string; count: number; types: Record<string, number> }>;
type FrequencyTrend = Array<{ month: string; ltifr: number | null; trifr: number | null }>;
type SiteLeague = Array<{ id: number; name: string; incidents: number; hazards: number | null }>;
type FirstAid = { treatments: number | null; ambulance: number | null; hospital: number | null };

/**
 * First-response props are always here. The rest load after the page shows (step C, Inertia::defer groups
 * "headline", "work" and "detail") and are undefined until then; every panel that shows them uses <DeferredPanel>.
 */
type Props = {
    source_scope?: 'readable_subset';
    source_completeness?: 'unknown';
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
    worker_participation: ParticipationCoverage;
    procedures: {
        approved: number;
        review_due: number;
        coverage_gap_categories: number;
    };
    /** Where open incidents are up to, by stage (optional: hidden until the source sends it). */
    incident_stages?: IncidentStagesData | null;
    /** G2: which four header boxes this person gets (the boxes themselves load as `header_boxes`). */
    header_box_set?: HeaderBoxes['set'];

    // Background group "headline".
    kpis?: Kpis;
    /** Safeguarding's own read: the open-concerns link, or restricted without safeguarding access. */
    safeguarding_source?: SafeguardingSource;
    incident_trends?: IncidentTrend;
    leading_lagging?: HeroLeadingLagging;
    frequency_trends?: FrequencyTrend;
    // Background group "work".
    worklists?: WorklistsPayload;
    backbone?: Backbone;
    /** Exposure managers only: how many exposures haven't reached the H&S register (job 3). */
    exposure_delivery_problems?: number | null;
    // Background group "detail".
    frequency_operands?: { near_misses: number; recordable: number | null };
    hazard_burndown?: Array<{ week: string; open: number }>;
    incidents_by_category?: Array<{ label: string; count: number }>;
    site_league?: SiteLeague;
    open_hazards_list?: OpenHazardRow[];
    first_aid?: FirstAid;
    restraints?: RestraintDashboardData;
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

type WorkValues = { worklists: WorklistsPayload; backbone: Backbone; exposure_delivery_problems: number | null };
const WORK_KEYS: Array<keyof WorkValues> = ['worklists', 'backbone', 'exposure_delivery_problems'];

/** A panel fed by the "work" group (worklists, backbone counts, exposure problems). */
function WorkPanel({ label, rows = 3, children }: { label: string; rows?: number; children: (values: WorkValues) => ReactNode }) {
    return (
        <DeferredPanel<WorkValues> data={WORK_KEYS} label={label} fallback={<SkeletonCard rows={rows} />}>
            {children}
        </DeferredPanel>
    );
}

export default function HealthSafetyDashboard({
    source_completeness,
    filters,
    sites,
    org_name,
    clients,
    staff,
    worker_participation = {
        pct: null,
        committees: null,
        source_access: 'unavailable',
    },
    procedures,
    worklists,
    incident_stages = null,
    header_box_set,
}: Props) {
    // A tab can be opened from a link (?tab=leading); unknown values fall back to the overview.
    const pageUrl = usePage().url;
    const [tab, setTab] = useState<string>(() => {
        const requested = new URLSearchParams(pageUrl.split('?')[1] ?? '').get('tab');
        return requested && ['overview', 'leading', 'lagging', 'compliance'].includes(requested) ? requested : 'overview';
    });
    const [launcherOpen, setLauncherOpen] = useState(false);
    const [activeWizard, setActiveWizard] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    // First aid, chemicals, restraints, drills, injuries, lone-worker sessions and safeguarding
    // open their own report pages; the incident opens its dialog here.
    const openWorkflow = (key: string) => {
        setLauncherOpen(false);
        const reportPage = reportPageFor(key);
        if (reportPage) {
            router.visit(reportPage);
            return;
        }
        setActiveWizard(key);
    };
    const visible = (lists: WorklistsPayload) => searchDashboardWorklists(lists, search);
    const searchTerms = search
        .trim()
        .toLocaleLowerCase('en-NZ')
        .split(/\s+/)
        .filter(Boolean);
    const visibleSites = (league: SiteLeague) =>
        league.filter((site) =>
            searchTerms.every((term) =>
                site.name.toLocaleLowerCase('en-NZ').includes(term),
            ),
        );
    // Tab counts appear once the worklists have loaded; until then the tabs carry no count (never a "0").
    const tabItems = buildHsTabItems(
        worklists?.open_investigations.length ?? 0,
        worklists?.expiring.length ?? 0,
    );
    // Destinations keep the selected Site (the destination registers read site_id).
    const destination = (path: string, params: Record<string, string> = {}) => {
        const query = new URLSearchParams(params);
        if (filters.site !== null) query.set('site_id', String(filters.site));
        return path + (query.size ? '?' + query.toString() : '');
    };
    const fleetRestricted =
        'Access restricted — Health & safety access doesn’t include Fleet records';
    // The header carries four numbers; the lens's other two measures open the body (approved drawings).
    const measuresFor = (kpis: Kpis, safeguardingSource: SafeguardingSource): TallyItem[] => {
        const fleetUnresolved = kpis.fleet_unresolved ?? null;
        const fleet30 = kpis.fleet_incidents_30d ?? null;
        const openSafeguarding = kpis.open_safeguarding ?? null;

        return filters.lens === 'governance'
            ? [
                  {
                      value: openSafeguarding === null ? '—' : String(openSafeguarding),
                      label: 'Safeguarding',
                      sub: openSafeguarding === null ? 'Not available' : 'Open concerns now',
                      // Safeguarding's own read decides the count and the link (Stephan, 26 Oct: hidden without access).
                      href: safeguardingSource.href ?? undefined,
                  },
                  {
                      value: fleetUnresolved === null ? '—' : String(fleetUnresolved),
                      label: 'Fleet incidents',
                      sub: fleetUnresolved === null ? fleetRestricted : 'Unresolved now',
                      href:
                          fleetUnresolved === null
                              ? undefined
                              : destination('/fleet-assets/incidents', { tab: 'open' }),
                  },
              ]
            : [
                  {
                      value: String(procedures.approved),
                      label: 'Procedures',
                      sub:
                          procedures.review_due > 0
                              ? String(procedures.review_due) + ' due for review'
                              : procedures.coverage_gap_categories > 0
                                ? procedures.coverage_gap_categories === 1
                                    ? '1 area still needs a procedure'
                                    : String(procedures.coverage_gap_categories) + ' areas still need a procedure'
                                : 'Approved',
                      tone:
                          procedures.coverage_gap_categories > 0
                              ? 'critical'
                              : procedures.review_due > 0
                                ? 'warning'
                                : undefined,
                      href: destination('/health-safety/procedures', { tab: 'approved' }),
                  },
                  {
                      value: fleet30 === null ? '—' : String(fleet30),
                      label: 'Fleet incidents',
                      sub: fleet30 === null ? fleetRestricted : 'Last 30 days',
                      href: fleet30 === null ? undefined : destination('/fleet-assets/incidents'),
                  },
              ];
    };
    const measures = (
        <DeferredPanel<{ kpis: Kpis; safeguarding_source: SafeguardingSource }>
            data={['kpis', 'safeguarding_source']}
            label="Safety measures"
            fallback={<SkeletonStats count={2} />}
        >
            {({ kpis, safeguarding_source }) => <Tally items={measuresFor(kpis, safeguarding_source)} keepZeros />}
        </DeferredPanel>
    );
    const attention = (
        <DeferredPanel<WorkValues & { kpis: Kpis }>
            data={[...WORK_KEYS, 'kpis']}
            label="Needs attention"
            fallback={<SkeletonStats count={4} />}
        >
            {({ worklists: lists, backbone, exposure_delivery_problems, kpis }) => (
                <CommandCentreAttention
                    expiring={lists.expiring}
                    ppeState={lists.ppe_expiring_state}
                    worksafePending={backbone.events.worksafe_pending ?? null}
                    activeAlerts={kpis.active_alerts ?? null}
                    exposureDeliveryProblems={exposure_delivery_problems ?? null}
                    site={filters.site}
                />
            )}
        </DeferredPanel>
    );
    const worklist = (show: Parameters<typeof HsWorklists>[0]['show'], label: string) => (
        <WorkPanel label={label}>
            {({ worklists: lists }) => <HsWorklists worklists={visible(lists)} show={show} />}
        </WorkPanel>
    );
    const trend = (variant: 'full' | 'bars') => (
        <DeferredPanel<{ incident_trends: IncidentTrend; frequency_trends: FrequencyTrend }>
            data={['incident_trends', 'frequency_trends']}
            label="Incident trend"
            fallback={<SkeletonCard rows={5} />}
        >
            {({ incident_trends, frequency_trends }) =>
                variant === 'full' ? (
                    <IncidentTrendCard
                        bars={incident_trends}
                        frequency={frequency_trends}
                        variant="full"
                        title="Incidents and near misses"
                    />
                ) : (
                    <IncidentBars bars={incident_trends} />
                )
            }
        </DeferredPanel>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health and safety', href: '/health-safety' },
            ]}
        >
            <Head title="Health & safety" />

            <PageLayout
                hero={
                    <CommandCentreHero
                        sourceCompleteness={source_completeness}
                        filters={filters}
                        sites={sites}
                        onReport={() => setLauncherOpen(true)}
                        orgName={org_name}
                        search={{ value: search, onChange: setSearch }}
                        boxSet={header_box_set}
                        charts={
                            header_box_set ? (
                                <OverviewHeaderCharts hazards={header_box_set === 'hazards'} link={destination} />
                            ) : undefined
                        }
                        rail={
                            <PageHeaderRail
                                items={tabItems.map((item) => ({
                                    key: item.id,
                                    label: item.label,
                                    count:
                                        typeof item.badge === 'number'
                                            ? item.badge
                                            : undefined,
                                }))}
                                value={tab}
                                onSelect={setTab}
                                ariaLabel="Health & safety views"
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
                    {source_completeness === 'unknown' && !header_box_set && (
                        <p className="text-subtle" role="status">
                            These measures cover records you can access. The
                            full picture may be incomplete.
                        </p>
                    )}
                    {search.trim() && (
                        <p className="text-subtle" role="status">
                            Search matches the items loaded on this overview.
                            Counts and charts retain the selected dates and
                            site. Open a register to search all accessible
                            records.
                        </p>
                    )}

                    {tab === 'overview' && header_box_set && filters.lens !== 'frontline' && (
                        // G2 (B2.2, approved boards M1/M3): one queue in D1's blocks, where reviews are up to, then the
                        // side column. The person's own numbers sit in the header.
                        <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                            <div className="grid min-w-0 gap-5">
                                <DeferredPanel<WorkValues & { queue: OverviewQueueData }>
                                    data={[...WORK_KEYS, 'queue']}
                                    label="Your queue"
                                    fallback={<SkeletonCard rows={6} />}
                                >
                                    {({ worklists: lists, queue }) => (
                                        <OverviewQueue
                                            queue={queue}
                                            lists={visible(lists)}
                                            link={destination}
                                            title={header_box_set === 'queue' && filters.lens === 'governance' ? 'Your queue' : 'Your sites'}
                                        />
                                    )}
                                </DeferredPanel>
                                {header_box_set === 'queue' || header_box_set === 'compliance' ? (
                                    <DeferredPanel<{ queue: OverviewQueueData }> data="queue" label="Where safety reviews are up to" fallback={<SkeletonStats count={5} />}>
                                        {({ queue }) => <ReviewStages stages={queue.stages} link={destination} />}
                                    </DeferredPanel>
                                ) : null}
                            </div>
                            <SideColumn>
                                {header_box_set === 'queue' ? (
                                    <DeferredPanel<{ leading_lagging: HeroLeadingLagging }> data="leading_lagging" label="Days since a lost-time injury" fallback={<SkeletonStats count={1} />}>
                                        {({ leading_lagging }) =>
                                            leading_lagging.lagging.days_since_lti === null ? null : (
                                                <Tally
                                                    keepZeros
                                                    items={[
                                                        {
                                                            value: String(leading_lagging.lagging.days_since_lti),
                                                            label: 'Days since a lost-time injury',
                                                            sub: 'Rates are on "Harm and injuries"',
                                                            href: destination('/health-safety/analytics'),
                                                        },
                                                    ]}
                                                />
                                            )
                                        }
                                    </DeferredPanel>
                                ) : null}
                                {worklist(['expiring'], 'Checks due')}
                                <DeferredPanel<{ site_league: SiteLeague }>
                                    data="site_league"
                                    label="Sites"
                                    fallback={<SkeletonCard rows={4} />}
                                >
                                    {({ site_league }) =>
                                        // B2.7: a side-by-side table only with two or more Sites.
                                        site_league.length >= 2 ? <SiteSafetyComparisonCard data={visibleSites(site_league)} /> : null
                                    }
                                </DeferredPanel>
                            </SideColumn>
                        </div>
                    )}

                    {tab === 'overview' && !(header_box_set && filters.lens !== 'frontline') && (
                        <>
                            <RoleStart lens={filters.lens} onWorkflow={openWorkflow} />
                            {filters.lens !== 'governance' && incident_stages ? (
                                <IncidentStagesFlow data={incident_stages} />
                            ) : null}
                            <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                                <div className="grid min-w-0 gap-5">
                                    {measures}
                                    {filters.lens === 'governance' ? (
                                        <>
                                            {attention}
                                            {worklist(['acceptance'], 'H&S handovers')}
                                            {worklist(['corrective_actions'], 'Overdue corrective actions')}
                                            {worklist(['notifiable'], 'Notifiable events')}
                                            {trend('full')}
                                        </>
                                    ) : (
                                        <>
                                            <WorkPanel label="What needs you now" rows={4}>
                                                {({ worklists: lists }) => <NeedsYouNow worklists={visible(lists)} />}
                                            </WorkPanel>
                                            {trend('bars')}
                                        </>
                                    )}
                                </div>
                                <SideColumn>
                                    <DeferredPanel<{ site_league: SiteLeague }>
                                        data="site_league"
                                        label="Site comparison"
                                        fallback={<SkeletonCard rows={4} />}
                                    >
                                        {({ site_league }) => <SiteSafetyComparisonCard data={visibleSites(site_league)} />}
                                    </DeferredPanel>
                                    {worklist(['expiring'], 'Coming due')}
                                </SideColumn>
                            </div>
                        </>
                    )}

                    {tab === 'leading' && (
                        <>
                            {measures}
                            {attention}
                            <DeferredPanel<{ first_aid: FirstAid }>
                                data="first_aid"
                                label="First aid"
                                fallback={<SkeletonStats count={3} />}
                            >
                                {({ first_aid }) => <FirstAidTally data={first_aid} />}
                            </DeferredPanel>
                            <LeadingPanel
                                workerParticipation={worker_participation}
                                filters={filters}
                            />
                            <DeferredPanel<{
                                leading_lagging: HeroLeadingLagging;
                                frequency_operands: { near_misses: number; recordable: number | null };
                                hazard_burndown: Array<{ week: string; open: number }>;
                                kpis: Kpis;
                                open_hazards_list: OpenHazardRow[];
                            }>
                                data={['leading_lagging', 'frequency_operands', 'hazard_burndown', 'kpis', 'open_hazards_list']}
                                label="Prevention charts"
                                fallback={<SkeletonCard rows={6} />}
                            >
                                {({ leading_lagging, frequency_operands, hazard_burndown, kpis, open_hazards_list }) => (
                                    <LeadingCharts
                                        ratio={leading_lagging.leading.near_miss_ratio}
                                        operands={frequency_operands}
                                        burndown={hazard_burndown}
                                        drillPct={kpis.drill_compliance_pct ?? null}
                                        trainingPct={leading_lagging.leading.training_pct}
                                        openHazards={open_hazards_list}
                                    />
                                )}
                            </DeferredPanel>
                        </>
                    )}

                    {tab === 'lagging' && (
                        <>
                            {measures}
                            {attention}
                            <DeferredPanel<{ leading_lagging: HeroLeadingLagging }>
                                data="leading_lagging"
                                label="Harm and injury rates"
                                fallback={<SkeletonStats count={4} />}
                            >
                                {({ leading_lagging }) => <LaggingPanel data={leading_lagging.lagging} filters={filters} />}
                            </DeferredPanel>
                            <DeferredPanel<{
                                incident_trends: IncidentTrend;
                                frequency_trends: FrequencyTrend;
                                backbone: Backbone;
                                incidents_by_category: Array<{ label: string; count: number }>;
                            }>
                                data={['incident_trends', 'frequency_trends', 'backbone', 'incidents_by_category']}
                                label="Harm charts"
                                fallback={<SkeletonCard rows={6} />}
                            >
                                {({ incident_trends, frequency_trends, backbone, incidents_by_category }) => (
                                    <LaggingCharts
                                        bars={incident_trends}
                                        frequency={frequency_trends}
                                        severity={backbone.events?.events_by_severity}
                                        category={incidents_by_category}
                                    />
                                )}
                            </DeferredPanel>
                            <DeferredPanel<{ restraints: RestraintDashboardData }>
                                data="restraints"
                                label="Restraint practice"
                                fallback={<SkeletonCard rows={4} />}
                            >
                                {({ restraints }) => <RestraintSection data={restraints} />}
                            </DeferredPanel>
                            {worklist(['investigations'], 'Open investigations')}
                        </>
                    )}

                    {tab === 'compliance' && (
                        <>
                            {measures}
                            {attention}
                            <WorkPanel label="Duties" rows={4}>
                                {({ worklists: lists, backbone }) => (
                                    <CompliancePanel
                                        site={filters.site}
                                        expiring={lists.expiring}
                                        worksafePending={backbone.events.worksafe_pending ?? null}
                                    />
                                )}
                            </WorkPanel>
                            {worklist(['expiring'], 'Coming due')}
                            <GovernanceExports />
                        </>
                    )}

                    <ReportLauncher
                        open={launcherOpen}
                        onClose={() => setLauncherOpen(false)}
                        onWorkflow={openWorkflow}
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

/* ------------------------------------------------------------------ */
/*  Overview sections (approved H&S drawings, 23 Oct 2026)            */
/* ------------------------------------------------------------------ */

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** "17 Oct" from a civil date (YYYY-MM-DD) or an instant, in the worker time zone. */
function shortDay(value: string | null | undefined): string {
    if (!value) return '';
    const civil = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (civil) return `${Number(civil[3])} ${MONTHS[Number(civil[2]) - 1] ?? ''}`;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return date
        .toLocaleDateString('en-NZ', { timeZone: 'Pacific/Auckland', day: 'numeric', month: 'short' })
        .replace(/,/g, '');
}

/** Where open incidents are up to, by the step each is waiting on (sent by the source when available). */
export type IncidentStagesData = {
    stages: Array<{
        key: string;
        label: string;
        count: number;
        sub?: string | null;
        tone?: 'critical' | 'warning' | 'info' | 'success' | null;
        items?: Array<{ text: string; href: string; tone?: 'critical' | 'warning' | 'info' | 'success' | 'none' | null }>;
    }>;
    note?: string | null;
};

function IncidentStagesFlow({ data }: { data: IncidentStagesData }) {
    return (
        <Flow
            title="Where incidents are up to"
            sub="Every open incident you can see, by the step it’s waiting on"
            stages={data.stages.map((stage) => ({
                label: stage.label,
                n: stage.count,
                sub: stage.sub ?? undefined,
                tone: stage.tone ?? undefined,
                items: (stage.items ?? []).map((item) => ({
                    text: item.text,
                    href: item.href,
                    tone: item.tone ?? undefined,
                })),
            }))}
            note={
                data.note ?? (
                    <>
                        <b>Needs an owner</b> means nobody has accepted it yet — the site manager picks someone. Closing
                        an incident, its safety review and its actions are separate steps.
                    </>
                )
            }
        />
    );
}

/**
 * "Needs you now": open and due work from the overview's own previews, the most urgent first — WorkSafe
 * notifications still to make, overdue corrective actions, safety reviews waiting for acceptance and expired
 * register items. A preview, not the whole picture: the line says so and links to All tasks.
 */
function NeedsYouNow({ worklists }: { worklists: WorklistsPayload }) {
    type Row = LogItem & { rank: number; sortAt: string };
    const rows: Row[] = [];
    for (const n of worklists.notifiable_events) {
        if (n.status !== 'pending') continue;
        rows.push({
            key: `ntf-${n.id}`,
            rank: 0,
            sortAt: n.occurred_at ?? '',
            time: shortDay(n.occurred_at),
            tone: 'critical',
            title: `Notify WorkSafe — ${n.title ?? n.event_reference}`,
            sub: [n.event_reference, n.occurred_at ? `known since ${formatDateTimeLong(n.occurred_at)}` : null]
                .filter(Boolean)
                .join(' · '),
            action: { label: 'Record notification', href: `/health-safety/events/${n.id}` },
        });
    }
    for (const c of worklists.overdue_corrective_actions) {
        rows.push({
            key: `ca-${c.id}`,
            rank: 1,
            sortAt: c.due_date ?? '',
            time: shortDay(c.due_date),
            tone: 'critical',
            // Marked completed but not yet verified: say what is waiting, counted from completion.
            title: `${c.status === 'completed' ? hsLabel('action_status', 'completed') : 'Action overdue'} — ${c.title ?? c.reference ?? `#${c.id}`}`,
            sub: [
                c.reference,
                c.priority ? `${hsLabel('action_priority', c.priority)} priority` : null,
                c.owner,
                c.status === 'completed' && c.completed_days_ago != null
                    ? completedAgo(c.completed_days_ago)
                    : c.days_overdue != null
                      ? `${c.days_overdue} days overdue`
                      : 'Overdue',
            ]
                .filter(Boolean)
                .join(' · '),
            action: { label: 'Open', href: `/health-safety/corrective-actions/${c.id}` },
        });
    }
    for (const accept of worklists.attention[0]?.items ?? []) {
        rows.push({
            key: `acc-${accept.id}`,
            rank: 2,
            sortAt: accept.reported_at ?? '',
            time: shortDay(accept.reported_at),
            tone: 'warning',
            title: `${hsLabel('handover_status', 'awaiting_acceptance')} — ${accept.title}`,
            sub: [accept.event_reference, accept.client, accept.site, accept.owner ? `proposed owner ${accept.owner}` : null]
                .filter(Boolean)
                .join(' · '),
            action: { label: 'Accept', href: accept.action_url },
        });
    }
    for (const due of worklists.expiring) {
        if (due.days_until == null || due.days_until >= 0 || due.type === 'ppe_repair') continue;
        rows.push({
            key: `exp-${due.type}-${due.label ?? ''}-${due.due_date ?? ''}`,
            rank: 3,
            sortAt: due.due_date ?? '',
            time: shortDay(due.due_date),
            tone: 'warning',
            title: `${due.type_label} expired — ${due.label ?? due.type_label}`,
            sub: [due.site, `expired ${Math.abs(due.days_until)} day${Math.abs(due.days_until) === 1 ? '' : 's'} ago`]
                .filter(Boolean)
                .join(' · '),
            action: { label: 'Review', href: due.register_url },
        });
    }
    rows.sort((x, y) => x.rank - y.rank || x.sortAt.localeCompare(y.sortAt));
    return rows.length ? (
        <Log
            title="Needs you now"
            sub="Open and due work from records you can see — it may not be everything. Open a register for the full list."
            action={{ label: 'All tasks', href: '/tasks' }}
            items={rows}
        />
    ) : (
        <RowList
            title="Needs you now"
            sub="Open and due work from records you can see — it may not be everything."
            items={[]}
            empty="Nothing in this preview needs you now. Open a register for the full list."
        />
    );
}

/** Incidents and near misses each month: the latest in the brand, one sentence for what changed. */
function IncidentBars({ bars }: { bars: Array<{ month: string; count: number }> }) {
    if (!bars.length) return null;
    const labelOf = (ym: string) => MONTHS[Number(ym.split('-')[1]) - 1] ?? ym;
    const latest = bars[bars.length - 1];
    const previous = bars.length > 1 ? bars[bars.length - 2] : null;
    const diff = previous ? latest.count - previous.count : 0;
    const month = MONTHS_LONG[Number(latest.month.split('-')[1]) - 1] ?? latest.month;
    return (
        <PointBars
            title="Incidents and near misses"
            sub="Each month, records you can see"
            values={bars.map((b) => b.count)}
            labels={bars.map((b) => labelOf(b.month))}
            note={
                <>
                    <b>
                        {latest.count} so far in {month}
                    </b>
                    {previous
                        ? diff === 0
                            ? ` — the same as ${labelOf(previous.month)}.`
                            : ` — ${Math.abs(diff)} ${diff > 0 ? 'more' : 'fewer'} than ${labelOf(previous.month)}.`
                        : '.'}
                </>
            }
        />
    );
}

/**
 * First aid (Prevention tab). A leading care-activity signal: first-aid-only treatment is NOT recordable and is
 * deliberately excluded from the recordable injury rate, so it lives here rather than among the rates.
 */
function FirstAidTally({
    data,
}: {
    data: { treatments: number | null; ambulance: number | null; hospital: number | null };
}) {
    const item = (
        value: number | null,
        label: string,
        href: string,
        tone?: 'warning' | 'critical',
    ): TallyItem => ({
        value: value === null ? '—' : String(value),
        label,
        sub: value === null ? 'Not confirmed' : undefined,
        tone: value !== null && value > 0 ? tone : undefined,
        href,
    });
    return (
        <Tally
            title="First aid"
            sub="Last 30 days · records you can see · first aid alone isn’t a recordable injury, so it’s left out of the recordable injury rate"
            keepZeros
            items={[
                item(data.treatments, 'First-aid treatments', '/health-safety/first-aid'),
                item(data.ambulance, 'Ambulance called', '/health-safety/first-aid?tab=ambulance', 'warning'),
                item(
                    data.hospital,
                    'Hospital referrals',
                    '/health-safety/first-aid?treatment_outcome=sent_to_hospital',
                    'critical',
                ),
            ]}
        />
    );
}

/**
 * Restraint & behaviour support (Harm & injuries tab) — least-restrictive practice (Ngā Paerewa NZS 8134:2021).
 * Each number opens the restraints register's matching tab; the review list opens each event in its queue.
 */
function RestraintSection({ data }: { data: RestraintDashboardData }) {
    const s = data.summary;
    const n = (value: number | null) => (value === null ? '—' : String(value));
    const nc = (value: number | null, caption: string) => (value === null ? 'Not confirmed' : caption);
    const items: TallyItem[] = [
        { value: n(s.events_in_period), label: 'Restraint events', sub: nc(s.events_in_period, 'This period'), href: '/health-safety/restraints?lens=events&tab=30d' },
        { value: n(s.out_of_plan), label: 'Out of plan', sub: nc(s.out_of_plan, 'Not as the plan says'), tone: (s.out_of_plan ?? 0) > 0 ? 'critical' : undefined, href: '/health-safety/restraints?lens=events&tab=out_of_plan' },
        ...(s.with_injury !== 0
            ? [{ value: n(s.with_injury), label: 'With injury', sub: nc(s.with_injury, 'Harm occurred'), tone: (s.with_injury ?? 0) > 0 ? ('critical' as const) : undefined, href: '/health-safety/restraints?lens=events&tab=injury' }]
            : []),
        { value: n(s.unreviewed), label: 'Not reviewed', sub: nc(s.unreviewed, 'Need a review'), tone: (s.unreviewed ?? 0) > 0 ? 'warning' : undefined, href: '/health-safety/restraints?lens=events&tab=unreviewed' },
        { value: n(s.active_plans), label: 'Behaviour support plans', sub: nc(s.active_plans, 'In use'), href: '/health-safety/restraints?lens=plans&tab=active' },
        { value: n(s.plans_review_due), label: 'Plan reviews due', sub: nc(s.plans_review_due, 'In the next 30 days'), tone: (s.plans_review_due ?? 0) > 0 ? 'warning' : undefined, href: '/health-safety/restraints?lens=plans&tab=review_due' },
    ];
    return (
        <>
            <Tally
                title="Restraint & behaviour support"
                sub={
                    s.source_access === 'restricted'
                        ? 'Least-restrictive practice — Ngā Paerewa NZS 8134:2021 · Access to restraint measures is restricted.'
                        : 'Least-restrictive practice — Ngā Paerewa NZS 8134:2021 · Measures cover readable records; the full picture may be incomplete.'
                }
                action={{ label: 'Open register', href: '/health-safety/restraints' }}
                keepZeros
                items={items}
                calm={s.with_injury === 0 ? 'Nobody was hurt in a restraint event' : undefined}
            />
            <RowList
                title="Needs review"
                sub="Restraint events waiting for review"
                action={{ label: 'View all', href: '/health-safety/restraints?lens=events&tab=unreviewed' }}
                empty={
                    data.unreviewed === null
                        ? 'The list of restraints to review isn’t available right now.'
                        : 'No restraints are waiting for review.'
                }
                items={(data.unreviewed ?? []).map((e) => ({
                    key: e.id,
                    title: e.client ?? 'Client details unavailable',
                    sub: [e.reference, e.restraint_type ? hsLabel('restraint_type', e.restraint_type) : null].filter(Boolean).join(' · '),
                    href: `/health-safety/restraints?lens=events&tab=unreviewed&event=${e.id}`,
                    badge: (
                        <span className="inline-flex flex-wrap justify-end gap-1.5">
                            {!e.within_support_plan ? (
                                <StatusBadge variant="critical" size="sm">
                                    Outside the plan
                                </StatusBadge>
                            ) : null}
                            {e.injury_occurred ? (
                                <StatusBadge variant="critical" size="sm">
                                    Injury
                                </StatusBadge>
                            ) : null}
                            {e.severity ? (
                                <StatusBadge variant={hsVariant('severity', e.severity)} size="sm">{hsLabel('severity', e.severity)}</StatusBadge>
                            ) : null}
                        </span>
                    ),
                }))}
            />
        </>
    );
}
