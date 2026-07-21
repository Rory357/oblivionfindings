import { PageHero, PageLayout, type PageHeroStat } from '@/components/page';
import {
    GroupPillRail,
    TabSearchPalette,
    TierTwoTabs,
    useGroupedProfileSearchShortcut,
    type GroupedProfileNavGroup,
} from '@/components/page/grouped-profile-nav';
import { useUiPreference } from '@/hooks/use-ui-preference';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    BedDouble,
    BellRing,
    Building2,
    CalendarPlus,
    ExternalLink,
    Gauge,
    Pencil,
    Plus,
    ShieldAlert,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
    dataGroupForTab,
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
    SiteProfileDataGroup,
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

type SitePeopleData = {
    clients: SiteClientsData;
    contacts: SiteContactsData;
    staff_requirements: SiteStaffRequirementsData;
    shift_coverage: SiteShiftCoverageData;
};

type SiteSafetyData = {
    locked: boolean;
    hazards: SiteProfileSummaryModule;
    risk_assessments: SiteProfileSummaryModule;
    inspections: SiteProfileSummaryModule;
    drills: SiteProfileSummaryModule;
    first_aid: SiteProfileSummaryModule;
    ppe: SiteProfileSummaryModule;
    emergency_plan: EmergencyPlanModule;
};

type SiteOperationsData = {
    calendar: SiteProfileSummaryModule;
    checklists: SiteProfileSummaryModule;
    meal_planner: SiteProfileSummaryModule;
    assets: SiteProfileSummaryModule;
    fleet: SiteProfileSummaryModule;
    hardware: SiteProfileSummaryModule;
    plan: SitePlanSummaryModule;
};

type SiteAdminData = {
    documents: SiteProfileSummaryModule;
    financials: SiteProfileFinancialsModule;
    vendors_credentials: SiteProfileSummaryModule;
    services: SiteProfileSummaryModule;
};

export type SiteProfileProps = {
    site: SiteProfileSite;
    hero: SiteProfileHeroData;
    permissions: SiteProfilePermissionMap;
    attention: SiteProfileAttentionData;
    overview: SiteProfileOverviewData;
    readiness: SiteReadinessData;
    uiPreferences: { pinned_tabs: string[] };
    peopleData?: SitePeopleData;
    safetyData?: SiteSafetyData;
    operationsData?: SiteOperationsData;
    adminData?: SiteAdminData;
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

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

type InertiaRequestException = {
    config?: {
        headers?: {
            get?: (name: string) => unknown;
            [name: string]: unknown;
        };
    };
};

function exceptionTargetsGroup(
    exception: unknown,
    dataGroup: SiteProfileDataGroup,
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
            .includes(dataGroup)
    );
}

function stableHue(value: string): number {
    return [...value].reduce(
        (total, character) => (total * 31 + character.charCodeAt(0)) % 360,
        0,
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
    const [loadingGroups, setLoadingGroups] = useState<
        Partial<Record<SiteProfileDataGroup, boolean>>
    >({});
    const loadingGroupsRef = useRef<
        Partial<Record<SiteProfileDataGroup, boolean>>
    >({});
    const [groupErrors, setGroupErrors] = useState<
        Partial<Record<SiteProfileDataGroup, boolean>>
    >({});
    const pinned = useUiPreference<string[]>({
        key: 'sites.profile.pinned-tabs',
        initialValue: uiPreferences.pinned_tabs,
    });

    const requestGroup = useCallback(
        (dataGroup: SiteProfileDataGroup, force = false) => {
            if (
                (!force && props[dataGroup] !== undefined) ||
                loadingGroupsRef.current[dataGroup]
            ) {
                return;
            }

            loadingGroupsRef.current[dataGroup] = true;
            setLoadingGroups((current) => ({ ...current, [dataGroup]: true }));
            setGroupErrors((current) => ({ ...current, [dataGroup]: false }));

            const failGroupRequest = () =>
                setGroupErrors((current) => ({
                    ...current,
                    [dataGroup]: true,
                }));
            const stopWatchingExceptions = router.on('exception', (event) => {
                if (!exceptionTargetsGroup(event.detail.exception, dataGroup)) {
                    return;
                }

                failGroupRequest();
                stopWatchingExceptions();

                // This request owns the visible error state, so suppress the
                // otherwise-unhandled rejected promise after recording it.
                return false;
            });

            router.reload({
                only: [dataGroup],
                preserveState: true,
                preserveScroll: true,
                onError: () => {
                    stopWatchingExceptions();
                    failGroupRequest();
                },
                onSuccess: stopWatchingExceptions,
                onCancel: stopWatchingExceptions,
                onFinish: () => {
                    stopWatchingExceptions();
                    loadingGroupsRef.current[dataGroup] = false;
                    setLoadingGroups((current) => ({
                        ...current,
                        [dataGroup]: false,
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
            const dataGroup = dataGroupForTab(resolved.id);
            if (dataGroup) requestGroup(dataGroup);
        },
        [permissions, requestGroup, site.type],
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
        const dataGroup = dataGroupForTab(normalized.id);
        if (dataGroup) requestGroup(dataGroup);
    }, [permissions, requestGroup, site.type]);

    const navGroups = useMemo(
        () => buildNavGroups(resolvedTabs),
        [resolvedTabs],
    );
    const active =
        resolvedTabs.find((tab) => tab.id === activeTab) ?? resolvedTabs[0];
    const activeGroup = active?.group ?? 'overview';
    const groupTabs =
        navGroups.find((group) => group.key === activeGroup)?.tabs ?? [];
    const heroStats: PageHeroStat[] = [
        {
            label: 'Readiness',
            value: `${hero.readiness.score}%`,
            sub:
                hero.readiness.missing_critical > 0
                    ? `${hero.readiness.missing_critical} critical missing`
                    : 'Core setup complete',
            icon: Gauge,
            tone: hero.readiness.missing_critical > 0 ? 'warning' : 'success',
            hideOnMobile: false,
        },
        {
            label: 'Needs attention',
            value: hero.attention.total,
            sub:
                hero.attention.critical > 0
                    ? `${hero.attention.critical} critical`
                    : 'No critical items',
            icon: BellRing,
            tone: hero.attention.critical > 0 ? 'critical' : 'neutral',
            hideOnMobile: false,
        },
        {
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
                    <PageHero
                        category="sites"
                        brandColour={site.brand_colour || 'var(--primary)'}
                        backHref="/sites"
                        backLabel="All Sites"
                        icon={Building2}
                        title={site.name}
                        description={hero.description}
                        avatarStack={hero.avatars.map((avatar) => ({
                            id: avatar.id,
                            initials: initials(avatar.name),
                            hue: stableHue(avatar.name),
                            name: avatar.name,
                            popover: {
                                title: avatar.name,
                                subtitle: 'Client at this Site',
                                primaryAction: {
                                    label: 'Open profile',
                                    href: `/clients/${avatar.id}`,
                                },
                            },
                        }))}
                        badges={[
                            {
                                label: site.display_type,
                                tone: 'default',
                            },
                            {
                                label:
                                    hero.status === 'archived'
                                        ? 'Archived'
                                        : hero.status === 'active'
                                          ? 'Active'
                                          : 'Inactive',
                                tone:
                                    hero.status === 'active'
                                        ? 'success'
                                        : 'warning',
                            },
                            ...(site.is_high_risk
                                ? [
                                      {
                                          label: 'High risk',
                                          tone: 'critical' as const,
                                      },
                                  ]
                                : []),
                        ]}
                        stats={heroStats}
                        quickActions={hero.quick_actions.map((action) => ({
                            label: action.label,
                            href: action.href,
                            icon: QUICK_ACTION_ICONS[action.id] ?? ExternalLink,
                        }))}
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
                        loadingGroups={loadingGroups}
                        groupErrors={groupErrors}
                        onNavigate={selectTab}
                        onRetry={(group) => requestGroup(group, true)}
                    />
                </div>
            </PageLayout>
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
    loadingGroups,
    groupErrors,
    onNavigate,
    onRetry,
}: {
    active?: ResolvedSiteProfileTab;
    props: SiteProfileProps;
    loadingGroups: Partial<Record<SiteProfileDataGroup, boolean>>;
    groupErrors: Partial<Record<SiteProfileDataGroup, boolean>>;
    onNavigate: (tab: string) => void;
    onRetry: (group: SiteProfileDataGroup) => void;
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

    const dataGroup = dataGroupForTab(active.id);
    if (!dataGroup) {
        return (
            <SiteProfileEmptyState
                title={`${active.label} is not configured`}
                description="This section has no Site-specific data yet."
            />
        );
    }
    if (groupErrors[dataGroup]) {
        return (
            <SiteProfileErrorState
                label={active.label}
                onRetry={() => onRetry(dataGroup)}
            />
        );
    }
    const groupData = props[dataGroup];
    if (loadingGroups[dataGroup] || groupData === undefined) {
        return <SiteProfileLoadingState label={active.label} />;
    }

    if (dataGroup === 'peopleData') {
        const people = groupData as SitePeopleData;
        switch (active.id) {
            case 'clients':
                return (
                    <SiteProfileClients
                        siteId={props.site.id}
                        data={people.clients}
                    />
                );
            case 'contacts':
                return (
                    <SiteProfileContacts
                        siteId={props.site.id}
                        data={people.contacts}
                    />
                );
            case 'staff_requirements':
                return (
                    <SiteProfileStaffRequirements
                        siteId={props.site.id}
                        data={people.staff_requirements}
                    />
                );
            case 'shift_coverage':
                return (
                    <SiteProfileShiftCoverage data={people.shift_coverage} />
                );
        }
    }

    if (dataGroup === 'safetyData') {
        const safety = groupData as SiteSafetyData;
        if (safety.locked) return <SiteProfileLockedState label="Safety" />;

        switch (active.id) {
            case 'hazards':
                return <SiteProfileHazards data={safety.hazards} />;
            case 'risk_assessments':
                return (
                    <SiteProfileRiskAssessments
                        data={safety.risk_assessments}
                    />
                );
            case 'inspections':
                return <SiteProfileInspections data={safety.inspections} />;
            case 'drills':
                return <SiteProfileDrills data={safety.drills} />;
            case 'first_aid':
                return <SiteProfileFirstAid data={safety.first_aid} />;
            case 'ppe':
                return <SiteProfilePpe data={safety.ppe} />;
            case 'emergency_plan':
                return (
                    <SiteProfileEmergencyPlan data={safety.emergency_plan} />
                );
        }
    }

    if (dataGroup === 'operationsData') {
        const operations = groupData as SiteOperationsData;
        switch (active.id) {
            case 'calendar':
                return <SiteProfileCalendar data={operations.calendar} />;
            case 'checklists':
                return <SiteProfileChecklists data={operations.checklists} />;
            case 'meal_planner':
                return (
                    <SiteProfileMealPlanner
                        site={props.site}
                        data={operations.meal_planner}
                    />
                );
            case 'assets':
                return <SiteProfileAssets data={operations.assets} />;
            case 'fleet':
                return <SiteProfileFleet data={operations.fleet} />;
            case 'hardware':
                return <SiteProfileHardware data={operations.hardware} />;
            case 'plan':
                return <SiteProfilePlan data={operations.plan} />;
        }
    }

    if (dataGroup === 'adminData') {
        const admin = groupData as SiteAdminData;
        switch (active.id) {
            case 'documents':
                return <SiteProfileDocuments data={admin.documents} />;
            case 'financials':
                return <SiteProfileFinancials data={admin.financials} />;
            case 'vendors':
                return <SiteProfileVendors data={admin.vendors_credentials} />;
            case 'services':
                return <SiteProfileServices data={admin.services} />;
        }
    }

    return (
        <SiteProfileEmptyState
            title={`${active.label} is not configured`}
            description="This section has no Site-specific data yet."
        />
    );
}
