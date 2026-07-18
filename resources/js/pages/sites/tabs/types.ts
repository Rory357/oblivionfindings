import type { LucideIcon } from 'lucide-react';

export type SiteProfileDataGroup =
    | 'peopleData'
    | 'safetyData'
    | 'operationsData'
    | 'adminData';

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
    dataGroup?: SiteProfileDataGroup;
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
