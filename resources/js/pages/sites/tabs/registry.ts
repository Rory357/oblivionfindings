import {
    AlertTriangle,
    Ambulance,
    BriefcaseMedical,
    CalendarDays,
    Car,
    CheckSquare,
    ClipboardCheck,
    ContactRound,
    FileText,
    FolderOpen,
    Gauge,
    HandCoins,
    HardDrive,
    House,
    PackageCheck,
    PanelsTopLeft,
    ShieldCheck,
    ShoppingBasket,
    Siren,
    SquareActivity,
    Store,
    UserRoundCog,
    Users,
    Utensils,
} from 'lucide-react';
import type {
    ResolvedSiteProfileTab,
    SiteProfileDataGroup,
    SiteProfileGroupDefinition,
    SiteProfileGroupId,
    SiteProfilePermissionMap,
    SiteProfileTabDefinition,
    SiteProfileTabMetrics,
    SiteProfileTerminology,
} from './types';

export const siteProfileGroups: SiteProfileGroupDefinition[] = [
    { id: 'overview', label: 'Overview', icon: PanelsTopLeft },
    { id: 'people', label: 'People', icon: Users },
    { id: 'safety', label: 'Safety', icon: ShieldCheck },
    { id: 'operations', label: 'Operations', icon: SquareActivity },
    { id: 'admin', label: 'Admin', icon: FolderOpen },
];

export function siteProfileTerminology(
    siteType: string,
): SiteProfileTerminology {
    if (siteType === 'house' || siteType === 'residential') {
        return {
            people: 'Residents',
            occupancy: 'Bedrooms',
            plan: 'Plan & Rooms',
        };
    }

    if (siteType === 'facility') {
        return {
            people: 'Attendees',
            occupancy: 'Places',
            plan: 'Plan & Zones',
        };
    }

    if (siteType === 'head_office') {
        return {
            people: 'Clients',
            occupancy: 'Resources',
            plan: 'Plan & Resources',
        };
    }

    return {
        people: 'Clients',
        occupancy: 'Spaces',
        plan: 'Plan & Spaces',
    };
}

const literal = (label: string) => () => label;

export const siteProfileTabs: SiteProfileTabDefinition[] = [
    {
        id: 'overview',
        group: 'overview',
        label: literal('Overview'),
        icon: House,
    },
    {
        id: 'readiness',
        group: 'overview',
        label: literal('Readiness'),
        icon: Gauge,
        warningSource: 'readiness',
    },
    {
        id: 'clients',
        group: 'people',
        label: (siteType) => siteProfileTerminology(siteType).people,
        icon: Users,
        dataGroup: 'peopleData',
        permission: ['clients.viewAny', 'clients.viewAssigned'],
        hiddenFor: ['head_office'],
    },
    {
        id: 'contacts',
        group: 'people',
        label: literal('Contacts'),
        icon: ContactRound,
        dataGroup: 'peopleData',
    },
    {
        id: 'staff_requirements',
        group: 'people',
        label: literal('Staff Requirements'),
        icon: UserRoundCog,
        dataGroup: 'peopleData',
        permission: 'staff.viewAny',
        warningSource: 'staff_requirements',
    },
    {
        id: 'shift_coverage',
        group: 'people',
        label: literal('Shift Coverage'),
        icon: CalendarDays,
        dataGroup: 'peopleData',
        permission: 'rostering.viewAny',
        hiddenFor: ['head_office'],
        warningSource: 'shift_coverage',
    },
    {
        id: 'hazards',
        group: 'safety',
        label: literal('Hazards'),
        icon: AlertTriangle,
        dataGroup: 'safetyData',
        permission: 'hazards.view',
        warningSource: 'hazards',
    },
    {
        id: 'risk_assessments',
        group: 'safety',
        label: literal('Risk Assessments'),
        icon: ClipboardCheck,
        dataGroup: 'safetyData',
        permission: 'hazards.view',
        warningSource: 'risk_assessments',
    },
    {
        id: 'inspections',
        group: 'safety',
        label: literal('Inspections'),
        icon: CheckSquare,
        dataGroup: 'safetyData',
        permission: 'hazards.view',
        warningSource: 'inspections',
    },
    {
        id: 'drills',
        group: 'safety',
        label: literal('Drills'),
        icon: Siren,
        dataGroup: 'safetyData',
        permission: 'hazards.view',
        warningSource: 'drills',
    },
    {
        id: 'first_aid',
        group: 'safety',
        label: literal('First Aid'),
        icon: BriefcaseMedical,
        dataGroup: 'safetyData',
        permission: 'hazards.view',
        warningSource: 'first_aid',
    },
    {
        id: 'ppe',
        group: 'safety',
        label: literal('PPE'),
        icon: PackageCheck,
        dataGroup: 'safetyData',
        permission: 'hazards.view',
        warningSource: 'ppe',
    },
    {
        id: 'emergency_plan',
        group: 'safety',
        label: literal('Emergency Plan'),
        icon: Ambulance,
        dataGroup: 'safetyData',
        permission: 'hazards.view',
        warningSource: 'emergency_plan',
    },
    {
        id: 'calendar',
        group: 'operations',
        label: literal('Calendar'),
        icon: CalendarDays,
        dataGroup: 'operationsData',
        permission: 'calendar.view',
    },
    {
        id: 'checklists',
        group: 'operations',
        label: literal('Checklists'),
        icon: ClipboardCheck,
        dataGroup: 'operationsData',
        permission: 'checklists.view',
        warningSource: 'checklists',
    },
    {
        id: 'meal_planner',
        group: 'operations',
        label: literal('Meal Planner'),
        icon: Utensils,
        dataGroup: 'operationsData',
        permission: 'sites.meals.view',
        hiddenFor: ['head_office'],
    },
    {
        id: 'assets',
        group: 'operations',
        label: literal('Assets'),
        icon: ShoppingBasket,
        dataGroup: 'operationsData',
        permission: ['assets.viewAny', 'assets.viewAssigned'],
        warningSource: 'assets',
    },
    {
        id: 'fleet',
        group: 'operations',
        label: literal('Fleet'),
        icon: Car,
        dataGroup: 'operationsData',
        permission: 'fleet.viewAny',
        warningSource: 'fleet',
    },
    {
        id: 'hardware',
        group: 'operations',
        label: literal('Hardware'),
        icon: HardDrive,
        dataGroup: 'operationsData',
        permission: 'securityDevices.devices.view',
        warningSource: 'hardware',
    },
    {
        id: 'plan',
        group: 'operations',
        label: (siteType) => siteProfileTerminology(siteType).plan,
        icon: FileText,
        dataGroup: 'operationsData',
    },
    {
        id: 'documents',
        group: 'admin',
        label: literal('Documents'),
        icon: FileText,
        dataGroup: 'adminData',
        warningSource: 'documents',
    },
    {
        id: 'financials',
        group: 'admin',
        label: literal('Financials'),
        icon: HandCoins,
        dataGroup: 'adminData',
        permission: 'finance.dashboard',
        warningSource: 'financials',
    },
    {
        id: 'vendors',
        group: 'admin',
        label: literal('Vendors & Credentials'),
        icon: Store,
        dataGroup: 'adminData',
        permission: ['vendors.view', 'credentials.view'],
        warningSource: 'vendors',
    },
    {
        id: 'services',
        group: 'admin',
        label: literal('Services'),
        icon: SquareActivity,
        dataGroup: 'adminData',
    },
];

export function visibleSiteProfileTabs(
    siteType: string,
    permissions: SiteProfilePermissionMap,
    metrics: SiteProfileTabMetrics = {},
): ResolvedSiteProfileTab[] {
    return siteProfileTabs
        .filter((tab) => !tab.hiddenFor?.includes(siteType))
        .map((tab) => {
            const requiredPermissions = Array.isArray(tab.permission)
                ? tab.permission
                : tab.permission
                  ? [tab.permission]
                  : [];
            const locked =
                requiredPermissions.length > 0 &&
                !requiredPermissions.some(
                    (permission) => permissions[permission] === true,
                );

            return {
                ...tab,
                label: tab.label(siteType),
                locked,
                count: locked ? undefined : metrics.counts?.[tab.id],
                warningCount: locked
                    ? undefined
                    : metrics.warnings?.[tab.warningSource ?? tab.id],
            };
        });
}

export function resolveSiteProfileTab(
    requestedTab: string | null | undefined,
    siteType: string,
    permissions: SiteProfilePermissionMap,
): ResolvedSiteProfileTab {
    const visible = visibleSiteProfileTabs(siteType, permissions);

    return (
        visible.find((tab) => tab.id === requestedTab && !tab.locked) ??
        visible.find((tab) => tab.id === 'overview') ??
        visible[0]
    );
}

export function dataGroupForTab(
    tabId: string,
): SiteProfileDataGroup | undefined {
    return siteProfileTabs.find((tab) => tab.id === tabId)?.dataGroup;
}

export function warningTotalsByGroup(
    warningCounts: Record<string, number | undefined>,
): Record<SiteProfileGroupId, number> {
    const totals: Record<SiteProfileGroupId, number> = {
        overview: 0,
        people: 0,
        safety: 0,
        operations: 0,
        admin: 0,
    };

    for (const tab of siteProfileTabs) {
        if (!tab.warningSource) continue;
        totals[tab.group] += warningCounts[tab.warningSource] ?? 0;
    }

    return totals;
}
