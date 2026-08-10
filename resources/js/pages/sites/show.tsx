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
import {
    SiteTechnologyProjectionPanel,
    type SiteTechnologyProjection,
} from '@/components/sites/site-technology-projection';
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
import { EditLocationDialog } from './_overview-dialogs';
import SiteGeofenceDialog from './_site-geofence-dialog';
import { SiteProfileDialogHost } from './site-profile-dialog-host';
import { SiteProfileAssets, type SiteAssetsData } from './tabs/assets';
import {
    SiteProfileCalendar,
    type SiteProfileCalendarData,
} from './tabs/calendar';
import {
    SiteProfileChecklists,
    type SiteProfileChecklistsData,
} from './tabs/checklists';
import { SiteProfileClients, type SiteClientsData } from './tabs/clients';
import { SiteProfileContacts, type SiteContactsData } from './tabs/contacts';
import { SiteProfileDocuments, type SiteDocumentsData } from './tabs/documents';
import { SiteProfileDrills, type SiteDrillsData } from './tabs/drills';
import {
    SiteProfileEmergencyPlan,
    type EmergencyPlanModule,
} from './tabs/emergency-plan';
import {
    SiteProfileFinancials,
    type SiteProfileFinancialsModule,
} from './tabs/financials';
import { SiteProfileFirstAid, type SiteFirstAidData } from './tabs/first-aid';
import { SiteProfileFleet, type SiteFleetData } from './tabs/fleet';
import { SiteProfileHardware, type SiteHardwareData } from './tabs/hardware';
import { SiteProfileHazards, type SiteHazardsData } from './tabs/hazards';
import {
    SiteProfileInspections,
    type SiteInspectionsData,
} from './tabs/inspections';
import { SiteProfileMealPlanner } from './tabs/meal-planner';
import { SiteProfileOverview } from './tabs/overview';
import { SiteProfilePlan, type SitePlanData } from './tabs/plan';
import { SiteProfilePpe, type SitePpeData } from './tabs/ppe';
import { SiteProfileReadiness } from './tabs/readiness';
import {
    dataPropForTab,
    resolveSiteProfileTab,
    siteProfileGroups,
    siteProfileTabQueryValue,
    visibleSiteProfileTabs,
} from './tabs/registry';
import {
    SiteProfileRiskAssessments,
    type SiteRiskAssessmentsData,
} from './tabs/risk-assessments';
import { SiteProfileServices, type SiteServicesData } from './tabs/services';
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
import {
    SiteProfileVendors,
    type SiteVendorsCredentialsData,
} from './tabs/vendors';

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
    address_line_1?: string | null;
    address_line_2?: string | null;
    suburb?: string | null;
    city?: string | null;
    postcode?: string | null;
    country?: string | null;
    region?: string | null;
    latitude?: string | number | null;
    longitude?: string | number | null;
    access_instructions?: string | null;
    risk_notes?: string | null;
    risk_review_date?: string | null;
    emergency_plan_location?: string | null;
    medication_storage_location?: string | null;
    is_high_risk: boolean;
    is_high_needs: boolean;
    primary_contact?: { id: number; name: string } | null;
    manager_contact?: SiteProfileRoleContact | null;
    site_lead_contact?: SiteProfileRoleContact | null;
    after_hours_contact?: SiteProfileRoleContact | null;
    primary_site_contact?: SiteProfileRoleContact | null;
};

export type SiteProfileRoleContact = {
    id: number;
    name: string;
    role?: string | null;
    phone?: string | null;
    email?: string | null;
    is_primary?: boolean;
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
        latitude?: string | number | null;
        longitude?: string | number | null;
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
    geofences: Array<{
        id: number;
        name: string;
        type: 'circle' | 'polygon';
        shape: Record<string, unknown> | null;
        breach_type: 'enter' | 'exit' | 'both';
        is_active?: boolean;
        asset_id?: number | null;
        assigned_asset_ids?: number[];
    }>;
    geofence_assets: Array<{
        id: number;
        name: string;
        asset_tag?: string | null;
        category?: string | null;
        status?: string | null;
    }>;
    can_manage: boolean;
    can_manage_geofences: boolean;
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

export type SiteProfileProps = {
    site: SiteProfileSite;
    hero: SiteProfileHeroData;
    permissions: SiteProfilePermissionMap;
    attention: SiteProfileAttentionData;
    overview: SiteProfileOverviewData;
    readiness: SiteReadinessData;
    uiPreferences: { pinned_tabs: string[] };
    can: {
        viewTechnology: boolean;
        viewHardwarePlacement: boolean;
    };
    clientsData?: SiteClientsData;
    contactsData?: SiteContactsData;
    staffRequirementsData?: SiteStaffRequirementsData;
    shiftCoverageData?: SiteShiftCoverageData;
    hazardsData?: SiteHazardsData;
    riskAssessmentsData?: SiteRiskAssessmentsData;
    inspectionsData?: SiteInspectionsData;
    drillsData?: SiteDrillsData;
    firstAidData?: SiteFirstAidData;
    ppeData?: SitePpeData;
    emergencyPlanData?: EmergencyPlanModule & { locked?: boolean };
    calendarData?: SiteProfileCalendarData;
    checklistsData?: SiteProfileChecklistsData;
    mealPlannerData?: { locked?: boolean };
    assetsData?: SiteAssetsData;
    fleetData?: SiteFleetData;
    hardwareData?: SiteHardwareData;
    planData?: SitePlanData;
    documentsData?: SiteDocumentsData;
    financialsData?: SiteProfileFinancialsModule;
    vendorsCredentialsData?: SiteVendorsCredentialsData;
    servicesData?: SiteServicesData;
    technology?: SiteTechnologyProjection;
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
    url.searchParams.set('tab', siteProfileTabQueryValue(tab));
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
    const profilePermissions = useMemo(
        () => ({
            ...permissions,
            viewTechnology: props.can.viewTechnology,
        }),
        [permissions, props.can.viewTechnology],
    );
    const resolvedTabs = useMemo(
        () =>
            visibleSiteProfileTabs(
                site.type,
                profilePermissions,
                tabMetrics(props),
            ),
        [profilePermissions, props, site.type],
    );
    const initialTab = useMemo(
        () =>
            resolveSiteProfileTab(
                currentRequestedTab(),
                site.type,
                profilePermissions,
            ).id,
        [profilePermissions, site.type],
    );
    const [activeTab, setActiveTab] = useState(initialTab);
    const [searchOpen, setSearchOpen] = useState(false);
    const [locationOpen, setLocationOpen] = useState(false);
    const [geofenceOpen, setGeofenceOpen] = useState(false);
    const [loadingProps, setLoadingProps] = useState<
        Partial<Record<SiteProfileDataProp, boolean>>
    >({});
    const propsRef = useRef(props);
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
                (!force && propsRef.current[dataProp] !== undefined) ||
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
        [],
    );

    const selectTab = useCallback(
        (requested: string) => {
            const resolved = resolveSiteProfileTab(
                requested,
                site.type,
                profilePermissions,
            );
            if (resolved.locked) return;

            setActiveTab(resolved.id);
            replaceTabInUrl(resolved.id);
            const dataProp = dataPropForTab(resolved.id);
            if (dataProp) requestProp(dataProp);
        },
        [profilePermissions, requestProp, site.type],
    );

    useEffect(() => {
        propsRef.current = props;
    }, [props]);

    useGroupedProfileSearchShortcut(() => setSearchOpen(true));

    useEffect(() => {
        const normalized = resolveSiteProfileTab(
            currentRequestedTab(),
            site.type,
            profilePermissions,
        );
        if (currentRequestedTab() !== normalized.id) {
            replaceTabInUrl(normalized.id);
        }
        setActiveTab(normalized.id);
        const dataProp = dataPropForTab(normalized.id);
        if (dataProp) requestProp(dataProp);
    }, [profilePermissions, requestProp, site.type]);

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
                        onEditLocation={() => setLocationOpen(true)}
                        onConfigureGeofence={() => setGeofenceOpen(true)}
                        onRetry={(dataProp) => requestProp(dataProp, true)}
                    />
                </div>
            </PageLayout>
            <SiteProfileDialogHost />
            <EditLocationDialog
                siteId={site.id}
                siteName={site.name}
                isOpen={locationOpen}
                onClose={() => setLocationOpen(false)}
                initial={{
                    address_line_1: site.address_line_1 ?? '',
                    address_line_2: site.address_line_2 ?? '',
                    suburb: site.suburb ?? '',
                    city: site.city ?? '',
                    postcode: site.postcode ?? '',
                    country: site.country ?? '',
                    region: site.region ?? '',
                    latitude:
                        site.latitude == null ? '' : String(site.latitude),
                    longitude:
                        site.longitude == null ? '' : String(site.longitude),
                    access_instructions: site.access_instructions ?? '',
                }}
                geofences={overview.geofences}
                onOpenGeofence={
                    overview.can_manage_geofences
                        ? () => {
                              setLocationOpen(false);
                              setGeofenceOpen(true);
                          }
                        : undefined
                }
            />
            <SiteGeofenceDialog
                isOpen={geofenceOpen}
                onClose={() => setGeofenceOpen(false)}
                onOpenLocation={() => {
                    setGeofenceOpen(false);
                    setLocationOpen(true);
                }}
                siteId={site.id}
                siteName={site.name}
                siteLat={site.latitude}
                siteLng={site.longitude}
                existing={overview.geofences[0] ?? null}
                assets={overview.geofence_assets}
            />
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
    onEditLocation,
    onConfigureGeofence,
    onRetry,
}: {
    active?: ResolvedSiteProfileTab;
    props: SiteProfileProps;
    loadingProps: Partial<Record<SiteProfileDataProp, boolean>>;
    propErrors: Partial<Record<SiteProfileDataProp, boolean>>;
    onNavigate: (tab: string) => void;
    onEditLocation: () => void;
    onConfigureGeofence: () => void;
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
                onEditLocation={onEditLocation}
                onConfigureGeofence={onConfigureGeofence}
            />
        );
    }
    if (active.id === 'readiness') {
        return (
            <SiteProfileReadiness
                readiness={props.readiness}
                onNavigate={onNavigate}
                onConfigureGeofence={onConfigureGeofence}
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
                    siteId={props.site.id}
                    data={tabData as SiteShiftCoverageData}
                />
            );
        case 'hazards':
            return <SiteProfileHazards data={tabData as SiteHazardsData} />;
        case 'risk_assessments':
            return (
                <SiteProfileRiskAssessments
                    data={tabData as SiteRiskAssessmentsData}
                />
            );
        case 'inspections':
            return (
                <SiteProfileInspections data={tabData as SiteInspectionsData} />
            );
        case 'drills':
            return <SiteProfileDrills data={tabData as SiteDrillsData} />;
        case 'first_aid':
            return <SiteProfileFirstAid data={tabData as SiteFirstAidData} />;
        case 'ppe':
            return <SiteProfilePpe data={tabData as SitePpeData} />;
        case 'emergency_plan':
            return (
                <SiteProfileEmergencyPlan
                    data={tabData as EmergencyPlanModule}
                />
            );
        case 'calendar':
            return (
                <SiteProfileCalendar
                    data={tabData as SiteProfileCalendarData}
                />
            );
        case 'checklists':
            return (
                <SiteProfileChecklists
                    data={tabData as SiteProfileChecklistsData}
                />
            );
        case 'meal_planner':
            return (
                <SiteProfileMealPlanner
                    site={props.site}
                    data={tabData as { locked?: boolean }}
                />
            );
        case 'assets':
            return <SiteProfileAssets data={tabData as SiteAssetsData} />;
        case 'fleet':
            return <SiteProfileFleet data={tabData as SiteFleetData} />;
        case 'hardware':
            return <SiteProfileHardware data={tabData as SiteHardwareData} />;
        case 'technology':
            return (
                <SiteTechnologyProjectionPanel
                    siteId={props.site.id}
                    data={tabData as SiteTechnologyProjection}
                    canViewHardwarePlacement={props.can.viewHardwarePlacement}
                />
            );
        case 'plan':
            return <SiteProfilePlan data={tabData as SitePlanData} />;
        case 'documents':
            return <SiteProfileDocuments data={tabData as SiteDocumentsData} />;
        case 'financials':
            return (
                <SiteProfileFinancials
                    data={tabData as SiteProfileFinancialsModule}
                />
            );
        case 'vendors':
            return (
                <SiteProfileVendors
                    data={tabData as SiteVendorsCredentialsData}
                />
            );
        case 'services':
            return <SiteProfileServices data={tabData as SiteServicesData} />;
    }

    return (
        <SiteProfileEmptyState
            title={`${active.label} is not configured`}
            description="This section has no Site-specific data yet."
        />
    );
}
