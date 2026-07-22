import { PageLayout } from '@/components/page';
import {
    GroupPillRail,
    TabSearchPalette,
    TierTwoTabs,
    useGroupedProfileSearchShortcut,
    type GroupedProfileNavGroup,
} from '@/components/page/grouped-profile-nav';
import { SiteProfileAlertRibbon } from '@/components/sites/profile/alert-ribbon';
import {
    SiteProfileHero,
    type SiteHeroStat,
} from '@/components/sites/profile/hero';
import { useUiPreference } from '@/hooks/use-ui-preference';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    BedDouble,
    BellRing,
    CalendarPlus,
    ExternalLink,
    Gauge,
    Pencil,
    Plus,
    ShieldAlert,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { SiteProfileDialogHost } from './site-profile-dialog-host';
import { SiteProfileAssets } from './tabs/assets';
import { SiteProfileCalendar } from './tabs/calendar';
import { SiteProfileChecklists } from './tabs/checklists';
import { SiteProfileClients, type SiteClientsData } from './tabs/clients';
import { SiteProfileContacts, type SiteContactsData } from './tabs/contacts';
import { SiteProfileDocuments } from './tabs/documents';
import { SiteProfileDrills } from './tabs/drills';
import {
    SiteProfileEmergencyPlan,
    type EmergencyPlanModule,
} from './tabs/emergency-plan';
import {
    SiteProfileFinancials,
    type SiteProfileFinancialsModule,
} from './tabs/financials';
import { SiteProfileFirstAid } from './tabs/first-aid';
import { SiteProfileFleet } from './tabs/fleet';
import { SiteProfileHardware } from './tabs/hardware';
import { SiteProfileHazards } from './tabs/hazards';
import { SiteProfileInspections } from './tabs/inspections';
import { SiteProfileMealPlanner } from './tabs/meal-planner';
import type { SiteProfileSummaryModule } from './tabs/module-summary-panel';
import { SiteProfileOverview } from './tabs/overview';
import { SiteProfilePlan, type SitePlanSummaryModule } from './tabs/plan';
import { SiteProfilePpe } from './tabs/ppe';
import { SiteProfileReadiness } from './tabs/readiness';
import {
    dataPropForTab,
    resolveSiteProfileTab,
    siteProfileGroups,
    visibleSiteProfileTabs,
} from './tabs/registry';
import { SiteProfileRiskAssessments } from './tabs/risk-assessments';
import { SiteProfileServices } from './tabs/services';
import {
    SiteProfileShiftCoverage,
    type SiteShiftCoverageData,
} from './tabs/shift-coverage';
import {
    SiteProfileEmptyState,
    SiteProfileErrorState,
    SiteProfileLoadingState,
    SiteProfileLockedState,
} from './tabs/site-profile-states';
import {
    SiteProfileStaffRequirements,
    type SiteStaffRequirementsData,
} from './tabs/staff-requirements';
import type {
    ResolvedSiteProfileTab,
    SiteProfileDataProp,
    SiteProfilePermissionMap,
} from './tabs/types';
import { SiteProfileVendors } from './tabs/vendors';

export type SiteProfileSite = {
    id: number;
    name: string;
    type: 'head_office' | 'house' | 'facility' | 'residential';
    display_type: string;
    brand_colour?: string | null;
    phone?: string | null;
    email?: string | null;
    is_active: boolean;
    archived: boolean;
    address?: string | null;
    region?: string | null;
    is_high_risk: boolean;
    is_high_needs: boolean;
    primary_contact?: { id: number; name: string } | null;
};

export type SiteProfileHeroData = {
    description: string;
    brand_colour?: string | null;
    status: 'active' | 'inactive' | 'archived';
    readiness: { score: number; missing_critical: number };
    attention: { total: number; critical: number; warning: number };
    occupancy: { label: string; total: number; occupied: number };
    avatars: Array<{
        id: number;
        name: string;
        profile_photo_url?: string | null;
    }>;
    quick_actions: Array<{ id: string; label: string; href: string }>;
};

export type SiteProfileAttentionItem = {
    id: string;
    source: string;
    severity: 'critical' | 'warning';
    title: string;
    detail: string;
    due_date?: string | null;
    tab: string;
    href: string;
};

export type SiteProfileAttentionData = {
    summary: { total: number; critical: number; warning: number };
    groups: Record<string, number>;
    items: SiteProfileAttentionItem[];
};

export type SiteProfileOverviewData = {
    location: {
        address?: string | null;
        region?: string | null;
        access_instructions?: string | null;
    };
    contacts: Array<{
        id: number;
        type: string;
        name: string;
        role?: string | null;
        phone?: string | null;
        email?: string | null;
        is_primary: boolean;
    }>;
    safety: {
        is_high_risk: boolean;
        is_high_needs: boolean;
        risk_notes?: string | null;
        risk_review_date?: string | null;
        emergency_plan_location?: string | null;
        medication_storage_location?: string | null;
    };
    services: Array<{
        id: number;
        name: string;
        type?: string | null;
        description?: string | null;
    }>;
    notes: Array<{
        id: number;
        body: string;
        created_at?: string | null;
        created_by?: string | null;
    }>;
};

export type SiteReadinessData = {
    critical: Array<{
        key: string;
        label: string;
        done: boolean;
        action: string;
    }>;
    recommended: Array<{
        key: string;
        label: string;
        done: boolean;
        action: string;
    }>;
    score: number;
    missing_critical: string[];
    is_active_but_incomplete: boolean;
};

type SiteSafetyTabData = SiteProfileSummaryModule & {
    locked: boolean;
};

export type SiteProfileProps = {
    site: SiteProfileSite;
    hero: SiteProfileHeroData;
    permissions: SiteProfilePermissionMap;
    attention: SiteProfileAttentionData;
    overview: SiteProfileOverviewData;
    readiness: SiteReadinessData;
    uiPreferences: { pinned_tabs: string[] };
    clientsData?: SiteClientsData;
    contactsData?: SiteContactsData;
    staffRequirementsData?: SiteStaffRequirementsData;
    shiftCoverageData?: SiteShiftCoverageData;
    hazardsData?: SiteSafetyTabData;
    riskAssessmentsData?: SiteSafetyTabData;
    inspectionsData?: SiteSafetyTabData;
    drillsData?: SiteSafetyTabData;
    firstAidData?: SiteSafetyTabData;
    ppeData?: SiteSafetyTabData;
    emergencyPlanData?: EmergencyPlanModule & { locked?: boolean };
    calendarData?: SiteProfileSummaryModule;
    checklistsData?: SiteProfileSummaryModule;
    mealPlannerData?: SiteProfileSummaryModule;
    assetsData?: SiteProfileSummaryModule;
    fleetData?: SiteProfileSummaryModule;
    hardwareData?: SiteProfileSummaryModule;
    planData?: SitePlanSummaryModule;
    documentsData?: SiteProfileSummaryModule;
    financialsData?: SiteProfileFinancialsModule;
    vendorsCredentialsData?: SiteProfileSummaryModule;
    servicesData?: SiteProfileSummaryModule;
};

const QUICK_ACTION_ICONS: Record<string, LucideIcon> = {
    edit_site: Pencil,
    add_client: Plus,
    add_calendar_event: CalendarPlus,
    report_hazard: ShieldAlert,
};

function currentRequestedTab(): string | null {
    if (typeof window === 'undefined') return null;
    return new URLSearchParams(window.location.search).get('tab');
}

function replaceTabInUrl(tab: string) {
    if (typeof window === 'undefined') return;

    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', url);
}

type InertiaRequestException = {
    config?: {
        headers?: {
            get?: (name: string) => unknown;
            [name: string]: unknown;
        };
    };
};

function exceptionTargetsProp(
    exception: unknown,
    dataProp: SiteProfileDataProp,
): boolean {
    const headers = (exception as InertiaRequestException)?.config?.headers;
    if (!headers) return false;

    const partialData =
        (typeof headers.get === 'function'
            ? headers.get('X-Inertia-Partial-Data')
            : undefined) ??
        headers['X-Inertia-Partial-Data'] ??
        headers['x-inertia-partial-data'];

    return (
        typeof partialData === 'string' &&
        partialData
            .split(',')
            .map((value) => value.trim())
            .includes(dataProp)
    );
}

export default function SiteShow(props: SiteProfileProps) {
    const {
        site,
        hero,
        permissions,
        attention,
        overview,
        readiness,
        uiPreferences,
    } = props;
    const resolvedTabs = useMemo(
        () => visibleSiteProfileTabs(site.type, permissions, tabMetrics(props)),
        [props, site.type, permissions],
    );
    const initialTab = useMemo(
        () =>
            resolveSiteProfileTab(currentRequestedTab(), site.type, permissions)
                .id,
        [permissions, site.type],
    );
    const [activeTab, setActiveTab] = useState(initialTab);
    const [searchOpen, setSearchOpen] = useState(false);
    const [loadingProps, setLoadingProps] = useState<
        Partial<Record<SiteProfileDataProp, boolean>>
    >({});
    const loadingPropsRef = useRef<
        Partial<Record<SiteProfileDataProp, boolean>>
    >({});
    const [propErrors, setPropErrors] = useState<
        Partial<Record<SiteProfileDataProp, boolean>>
    >({});
    const pinned = useUiPreference<string[]>({
        key: 'sites.profile.pinned-tabs',
        initialValue: uiPreferences.pinned_tabs,
    });

    const requestProp = useCallback(
        (dataProp: SiteProfileDataProp, force = false) => {
            if (
                (!force && props[dataProp] !== undefined) ||
                loadingPropsRef.current[dataProp]
            ) {
                return;
            }

            loadingPropsRef.current[dataProp] = true;
            setLoadingProps((current) => ({ ...current, [dataProp]: true }));
            setPropErrors((current) => ({ ...current, [dataProp]: false }));

            const failPropRequest = () =>
                setPropErrors((current) => ({
                    ...current,
                    [dataProp]: true,
                }));
            const stopWatchingExceptions = router.on('exception', (event) => {
                if (!exceptionTargetsProp(event.detail.exception, dataProp)) {
                    return;
                }

                failPropRequest();
                stopWatchingExceptions();

                // This request owns the visible error state, so suppress the
                // otherwise-unhandled rejected promise after recording it.
                return false;
            });

            router.reload({
                only: [dataProp],
                preserveState: true,
                preserveScroll: true,
                onError: () => {
                    stopWatchingExceptions();
                    failPropRequest();
                },
                onSuccess: stopWatchingExceptions,
                onCancel: stopWatchingExceptions,
                onFinish: () => {
                    stopWatchingExceptions();
                    loadingPropsRef.current[dataProp] = false;
                    setLoadingProps((current) => ({
                        ...current,
                        [dataProp]: false,
                    }));
                },
            });
        },
        [props],
    );

    const selectTab = useCallback(
        (requested: string) => {
            const resolved = resolveSiteProfileTab(
                requested,
                site.type,
                permissions,
            );
            if (resolved.locked) return;

            setActiveTab(resolved.id);
            replaceTabInUrl(resolved.id);
            const dataProp = dataPropForTab(resolved.id);
            if (dataProp) requestProp(dataProp);
        },
        [permissions, requestProp, site.type],
    );

    useGroupedProfileSearchShortcut(() => setSearchOpen(true));

    useEffect(() => {
        const normalized = resolveSiteProfileTab(
            currentRequestedTab(),
            site.type,
            permissions,
        );
        if (currentRequestedTab() !== normalized.id) {
            replaceTabInUrl(normalized.id);
        }
        setActiveTab(normalized.id);
        const dataProp = dataPropForTab(normalized.id);
        if (dataProp) requestProp(dataProp);
    }, [permissions, requestProp, site.type]);

    const navGroups = useMemo(
        () => buildNavGroups(resolvedTabs),
        [resolvedTabs],
    );
    const active =
        resolvedTabs.find((tab) => tab.id === activeTab) ?? resolvedTabs[0];
    const activeGroup = active?.group ?? 'overview';
    const groupTabs =
        navGroups.find((group) => group.key === activeGroup)?.tabs ?? [];
    const heroStats: SiteHeroStat[] = [
        {
            id: 'readiness',
            label: 'Readiness',
            value: `${hero.readiness.score}%`,
            detail:
                hero.readiness.missing_critical > 0
                    ? `${hero.readiness.missing_critical} critical missing`
                    : 'Core setup complete',
            icon: Gauge,
        },
        {
            id: 'attention',
            label: 'Needs attention',
            value: String(hero.attention.total),
            detail:
                hero.attention.critical > 0
                    ? `${hero.attention.critical} critical`
                    : 'No critical items',
            icon: BellRing,
        },
        {
            id: 'occupancy',
            label: hero.occupancy.label,
            value: `${hero.occupancy.occupied}/${hero.occupancy.total}`,
            icon: BedDouble,
        },
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
            ]}
        >
            <Head title={`${site.name} — Site Profile`} />
            <PageLayout
                width="wide"
                hero={
                    <SiteProfileHero
                        siteId={site.id}
                        name={site.name}
                        description={hero.description}
                        brandColour={site.brand_colour ?? hero.brand_colour}
                        statusLabel={
                            hero.status === 'archived'
                                ? 'Archived'
                                : hero.status === 'active'
                                  ? 'Active'
                                  : 'Inactive'
                        }
                        typeLabel={site.display_type}
                        region={site.region}
                        avatars={hero.avatars}
                        stats={heroStats}
                        actions={hero.quick_actions.map((action) => ({
                            id: action.id,
                            label: action.label,
                            href: action.href,
                            icon: QUICK_ACTION_ICONS[action.id] ?? ExternalLink,
                        }))}
                        onEdit={
                            permissions['site.update']
                                ? () => router.visit(`/sites/${site.id}/edit`)
                                : undefined
                        }
                        footer={
                            <GroupPillRail
                                groups={navGroups}
                                openGroup={activeGroup}
                                activeTab={activeTab}
                                onOpenGroup={(_group, tab) => selectTab(tab)}
                                onSearch={() => setSearchOpen(true)}
                                testIdPrefix="site-profile"
                                ariaLabel="Site Profile groups"
                            />
                        }
                    />
                }
            >
                <SiteProfileAlertRibbon
                    alerts={attention.items.slice(0, 6).map((item) => ({
                        id: item.id,
                        label: item.title,
                        detail: item.detail,
                        tone: item.severity,
                        icon:
                            item.severity === 'critical'
                                ? ShieldAlert
                                : BellRing,
                        onSelect: () => selectTab(item.tab),
                    }))}
                />
                <TierTwoTabs
                    tabs={groupTabs}
                    activeTab={activeTab}
                    onTab={selectTab}
                    renderLink={(tab, className, inner, tabProps) => (
                        <Link
                            key={tab.key}
                            href={tab.href ?? '#'}
                            className={className}
                            {...tabProps}
                        >
                            {inner}
                        </Link>
                    )}
                    testIdPrefix="site-profile"
                    ariaLabel="Site Profile sections"
                    panelId="site-profile-tab-panel"
                    pinnedTabs={pinned.value}
                    onPinnedTabsChange={pinned.setValue}
                />
                {pinned.error ? (
                    <p role="alert" className="text-sm text-status-critical">
                        {pinned.error}
                    </p>
                ) : null}
                <div
                    id="site-profile-tab-panel"
                    role="tabpanel"
                    aria-labelledby={`site-profile-tab-${activeTab}`}
                    tabIndex={0}
                    className="focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <SiteProfileContent
                        active={active}
                        props={props}
                        loadingProps={loadingProps}
                        propErrors={propErrors}
                        onNavigate={selectTab}
                        onRetry={(dataProp) => requestProp(dataProp, true)}
                    />
                </div>
            </PageLayout>
            <SiteProfileDialogHost />
            <TabSearchPalette
                open={searchOpen}
                onClose={() => setSearchOpen(false)}
                groups={navGroups}
                onTab={selectTab}
                testIdPrefix="site-profile"
                searchLabel="Find a Site Profile section"
            />
        </AppLayout>
    );
}

function buildNavGroups(
    tabs: ResolvedSiteProfileTab[],
): GroupedProfileNavGroup[] {
    return siteProfileGroups
        .map((group) => ({
            key: group.id,
            label: group.label,
            icon: group.icon,
            tabs: tabs
                .filter((tab) => tab.group === group.id)
                .map((tab) => ({
                    key: tab.id,
                    label: tab.label,
                    icon: tab.icon,
                    count: tab.count,
                    warningCount: tab.warningCount,
                    disabled: tab.locked,
                })),
        }))
        .filter((group) => group.tabs.length > 0);
}

function tabMetrics(props: SiteProfileProps) {
    const warnings: Record<string, number> = {
        readiness: props.readiness.missing_critical.length,
    };
    for (const item of props.attention.items) {
        warnings[item.tab] = (warnings[item.tab] ?? 0) + 1;
    }

    return { warnings };
}

function SiteProfileContent({
    active,
    props,
    loadingProps,
    propErrors,
    onNavigate,
    onRetry,
}: {
    active?: ResolvedSiteProfileTab;
    props: SiteProfileProps;
    loadingProps: Partial<Record<SiteProfileDataProp, boolean>>;
    propErrors: Partial<Record<SiteProfileDataProp, boolean>>;
    onNavigate: (tab: string) => void;
    onRetry: (dataProp: SiteProfileDataProp) => void;
}) {
    if (!active) return null;
    if (active.locked) return <SiteProfileLockedState label={active.label} />;
    if (active.id === 'overview') {
        return (
            <SiteProfileOverview
                site={props.site}
                hero={props.hero}
                overview={props.overview}
                attention={props.attention}
                onNavigate={onNavigate}
            />
        );
    }
    if (active.id === 'readiness') {
        return (
            <SiteProfileReadiness
                readiness={props.readiness}
                onNavigate={onNavigate}
            />
        );
    }

    const dataProp = dataPropForTab(active.id);
    if (!dataProp) {
        return (
            <SiteProfileEmptyState
                title={`${active.label} is not configured`}
                description="This section has no Site-specific data yet."
            />
        );
    }
    if (propErrors[dataProp]) {
        return (
            <SiteProfileErrorState
                label={active.label}
                onRetry={() => onRetry(dataProp)}
            />
        );
    }
    const tabData = props[dataProp];
    if (loadingProps[dataProp] || tabData === undefined) {
        return <SiteProfileLoadingState label={active.label} />;
    }

    if (
        typeof tabData === 'object' &&
        tabData !== null &&
        'locked' in tabData &&
        tabData.locked
    ) {
        return <SiteProfileLockedState label={active.label} />;
    }

    switch (active.id) {
        case 'clients':
            return (
                <SiteProfileClients
                    siteId={props.site.id}
                    data={tabData as SiteClientsData}
                />
            );
        case 'contacts':
            return (
                <SiteProfileContacts
                    siteId={props.site.id}
                    data={tabData as SiteContactsData}
                />
            );
        case 'staff_requirements':
            return (
                <SiteProfileStaffRequirements
                    siteId={props.site.id}
                    data={tabData as SiteStaffRequirementsData}
                />
            );
        case 'shift_coverage':
            return (
                <SiteProfileShiftCoverage
                    data={tabData as SiteShiftCoverageData}
                />
            );
        case 'hazards':
            return (
                <SiteProfileHazards
                    data={tabData as SiteProfileSummaryModule}
                />
            );
        case 'risk_assessments':
            return (
                <SiteProfileRiskAssessments
                    data={tabData as SiteProfileSummaryModule}
                />
            );
        case 'inspections':
            return (
                <SiteProfileInspections
                    data={tabData as SiteProfileSummaryModule}
                />
            );
        case 'drills':
            return (
                <SiteProfileDrills data={tabData as SiteProfileSummaryModule} />
            );
        case 'first_aid':
            return (
                <SiteProfileFirstAid
                    data={tabData as SiteProfileSummaryModule}
                />
            );
        case 'ppe':
            return (
                <SiteProfilePpe data={tabData as SiteProfileSummaryModule} />
            );
        case 'emergency_plan':
            return (
                <SiteProfileEmergencyPlan
                    data={tabData as EmergencyPlanModule}
                />
            );
        case 'calendar':
            return (
                <SiteProfileCalendar
                    data={tabData as SiteProfileSummaryModule}
                />
            );
        case 'checklists':
            return (
                <SiteProfileChecklists
                    data={tabData as SiteProfileSummaryModule}
                />
            );
        case 'meal_planner':
            return (
                <SiteProfileMealPlanner
                    site={props.site}
                    data={tabData as SiteProfileSummaryModule}
                />
            );
        case 'assets':
            return (
                <SiteProfileAssets data={tabData as SiteProfileSummaryModule} />
            );
        case 'fleet':
            return (
                <SiteProfileFleet data={tabData as SiteProfileSummaryModule} />
            );
        case 'hardware':
            return (
                <SiteProfileHardware
                    data={tabData as SiteProfileSummaryModule}
                />
            );
        case 'plan':
            return <SiteProfilePlan data={tabData as SitePlanSummaryModule} />;
        case 'documents':
            return (
                <SiteProfileDocuments
                    data={tabData as SiteProfileSummaryModule}
                />
            );
        case 'financials':
            return (
                <SiteProfileFinancials
                    data={tabData as SiteProfileFinancialsModule}
                />
            );
        case 'vendors':
            return (
                <SiteProfileVendors
                    data={tabData as SiteProfileSummaryModule}
                />
            );
        case 'services':
            return (
                <SiteProfileServices
                    data={tabData as SiteProfileSummaryModule}
                />
            );
    }

    return (
        <SiteProfileEmptyState
            title={`${active.label} is not configured`}
            description="This section has no Site-specific data yet."
        />
    );
}
