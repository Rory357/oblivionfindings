import type { LucideIcon } from 'lucide-react';

export type SiteProfileDataProp =
    | 'clientsData'
    | 'contactsData'
    | 'staffRequirementsData'
    | 'shiftCoverageData'
    | 'hazardsData'
    | 'riskAssessmentsData'
    | 'inspectionsData'
    | 'drillsData'
    | 'firstAidData'
    | 'ppeData'
    | 'emergencyPlanData'
    | 'calendarData'
    | 'checklistsData'
    | 'mealPlannerData'
    | 'assetsData'
    | 'fleetData'
    | 'hardwareData'
    | 'planData'
    | 'documentsData'
    | 'financialsData'
    | 'vendorsCredentialsData'
    | 'servicesData';

export type SiteProfileGroupId =
    | 'overview'
    | 'people'
    | 'safety'
    | 'operations'
    | 'admin';

export type SiteProfilePermissionMap = Record<string, boolean | undefined>;

export type SiteProfileTerminology = {
    people: string;
    occupancy: string;
    plan: string;
};

export type SiteProfileGroupDefinition = {
    id: SiteProfileGroupId;
    label: string;
    icon: LucideIcon;
};

export type SiteProfileTabDefinition = {
    id: string;
    group: SiteProfileGroupId;
    label: (siteType: string) => string;
    icon: LucideIcon;
    dataProp?: SiteProfileDataProp;
    permission?: string | string[];
    hiddenFor?: string[];
    warningSource?: string;
};

export type ResolvedSiteProfileTab = Omit<SiteProfileTabDefinition, 'label'> & {
    label: string;
    locked: boolean;
    count?: number;
    warningCount?: number;
};

export type SiteProfileTabMetrics = {
    counts?: Record<string, number | undefined>;
    warnings?: Record<string, number | undefined>;
};
