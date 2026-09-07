import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterAvatars,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterContacts,
    PageHeaderMeterDonut,
    PageHeaderMeterSpark,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearchTrigger,
    PageHeaderStatusChip,
    PageLayout,
    type PageHeaderRailItem,
} from '@/components/page';
import {
    TabSearchPalette,
    TierTwoTabs,
    useGroupedProfileSearchShortcut,
    type GroupedProfileNavGroup,
} from '@/components/page/grouped-profile-nav';
import {
    SiteTechnologyProjectionPanel,
    type SiteTechnologyProjection,
} from '@/components/sites/site-technology-projection';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarPlus,
    CheckSquare,
    ChevronDown,
    ClipboardCheck,
    ExternalLink,
    Link2,
    Pencil,
    Plus,
    ShieldAlert,
    Upload,
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
    siteProfileTerminology,
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
    /** ServiceType categories of the ACTIVE service contexts ("Respite", …). */
    support_types: string[];
    brand_colour?: string | null;
    status: 'active' | 'inactive' | 'archived';
    readiness: { score: number; missing_critical: number };
    attention: { total: number; critical: number; warning: number };
    occupancy: { label: string; total: number; occupied: number };
    /** People at this Site with hover details — null when the viewer can't see them. */
    people: {
        count: number;
        avatars: Array<{
            id: number;
            name: string;
            photo_url?: string | null;
            detail?: string | null;
            href?: string | null;
        }>;
    } | null;
    /** Open register count + 7-day reported trend — null without hazards.view. */
    open_hazards: { open: number; trend: number[] } | null;
    /** Overdue runs + 90-day on-time fraction — null without checklists.view. */
    checks: {
        overdue: number;
        on_time: number;
        total: number;
        percent: number | null;
    } | null;
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
    link_resident: Link2,
    add_calendar_event: CalendarPlus,
    report_hazard: ShieldAlert,
    start_checklist: ClipboardCheck,
    book_inspection: CheckSquare,
    add_document: Upload,
};

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

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
    const { site, hero, permissions, attention, overview } = props;
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

    // The rail remembers which tab you were on in each group, so switching
    // groups doesn't lose your place.
    const rememberedTabs = useRef<Record<string, string>>({});
    useEffect(() => {
        rememberedTabs.current[activeGroup] = activeTab;
    }, [activeGroup, activeTab]);
    const selectGroup = useCallback(
        (groupKey: string) => {
            const group = navGroups.find((item) => item.key === groupKey);
            if (!group) return;
            const remembered = rememberedTabs.current[groupKey];
            const target = group.tabs.some(
                (tab) => tab.key === remembered && !tab.disabled,
            )
                ? remembered
                : group.tabs.find((tab) => !tab.disabled)?.key;
            if (target) selectTab(target);
        },
        [navGroups, selectTab],
    );

    const terminology = siteProfileTerminology(site.type);
    const statusLabel =
        hero.status === 'archived'
            ? 'Archived'
            : hero.status === 'active'
              ? 'Active'
              : 'Inactive';
    // The header renders a dedicated edit chip, so drop the duplicate
    // quick action from the Add/log menu.
    const quickActions = hero.quick_actions.filter(
        (action) => action.id !== 'edit_site',
    );
    const clientsTab = resolvedTabs.find(
        (tab) => tab.id === 'clients' && !tab.locked,
    );
    // Only houses track per-room occupancy; other types only know capacity.
    const occupancyTracked =
        site.type === 'house' || site.type === 'residential';
    const occupancyPercent =
        hero.occupancy.total > 0
            ? Math.round((hero.occupancy.occupied / hero.occupancy.total) * 100)
            : 0;
    const hazardsWeek =
        hero.open_hazards?.trend.reduce((sum, day) => sum + day, 0) ?? 0;
    const railItems: PageHeaderRailItem[] = navGroups.map((group) => ({
        key: group.key,
        label: group.label,
        icon: group.icon,
        count: attention.groups[group.key] || undefined,
        alert: true,
    }));

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
            ]}
        >
            <Head title={`${site.name} — Site Profile`} />
            <PageLayout
                width="wide"
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/sites"
                        mark={
                            <span className="eh-mark-ring text-[17px] font-bold tracking-tight">
                                {initials(site.name)}
                            </span>
                        }
                        title={site.name}
                        titleChip={
                            <PageHeaderStatusChip
                                variant={
                                    hero.status === 'active'
                                        ? 'success'
                                        : 'neutral'
                                }
                            >
                                {statusLabel}
                            </PageHeaderStatusChip>
                        }
                        subline={
                            /* Two lines (profile revision 2026-09-06): the
                               address first, then what this place IS —
                               type · support category · region. */
                            <>
                                <span className="block">
                                    {hero.description}
                                </span>
                                <span className="block">
                                    {[
                                        site.display_type,
                                        hero.support_types.join(' / '),
                                        site.region,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            </>
                        }
                        actions={
                            <>
                                <PageHeaderSearchTrigger
                                    placeholder="Search this site…"
                                    onOpen={() => setSearchOpen(true)}
                                />
                                {permissions['site.update'] ? (
                                    <PageHeaderGlassButton
                                        icon={Pencil}
                                        aria-label="Edit Site"
                                        title="Edit Site"
                                        onClick={() =>
                                            router.visit(
                                                `/sites/${site.id}/edit`,
                                            )
                                        }
                                    />
                                ) : null}
                                {quickActions.length ? (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <PageHeaderPrimaryButton
                                                icon={Plus}
                                            >
                                                Add / log
                                                <ChevronDown className="size-3.5 opacity-70" />
                                            </PageHeaderPrimaryButton>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            className="w-56"
                                        >
                                            {quickActions.map((action) => {
                                                const Icon =
                                                    QUICK_ACTION_ICONS[
                                                        action.id
                                                    ] ?? ExternalLink;
                                                return (
                                                    <DropdownMenuItem
                                                        key={action.id}
                                                        asChild
                                                        className="min-h-11"
                                                    >
                                                        <Link
                                                            href={action.href}
                                                        >
                                                            <Icon className="mr-2 h-4 w-4" />
                                                            {action.label}
                                                        </Link>
                                                    </DropdownMenuItem>
                                                );
                                            })}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                ) : null}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Key contacts"
                                    ariaLabel="View the contact register"
                                    onClick={() => selectTab('contacts')}
                                >
                                    <PageHeaderMeterContacts
                                        contacts={[
                                            {
                                                label: 'Manager',
                                                name: site.manager_contact
                                                    ?.name,
                                                phone: site.manager_contact
                                                    ?.phone,
                                            },
                                            {
                                                label: 'Site lead',
                                                name: site.site_lead_contact
                                                    ?.name,
                                                phone: site.site_lead_contact
                                                    ?.phone,
                                            },
                                            {
                                                label: 'After hours',
                                                name: site.after_hours_contact
                                                    ?.name,
                                                phone: site.after_hours_contact
                                                    ?.phone,
                                            },
                                        ]}
                                    />
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Needs attention"
                                    tone={
                                        attention.summary.critical > 0
                                            ? 'critical'
                                            : attention.summary.total > 0
                                              ? 'warning'
                                              : 'success'
                                    }
                                    ariaLabel="View items needing attention"
                                    onClick={() => selectTab('overview')}
                                >
                                    <PageHeaderMeterBig>
                                        {attention.summary.total}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {attention.summary.total > 0
                                            ? `${attention.summary.critical} critical · ${attention.summary.warning} warning`
                                            : 'nothing outstanding'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                {hero.open_hazards ? (
                                    <PageHeaderMeterBlock
                                        label="Open hazards"
                                        value={hero.open_hazards.open}
                                        tone={
                                            hero.open_hazards.open > 0
                                                ? 'critical'
                                                : 'success'
                                        }
                                        ariaLabel="View open hazards"
                                        onClick={() => selectTab('hazards')}
                                    >
                                        <PageHeaderMeterSpark
                                            values={hero.open_hazards.trend}
                                        />
                                        <PageHeaderMeterCaption>
                                            {hazardsWeek > 0
                                                ? `${hazardsWeek} reported this week`
                                                : 'none reported this week'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                                {hero.checks ? (
                                    hero.checks.percent !== null ? (
                                        <PageHeaderMeterBlock
                                            label="Checks on time"
                                            value={
                                                hero.checks.overdue > 0
                                                    ? `${hero.checks.overdue} overdue`
                                                    : undefined
                                            }
                                            tone={
                                                hero.checks.overdue > 0
                                                    ? 'warning'
                                                    : 'brand'
                                            }
                                            ariaLabel="View checklists"
                                            onClick={() =>
                                                selectTab('checklists')
                                            }
                                        >
                                            <PageHeaderMeterDonut
                                                percent={hero.checks.percent}
                                                caption={
                                                    <>
                                                        {hero.checks.on_time}{' '}
                                                        of {hero.checks.total}
                                                        <br />
                                                        done on time
                                                    </>
                                                }
                                            />
                                        </PageHeaderMeterBlock>
                                    ) : (
                                        <PageHeaderMeterBlock
                                            label="Overdue checks"
                                            tone={
                                                hero.checks.overdue > 0
                                                    ? 'warning'
                                                    : 'success'
                                            }
                                            ariaLabel="View overdue checklists"
                                            onClick={() =>
                                                selectTab('checklists')
                                            }
                                        >
                                            <PageHeaderMeterBig>
                                                {hero.checks.overdue}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                {hero.checks.overdue > 0
                                                    ? 'checklist runs late'
                                                    : 'all on schedule'}
                                            </PageHeaderMeterCaption>
                                        </PageHeaderMeterBlock>
                                    )
                                ) : null}
                                {clientsTab && hero.occupancy.total > 0 ? (
                                    occupancyTracked ? (
                                        <PageHeaderMeterBlock
                                            label={hero.occupancy.label}
                                            value={`${hero.occupancy.occupied}/${hero.occupancy.total}`}
                                            ariaLabel={`View ${terminology.people.toLowerCase()} and room placements`}
                                            onClick={() =>
                                                selectTab('clients')
                                            }
                                        >
                                            <PageHeaderMeterBar
                                                percent={occupancyPercent}
                                            />
                                            <PageHeaderMeterCaption>
                                                {occupancyPercent}% occupied ·{' '}
                                                {hero.occupancy.total -
                                                    hero.occupancy
                                                        .occupied}{' '}
                                                available
                                            </PageHeaderMeterCaption>
                                        </PageHeaderMeterBlock>
                                    ) : (
                                        <PageHeaderMeterBlock
                                            label={hero.occupancy.label}
                                            ariaLabel={`View ${terminology.people.toLowerCase()}`}
                                            onClick={() =>
                                                selectTab('clients')
                                            }
                                        >
                                            <PageHeaderMeterBig>
                                                {hero.occupancy.total}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                capacity
                                            </PageHeaderMeterCaption>
                                        </PageHeaderMeterBlock>
                                    )
                                ) : null}
                                {clientsTab && hero.people ? (
                                    <PageHeaderMeterBlock
                                        label={terminology.people}
                                        value={
                                            hero.people.avatars.length > 0
                                                ? hero.people.count
                                                : undefined
                                        }
                                        ariaLabel={`View ${terminology.people.toLowerCase()}`}
                                        onClick={() => selectTab('clients')}
                                    >
                                        {hero.people.avatars.length > 0 ? (
                                            <PageHeaderMeterAvatars
                                                people={hero.people.avatars}
                                                overflow={
                                                    hero.people.count -
                                                    hero.people.avatars.length
                                                }
                                            />
                                        ) : (
                                            <PageHeaderMeterBig>
                                                {hero.people.count}
                                            </PageHeaderMeterBig>
                                        )}
                                        <PageHeaderMeterCaption>
                                            active or onboarding
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                items={railItems}
                                value={activeGroup}
                                onSelect={selectGroup}
                                onFind={() => setSearchOpen(true)}
                                ariaLabel="Site Profile groups"
                            />
                        }
                    />
                }
            >
                {/* 20px rhythm between the page's section stack (DESIGN.md
                    spacing rule) — the sub-tab strip and panel are siblings
                    on the page ground. Alert counts live in the header's
                    meter row; the detail list is the overview's attention
                    panel (PAGE_HEADER_STYLE_GUIDE.md). */}
                <div className="flex flex-col gap-5">
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
                    />
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
